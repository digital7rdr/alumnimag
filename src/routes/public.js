const express = require('express');
const fs = require('fs');
const db = require('../db');
const storage = require('../storage');
const intake = require('../intake');
const reviews = require('../reviews');
const { escapeHtml: esc } = require('../html');
const { upload } = require('./api');

const router = express.Router();

function page(title, body) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)} · The Missing Link</title>
<link rel="stylesheet" href="/public.css"></head><body><main class="pub">
<header class="pub-head"><div class="pub-brand">The Missing Link</div><div class="pub-sub">Alumni magazine</div></header>
${body}</main></body></html>`;
}

function sendFile(res, f) {
  const p = storage.filePath(f.stored_name);
  if (!fs.existsSync(p)) return res.status(404).send('File missing');
  res.setHeader('Content-Type', f.mime);
  res.setHeader('Cache-Control', 'private, max-age=3600');
  res.setHeader('Content-Disposition', `${/^image\//.test(f.mime) ? 'inline' : 'attachment'}; filename="${encodeURIComponent(f.original_name)}"`);
  fs.createReadStream(p).pipe(res);
}

// Signed-in users only
router.get('/files/:id', async (req, res) => {
  if (!req.session.user) return res.status(401).send('Sign in required');
  const f = await db.one('SELECT * FROM files WHERE id = ?', [req.params.id]);
  if (!f) return res.status(404).send('Not found');
  sendFile(res, f);
});

// ---------------- Contributor submission ----------------
router.get('/submit/:token', async (req, res) => {
  const e = await db.one('SELECT * FROM editions WHERE submission_token = ?', [req.params.token]);
  if (!e) return res.status(404).send(page('Not found', '<p>This submission link is not valid.</p>'));
  if (!e.submissions_open) return res.send(page('Submissions closed', `<h1>Submissions closed</h1><p>Submissions for <strong>${esc(e.name)}</strong> are closed. Please contact the editorial team.</p>`));
  const sections = await db.query('SELECT * FROM sections ORDER BY num');
  res.send(page(`Submit to ${e.name}`, `
  <h1>Submit an article</h1>
  <p class="muted">Edition: <strong>${esc(e.name)}</strong>. Upload your article (Word .docx preferred, or PDF) and any photos (JPG/PNG). All photos are kept exactly as you send them.</p>
  <form method="post" enctype="multipart/form-data" class="pub-form">
    <label>Your name<input name="author_name" required maxlength="190"></label>
    <label>Your email<input name="author_email" type="email" required maxlength="190"></label>
    <label>Section<select name="section_id" required><option value="">Choose a section</option>
      ${sections.map((s) => `<option value="${s.id}">${s.num}. ${esc(s.name)}</option>`).join('')}</select></label>
    <label>Article title<input name="title" maxlength="290" placeholder="Optional if it is in your document"></label>
    <label>Files<input name="files" type="file" multiple accept="${intake.ALLOWED_EXT.join(',')}"></label>
    <label>Or paste your article<textarea name="text" rows="10" placeholder="Optional"></textarea></label>
    <input name="website" class="hp" tabindex="-1" autocomplete="off">
    <button type="submit">Submit article</button>
  </form>`));
});

