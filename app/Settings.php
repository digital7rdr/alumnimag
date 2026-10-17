<?php
declare(strict_types=1);

// Settings stored in the database override the .env defaults, so admins can change them in the dashboard.
final class Settings
{
    private static ?array $cache = null;
    public const SECRET_KEYS = ['wp_app_password'];
    public const MASK = '••••••••';

    public static function defaults(): array
    {
        return [
            'review_days' => (string) (int) Config::get('REVIEW_DAYS', '2'),
            'review_cc' => (string) Config::get('REVIEW_CC', 'alumnikarunya@gmail.com'),
            'gemini_model' => (string) Config::get('GEMINI_MODEL', 'gemini-2.5-flash'),
            'ai_prompt' => '',
            'ai_provider' => in_array(Config::get('AI_PROVIDER'), ['gemini', 'groq'], true) ? (string) Config::get('AI_PROVIDER') : (Config::get('GEMINI_API_KEY') || !Config::get('GROQ_API_KEY') ? 'gemini' : 'groq'),
            'ai_fallback' => '1',
            'groq_model' => (string) Config::get('GROQ_MODEL', 'openai/gpt-oss-120b'),
            'wp_url' => rtrim((string) Config::get('WP_URL', 'https://readthemissinglink.net'), '/'),
            'wp_user' => (string) Config::get('WP_USER', ''),
            'wp_app_password' => (string) Config::get('WP_APP_PASSWORD', ''),
            'wp_layout' => 'et_full_width_page',
            'crop_featured' => '1',
            'email_from_name' => 'The Missing Link Editorial',
            // One folder per line: "Folder name = stage". Lines without "= stage" are plain folders.
            'folder_template' => "01. Raw = raw\n02. AI Edited = edited\n03. With Reviewers = in_review\n04. Compiled = compiled\n05. Final = published",
            'folder_sections' => '1',
        ];
    }

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::defaults();
        foreach (Db::q('SELECT k, v FROM settings') as $r) {
            if ($r['v'] !== null && $r['v'] !== '') $out[$r['k']] = $r['v'];
        }
        return self::$cache = $out;
    }

    public static function get(string $k): string
    {
        return (string) (self::all()[$k] ?? '');
    }

    public static function set(array $values): void
    {
        $known = self::defaults();
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, $known)) continue;
            Db::exec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v === null ? '' : (string) $v]);
        }
        self::$cache = null;
    }

    public static function publicView(): array
    {
        $s = self::all();
        foreach (self::SECRET_KEYS as $k) $s[$k] = $s[$k] ? self::MASK : '';
        return $s;
    }
}

final class Activity
{
    public static function log(?int $articleId, ?int $editionId, ?int $userId, string $action, string $detail = ''): void
    {
        try {
            Db::exec('INSERT INTO activity (article_id, edition_id, user_id, action, detail) VALUES (?, ?, ?, ?, ?)',
                [$articleId, $editionId, $userId, $action, mb_substr($detail, 0, 5000)]);
        } catch (Throwable $e) {
            error_log('[activity] ' . $e->getMessage());
        }
    }
}
