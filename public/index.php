<?php
// Front controller for drive.readthemissinglink.net.
// When this folder is copied into the web folder, app-root.php (written by bin/deploy.sh) says where
// the private code lives; when run from the repository it finds ../app directly.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) return false; // local testing: serve static files

$root = is_file(__DIR__ . '/../app/bootstrap.php') ? dirname(__DIR__) : (is_file(__DIR__ . '/app-root.php') ? require __DIR__ . '/app-root.php' : null);
if (!$root || !is_file("$root/app/bootstrap.php")) {
    http_response_code(500);
    exit('The application code was not found. Run bin/deploy.sh again.');
}
require "$root/app/bootstrap.php";

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
session_name('tml_sid');
session_set_cookie_params(['lifetime' => 7 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.gc_maxlifetime', (string) (7 * 86400));
ini_set('session.use_strict_mode', '1');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isApi = str_starts_with($path, '/api/') || $path === '/api';

try {
    session_start();
    Migrate::ensure();
    if ($isApi) {
        Api::handle($method, substr($path, 4) ?: '/');
    } elseif (!PublicPages::handle($method, $path)) {
        // Everything else is the dashboard (single-page app).
        session_write_close();
        header('Cache-Control: no-cache');
        // Version the script/style links so browsers fetch the new files right after every update.
        $v = static fn (string $f) => is_file(__DIR__ . "/$f") ? substr(md5((string) filemtime(__DIR__ . "/$f") . filesize(__DIR__ . "/$f")), 0, 10) : '1';
        $page = (string) file_get_contents(__DIR__ . '/index.html');
        $page = str_replace(['"/app.js"', '"/app.css"'], ['"/app.js?v=' . $v('app.js') . '"', '"/app.css?v=' . $v('app.css') . '"'], $page);
        Http::html($page);
    }
} catch (Throwable $e) {
    $status = $e instanceof WpError ? 502 : ($e instanceof AppError ? $e->getCode() : 500);
    if ($status < 400 || $status > 599) $status = 400;
    if (!$e instanceof AppError) error_log('[alumnimag] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = $e instanceof AppError ? $e->getMessage() : ($e instanceof PDOException ? 'Database error - please try again. (' . $e->getMessage() . ')' : $e->getMessage());
    if (headers_sent()) return;
    if ($isApi) Http::json(['error' => $msg], $status);
    else Http::html('<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif">' . htmlspecialchars($msg) . '</p>', $status);
}
