const express = require('express');
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const multer = require('multer');
const db = require('../db');
const config = require('../config');
const settings = require('../settings');
const html = require('../html');
const intake = require('../intake');
const ai = require('../ai');
const reviews = require('../reviews');
const wordpress = require('../wordpress');
const mailer = require('../mailer');
const storage = require('../storage');
const activity = require('../activity');
const folders = require('../folders');
const { requireLogin, requireAdmin } = require('../auth');

const router = express.Router();
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: config.maxUploadMb * 1024 * 1024, files: 40 },
  fileFilter: (req, file, cb) => cb(intake.isAllowed(file.originalname) ? null : new Error(`File type not allowed: ${file.originalname}. Allowed: ${intake.ALLOWED_EXT.join(' ')}`), true),
});

const wrap = (fn) => (req, res) => Promise.resolve(fn(req, res)).catch((e) => {
  console.error('[api]', req.method, req.originalUrl, e.message);
  res.status(e.status && e.status < 600 && e.status >= 400 ? e.status : 400).json({ error: e.message });
});
const uid = (req) => req.session.user?.id || null;
const STAGES = ['raw', 'reviewing', 'edited', 'in_review', 'compiled', 'published'];

// ---------------- Auth ----------------
router.post('/login', wrap(async (req, res) => {
  const { email, password } = req.body || {};
  const u = await db.one('SELECT * FROM users WHERE email = ? AND active = 1', [String(email || '').toLowerCase().trim()]);
  if (!u || !(await bcrypt.compare(String(password || ''), u.password_hash))) return res.status(401).json({ error: 'Wrong email or password' });
  req.session.user = { id: u.id, name: u.name, email: u.email, role: u.role };
  res.json({ user: req.session.user });
}));
router.post('/logout', (req, res) => req.session.destroy(() => res.json({ ok: true })));
router.get('/me', (req, res) => res.json({ user: req.session.user || null }));

router.use(requireLogin);

// ---------------- Meta ----------------
router.get('/sections', wrap(async (req, res) => res.json(await db.query('SELECT * FROM sections ORDER BY num'))));

router.get('/stats', wrap(async (req, res) => {
  const byStage = await db.query('SELECT status, COUNT(*) n FROM articles GROUP BY status');
  const editions = await db.one('SELECT COUNT(*) n FROM editions');
  const pendingReviews = await db.one("SELECT COUNT(*) n FROM reviewers WHERE status = 'sent'");
  const overdue = await db.one("SELECT COUNT(*) n FROM reviewers WHERE status = 'sent' AND due_at < NOW()");
  const recent = await db.query(`SELECT ac.*, a.title, u.name AS user_name FROM activity ac
    LEFT JOIN articles a ON a.id = ac.article_id LEFT JOIN users u ON u.id = ac.user_id ORDER BY ac.id DESC LIMIT 12`);
  res.json({ byStage: Object.fromEntries(byStage.map((r) => [r.status, r.n])), editions: editions.n, pendingReviews: pendingReviews.n, overdue: overdue.n, recent });
}));

// ---------------- Editions ----------------
router.get('/editions', wrap(async (req, res) => {
  const rows = await db.query(`SELECT e.*,
      (SELECT COUNT(*) FROM articles a WHERE a.edition_id = e.id) AS article_count,
      (SELECT COUNT(*) FROM articles a WHERE a.edition_id = e.id AND a.status = 'published') AS published_count,
      (SELECT COUNT(*) FROM files f WHERE f.edition_id = e.id AND f.folder_id IS NOT NULL AND f.article_id IS NULL) AS waiting_count,
      (SELECT COUNT(*) FROM folders d WHERE d.edition_id = e.id) AS folder_count
    FROM editions e ORDER BY e.year DESC, e.id DESC`);
  res.json(rows);
}));

