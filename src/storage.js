const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const config = require('./config');
const db = require('./db');

// Files are write-once. Nothing in this app ever deletes a stored file automatically:
// stage changes (Raw -> Edited -> Compiled -> Published) only change the article's
// status column, and AI editing only produces new text. That is what fixes the old
// "photos disappear after AI edit / after moving folders" problems.

function ensureDirs() {
  for (const d of ['', 'files', 'tmp']) fs.mkdirSync(path.join(config.storageDir, d), { recursive: true });
}

function safeName(name) {
  const ext = path.extname(name || '').toLowerCase().replace(/[^.a-z0-9]/g, '');
  const base = path.basename(name || 'file', path.extname(name || ''))
    .normalize('NFKD').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 60) || 'file';
  return { base, ext };
}

// Folder and file names that are safe on Linux/Windows but still readable in a file manager.
function fsName(name, fallback = 'untitled') {
  const s = String(name || '').replace(/[\\/:*?"<>|\x00-\x1f]+/g, '-').replace(/\s+/g, ' ').replace(/^[.\s-]+|[.\s-]+$/g, '').slice(0, 120);
  return s || fallback;
}

// stored_name is either a bare name (kept in files/) or a path relative to the storage
// root (files gathered in the edition folder tree, e.g. library/2026/Nov 2026/01. Raw/...).
function filePath(storedName) {
  const rel = storedName.includes('/') ? storedName : path.join('files', storedName);
  const full = path.resolve(config.storageDir, rel);
  if (!full.startsWith(path.resolve(config.storageDir) + path.sep)) throw new Error('Invalid file path');
  return full;
}

function absDir(relDir) {
  const full = path.resolve(config.storageDir, relDir);
  if (!full.startsWith(path.resolve(config.storageDir) + path.sep)) throw new Error('Invalid folder path');
  return full;
}

// Writes without ever overwriting: "photo.jpg" -> "photo (2).jpg" when the name is taken.
function writeUnique(dirAbs, name, buffer) {
  fs.mkdirSync(dirAbs, { recursive: true });
  const ext = path.extname(name);
  const base = name.slice(0, name.length - ext.length);
  for (let i = 1; i < 1000; i++) {
    const candidate = i === 1 ? name : `${base} (${i})${ext}`;
    try {
      fs.writeFileSync(path.join(dirAbs, candidate), buffer, { flag: 'wx' });
      return candidate;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
    }
  }
  throw new Error('Too many files with the same name');
}

async function saveBuffer(buffer, { originalName, mime, kind, articleId = null, editionId = null, origin = null, sortOrder = 0, dir = null, folderId = null }) {
  ensureDirs();
  let storedName;
  if (dir) {
    const { ext } = safeName(originalName);
    const base = fsName(path.basename(originalName || 'file', path.extname(originalName || '')), 'file');
    storedName = path.posix.join(dir, writeUnique(absDir(dir), base + ext, buffer));
  } else {
    const { base, ext } = safeName(originalName);
    const stamp = Date.now().toString(36) + crypto.randomBytes(4).toString('hex');
    storedName = `${stamp}-${base}${ext}`;
    fs.writeFileSync(filePath(storedName), buffer, { flag: 'wx' });
  }
  const sha1 = crypto.createHash('sha1').update(buffer).digest('hex');
  const res = await db.query(
    `INSERT INTO files (article_id, edition_id, folder_id, kind, original_name, stored_name, mime, size, sha1, origin, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [articleId, editionId, folderId, kind, originalName || storedName, storedName, mime || 'application/octet-stream', buffer.length, sha1, origin, sortOrder]
  );
  return { id: res.insertId, storedName, sha1, size: buffer.length };
}

function readFile(storedName) {
  return fs.readFileSync(filePath(storedName));
}

module.exports = { ensureDirs, saveBuffer, readFile, filePath, safeName, fsName, absDir, writeUnique };