const recent = new Map();
router.post('/submit/:token', upload.array('files', 25), async (req, res) => {
  try {
    const e = await db.one('SELECT * FROM editions WHERE submission_token = ? AND submissions_open = 1', [req.params.token]);
    if (!e) return res.status(404).send(page('Not found', '<p>This submission link is not valid or is closed.</p>'));
    if (req.body.website) return res.send(page('Thank you', '<h1>Thank you</h1>'));
    const ip = req.ip;
    const now = Date.now();
    const hits = (recent.get(ip) || []).filter((t) => now - t < 3600e3);
    if (hits.length >= 10) return res.status(429).send(page('Slow down', '<p>Too many submissions from this connection. Please try again later.</p>'));
    recent.set(ip, [...hits, now]);

    const files = req.files || [];
    const text = String(req.body.text || '').trim();
    if (!files.length && !text) throw new Error('Please upload a file or paste your article.');
    const bodyHtml = text ? require('../html').textToHtml(text) : '';
    const out = await intake.createArticle({
      editionId: e.id, sectionId: req.body.section_id || null, title: String(req.body.title || '').trim() || undefined,
      authorName: String(req.body.author_name || '').trim(), authorEmail: String(req.body.author_email || '').trim(),
      source: 'contributor', bodyHtml, files,
    });
    res.send(page('Thank you', `<h1>Thank you!</h1><p>Your article has been received by the editorial team (reference #${out.articleId}).</p>`));
  } catch (err) {
    res.status(400).send(page('Problem', `<h1>Something went wrong</h1><p>${esc(err.message)}</p><p><a href="">Go back</a></p>`));
  }
});

// ---------------- Reviewer page ----------------
async function reviewerByToken(token) {
  return db.one(`SELECT r.*, a.title, a.subtitle, a.author_name, a.edited_html, a.raw_html, a.status AS article_status, e.name AS edition_name, s.name AS section_name
    FROM reviewers r JOIN articles a ON a.id = r.article_id JOIN editions e ON e.id = r.edition_id LEFT JOIN sections s ON s.id = a.section_id WHERE r.token = ?`, [token]);
}

router.get('/r/:token', async (req, res) => {
  const r = await reviewerByToken(req.params.token);
  if (!r) return res.status(404).send(page('Not found', '<p>This review link is not valid.</p>'));
  const body = (r.edited_html || r.raw_html || '').replace(/src="\/files\/(\d+)"/g, `src="/r/${encodeURIComponent(req.params.token)}/files/$1"`);
  const done = ['approved', 'changes'].includes(r.status);
  res.send(page(`Review: ${r.title}`, `
  <p class="muted">${esc(r.edition_name)} · ${esc(r.section_name || '')}${r.due_at ? ` · please respond by <strong>${esc(reviews.fmtDate(r.due_at))}</strong>` : ''}</p>
  <article class="pub-article"><h1>${esc(r.title)}</h1>${r.subtitle ? `<p class="subtitle">${esc(r.subtitle)}</p>` : ''}
  ${r.author_name ? `<p class="byline">By ${esc(r.author_name)}</p>` : ''}${body}</article>
  ${done ? `<div class="notice">You already responded (${r.status === 'approved' ? 'approved' : 'changes requested'}). You can update your response below.</div>` : ''}
  <form method="post" class="pub-form">
    <label>Comments for the editor<textarea name="comments" rows="6">${esc(r.comments || '')}</textarea></label>
    <div class="row"><button name="decision" value="approve" type="submit">Approve</button>
    <button name="decision" value="changes" type="submit" class="secondary">Request changes</button></div>
  </form>`));
});

router.get('/r/:token/files/:id', async (req, res) => {
  const r = await db.one('SELECT article_id FROM reviewers WHERE token = ?', [req.params.token]);
  if (!r) return res.status(404).send('Not found');
  const f = await db.one("SELECT * FROM files WHERE id = ? AND article_id = ? AND kind = 'image'", [req.params.id, r.article_id]);
  if (!f) return res.status(404).send('Not found');
  sendFile(res, f);
});

router.post('/r/:token', express.urlencoded({ extended: false }), async (req, res) => {
  try {
    const out = await reviews.respond(req.params.token, { decision: req.body.decision, comments: req.body.comments });
    res.send(page('Thank you', `<h1>Thank you</h1><p>Your response (${out.status === 'approved' ? 'approved' : 'changes requested'}) has been sent to the editorial team.</p><p><a href="/r/${esc(req.params.token)}">Back to the article</a></p>`));
  } catch (e) {
    res.status(400).send(page('Problem', `<p>${esc(e.message)}</p>`));
  }
});

module.exports = router;
