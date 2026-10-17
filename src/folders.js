// Year > Issue > stage folders > section folders.
//
// The folder tree is created on disk under STORAGE_DIR/library/<year>/<issue>/ so it can also
// be browsed in the hosting file manager. Files are gathered into the Raw folders, then
// "processed" into articles. Later stage folders show the articles that are at that stage,
// and every time an article reaches a stage a copy (article.html + photos + source files)
// is written into that stage's folder. Nothing is ever moved or deleted, so photos can
// never go missing between Raw, Compiled and Final.
const fs = require('fs');
const path = require('path');
const JSZip = require('jszip');
const db = require('./db');
const storage = require('./storage');
const settings = require('./settings');
const html = require('./html');
const intake = require('./intake');
const activity = require('./activity');

const STAGE_KEYS = ['raw', 'edited', 'in_review', 'compiled', 'published'];
const STAGE_STATUSES = { raw: ['raw', 'reviewing'], edited: ['edited'], in_review: ['in_review'], compiled: ['compiled'], published: ['published'] };
const STAGE_ALIASES = { raw: 'raw', edited: 'edited', 'ai edited': 'edited', ai: 'edited', in_review: 'in_review', review: 'in_review', reviewers: 'in_review', compiled: 'compiled', published: 'published', final: 'published', wordpress: 'published' };

function parseTemplate(text) {
  const out = [];
  for (const raw of String(text || '').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const [namePart, stagePart] = line.split('=').map((x) => (x || '').trim());
    const name = storage.fsName(namePart, '');
    if (!name) continue;
    const stage = stagePart ? STAGE_ALIASES[stagePart.toLowerCase()] : null;
    if (stagePart && !stage) throw new Error(`Unknown stage "${stagePart}" in folder template. Use one of: ${STAGE_KEYS.join(', ')}`);
    out.push({ name, stage });
  }
  if (!out.length) throw new Error('The folder template is empty');
  return out;
}

const sectionFolderName = (s) => storage.fsName(`Section ${s.num} - ${s.name}`);

