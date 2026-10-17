<?php
declare(strict_types=1);

// Minimal .xlsx / .csv reader: returns [sheet name => rows], each row an array of cell strings (0-based columns).
final class Xlsx
{
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv') return [pathinfo($originalName, PATHINFO_FILENAME) => self::csv($path)];
        if ($ext !== 'xlsx') throw new AppError('Upload an Excel (.xlsx) or CSV file');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new AppError('This Excel file could not be opened');

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $d = self::dom($xml);
            foreach ($d->getElementsByTagName('si') as $si) {
                $t = '';
                foreach ($si->getElementsByTagName('t') as $node) $t .= $node->textContent;
                $shared[] = $t;
            }
        }
        $rels = [];
        if (($xml = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            foreach (self::dom($xml)->getElementsByTagName('Relationship') as $r) $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
        $out = [];
        $wb = self::dom((string) $zip->getFromName('xl/workbook.xml'));
        foreach ($wb->getElementsByTagName('sheet') as $s) {
            $rid = '';
            foreach ($s->attributes as $a) if ($a->localName === 'id') $rid = $a->value;
            $target = $rels[$rid] ?? '';
            $file = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $xml = $zip->getFromName($file);
            if ($xml === false) continue;
            $out[$s->getAttribute('name')] = self::sheet(self::dom($xml), $shared);
        }
        $zip->close();
        return $out;
    }

    private static function dom(string $xml): DOMDocument
    {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $d;
    }

    private static function col(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    private static function sheet(DOMDocument $d, array $shared): array
    {
        $rows = [];
        foreach ($d->getElementsByTagName('row') as $row) {
            $cells = [];
            foreach ($row->getElementsByTagName('c') as $c) {
                $t = $c->getAttribute('t');
                $v = '';
                if ($t === 'inlineStr') {
                    foreach ($c->getElementsByTagName('t') as $node) $v .= $node->textContent;
                } else {
                    $vn = $c->getElementsByTagName('v')->item(0);
                    $raw = $vn ? $vn->textContent : '';
                    $v = $t === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                }
                $v = trim(str_replace("\xc2\xa0", ' ', $v));
                if ($v !== '') $cells[self::col($c->getAttribute('r'))] = $v;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $line = array_fill(0, $max + 1, '');
                foreach ($cells as $i => $v) $line[$i] = $v;
                $rows[] = $line;
            }
        }
        return $rows;
    }

    private static function csv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        $first = fgets($fh);
        $sep = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (str_contains((string) $first, "\t") ? "\t" : ',');
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            $r = array_map(fn ($v) => trim(str_replace("\xc2\xa0", ' ', (string) $v), " \t\n\r\0\x0B\xEF\xBB\xBF"), $r);
            if (array_filter($r, fn ($v) => $v !== '')) $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }
}
