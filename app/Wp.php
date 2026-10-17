<?php
declare(strict_types=1);

final class Wp
{
    private static function cfg(): array
    {
        $s = Settings::all();
        if (!$s['wp_url'] || !$s['wp_user'] || !$s['wp_app_password']) throw new AppError('WordPress URL, user and application password must be set in Settings');
        return [
            'base' => rtrim($s['wp_url'], '/') . '/wp-json/wp/v2',
            'auth' => 'Authorization: Basic ' . base64_encode($s['wp_user'] . ':' . preg_replace('/\s+/', '', $s['wp_app_password'])),
            'layout' => $s['wp_layout'],
            'crop' => $s['crop_featured'] === '1',
            'site' => rtrim($s['wp_url'], '/'),
        ];
    }

    private static function call(array $cfg, string $method, string $path, $json = null, ?string $raw = null, array $headers = [])
    {
        $h = array_merge([$cfg['auth'], 'Accept: application/json'], $headers);
        $body = null;
        if ($json !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        if ($raw !== null) $body = $raw;
        [$status, $resp] = Http::request($method, $cfg['base'] . $path, $h, $body, 120);
        $data = json_decode($resp, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : substr($resp, 0, 200);
            $e = new WpError("WordPress $status: " . strip_tags((string) $msg), $status ?: 502);
            $e->wpCode = is_array($data) ? ($data['code'] ?? null) : null;
            $e->wpData = is_array($data) ? ($data['data'] ?? null) : null;
            throw $e;
        }
        return $data;
    }

    private static function decode(?string $s): string
    {
        return trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function term(array $cfg, string $taxonomy, string $name): int
    {
        // WordPress stores "&" as "&amp;", so search with a plain keyword and compare decoded names.
        $words = array_slice(array_values(array_filter(preg_split('/[\s,&]+/', $name))), 0, 2);
        $found = self::call($cfg, 'GET', "/$taxonomy?per_page=100&search=" . rawurlencode(implode(' ', $words)));
        $norm = fn ($s) => preg_replace('/\s+/', ' ', mb_strtolower(self::decode($s)));
        foreach ((array) $found as $t) if ($norm($t['name'] ?? '') === $norm($name)) return (int) $t['id'];
        try {
            return (int) self::call($cfg, 'POST', "/$taxonomy", ['name' => $name])['id'];
        } catch (WpError $e) {
            if ($e->wpCode === 'term_exists' && !empty($e->wpData['term_id'])) return (int) $e->wpData['term_id'];
            throw $e;
        }
    }

    private static function upload(array $cfg, string $data, string $filename, string $mime, string $title): array
    {
        $media = self::call($cfg, 'POST', '/media', null, $data, [
            "Content-Type: $mime",
            'Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"',
        ]);
        try { self::call($cfg, 'POST', '/media/' . $media['id'], ['alt_text' => $title, 'title' => $title]); } catch (Throwable $e) { /* not important */ }
        return $media;
    }

    /** Centre-crop to exactly $w x $h (like CSS object-fit: cover), JPEG output. */
    public static function cropTo(string $data, int $w, int $h): string
    {
        $src = @imagecreatefromstring($data);
        if (!$src) throw new AppError('The featured photo could not be read for cropping');
        if (function_exists('exif_read_data') && str_starts_with($data, "\xFF\xD8")) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
            $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot) $src = imagerotate($src, $rot, 0);
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
        ob_start();
        imagejpeg($dst, null, 88);
        return (string) ob_get_clean();
    }

    /** Admin only, Compiled articles only. Always creates or updates a DRAFT. */
    public static function publishDraft(int $articleId, ?int $userId): array
    {
        $art = Db::one('SELECT a.*, s.wp_category, e.name AS edition_name FROM articles a
            LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?', [$articleId]);
        if (!$art) throw new AppError('Article not found', 404);
        if (!in_array($art['status'], ['compiled', 'published'], true)) throw new AppError('Only Compiled articles can be sent to WordPress');
        $cfg = self::cfg();
        @set_time_limit(300);
        $content = $art['edited_html'] ?: ($art['raw_html'] ?: '');

        // 1) Upload every inline image (re-using media already uploaded for this article).
        $ids = array_values(array_unique(Html::imageIdsIn($content)));
        $urls = [];
        foreach ($ids as $id) {
            $f = Db::one('SELECT * FROM files WHERE id = ? AND article_id = ?', [$id, $articleId]);
            if (!$f) continue;
            if ($f['wp_media_id'] && $f['wp_url']) { $urls[$id] = $f['wp_url']; continue; }
            $media = self::upload($cfg, Storage::read($f['stored_name']), $f['original_name'], $f['mime'], $art['title']);
            Db::exec('UPDATE files SET wp_media_id = ?, wp_url = ? WHERE id = ?', [$media['id'], $media['source_url'], $id]);
            $urls[$id] = $media['source_url'];
        }
        $body = preg_replace_callback('/<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>/', fn ($m) => isset($urls[(int) $m[1]])
            ? '<img src="' . Html::e($urls[(int) $m[1]]) . '" alt="' . Html::e($art['title']) . '" class="aligncenter size-large">' : '', $content);
        $body = preg_replace('/<p>\s*(<img[^>]+>)\s*<\/p>/', '<figure class="wp-block-image aligncenter size-large">$1</figure>', $body);
        if ($art['subtitle']) $body = '<p class="tml-subtitle"><em>' . Html::e($art['subtitle']) . "</em></p>\n" . $body;

        // 2) Featured image: a separate 1200x600 crop so inline photos keep full quality.
        $featured = 0;
        $fid = (int) ($art['featured_file_id'] ?: ($ids[0] ?? 0));
        if ($fid && (int) $art['wp_featured_file'] === $fid && $art['wp_featured_media']) {
            $featured = (int) $art['wp_featured_media'];
        } elseif ($fid) {
            $f = Db::one('SELECT * FROM files WHERE id = ?', [$fid]);
            if ($f) {
                $data = Storage::read($f['stored_name']);
                $name = $f['original_name'];
                $mime = $f['mime'];
                if ($cfg['crop']) {
                    $data = self::cropTo($data, 1200, 600);
                    $name = preg_replace('/\.[^.]+$/', '', $name) . '-1200x600.jpg';
                    $mime = 'image/jpeg';
                }
                $featured = (int) self::upload($cfg, $data, $name, $mime, $art['title'])['id'];
                Db::exec('UPDATE articles SET wp_featured_media = ?, wp_featured_file = ? WHERE id = ?', [$featured, $fid, $articleId]);
            }
        }

        // 3) Category from the section, edition as tag.
        $payload = [
            'title' => $art['title'],
            'content' => $body,
            'excerpt' => $art['summary'] ?: '',
            'status' => 'draft',
            'categories' => $art['wp_category'] ? [self::term($cfg, 'categories', $art['wp_category'])] : [],
            'tags' => $art['edition_name'] ? [self::term($cfg, 'tags', $art['edition_name'])] : [],
            // Honoured when the tml-bridge mu-plugin is installed (wordpress/tml-bridge.php).
            'meta' => ['_et_pb_use_builder' => 'off', '_et_pb_page_layout' => $cfg['layout'], '_tml_article_id' => (string) $art['id']],
        ];
        if ($featured) $payload['featured_media'] = $featured;

        // 4) Update the existing draft if it still exists; otherwise create a new one.
        $post = null;
        if ($art['wp_post_id']) {
            try {
                $existing = self::call($cfg, 'GET', '/posts/' . $art['wp_post_id'] . '?context=edit');
                if ($existing && ($existing['status'] ?? '') !== 'trash') {
                    $post = self::call($cfg, 'POST', '/posts/' . $art['wp_post_id'], ['status' => $existing['status'] === 'publish' ? 'publish' : 'draft'] + $payload);
                }
            } catch (WpError $e) {
                if (!in_array($e->getCode(), [404, 410], true)) throw $e;
            }
        }
        if (!$post) {
            try {
                $post = self::call($cfg, 'POST', '/posts', $payload);
            } catch (WpError $e) {
                if ($e->getCode() !== 400 || stripos($e->getMessage(), 'meta') === false) throw $e;
                unset($payload['meta']); // bridge plugin not installed
                $post = self::call($cfg, 'POST', '/posts', $payload);
            }
        }

        Db::exec("UPDATE articles SET status = 'published', wp_post_id = ?, wp_link = ?, published_at = NOW() WHERE id = ?", [$post['id'], $post['link'] ?? null, $articleId]);
        Folders::afterStageChange($articleId);
        Activity::log($articleId, (int) $art['edition_id'], $userId, 'wp_draft', "Post {$post['id']} (" . count($ids) . ' images)');
        return ['postId' => $post['id'], 'editUrl' => $cfg['site'] . '/wp-admin/post.php?post=' . $post['id'] . '&action=edit', 'images' => count($ids)];
    }

    public static function test(): array
    {
        $me = self::call(self::cfg(), 'GET', '/users/me?context=edit');
        return ['name' => $me['name'] ?? '', 'roles' => $me['roles'] ?? []];
    }
}

final class WpError extends AppError
{
    public ?string $wpCode = null;
    public $wpData = null;
}