async function findOrCreate({ editionId, parentId, name, kind, stage = null, sectionId = null, sort = 0, relPath }) {
  let f = await db.one('SELECT * FROM folders WHERE edition_id = ? AND parent_id <=> ? AND name = ?', [editionId, parentId, name]);
  let created = false;
  if (!f) {
    const r = await db.query(
      'INSERT INTO folders (edition_id, parent_id, name, kind, stage, section_id, sort, path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [editionId, parentId, name, kind, stage, sectionId, sort, relPath]);
    f = await db.one('SELECT * FROM folders WHERE id = ?', [r.insertId]);
    created = true;
  } else if (f.stage !== stage || f.sort !== sort || f.kind !== kind) {
    await db.query('UPDATE folders SET stage = ?, sort = ?, kind = ? WHERE id = ?', [stage, sort, kind, f.id]);
  }
  fs.mkdirSync(storage.absDir(f.path), { recursive: true });
  return { folder: f, created };
}

// Creates any folders from the template that are missing. Safe to run again at any time.
async function generate(editionId, userId = null) {
  const e = await db.one('SELECT * FROM editions WHERE id = ?', [editionId]);
  if (!e) throw new Error('Edition not found');
  const s = await settings.all();
  const template = parseTemplate(s.folder_template);
  const sections = s.folder_sections === '1' ? await db.query('SELECT * FROM sections ORDER BY num') : [];
  const root = path.posix.join('library', String(e.year), storage.fsName(e.name, `Issue ${e.id}`));
  let created = 0;
  for (const [i, t] of template.entries()) {
    const top = await findOrCreate({ editionId: e.id, parentId: null, name: t.name, kind: t.stage ? 'stage' : 'custom', stage: t.stage, sort: i, relPath: path.posix.join(root, t.name) });
    created += top.created ? 1 : 0;
    for (const sec of sections) {
      const name = sectionFolderName(sec);
      const sub = await findOrCreate({ editionId: e.id, parentId: top.folder.id, name, kind: 'section', stage: t.stage, sectionId: sec.id, sort: sec.num, relPath: path.posix.join(top.folder.path, name) });
      created += sub.created ? 1 : 0;
    }
  }
  if (created) await activity.log({ editionId: e.id, userId, action: 'folders_generated', detail: `${created} folder(s) created` });
  return { created, root };
}

async function getFolder(id) {
  const f = await db.one('SELECT * FROM folders WHERE id = ?', [id]);
  if (!f) { const err = new Error('Folder not found'); err.status = 404; throw err; }
  return f;
}

function articleWhere(f) {
  // Which articles a folder shows. Stage folders are "live views" of the articles at that stage.
  if (f.kind === 'article') return { sql: 'a.folder_id = ?', args: [f.id] };
  if (!f.stage) return null;
  const st = STAGE_STATUSES[f.stage];
  const ph = st.map(() => '?').join(',');
  // In Raw, articles made from an article folder are listed inside that folder instead.
  const own = f.stage === 'raw' ? ' AND a.folder_id IS NULL' : '';
  if (f.kind === 'section') return { sql: `a.status IN (${ph}) AND a.section_id = ?${own}`, args: [...st, f.section_id] };
  // Stage folder itself: articles with no section (so nothing is ever hidden).
  return { sql: `a.status IN (${ph}) AND a.section_id IS NULL${own}`, args: st };
}

async function tree(editionId) {
  const folders = await db.query('SELECT * FROM folders WHERE edition_id = ? ORDER BY parent_id IS NOT NULL, sort, name', [editionId]);
  const gathered = await db.query('SELECT folder_id, COUNT(*) n FROM files WHERE edition_id = ? AND folder_id IS NOT NULL AND article_id IS NULL GROUP BY folder_id', [editionId]);
  const arts = await db.query('SELECT id, status, section_id, folder_id FROM articles WHERE edition_id = ?', [editionId]);
  const g = Object.fromEntries(gathered.map((r) => [r.folder_id, r.n]));
  const byId = Object.fromEntries(folders.map((f) => [f.id, { ...f, waiting: g[f.id] || 0, articles: 0, children: [] }]));
  for (const f of Object.values(byId)) {
    if (f.kind === 'article') f.articles = arts.filter((a) => a.folder_id === f.id).length;
    else if (f.stage) {
      const st = STAGE_STATUSES[f.stage];
      f.articles = arts.filter((a) => st.includes(a.status) && (f.stage !== 'raw' || a.folder_id == null)
        && (f.kind === 'section' ? a.section_id === f.section_id : a.section_id == null)).length;
    }
  }
  const roots = [];
  for (const f of Object.values(byId)) (f.parent_id && byId[f.parent_id] ? byId[f.parent_id].children : roots).push(f);
  // Totals including subfolders, for the tree badges.
  const total = (f) => { f.totalWaiting = f.waiting; f.totalArticles = f.articles; for (const c of f.children) { total(c); f.totalWaiting += c.totalWaiting; f.totalArticles += c.totalArticles; } };
  roots.forEach(total);
  const sortFn = (a, b) => a.sort - b.sort || a.name.localeCompare(b.name);
  const sortRec = (list) => { list.sort(sortFn); list.forEach((f) => sortRec(f.children)); };
  sortRec(roots);
  return roots;
}

async function articleFiles(articleId) {
  return db.query("SELECT id, kind, original_name, stored_name, mime, size FROM files WHERE article_id = ? AND kind IN ('image','source','attachment') ORDER BY kind = 'source' DESC, sort_order, id", [articleId]);
}

async function contents(folderId) {
  const f = await getFolder(folderId);
  const chain = [];
  for (let p = f; p; p = p.parent_id ? await db.one('SELECT * FROM folders WHERE id = ?', [p.parent_id]) : null) chain.unshift({ id: p.id, name: p.name });
  const edition = await db.one('SELECT id, year, name FROM editions WHERE id = ?', [f.edition_id]);
  const children = (await tree(f.edition_id));
  const find = (list) => { for (const x of list) { if (x.id === f.id) return x; const y = find(x.children); if (y) return y; } return null; };
  const node = find(children);
  const waiting = await db.query('SELECT id, kind, original_name, mime, size, created_at FROM files WHERE folder_id = ? AND article_id IS NULL ORDER BY original_name', [f.id]);
  let articles = [];
  const w = articleWhere(f);
  if (w) {
    articles = await db.query(`SELECT a.id, a.title, a.status, a.author_name, a.section_id, a.featured_file_id, a.updated_at
      FROM articles a WHERE a.edition_id = ? AND ${w.sql} ORDER BY a.title`, [f.edition_id, ...w.args]);
    for (const a of articles) a.files = (await articleFiles(a.id)).map(({ stored_name, ...x }) => x);
  }
  const effectiveStage = f.stage;
  // Articles in this section (any stage) - for attaching loose photos.
  const sectionArticles = f.section_id
    ? await db.query("SELECT id, title, status FROM articles WHERE edition_id = ? AND section_id = ? AND status <> 'reviewing' ORDER BY title", [f.edition_id, f.section_id])
    : [];
  return {
    folder: { ...f, totalWaiting: node ? node.totalWaiting : waiting.length, totalArticles: node ? node.totalArticles : 0, children: node ? node.children.map(({ children: _c, ...c }) => c) : [] },
    edition, chain, waiting, articles, sectionArticles,
    canUpload: !f.stage || f.stage === 'raw',
    canProcess: effectiveStage === 'raw',
    canSubfolder: effectiveStage === 'raw' || !f.stage,
    diskPath: f.path,
  };
}

async function uploadInto(folderId, uploads, userId) {
  const f = await getFolder(folderId);
  if (f.stage && f.stage !== 'raw') throw new Error('Files are gathered in the Raw folders (or in plain folders). Later stages fill themselves as articles move along.');
  const saved = [];
  for (const u of uploads) {
    const mime = intake.mimeFor(u.originalname, u.mimetype);
    const r = await storage.saveBuffer(u.buffer, {
      originalName: u.originalname, mime, kind: intake.WEB_IMAGE.test(mime) ? 'image' : 'source',
      editionId: f.edition_id, folderId: f.id, dir: f.path, origin: 'folder',
    });
    saved.push({ id: r.id, name: u.originalname });
  }
  await activity.log({ editionId: f.edition_id, userId, action: 'files_gathered', detail: `${saved.length} file(s) into ${f.name}` });
  return saved;
}

async function createSubfolder(parentId, name, userId) {
  const p = await getFolder(parentId);
  if (p.stage && p.stage !== 'raw') throw new Error('Article folders can only be made inside Raw');
  if (p.kind === 'article') throw new Error('An article folder cannot contain another folder');
  const clean = storage.fsName(name, '');
  if (!clean) throw new Error('Folder name is required');
  const { folder, created } = await findOrCreate({
    editionId: p.edition_id, parentId: p.id, name: clean, kind: p.stage === 'raw' ? 'article' : 'custom',
    stage: p.stage, sectionId: p.section_id, sort: 1000, relPath: path.posix.join(p.path, clean),
  });
  if (!created) throw new Error('A folder with that name already exists');
  await activity.log({ editionId: p.edition_id, userId, action: 'folder_created', detail: `${p.name}/${clean}` });
  return folder;
}

const isImage = (row) => intake.WEB_IMAGE.test(intake.mimeFor(row.original_name, row.mime));
const baseOf = (n) => path.basename(n, path.extname(n)).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

// Turns the files gathered in a Raw folder into articles.
//  - an article folder (Raw/Section/<name>/) becomes ONE article with all its files
//  - in a section folder, each document becomes an article; photos go to the only document,
//    or to the document whose name they start with (e.g. "Kilimanjaro 2.jpg" -> "Kilimanjaro.docx")
//  - photos with no matching document wait until they are attached (or photosAsArticle is set)
async function processFolder(folderId, userId, { photosAsArticle = false } = {}) {
  const f = await getFolder(folderId);
  if (f.stage !== 'raw') throw new Error('Only Raw folders are processed');
  const out = { created: [], waiting: 0, notes: [] };

  const kids = await db.query("SELECT id FROM folders WHERE parent_id = ? AND kind = 'article'", [f.id]);
  for (const k of kids) {
    const r = await processFolder(k.id, userId, { photosAsArticle: true });
    out.created.push(...r.created); out.waiting += r.waiting; out.notes.push(...r.notes);
  }
  if (f.kind === 'stage') {
    const secs = await db.query("SELECT id FROM folders WHERE parent_id = ? AND kind = 'section'", [f.id]);
    for (const s of secs) {
      const r = await processFolder(s.id, userId, { photosAsArticle });
      out.created.push(...r.created); out.waiting += r.waiting; out.notes.push(...r.notes);
    }
  }

  const rows = await db.query('SELECT * FROM files WHERE folder_id = ? AND article_id IS NULL ORDER BY original_name', [f.id]);
  if (!rows.length) return out;
  const docs = rows.filter((r) => !isImage(r));
  const imgs = rows.filter(isImage);
  const make = async (title, stored) => {
    const r = await intake.createArticle({ editionId: f.edition_id, sectionId: f.section_id, title, storedFiles: stored, folderId: f.kind === 'article' ? f.id : null, userId });
    const a = await db.one('SELECT id, title FROM articles WHERE id = ?', [r.articleId]);
    out.created.push({ articleId: a.id, title: a.title, folder: f.name });
    out.notes.push(...r.notes);
  };

  if (f.kind === 'article') {
    await make(docs.length ? null : f.name, [...docs, ...imgs]);
    return out;
  }
  if (docs.length === 1) {
    await make(null, [...docs, ...imgs]);
    return out;
  }
  if (!docs.length) {
    if (photosAsArticle) await make(`${f.name} - photos`, imgs);
    else { out.waiting += imgs.length; out.notes.push(`${f.name}: ${imgs.length} photo(s) have no document. Attach them to an article, or process again with "photos as one article".`); }
    return out;
  }
  const claimed = new Set();
  for (const d of docs) {
    const b = baseOf(d.original_name);
    const mine = imgs.filter((i) => !claimed.has(i.id) && b && baseOf(i.original_name).startsWith(b));
    mine.forEach((i) => claimed.add(i.id));
    await make(null, [d, ...mine]);
  }
  const left = imgs.filter((i) => !claimed.has(i.id));
  if (left.length) {
    out.waiting += left.length;
    out.notes.push(`${f.name}: ${left.length} photo(s) could not be matched to a document. Attach each one to its article, or next time put an article's files in its own article folder.`);
  }
  return out;
}

async function processEdition(editionId, userId) {
  const raws = await db.query("SELECT id FROM folders WHERE edition_id = ? AND parent_id IS NULL AND stage = 'raw'", [editionId]);
  if (!raws.length) throw new Error('This issue has no Raw folder yet. Ask an administrator to generate the folders.');
  const out = { created: [], waiting: 0, notes: [] };
  for (const r of raws) {
    const x = await processFolder(r.id, userId);
    out.created.push(...x.created); out.waiting += x.waiting; out.notes.push(...x.notes);
  }
  return out;
}

// Links a waiting photo/document to an existing article (appended to its current text).
async function attachFile(fileId, articleId, userId) {
  const file = await db.one('SELECT * FROM files WHERE id = ?', [fileId]);
  if (!file || file.article_id) throw new Error('That file is already part of an article');
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [articleId]);
  if (!a || a.edition_id !== file.edition_id) throw new Error('Article not found in this issue');
  if (a.status === 'reviewing') throw new Error('Wait until the AI review has finished');
  const img = isImage(file);
  await db.query('UPDATE files SET article_id = ?, kind = ?, sort_order = 200 WHERE id = ?', [a.id, img ? 'image' : 'attachment', file.id]);
  if (img) {
    const col = a.status === 'raw' || !a.edited_html ? 'raw_html' : 'edited_html';
    await db.query(`UPDATE articles SET ${col} = CONCAT(COALESCE(${col}, ''), ?), featured_file_id = COALESCE(featured_file_id, ?) WHERE id = ?`,
      [`\n<p>${html.imgTag(file.id)}</p>`, file.id, a.id]);
  }
  await activity.log({ articleId: a.id, editionId: a.edition_id, userId, action: 'file_attached', detail: file.original_name });
  return { ok: true };
}

