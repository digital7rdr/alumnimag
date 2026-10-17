<?php
declare(strict_types=1);

final class Intake
{
    public const WEB_IMAGE = '/^image\/(jpeg|png|gif|webp)$/';
    public const ALLOWED_EXT = ['.docx', '.doc', '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.txt', '.md', '.rtf', '.odt'];
    private const EXT_MIME = [
        '.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.doc' => 'application/msword', '.pdf' => 'application/pdf',
        '.jpg' => 'image/jpeg', '.jpeg' => 'image/jpeg', '.png' => 'image/png', '.gif' => 'image/gif', '.webp' => 'image/webp',
        '.txt' => 'text/plain', '.md' => 'text/markdown', '.rtf' => 'application/rtf', '.odt' => 'application/vnd.oasis.opendocument.text',
        '.emf' => 'image/x-emf', '.wmf' => 'image/x-wmf', '.tif' => 'image/tiff', '.tiff' => 'image/tiff', '.bmp' => 'image/bmp', '.svg' => 'image/svg+xml',
    ];

    public static function ext(string $name): string
    {
        $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $e !== '' ? ".$e" : '';
    }

    public static function mimeFor(string $name, ?string $given = null): string
    {
        return self::EXT_MIME[self::ext($name)] ?? ($given ?: 'application/octet-stream');
    }

    public static function isAllowed(string $name): bool
    {
        return in_array(self::ext($name), self::ALLOWED_EXT, true);
    }

    public static function isWebImage(string $name, ?string $mime = null): bool
    {
        return (bool) preg_match(self::WEB_IMAGE, self::mimeFor($name, $mime));
    }

