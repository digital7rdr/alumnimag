<?php
declare(strict_types=1);

// People directory (reviewers, section owners, contributors, team) and the default reviewers per section.
final class People
{
    public const ROLES = ['reviewer' => 'Reviewer', 'section_owner' => 'Section owner', 'contributor' => 'Contributor', 'team' => 'Team'];
    public const SECTION_ROLES = ['owner' => 'Section owners', 'reviewer1' => 'Reviewer 1', 'reviewer2' => 'Reviewer 2'];

    public static function norm(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name));
        $s = preg_replace('/^(dr|mr|mrs|ms|prof|rev|fr)\.?\s+/u', '', $s);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Same person if the names match, or differ by one letter in a long name ("Fernandez"/"Fernandes"). */
    public static function sameName(?string $a, ?string $b): bool
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        return min(strlen($a), strlen($b)) >= 8 && levenshtein($a, $b) <= 1;
    }

    private static function cleanRoles($roles): string
    {
        $list = is_array($roles) ? $roles : explode(',', (string) $roles);
        $list = array_values(array_unique(array_filter(array_map('trim', $list), fn ($r) => isset(self::ROLES[$r]))));
        return implode(',', $list);
    }

    public static function list(string $q = '', string $role = ''): array
    {
        $where = ['1=1'];
        $args = [];
        if ($q !== '') { $where[] = '(p.name LIKE ? OR p.email LIKE ? OR p.batch LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
        if ($role !== '' && isset(self::ROLES[$role])) { $where[] = 'FIND_IN_SET(?, p.roles)'; $args[] = $role; }
        $rows = Db::q('SELECT p.* FROM people p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.active DESC, p.name', $args);
        $links = [];
        foreach (Db::q('SELECT sp.person_id, sp.role, s.num, s.name FROM section_people sp JOIN sections s ON s.id = sp.section_id ORDER BY s.num') as $l) {
            $links[(int) $l['person_id']][] = ['role' => $l['role'], 'section' => $l['num'] . '. ' . $l['name']];
        }
        foreach ($rows as &$r) $r['sections'] = $links[(int) $r['id']] ?? [];
        return $rows;
    }

    public static function save(?int $id, array $b): array
    {
        $name = trim((string) ($b['name'] ?? ''));
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if ($name === '') throw new AppError('Name is required');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError("\"$email\" is not a valid email address");
        if ($email !== '' && Db::one('SELECT id FROM people WHERE email = ? AND id <> ?', [$email, (int) $id])) throw new AppError("Someone else already has the email $email");
        $f = [
            'name' => mb_substr($name, 0, 150), 'email' => $email ?: null,
            'batch' => mb_substr(trim((string) ($b['batch'] ?? '')), 0, 60) ?: null,
            'linkedin' => mb_substr(trim((string) ($b['linkedin'] ?? '')), 0, 300) ?: null,
            'phone' => mb_substr(trim((string) ($b['phone'] ?? '')), 0, 40) ?: null,
            'notes' => mb_substr(trim((string) ($b['notes'] ?? '')), 0, 2000) ?: null,
            'roles' => self::cleanRoles($b['roles'] ?? ''),
            'active' => array_key_exists('active', $b) ? ($b['active'] ? 1 : 0) : 1,
        ];
        if ($id) {
            if (!Db::one('SELECT id FROM people WHERE id = ?', [$id])) throw new AppError('Person not found', 404);
            Db::update('people', $id, $f);
        } else {
            $id = Db::exec('INSERT INTO people (name, email, batch, linkedin, phone, notes, roles, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_values($f));
        }
        return Db::one('SELECT * FROM people WHERE id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM section_people WHERE person_id = ?', [$id]);
        Db::exec('UPDATE reviewers SET person_id = NULL WHERE person_id = ?', [$id]);
        Db::exec('DELETE FROM people WHERE id = ?', [$id]);
    }

    /** Finds a person by email, else by name; creates them if missing. Adds the given roles and fills blanks. */
    public static function upsert(string $name, ?string $email, ?string $batch, array $roles, array &$stats = []): ?array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $email = strtolower(trim((string) $email));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        if ($name === '' && $email === '') return null;
        $p = $email !== '' ? Db::one('SELECT * FROM people WHERE email = ?', [$email]) : null;
        if (!$p && $name !== '') {
            foreach (Db::q('SELECT * FROM people') as $cand) {
                if (self::sameName($cand['name'], $name) && (!$email || !$cand['email'] || $cand['email'] === $email)) { $p = $cand; break; }
            }
        }
        if (!$p && $name !== '' && $batch) {
            // "Anesh D Sundar" and "Anesh Sundar" from the same batch are one person (initials dropped).
            $bare = fn ($n) => implode(' ', array_filter(explode(' ', self::norm($n)), fn ($w) => mb_strlen($w) > 1));
            $nb = $bare($name);
            foreach (Db::q('SELECT * FROM people WHERE batch = ?', [$batch]) as $cand) {
                if ($nb !== '' && str_contains($nb, ' ') && $bare($cand['name']) === $nb && (!$email || !$cand['email'] || $cand['email'] === $email)) { $p = $cand; break; }
            }
        }
        if (!$p) {
            $id = Db::exec('INSERT INTO people (name, email, batch, roles, active) VALUES (?, ?, ?, ?, 1)',
                [mb_substr($name ?: $email, 0, 150), $email ?: null, $batch ?: null, self::cleanRoles($roles)]);
            $stats['added'] = ($stats['added'] ?? 0) + 1;
            return Db::one('SELECT * FROM people WHERE id = ?', [$id]);
        }
        $newRoles = self::cleanRoles(array_merge(explode(',', (string) $p['roles']), $roles));
        $f = [];
        if ($newRoles !== $p['roles']) $f['roles'] = $newRoles;
        if (!$p['email'] && $email) $f['email'] = $email;
        if ($name !== '' && mb_strlen($name) > mb_strlen($p['name']) && self::norm($name) !== self::norm($p['name']) && str_contains(self::norm($name), explode(' ', self::norm($p['name']))[0])) $f['name'] = mb_substr($name, 0, 150);
        if (!$p['batch'] && $batch) $f['batch'] = mb_substr($batch, 0, 60);
        if ($f) { Db::update('people', (int) $p['id'], $f); $stats['updated'] = ($stats['updated'] ?? 0) + 1; $p = $f + $p; }
        return $p;
    }

    /** Matches a short name from a spreadsheet ("Regi", "Dr Marshal") to one person, or null if none/ambiguous. */
    public static function matchName(string $token, ?bool &$ambiguous = null): ?array
    {
        $ambiguous = false;
        $n = self::norm($token);
        if ($n === '') return null;
        $all = Db::q('SELECT * FROM people');
        foreach ($all as $p) if (self::norm($p['name']) === $n) return $p;
        $close = array_values(array_filter($all, fn ($p) => self::sameName($p['name'], $token)));
        if (count($close) === 1) return $close[0];
        $hits = array_values(array_filter($all, fn ($p) => str_starts_with(self::norm($p['name']) . ' ', $n . ' ') || str_starts_with(self::norm($p['name']), $n)));
        if (count($hits) === 1) return $hits[0];
        if (count($hits) > 1) $ambiguous = true;
        return null;
    }

    private static function sectionFor(string $label): ?array
    {
        $key = fn ($s) => str_replace(['colour', ' and '], ['color', ' '], self::norm(preg_replace('/^\s*[IVXLC]+\.\s*/', '', $s)));
        $l = $key($label);
        if ($l === '') return null;
        foreach (Db::q('SELECT * FROM sections ORDER BY num') as $s) {
            $k = $key($s['name']);
            if ($k === $l || str_starts_with($l, $k) || str_starts_with($k, $l)) return $s;
        }
        return null;
    }

    private static function defaultRole(string $sheet): ?string
    {
        $s = strtolower($sheet);
        if (preg_match('/editor|reviewer/', $s)) return 'reviewer';
        if (preg_match('/team|member|committee/', $s)) return 'team';
        if (preg_match('/contributor|author|writer|people|alumni/', $s)) return 'contributor';
        return null;
    }

    /**
     * Imports people from an Excel/CSV file.
     * - Sheets named like Editors/Reviewers (reviewer), Team (team), Contributors/Authors (contributor), or any sheet with
     *   a Name + Email header. Columns found by header: name, email, batch/years, linkedin, phone, role.
     * - A "Status" sheet with a "Section owners" column: rows like "IX. LIGHT & COLOR | Nivitha" set the section owners;
     *   optional "Reviewer 1" / "Reviewer 2" columns on the same sheet set the section's default reviewers.
     */
    public static function import(string $path, string $originalName, ?int $userId): array
    {
        $book = Xlsx::read($path, $originalName);
        $stats = ['added' => 0, 'updated' => 0];
        $report = ['sheets' => [], 'sectionOwners' => [], 'sectionReviewers' => [], 'unmatched' => [], 'skipped' => []];
        $ownerSheets = [];
        $isBatch = fn ($v) => (bool) preg_match('/^\s*(\d{4}\s*[-–]\s*\d{2,4}|\d{4}\s*student|faculty.*)\s*$/i', $v);

        foreach ($book as $sheet => $rows) {
            $hdrIdx = null;
            $cols = [];
            foreach (array_slice($rows, 0, 6, true) as $i => $row) {
                foreach ($row as $c => $v) {
                    $h = strtolower($v);
                    if (preg_match('/^(full )?name$|^editor( list)?$|^reviewer( name)?$|^author$|^contributor$|^person$/', $h)) $cols['name'] ??= $c;
                    elseif (preg_match('/e-?mail/', $h)) $cols['email'] ??= $c;
                    elseif (preg_match('/^batch|years|year of/', $h)) $cols['batch'] ??= $c;
                    elseif (str_contains($h, 'linkedin')) $cols['linkedin'] ??= $c;
                    elseif (preg_match('/phone|mobile|whatsapp/', $h)) $cols['phone'] ??= $c;
                    elseif ($h === 'role' || $h === 'roles') $cols['role'] ??= $c;
                    elseif (str_contains($h, 'section owner')) $cols['owners'] ??= $c;
                    elseif (preg_match('/^reviewers?\s*(1|one|i)$/', $h)) $cols['reviewer1'] ??= $c;
                    elseif (preg_match('/^reviewers?\s*(2|two|ii)$/', $h)) $cols['reviewer2'] ??= $c;
                    elseif (preg_match('/^sections?( \/ articles?)?$|^articles?$/', $h)) $cols['sections'] ??= $c;
                }
                if (isset($cols['owners']) && !isset($cols['sections'])) unset($cols['owners']); // a "Section Owner" value, not a header
                if (isset($cols['owners']) || isset($cols['name'])) { $hdrIdx = $i; break; }
                $cols = [];
            }
            if (isset($cols['owners'])) {
                $ownerSheets[$sheet] = [$rows, array_filter(['owner' => $cols['owners'], 'reviewer1' => $cols['reviewer1'] ?? null, 'reviewer2' => $cols['reviewer2'] ?? null], fn ($c) => $c !== null), $hdrIdx];
                continue;
            }
            $role = self::defaultRole($sheet);
            if (!$role && !(isset($cols['name']) && isset($cols['email']))) { $report['skipped'][] = $sheet; continue; }
            $role ??= 'contributor';

            $count = 0;
            foreach ($rows as $i => $row) {
                if ($hdrIdx !== null && $i <= $hdrIdx) continue;
                if (isset($cols['name'])) {
                    $name = $row[$cols['name']] ?? '';
                    $email = isset($cols['email']) ? ($row[$cols['email']] ?? '') : '';
                    $batch = isset($cols['batch']) ? ($row[$cols['batch']] ?? '') : '';
                } else { // no header row: first text cell is the name, a "1987-1991" cell is the batch
                    $name = $email = $batch = '';
                    foreach ($row as $v) {
                        if (str_contains($v, '@')) $email = $email ?: $v;
                        elseif ($isBatch($v)) $batch = $batch ?: $v;
                        elseif ($name === '' && preg_match('/\p{L}{2,}/u', $v) && !is_numeric($v) && mb_strlen($v) <= 80) $name = $v;
                    }
                }
                foreach ($row as $v) if ($email === '' && filter_var(trim($v), FILTER_VALIDATE_EMAIL)) $email = trim($v);
                if (preg_match('/^(s\.?\s?no|name|editor list|years in)/i', $name) || ($name !== '' && !preg_match('/\p{L}{2,}/u', $name))) continue;
                $roles = [$role];
                if (isset($cols['role']) && ($row[$cols['role']] ?? '') !== '') {
                    $roles = []; // a Role column overrides the sheet's default role
                    foreach (preg_split('/[,;\/]+/', strtolower($row[$cols['role']])) as $r) {
                        $r = str_replace(' ', '_', trim($r));
                        if (isset(self::ROLES[$r])) $roles[] = $r; elseif ($r === 'owner') $roles[] = 'section_owner';
                    }
                }
                $p = self::upsert($name, $email, $isBatch($batch) || $batch !== '' ? $batch : null, $roles, $stats);
                if ($p) {
                    $count++;
                    $extra = [];
                    if (isset($cols['linkedin']) && ($row[$cols['linkedin']] ?? '') !== '' && !$p['linkedin']) $extra['linkedin'] = mb_substr($row[$cols['linkedin']], 0, 300);
                    if (isset($cols['phone']) && ($row[$cols['phone']] ?? '') !== '' && !$p['phone']) $extra['phone'] = mb_substr($row[$cols['phone']], 0, 40);
                    if ($extra) Db::update('people', (int) $p['id'], $extra);
                }
            }
            $report['sheets'][] = ['sheet' => $sheet, 'role' => self::ROLES[$role], 'people' => $count];
        }

        // Section owners (and optional Reviewer 1 / Reviewer 2 columns) from a Status-style sheet,
        // after everyone is known so short names can be matched.
        foreach ($ownerSheets as $sheet => [$rows, $roleCols, $hdrIdx]) {
            $firstCol = min($roleCols);
            $setCount = 0;
            foreach ($rows as $i => $row) {
                if ($i <= $hdrIdx) continue;
                $label = '';
                foreach ($row as $c => $v) if ($c < $firstCol && preg_match('/^\s*[IVXLC]+\.\s+\S/', $v)) $label = $v;
                if ($label === '') continue;
                $section = null;
                foreach ($roleCols as $secRole => $col) {
                    $cell = trim($row[$col] ?? '');
                    if ($cell === '' || $cell === '-') continue;
                    $section ??= self::sectionFor($label);
                    if (!$section) { $report['unmatched'][] = "Section not found: $label"; break; }
                    $names = [];
                    foreach (preg_split('/\s*(?:,|\/|&|;|\band\b)\s*/i', $cell) as $tok) {
                        $tok = trim($tok);
                        if ($tok === '' || mb_strlen($tok) < 2) continue;
                        $amb = false;
                        $p = self::matchName($tok, $amb);
                        $what = self::SECTION_ROLES[$secRole];
                        if (!$p && $amb) { $report['unmatched'][] = "\"$tok\" ({$section['name']}, $what) matches more than one person - use the full name or set it by hand"; continue; }
                        $personRole = $secRole === 'owner' ? 'section_owner' : 'reviewer';
                        if (!$p) $p = self::upsert($tok, null, null, [$personRole], $stats);
                        else self::upsert($p['name'], $p['email'], null, [$personRole], $stats);
                        Db::exec('INSERT IGNORE INTO section_people (section_id, person_id, role) VALUES (?, ?, ?)', [$section['id'], $p['id'], $secRole]);
                        $names[] = $p['name'];
                        $setCount++;
                    }
                    if ($names && $secRole === 'owner') $report['sectionOwners'][] = ['section' => $section['num'] . '. ' . $section['name'], 'owners' => $names];
                    if ($names && $secRole !== 'owner') $report['sectionReviewers'][] = ['section' => $section['num'] . '. ' . $section['name'], 'round' => $secRole === 'reviewer1' ? 1 : 2, 'people' => $names];
                }
            }
            $report['sheets'][] = ['sheet' => $sheet, 'role' => 'Section owners / reviewers', 'people' => $setCount];
        }
        Activity::log(null, null, $userId, 'people_imported', "$originalName: {$stats['added']} added, {$stats['updated']} updated");
        return $stats + $report;
    }

    public static function sectionConfig(): array
    {
        $out = [];
        foreach (Db::q('SELECT * FROM sections ORDER BY num') as $s) {
            $s += ['owner' => [], 'reviewer1' => [], 'reviewer2' => []];
            foreach (Db::q('SELECT sp.role, p.id, p.name, p.email FROM section_people sp JOIN people p ON p.id = sp.person_id WHERE sp.section_id = ? ORDER BY p.name', [$s['id']]) as $p) {
                $s[$p['role']][] = ['id' => (int) $p['id'], 'name' => $p['name'], 'email' => $p['email']];
            }
            $out[] = $s;
        }
        return $out;
    }

    public static function setSection(int $sectionId, array $b): void
    {
        if (!Db::one('SELECT id FROM sections WHERE id = ?', [$sectionId])) throw new AppError('Section not found', 404);
        foreach (array_keys(self::SECTION_ROLES) as $role) {
            if (!array_key_exists($role, $b)) continue;
            Db::exec('DELETE FROM section_people WHERE section_id = ? AND role = ?', [$sectionId, $role]);
            foreach (array_unique(array_map('intval', (array) $b[$role])) as $pid) {
                if (!$pid || !Db::one('SELECT id FROM people WHERE id = ?', [$pid])) continue;
                Db::exec('INSERT IGNORE INTO section_people (section_id, person_id, role) VALUES (?, ?, ?)', [$sectionId, $pid, $role]);
                $add = $role === 'owner' ? 'section_owner' : 'reviewer';
                $p = Db::one('SELECT * FROM people WHERE id = ?', [$pid]);
                $roles = self::cleanRoles(array_merge(explode(',', (string) $p['roles']), [$add]));
                if ($roles !== $p['roles']) Db::update('people', $pid, ['roles' => $roles]);
            }
        }
    }

    /** Email addresses of the section owners of an article's section (for CC). */
    public static function ownerEmails(?int $sectionId): array
    {
        if (!$sectionId) return [];
        return array_values(array_filter(array_column(Db::q("SELECT p.email FROM section_people sp JOIN people p ON p.id = sp.person_id
            WHERE sp.section_id = ? AND sp.role = 'owner' AND p.active = 1 AND p.email IS NOT NULL", [$sectionId]), 'email')));
    }
}
