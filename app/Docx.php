<?php
declare(strict_types=1);

// Word (.docx) -> clean HTML. Every picture is kept: inline pictures, floating/anchored pictures,
// pictures in text boxes and group shapes are placed where they sit in the document, and any
// picture that is not referenced from the body (e.g. in headers) is added at the end.
final class Docx
{
    private ZipArchive $zip;
    private array $rels = [];
    private array $styles = [];
    private array $numFmt = [];
    private array $media = [];      // zip path => file id|null
    private $saveImage;
    private int $skipped = 0;

    public static function convert(string $data, callable $saveImage): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $data);
        try {
            $self = new self();
            $self->saveImage = $saveImage;
            $self->zip = new ZipArchive();
            if ($self->zip->open($tmp) !== true) throw new AppError('This Word file could not be opened (is it a real .docx?)');
            $out = $self->run();
            $self->zip->close();
            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    private function xml(string $name): ?DOMDocument
    {
        $s = $this->zip->getFromName($name);
        if ($s === false) return null;
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($s, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $d : null;
    }

    /** Local element name, also for elements whose namespace prefix was never declared. */
    private static function ln($n): string
    {
        if (!$n instanceof DOMNode) return '';
        if ($n->localName && !str_contains((string) $n->nodeName, ':')) return $n->localName;
        $name = (string) $n->nodeName;
        $i = strrpos($name, ':');
        return $i === false ? (string) $n->localName : substr($name, $i + 1);
    }

    private static function attr(DOMElement $el, string $local): ?string
    {
        foreach ($el->attributes as $a) if (self::ln($a) === $local) return $a->value;
        return null;
    }

    private static function kids(DOMNode $n, ?string $local = null): array
    {
        $out = [];
        foreach ($n->childNodes as $c) if ($c instanceof DOMElement && ($local === null || self::ln($c) === $local)) $out[] = $c;
        return $out;
    }

    private static function child(DOMNode $n, string $local): ?DOMElement
    {
        foreach ($n->childNodes as $c) if ($c instanceof DOMElement && self::ln($c) === $local) return $c;
        return null;
    }

    private static function desc(DOMElement $n, string $local): array
    {
        $out = [];
        foreach ($n->getElementsByTagName('*') as $c) if (self::ln($c) === $local) $out[] = $c;
        return $out;
    }

    private function run(): array
    {
        $this->loadRels();
        $this->loadStyles();
        $this->loadNumbering();
        $doc = $this->xml('word/document.xml');
        if (!$doc) throw new AppError('This Word file has no readable text (word/document.xml is missing)');
        $body = null;
        foreach ($doc->documentElement->childNodes as $c) if ($c instanceof DOMElement && self::ln($c) === 'body') $body = $c;
        $html = $body ? $this->blocks($body) : '';

        // Pictures that are not referenced from the body: never leave a photo behind.
        $extra = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            if (!preg_match('#^word/media/[^/]+$#', $name) || array_key_exists($name, $this->media)) continue;
            $id = $this->mediaId($name);
            if ($id) $extra[] = $id;
        }
        if ($extra) $html .= "\n" . implode("\n", array_map(fn ($id) => '<p>' . Html::imgTag($id) . '</p>', $extra));
        return ['html' => $html, 'extra' => count($extra), 'skipped' => $this->skipped];
    }

    private function loadRels(): void
    {
        $d = $this->xml('word/_rels/document.xml.rels');
        if (!$d) return;
        foreach ($d->getElementsByTagName('*') as $r) {
            if (self::ln($r) !== 'Relationship') continue;
            $this->rels[(string) $r->getAttribute('Id')] = ['target' => (string) $r->getAttribute('Target'), 'external' => $r->getAttribute('TargetMode') === 'External'];
        }
    }

    private function loadStyles(): void
    {
        $d = $this->xml('word/styles.xml');
        if (!$d) return;
        foreach ($d->getElementsByTagName('*') as $s) {
            if (self::ln($s) !== 'style') continue;
            $id = (string) self::attr($s, 'styleId');
            $nameEl = self::child($s, 'name');
            $this->styles[$id] = strtolower($nameEl ? (string) self::attr($nameEl, 'val') : $id);
        }
    }

    private function loadNumbering(): void
    {
        $d = $this->xml('word/numbering.xml');
        if (!$d) return;
        $abstract = [];
        foreach ($d->getElementsByTagName('*') as $el) {
            if (self::ln($el) === 'abstractNum') {
                foreach (self::kids($el, 'lvl') as $lvl) {
                    $fmt = self::child($lvl, 'numFmt');
                    $abstract[self::attr($el, 'abstractNumId')][(int) self::attr($lvl, 'ilvl')] = $fmt ? (string) self::attr($fmt, 'val') : 'bullet';
                }
            }
        }
        foreach ($d->getElementsByTagName('*') as $el) {
            if (self::ln($el) === 'num') {
                $a = self::child($el, 'abstractNumId');
                if ($a) $this->numFmt[(string) self::attr($el, 'numId')] = $abstract[self::attr($a, 'val')] ?? [];
            }
        }
    }

    private function mediaPath(string $target): string
    {
        $t = ltrim(str_replace('\\', '/', $target), '/');
        if (str_starts_with($t, 'word/')) return $t;
        $parts = [];
        foreach (explode('/', 'word/' . $t) as $p) {
            if ($p === '..') array_pop($parts); elseif ($p !== '.' && $p !== '') $parts[] = $p;
        }
        return implode('/', $parts);
    }

    private function mediaId(string $path): ?int
    {
        if (array_key_exists($path, $this->media)) return $this->media[$path];
        $bytes = $this->zip->getFromName($path);
        if ($bytes === false) return $this->media[$path] = null;
        $id = ($this->saveImage)($bytes, basename($path));
        if (!$id) $this->skipped++;
        return $this->media[$path] = $id;
    }

    private function imageFromRel(?string $rid): ?int
    {
        if (!$rid || empty($this->rels[$rid]) || $this->rels[$rid]['external']) return null;
        return $this->mediaId($this->mediaPath($this->rels[$rid]['target']));
    }

    // ---------------- Block level ----------------
    private function blocks(DOMElement $container): string
    {
        $out = [];
        $list = null;
        $flush = function () use (&$list, &$out) {
            if ($list) $out[] = "<{$list['type']}>" . implode('', array_map(fn ($i) => "<li>$i</li>", $list['items'])) . "</{$list['type']}>";
            $list = null;
        };
        foreach (self::kids($container) as $el) {
            switch (self::ln($el)) {
                case 'p':
                    $p = $this->paragraph($el);
                    if ($p['list'] && $p['text'] !== '') {
                        if (!$list || $list['type'] !== $p['list']) { $flush(); $list = ['type' => $p['list'], 'items' => []]; }
                        $list['items'][] = $p['text'];
                        if ($p['after']) { $flush(); $out[] = $p['after']; }
                        break;
                    }
                    $flush();
                    if ($p['html'] !== '') $out[] = $p['html'];
                    break;
                case 'tbl':
                    $flush();
                    $out[] = $this->table($el);
                    break;
                case 'sdt':
                    $flush();
                    $c = self::child($el, 'sdtContent');
                    if ($c) $out[] = $this->blocks($c);
                    break;
                case 'customXml':
                case 'ins':
                    $flush();
                    $out[] = $this->blocks($el);
                    break;
                case 'AlternateContent':
                    $flush();
                    $choice = self::child($el, 'Choice') ?: self::child($el, 'Fallback');
                    if ($choice) $out[] = $this->blocks($choice);
                    break;
            }
        }
        $flush();
        return implode("\n", array_filter($out, fn ($s) => $s !== ''));
    }

    private function paragraph(DOMElement $p): array
    {
        $tag = 'p';
        $list = null;
        $pPr = self::child($p, 'pPr');
        if ($pPr) {
            $st = self::child($pPr, 'pStyle');
            $name = $st ? ($this->styles[(string) self::attr($st, 'val')] ?? strtolower((string) self::attr($st, 'val'))) : '';
            if ($name === 'title') $tag = 'h2';
            elseif (preg_match('/^heading\s*(\d)/', $name, $m)) $tag = ((int) $m[1] <= 1) ? 'h2' : (((int) $m[1] === 2) ? 'h3' : 'h4');
            elseif (str_contains($name, 'quote')) $tag = 'blockquote';
            $num = self::child($pPr, 'numPr');
            if ($num) {
                $numId = self::child($num, 'numId');
                $ilvl = self::child($num, 'ilvl');
                $nid = $numId ? (string) self::attr($numId, 'val') : '0';
                if ($nid !== '0') {
                    $fmt = $this->numFmt[$nid][$ilvl ? (int) self::attr($ilvl, 'val') : 0] ?? 'bullet';
                    $list = in_array($fmt, ['bullet', 'none'], true) ? 'ul' : 'ol';
                }
            } elseif (preg_match('/list (bullet|number)/', $name, $m)) {
                $list = $m[1] === 'bullet' ? 'ul' : 'ol';
            }
        }
        $images = [];
        $boxes = [];
        $inline = self::tidy($this->inlines($p, $images, $boxes));
        $hasText = trim(preg_replace('/(&nbsp;|\xc2\xa0|<br>)/', '', strip_tags($inline, '<br>'))) !== '';
        $after = implode("\n", array_merge(array_map(fn ($id) => '<p>' . Html::imgTag($id) . '</p>', $images), $boxes));
        if ($list && $hasText) return ['list' => $list, 'text' => $inline, 'after' => $after, 'html' => ''];
        $html = $hasText ? ($tag === 'blockquote' ? "<blockquote><p>$inline</p></blockquote>" : "<$tag>$inline</$tag>") : '';
        if ($after !== '') $html .= ($html !== '' ? "\n" : '') . $after;
        return ['list' => null, 'text' => '', 'after' => '', 'html' => $html];
    }

    private static function tidy(string $s): string
    {
        for ($i = 0; $i < 3; $i++) $s = preg_replace('#</(strong|em|u|s|sup|sub)>(\s*)<\1>#', '$2', $s);
        $s = preg_replace('#<(strong|em|u|s|sup|sub)>(\s*)</\1>#', '$2', $s);
        $s = preg_replace('#^(\s|<br>)+|(\s|<br>)+$#', '', $s);
        return $s;
    }

    private function table(DOMElement $tbl): string
    {
        $rows = [];
        foreach (self::kids($tbl, 'tr') as $tr) {
            $cells = [];
            foreach (self::kids($tr, 'tc') as $tc) {
                $parts = [];
                foreach (self::kids($tc) as $c) {
                    if (self::ln($c) === 'p') {
                        $images = [];
                        $boxes = [];
                        $t = self::tidy($this->inlines($c, $images, $boxes));
                        if ($t !== '') $parts[] = $t;
                        foreach ($images as $id) $parts[] = Html::imgTag($id);
                    } elseif (self::ln($c) === 'tbl') {
                        $parts[] = $this->table($c);
                    }
                }
                $cells[] = '<td>' . implode('<br>', $parts) . '</td>';
            }
            if ($cells) $rows[] = '<tr>' . implode('', $cells) . '</tr>';
        }
        return $rows ? '<table><tbody>' . implode('', $rows) . '</tbody></table>' : '';
    }

    // ---------------- Inline level ----------------
    private function inlines(DOMElement $container, array &$images, array &$boxes): string
    {
        $out = '';
        foreach (self::kids($container) as $el) {
            switch (self::ln($el)) {
                case 'r':
                    $out .= $this->runEl($el, $images, $boxes);
                    break;
                case 'hyperlink':
                    $inner = $this->inlines($el, $images, $boxes);
                    $rid = self::attr($el, 'id');
                    $href = ($rid && !empty($this->rels[$rid]['external'])) ? $this->rels[$rid]['target'] : null;
                    $out .= $href && preg_match('#^(https?:|mailto:)#i', $href) ? '<a href="' . Html::e($href) . '">' . $inner . '</a>' : $inner;
                    break;
                case 'ins': case 'smartTag': case 'customXml': case 'fldSimple': case 'dir': case 'bdo':
                    $out .= $this->inlines($el, $images, $boxes);
                    break;
                case 'sdt':
                    $c = self::child($el, 'sdtContent');
                    if ($c) $out .= $this->inlines($c, $images, $boxes);
                    break;
                case 'AlternateContent':
                    $choice = self::child($el, 'Choice') ?: self::child($el, 'Fallback');
                    if ($choice) $out .= $this->inlines($choice, $images, $boxes);
                    break;
            }
        }
        return $out;
    }

    private function runEl(DOMElement $r, array &$images, array &$boxes): string
    {
        $wrap = [];
        $rPr = self::child($r, 'rPr');
        if ($rPr) {
            $on = function (string $local) use ($rPr): bool {
                $e = self::child($rPr, $local);
                if (!$e) return false;
                $v = self::attr($e, 'val');
                return $v === null || !in_array(strtolower($v), ['0', 'false', 'none', 'off'], true);
            };
            if ($on('b')) $wrap[] = 'strong';
            if ($on('i')) $wrap[] = 'em';
            if ($on('u')) $wrap[] = 'u';
            if ($on('strike') || $on('dstrike')) $wrap[] = 's';
            $va = self::child($rPr, 'vertAlign');
            if ($va) {
                $v = self::attr($va, 'val');
                if ($v === 'superscript') $wrap[] = 'sup';
                if ($v === 'subscript') $wrap[] = 'sub';
            }
        }
        $text = '';
        $this->runContent($r, $text, $images, $boxes);
        if ($text === '' || !$wrap || trim(strip_tags($text, '<br>')) === '') return $text;
        foreach ($wrap as $t) $text = "<$t>$text</$t>";
        return $text;
    }

    private function runContent(DOMElement $r, string &$text, array &$images, array &$boxes): void
    {
        foreach (self::kids($r) as $c) {
            switch (self::ln($c)) {
                case 't':
                    $text .= Html::e($c->textContent);
                    break;
                case 'tab':
                    $text .= ' ';
                    break;
                case 'br':
                    if (self::attr($c, 'type') !== 'page' && self::attr($c, 'type') !== 'column') $text .= '<br>';
                    break;
                case 'cr':
                    $text .= '<br>';
                    break;
                case 'noBreakHyphen':
                    $text .= '-';
                    break;
                case 'drawing':
                    $this->drawing($c, $images, $boxes);
                    break;
                case 'pict':
                    $this->vml($c, $images, $boxes);
                    break;
                case 'AlternateContent':
                    $choice = self::child($c, 'Choice');
                    $useChoice = $choice && (self::desc($choice, 'blip') || self::desc($choice, 'txbxContent'));
                    $branch = $useChoice ? $choice : (self::child($c, 'Fallback') ?: $choice);
                    if ($branch) $this->runContent($branch, $text, $images, $boxes);
                    break;
            }
        }
    }

    private static function inTextBox(DOMNode $el, DOMNode $stop): bool
    {
        for ($n = $el->parentNode; $n && $n !== $stop; $n = $n->parentNode) if (self::ln($n) === 'txbxContent') return true;
        return false;
    }

    private function drawing(DOMElement $d, array &$images, array &$boxes): void
    {
        foreach (self::desc($d, 'blip') as $blip) {
            if (self::inTextBox($blip, $d)) continue; // handled with the text box below
            $id = $this->imageFromRel(self::attr($blip, 'embed'));
            if ($id) $images[] = $id;
        }
        foreach (self::desc($d, 'txbxContent') as $tb) {
            $html = $this->blocks($tb);
            if ($html !== '') $boxes[] = $html;
        }
    }

    private function vml(DOMElement $pict, array &$images, array &$boxes): void
    {
        foreach (self::desc($pict, 'imagedata') as $img) {
            if (self::inTextBox($img, $pict)) continue;
            $id = $this->imageFromRel(self::attr($img, 'id'));
            if ($id) $images[] = $id;
        }
        foreach (self::desc($pict, 'txbxContent') as $tb) {
            $html = $this->blocks($tb);
            if ($html !== '') $boxes[] = $html;
        }
    }
}
