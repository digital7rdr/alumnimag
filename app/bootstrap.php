<?php
// The Missing Link - Editorial Desk (PHP edition).
// Everything outside public/ is private code; only public/ is copied into the web folder.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('SCHEMA_VERSION', 4);

spl_autoload_register(function (string $class): void {
    $map = [
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/lib/PHPMailer/',
        'Smalot\\PdfParser\\' => __DIR__ . '/lib/Smalot/PdfParser/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});

class AppError extends RuntimeException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message, $status);
    }
}

foreach (['Config', 'Db', 'Settings', 'Storage', 'Html', 'Docx', 'Intake', 'Ai', 'AiImage', 'Mailer', 'Reviews', 'Wp', 'Folders', 'People', 'Xlsx', 'Migrate', 'Http', 'Api', 'PublicPages'] as $m) {
    require __DIR__ . "/$m.php";
}

Config::load();
date_default_timezone_set(Config::get('TIMEZONE', 'Asia/Kolkata'));