// ---------- Article export (stage copies and ZIP downloads) ----------
function articleDocument(a, files) {
  const nameFor = {};
  for (const f of files) nameFor[f.id] = `${f.id}-${storage.fsName(f.original_name, 'file')}`;
  const body = (a.status === 'raw' || a.status === 'reviewing' ? a.raw_html : (a.edited_html || a.raw_html)) || '';
  const out = body.replace(/src="\/files\/(\d+)"/g, (m, id) => (nameFor[id] ? `src="files/${nameFor[id]}"` : m));
  const doc = `<!doctype html><html><head><meta charset="utf-8"><title>${html.escapeHtml(a.title)}</title>
<style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.6}img{max-width:100%;height:auto}</style></head><body>
<h1>${html.escapeHtml(a.title)}</h1>${a.subtitle ? `<p><em>${html.escapeHtml(a.subtitle)}</em></p>` : ''}${a.author_name ? `<p>By ${html.escapeHtml(a.author_name)}</p>` : ''}
${out}</body></html>`;
  return { doc, nameFor };
}

const articleDirName = (a) => storage.fsName(`${a.title} [${a.id}]`);

async function stageFolderFor(a) {
  const stage = Object.keys(STAGE_STATUSES).find((k) => STAGE_STATUSES[k].includes(a.status));
  const top = await db.one('SELECT * FROM folders WHERE edition_id = ? AND parent_id IS NULL AND stage = ? ORDER BY sort LIMIT 1', [a.edition_id, stage]);
  if (!top) return null;
  if (!a.section_id) return top;
  return (await db.one("SELECT * FROM folders WHERE parent_id = ? AND kind = 'section' AND section_id = ?", [top.id, a.section_id])) || top;
}

