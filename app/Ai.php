<?php
declare(strict_types=1);

final class Ai
{
    public const DEFAULT_PROMPT = <<<'TXT'
You are the copy editor of "The Missing Link", the alumni magazine of Karunya.
Edit the article for publication:
- Fix grammar, spelling, punctuation and awkward phrasing. Use Indian/British English spelling.
- Keep the author's voice, meaning, facts, names, numbers and quotes. Do not invent or add content.
- Improve flow and paragraphing; add short sub-headings (<h3>) only where the article is long and clearly has sections.
- Every image is marked as [[IMG:number]]. Keep EVERY marker exactly as written, in the same place, each in its own <p>.
- Output clean HTML only using p, h3, strong, em, blockquote, ul, ol, li, a. Never use Markdown such as ** or #.
TXT;

    private const RESPONSE_SHAPE = 'Return ONLY a JSON object with these keys:
{"title": string, "subtitle": string or "", "author_line": string or "", "body_html": string, "summary": string (max 2 sentences), "changes": [short strings describing the main edits]}';

    public static function call(string $system, string $user): array
    {
        $key = Config::get('GEMINI_API_KEY');
        if (!$key) throw new AppError('GEMINI_API_KEY is not set in .env');
        $model = Settings::get('gemini_model') ?: (string) Config::get('GEMINI_MODEL', 'gemini-2.5-flash');
        $base = rtrim((string) Config::get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'), '/');
        $url = "$base/v1beta/models/" . rawurlencode($model) . ':generateContent';
        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['temperature' => 0.3, 'responseMimeType' => 'application/json'],
        ], JSON_UNESCAPED_UNICODE);

