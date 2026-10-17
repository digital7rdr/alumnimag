<?php
declare(strict_types=1);

// Files are write-once. Nothing in this app deletes a stored file automatically: stage changes only
// change the article's status, and AI editing only produces new text. That is what prevents the old
// "photos disappear after AI edit / after moving folders" problems.
final class Storage
{
    public static function root(): string
    {
        return Config::storageDir();
    }

    public static function ensureDirs(): void
    {
        foreach (['', '/files', '/tmp', '/library'] as $d) {
            $p = self::root() . $d;
            if (!is_dir($p)) @mkdir($p, 0775, true);
        }
    }

    /** Folder/file names that are safe on Linux and Windows but still readable in a file manager. */
    public static function fsName(?string $name, string $fallback = 'untitled'): string
    {
        $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '-', (string) $name);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace('/^[.\s-]+|[.\s-]+$/u', '', $s);
        $s = mb_substr($s, 0, 120);
        return $s !== '' ? $s : $fallback;
    }

    private static function safeBase(string $name): array
    {
        $ext = strtolower(preg_replace('/[^.a-z0-9]/i', '', (string) pathinfo($name, PATHINFO_EXTENSION)));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\w\- ]+/u', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base));
        $base = substr(preg_replace('/\s+/', '-', trim((string) $base)), 0, 60) ?: 'file';
        return [$base, $ext !== '' ? ".$ext" : ''];
    }

    private static function inside(string $full): string
    {
        $root = realpath(self::root()) ?: self::root();
        $norm = self::normalise($full);
        if (!str_starts_with($norm, rtrim($root, '/') . '/')) throw new AppError('Invalid file path');
        return $norm;
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') { array_pop($parts); continue; }
            $parts[] = $p;
        }
        return '/' . implode('/', $parts);
    }

    /** stored_name is either a bare name (kept in files/) or a path relative to the storage root. */
    public static function filePath(string $storedName): string
    {
        $rel = str_contains($storedName, '/') ? $storedName : 'files/' . $storedName;
        return self::inside(self::root() . '/' . $rel);
    }

    public static function absDir(string $relDir): string
    {
        return self::inside(self::root() . '/' . $relDir);
    }

    /** Writes without ever overwriting: "photo.jpg" -> "photo (2).jpg" when the name is taken. */
    public static function writeUnique(string $dirAbs, string $name, string $data): string
    {
        if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
        for ($i = 1; $i < 1000; $i++) {
            $candidate = $i === 1 ? $name : "$base ($i)" . ($ext !== '' ? ".$ext" : '');
            $fh = @fopen("$dirAbs/$candidate", 'x');
            if ($fh) {
                fwrite($fh, $data);
                fclose($fh);
                return $candidate;
            }
        }
        throw new AppError('Too many files with the same name');
    }

    public static function save(string $data, array $o): array
    {
        self::ensureDirs();
        $original = (string) ($o['originalName'] ?? 'file');
        if (!empty($o['dir'])) {
            [, $ext] = self::safeBase($original);
            $base = self::fsName(pathinfo($original, PATHINFO_FILENAME), 'file');
            $stored = rtrim($o['dir'], '/') . '/' . self::writeUnique(self::absDir($o['dir']), $base . $ext, $data);
        } else {
            [$base, $ext] = self::safeBase($original);
            $stored = base_convert((string) (int) (microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(4)) . "-$base$ext";
            $fh = fopen(self::filePath($stored), 'x');
            fwrite($fh, $data);
            fclose($fh);
        }
        $sha1 = sha1($data);
        $id = Db::exec('INSERT INTO files (article_id, edition_id, folder_id, kind, original_name, stored_name, mime, size, sha1, origin, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $o['articleId'] ?? null, $o['editionId'] ?? null, $o['folderId'] ?? null, $o['kind'], mb_substr($original, 0, 290),
            $stored, $o['mime'] ?? 'application/octet-stream', strlen($data), $sha1, $o['origin'] ?? null, (int) ($o['sortOrder'] ?? 0),
        ]);
        return ['id' => $id, 'storedName' => $stored, 'sha1' => $sha1, 'size' => strlen($data)];
    }

    public static function read(string $storedName): string
    {
        $p = self::filePath($storedName);
        if (!is_file($p)) throw new AppError('A stored file is missing on the server: ' . basename($storedName), 404);
        return (string) file_get_contents($p);
    }
}
