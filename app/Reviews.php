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

    /** Adds a reviewer to an article (linked to the People directory; the person is created if new). */
    public static function add(int $editionId, ?int $articleId, string $name, string $email, ?int $personId = null, int $round = 1): int
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($personId) {
            $p = Db::one('SELECT * FROM people WHERE id = ?', [$personId]);
            if (!$p) throw new AppError('Person not found', 404);
            $name = $p['name'];
            $email = (string) $p['email'];
            if ($email === '') throw new AppError("{$p['name']} has no email address yet - add it under People first");
            People::upsert($p['name'], $email, null, ['reviewer']);
        } else {
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Reviewer name and a valid email are required');
            $p = People::upsert($name, $email, null, ['reviewer']);
            $personId = $p ? (int) $p['id'] : null;
        }
        $round = $round === 2 ? 2 : 1;
        if ($articleId && Db::one('SELECT id FROM reviewers WHERE article_id = ? AND email = ? AND round = ?', [$articleId, $email, $round])) {
            throw new AppError("$name is already a round $round reviewer of this article");
        }
        return Db::exec('INSERT INTO reviewers (edition_id, article_id, person_id, round, name, email, token) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$editionId, $articleId, $personId, $round, $name, $email, bin2hex(random_bytes(24))]);
    }

    /** Adds the section's default Reviewer 1 / Reviewer 2 people to an article (skipping the author and anyone already on it). */
    public static function assignFromSection(int $articleId, ?int $userId = null): array
    {
        $a = Db::one('SELECT * FROM articles WHERE id = ?', [$articleId]);
        if (!$a) throw new AppError('Article not found', 404);
        $out = ['added' => [], 'skipped' => []];
        if (!$a['section_id']) return $out;
        $rows = Db::q("SELECT sp.role, p.* FROM section_people sp JOIN people p ON p.id = sp.person_id
            WHERE sp.section_id = ? AND sp.role IN ('reviewer1','reviewer2') AND p.active = 1 ORDER BY sp.role, p.name", [$a['section_id']]);
        foreach ($rows as $p) {
            $round = $p['role'] === 'reviewer2' ? 2 : 1;
            if ($a['author_name'] && People::norm($a['author_name']) === People::norm($p['name'])) { $out['skipped'][] = "{$p['name']} (the author)"; continue; }
            if (!$p['email']) { $out['skipped'][] = "{$p['name']} (no email)"; continue; }
            if (Db::one('SELECT id FROM reviewers WHERE article_id = ? AND email = ?', [$articleId, $p['email']])) continue;
            self::add((int) $a['edition_id'], $articleId, $p['name'], $p['email'], (int) $p['id'], $round);
            $out['added'][] = "{$p['name']} (round $round)";
        }
        if ($out['added']) Activity::log($articleId, (int) $a['edition_id'], $userId, 'reviewers_assigned', implode(', ', $out['added']));
        return $out;
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
        $round = (int) ($r['round'] ?? 1) === 2 ? ' - review round 2' : '';
        $subject = $reminder ? "Reminder: review due - \"{$art['title']}\"$round" : "Please review: \"{$art['title']}\" (due $due)$round";
        $inner = '<p>Dear ' . Html::e($r['name']) . ',</p><p>'
            . ($reminder ? 'This is a friendly reminder that the article below is waiting for your review.' : 'You have been asked to review the following article for the upcoming edition.') . '</p>'
            . '<p style="background:#f3f4f6;padding:12px 16px;border-radius:6px"><strong>' . Html::e($art['title']) . '</strong><br>'
            . Html::e($art['section_name'] ?? '') . ($art['author_name'] ? ' &middot; by ' . Html::e($art['author_name']) : '') . '<br>'
            . 'Edition: ' . Html::e($art['edition_name'] ?? '') . '</p>'
            . "<p><strong>Please respond by $due.</strong></p>"
            . '<p><a href="' . Html::e($link) . '" style="display:inline-block;background:#1a4a4a;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none">Open the article</a></p>'
            . '<p style="font-size:13px;color:#6b7280">On that page you can approve the article or send comments. Link: ' . Html::e($link) . '</p>';
        $text = "Dear {$r['name']},\n\nPlease review \"{$art['title']}\" by $due.\nOpen: $link\n";
        $extraCc = Settings::get('cc_section_owners') === '1' ? People::ownerEmails($art['section_id'] ? (int) $art['section_id'] : null) : [];
        return Mailer::sendReviewMail($r['email'], $subject, self::layout($inner), $text, $extraCc);
    }

    /** Sends review requests for one article. Due date = now + review_days (default 2 days). */
    public static function sendForArticle(int $articleId, ?int $userId = null, ?array $onlyIds = null): array
    {
        $art = self::articleForMail($articleId);
        if (!$art) throw new AppError('Article not found', 404);
        if (!in_array($art['status'], ['edited', 'in_review'], true)) throw new AppError('Only AI-edited articles can be sent to reviewers');
        if (!Db::one('SELECT id FROM reviewers WHERE article_id = ?', [$articleId]) && Settings::get('auto_assign_reviewers') === '1') self::assignFromSection($articleId, $userId);
        $all = Db::q('SELECT * FROM reviewers WHERE article_id = ? ORDER BY round, id', [$articleId]);
        if (!$all) throw new AppError('Assign at least one reviewer to this article first (or set the section\'s reviewers under People)');
        // Rounds: round 1 first; round 2 only once everyone in round 1 has approved.
        $round = null;
        foreach ($all as $r) if ($r['status'] !== 'approved') { $round = (int) $r['round']; break; }
        if ($round === null) throw new AppError('All reviewers have already approved this article - move it to Compiled');
        $reviewers = array_values(array_filter($all, fn ($r) => (int) $r['round'] === $round && in_array($r['status'], ['pending', 'sent'], true)
            && ($onlyIds === null || in_array((int) $r['id'], $onlyIds, true))));
        if (!$reviewers) throw new AppError("A round $round reviewer asked for changes - update the article, then use \"Resend\" on that reviewer");

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
        Activity::log($articleId, (int) $art['edition_id'], $userId, 'sent_to_reviewers', "Round $round: " . count($res['sent']) . ' sent, ' . count($res['failed']) . ' failed, due ' . self::fmtDate($due));
        return $res + ['dueAt' => $due, 'days' => $days, 'round' => $round];
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
        // Everyone in this round approved and a later round is waiting: send it straight away.
        if ($status === 'approved' && $r['article_id']) {
            $open = (int) Db::val("SELECT COUNT(*) FROM reviewers WHERE article_id = ? AND round = ? AND status <> 'approved'", [$r['article_id'], $r['round']]);
            $next = (int) Db::val("SELECT COUNT(*) FROM reviewers WHERE article_id = ? AND round > ? AND status = 'pending'", [$r['article_id'], $r['round']]);
            if (!$open && $next) {
                try { self::sendForArticle((int) $r['article_id']); } catch (Throwable $e) { error_log('[reviews] next round: ' . $e->getMessage()); }
            }
        }
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
