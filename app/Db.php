<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', 'localhost'), (int) Config::get('DB_PORT', '3306'), Config::get('DB_NAME', ''));
            self::$pdo = new PDO($dsn, (string) Config::get('DB_USER', ''), (string) Config::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        }
        return self::$pdo;
    }

    /** Runs a statement; returns all rows for SELECT-like queries. */
    public static function q(string $sql, array $args = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($args));
        return $st->columnCount() ? $st->fetchAll() : [];
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $rows = self::q($sql, $args);
        return $rows[0] ?? null;
    }

    public static function val(string $sql, array $args = [])
    {
        $row = self::one($sql, $args);
        return $row ? reset($row) : null;
    }

    /** INSERT/UPDATE/DELETE; returns the insert id (inserts) or affected rows. */
    public static function exec(string $sql, array $args = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($args));
        $id = (int) self::pdo()->lastInsertId();
        return stripos(ltrim($sql), 'INSERT') === 0 ? $id : $st->rowCount();
    }

    /** UPDATE table SET <fields> WHERE id = ? */
    public static function update(string $table, int $id, array $fields): void
    {
        if (!$fields) return;
        $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($fields)));
        self::exec("UPDATE `$table` SET $sets WHERE id = ?", [...array_values($fields), $id]);
    }
}
