const path = require('path');
require('dotenv').config({ path: process.env.DOTENV_PATH || path.join(__dirname, '..', '.env') });

const env = (k, d) => (process.env[k] !== undefined && process.env[k] !== '' ? process.env[k] : d);

module.exports = {
  port: parseInt(env('PORT', '3000'), 10),
  baseUrl: env('BASE_URL', 'https://drive.readthemissinglink.net').replace(/\/$/, ''),
  nodeEnv: env('NODE_ENV', 'production'),
  sessionSecret: env('SESSION_SECRET', 'change-me-in-env'),

  db: {
    host: env('DB_HOST', 'localhost'),
    port: parseInt(env('DB_PORT', '3306'), 10),
    database: env('DB_NAME', ''),
    user: env('DB_USER', ''),
    password: env('DB_PASSWORD', ''),
  },

  // Keep uploads OUTSIDE the app folder so a redeploy from Git never wipes them.
  storageDir: path.resolve(env('STORAGE_DIR', path.join(__dirname, '..', '..', 'alumnimag-storage'))),
  maxUploadMb: parseInt(env('MAX_UPLOAD_MB', '40'), 10),

  admin: {
    email: env('ADMIN_EMAIL', ''),
    password: env('ADMIN_PASSWORD', ''),
    name: env('ADMIN_NAME', 'Administrator'),
  },

  gemini: {
    apiKey: env('GEMINI_API_KEY', ''),
    model: env('GEMINI_MODEL', 'gemini-2.5-flash'),
    baseUrl: env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com').replace(/\/$/, ''),
  },

  smtp: {
    host: env('SMTP_HOST', 'smtp.gmail.com'),
    port: parseInt(env('SMTP_PORT', '465'), 10),
    secure: env('SMTP_SECURE', 'true') === 'true',
    user: env('SMTP_USER', ''),
    pass: env('SMTP_PASS', ''),
    from: env('SMTP_FROM', ''),
    // 'json' only logs messages instead of sending (for local testing)
    transport: env('MAIL_TRANSPORT', 'smtp'),
  },

  wordpress: {
    url: env('WP_URL', 'https://readthemissinglink.net').replace(/\/$/, ''),
    user: env('WP_USER', ''),
    appPassword: env('WP_APP_PASSWORD', ''),
  },

  // Business rules (also editable in Settings; these are the defaults)
  defaults: {
    reviewDays: parseInt(env('REVIEW_DAYS', '2'), 10),
    reviewCc: env('REVIEW_CC', 'alumnikarunya@gmail.com'),
  },
};
