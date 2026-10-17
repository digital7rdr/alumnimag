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

    public const PROVIDERS = ['gemini' => 'Google Gemini', 'groq' => 'Groq'];
    public static ?string $lastProvider = null;
    public static array $lastNotes = [];

    public static function hasKey(string $provider): bool
    {
        return (bool) Config::get($provider === 'groq' ? 'GROQ_API_KEY' : 'GEMINI_API_KEY');
    }

    /** Main provider first; the other one is tried when the main one fails (if enabled and its key is set). */
    public static function order(): array
    {
        $main = Settings::get('ai_provider') === 'groq' ? 'groq' : 'gemini';
        $other = $main === 'groq' ? 'gemini' : 'groq';
        $list = [$main];
        if (Settings::get('ai_fallback') === '1' && self::hasKey($other)) $list[] = $other;
        return $list;
    }

    /** Sends the prompt and returns the JSON object from the model. */
    public static function call(string $system, string $user, ?string $only = null): array
    {
        self::$lastNotes = [];
        $errors = [];
        foreach ($only ? [$only] : self::order() as $p) {
            try {
                $out = $p === 'groq' ? self::groq($system, $user) : self::gemini($system, $user);
                self::$lastProvider = $p;
                if ($errors) self::$lastNotes[] = 'Edited with ' . self::PROVIDERS[$p] . ' because ' . implode('; ', $errors);
                return $out;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new AppError(implode(' | ', $errors), 502);
    }

    private static function parseJson(string $text, string $who): array
    {
        $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
        if (!is_array($json)) throw new AppError("$who did not return valid JSON - please run the review again");
        return $json;
    }

    private static function retryable(int $status): bool
    {
        return in_array($status, [0, 429, 500, 502, 503, 504], true);
    }

    private static function gemini(string $system, string $user): array
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
                return self::parseJson($text, 'Gemini');
            }
            $last = "Gemini $status: " . ($data['error']['message'] ?? ($status ? substr($resp, 0, 200) : $resp));
            if (!self::retryable($status)) break;
            sleep(2 * $attempt);
        }
        throw new AppError($last, 502);
    }

    // Groq uses the OpenAI-compatible chat API.
    private static function groq(string $system, string $user): array
    {
        $key = Config::get('GROQ_API_KEY');
        if (!$key) throw new AppError('GROQ_API_KEY is not set in .env');
        $model = trim(Settings::get('groq_model')) ?: 'openai/gpt-oss-120b';
        // Models Groq has shut down -> their recommended replacements (https://console.groq.com/docs/deprecations)
        $retired = ['llama-3.3-70b-versatile' => 'openai/gpt-oss-120b', 'llama-3.1-8b-instant' => 'openai/gpt-oss-20b',
            'qwen/qwen3-32b' => 'openai/gpt-oss-120b', 'meta-llama/llama-4-scout-17b-16e-instruct' => 'openai/gpt-oss-120b',
            'moonshotai/kimi-k2-instruct-0905' => 'openai/gpt-oss-120b', 'qwen/qwen3.6-27b' => 'openai/gpt-oss-120b'];
        $model = $retired[$model] ?? $model;
        $base = rtrim((string) Config::get('GROQ_BASE_URL', 'https://api.groq.com/openai'), '/');
        $body = json_encode([
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
            'max_completion_tokens' => 16000,
        ] + (str_starts_with($model, 'openai/gpt-oss') ? ['reasoning_effort' => 'low'] : []), JSON_UNESCAPED_UNICODE);

        $last = 'Groq request failed';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$status, $resp] = Http::request('POST', "$base/v1/chat/completions", ['Content-Type: application/json', "Authorization: Bearer $key"], $body, 240);
            $data = json_decode($resp, true);
            if ($status >= 200 && $status < 300) {
                $choice = $data['choices'][0] ?? [];
                if (($choice['finish_reason'] ?? '') === 'length') throw new AppError('Groq stopped early because the article is too long for the model - split it or use Gemini');
                $text = (string) ($choice['message']['content'] ?? '');
                if ($text === '') throw new AppError('Groq returned an empty response');
                return self::parseJson($text, 'Groq');
            }
            $msg = $data['error']['message'] ?? ($status ? substr($resp, 0, 200) : $resp);
            if ($status === 413 || stripos((string) $msg, 'tokens per minute') !== false) $msg .= ' (the free Groq tier limits tokens per minute - long articles may need Gemini)';
            $last = "Groq $status: $msg";
            if (!self::retryable($status) || $status === 413) break;
            sleep(3 * $attempt);
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
            if (Html::plainText($body) === '' && Html::plainText($art['raw_html'] ?? '') !== '') throw new AppError('The AI returned an empty article - nothing was changed');

            $notes = [];
            foreach ((array) ($out['changes'] ?? []) as $c) if (is_scalar($c)) $notes[] = (string) $c;
            array_push($notes, ...self::$lastNotes);
            if ($restored['restored']) $notes[] = count($restored['restored']) . ' photo(s) were re-attached at the end because the AI moved them - check their position.';
            $title = mb_substr(trim(str_replace('*', '', (string) ($out['title'] ?? ''))), 0, 290) ?: $art['title'];

            // (Decided in PHP, not SQL: comparing two text values in SQL fails on servers with mixed collations.)
            $authorLine = trim(mb_substr((string) ($out['author_line'] ?? ''), 0, 190));
            $author = $authorLine !== '' ? $authorLine : $art['author_name'];
            Db::exec("UPDATE articles SET status = 'edited', title = ?, subtitle = ?, author_name = ?,
                edited_html = ?, summary = ?, ai_notes = ?, ai_started_at = NULL WHERE id = ?", [
                $title, mb_substr((string) ($out['subtitle'] ?? ''), 0, 290) ?: null, $author,
                $body, ((string) ($out['summary'] ?? '')) ?: null, $notes ? implode("\n", $notes) : null, $articleId,
            ]);
            $who = self::PROVIDERS[self::$lastProvider] ?? 'AI';
            Db::exec('INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, ?, ?, ?, ?)', [$articleId, 'AI edit (' . $who . ')', $title, $body, $userId]);
            Activity::log($articleId, (int) $art['edition_id'], $userId, 'ai_review', "$who: " . count($t['ids']) . ' image(s) preserved');
            Folders::afterStageChange($articleId);
            return ['ok' => true, 'title' => $title, 'imagesPreserved' => count($t['ids']), 'restored' => count($restored['restored']), 'provider' => $who];
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

    public static function test(string $provider): bool
    {
        if (!isset(self::PROVIDERS[$provider])) throw new AppError('Unknown AI provider');
        return (bool) self::call('Reply with the JSON object {"ok": true}.', 'ping', $provider);
    }
}
