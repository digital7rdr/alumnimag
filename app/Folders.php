<?php
declare(strict_types=1);

// Year > Issue > stage folders > section folders.
//
// The folder tree is created on disk under STORAGE_DIR/library/<year>/<issue>/ so it can also be
// browsed in the hosting File Manager. Files are gathered into the Raw folders, then "processed" into
// articles. Later stage folders show the articles that are at that stage, and when an article reaches
// a stage a copy (article.html + photos + original files) is written into that stage's folder.
// Nothing is ever moved or deleted, so photos cannot go missing between Raw, Compiled and Final.
final class Folders
{
    public const STAGE_KEYS = ['raw', 'edited', 'in_review', 'compiled', 'published'];
    private const STATUSES = ['raw' => ['raw', 'reviewing'], 'edited' => ['edited'], 'in_review' => ['in_review'], 'compiled' => ['compiled'], 'published' => ['published']];
    private const ALIASES = ['raw' => 'raw', 'edited' => 'edited', 'ai edited' => 'edited', 'ai' => 'edited', 'in_review' => 'in_review', 'review' => 'in_review',
        'reviewers' => 'in_review', 'compiled' => 'compiled', 'published' => 'published', 'final' => 'published', 'wordpress' => 'published'];

    public static function parseTemplate(?string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') continue;
            $parts = array_map('trim', explode('=', $line, 2));
            $name = Storage::fsName($parts[0], '');
            if ($name === '') continue;
            $stagePart = $parts[1] ?? '';
            $stage = $stagePart !== '' ? (self::ALIASES[strtolower($stagePart)] ?? null) : null;
            if ($stagePart !== '' && !$stage) throw new AppError("Unknown stage \"$stagePart\" in the folder template. Use one of: " . implode(', ', self::STAGE_KEYS));
            $out[] = ['name' => $name, 'stage' => $stage];
        }
        if (!$out) throw new AppError('The folder template is empty');
        return $out;
    }

    private static function sectionFolderName(array $s): string
    {
        return Storage::fsName("Section {$s['num']} - {$s['name']}");
    }

    private static function findOrCreate(int $editionId, ?int $parentId, string $name, string $kind, ?string $stage, ?int $sectionId, int $sort, string $relPath): array
    {
        $f = Db::one('SELECT * FROM folders WHERE edition_id = ? AND parent_id <=> ? AND name = ?', [$editionId, $parentId, $name]);
        $created = false;
        if (!$f) {
            $id = Db::exec('INSERT INTO folders (edition_id, parent_id, name, kind, stage, section_id, sort, path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$editionId, $parentId, $name, $kind, $stage, $sectionId, $sort, $relPath]);
            $f = Db::one('SELECT * FROM folders WHERE id = ?', [$id]);
            $created = true;
        } elseif ($f['stage'] !== $stage || (int) $f['sort'] !== $sort || $f['kind'] !== $kind) {
            Db::exec('UPDATE folders SET stage = ?, sort = ?, kind = ? WHERE id = ?', [$stage, $sort, $kind, $f['id']]);
        }
        $abs = Storage::absDir($f['path']);
        if (!is_dir($abs)) mkdir($abs, 0775, true);
        return [$f, $created];
    }

    /** Creates any folders from the template that are missing. Safe to run again at any time. */
    public static function generate(int $editionId, ?int $userId = null): array
    {
        $e = Db::one('SELECT * FROM editions WHERE id = ?', [$editionId]);
        if (!$e) throw new AppError('Issue not found', 404);
        $s = Settings::all();
        $template = self::parseTemplate($s['folder_template']);
        $sections = $s['folder_sections'] === '1' ? Db::q('SELECT * FROM sections ORDER BY num') : [];
        $root = 'library/' . (int) $e['year'] . '/' . Storage::fsName($e['name'], "Issue {$e['id']}");
        $created = 0;
        foreach ($template as $i => $t) {
            [$top, $c] = self::findOrCreate($editionId, null, $t['name'], $t['stage'] ? 'stage' : 'custom', $t['stage'], null, $i, "$root/{$t['name']}");
            $created += $c ? 1 : 0;
            foreach ($sections as $sec) {
                $name = self::sectionFolderName($sec);
                [, $c2] = self::findOrCreate($editionId, (int) $top['id'], $name, 'section', $t['stage'], (int) $sec['id'], (int) $sec['num'], "{$top['path']}/$name");
                $created += $c2 ? 1 : 0;
            }
        }
        if ($created) Activity::log(null, $editionId, $userId, 'folders_generated', "$created folder(s) created");
        return ['created' => $created, 'root' => $root];
    }

    private static function get(int $id): array
    {
        $f = Db::one('SELECT * FROM folders WHERE id = ?', [$id]);
        if (!$f) throw new AppError('Folder not found', 404);
        return $f;
    }

    /** Which articles a folder shows. Stage folders are live views of the articles at that stage. */
    private static function articleWhere(array $f): ?array
    {
        if ($f['kind'] === 'article') return ['a.folder_id = ?', [(int) $f['id']]];
        if (!$f['stage']) return null;
        $st = self::STATUSES[$f['stage']];
        $ph = implode(',', array_fill(0, count($st), '?'));
        // In Raw, articles made from an article folder are listed inside that folder instead.
        $own = $f['stage'] === 'raw' ? ' AND a.folder_id IS NULL' : '';
        if ($f['kind'] === 'section') return ["a.status IN ($ph) AND a.section_id = ?$own", [...$st, (int) $f['section_id']]];
        return ["a.status IN ($ph) AND a.section_id IS NULL$own", $st]; // stage folder itself: articles with no section
    }

    public static function tree(int $editionId): array
    {
        $folders = Db::q('SELECT * FROM folders WHERE edition_id = ? ORDER BY sort, name', [$editionId]);
        $waiting = [];
        foreach (Db::q('SELECT folder_id, COUNT(*) n FROM files WHERE edition_id = ? AND folder_id IS NOT NULL AND article_id IS NULL GROUP BY folder_id', [$editionId]) as $r) $waiting[(int) $r['folder_id']] = (int) $r['n'];
        $arts = Db::q('SELECT id, status, section_id, folder_id FROM articles WHERE edition_id = ?', [$editionId]);
        $byId = [];
        foreach ($folders as $f) {
            $f['id'] = (int) $f['id'];
            $f['waiting'] = $waiting[$f['id']] ?? 0;
            $f['articles'] = 0;
            if ($f['kind'] === 'article') {
                $f['articles'] = count(array_filter($arts, fn ($a) => (int) $a['folder_id'] === $f['id']));
            } elseif ($f['stage']) {
                $st = self::STATUSES[$f['stage']];
                $f['articles'] = count(array_filter($arts, fn ($a) => in_array($a['status'], $st, true)
                    && ($f['stage'] !== 'raw' || $a['folder_id'] === null)
                    && ($f['kind'] === 'section' ? (int) $a['section_id'] === (int) $f['section_id'] : $a['section_id'] === null)));
            }
            $f['children'] = [];
            $byId[$f['id']] = $f;
        }
        $build = function (?int $parent) use (&$build, $byId): array {
            $list = [];
            foreach ($byId as $f) {
                if (($f['parent_id'] === null ? null : (int) $f['parent_id']) !== $parent) continue;
                $f['children'] = $build($f['id']);
                $f['totalWaiting'] = $f['waiting'] + array_sum(array_column($f['children'], 'totalWaiting'));
                $f['totalArticles'] = $f['articles'] + array_sum(array_column($f['children'], 'totalArticles'));
                $list[] = $f;
            }
            usort($list, fn ($a, $b) => [(int) $a['sort'], $a['name']] <=> [(int) $b['sort'], $b['name']]);
            return $list;
        };
        return $build(null);
    }

    private static function articleFiles(int $articleId): array
    {
        return Db::q("SELECT id, kind, original_name, stored_name, mime, size FROM files WHERE article_id = ? AND kind IN ('image','source','attachment') ORDER BY kind = 'source' DESC, sort_order, id", [$articleId]);
    }

    public static function contents(int $folderId): array
    {
        $f = self::get($folderId);
        $chain = [];
        for ($p = $f; $p; $p = $p['parent_id'] ? Db::one('SELECT * FROM folders WHERE id = ?', [$p['parent_id']]) : null) array_unshift($chain, ['id' => (int) $p['id'], 'name' => $p['name']]);
        $find = function (array $list) use (&$find, $f) {
            foreach ($list as $x) {
                if ($x['id'] === (int) $f['id']) return $x;
                if ($y = $find($x['children'])) return $y;
            }
            return null;
        };
        $node = $find(self::tree((int) $f['edition_id']));
        $waiting = Db::q('SELECT id, kind, original_name, mime, size, created_at FROM files WHERE folder_id = ? AND article_id IS NULL ORDER BY original_name', [$f['id']]);
        $articles = [];
        if ($w = self::articleWhere($f)) {
            $articles = Db::q("SELECT a.id, a.title, a.status, a.author_name, a.section_id, a.featured_file_id, a.ai_queued, a.updated_at
                FROM articles a WHERE a.edition_id = ? AND {$w[0]} ORDER BY a.title", [(int) $f['edition_id'], ...$w[1]]);
            foreach ($articles as &$a) $a['files'] = array_map(function ($x) { unset($x['stored_name']); return $x; }, self::articleFiles((int) $a['id']));
            unset($a);
        }
        $sectionArticles = $f['section_id'] ? Db::q("SELECT id, title, status FROM articles WHERE edition_id = ? AND section_id = ? AND status <> 'reviewing' ORDER BY title", [$f['edition_id'], $f['section_id']]) : [];
        $children = $node ? array_map(function ($c) { unset($c['children']); return $c; }, $node['children']) : [];
        return [
            'folder' => $f + ['totalWaiting' => $node['totalWaiting'] ?? count($waiting), 'totalArticles' => $node['totalArticles'] ?? 0, 'children' => $children],
            'edition' => Db::one('SELECT id, year, name FROM editions WHERE id = ?', [$f['edition_id']]),
            'chain' => $chain, 'waiting' => $waiting, 'articles' => $articles, 'sectionArticles' => $sectionArticles,
            'canUpload' => !$f['stage'] || $f['stage'] === 'raw',
            'canProcess' => $f['stage'] === 'raw',
            'canSubfolder' => !$f['stage'] || $f['stage'] === 'raw',
            'diskPath' => $f['path'],
        ];
    }

    public static function uploadInto(int $folderId, array $uploads, ?int $userId): array
    {
        $f = self::get($folderId);
        if ($f['stage'] && $f['stage'] !== 'raw') throw new AppError('Files are gathered in the Raw folders (or in plain folders). Later stages fill themselves as articles move along.');
        $saved = [];
        foreach ($uploads as $u) {
            $mime = Intake::mimeFor($u['name'], $u['type']);
            $r = Storage::save($u['data'], ['originalName' => $u['name'], 'mime' => $mime, 'kind' => preg_match(Intake::WEB_IMAGE, $mime) ? 'image' : 'source',
                'editionId' => (int) $f['edition_id'], 'folderId' => (int) $f['id'], 'dir' => $f['path'], 'origin' => 'folder']);
            $saved[] = ['id' => $r['id'], 'name' => $u['name']];
        }
        Activity::log(null, (int) $f['edition_id'], $userId, 'files_gathered', count($saved) . " file(s) into {$f['name']}");
        return $saved;
    }

    public static function createSubfolder(int $parentId, ?string $name, ?int $userId): array
    {
        $p = self::get($parentId);
        if ($p['stage'] && $p['stage'] !== 'raw') throw new AppError('Article folders can only be made inside Raw');
        if ($p['kind'] === 'article') throw new AppError('An article folder cannot contain another folder');
        $clean = Storage::fsName($name, '');
        if ($clean === '') throw new AppError('Folder name is required');
        [$folder, $created] = self::findOrCreate((int) $p['edition_id'], (int) $p['id'], $clean, $p['stage'] === 'raw' ? 'article' : 'custom',
            $p['stage'], $p['section_id'] ? (int) $p['section_id'] : null, 1000, "{$p['path']}/$clean");
        if (!$created) throw new AppError('A folder with that name already exists');
        Activity::log(null, (int) $p['edition_id'], $userId, 'folder_created', "{$p['name']}/$clean");
        return $folder;
    }

    private static function baseOf(string $n): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(pathinfo($n, PATHINFO_FILENAME))));
    }

    /**
     * Turns the files gathered in a Raw folder into articles.
     *  - an article folder (Raw/Section/<name>/) becomes ONE article with all its files
     *  - in a section folder each document becomes an article; photos go to the only document, or to
     *    the document whose name they start with ("Kilimanjaro 2.jpg" -> "Kilimanjaro.docx")
     *  - photos with no matching document wait until attached (or photosAsArticle is set)
     */
    public static function process(int $folderId, ?int $userId, bool $photosAsArticle = false): array
    {
        $f = self::get($folderId);
        if ($f['stage'] !== 'raw') throw new AppError('Only Raw folders are processed');
        @set_time_limit(600);
        $out = ['created' => [], 'waiting' => 0, 'notes' => []];
        $merge = function (array $r) use (&$out) {
            array_push($out['created'], ...$r['created']);
            $out['waiting'] += $r['waiting'];
            array_push($out['notes'], ...$r['notes']);
        };
        foreach (Db::q("SELECT id FROM folders WHERE parent_id = ? AND kind = 'article'", [$f['id']]) as $k) $merge(self::process((int) $k['id'], $userId, true));
        if ($f['kind'] === 'stage') foreach (Db::q("SELECT id FROM folders WHERE parent_id = ? AND kind = 'section'", [$f['id']]) as $s) $merge(self::process((int) $s['id'], $userId, $photosAsArticle));

        $rows = Db::q('SELECT * FROM files WHERE folder_id = ? AND article_id IS NULL ORDER BY original_name', [$f['id']]);
        if (!$rows) return $out;
        $docs = array_values(array_filter($rows, fn ($r) => !Intake::isWebImage($r['original_name'], $r['mime'])));
        $imgs = array_values(array_filter($rows, fn ($r) => Intake::isWebImage($r['original_name'], $r['mime'])));
        $make = function (?string $title, array $stored) use ($f, $userId, &$out) {
            $r = Intake::createArticle(['editionId' => (int) $f['edition_id'], 'sectionId' => $f['section_id'], 'title' => $title, 'storedFiles' => $stored,
                'folderId' => $f['kind'] === 'article' ? (int) $f['id'] : null, 'userId' => $userId]);
            $a = Db::one('SELECT id, title FROM articles WHERE id = ?', [$r['articleId']]);
            $out['created'][] = ['articleId' => (int) $a['id'], 'title' => $a['title'], 'folder' => $f['name']];
            array_push($out['notes'], ...$r['notes']);
        };

        if ($f['kind'] === 'article') { $make($docs ? null : $f['name'], [...$docs, ...$imgs]); return $out; }
        if (count($docs) === 1) { $make(null, [...$docs, ...$imgs]); return $out; }
        if (!$docs) {
            if ($photosAsArticle) $make("{$f['name']} - photos", $imgs);
            else {
                $out['waiting'] += count($imgs);
                $out['notes'][] = "{$f['name']}: " . count($imgs) . ' photo(s) have no document. Attach them to an article, or process again with "photos as one article".';
            }
            return $out;
        }
        $claimed = [];
        foreach ($docs as $d) {
            $b = self::baseOf($d['original_name']);
            $mine = array_values(array_filter($imgs, fn ($i) => !isset($claimed[$i['id']]) && $b !== '' && str_starts_with(self::baseOf($i['original_name']), $b)));
            foreach ($mine as $i) $claimed[$i['id']] = true;
            $make(null, [$d, ...$mine]);
        }
        $left = count(array_filter($imgs, fn ($i) => !isset($claimed[$i['id']])));
        if ($left) {
            $out['waiting'] += $left;
            $out['notes'][] = "{$f['name']}: $left photo(s) could not be matched to a document. Attach each one to its article, or next time put an article's files in its own article folder.";
        }
        return $out;
    }

    public static function processEdition(int $editionId, ?int $userId): array
    {
        $raws = Db::q("SELECT id FROM folders WHERE edition_id = ? AND parent_id IS NULL AND stage = 'raw'", [$editionId]);
        if (!$raws) throw new AppError('This issue has no Raw folder yet. Ask an administrator to create the folders.');
        $out = ['created' => [], 'waiting' => 0, 'notes' => []];
        foreach ($raws as $r) {
            $x = self::process((int) $r['id'], $userId);
            array_push($out['created'], ...$x['created']);
            $out['waiting'] += $x['waiting'];
            array_push($out['notes'], ...$x['notes']);
        }
        return $out;
    }

    /** Links a waiting photo/document to an existing article (appended to its current text). */
    public static function attachFile(int $fileId, int $articleId, ?int $userId): array
    {
        $file = Db::one('SELECT * FROM files WHERE id = ?', [$fileId]);
        if (!$file || $file['article_id']) throw new AppError('That file is already part of an article');
        $a = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$a || (int) $a['edition_id'] !== (int) $file['edition_id']) throw new AppError('Article not found in this issue');
        if ($a['status'] === 'reviewing') throw new AppError('Wait until the AI review has finished');
        $img = Intake::isWebImage($file['original_name'], $file['mime']);
        Db::exec('UPDATE files SET article_id = ?, kind = ?, sort_order = 200 WHERE id = ?', [$a['id'], $img ? 'image' : 'attachment', $file['id']]);
        if ($img) {
            $col = ($a['status'] === 'raw' || !$a['edited_html']) ? 'raw_html' : 'edited_html';
            Db::exec("UPDATE articles SET $col = CONCAT(COALESCE($col, ''), ?), featured_file_id = COALESCE(featured_file_id, ?) WHERE id = ?",
                ["\n<p>" . Html::imgTag($file['id']) . '</p>', $file['id'], $a['id']]);
        }
        Activity::log((int) $a['id'], (int) $a['edition_id'], $userId, 'file_attached', $file['original_name']);
        return ['ok' => true];
    }

    // ---------------- Article export (stage copies and ZIP downloads) ----------------
    private static function articleDocument(array $a, array $files): array
    {
        $nameFor = [];
        foreach ($files as $f) $nameFor[(int) $f['id']] = $f['id'] . '-' . Storage::fsName($f['original_name'], 'file');
        $body = in_array($a['status'], ['raw', 'reviewing'], true) ? (string) $a['raw_html'] : (string) ($a['edited_html'] ?: $a['raw_html']);
        $body = preg_replace_callback('/src="\/files\/(\d+)"/', fn ($m) => isset($nameFor[(int) $m[1]]) ? 'src="files/' . Html::e($nameFor[(int) $m[1]]) . '"' : $m[0], $body);
        $doc = '<!doctype html><html><head><meta charset="utf-8"><title>' . Html::e($a['title']) . '</title>'
            . '<style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.6}img{max-width:100%;height:auto}</style></head><body>'
            . '<h1>' . Html::e($a['title']) . '</h1>' . ($a['subtitle'] ? '<p><em>' . Html::e($a['subtitle']) . '</em></p>' : '')
            . ($a['author_name'] ? '<p>By ' . Html::e($a['author_name']) . '</p>' : '') . "\n$body</body></html>";
        return [$doc, $nameFor];
    }

    private static function articleDirName(array $a): string
    {
        return Storage::fsName("{$a['title']} [{$a['id']}]");
    }

    private static function stageFolderFor(array $a): ?array
    {
        $stage = null;
        foreach (self::STATUSES as $k => $st) if (in_array($a['status'], $st, true)) $stage = $k;
        $top = Db::one('SELECT * FROM folders WHERE edition_id = ? AND parent_id IS NULL AND stage = ? ORDER BY sort LIMIT 1', [$a['edition_id'], $stage]);
        if (!$top || !$a['section_id']) return $top;
        return Db::one("SELECT * FROM folders WHERE parent_id = ? AND kind = 'section' AND section_id = ?", [$top['id'], $a['section_id']]) ?: $top;
    }

    /** Writes a copy of the article (text + every photo + original documents) into its stage folder. */
    public static function snapshot(int $articleId): ?string
    {
        $a = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$a || in_array($a['status'], ['raw', 'reviewing'], true)) return null;
        $folder = self::stageFolderFor($a);
        if (!$folder) return null;
        $files = self::articleFiles($articleId);
        [$doc, $nameFor] = self::articleDocument($a, $files);
        $rel = $folder['path'] . '/' . self::articleDirName($a);
        $dir = Storage::absDir($rel);
        if (!is_dir("$dir/files")) mkdir("$dir/files", 0775, true);
        file_put_contents("$dir/article.html", $doc);
        foreach ($files as $f) {
            $target = "$dir/files/" . $nameFor[(int) $f['id']];
            if (file_exists($target)) continue;
            $src = Storage::filePath($f['stored_name']);
            if (!is_file($src)) continue;
            if (!@link($src, $target)) @copy($src, $target);
        }
        return $rel;
    }

    public static function afterStageChange(int $articleId): void
    {
        try {
            self::snapshot($articleId);
        } catch (Throwable $e) {
            error_log("[folders] stage copy failed for article $articleId: " . $e->getMessage());
        }
    }

    /** Builds a ZIP of a folder (its waiting files, its articles and all subfolders) in a temp file. */
    public static function zip(int $folderId): array
    {
        $root = self::get($folderId);
        Storage::ensureDirs();
        $tmp = Storage::root() . '/tmp/zip-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== true) throw new AppError('Could not create the ZIP file');
        $walk = function (array $f, string $prefix) use (&$walk, $zip) {
            $zip->addEmptyDir(rtrim($prefix, '/'));
            foreach (Db::q('SELECT * FROM files WHERE folder_id = ? AND article_id IS NULL', [$f['id']]) as $w) {
                $p = Storage::filePath($w['stored_name']);
                if (is_file($p)) $zip->addFile($p, $prefix . Storage::fsName($w['original_name'], "file-{$w['id']}"));
            }
            if ($where = self::articleWhere($f)) {
                foreach (Db::q("SELECT a.* FROM articles a WHERE a.edition_id = ? AND {$where[0]}", [(int) $f['edition_id'], ...$where[1]]) as $a) {
                    $files = self::articleFiles((int) $a['id']);
                    [$doc, $nameFor] = self::articleDocument($a, $files);
                    $dir = $prefix . self::articleDirName($a) . '/';
                    $zip->addFromString($dir . 'article.html', $doc);
                    foreach ($files as $x) {
                        $p = Storage::filePath($x['stored_name']);
                        if (is_file($p)) $zip->addFile($p, $dir . 'files/' . $nameFor[(int) $x['id']]);
                    }
                }
            }
            foreach (Db::q('SELECT * FROM folders WHERE parent_id = ? ORDER BY sort, name', [$f['id']]) as $k) $walk($k, $prefix . $k['name'] . '/');
        };
        $walk($root, $root['name'] . '/');
        $zip->close();
        return ['path' => $tmp, 'name' => $root['name'] . '.zip'];
    }

    public static function removeWaiting(int $fileId, ?int $userId): void
    {
        $file = Db::one('SELECT * FROM files WHERE id = ?', [$fileId]);
        if (!$file || $file['article_id'] || !$file['folder_id']) throw new AppError('Only files that are still waiting can be removed from a folder');
        // Hidden from the folder list only; the file itself stays on the server.
        Db::exec('UPDATE files SET folder_id = NULL WHERE id = ?', [$file['id']]);
        Activity::log(null, (int) $file['edition_id'], $userId, 'file_removed_from_folder', $file['original_name'] . ' (kept on the server)');
    }
}
