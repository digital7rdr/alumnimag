<?php
declare(strict_types=1);

// JSON API used by the dashboard (public/app.js). Same endpoints as the earlier Node version.
final class Api
{
    private static function uid(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    private static function requireAdmin(): void
    {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') throw new AppError('Only an administrator can do this', 403);
    }

    public static function handle(string $method, string $path): void
    {
        $b = Http::body();

        // ---------- Auth (no login needed) ----------
        if ($method === 'POST' && $path === '/login') {
            $u = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [strtolower(trim((string) ($b['email'] ?? '')))]);
            if (!$u || !password_verify((string) ($b['password'] ?? ''), $u['password_hash'])) {
                usleep(400000);
                Http::json(['error' => 'Wrong email or password'], 401);
                return;
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
            Http::json(['user' => $_SESSION['user']]);
            return;
        }
        if ($method === 'POST' && $path === '/logout') {
            $_SESSION = [];
            session_destroy();
            Http::json(['ok' => true]);
            return;
        }
        if ($method === 'GET' && $path === '/me') {
            Http::json(['user' => $_SESSION['user'] ?? null]);
            return;
        }
        if (empty($_SESSION['user'])) throw new AppError('Please sign in', 401);
        // Re-check the account on every request so disabled users are signed out at once.
        $live = Db::one('SELECT id, name, email, role, active FROM users WHERE id = ?', [self::uid()]);
        if (!$live || !$live['active']) { $_SESSION = []; throw new AppError('Please sign in', 401); }
        $_SESSION['user'] = ['id' => (int) $live['id'], 'name' => $live['name'], 'email' => $live['email'], 'role' => $live['role']];
        session_write_close(); // long requests (AI, uploads) must not block other tabs

        foreach (self::routes() as [$m, $re, $fn, $admin]) {
            if ($m !== $method || !preg_match("#^$re$#", $path, $args)) continue;
            if ($admin) self::requireAdmin();
            array_shift($args);
            $fn($b, ...$args);
            return;
        }
        throw new AppError('Not found', 404);
    }

    private static function routes(): array
    {
        return [
            // ---------- Meta ----------
            ['GET', '/sections', fn () => Http::json(Db::q('SELECT * FROM sections ORDER BY num')), false],
            ['GET', '/stats', function () {
                $by = [];
                foreach (Db::q('SELECT status, COUNT(*) n FROM articles GROUP BY status') as $r) $by[$r['status']] = (int) $r['n'];
                Http::json([
                    'byStage' => (object) $by,
                    'editions' => (int) Db::val('SELECT COUNT(*) FROM editions'),
                    'pendingReviews' => (int) Db::val("SELECT COUNT(*) FROM reviewers WHERE status = 'sent'"),
                    'overdue' => (int) Db::val("SELECT COUNT(*) FROM reviewers WHERE status = 'sent' AND due_at < NOW()"),
                    'recent' => Db::q('SELECT ac.*, a.title, u.name AS user_name FROM activity ac LEFT JOIN articles a ON a.id = ac.article_id LEFT JOIN users u ON u.id = ac.user_id ORDER BY ac.id DESC LIMIT 12'),
                ]);
            }, false],

            // ---------- Issues (editions) ----------
            ['GET', '/editions', fn () => Http::json(Db::q("SELECT e.*,
                (SELECT COUNT(*) FROM articles a WHERE a.edition_id = e.id) AS article_count,
                (SELECT COUNT(*) FROM articles a WHERE a.edition_id = e.id AND a.status = 'published') AS published_count,
                (SELECT COUNT(*) FROM files f WHERE f.edition_id = e.id AND f.folder_id IS NOT NULL AND f.article_id IS NULL) AS waiting_count,
                (SELECT COUNT(*) FROM folders d WHERE d.edition_id = e.id) AS folder_count
                FROM editions e ORDER BY e.year DESC, e.id DESC")), false],
            ['POST', '/editions', function ($b) {
                $year = (int) ($b['year'] ?? 0);
                $name = trim((string) ($b['name'] ?? ''));
                if ($year < 2000 || $year > 2100 || $name === '') throw new AppError('Year and issue name are required');
                if (Db::one('SELECT id FROM editions WHERE year = ? AND name = ?', [$year, $name])) throw new AppError("\"$name\" already exists in $year");
                $id = Db::exec('INSERT INTO editions (year, name, submission_token) VALUES (?, ?, ?)', [$year, mb_substr($name, 0, 150), bin2hex(random_bytes(12))]);
                Activity::log(null, $id, self::uid(), 'edition_created', "$year / $name");
                $gen = (($b['generate'] ?? true) === false) ? null : Folders::generate($id, self::uid());
                Http::json(['id' => $id, 'folders' => $gen]);
            }, true],
            ['GET', '/editions/(\d+)', function ($b, $id) {
                $e = Db::one('SELECT * FROM editions WHERE id = ?', [$id]);
                if (!$e) throw new AppError('Issue not found', 404);
                $e['articles'] = Db::q("SELECT a.id, a.title, a.author_name, a.status, a.source, a.section_id, a.featured_file_id, a.ai_error, a.ai_queued,
                    a.wp_post_id, a.updated_at, s.num AS section_num, s.name AS section_name,
                    (SELECT COUNT(*) FROM files f WHERE f.article_id = a.id AND f.kind = 'image') AS image_count,
                    (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id) AS reviewer_count,
                    (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'approved') AS approved_count,
                    (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'changes') AS changes_count,
                    (SELECT MIN(r.due_at) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'sent') AS next_due
                    FROM articles a LEFT JOIN sections s ON s.id = a.section_id WHERE a.edition_id = ? ORDER BY s.num, a.id", [$e['id']]);
                $e['submitUrl'] = Config::baseUrl() . '/submit/' . $e['submission_token'];
                $e['waiting_count'] = (int) Db::val('SELECT COUNT(*) FROM files WHERE edition_id = ? AND folder_id IS NOT NULL AND article_id IS NULL', [$e['id']]);
                $e['folder_count'] = (int) Db::val('SELECT COUNT(*) FROM folders WHERE edition_id = ?', [$e['id']]);
                Http::json($e);
            }, false],
            ['PATCH', '/editions/(\d+)', function ($b, $id) {
                $f = [];
                if (array_key_exists('name', $b)) { self::requireAdmin(); $f['name'] = mb_substr(trim((string) $b['name']), 0, 150); }
                if (array_key_exists('year', $b)) { self::requireAdmin(); $f['year'] = (int) $b['year']; }
                if (array_key_exists('submissions_open', $b)) $f['submissions_open'] = $b['submissions_open'] ? 1 : 0;
                Db::update('editions', (int) $id, $f);
                Http::json(['ok' => true]);
            }, false],

            // ---------- Folders ----------
            ['POST', '/editions/(\d+)/folders/generate', fn ($b, $id) => Http::json(Folders::generate((int) $id, self::uid())), true],
            ['GET', '/editions/(\d+)/folders', fn ($b, $id) => Http::json(Folders::tree((int) $id)), false],
            ['POST', '/editions/(\d+)/process', fn ($b, $id) => Http::json(Folders::processEdition((int) $id, self::uid())), false],
            ['GET', '/folders/(\d+)', fn ($b, $id) => Http::json(Folders::contents((int) $id)), false],
            ['POST', '/folders/(\d+)/files', function ($b, $id) {
                $up = Intake::uploads();
                if (!$up) throw new AppError('Choose at least one file');
                Http::json(['saved' => Folders::uploadInto((int) $id, $up, self::uid())]);
            }, false],
            ['POST', '/folders/(\d+)/subfolders', fn ($b, $id) => Http::json(Folders::createSubfolder((int) $id, (string) ($b['name'] ?? ''), self::uid())), false],
            ['POST', '/folders/(\d+)/process', fn ($b, $id) => Http::json(Folders::process((int) $id, self::uid(), !empty($b['photosAsArticle']))), false],
            ['GET', '/folders/(\d+)/zip', function ($b, $id) {
                @set_time_limit(600);
                $z = Folders::zip((int) $id);
                header('Content-Type: application/zip');
                header('Content-Length: ' . filesize($z['path']));
                header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($z['name']));
                readfile($z['path']);
                @unlink($z['path']);
            }, false],
            ['POST', '/files/(\d+)/attach', fn ($b, $id) => Http::json(Folders::attachFile((int) $id, (int) ($b['article_id'] ?? 0), self::uid())), false],
            ['DELETE', '/files/(\d+)/waiting', function ($b, $id) { Folders::removeWaiting((int) $id, self::uid()); Http::json(['ok' => true]); }, true],

            // ---------- Intake ----------
            ['POST', '/editions/(\d+)/upload', function ($b, $id) {
                if (!Db::one('SELECT id FROM editions WHERE id = ?', [$id])) throw new AppError('Issue not found', 404);
                $files = Intake::uploads();
                if (!$files) throw new AppError('Choose at least one file');
                $section = ($b['section_id'] ?? '') ?: null;
                $common = ['editionId' => (int) $id, 'sectionId' => $section, 'authorName' => $b['author_name'] ?? null, 'userId' => self::uid()];
                $created = [];
                if (($b['mode'] ?? '') === 'each') {
                    $docs = array_values(array_filter($files, fn ($f) => !Intake::isWebImage($f['name'], $f['type'])));
                    $imgs = array_values(array_filter($files, fn ($f) => Intake::isWebImage($f['name'], $f['type'])));
                    foreach ($docs as $i => $d) $created[] = Intake::createArticle($common + ['uploads' => $i === 0 ? [$d, ...$imgs] : [$d]]);
                    if (!$docs) $created[] = Intake::createArticle($common + ['title' => $b['title'] ?? '', 'uploads' => $imgs]);
                } else {
                    $created[] = Intake::createArticle($common + ['title' => $b['title'] ?? '', 'uploads' => $files]);
                }
                Http::json(['created' => $created]);
            }, false],
            ['POST', '/editions/(\d+)/articles', function ($b, $id) {
                if (trim((string) ($b['title'] ?? '')) === '') throw new AppError('Title is required');
                Http::json(Intake::createArticle(['editionId' => (int) $id, 'sectionId' => ($b['section_id'] ?? '') ?: null, 'title' => $b['title'],
                    'authorName' => $b['author_name'] ?? null, 'authorEmail' => $b['author_email'] ?? null, 'source' => 'editor', 'bodyHtml' => (string) ($b['html'] ?? ''), 'userId' => self::uid()]));
            }, false],

            // ---------- Articles ----------
            ['GET', '/articles/(\d+)', function ($b, $id) {
                $a = Db::one('SELECT a.*, s.name AS section_name, s.num AS section_num, e.name AS edition_name, e.year AS edition_year
                    FROM articles a LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                $a['files'] = Db::q('SELECT id, kind, original_name, mime, size, origin, sort_order, wp_media_id, created_at FROM files WHERE article_id = ? ORDER BY kind, sort_order, id', [$a['id']]);
                $a['versions'] = Db::q('SELECT v.id, v.label, v.title, v.created_at, u.name AS user_name FROM article_versions v LEFT JOIN users u ON u.id = v.user_id WHERE v.article_id = ? ORDER BY v.id DESC', [$a['id']]);
                $a['reviewers'] = Db::q('SELECT id, person_id, round, name, email, status, sent_at, due_at, reminded_at, responded_at, comments FROM reviewers WHERE article_id = ? ORDER BY round, id', [$a['id']]);
                $a['activity'] = Db::q('SELECT ac.*, u.name AS user_name FROM activity ac LEFT JOIN users u ON u.id = ac.user_id WHERE ac.article_id = ? ORDER BY ac.id DESC LIMIT 50', [$a['id']]);
                Http::json($a);
            }, false],
            ['PUT', '/articles/(\d+)', function ($b, $id) {
                $a = Db::one('SELECT * FROM articles WHERE id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                if ($a['status'] === 'reviewing') throw new AppError('The AI is editing this article right now - try again in a moment');
                $f = [];
                foreach (['title', 'subtitle', 'author_name', 'author_email', 'summary'] as $k) {
                    if (array_key_exists($k, $b)) $f[$k] = mb_substr(trim((string) ($b[$k] ?? '')), 0, $k === 'summary' ? 2000 : 290) ?: null;
                }
                if (array_key_exists('title', $f) && $f['title'] === null) unset($f['title']);
                if (array_key_exists('section_id', $b)) $f['section_id'] = $b['section_id'] ? (int) $b['section_id'] : null;
                if (array_key_exists('featured_file_id', $b)) $f['featured_file_id'] = $b['featured_file_id'] ? (int) $b['featured_file_id'] : null;
                if (array_key_exists('html', $b)) {
                    $clean = Html::clean(Html::normalizeImgs(Html::fixMarkdown((string) $b['html'])));
                    if ($a['status'] === 'raw') $f['raw_html'] = $clean; else $f['edited_html'] = $clean;
                    Db::exec("INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, 'Manual edit', ?, ?, ?)", [$a['id'], $f['title'] ?? $a['title'], $clean, self::uid()]);
                }
                Db::update('articles', (int) $a['id'], $f);
                Activity::log((int) $a['id'], (int) $a['edition_id'], self::uid(), 'edited', implode(', ', array_keys($f)));
                Http::json(['ok' => true]);
            }, false],
            ['GET', '/articles/(\d+)/versions/(\d+)', function ($b, $id, $vid) {
                $v = Db::one('SELECT * FROM article_versions WHERE id = ? AND article_id = ?', [$vid, $id]);
                if (!$v) throw new AppError('Version not found', 404);
                Http::json($v);
            }, false],
            ['POST', '/articles/(\d+)/files', function ($b, $id) {
                $a = Db::one('SELECT * FROM articles WHERE id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                $saved = [];
                foreach (Intake::uploads() as $u) {
                    $mime = Intake::mimeFor($u['name'], $u['type']);
                    $kind = preg_match(Intake::WEB_IMAGE, $mime) ? 'image' : 'attachment';
                    $s = Storage::save($u['data'], ['articleId' => (int) $a['id'], 'editionId' => (int) $a['edition_id'], 'kind' => $kind, 'mime' => $mime, 'originalName' => $u['name'], 'origin' => 'upload', 'sortOrder' => 200]);
                    $saved[] = ['id' => $s['id'], 'kind' => $kind, 'name' => $u['name']];
                }
                if (!$a['featured_file_id']) {
                    foreach ($saved as $s) if ($s['kind'] === 'image') { Db::exec('UPDATE articles SET featured_file_id = ? WHERE id = ?', [$s['id'], $a['id']]); break; }
                }
                Activity::log((int) $a['id'], (int) $a['edition_id'], self::uid(), 'files_added', implode(', ', array_column($saved, 'name')));
                Http::json(['saved' => $saved]);
            }, false],
            ['POST', '/articles/(\d+)/ai-image/prompt', fn ($b, $id) => Http::json(['prompt' => AiImage::suggestPrompt((int) $id)]), false],
            ['POST', '/articles/(\d+)/ai-image', fn ($b, $id) => Http::json(AiImage::generate((int) $id, (string) ($b['prompt'] ?? ''), self::uid())), false],
            ['POST', '/articles/(\d+)/ai-review', fn ($b, $id) => Http::json(Ai::review((int) $id, self::uid())), false],
            ['POST', '/editions/(\d+)/ai-review-all', function ($b, $id) {
                $n = Ai::queueEdition((int) $id);
                Http::json(['queued' => $n]);
                if ($n) {
                    // Answer the browser now, then keep working; the cron job finishes anything left.
                    Http::finishRequest();
                    @set_time_limit(900);
                    Ai::processQueue(840);
                }
            }, false],
            ['POST', '/articles/(\d+)/stage', function ($b, $id) {
                $moves = ['compiled' => ['edited', 'in_review'], 'edited' => ['in_review', 'compiled'], 'raw' => ['edited']];
                $to = (string) ($b['to'] ?? '');
                $a = Db::one('SELECT * FROM articles WHERE id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                if (!isset($moves[$to]) || !in_array($a['status'], $moves[$to], true)) throw new AppError("Cannot move from {$a['status']} to $to");
                if ($to === 'raw' && !$a['raw_html']) throw new AppError('No original text to go back to');
                Db::exec('UPDATE articles SET status = ? WHERE id = ?', [$to, $a['id']]);
                Folders::afterStageChange((int) $a['id']);
                Activity::log((int) $a['id'], (int) $a['edition_id'], self::uid(), 'stage', "{$a['status']} -> $to");
                Http::json(['ok' => true]);
            }, false],
            ['POST', '/editions/(\d+)/compile', function ($b, $id) {
                $only = ($b['onlyApproved'] ?? true) !== false;
                $rows = Db::q("SELECT a.id FROM articles a WHERE a.edition_id = ? AND a.status = 'in_review'"
                    . ($only ? " AND NOT EXISTS (SELECT 1 FROM reviewers r WHERE r.article_id = a.id AND r.status IN ('sent','changes','pending'))" : ''), [$id]);
                foreach ($rows as $r) {
                    Db::exec("UPDATE articles SET status = 'compiled' WHERE id = ?", [$r['id']]);
                    Folders::afterStageChange((int) $r['id']);
                    Activity::log((int) $r['id'], (int) $id, self::uid(), 'stage', 'in_review -> compiled');
                }
                Http::json(['moved' => count($rows)]);
            }, false],
            ['POST', '/articles/(\d+)/publish', fn ($b, $id) => Http::json(Wp::publishDraft((int) $id, self::uid())), true],
            ['DELETE', '/articles/(\d+)', function ($b, $id) {
                $a = Db::one('SELECT * FROM articles WHERE id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                // The article record goes; uploaded files stay on the server.
                Db::exec('DELETE FROM reviewers WHERE article_id = ?', [$a['id']]);
                Db::exec('UPDATE files SET article_id = NULL WHERE article_id = ?', [$a['id']]);
                Db::exec('DELETE FROM articles WHERE id = ?', [$a['id']]);
                Activity::log(null, (int) $a['edition_id'], self::uid(), 'article_deleted', $a['title']);
                Http::json(['ok' => true]);
            }, true],

            // ---------- Reviewers ----------
            ['GET', '/editions/(\d+)/reviewers', fn ($b, $id) => Http::json(Db::q('SELECT r.id, r.round, r.name, r.email, r.status, r.sent_at, r.due_at, r.reminded_at, r.responded_at, r.comments, r.article_id, a.title AS article_title
                FROM reviewers r LEFT JOIN articles a ON a.id = r.article_id WHERE r.edition_id = ? ORDER BY a.title, r.round, r.name', [$id])), false],
            ['POST', '/articles/(\d+)/reviewers', function ($b, $id) {
                $a = Db::one('SELECT * FROM articles WHERE id = ?', [$id]);
                if (!$a) throw new AppError('Article not found', 404);
                $pid = (int) ($b['person_id'] ?? 0) ?: null;
                Http::json(['id' => Reviews::add((int) $a['edition_id'], (int) $a['id'], (string) ($b['name'] ?? ''), (string) ($b['email'] ?? ''), $pid, (int) ($b['round'] ?? 1))]);
            }, false],
            ['POST', '/articles/(\d+)/reviewers/from-section', fn ($b, $id) => Http::json(Reviews::assignFromSection((int) $id, self::uid())), false],
            ['POST', '/reviewers/(\d+)/resend', function ($b, $id) {
                $r = Db::one('SELECT * FROM reviewers WHERE id = ?', [$id]);
                if (!$r || !$r['article_id']) throw new AppError('Reviewer not found', 404);
                Db::exec("UPDATE reviewers SET status = 'pending' WHERE id = ?", [$id]);
                Http::json(Reviews::sendForArticle((int) $r['article_id'], self::uid(), [(int) $id]));
            }, false],
            // Bulk add: one line per reviewer "Name, email, Article title[, round]" (email may be left out for people already in People)
            ['POST', '/editions/(\d+)/reviewers/import', function ($b, $id) {
                $arts = Db::q('SELECT id, title FROM articles WHERE edition_id = ?', [$id]);
                $out = ['added' => 0, 'skipped' => []];
                foreach (preg_split('/\r\n|\r|\n/', (string) ($b['text'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $parts = array_map('trim', preg_split('/\t|,/', $line));
                    $name = $parts[0] ?? '';
                    $rest = array_slice($parts, 1);
                    $email = '';
                    if ($rest && filter_var($rest[0], FILTER_VALIDATE_EMAIL)) $email = array_shift($rest);
                    $round = 1;
                    if ($rest && preg_match('/^(?:round\s*)?([12])$/i', (string) end($rest), $m)) { $round = (int) $m[1]; array_pop($rest); }
                    $title = mb_strtolower(trim(implode(',', $rest)));
                    $art = null;
                    foreach ($arts as $x) if (mb_strtolower($x['title']) === $title) { $art = $x; break; }
                    if (!$art && $title !== '') foreach ($arts as $x) if (str_contains(mb_strtolower($x['title']), $title)) { $art = $x; break; }
                    $pid = null;
                    if ($email === '' && $name !== '') { $p = People::matchName($name); if ($p && $p['email']) { $pid = (int) $p['id']; } }
                    try {
                        if (!$art) throw new AppError('article not found');
                        Reviews::add((int) $id, (int) $art['id'], $name, $email, $pid, $round);
                        $out['added']++;
                    } catch (Throwable $e) {
                        $out['skipped'][] = "$line - " . $e->getMessage();
                    }
                }
                Http::json($out);
            }, false],
            ['DELETE', '/reviewers/(\d+)', function ($b, $id) { Db::exec('DELETE FROM reviewers WHERE id = ?', [$id]); Http::json(['ok' => true]); }, false],
            ['POST', '/articles/(\d+)/send-review', function ($b, $id) { @set_time_limit(300); Http::json(Reviews::sendForArticle((int) $id, self::uid())); }, false],
            ['POST', '/editions/(\d+)/send-reviews', function ($b, $id) {
                @set_time_limit(600);
                $arts = Db::q("SELECT a.id, a.title FROM articles a WHERE a.edition_id = ? AND a.status = 'edited'
                    AND EXISTS (SELECT 1 FROM reviewers r WHERE r.article_id = a.id AND r.status = 'pending')", [$id]);
                $out = ['articles' => 0, 'sent' => 0, 'failed' => []];
                foreach ($arts as $a) {
                    try {
                        $r = Reviews::sendForArticle((int) $a['id'], self::uid());
                        $out['articles']++;
                        $out['sent'] += count($r['sent']);
                        foreach ($r['failed'] as $f) $out['failed'][] = $f + ['article' => $a['title']];
                    } catch (Throwable $e) {
                        $out['failed'][] = ['article' => $a['title'], 'error' => $e->getMessage()];
                    }
                }
                Http::json($out);
            }, false],

            // ---------- People and section reviewers ----------
            ['GET', '/people', fn () => Http::json(People::list(trim((string) ($_GET['q'] ?? '')), (string) ($_GET['role'] ?? ''))), false],
            ['POST', '/people', fn ($b) => Http::json(People::save(null, $b)), true],
            ['PUT', '/people/(\d+)', fn ($b, $id) => Http::json(People::save((int) $id, $b)), true],
            ['DELETE', '/people/(\d+)', function ($b, $id) { People::delete((int) $id); Http::json(['ok' => true]); }, true],
            ['POST', '/people/import', function () {
                $f = $_FILES['file'] ?? null;
                if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK) throw new AppError('Choose an Excel (.xlsx) or CSV file');
                @set_time_limit(300);
                Http::json(People::import($f['tmp_name'], (string) $f['name'], self::uid()));
            }, true],
            ['GET', '/section-people', fn () => Http::json(People::sectionConfig()), false],
            ['PUT', '/sections/(\d+)/people', function ($b, $id) { People::setSection((int) $id, $b); Http::json(['ok' => true]); }, true],

            // ---------- Settings / users (admin) ----------
            ['GET', '/settings', fn () => Http::json(Settings::publicView() + ['default_prompt' => Ai::DEFAULT_PROMPT, 'env' => [
                'gemini' => (bool) Config::get('GEMINI_API_KEY'),
                'groq' => (bool) Config::get('GROQ_API_KEY'),
                'image' => AiImage::configured(),
                'smtp' => Config::get('SMTP_USER') && Config::get('SMTP_PASS'),
                'storageDir' => Storage::root(),
                'uploadLimit' => ini_get('upload_max_filesize') . ' per file, ' . ini_get('post_max_size') . ' per upload',
                'cron' => self::cronStatus(),
            ]]), true],
            ['PUT', '/settings', function ($b) {
                if (($b['wp_app_password'] ?? null) === Settings::MASK) unset($b['wp_app_password']);
                if (array_key_exists('folder_template', $b)) Folders::parseTemplate((string) $b['folder_template']);
                if (array_key_exists('ai_provider', $b) && !isset(Ai::PROVIDERS[$b['ai_provider']])) throw new AppError('Unknown AI provider');
                if (array_key_exists('review_days', $b)) {
                    $d = (int) $b['review_days'];
                    if ($d < 1 || $d > 30) throw new AppError('Review days must be between 1 and 30');
                    $b['review_days'] = $d;
                }
                Settings::set($b);
                Http::json(['ok' => true]);
            }, true],
            ['POST', '/settings/test-wp', fn () => Http::json(Wp::test()), true],
            ['POST', '/settings/test-ai', fn ($b) => Http::json(['ok' => Ai::test((string) ($b['provider'] ?? 'gemini'))]), true],
            ['POST', '/settings/test-email', function ($b) {
                $to = trim((string) ($b['to'] ?? '')) ?: $_SESSION['user']['email'];
                $info = Mailer::sendReviewMail($to, 'Test from The Missing Link dashboard', '<p>Email is working. The review CC address is included on this message.</p>', 'Email is working.');
                Http::json(['ok' => true, 'cc' => $info['cc']]);
            }, true],
            ['GET', '/users', fn () => Http::json(Db::q('SELECT id, name, email, role, active, created_at FROM users ORDER BY name')), true],
            ['POST', '/users', function ($b) {
                $name = trim((string) ($b['name'] ?? ''));
                $email = strtolower(trim((string) ($b['email'] ?? '')));
                $pw = (string) ($b['password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) throw new AppError('Name, email and a password of at least 8 characters are required');
                if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) throw new AppError('A user with that email already exists');
                Http::json(['id' => Db::exec('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash($pw, PASSWORD_BCRYPT), ($b['role'] ?? '') === 'admin' ? 'admin' : 'editor'])]);
            }, true],
            ['PUT', '/users/(\d+)', function ($b, $id) {
                $f = [];
                if (!empty($b['name'])) $f['name'] = mb_substr((string) $b['name'], 0, 120);
                if (!empty($b['role'])) $f['role'] = $b['role'] === 'admin' ? 'admin' : 'editor';
                if (array_key_exists('active', $b)) $f['active'] = $b['active'] ? 1 : 0;
                if (!empty($b['password'])) {
                    if (strlen((string) $b['password']) < 8) throw new AppError('Password must be at least 8 characters');
                    $f['password_hash'] = password_hash((string) $b['password'], PASSWORD_BCRYPT);
                }
                if ((int) $id === self::uid() && (($f['role'] ?? '') === 'editor' || ($f['active'] ?? 1) === 0)) throw new AppError('You cannot demote or disable yourself');
                Db::update('users', (int) $id, $f);
                Http::json(['ok' => true]);
            }, true],
        ];
    }

    private static function cronStatus(): string
    {
        $f = Storage::root() . '/tmp/cron-last-run';
        if (!is_file($f)) return 'never';
        return date('j M Y H:i', (int) filemtime($f));
    }
}
