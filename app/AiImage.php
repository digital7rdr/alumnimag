<?php
declare(strict_types=1);

// AI images for articles, generated with Cloudflare Workers AI (free daily allowance).
// Generated pictures are stored like any other photo of the article (never deleted).
final class AiImage
{
    public static function configured(): bool
    {
        return Config::get('CF_ACCOUNT_ID') && Config::get('CF_API_TOKEN');
    }

    /** Uses the text AI (Groq/Gemini) to write an image prompt from the article. */
    public static function suggestPrompt(int $articleId): string
    {
        $a = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$a) throw new AppError('Article not found', 404);
        $text = mb_substr(Html::plainText($a['edited_html'] ?: $a['raw_html']), 0, 2500);
        $system = 'You write prompts for an AI image generator that illustrates articles in "The Missing Link", an alumni magazine of Karunya University in India. '
            . 'Describe ONE clear, tasteful scene that fits the article, in 1-3 sentences: subject, setting, mood, lighting, composition (wide landscape framing). '
            . 'No text, letters, logos or watermarks in the image. Do not depict real, named people; use general figures or objects instead. '
            . 'Return ONLY a JSON object: {"prompt": string}.';
        $out = Ai::call($system, "Title: {$a['title']}\n" . ($a['summary'] ? "Summary: {$a['summary']}\n" : '') . "\nArticle:\n$text");
        $prompt = trim((string) ($out['prompt'] ?? ''));
        if ($prompt === '') throw new AppError('The AI did not suggest a prompt - please write one yourself');
        return mb_substr($prompt, 0, 1500);
    }

    /** Generates an image from the prompt, saves it with the article, and returns the new file id. */
    public static function generate(int $articleId, string $prompt, ?int $userId): array
    {
        $a = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$a) throw new AppError('Article not found', 404);
        $prompt = trim($prompt);
        if ($prompt === '') throw new AppError('Write a description of the picture first (or press "Suggest from article")');
        if (!self::configured()) throw new AppError('AI images need CF_ACCOUNT_ID and CF_API_TOKEN (Cloudflare Workers AI) in .env');

        $style = trim(Settings::get('image_style'));
        $full = mb_substr($prompt . ($style !== '' ? ". $style" : ''), 0, 2000);
        $model = trim(Settings::get('image_model')) ?: '@cf/black-forest-labs/flux-1-schnell';
        $body = ['prompt' => $full];
        if (str_contains($model, 'flux-1-schnell')) $body['steps'] = 8;
        $base = rtrim((string) Config::get('CF_BASE_URL', 'https://api.cloudflare.com'), '/');
        $url = "$base/client/v4/accounts/" . rawurlencode((string) Config::get('CF_ACCOUNT_ID')) . "/ai/run/$model";

        @set_time_limit(180);
        [$status, $resp] = Http::request('POST', $url, ['Content-Type: application/json', 'Authorization: Bearer ' . Config::get('CF_API_TOKEN')], json_encode($body, JSON_UNESCAPED_UNICODE), 150);
        $bytes = null;
        $data = json_decode($resp, true);
        if ($status >= 200 && $status < 300) {
            if (is_array($data)) {
                $b64 = $data['result']['image'] ?? $data['image'] ?? null;
                if (is_string($b64)) $bytes = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $b64), true) ?: null;
            } else {
                $bytes = $resp; // some models return the picture itself
            }
        }
        if (!$bytes) {
            $msg = is_array($data) ? ($data['errors'][0]['message'] ?? $data['error'] ?? json_encode($data)) : ($status ? substr($resp, 0, 200) : $resp);
            if ($status === 429 || stripos((string) $msg, 'neuron') !== false) $msg .= ' (the free daily allowance may be used up - it resets at 00:00 UTC)';
            throw new AppError("Image generation failed (Cloudflare $status): $msg", 502);
        }
        [$mime, $ext] = str_starts_with($bytes, "\x89PNG") ? ['image/png', 'png'] : (str_starts_with($bytes, "\xFF\xD8") ? ['image/jpeg', 'jpg'] : (str_starts_with($bytes, 'RIFF') ? ['image/webp', 'webp'] : [null, null]));
        if (!$mime) throw new AppError('Cloudflare did not return a picture - try again');

        $n = 1 + (int) Db::val("SELECT COUNT(*) FROM files WHERE article_id = ? AND origin = 'ai-image'", [$articleId]);
        $saved = Storage::save($bytes, ['articleId' => $articleId, 'editionId' => (int) $a['edition_id'], 'kind' => 'image', 'mime' => $mime,
            'originalName' => "ai-image-$n.$ext", 'origin' => 'ai-image', 'sortOrder' => 300 + $n]);
        if (!$a['featured_file_id']) Db::exec('UPDATE articles SET featured_file_id = ? WHERE id = ?', [$saved['id'], $articleId]);
        Activity::log($articleId, (int) $a['edition_id'], $userId, 'ai_image', mb_substr($prompt, 0, 500));
        return ['id' => $saved['id'], 'prompt' => $prompt];
    }
}