    /** Uploaded files from $_FILES[field] -> [['name','type','data'], ...], with friendly errors. */
    public static function uploads(string $field = 'files'): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax = self::iniBytes((string) ini_get('post_max_size'));
        if ($postMax && $len > $postMax) throw new AppError('The upload is too large for the server (limit ' . ini_get('post_max_size') . '). Upload fewer files at a time.');
        $f = $_FILES[$field] ?? null;
        if (!$f) return [];
        $names = (array) $f['name'];
        $out = [];
        foreach ($names as $i => $name) {
            $err = (int) ((array) $f['error'])[$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new AppError("$name is larger than the server allows (" . ini_get('upload_max_filesize') . ').');
            if ($err !== UPLOAD_ERR_OK) throw new AppError("Upload of $name failed (error $err). Please try again.");
            if (!self::isAllowed($name)) throw new AppError("File type not allowed: $name. Allowed: " . implode(' ', self::ALLOWED_EXT));
            $size = (int) ((array) $f['size'])[$i];
            if ($size > Config::maxUploadMb() * 1048576) throw new AppError("$name is larger than " . Config::maxUploadMb() . ' MB.');
            $out[] = ['name' => $name, 'type' => (string) ((array) $f['type'])[$i], 'data' => (string) file_get_contents(((array) $f['tmp_name'])[$i])];
        }
        if (count($out) > 40) throw new AppError('Upload at most 40 files at a time.');
        return $out;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') return 0;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    public static function guessTitle(string $body, ?string $fallback): string
    {
        $t = null;
        if (preg_match('/^\s*<p>\s*TITLE:\s*([^<]+)<\/p>/i', $body, $m)) $t = $m[1];
        elseif (preg_match('/<h[1-3][^>]*>([\s\S]*?)<\/h[1-3]>/i', $body, $m)) $t = $m[1];
        elseif (preg_match('/<p>(?:<strong>)?([^<]{4,140})(?:<\/strong>)?<\/p>/i', $body, $m)) $t = $m[1];
        $clean = trim(preg_replace('/^TITLE:\s*/i', '', Html::plainText($t ?? '')));
        return mb_substr($clean !== '' ? $clean : ($fallback ?: 'Untitled'), 0, 290);
    }

    public static function pdfText(string $data): string
    {
        $parser = new \Smalot\PdfParser\Parser();
        return $parser->parseContent($data)->getText();
    }

    /**
     * $uploads: new files [['name','type','data']] - saved here.
     * $storedRows: rows from the files table already gathered in a folder - linked to the new article
     * (never copied or moved) and their text/pictures extracted.
     */
    public static function createArticle(array $o): array
    {
        $editionId = (int) $o['editionId'];
        $source = $o['source'] ?? 'upload';
        $title = trim((string) ($o['title'] ?? ''));
        $userId = $o['userId'] ?? null;
        $articleId = Db::exec('INSERT INTO articles (edition_id, section_id, title, author_name, author_email, source, created_by, folder_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $editionId, ($o['sectionId'] ?? null) ?: null, mb_substr($title !== '' ? $title : 'Untitled', 0, 290),
            ($o['authorName'] ?? null) ?: null, ($o['authorEmail'] ?? null) ?: null, $source, $userId, $o['folderId'] ?? null,
        ]);
        $ctx = ['articleId' => $articleId, 'editionId' => $editionId];

        $body = !empty($o['bodyHtml']) ? Html::clean(Html::normalizeImgs($o['bodyHtml'])) : '';
        $notes = [];
        $imageIds = [];
        $derived = null;

        $items = [];
        foreach ($o['storedFiles'] ?? [] as $row) $items[] = ['row' => $row, 'name' => $row['original_name'], 'type' => $row['mime']];
        foreach ($o['uploads'] ?? [] as $u) $items[] = ['upload' => $u, 'name' => $u['name'], 'type' => $u['type']];

        $order = 0;
        $saveImage = function (string $bytes, string $name) use ($ctx, &$order): ?int {
            $mime = self::mimeFor($name);
            if (!preg_match(self::WEB_IMAGE, $mime)) {
                // EMF/WMF/TIFF etc. cannot be shown on the web: keep the original as an attachment.
                Storage::save($bytes, $ctx + ['kind' => 'attachment', 'mime' => $mime, 'originalName' => $name, 'origin' => 'docx']);
                return null;
            }
            $order++;
            return Storage::save($bytes, $ctx + ['kind' => 'image', 'mime' => $mime, 'originalName' => $name, 'origin' => 'docx', 'sortOrder' => $order])['id'];
        };

        foreach ($items as $it) {
            $name = $it['name'];
            $mime = self::mimeFor($name, $it['type']);
            $ext = self::ext($name);
            $isImage = (bool) preg_match(self::WEB_IMAGE, $mime);
            if (isset($it['row'])) {
                $data = Storage::read($it['row']['stored_name']);
                Db::exec('UPDATE files SET article_id = ?, kind = ?, sort_order = ? WHERE id = ?',
                    [$articleId, $isImage ? 'image' : 'source', $isImage ? 100 + count($imageIds) : 0, $it['row']['id']]);
                if ($isImage) { $imageIds[] = (int) $it['row']['id']; continue; }
            } else {
                $data = $it['upload']['data'];
                if ($isImage) {
                    $imageIds[] = Storage::save($data, $ctx + ['kind' => 'image', 'mime' => $mime, 'originalName' => $name, 'origin' => 'upload', 'sortOrder' => 100 + count($imageIds)])['id'];
                    continue;
                }
                // Keep every source document exactly as uploaded.
                Storage::save($data, $ctx + ['kind' => 'source', 'mime' => $mime, 'originalName' => $name, 'origin' => 'upload']);
            }

            try {
                $base = pathinfo($name, PATHINFO_FILENAME);
                if ($ext === '.docx') {
                    $r = Docx::convert($data, $saveImage);
                    $derived = $derived ?: self::guessTitle($r['html'], $base);
                    $body .= ($body !== '' ? "\n" : '') . $r['html'];
                    if ($r['extra']) $notes[] = "{$r['extra']} picture(s) in $name are not placed in the main text (e.g. in a header) and were added at the end - move or remove them in the editor.";
                    if ($r['skipped']) $notes[] = "{$r['skipped']} picture(s) in $name are in a format browsers cannot show (EMF/WMF/TIFF); they are kept under Files.";
                } elseif ($ext === '.pdf') {
                    $b = Html::textToHtml(self::pdfText($data));
                    $derived = $derived ?: self::guessTitle($b, $base);
                    $body .= ($body !== '' ? "\n" : '') . $b;
                    $notes[] = "Text extracted from PDF $name. Upload its photos separately as JPG/PNG.";
                } elseif ($ext === '.txt' || $ext === '.md') {
                    $b = Html::fixMarkdown(Html::textToHtml($data));
                    $derived = $derived ?: self::guessTitle($b, $base);
                    $body .= ($body !== '' ? "\n" : '') . $b;
                } else {
                    $notes[] = "$name was stored, but its text cannot be read automatically ($ext). Save it as .docx and upload again, or paste the text in the editor.";
                }
            } catch (Throwable $e) {
                $notes[] = "Could not read $name: " . $e->getMessage();
            }
        }

        if ($imageIds) $body .= "\n" . implode("\n", array_map(fn ($id) => '<p>' . Html::imgTag($id) . '</p>', $imageIds));
        $body = preg_replace('/^\s*<p>\s*TITLE:\s*[^<]*<\/p>\s*/i', '', Html::fixMarkdown($body));
        $body = Html::clean($body);
        $finalTitle = $title !== '' ? $title : ($derived ?: 'Untitled');
        $firstImage = Html::imageIdsIn($body)[0] ?? null;

        Db::exec('UPDATE articles SET title = ?, raw_html = ?, featured_file_id = ?, ai_notes = ? WHERE id = ?',
            [mb_substr($finalTitle, 0, 290), $body, $firstImage, $notes ? implode("\n", $notes) : null, $articleId]);
        Db::exec("INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, 'Original', ?, ?, ?)", [$articleId, $finalTitle, $body, $userId]);
        Activity::log($articleId, $editionId, $userId, 'created', $source . ': ' . (implode(', ', array_column($items, 'name')) ?: 'editor'));
        return ['articleId' => $articleId, 'notes' => $notes];
    }
}