// Writes a copy of the article (text + every photo + the original documents) into the folder
// of the stage it has just reached. Copies only ever get added - nothing is moved or removed.
async function snapshot(articleId) {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [articleId]);
  if (!a || a.status === 'raw' || a.status === 'reviewing') return null;
  const folder = await stageFolderFor(a);
  if (!folder) return null;
  const files = await articleFiles(a.id);
  const { doc, nameFor } = articleDocument(a, files);
  const dir = storage.absDir(path.posix.join(folder.path, articleDirName(a)));
  fs.mkdirSync(path.join(dir, 'files'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'article.html'), doc);
  for (const f of files) {
    const target = path.join(dir, 'files', nameFor[f.id]);
    if (fs.existsSync(target)) continue;
    const src = storage.filePath(f.stored_name);
    if (!fs.existsSync(src)) continue;
    try { fs.linkSync(src, target); } catch { fs.copyFileSync(src, target); }
  }
  return path.posix.join(folder.path, articleDirName(a));
}

function afterStageChange(articleId) {
  snapshot(articleId).catch((e) => console.warn('[folders] stage copy failed for article', articleId, e.message));
}

async function addArticleToZip(zip, dirName, articleId) {
  const a = await db.one('SELECT * FROM articles WHERE id = ?', [articleId]);
  const files = await articleFiles(articleId);
  const { doc, nameFor } = articleDocument(a, files);
  const d = zip.folder(dirName);
  d.file('article.html', doc);
  for (const f of files) {
    const p = storage.filePath(f.stored_name);
    if (fs.existsSync(p)) d.file(`files/${nameFor[f.id]}`, fs.readFileSync(p));
  }
}

