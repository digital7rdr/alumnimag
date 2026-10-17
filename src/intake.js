const path = require('path');
const crypto = require('crypto');
const mammoth = require('mammoth');
const JSZip = require('jszip');
const db = require('./db');
const storage = require('./storage');
const html = require('./html');
const activity = require('./activity');

const WEB_IMAGE = /^image\/(jpeg|png|gif|webp)$/;
const EXT_MIME = {
  '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  '.doc': 'application/msword',
  '.pdf': 'application/pdf',
  '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.gif': 'image/gif', '.webp': 'image/webp',
  '.txt': 'text/plain', '.md': 'text/markdown', '.rtf': 'application/rtf', '.odt': 'application/vnd.oasis.opendocument.text',
  '.emf': 'image/x-emf', '.wmf': 'image/x-wmf', '.tif': 'image/tiff', '.tiff': 'image/tiff', '.bmp': 'image/bmp',
};
const ALLOWED_EXT = ['.docx', '.doc', '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.txt', '.md', '.rtf', '.odt'];

function mimeFor(name, given) {
  const ext = path.extname(name || '').toLowerCase();
  return EXT_MIME[ext] || given || 'application/octet-stream';
}

function isAllowed(name) {
  return ALLOWED_EXT.includes(path.extname(name || '').toLowerCase());
}

function guessTitle(bodyHtml, fallback) {
  const t = (bodyHtml.match(/^\s*<p>\s*TITLE:\s*([^<]+)<\/p>/i) || [])[1]
    || (bodyHtml.match(/<h[1-3][^>]*>([\s\S]*?)<\/h[1-3]>/i) || [])[1]
    || (bodyHtml.match(/<p>(?:<strong>)?([^<]{4,140})(?:<\/strong>)?<\/p>/i) || [])[1];
  const clean = html.plainText(t || '').replace(/^TITLE:\s*/i, '').trim();
  return (clean || fallback || 'Untitled').slice(0, 290);
}

function stripLeadingTitle(bodyHtml) {
  return bodyHtml.replace(/^\s*<p>\s*TITLE:\s*[^<]*<\/p>\s*/i, '');
}

