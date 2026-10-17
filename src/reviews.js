const crypto = require('crypto');
const db = require('./db');
const config = require('./config');
const settings = require('./settings');
const mailer = require('./mailer');
const activity = require('./activity');
const { escapeHtml } = require('./html');

const token = () => crypto.randomBytes(24).toString('hex');

function fmtDate(d) {
  return new Date(d).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}

function emailLayout(inner) {
  return `<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1f2937">
  <div style="background:#1a4a4a;color:#fff;padding:18px 24px;border-radius:8px 8px 0 0;font-size:18px">The Missing Link &middot; Editorial review</div>
  <div style="border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 8px 8px;line-height:1.6">${inner}</div></div>`;
}

async function addReviewer({ editionId, articleId, name, email }) {
  const res = await db.query('INSERT INTO reviewers (edition_id, article_id, name, email, token) VALUES (?, ?, ?, ?, ?)',
    [editionId, articleId || null, name.trim(), email.trim().toLowerCase(), token()]);
  return res.insertId;
}

async function sendOne(reviewer, art, { reminder = false } = {}) {
  const link = `${config.baseUrl}/r/${reviewer.token}`;
  const due = fmtDate(reviewer.due_at);
  const subject = reminder
    ? `Reminder: review due - "${art.title}"`
    : `Please review: "${art.title}" (due ${due})`;
  const inner = `
    <p>Dear ${escapeHtml(reviewer.name)},</p>
    <p>${reminder ? 'This is a friendly reminder that the article below is waiting for your review.' : 'You have been asked to review the following article for the upcoming edition.'}</p>
    <p style="background:#f3f4f6;padding:12px 16px;border-radius:6px"><strong>${escapeHtml(art.title)}</strong><br>
      ${escapeHtml(art.section_name || '')}${art.author_name ? ' &middot; by ' + escapeHtml(art.author_name) : ''}<br>
      Edition: ${escapeHtml(art.edition_name || '')}</p>
    <p><strong>Please respond by ${due}.</strong></p>
    <p><a href="${link}" style="display:inline-block;background:#1a4a4a;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none">Open the article</a></p>
    <p style="font-size:13px;color:#6b7280">On that page you can approve the article or send comments. Link: ${link}</p>`;
  const text = `Dear ${reviewer.name},\n\nPlease review "${art.title}" by ${due}.\nOpen: ${link}\n`;
  return mailer.sendReviewMail({ to: reviewer.email, subject, html: emailLayout(inner), text });
}

async function articleForMail(articleId) {
  return db.one(`SELECT a.*, s.name AS section_name, e.name AS edition_name
    FROM articles a LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?`, [articleId]);
}

// Send review requests for one article. Due date = now + review_days (default 2 days).
async function sendForArticle(articleId, userId = null) {
  const art = await articleForMail(articleId);
  if (!art) throw new Error('Article not found');
  if (!['edited', 'in_review'].includes(art.status)) throw new Error('Only AI-edited articles can be sent to reviewers');
  const reviewers = await db.query("SELECT * FROM reviewers WHERE article_id = ? AND status IN ('pending','sent')", [articleId]);
  if (!reviewers.length) throw new Error('Assign at least one reviewer to this article first');

  const days = parseInt(await settings.get('review_days'), 10) || 2;
  // Due date is computed by MySQL so it always matches the NOW() used by the reminder check.
  const { due } = await db.one('SELECT DATE_ADD(NOW(), INTERVAL ? DAY) AS due', [days]);
  const results = { sent: [], failed: [] };

  for (const r of reviewers) {
    try {
      await db.query("UPDATE reviewers SET status = 'sent', sent_at = NOW(), due_at = DATE_ADD(NOW(), INTERVAL ? DAY), reminded_at = NULL WHERE id = ?", [days, r.id]);
      const info = await sendOne({ ...r, due_at: due }, art);
      results.sent.push({ name: r.name, email: r.email, cc: info.cc });
    } catch (e) {
      await db.query("UPDATE reviewers SET status = 'pending' WHERE id = ?", [r.id]);
      results.failed.push({ name: r.name, email: r.email, error: e.message });
    }
  }
  if (results.sent.length) {
    await db.query("UPDATE articles SET status = 'in_review' WHERE id = ?", [articleId]);
    require('./folders').afterStageChange(articleId);
  }
  await activity.log({ articleId, editionId: art.edition_id, userId, action: 'sent_to_reviewers', detail: `${results.sent.length} sent, ${results.failed.length} failed, due ${fmtDate(due)}` });
  return { ...results, dueAt: due, days };
}

// Called every 30 min: reviewers past their due date get one reminder (CC editorial).
async function sendDueReminders() {
  const due = await db.query(`SELECT * FROM reviewers WHERE status = 'sent' AND due_at IS NOT NULL AND due_at <= NOW() AND reminded_at IS NULL`);
  let count = 0;
  for (const r of due) {
    try {
      const art = await articleForMail(r.article_id);
      if (!art || art.status !== 'in_review') continue;
      await sendOne(r, art, { reminder: true });
      await db.query('UPDATE reviewers SET reminded_at = NOW() WHERE id = ?', [r.id]);
      await activity.log({ articleId: r.article_id, editionId: r.edition_id, action: 'reminder_sent', detail: r.email });
      count++;
    } catch (e) {
      console.error('[reviews] reminder failed', r.email, e.message);
    }
  }
  return count;
}

async function respond(tokenValue, { decision, comments }) {
  const r = await db.one('SELECT * FROM reviewers WHERE token = ?', [tokenValue]);
  if (!r) throw new Error('Review link not found');
  const status = decision === 'approve' ? 'approved' : 'changes';
  await db.query('UPDATE reviewers SET status = ?, comments = ?, responded_at = NOW() WHERE id = ?', [status, (comments || '').slice(0, 5000), r.id]);
  const art = await articleForMail(r.article_id);
  await activity.log({ articleId: r.article_id, editionId: r.edition_id, action: `review_${status}`, detail: `${r.name}: ${comments || ''}` });

  // Let the editorial inbox know.
  try {
    const cc = mailer.splitList(await settings.get('review_cc'));
    if (cc.length && art) {
      await mailer.sendMail({
        to: cc.join(','),
        subject: `${status === 'approved' ? 'Approved' : 'Changes requested'}: "${art.title}" by ${r.name}`,
        html: emailLayout(`<p><strong>${escapeHtml(r.name)}</strong> ${status === 'approved' ? 'approved' : 'requested changes to'} <strong>${escapeHtml(art.title)}</strong>.</p>${comments ? `<blockquote style="border-left:3px solid #1a4a4a;padding-left:12px">${escapeHtml(comments).replace(/\n/g, '<br>')}</blockquote>` : ''}<p><a href="${config.baseUrl}/#/article/${art.id}">Open in dashboard</a></p>`),
      });
    }
  } catch (e) {
    console.error('[reviews] notify failed', e.message);
  }
  return { status };
}

let timer = null;
function startScheduler() {
  if (timer) return;
  const run = () => sendDueReminders().then((n) => n && console.log(`[reviews] sent ${n} reminder(s)`)).catch((e) => console.error('[reviews]', e.message));
  timer = setInterval(run, 30 * 60 * 1000);
  setTimeout(run, 60 * 1000);
}

module.exports = { addReviewer, sendForArticle, sendDueReminders, respond, startScheduler, fmtDate };