async function zipFolder(folderId) {
  const root = await getFolder(folderId);
  const zip = new JSZip();
  const walk = async (f, z) => {
    const waiting = await db.query('SELECT * FROM files WHERE folder_id = ? AND article_id IS NULL', [f.id]);
    for (const w of waiting) {
      const p = storage.filePath(w.stored_name);
      if (fs.existsSync(p)) z.file(storage.fsName(w.original_name, `file-${w.id}`), fs.readFileSync(p));
    }
    const where = articleWhere(f);
    if (where) {
      const arts = await db.query(`SELECT a.id, a.title FROM articles a WHERE a.edition_id = ? AND ${where.sql}`, [f.edition_id, ...where.args]);
      for (const a of arts) await addArticleToZip(z, articleDirName(a), a.id);
    }
    const kids = await db.query('SELECT * FROM folders WHERE parent_id = ? ORDER BY sort, name', [f.id]);
    for (const k of kids) await walk(k, z.folder(k.name));
  };
  await walk(root, zip.folder(root.name));
  const buffer = await zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE', compressionOptions: { level: 3 } });
  return { buffer, name: `${root.name}.zip` };
}

async function removeWaiting(fileId, userId) {
  const file = await db.one('SELECT * FROM files WHERE id = ?', [fileId]);
  if (!file || file.article_id || !file.folder_id) throw new Error('Only files that are still waiting can be removed from a folder');
  // Hidden from the folder list only; the file itself stays on disk.
  await db.query('UPDATE files SET folder_id = NULL WHERE id = ?', [file.id]);
  await activity.log({ editionId: file.edition_id, userId, action: 'file_removed_from_folder', detail: `${file.original_name} (kept on disk)` });
}

module.exports = {
  parseTemplate, generate, tree, contents, uploadInto, createSubfolder, processFolder, processEdition,
  attachFile, snapshot, afterStageChange, zipFolder, removeWaiting, STAGE_KEYS,
};
