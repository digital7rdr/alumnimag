const Jimp = require('jimp');
const db = require('./db');
const settings = require('./settings');
const storage = require('./storage');
const html = require('./html');
const activity = require('./activity');

async function wpConfig() {
  const s = await settings.all();
  if (!s.wp_url || !s.wp_user || !s.wp_app_password) throw new Error('WordPress URL, user and application password must be set in Settings');
  return {
    base: s.wp_url.replace(/\/$/, '') + '/wp-json/wp/v2',
    auth: 'Basic ' + Buffer.from(`${s.wp_user}:${s.wp_app_password.replace(/\s+/g, '')}`).toString('base64'),
    layout: s.wp_layout,
    crop: s.crop_featured === '1',
  };
}

async function wp(cfg, method, path, { json, body, headers = {} } = {}) {
  const res = await fetch(cfg.base + path, {
    method,
    headers: { Authorization: cfg.auth, ...(json ? { 'Content-Type': 'application/json' } : {}), ...headers },
    body: json ? JSON.stringify(json) : body,
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }
  if (!res.ok) {
    const err = new Error(`WordPress ${res.status}: ${data?.message || String(text).slice(0, 200)}`);
    err.status = res.status;
    err.code = data?.code;
    err.data = data?.data;
    throw err;
  }
  return data;
}

const decode = (s) => String(s || '').replace(/&amp;/g, '&').replace(/&#8217;/g, "'").replace(/&#038;/g, '&').trim();

async function findOrCreateTerm(cfg, taxonomy, name) {
  // WordPress stores "&" as "&amp;", so search with a plain keyword and compare decoded names.
  const keyword = name.replace(/&/g, ' ').split(/[\s,]+/).filter(Boolean).slice(0, 2).join(' ');
  const found = await wp(cfg, 'GET', `/${taxonomy}?per_page=100&search=${encodeURIComponent(keyword)}`);
  const norm = (s) => decode(s).toLowerCase().replace(/\s+/g, ' ');
  const hit = found.find((t) => norm(t.name) === norm(name));
  if (hit) return hit.id;
  try {
    return (await wp(cfg, 'POST', `/${taxonomy}`, { json: { name } })).id;
  } catch (e) {
    if (e.code === 'term_exists' && e.data?.term_id) return e.data.term_id;
    throw e;
  }
}

async function uploadMedia(cfg, buffer, filename, mime, title) {
  const media = await wp(cfg, 'POST', '/media', {
    body: buffer,
    headers: { 'Content-Type': mime, 'Content-Disposition': `attachment; filename="${filename.replace(/"/g, '')}"` },
  });
  if (title) {
    try { await wp(cfg, 'POST', `/media/${media.id}`, { json: { alt_text: title, title } }); } catch { /* non-fatal */ }
  }
  return media;
}

async function cropTo(buffer, w, h) {
  const img = await Jimp.read(buffer);
  img.cover(w, h).quality(88);
  return img.getBufferAsync(Jimp.MIME_JPEG);
}

// Admin only, Compiled articles only. Always creates/updates a DRAFT.
async function publishDraft(articleId, userId) {
  const art = await db.one(`SELECT a.*, s.wp_category, e.name AS edition_name FROM articles a
    LEFT JOIN sections s ON s.id = a.section_id JOIN editions e ON e.id = a.edition_id WHERE a.id = ?`, [articleId]);
  if (!art) throw new Error('Article not found');
  if (!['compiled', 'published'].includes(art.status)) throw new Error('Only Compiled articles can be sent to WordPress');
  const cfg = await wpConfig();
  const content = art.edited_html || art.raw_html || '';

  // 1) Upload every inline image (re-using media already uploaded for this article).
  const ids = [...new Set(html.imageIdsIn(content))];
  const urlById = {};
  for (const id of ids) {
    const f = await db.one('SELECT * FROM files WHERE id = ? AND article_id = ?', [id, articleId]);
    if (!f) continue;
    if (f.wp_media_id && f.wp_url) { urlById[id] = f.wp_url; continue; }
    const media = await uploadMedia(cfg, storage.readFile(f.stored_name), f.original_name, f.mime, art.title);
    await db.query('UPDATE files SET wp_media_id = ?, wp_url = ? WHERE id = ?', [media.id, media.source_url, id]);
    urlById[id] = media.source_url;
  }
  let body = content.replace(/<img\b[^>]*\bdata-file-id="(\d+)"[^>]*>/g, (m, id) =>
    urlById[id] ? `<img src="${urlById[id]}" alt="${html.escapeHtml(art.title)}" class="aligncenter size-large">` : '');
  body = body.replace(/<p>\s*(<img[^>]+>)\s*<\/p>/g, '<figure class="wp-block-image aligncenter size-large">$1</figure>');
  if (art.subtitle) body = `<p class="tml-subtitle"><em>${html.escapeHtml(art.subtitle)}</em></p>\n` + body;

  // 2) Featured image: a separate 1200x600 crop so inline photos keep full quality.
  let featuredMedia = 0;
  const featuredId = art.featured_file_id || ids[0];
  if (featuredId && art.wp_featured_file === featuredId && art.wp_featured_media) {
    featuredMedia = art.wp_featured_media; // same photo already uploaded - reuse it
  } else if (featuredId) {
    const f = await db.one('SELECT * FROM files WHERE id = ?', [featuredId]);
    if (f) {
      let buf = storage.readFile(f.stored_name);
      let name = f.original_name;
      let mime = f.mime;
      if (cfg.crop) {
        buf = await cropTo(buf, 1200, 600);
        name = name.replace(/\.[^.]+$/, '') + '-1200x600.jpg';
        mime = 'image/jpeg';
      }
      featuredMedia = (await uploadMedia(cfg, buf, name, mime, art.title)).id;
      await db.query('UPDATE articles SET wp_featured_media = ?, wp_featured_file = ? WHERE id = ?', [featuredMedia, featuredId, articleId]);
    }
  }

  // 3) Category from section, edition as tag.
  const categories = art.wp_category ? [await findOrCreateTerm(cfg, 'categories', art.wp_category)] : [];
  const tags = art.edition_name ? [await findOrCreateTerm(cfg, 'tags', art.edition_name)] : [];

  const payload = {
    title: art.title,
    content: body,
    excerpt: art.summary || '',
    status: 'draft',
    categories,
    tags,
    ...(featuredMedia ? { featured_media: featuredMedia } : {}),
    // Honoured when the tml-bridge mu-plugin is installed (see wordpress/README.md).
    meta: { _et_pb_use_builder: 'off', _et_pb_page_layout: cfg.layout, _tml_article_id: String(art.id) },
  };

  // 4) Update the existing draft if it still exists; otherwise create a new one.
  //    (Fixes the old "already imported" error after a post was deleted.)
  let post = null;
  if (art.wp_post_id) {
    try {
      const existing = await wp(cfg, 'GET', `/posts/${art.wp_post_id}?context=edit`);
      if (existing && existing.status !== 'trash') {
        post = await wp(cfg, 'POST', `/posts/${art.wp_post_id}`, { json: { ...payload, status: existing.status === 'publish' ? 'publish' : 'draft' } });
      }
    } catch (e) {
      if (e.status !== 404 && e.status !== 410) throw e;
    }
  }
  if (!post) {
    try {
      post = await wp(cfg, 'POST', '/posts', { json: payload });
    } catch (e) {
      // If meta is rejected (bridge plugin not installed) retry without it.
      if (e.status === 400 && /meta/i.test(e.message)) {
        const { meta, ...rest } = payload;
        post = await wp(cfg, 'POST', '/posts', { json: rest });
      } else throw e;
    }
  }

  await db.query("UPDATE articles SET status = 'published', wp_post_id = ?, wp_link = ?, published_at = NOW() WHERE id = ?",
    [post.id, post.link || null, articleId]);
  require('./folders').afterStageChange(articleId);
  await activity.log({ articleId, editionId: art.edition_id, userId, action: 'wp_draft', detail: `Post ${post.id} (${ids.length} images)` });
  const s = await settings.all();
  return { postId: post.id, editUrl: `${s.wp_url.replace(/\/$/, '')}/wp-admin/post.php?post=${post.id}&action=edit`, images: ids.length };
}

async function testConnection() {
  const cfg = await wpConfig();
  const me = await wp(cfg, 'GET', '/users/me?context=edit');
  return { name: me.name, roles: me.roles || [] };
}

module.exports = { publishDraft, testConnection, cropTo };
