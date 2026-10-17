<?php
declare(strict_types=1);

// Creates/updates the database tables. Runs automatically once per schema version.
final class Migrate
{
    public const SECTIONS = [
        [1, 'Currently in Karunya', '01 Currently In Karunya'],
        [2, 'VOX POPULI', '02 Vox Populi'],
        [3, 'Business, Entrepreneurship & Innovation', '03 Business, Entrepreneurship, & Innovation'],
        [4, 'Finance', '04 Finance'],
        [5, 'Fitness & Health', '05 Fitness & Health'],
        [6, 'Food', '06 Food'],
        [7, 'Like Unlike', '07 Like Unlike'],
        [8, 'A Celebration of Life', '08 A Celebration Of Life'],
        [9, 'Light & Colour', '09 Light & Colour'],
        [10, 'LOL', '10 LOL'],
        [11, 'Alumni Advertisements', '11 Alumni Advertisements'],
    ];

    private const TABLES = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(100) NOT NULL,
    role ENUM('admin','editor') NOT NULL DEFAULT 'editor',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    num INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    wp_category VARCHAR(190) NOT NULL,
    UNIQUE KEY uq_num (num)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS editions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    submission_token VARCHAR(64) NOT NULL UNIQUE,
    submissions_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    edition_id INT NOT NULL,
    section_id INT NULL,
    title VARCHAR(300) NOT NULL,
    subtitle VARCHAR(300) NULL,
    author_name VARCHAR(190) NULL,
    author_email VARCHAR(190) NULL,
    status ENUM('raw','reviewing','edited','in_review','compiled','published') NOT NULL DEFAULT 'raw',
    source ENUM('upload','editor','contributor') NOT NULL DEFAULT 'upload',
    raw_html MEDIUMTEXT NULL,
    edited_html MEDIUMTEXT NULL,
    summary TEXT NULL,
    ai_notes TEXT NULL,
    ai_error TEXT NULL,
    featured_file_id INT NULL,
    wp_post_id INT NULL,
    wp_link VARCHAR(500) NULL,
    wp_featured_media INT NULL,
    wp_featured_file INT NULL,
    published_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_edition (edition_id),
    KEY idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS article_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    label VARCHAR(60) NOT NULL,
    title VARCHAR(300) NULL,
    html MEDIUMTEXT NOT NULL,
    user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NULL,
    edition_id INT NULL,
    kind ENUM('source','image','attachment') NOT NULL,
    original_name VARCHAR(300) NOT NULL,
    stored_name VARCHAR(700) NOT NULL,
    mime VARCHAR(120) NOT NULL,
    size INT NOT NULL DEFAULT 0,
    sha1 CHAR(40) NULL,
    origin VARCHAR(40) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    wp_media_id INT NULL,
    wp_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS reviewers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    edition_id INT NOT NULL,
    article_id INT NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','sent','approved','changes') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    due_at DATETIME NULL,
    reminded_at DATETIME NULL,
    responded_at DATETIME NULL,
    comments TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_edition (edition_id),
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    edition_id INT NOT NULL,
    parent_id INT NULL,
    name VARCHAR(190) NOT NULL,
    kind ENUM('stage','section','custom','article') NOT NULL DEFAULT 'custom',
    stage VARCHAR(20) NULL,
    section_id INT NULL,
    sort INT NOT NULL DEFAULT 0,
    path VARCHAR(700) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_edition (edition_id),
    KEY idx_parent (parent_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(80) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NULL,
    edition_id INT NULL,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    ];

    private const TABLES_V4 = [
        "CREATE TABLE IF NOT EXISTS people (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            batch VARCHAR(60) NULL,
            linkedin VARCHAR(300) NULL,
            phone VARCHAR(40) NULL,
            roles VARCHAR(120) NOT NULL DEFAULT '',
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email (email),
            KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS section_people (
            section_id INT NOT NULL,
            person_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            PRIMARY KEY (section_id, person_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    private static function addColumn(string $table, string $column, string $ddl): void
    {
        $has = Db::val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        if (!$has) Db::exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
    }

    public static function run(): void
    {
        foreach (self::TABLES as $sql) Db::exec($sql);
        self::addColumn('files', 'folder_id', 'INT NULL');
        self::addColumn('articles', 'folder_id', 'INT NULL');
        self::addColumn('articles', 'ai_queued', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumn('articles', 'ai_started_at', 'DATETIME NULL');
        self::addColumn('articles', 'ai_prev_status', 'VARCHAR(20) NULL');
        foreach (self::TABLES_V4 as $sql) Db::exec($sql);
        self::addColumn('reviewers', 'person_id', 'INT NULL');
        self::addColumn('reviewers', 'round', 'TINYINT NOT NULL DEFAULT 1');
        $len = Db::val("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'stored_name'");
        if ($len !== null && (int) $len < 700) Db::exec('ALTER TABLE files MODIFY stored_name VARCHAR(700) NOT NULL');

        foreach (self::SECTIONS as [$num, $name, $wp]) Db::exec('INSERT IGNORE INTO sections (num, name, wp_category) VALUES (?, ?, ?)', [$num, $name, $wp]);

        $email = strtolower(trim((string) Config::get('ADMIN_EMAIL', '')));
        $pass = (string) Config::get('ADMIN_PASSWORD', '');
        if (!Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin'") && $email !== '' && $pass !== '') {
            Db::exec("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
                [Config::get('ADMIN_NAME', 'Administrator'), $email, password_hash($pass, PASSWORD_BCRYPT)]);
        }
    }

    /** Cheap check on every request: migrations run only when the schema version changes. */
    public static function ensure(): void
    {
        Storage::ensureDirs();
        $flag = Storage::root() . '/tmp/schema-v' . SCHEMA_VERSION;
        if (is_file($flag)) return;
        self::run();
        @file_put_contents($flag, date('c'));
    }
}
