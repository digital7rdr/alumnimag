<?php
declare(strict_types=1);

final class Http
{
    /** Outgoing HTTP request. Returns [status, body]; status 0 means a network error (body = message). */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'TheMissingLink-EditorialDesk/2.0',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, "Network error: $err"];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $resp];
    }

    // ---------------- Incoming request helpers ----------------
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body !== null) return self::$body;
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($type, 'application/json') !== false) {
            $data = json_decode((string) file_get_contents('php://input'), true);
            return self::$body = is_array($data) ? $data : [];
        }
        return self::$body = $_POST;
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function sendFile(array $f, bool $download = false): void
    {
        $p = Storage::filePath($f['stored_name']);
        if (!is_file($p)) { http_response_code(404); echo 'File missing'; return; }
        $inline = !$download && preg_match('/^image\//', $f['mime']);
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['original_name']));
        readfile($p);
    }

    /** Sends the response to the browser now and lets PHP keep working (for background jobs). */
    public static function finishRequest(): void
    {
        ignore_user_abort(true);
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
    }
}
