const sanitizeHtml = require('sanitize-html');

const ALLOWED = {
  allowedTags: ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'ul', 'ol', 'li',
    'a', 'img', 'figure', 'figcaption', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'sup', 'sub', 'span'],
  allowedAttributes: {
    a: ['href', 'target', 'rel'],
    img: ['src', 'alt', 'data-file-id', 'width', 'height'],
    '*': ['class'],
  },
  allowedSchemes: ['http', 'https', 'mailto', 'data'],
  allowedSchemesByTag: { img: ['http', 'https', 'data'] },
  allowProtocolRelative: false,
  transformTags: { b: 'strong', i: 'em', h1: 'h2' },
};

function clean(html) {
  return sanitizeHtml(html || '', ALLOWED).trim();
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Inline markdown -> HTML (fallback in case the AI or an author used **bold** etc.)
function inlineMd(text) {
  return text
    .replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>')
    .replace(/__(.+?)__/gs, '<strong>$1</strong>')
    .replace(/(^|[^*])\*(?!\s)([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>');
}

// Convert any stray markdown left inside HTML text (e.g. "<p>**Heading**</p>", "* item").
function fixMarkdown(html) {
  let out = html || '';
  // A paragraph that is entirely **bold** becomes a sub-heading.
  out = out.replace(/<p>\s*\*\*([^*<]{2,160}?)\*\*\s*<\/p>/g, '<h3>$1</h3>');
  // Markdown headings written inside paragraphs.
  out = out.replace(/<p>\s*#{1,3}\s+([^<]+)<\/p>/g, '<h3>$1</h3>');
  // Runs of "<p>* item</p>" become a list.
  out = out.replace(/(?:<p>\s*[*\-•]\s+[^<]*?<\/p>\s*)+/g, (block) => {
    const items = [...block.matchAll(/<p>\s*[*\-•]\s+([^<]*?)<\/p>/g)].map((m) => `<li>${m[1]}</li>`);
    return `<ul>${items.join('')}</ul>`;
  });
  // Remaining inline markers.
  out = out.replace(/>([^<]+)</g, (m, text) => `>${inlineMd(text)}<`);
  return out;
}

// Plain text (pdf, pasted text) -> paragraphs.
function textToHtml(text) {
  const blocks = String(text || '').replace(/\r\n?/g, '\n').split(/\n\s*\n+/).map((b) => b.trim()).filter(Boolean);
  return blocks.map((b) => `<p>${escapeHtml(b).replace(/\n/g, '<br>')}</p>`).join('\n');
}

// ---- Image tokens: images are swapped for [[IMG:id]] before the AI sees the text and
// restored afterwards, so the AI can never drop or alter a photo.
const IMG_RE = /<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>/g;

function imagesToTokens(html) {
  const ids = [];
  let out = (html || '').replace(/<figure[^>]*>\s*(<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>)[\s\S]*?<\/figure>/g, (m, img, id) => {
    ids.push(Number(id));
    return `<p>[[IMG:${id}]]</p>`;
  });
  out = out.replace(IMG_RE, (m, id) => {
    if (!ids.includes(Number(id))) ids.push(Number(id));
    return `[[IMG:${id}]]`;
  });
  return { html: out, ids };
}

function imgTag(id) {
  return `<img src="/files/${id}" data-file-id="${id}" alt="">`;
}

function tokensToImages(html, expectedIds = []) {
  const seen = new Set();
  let out = (html || '').replace(/<p>\s*\[\[IMG:(\d+)\]\]\s*<\/p>/g, (m, id) => {
    seen.add(Number(id));
    return `<p>${imgTag(id)}</p>`;
  });
  out = out.replace(/\[\[IMG:(\d+)\]\]/g, (m, id) => {
    seen.add(Number(id));
    return imgTag(id);
  });
  // Safety net: any image the AI dropped is put back at the end - photos are never lost.
  const missing = expectedIds.filter((id) => !seen.has(Number(id)));
  if (missing.length) out += '\n' + missing.map((id) => `<p>${imgTag(id)}</p>`).join('\n');
  return { html: out, restored: missing };
}

// Browser editors keep only src on <img>; restore our data-file-id from "/files/ID" (or reviewer URLs).
function normalizeImgs(html) {
  return (html || '').replace(/<img\b([^>]*?)\bsrc="(?:[^"]*?)\/files\/(\d+)"([^>]*)>/g, (m, pre, id) => imgTag(id));
}

function imageIdsIn(html) {
  return [...(html || '').matchAll(/data-file-id="(\d+)"/g)].map((m) => Number(m[1]));
}

function plainText(html) {
  return sanitizeHtml(html || '', { allowedTags: [], allowedAttributes: {} }).replace(/\s+/g, ' ').trim();
}

module.exports = { clean, escapeHtml, fixMarkdown, textToHtml, imagesToTokens, tokensToImages, imageIdsIn, plainText, imgTag, normalizeImgs };
