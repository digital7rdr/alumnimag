<?php
declare(strict_types=1);

// Pages outside the dashboard: contributor upload link, reviewer page, and protected file downloads.
final class PublicPages
{
    private static function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . Html::e($title) . ' · The Missing Link</title><link rel="stylesheet" href="/public.css"></head><body><main class="pub">'
            . '<header class="pub-head"><div class="pub-brand">The Missing Link</div><div class="pub-sub">Alumni magazine</div></header>'
            . $body . '</main></body></html>';
    }

    /** Returns true when the path was handled here. */
    public static function handle(string $method, string $path): bool
    {
        if ($path === '/health') {
            Http::json(['status' => 'ok', 'time' => date('c'), 'php' => PHP_VERSION]);
            return true;
        }
        if ($method === 'GET' && preg_match('#^/files/(\d+)$#', $path, $m)) {
            if (empty($_SESSION['user'])) { http_response_code(401); echo 'Sign in required'; return true; }
            session_write_close();
            $f = Db::one('SELECT * FROM files WHERE id = ?', [$m[1]]);
            if (!$f) { http_response_code(404); echo 'Not found'; return true; }
            Http::sendFile($f);
            return true;
        }
        if (preg_match('#^/submit/([a-f0-9]{16,64})$#', $path, $m)) {
            session_write_close();
            $method === 'POST' ? self::submitPost($m[1]) : self::submitForm($m[1]);
            return true;
        }
        if (preg_match('#^/r/([a-f0-9]{16,64})/files/(\d+)$#', $path, $m)) {
            session_write_close();
            $r = Db::one('SELECT article_id FROM reviewers WHERE token = ?', [$m[1]]);
            $f = $r ? Db::one("SELECT * FROM files WHERE id = ? AND article_id = ? AND kind = 'image'", [$m[2], $r['article_id']]) : null;
            if (!$f) { http_response_code(404); echo 'Not found'; return true; }
            Http::sendFile($f);
            return true;
        }
        if (preg_match('#^/r/([a-f0-9]{16,64})$#', $path, $m)) {
            session_write_close();
            $method === 'POST' ? self::reviewPost($m[1]) : self::reviewPage($m[1]);
            return true;
        }
        return false;
    }

    private static function submitForm(string $token): void
    {
        $e = Db::one('SELECT * FROM editions WHERE submission_token = ?', [$token]);
        if (!$e) { Http::html(self::page('Not found', '<p>This submission link is not valid.</p>'), 404); return; }
        if (!$e['submissions_open']) {
            Http::html(self::page('Submissions closed', '<h1>Submissions closed</h1><p>Submissions for <strong>' . Html::e($e['name']) . '</strong> are closed. Please contact the editorial team.</p>'));
            return;
        }
        $opts = implode('', array_map(fn ($s) => '<option value="' . (int) $s['id'] . '">' . (int) $s['num'] . '. ' . Html::e($s['name']) . '</option>', Db::q('SELECT * FROM sections ORDER BY num')));
        Http::html(self::page('Submit to ' . $e['name'], '
  <h1>Submit an article</h1>
  <p class="muted">Edition: <strong>' . Html::e($e['name']) . '</strong>. Upload your article (Word .docx preferred, or PDF) and any photos (JPG/PNG). All photos are kept exactly as you send them.</p>
  <form method="post" enctype="multipart/form-data" class="pub-form">
    <label>Your name<input name="author_name" required maxlength="190"></label>
    <label>Your email<input name="author_email" type="email" required maxlength="190"></label>
    <label>Section<select name="section_id" required><option value="">Choose a section</option>' . $opts . '</select></label>
    <label>Article title<input name="title" maxlength="290" placeholder="Optional if it is in your document"></label>
    <label>Files<input name="files[]" type="file" multiple accept="' . implode(',', Intake::ALLOWED_EXT) . '"></label>
    <label>Or paste your article<textarea name="text" rows="10" placeholder="Optional"></textarea></label>
    <input name="website" class="hp" tabindex="-1" autocomplete="off">
    <button type="submit">Submit article</button>
  </form>'));
    }

    private static function submitPost(string $token): void
    {
        try {
            $e = Db::one('SELECT * FROM editions WHERE submission_token = ? AND submissions_open = 1', [$token]);
            if (!$e) { Http::html(self::page('Not found', '<p>This submission link is not valid or is closed.</p>'), 404); return; }
            if (!empty($_POST['website'])) { Http::html(self::page('Thank you', '<h1>Thank you</h1>')); return; } // spam trap
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $hits = (int) Db::val("SELECT COUNT(*) FROM activity WHERE action = 'contributor_submit' AND detail = ? AND created_at > NOW() - INTERVAL 1 HOUR", [$ip]);
            if ($hits >= 10) { Http::html(self::page('Slow down', '<p>Too many submissions from this connection. Please try again later.</p>'), 429); return; }
            $files = Intake::uploads();
            $text = trim((string) ($_POST['text'] ?? ''));
            if (!$files && $text === '') throw new AppError('Please upload a file or paste your article.');
            $out = Intake::createArticle([
                'editionId' => (int) $e['id'], 'sectionId' => ($_POST['section_id'] ?? '') ?: null, 'title' => trim((string) ($_POST['title'] ?? '')),
                'authorName' => mb_substr(trim((string) ($_POST['author_name'] ?? '')), 0, 190), 'authorEmail' => mb_substr(trim((string) ($_POST['author_email'] ?? '')), 0, 190),
                'source' => 'contributor', 'bodyHtml' => $text !== '' ? Html::textToHtml($text) : '', 'uploads' => $files,
            ]);
            Activity::log(null, (int) $e['id'], null, 'contributor_submit', $ip);
            Http::html(self::page('Thank you', '<h1>Thank you!</h1><p>Your article has been received by the editorial team (reference #' . (int) $out['articleId'] . ').</p>'));
        } catch (Throwable $err) {
            Http::html(self::page('Problem', '<h1>Something went wrong</h1><p>' . Html::e($err->getMessage()) . '</p><p><a href="">Go back</a></p>'), 400);
        }
    }

    private static function reviewer(string $token): ?array
    {
        return Db::one('SELECT r.*, a.title, a.subtitle, a.author_name, a.edited_html, a.raw_html, e.name AS edition_name, s.name AS section_name
            FROM reviewers r JOIN articles a ON a.id = r.article_id JOIN editions e ON e.id = r.edition_id LEFT JOIN sections s ON s.id = a.section_id WHERE r.token = ?', [$token]);
    }

    private static function reviewPage(string $token): void
    {
        $r = self::reviewer($token);
        if (!$r) { Http::html(self::page('Not found', '<p>This review link is not valid.</p>'), 404); return; }
        $body = preg_replace('/src="\/files\/(\d+)"/', 'src="/r/' . $token . '/files/$1"', (string) ($r['edited_html'] ?: $r['raw_html']));
        $done = in_array($r['status'], ['approved', 'changes'], true);
        Http::html(self::page('Review: ' . $r['title'], '
  <p class="muted">' . Html::e($r['edition_name']) . ' · ' . Html::e($r['section_name'] ?? '') . ($r['due_at'] ? ' · please respond by <strong>' . Html::e(Reviews::fmtDate($r['due_at'])) . '</strong>' : '') . '</p>
  <article class="pub-article"><h1>' . Html::e($r['title']) . '</h1>' . ($r['subtitle'] ? '<p class="subtitle">' . Html::e($r['subtitle']) . '</p>' : '')
            . ($r['author_name'] ? '<p class="byline">By ' . Html::e($r['author_name']) . '</p>' : '') . $body . '</article>'
            . ($done ? '<div class="notice">You already responded (' . ($r['status'] === 'approved' ? 'approved' : 'changes requested') . '). You can update your response below.</div>' : '') . '
  <form method="post" class="pub-form">
    <label>Comments for the editor<textarea name="comments" rows="6">' . Html::e($r['comments'] ?? '') . '</textarea></label>
    <div class="row"><button name="decision" value="approve" type="submit">Approve</button>
    <button name="decision" value="changes" type="submit" class="secondary">Request changes</button></div>
  </form>'));
    }

    private static function reviewPost(string $token): void
    {
        try {
            $out = Reviews::respond($token, (string) ($_POST['decision'] ?? ''), trim((string) ($_POST['comments'] ?? '')));
            Http::html(self::page('Thank you', '<h1>Thank you</h1><p>Your response (' . ($out['status'] === 'approved' ? 'approved' : 'changes requested') . ') has been sent to the editorial team.</p><p><a href="/r/' . Html::e($token) . '">Back to the article</a></p>'));
        } catch (Throwable $e) {
            Http::html(self::page('Problem', '<p>' . Html::e($e->getMessage()) . '</p>'), 400);
        }
    }
}
