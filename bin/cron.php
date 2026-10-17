<?php
// Background jobs - run every 5 minutes from Hostinger's Cron Jobs:
//   php /home/u604557415/alumnimag/bin/cron.php
// - sends one reminder to reviewers whose 2-day deadline has passed (CC editorial inbox)
// - finishes any "AI review all" articles still waiting in the queue
// - clears old temporary ZIP files
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

Migrate::ensure();
$reminders = Reviews::sendDueReminders();
$reviewed = Ai::processQueue(240);
foreach (glob(Storage::root() . '/tmp/zip-*.zip') ?: [] as $z) if (filemtime($z) < time() - 3600) @unlink($z);
@touch(Storage::root() . '/tmp/cron-last-run');
echo date('c') . " reminders=$reminders ai_reviewed=$reviewed\n";
