<?php
declare(strict_types=1);

final class Reviews
{
    public static function fmtDate(?string $d): string
    {
        return $d ? date('D j F Y', strtotime($d)) : '';
    }

    public static function layout(string $inner): string
    {
        return '<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1f2937">'
            . '<div style="background:#1a4a4a;color:#fff;padding:18px 24px;border-radius:8px 8px 0 0;font-size:18px">The Missing Link &middot; Editorial review</div>'
            . '<div style="border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 8px 8px;line-height:1.6">' . $inner . '</div></div>';
    }

    public static function add(int $editionId, ?int $articleId, string $name, string $email): int
    {
        return Db::exec('INSERT INTO reviewers (edition_id, article_id, name, email, token) VALUES (?, ?, ?, ?, ?)',
            [$editionId, $articleId, trim($name), strtolower(trim($email)), bin2hex(random_bytes(24))]);
    }

    private static function articleForMail(?int $articleId): ?array
    {
        return $articleId ? Db::one('SELECT a.*, s.name AS section_name, e.name AS edition_name FROM articles a
            LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?', [$articleId]) : null;
    }

    private static function sendOne(array $r, array $art, bool $reminder = false): array
    {
        $link = Config::baseUrl() . '/r/' . $r['token'];
        $due = self::fmtDate($r['due_at']);
        $subject = $reminder ? "Reminder: review due - \"{$art['title']}\"" : "Please review: \"{$art['title']}\" (due $due)";
        $inner = '<p>Dear ' . Html::e($r['name']) . ',</p><p>'
            . ($reminder ? 'This is a friendly reminder that the article below is waiting for your review.' : 'You have been asked to review the following article for the upcoming edition.') . '</p>'
            . '<p style="background:#f3f4f6;padding:12px 16px;border-radius:6px"><strong>' . Html::e($art['title']) . '</strong><br>'
            . Html::e($art['section_name'] ?? '') . ($art['author_name'] ? ' &middot; by ' . Html::e($art['author_name']) : '') . '<br>'
            . 'Edition: ' . Html::e($art['edition_name'] ?? '') . '</p>'
            . "<p><strong>Please respond by $due.</strong></p>"
            . '<p><a href="' . Html::e($link) . '" style="display:inline-block;background:#1a4a4a;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none">Open the article</a></p>'
            . '<p style="font-size:13px;color:#6b7280">On that page you can approve the article or send comments. Link: ' . Html::e($link) . '</p>';
        $text = "Dear {$r['name']},\n\nPlease review \"{$art['title']}\" by $due.\nOpen: $link\n";
        return Mailer::sendReviewMail($r['email'], $subject, self::layout($inner), $text);
    }

    /** Sends review requests for one article. Due date = now + review_days (default 2 days). */
    public static function sendForArticle(int $articleId, ?int $userId = null): array
    {
        $art = self::articleForMail($articleId);
        if (!$art) throw new AppError('Article not found', 404);
        if (!in_array($art['status'], ['edited', 'in_review'], true)) throw new AppError('Only AI-edited articles can be sent to reviewers');
        $reviewers = Db::q("SELECT * FROM reviewers WHERE article_id = ? AND status IN ('pending','sent')", [$articleId]);
        if (!$reviewers) throw new AppError('Assign at least one reviewer to this article first');

        $days = max(1, (int) Settings::get('review_days') ?: 2);
        // The due date comes from MySQL so it always matches the NOW() used by the reminder check.
        $due = (string) Db::val('SELECT DATE_ADD(NOW(), INTERVAL ? DAY)', [$days]);
        $res = ['sent' => [], 'failed' => []];
        foreach ($reviewers as $r) {
            try {
                Db::exec("UPDATE reviewers SET status = 'sent', sent_at = NOW(), due_at = ?, reminded_at = NULL WHERE id = ?", [$due, $r['id']]);
                $info = self::sendOne(['due_at' => $due] + $r, $art);
                $res['sent'][] = ['name' => $r['name'], 'email' => $r['email'], 'cc' => $info['cc']];
            } catch (Throwable $e) {
                Db::exec("UPDATE reviewers SET status = 'pending' WHERE id = ?", [$r['id']]);
                $res['failed'][] = ['name' => $r['name'], 'email' => $r['email'], 'error' => $e->getMessage()];
            }
        }
        if ($res['sent']) {
            Db::exec("UPDATE articles SET status = 'in_review' WHERE id = ?", [$articleId]);
            Folders::afterStageChange($articleId);
        }
        Activity::log($articleId, (int) $art['edition_id'], $userId, 'sent_to_reviewers', count($res['sent']) . ' sent, ' . count($res['failed']) . ' failed, due ' . self::fmtDate($due));
        return $res + ['dueAt' => $due, 'days' => $days];
    }

    /** Run by cron: reviewers past their due date get one reminder (CC editorial). */
    public static function sendDueReminders(): int
    {
        $count = 0;
        foreach (Db::q("SELECT * FROM reviewers WHERE status = 'sent' AND due_at IS NOT NULL AND due_at <= NOW() AND reminded_at IS NULL") as $r) {
            try {
                $art = self::articleForMail($r['article_id'] ? (int) $r['article_id'] : null);
                if (!$art || $art['status'] !== 'in_review') continue;
                self::sendOne($r, $art, true);
                Db::exec('UPDATE reviewers SET reminded_at = NOW() WHERE id = ?', [$r['id']]);
                Activity::log((int) $r['article_id'], (int) $r['edition_id'], null, 'reminder_sent', $r['email']);
                $count++;
            } catch (Throwable $e) {
                error_log('[reviews] reminder failed ' . $r['email'] . ': ' . $e->getMessage());
            }
        }
        return $count;
    }

    public static function respond(string $token, string $decision, string $comments): array
    {
        $r = Db::one('SELECT * FROM reviewers WHERE token = ?', [$token]);
        if (!$r) throw new AppError('Review link not found', 404);
        $status = $decision === 'approve' ? 'approved' : 'changes';
        Db::exec('UPDATE reviewers SET status = ?, comments = ?, responded_at = NOW() WHERE id = ?', [$status, mb_substr($comments, 0, 5000), $r['id']]);
        $art = self::articleForMail($r['article_id'] ? (int) $r['article_id'] : null);
        Activity::log($r['article_id'] ? (int) $r['article_id'] : null, (int) $r['edition_id'], null, "review_$status", "{$r['name']}: $comments");
        try {
            $cc = Mailer::splitList(Settings::get('review_cc'));
            if ($cc && $art) {
                Mailer::sendPlain($cc,
                    ($status === 'approved' ? 'Approved' : 'Changes requested') . ": \"{$art['title']}\" by {$r['name']}",
                    self::layout('<p><strong>' . Html::e($r['name']) . '</strong> ' . ($status === 'approved' ? 'approved' : 'requested changes to') . ' <strong>' . Html::e($art['title']) . '</strong>.</p>'
                        . ($comments !== '' ? '<blockquote style="border-left:3px solid #1a4a4a;padding-left:12px">' . nl2br(Html::e($comments)) . '</blockquote>' : '')
                        . '<p><a href="' . Html::e(Config::baseUrl() . '/#/article/' . $art['id']) . '">Open in dashboard</a></p>'));
            }
        } catch (Throwable $e) {
            error_log('[reviews] notify failed: ' . $e->getMessage());
        }
        return ['status' => $status];
    }
}
