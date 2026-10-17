<?php
// Quick health check: PHP extensions, .env values, database, storage folder.
//   php bin/check.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

$ok = true;
$line = function (bool $good, string $msg) use (&$ok) { echo ($good ? '  ok   ' : '  FAIL ') . $msg . "\n"; if (!$good) $ok = false; };

echo "PHP " . PHP_VERSION . "\n";
$line(version_compare(PHP_VERSION, '8.1', '>='), 'PHP 8.1 or newer');
foreach (['pdo_mysql', 'curl', 'zip', 'gd', 'dom', 'mbstring', 'openssl', 'fileinfo'] as $ext) $line(extension_loaded($ext), "extension $ext");

$keys = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'SMTP_USER', 'SMTP_PASS', 'ADMIN_EMAIL'];
if (in_array(Config::get('MAIL_TRANSPORT'), ['log', 'json'], true)) $keys = array_diff($keys, ['SMTP_USER', 'SMTP_PASS']);
foreach ($keys as $k) {
    $v = Config::get($k);
    $line($v !== null && $v !== '', "$k " . ($v ? 'set (' . strlen($v) . ' chars)' : 'MISSING'));
}

$g = (bool) Config::get('GEMINI_API_KEY');
$q = (bool) Config::get('GROQ_API_KEY');
$line($g || $q, 'AI key: ' . ($g ? 'GEMINI_API_KEY set' : 'no GEMINI_API_KEY') . ', ' . ($q ? 'GROQ_API_KEY set' : 'no GROQ_API_KEY') . ($g || $q ? '' : ' - set at least one'));

try {
    Migrate::run();
    $line(true, 'database connected, tables ready (' . Db::val('SELECT COUNT(*) FROM users') . ' user(s))');
} catch (Throwable $e) {
    $line(false, 'database: ' . $e->getMessage());
}

Storage::ensureDirs();
$line(is_dir(Storage::root()) && is_writable(Storage::root()), 'storage folder writable: ' . Storage::root());
echo $ok ? "All good.\n" : "Fix the FAIL lines above.\n";
exit($ok ? 0 : 1);