        $last = 'Gemini request failed';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$status, $resp] = Http::request('POST', $url, ['Content-Type: application/json', "x-goog-api-key: $key"], $body, 240);
            $data = json_decode($resp, true);
            if ($status >= 200 && $status < 300) {
                $text = implode('', array_map(fn ($p) => $p['text'] ?? '', $data['candidates'][0]['content']['parts'] ?? []));
                if ($text === '') throw new AppError('Gemini returned an empty response' . (isset($data['candidates'][0]['finishReason']) ? ' (' . $data['candidates'][0]['finishReason'] . ')' : ''));
                $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
                if (!is_array($json)) throw new AppError('Gemini did not return valid JSON - please run the review again');
                return $json;
            }
            $last = "Gemini $status: " . ($data['error']['message'] ?? ($status ? substr($resp, 0, 200) : $resp));
            if (!in_array($status, [0, 429, 500, 502, 503, 504], true)) break;
            sleep(2 * $attempt);
        }
        throw new AppError($last, 502);
    }

    /**
     * AI edits TEXT only. Images are replaced by [[IMG:id]] tokens before sending and restored
     * afterwards; any image the model drops is re-attached. Stored files are never touched.
     */
    public static function review(int $articleId, ?int $userId = null): array
    {
        $art = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$art) throw new AppError('Article not found', 404);
        if (!in_array($art['status'], ['raw', 'edited'], true)) throw new AppError("AI review is only available for Raw or Edited articles (this one is {$art['status']})");

        $claimed = Db::exec("UPDATE articles SET status = 'reviewing', ai_error = NULL, ai_queued = 0, ai_started_at = NOW(), ai_prev_status = status WHERE id = ? AND status IN ('raw','edited')", [$articleId]);
        if (!$claimed) throw new AppError('This article is already being reviewed');
        @set_time_limit(300);
        try {
            $t = Html::imagesToTokens($art['raw_html'] ?? '');
            $custom = trim(Settings::get('ai_prompt'));
            $system = ($custom !== '' ? $custom : self::DEFAULT_PROMPT) . "\n\n" . self::RESPONSE_SHAPE;
            $user = "Working title: {$art['title']}\nAuthor: " . ($art['author_name'] ?: 'unknown') . "\n\nARTICLE HTML:\n{$t['html']}";
            $out = self::call($system, $user);

            $restored = Html::tokensToImages(Html::fixMarkdown((string) ($out['body_html'] ?? '')), $t['ids']);
            $body = Html::clean($restored['html']);
            if (Html::plainText($body) === '' && Html::plainText($art['raw_html'] ?? '') !== '') throw new AppError('Gemini returned an empty article - nothing was changed');

            $notes = [];
            foreach ((array) ($out['changes'] ?? []) as $c) if (is_scalar($c)) $notes[] = (string) $c;
            if ($restored['restored']) $notes[] = count($restored['restored']) . ' photo(s) were re-attached at the end because the AI moved them - check their position.';
            $title = mb_substr(trim(str_replace('*', '', (string) ($out['title'] ?? ''))), 0, 290) ?: $art['title'];

            Db::exec("UPDATE articles SET status = 'edited', title = ?, subtitle = ?, author_name = COALESCE(NULLIF(?, ''), author_name),
                edited_html = ?, summary = ?, ai_notes = ?, ai_started_at = NULL WHERE id = ?", [
                $title, mb_substr((string) ($out['subtitle'] ?? ''), 0, 290) ?: null, mb_substr((string) ($out['author_line'] ?? ''), 0, 190),
                $body, ((string) ($out['summary'] ?? '')) ?: null, $notes ? implode("\n", $notes) : null, $articleId,
            ]);
            Db::exec("INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, 'AI edit', ?, ?, ?)", [$articleId, $title, $body, $userId]);
            Activity::log($articleId, (int) $art['edition_id'], $userId, 'ai_review', count($t['ids']) . ' image(s) preserved');
            Folders::afterStageChange($articleId);
            return ['ok' => true, 'title' => $title, 'imagesPreserved' => count($t['ids']), 'restored' => count($restored['restored'])];
        } catch (Throwable $e) {
            Db::exec('UPDATE articles SET status = ?, ai_error = ?, ai_started_at = NULL WHERE id = ?', [$art['status'], mb_substr($e->getMessage(), 0, 1000), $articleId]);
            Activity::log($articleId, (int) $art['edition_id'], $userId, 'ai_error', $e->getMessage());
            throw $e instanceof AppError ? $e : new AppError($e->getMessage());
        }
    }

    /** Marks Raw articles for review; the queue is worked through by processQueue(). */
    public static function queueEdition(int $editionId): int
    {
        return Db::exec("UPDATE articles SET ai_queued = 1, ai_error = NULL WHERE edition_id = ? AND status = 'raw'", [$editionId]);
    }

    /** Works through queued articles one at a time until done or the time budget is used. */
    public static function processQueue(int $seconds = 240): int
    {
        if (!Db::val("SELECT GET_LOCK('tml_ai_queue', 0)")) return 0; // another worker is busy
        $done = 0;
        $start = time();
        try {
            self::recoverStale();
            while (time() - $start < $seconds) {
                $next = Db::one("SELECT id, created_by FROM articles WHERE ai_queued = 1 AND status = 'raw' ORDER BY id LIMIT 1");
                if (!$next) {
                    Db::exec("UPDATE articles SET ai_queued = 0 WHERE ai_queued = 1 AND status <> 'raw'");
                    break;
                }
                try {
                    self::review((int) $next['id'], null);
                } catch (Throwable $e) {
                    Db::exec('UPDATE articles SET ai_queued = 0 WHERE id = ?', [$next['id']]);
                }
                $done++;
            }
        } finally {
            Db::val("SELECT RELEASE_LOCK('tml_ai_queue')");
        }
        return $done;
    }

    /** An AI review that was cut off (server restart, timeout) is put back so it can be run again. */
    public static function recoverStale(): void
    {
        Db::exec("UPDATE articles SET status = COALESCE(ai_prev_status, 'raw'), ai_error = 'The AI review was interrupted - please run it again', ai_started_at = NULL
            WHERE status = 'reviewing' AND (ai_started_at IS NULL OR ai_started_at < NOW() - INTERVAL 10 MINUTE)");
    }

    public static function test(): bool
    {
        return (bool) self::call('Reply as JSON {"ok": true}.', 'ping');
    }
}