// Admin: Year > Issue name, with the folder structure generated automatically.
router.post('/editions', requireAdmin, wrap(async (req, res) => {
  const year = parseInt(req.body.year, 10);
  const name = String(req.body.name || '').trim();
  if (!(year >= 2000 && year <= 2100) || !name) throw new Error('Year and issue name are required');
  const dup = await db.one('SELECT id FROM editions WHERE year = ? AND name = ?', [year, name]);
  if (dup) throw new Error(`"${name}" already exists in ${year}`);
  const r = await db.query('INSERT INTO editions (year, name, submission_token) VALUES (?, ?, ?)', [year, name, crypto.randomBytes(12).toString('hex')]);
  await activity.log({ editionId: r.insertId, userId: uid(req), action: 'edition_created', detail: `${year} / ${name}` });
  const gen = req.body.generate === false ? null : await folders.generate(r.insertId, uid(req));
  res.json({ id: r.insertId, folders: gen });
}));

// ---------------- Folders ----------------
router.post('/editions/:id/folders/generate', requireAdmin, wrap(async (req, res) => {
  res.json(await folders.generate(Number(req.params.id), uid(req)));
}));
router.get('/editions/:id/folders', wrap(async (req, res) => res.json(await folders.tree(Number(req.params.id)))));
router.post('/editions/:id/process', wrap(async (req, res) => res.json(await folders.processEdition(Number(req.params.id), uid(req)))));
router.get('/folders/:id', wrap(async (req, res) => res.json(await folders.contents(Number(req.params.id)))));
router.post('/folders/:id/files', upload.array('files', 40), wrap(async (req, res) => {
  if (!(req.files || []).length) throw new Error('Choose at least one file');
  res.json({ saved: await folders.uploadInto(Number(req.params.id), req.files, uid(req)) });
}));
router.post('/folders/:id/subfolders', wrap(async (req, res) => res.json(await folders.createSubfolder(Number(req.params.id), req.body.name, uid(req)))));
router.post('/folders/:id/process', wrap(async (req, res) => res.json(await folders.processFolder(Number(req.params.id), uid(req), { photosAsArticle: !!req.body.photosAsArticle }))));
router.get('/folders/:id/zip', wrap(async (req, res) => {
  const { buffer, name } = await folders.zipFolder(Number(req.params.id));
  res.setHeader('Content-Type', 'application/zip');
  res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(name)}`);
  res.send(buffer);
}));
router.post('/files/:id/attach', wrap(async (req, res) => res.json(await folders.attachFile(Number(req.params.id), Number(req.body.article_id), uid(req)))));
router.delete('/files/:id/waiting', requireAdmin, wrap(async (req, res) => { await folders.removeWaiting(Number(req.params.id), uid(req)); res.json({ ok: true }); }));

router.get('/editions/:id', wrap(async (req, res) => {
  const e = await db.one('SELECT * FROM editions WHERE id = ?', [req.params.id]);
  if (!e) return res.status(404).json({ error: 'Edition not found' });
  const articles = await db.query(`SELECT a.id, a.title, a.author_name, a.status, a.source, a.section_id, a.featured_file_id, a.ai_error,
      a.wp_post_id, a.updated_at, s.num AS section_num, s.name AS section_name,
      (SELECT COUNT(*) FROM files f WHERE f.article_id = a.id AND f.kind = 'image') AS image_count,
      (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id) AS reviewer_count,
      (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'approved') AS approved_count,
      (SELECT COUNT(*) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'changes') AS changes_count,
      (SELECT MIN(r.due_at) FROM reviewers r WHERE r.article_id = a.id AND r.status = 'sent') AS next_due
    FROM articles a LEFT JOIN sections s ON s.id = a.section_id WHERE a.edition_id = ? ORDER BY s.num, a.id`, [e.id]);
  const waiting = await db.one('SELECT COUNT(*) n FROM files WHERE edition_id = ? AND folder_id IS NOT NULL AND article_id IS NULL', [e.id]);
  const fc = await db.one('SELECT COUNT(*) n FROM folders WHERE edition_id = ?', [e.id]);
  res.json({ ...e, submitUrl: `${config.baseUrl}/submit/${e.submission_token}`, articles, waiting_count: waiting.n, folder_count: fc.n });
}));

router.patch('/editions/:id', wrap(async (req, res) => {
  const fields = {};
  if ('name' in req.body) fields.name = String(req.body.name).trim();
  if ('year' in req.body) fields.year = parseInt(req.body.year, 10);
  if ('submissions_open' in req.body) fields.submissions_open = req.body.submissions_open ? 1 : 0;
  if (!Object.keys(fields).length) return res.json({ ok: true });
  await db.query('UPDATE editions SET ? WHERE id = ?', [fields, req.params.id]);
  res.json({ ok: true });
}));

// ---------------- Intake ----------------
router.post('/editions/:id/upload', upload.array('files', 40), wrap(async (req, res) => {
  const edition = await db.one('SELECT id FROM editions WHERE id = ?', [req.params.id]);
  if (!edition) throw new Error('Edition not found');
  const files = req.files || [];
  if (!files.length) throw new Error('Choose at least one file');
  const sectionId = req.body.section_id || null;
  const mode = req.body.mode === 'each' ? 'each' : 'single';
  const created = [];
  if (mode === 'each') {
    // One article per document; images in the batch go to the first article.
    const docs = files.filter((f) => !/^image\//.test(intake.mimeFor(f.originalname, f.mimetype)));
    const imgs = files.filter((f) => /^image\//.test(intake.mimeFor(f.originalname, f.mimetype)));
    for (const [i, d] of docs.entries()) {
      created.push(await intake.createArticle({ editionId: edition.id, sectionId, authorName: req.body.author_name, files: i === 0 ? [d, ...imgs] : [d], userId: uid(req) }));
    }
    if (!docs.length) created.push(await intake.createArticle({ editionId: edition.id, sectionId, title: req.body.title, authorName: req.body.author_name, files: imgs, userId: uid(req) }));
  } else {
    created.push(await intake.createArticle({ editionId: edition.id, sectionId, title: req.body.title, authorName: req.body.author_name, files, userId: uid(req) }));
  }
  res.json({ created });
}));

router.post('/editions/:id/articles', wrap(async (req, res) => {
  const { title, section_id, author_name, author_email, html: body } = req.body;
  if (!title) throw new Error('Title is required');
  const out = await intake.createArticle({ editionId: req.params.id, sectionId: section_id, title, authorName: author_name, authorEmail: author_email, source: 'editor', bodyHtml: body || '', userId: uid(req) });
  res.json(out);
}));

// ---------------- Articles ----------------
router.get('/articles/:id', wrap(async (req, res) => {
  const a = await db.one(`SELECT a.*, s.name AS section_name, s.num AS section_num, e.name AS edition_name, e.year AS edition_year
    FROM articles a LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?`, [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  const files = await db.query('SELECT id, kind, original_name, mime, size, origin, sort_order, wp_media_id, created_at FROM files WHERE article_id = ? ORDER BY kind, sort_order, id', [a.id]);
  const versions = await db.query('SELECT v.id, v.label, v.title, v.created_at, u.name AS user_name FROM article_versions v LEFT JOIN users u ON u.id = v.user_id WHERE v.article_id = ? ORDER BY v.id DESC', [a.id]);
  const reviewerRows = await db.query('SELECT id, name, email, status, sent_at, due_at, reminded_at, responded_at, comments FROM reviewers WHERE article_id = ? ORDER BY id', [a.id]);
  const log = await db.query('SELECT ac.*, u.name AS user_name FROM activity ac LEFT JOIN users u ON u.id = ac.user_id WHERE ac.article_id = ? ORDER BY ac.id DESC LIMIT 50', [a.id]);
  res.json({ ...a, files, versions, reviewers: reviewerRows, activity: log });
}));

router.put('/articles/:id', wrap(async (req, res) => {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  if (a.status === 'reviewing') throw new Error('The AI is editing this article right now - try again in a moment');
  const fields = {};
  for (const k of ['title', 'subtitle', 'author_name', 'author_email', 'summary']) if (k in req.body) fields[k] = String(req.body[k] || '').slice(0, k === 'summary' ? 2000 : 290) || null;
  if (fields.title === null) delete fields.title;
  if ('section_id' in req.body) fields.section_id = req.body.section_id || null;
  if ('featured_file_id' in req.body) fields.featured_file_id = req.body.featured_file_id || null;
  if ('html' in req.body) {
    const clean = html.clean(html.normalizeImgs(html.fixMarkdown(req.body.html)));
    if (a.status === 'raw') fields.raw_html = clean; else fields.edited_html = clean;
    await db.query('INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, ?, ?, ?, ?)',
      [a.id, 'Manual edit', fields.title || a.title, clean, uid(req)]);
  }
  if (Object.keys(fields).length) await db.query('UPDATE articles SET ? WHERE id = ?', [fields, a.id]);
  await activity.log({ articleId: a.id, editionId: a.edition_id, userId: uid(req), action: 'edited', detail: Object.keys(fields).join(', ') });
  res.json({ ok: true });
}));

router.get('/articles/:id/versions/:vid', wrap(async (req, res) => {
  const v = await db.one('SELECT * FROM article_versions WHERE id = ? AND article_id = ?', [req.params.vid, req.params.id]);
  if (!v) return res.status(404).json({ error: 'Version not found' });
  res.json(v);
}));

router.post('/articles/:id/files', upload.array('files', 40), wrap(async (req, res) => {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  const saved = [];
  for (const f of req.files || []) {
    const mime = intake.mimeFor(f.originalname, f.mimetype);
    const kind = /^image\/(jpeg|png|gif|webp)$/.test(mime) ? 'image' : 'attachment';
    const s = await storage.saveBuffer(f.buffer, { articleId: a.id, editionId: a.edition_id, kind, mime, originalName: f.originalname, origin: 'upload', sortOrder: 200 });
    saved.push({ id: s.id, kind, name: f.originalname });
  }
  if (!a.featured_file_id) {
    const firstImg = saved.find((s) => s.kind === 'image');
    if (firstImg) await db.query('UPDATE articles SET featured_file_id = ? WHERE id = ?', [firstImg.id, a.id]);
  }
  await activity.log({ articleId: a.id, editionId: a.edition_id, userId: uid(req), action: 'files_added', detail: saved.map((s) => s.name).join(', ') });
  res.json({ saved });
}));

router.post('/articles/:id/ai-review', wrap(async (req, res) => {
  res.json(await ai.reviewArticle(Number(req.params.id), uid(req)));
}));

// Review every Raw article in an edition, one after another, in the background.
const running = new Set();
router.post('/editions/:id/ai-review-all', wrap(async (req, res) => {
  const editionId = Number(req.params.id);
  if (running.has(editionId)) throw new Error('A batch review is already running for this edition');
  const raws = await db.query("SELECT id FROM articles WHERE edition_id = ? AND status = 'raw' ORDER BY id", [editionId]);
  running.add(editionId);
  (async () => {
    for (const r of raws) {
      try { await ai.reviewArticle(r.id, uid(req)); } catch (e) { console.error('[ai batch]', r.id, e.message); }
    }
  })().finally(() => running.delete(editionId));
  res.json({ queued: raws.length });
}));

const MOVES = {
  compiled: ['edited', 'in_review'],
  edited: ['in_review', 'compiled'],
  raw: ['edited'],
};
router.post('/articles/:id/stage', wrap(async (req, res) => {
  const to = req.body.to;
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  if (!MOVES[to] || !MOVES[to].includes(a.status)) throw new Error(`Cannot move from ${a.status} to ${to}`);
  if (to === 'raw' && !a.raw_html) throw new Error('No original text to go back to');
  await db.query('UPDATE articles SET status = ? WHERE id = ?', [to, a.id]);
  folders.afterStageChange(a.id);
  await activity.log({ articleId: a.id, editionId: a.edition_id, userId: uid(req), action: 'stage', detail: `${a.status} -> ${to}` });
  res.json({ ok: true });
}));

router.post('/editions/:id/compile', wrap(async (req, res) => {
  const onlyApproved = req.body.onlyApproved !== false;
  const rows = await db.query(`SELECT a.id FROM articles a WHERE a.edition_id = ? AND a.status = 'in_review' ${onlyApproved
    ? "AND NOT EXISTS (SELECT 1 FROM reviewers r WHERE r.article_id = a.id AND r.status IN ('sent','changes','pending'))" : ''}`, [req.params.id]);
  for (const r of rows) {
    await db.query("UPDATE articles SET status = 'compiled' WHERE id = ?", [r.id]);
    folders.afterStageChange(r.id);
    await activity.log({ articleId: r.id, editionId: Number(req.params.id), userId: uid(req), action: 'stage', detail: 'in_review -> compiled' });
  }
  res.json({ moved: rows.length });
}));

router.post('/articles/:id/publish', requireAdmin, wrap(async (req, res) => {
  res.json(await wordpress.publishDraft(Number(req.params.id), uid(req)));
}));

router.delete('/articles/:id', requireAdmin, wrap(async (req, res) => {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  // The article record goes; uploaded files stay on disk (files are never deleted automatically).
  await db.query('DELETE FROM reviewers WHERE article_id = ?', [a.id]);
  await db.query('UPDATE files SET article_id = NULL WHERE article_id = ?', [a.id]);
  await db.query('DELETE FROM articles WHERE id = ?', [a.id]);
  await activity.log({ editionId: a.edition_id, userId: uid(req), action: 'article_deleted', detail: a.title });
  res.json({ ok: true });
}));

// ---------------- Reviewers ----------------
router.get('/editions/:id/reviewers', wrap(async (req, res) => {
  res.json(await db.query(`SELECT r.id, r.name, r.email, r.status, r.sent_at, r.due_at, r.reminded_at, r.responded_at, r.comments, r.article_id, a.title AS article_title
    FROM reviewers r LEFT JOIN articles a ON a.id = r.article_id WHERE r.edition_id = ? ORDER BY a.title, r.name`, [req.params.id]));
}));

router.post('/articles/:id/reviewers', wrap(async (req, res) => {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [req.params.id]);
  if (!a) return res.status(404).json({ error: 'Article not found' });
  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim();
  if (!name || !/^\S+@\S+\.\S+$/.test(email)) throw new Error('Reviewer name and a valid email are required');
  const id = await reviews.addReviewer({ editionId: a.edition_id, articleId: a.id, name, email });
  res.json({ id });
}));

// Bulk add: one line per reviewer "Name, email, Article title"
router.post('/editions/:id/reviewers/import', wrap(async (req, res) => {
  const lines = String(req.body.text || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const arts = await db.query('SELECT id, title FROM articles WHERE edition_id = ?', [req.params.id]);
  const out = { added: 0, skipped: [] };
  for (const line of lines) {
    const [name, email, ...rest] = line.split(/\t|,/).map((s) => s.trim());
    const title = rest.join(',').trim().toLowerCase();
    const art = arts.find((a) => a.title.toLowerCase() === title) || arts.find((a) => title && a.title.toLowerCase().includes(title));
    if (!name || !/^\S+@\S+\.\S+$/.test(email || '') || !art) { out.skipped.push(line); continue; }
    await reviews.addReviewer({ editionId: Number(req.params.id), articleId: art.id, name, email });
    out.added++;
  }
  res.json(out);
}));

router.delete('/reviewers/:id', wrap(async (req, res) => {
  await db.query('DELETE FROM reviewers WHERE id = ?', [req.params.id]);
  res.json({ ok: true });
}));

router.post('/articles/:id/send-review', wrap(async (req, res) => {
  res.json(await reviews.sendForArticle(Number(req.params.id), uid(req)));
}));

router.post('/editions/:id/send-reviews', wrap(async (req, res) => {
  const arts = await db.query(`SELECT a.id, a.title FROM articles a WHERE a.edition_id = ? AND a.status = 'edited'
    AND EXISTS (SELECT 1 FROM reviewers r WHERE r.article_id = a.id AND r.status = 'pending')`, [req.params.id]);
  const out = { articles: 0, sent: 0, failed: [] };
  for (const a of arts) {
    try {
      const r = await reviews.sendForArticle(a.id, uid(req));
      out.articles++; out.sent += r.sent.length; out.failed.push(...r.failed.map((f) => ({ ...f, article: a.title })));
    } catch (e) { out.failed.push({ article: a.title, error: e.message }); }
  }
  res.json(out);
}));

// ---------------- Settings / users (admin) ----------------
router.get('/settings', requireAdmin, wrap(async (req, res) => {
  res.json({ ...(await settings.publicView()), default_prompt: ai.DEFAULT_PROMPT,
    env: { gemini: !!config.gemini.apiKey, smtp: !!(config.smtp.user && config.smtp.pass), storageDir: config.storageDir } });
}));
router.put('/settings', requireAdmin, wrap(async (req, res) => {
  const body = { ...req.body };
  if (body.wp_app_password === '••••••••') delete body.wp_app_password;
  if (body.folder_template !== undefined) folders.parseTemplate(body.folder_template); // validates
  if (body.review_days !== undefined) {
    const d = parseInt(body.review_days, 10);
    if (!(d >= 1 && d <= 30)) throw new Error('Review days must be between 1 and 30');
    body.review_days = d;
  }
  await settings.set(body);
  res.json({ ok: true });
}));
router.post('/settings/test-wp', requireAdmin, wrap(async (req, res) => res.json(await wordpress.testConnection())));
router.post('/settings/test-ai', requireAdmin, wrap(async (req, res) => res.json({ ok: await ai.testConnection() })));
router.post('/settings/test-email', requireAdmin, wrap(async (req, res) => {
  const to = req.body.to || req.session.user.email;
  const info = await mailer.sendReviewMail({ to, subject: 'Test from The Missing Link dashboard', html: '<p>Email is working. The review CC address is included on this message.</p>', text: 'Email is working.' });
  res.json({ ok: true, cc: info.cc });
}));

router.get('/users', requireAdmin, wrap(async (req, res) => res.json(await db.query('SELECT id, name, email, role, active, created_at FROM users ORDER BY name'))));
router.post('/users', requireAdmin, wrap(async (req, res) => {
  const { name, email, password, role } = req.body;
  if (!name || !email || !password || String(password).length < 8) throw new Error('Name, email and a password of at least 8 characters are required');
  const hash = await bcrypt.hash(password, 10);
  const r = await db.query('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [name, email.toLowerCase().trim(), hash, role === 'admin' ? 'admin' : 'editor']);
  res.json({ id: r.insertId });
}));
router.put('/users/:id', requireAdmin, wrap(async (req, res) => {
  const fields = {};
  if (req.body.name) fields.name = req.body.name;
  if (req.body.role) fields.role = req.body.role === 'admin' ? 'admin' : 'editor';
  if ('active' in req.body) fields.active = req.body.active ? 1 : 0;
  if (req.body.password) {
    if (String(req.body.password).length < 8) throw new Error('Password must be at least 8 characters');
    fields.password_hash = await bcrypt.hash(req.body.password, 10);
  }
  if (Number(req.params.id) === uid(req) && (fields.role === 'editor' || fields.active === 0)) throw new Error('You cannot demote or disable yourself');
  if (Object.keys(fields).length) await db.query('UPDATE users SET ? WHERE id = ?', [fields, req.params.id]);
  res.json({ ok: true });
}));

module.exports = { router, STAGES, upload };
