<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function splitList(?string $v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $v)), fn ($s) => $s !== ''));
    }

    private static function send(array $to, array $cc, string $subject, string $html, string $text, ?string $replyTo = null): void
    {
        $s = Settings::all();
        $user = (string) Config::get('SMTP_USER', '');
        $from = (string) Config::get('SMTP_FROM', $user);
        if (in_array(Config::get('MAIL_TRANSPORT'), ['log', 'json'], true)) {
            $line = json_encode(['to' => $to, 'cc' => $cc, 'replyTo' => $replyTo, 'subject' => $subject, 'text' => $text, 'at' => date('c')], JSON_UNESCAPED_UNICODE);
            Storage::ensureDirs();
            file_put_contents(Storage::root() . '/tmp/mail.log', $line . "\n", FILE_APPEND);
            return;
        }
        $pass = (string) Config::get('SMTP_PASS', '');
        if ($user === '' || $pass === '') throw new AppError('SMTP_USER / SMTP_PASS are not set in .env');

        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) Config::get('SMTP_HOST', 'smtp.gmail.com');
        $m->Port = (int) Config::get('SMTP_PORT', '465');
        $m->SMTPAuth = true;
        $m->Username = $user;
        $m->Password = $pass;
        $m->SMTPSecure = Config::get('SMTP_SECURE', 'true') === 'true' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet = 'UTF-8';
        $m->Timeout = 30;
        $m->setFrom($from ?: $user, $s['email_from_name']);
        foreach ($to as $a) $m->addAddress($a);
        foreach ($cc as $a) $m->addCC($a);
        if ($replyTo) $m->addReplyTo($replyTo);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text !== '' ? $text : Html::plainText($html);
        try {
            $m->send();
        } catch (Throwable $e) {
            throw new AppError('Email could not be sent: ' . ($m->ErrorInfo ?: $e->getMessage()));
        }
    }

    /** Every review notification CCs the editorial inbox (default alumnikarunya@gmail.com). */
    public static function sendReviewMail(string $to, string $subject, string $html, string $text = '', array $extraCc = []): array
    {
        $cc = [];
        foreach (array_merge(self::splitList(Settings::get('review_cc')), $extraCc) as $a) {
            $a = strtolower(trim($a));
            if ($a !== '' && $a !== strtolower($to) && !in_array($a, $cc, true)) $cc[] = $a;
        }
        $from = (string) Config::get('SMTP_FROM', (string) Config::get('SMTP_USER', ''));
        self::send([$to], $cc, $subject, $html, $text, $cc[0] ?? $from);
        return ['cc' => $cc];
    }

    public static function sendPlain(array $to, string $subject, string $html): void
    {
        if ($to) self::send($to, [], $subject, $html, '');
    }
}