// ---------- DOCX: every embedded picture is extracted, inline AND floating ----------
async function extractDocx(buffer, ctx) {
  const savedHashes = new Set();
  let order = 0;

  const result = await mammoth.convertToHtml({ buffer }, {
    convertImage: mammoth.images.imgElement(async (image) => {
      const imgBuf = await image.read();
      const mime = image.contentType || 'image/png';
      if (!WEB_IMAGE.test(mime)) {
        // EMF/WMF/TIFF etc. cannot be shown on the web: keep the original as an attachment.
        await storage.saveBuffer(imgBuf, { ...ctx, kind: 'attachment', mime, originalName: `embedded-${++order}${mime.includes('emf') ? '.emf' : ''}`, origin: 'docx' });
        return { src: '' };
      }
      const ext = mime.split('/')[1].replace('jpeg', 'jpg');
      const saved = await storage.saveBuffer(imgBuf, { ...ctx, kind: 'image', mime, originalName: `image-${++order}.${ext}`, origin: 'docx-inline', sortOrder: order });
      savedHashes.add(saved.sha1);
      return { src: `/files/${saved.id}`, alt: image.altText || '' };
    }),
  });

  let body = result.value
    .replace(/<img src="\/files\/(\d+)"/g, '<img src="/files/$1" data-file-id="$1"')
    .replace(/<img src=""[^>]*>/g, '');

  // Floating / anchored pictures that the converter skipped: pull them straight out of
  // word/media so no photo is ever left behind.
  const extra = [];
  try {
    const zip = await JSZip.loadAsync(buffer);
    const media = Object.keys(zip.files).filter((n) => /^word\/media\//.test(n) && !zip.files[n].dir).sort();
    for (const name of media) {
      const buf = await zip.files[name].async('nodebuffer');
      const sha1 = crypto.createHash('sha1').update(buf).digest('hex');
      if (savedHashes.has(sha1)) continue;
      const mime = mimeFor(name);
      if (!WEB_IMAGE.test(mime)) {
        await storage.saveBuffer(buf, { ...ctx, kind: 'attachment', mime, originalName: path.basename(name), origin: 'docx-media' });
        continue;
      }
      const saved = await storage.saveBuffer(buf, { ...ctx, kind: 'image', mime, originalName: path.basename(name), origin: 'docx-floating', sortOrder: ++order });
      savedHashes.add(sha1);
      extra.push(saved.id);
    }
  } catch (e) {
    console.warn('[intake] could not scan docx media:', e.message);
  }

  if (extra.length) {
    body += '\n' + extra.map((id) => `<p>${html.imgTag(id)}</p>`).join('\n');
  }
  return { body, floating: extra.length };
}

async function extractPdf(buffer) {
  const pdfParse = require('pdf-parse/lib/pdf-parse.js');
  const data = await pdfParse(buffer);
  return html.textToHtml(data.text);
}

// files: [{ buffer, originalname, mimetype }] - new uploads, saved here.
// storedFiles: rows from the files table that were already gathered in a folder; they are
// linked to the new article (never copied or moved), and their text/pictures extracted.
async function createArticle({ editionId, sectionId, title, authorName, authorEmail, source = 'upload', bodyHtml = '', files = [], storedFiles = [], folderId = null, userId = null }) {
  const res = await db.query(
    `INSERT INTO articles (edition_id, section_id, title, author_name, author_email, source, created_by, folder_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [editionId, sectionId || null, (title || 'Untitled').slice(0, 290), authorName || null, authorEmail || null, source, userId, folderId]
  );
  const articleId = res.insertId;
  const ctx = { articleId, editionId };

  let body = bodyHtml ? html.clean(html.normalizeImgs(bodyHtml)) : '';
  const notes = [];
  const imageIds = [];
  let derivedTitle = null;

  const items = [
    ...storedFiles.map((row) => ({ row, name: row.original_name, given: row.mime })),
    ...files.map((f) => ({ upload: f, name: f.originalname, given: f.mimetype })),
  ];

  for (const it of items) {
    const name = it.name;
    const mime = mimeFor(name, it.given);
    const ext = path.extname(name).toLowerCase();
    const isImage = WEB_IMAGE.test(mime);
    let buffer;

    if (it.row) {
      buffer = storage.readFile(it.row.stored_name);
      await db.query('UPDATE files SET article_id = ?, kind = ?, sort_order = ? WHERE id = ?',
        [articleId, isImage ? 'image' : 'source', isImage ? 100 + imageIds.length : 0, it.row.id]);
      if (isImage) { imageIds.push(it.row.id); continue; }
    } else {
      buffer = it.upload.buffer;
      if (isImage) {
        const saved = await storage.saveBuffer(buffer, { ...ctx, kind: 'image', mime, originalName: name, origin: 'upload', sortOrder: 100 + imageIds.length });
        imageIds.push(saved.id);
        continue;
      }
      // Keep every source document exactly as uploaded.
      await storage.saveBuffer(buffer, { ...ctx, kind: 'source', mime, originalName: name, origin: 'upload' });
    }

    try {
      if (ext === '.docx') {
        const { body: b, floating } = await extractDocx(buffer, ctx);
        derivedTitle = derivedTitle || guessTitle(b, path.basename(name, ext));
        body += (body ? '\n' : '') + b;
        if (floating) notes.push(`${floating} floating picture(s) from ${name} were added at the end of the text - move them into place in the editor if needed.`);
      } else if (ext === '.pdf') {
        const b = await extractPdf(buffer);
        derivedTitle = derivedTitle || guessTitle(b, path.basename(name, ext));
        body += (body ? '\n' : '') + b;
        notes.push(`Text extracted from PDF ${name}. Upload its photos separately as JPG/PNG.`);
      } else if (ext === '.txt' || ext === '.md') {
        const b = html.fixMarkdown(html.textToHtml(buffer.toString('utf8')));
        derivedTitle = derivedTitle || guessTitle(b, path.basename(name, ext));
        body += (body ? '\n' : '') + b;
      } else {
        notes.push(`${name} was stored, but its text cannot be read automatically (${ext}). Save it as .docx and upload again, or paste the text in the editor.`);
      }
    } catch (e) {
      notes.push(`Could not read ${name}: ${e.message}`);
    }
  }

  if (imageIds.length) body += '\n' + imageIds.map((id) => `<p>${html.imgTag(id)}</p>`).join('\n');

  body = html.clean(stripLeadingTitle(html.fixMarkdown(body)));
  const finalTitle = title || derivedTitle || 'Untitled';

  const firstImage = html.imageIdsIn(body)[0] || null;
  await db.query('UPDATE articles SET title = ?, raw_html = ?, featured_file_id = ?, ai_notes = ? WHERE id = ?',
    [finalTitle.slice(0, 290), body, firstImage, notes.join('\n') || null, articleId]);
  await db.query("INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, 'Original', ?, ?, ?)",
    [articleId, finalTitle, body, userId]);
  await activity.log({ articleId, editionId, userId, action: 'created', detail: `${source}: ${items.map((i) => i.name).join(', ') || 'editor'}` });

  return { articleId, notes };
}

module.exports = { createArticle, isAllowed, mimeFor, ALLOWED_EXT, extractDocx, WEB_IMAGE };
