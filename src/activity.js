const db = require('./db');

async function log({ articleId = null, editionId = null, userId = null, action, detail = '' }) {
  try {
    await db.query(
      'INSERT INTO activity (article_id, edition_id, user_id, action, detail) VALUES (?, ?, ?, ?, ?)',
      [articleId, editionId, userId, action, typeof detail === 'string' ? detail : JSON.stringify(detail)]
    );
  } catch (e) {
    console.error('[activity] failed to log', e.message);
  }
}

module.exports = { log };
