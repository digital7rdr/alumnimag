const db = require('./db');
const config = require('./config');

// Settings stored in DB override .env defaults, so admins can change them from the dashboard.
const DEFAULTS = {
  review_days: String(config.defaults.reviewDays),
  review_cc: config.defaults.reviewCc,
  gemini_model: config.gemini.model,
  ai_prompt: '',
  wp_url: config.wordpress.url,
  wp_user: config.wordpress.user,
  wp_app_password: config.wordpress.appPassword,
  wp_layout: 'et_full_width_page',
  crop_featured: '1',
  email_from_name: 'The Missing Link Editorial',
  // One folder per line: "Folder name = stage". Lines without "= stage" are plain folders.
  folder_template: [
    '01. Raw = raw',
    '02. AI Edited = edited',
    '03. With Reviewers = in_review',
    '04. Compiled = compiled',
    '05. Final = published',
  ].join('\n'),
  folder_sections: '1',
};

const SECRET_KEYS = ['wp_app_password'];

let cache = null;

async function all() {
  if (cache) return cache;
  const rows = await db.query('SELECT k, v FROM settings');
  const out = { ...DEFAULTS };
  for (const r of rows) if (r.v !== null && r.v !== '') out[r.k] = r.v;
  cache = out;
  return out;
}

async function get(k) {
  return (await all())[k];
}

async function set(values) {
  for (const [k, v] of Object.entries(values)) {
    if (!(k in DEFAULTS)) continue;
    await db.query('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [k, v == null ? '' : String(v)]);
  }
  cache = null;
}

async function publicView() {
  const s = { ...(await all()) };
  for (const k of SECRET_KEYS) s[k] = s[k] ? '••••••••' : '';
  return s;
}

module.exports = { all, get, set, publicView, DEFAULTS, SECRET_KEYS };
