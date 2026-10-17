<?php
declare(strict_types=1);

final class Html
{
    private const ALLOWED = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'em', 'u', 's', 'blockquote', 'ul', 'ol', 'li',
        'a', 'img', 'figure', 'figcaption', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'sup', 'sub', 'span'];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'strike' => 's', 'del' => 's', 'h5' => 'h4', 'h6' => 'h4'];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea'];
    private const ATTRS = ['a' => ['href', 'target', 'rel'], 'img' => ['src', 'alt', 'data-file-id', 'width', 'height']];

    public static function e($s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function load(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="__root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root') ?: $doc->getElementsByTagName('div')->item(0);
        return [$doc, $root];
    }

    private static function inner(DOMDocument $doc, DOMNode $root): string
    {
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) $out .= $doc->saveHTML($c);
        return $out;
    }

    private static function okUrl(string $url, bool $img): bool
    {
        $u = trim($url);
        if ($u === '') return false;
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) {
            $scheme = strtolower($m[1]);
            return $img ? in_array($scheme, ['http', 'https', 'data'], true) : in_array($scheme, ['http', 'https', 'mailto'], true);
        }
        return !str_starts_with($u, '//'); // relative URLs are fine, protocol-relative are not
    }

    /** Allow-list sanitiser (same rules as the old sanitize-html setup). */
    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') return '';
        [$doc, $root] = self::load($html);
        self::walk($doc, $root);
        return trim(self::inner($doc, $root));
    }

    private static function walk(DOMDocument $doc, DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            $tag = self::RENAME[$tag] ?? $tag;
            if (!in_array($tag, self::ALLOWED, true)) {
                self::walk($doc, $child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            if ($tag !== strtolower($child->tagName)) {
                $new = $doc->createElement($tag);
                foreach (iterator_to_array($child->attributes) as $a) $new->setAttribute($a->name, $a->value);
                while ($child->firstChild) $new->appendChild($child->firstChild);
                $node->replaceChild($new, $child);
                $child = $new;
            }
            $allowed = array_merge(self::ATTRS[$tag] ?? [], ['class']);
            foreach (iterator_to_array($child->attributes) as $a) {
                $name = strtolower($a->name);
                if (!in_array($name, $allowed, true)
                    || ($name === 'href' && !self::okUrl($a->value, false))
                    || ($name === 'src' && !self::okUrl($a->value, true))) {
                    $child->removeAttribute($a->name);
                }
            }
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            self::walk($doc, $child);
        }
    }

    public static function plainText(?string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h\d|\/li)[^>]*>/i', ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    private static function inlineMd(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/su', '<strong>$1</strong>', $text);
        return preg_replace('/(^|[^*])\*(?!\s)([^*\n]+?)\*(?!\*)/u', '$1<em>$2</em>', $text);
    }

    /** Converts stray Markdown left inside HTML (e.g. "<p>**Heading**</p>", "* item"). */
    public static function fixMarkdown(?string $html): string
    {
        $out = (string) $html;
        $out = preg_replace('/<p>\s*\*\*([^*<]{2,160}?)\*\*\s*<\/p>/u', '<h3>$1</h3>', $out);
        $out = preg_replace('/<p>\s*#{1,3}\s+([^<]+)<\/p>/u', '<h3>$1</h3>', $out);
        // "<p>* a<br>* b</p>" (bullets typed on consecutive lines) becomes a list.
        $out = preg_replace_callback('/<p>((?:\s*[*\-•]\s+[^<]*?(?:<br\s*\/?>)?)+)<\/p>/u', function ($m) {
            $lines = array_filter(array_map('trim', preg_split('/<br\s*\/?>/', $m[1])), fn ($l) => $l !== '');
            if (count($lines) < 2) return $m[0];
            return '<ul>' . implode('', array_map(fn ($l) => '<li>' . preg_replace('/^[*\-•]\s+/u', '', $l) . '</li>', $lines)) . '</ul>';
        }, $out);
        $out = preg_replace_callback('/(?:<p>\s*[*\-•]\s+[^<]*?<\/p>\s*)+/u', function ($m) {
            preg_match_all('/<p>\s*[*\-•]\s+([^<]*?)<\/p>/u', $m[0], $items);
            return '<ul>' . implode('', array_map(fn ($i) => "<li>$i</li>", $items[1])) . '</ul>';
        }, $out);
        return preg_replace_callback('/>([^<]+)</u', fn ($m) => '>' . self::inlineMd($m[1]) . '<', $out);
    }

    /** Plain text (PDF, pasted text) -> paragraphs. */
    public static function textToHtml(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $blocks = array_filter(array_map('trim', preg_split('/\n\s*\n+/', $text)), fn ($b) => $b !== '');
        return implode("\n", array_map(fn ($b) => '<p>' . str_replace("\n", '<br>', self::e($b)) . '</p>', $blocks));
    }

    public static function imgTag($id): string
    {
        $id = (int) $id;
        return "<img src=\"/files/$id\" data-file-id=\"$id\" alt=\"\">";
    }

    /** Images become [[IMG:id]] before the AI sees the text, so the AI can never drop or alter a photo. */
    public static function imagesToTokens(?string $html): array
    {
        $ids = [];
        $out = preg_replace_callback('/<figure[^>]*>\s*(<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>)[\s\S]*?<\/figure>/u', function ($m) use (&$ids) {
            $ids[] = (int) $m[2];
            return "<p>[[IMG:{$m[2]}]]</p>";
        }, (string) $html);
        $out = preg_replace_callback('/<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>/u', function ($m) use (&$ids) {
            if (!in_array((int) $m[1], $ids, true)) $ids[] = (int) $m[1];
            return "[[IMG:{$m[1]}]]";
        }, $out);
        return ['html' => $out, 'ids' => $ids];
    }

    public static function tokensToImages(?string $html, array $expected = []): array
    {
        $seen = [];
        $out = preg_replace_callback('/<p>\s*\[\[IMG:(\d+)\]\]\s*<\/p>/u', function ($m) use (&$seen) {
            $seen[(int) $m[1]] = true;
            return '<p>' . self::imgTag($m[1]) . '</p>';
        }, (string) $html);
        $out = preg_replace_callback('/\[\[IMG:(\d+)\]\]/u', function ($m) use (&$seen) {
            $seen[(int) $m[1]] = true;
            return self::imgTag($m[1]);
        }, $out);
        // Safety net: any image the AI dropped is put back at the end - photos are never lost.
        $missing = array_values(array_filter($expected, fn ($id) => !isset($seen[(int) $id])));
        if ($missing) $out .= "\n" . implode("\n", array_map(fn ($id) => '<p>' . self::imgTag($id) . '</p>', $missing));
        return ['html' => $out, 'restored' => $missing];
    }

    /** Browser editors keep only src on <img>; restore our data-file-id from ".../files/ID". */
    public static function normalizeImgs(?string $html): string
    {
        return preg_replace_callback('/<img\b([^>]*?)\bsrc="(?:[^"]*?)\/files\/(\d+)"([^>]*)>/u', fn ($m) => self::imgTag($m[2]), (string) $html);
    }

    public static function imageIdsIn(?string $html): array
    {
        preg_match_all('/data-file-id="(\d+)"/', (string) $html, $m);
        return array_map('intval', $m[1]);
    }
}
