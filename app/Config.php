<?php
declare(strict_types=1);

// Reads the .env file (default: <app root>/.env, or the path in ALUMNIMAG_ENV).
final class Config
{
    private static array $env = [];

    public static function load(): void
    {
        $path = getenv('ALUMNIMAG_ENV') ?: (getenv('DOTENV_PATH') ?: APP_ROOT . '/.env');
        if (is_file($path)) self::$env = self::parse((string) file_get_contents($path));
    }

    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'export ')) $line = substr($line, 7);
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $q = $val[0];
                $end = strpos($val, $q, 1);
                $val = $end === false ? substr($val, 1) : substr($val, 1, $end - 1);
                if ($q === '"') $val = str_replace(['\\n', '\\"'], ["\n", '"'], $val);
            } else {
                $hash = strpos($val, ' #');
                if ($hash !== false) $val = rtrim(substr($val, 0, $hash));
            }
            $out[$key] = $val;
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset(self::$env[$key]) && self::$env[$key] !== '') return self::$env[$key];
        return $default;
    }

    public static function baseUrl(): string
    {
        return rtrim((string) self::get('BASE_URL', 'https://drive.readthemissinglink.net'), '/');
    }

    public static function storageDir(): string
    {
        $dir = self::get('STORAGE_DIR', dirname(APP_ROOT) . '/alumnimag-storage');
        return rtrim($dir, '/');
    }

    public static function maxUploadMb(): int
    {
        return (int) self::get('MAX_UPLOAD_MB', '40');
    }
}
