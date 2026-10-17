const bcrypt = require('bcryptjs');
const db = require('./db');
const config = require('./config');

const SECTIONS = [
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

const TABLES = [
  `CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(100) NOT NULL,
    role ENUM('admin','editor') NOT NULL DEFAULT 'editor',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    num INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    wp_category VARCHAR(190) NOT NULL,
    UNIQUE KEY uq_num (num)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS editions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    submission_token VARCHAR(64) NOT NULL UNIQUE,
    submissions_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS articles (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS article_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    label VARCHAR(60) NOT NULL,
    title VARCHAR(300) NULL,
    html MEDIUMTEXT NOT NULL,
    user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS files (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS reviewers (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS folders (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(80) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NULL,
    edition_id INT NULL,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
];

// Adds a column only when it is missing (works on MySQL and MariaDB).
async function addColumn(table, column, ddl) {
  const rows = await db.query(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [table, column]);
  if (!rows.length) await db.query(`ALTER TABLE ${table} ADD COLUMN ${column} ${ddl}`);
}

async function migrate() {
  for (const sql of TABLES) await db.query(sql);
  await addColumn('files', 'folder_id', 'INT NULL');
  await addColumn('articles', 'folder_id', 'INT NULL');
  const [col] = await db.query(
    "SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'stored_name'");
  if (col && col.len < 700) await db.query('ALTER TABLE files MODIFY stored_name VARCHAR(700) NOT NULL');

  for (const [num, name, wp] of SECTIONS) {
    await db.query(
      'INSERT IGNORE INTO sections (num, name, wp_category) VALUES (?, ?, ?)',
      [num, name, wp]
    );
  }

  const admins = await db.query("SELECT id FROM users WHERE role='admin' LIMIT 1");
  if (!admins.length && config.admin.email && config.admin.password) {
    const hash = await bcrypt.hash(config.admin.password, 10);
    await db.query(
      "INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
      [config.admin.name, config.admin.email.toLowerCase(), hash]
    );
    console.log(`[migrate] Created admin user ${config.admin.email}`);
  }
}

module.exports = { migrate, SECTIONS };
