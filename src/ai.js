const config = require('./config');
const settings = require('./settings');
const db = require('./db');
const html = require('./html');
const activity = require('./activity');

const DEFAULT_PROMPT = `You are the copy editor of "The Missing Link", the alumni magazine of Karunya.
Edit the article for publication:
- Fix grammar, spelling, punctuation and awkward phrasing. Use Indian/British English spelling.
- Keep the author's voice, meaning, facts, names, numbers and quotes. Do not invent or add content.
- Improve flow and paragraphing; add short sub-headings (<h3>) only where the article is long and clearly has sections.
- Every image is marked as [[IMG:number]]. Keep EVERY marker exactly as written, in the same place, each in its own <p>.
- Output clean HTML only using p, h3, strong, em, blockquote, ul, ol, li, a. Never use Markdown such as ** or #.`;

const RESPONSE_SHAPE = `Return ONLY a JSON object with these keys:
{"title": string, "subtitle": string or "", "author_line": string or "", "body_html": string, "summary": string (max 2 sentences), "changes": [short strings describing the main edits]}`;

async function callGemini(systemText, userText) {
  const apiKey = config.gemini.apiKey;
  if (!apiKey) throw new Error('GEMINI_API_KEY is not set in .env');
  const model = (await settings.get('gemini_model')) || config.gemini.model;

  const url = `${config.gemini.baseUrl}/v1beta/models/${encodeURIComponent(model)}:generateContent`;
  const body = {
    systemInstruction: { parts: [{ text: systemText }] },
    contents: [{ role: 'user', parts: [{ text: userText }] }],
    generationConfig: { temperature: 0.3, responseMimeType: 'application/json' },
  };

  let lastErr;
  for (let attempt = 1; attempt <= 3; attempt++) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'x-goog-api-key': apiKey },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      const text = (data.candidates?.[0]?.content?.parts || []).map((p) => p.text || '').join('');
      if (!text) throw new Error('Gemini returned an empty response');
      return JSON.parse(text.replace(/^```(?:json)?\s*|\s*```$/g, ''));
    }
    lastErr = new Error(`Gemini ${res.status}: ${data.error?.message || res.statusText}`);
    if (![429, 500, 503].includes(res.status)) break;
    await new Promise((r) => setTimeout(r, 2000 * attempt));
  }
  throw lastErr;
}

// AI edits TEXT only. Images are replaced by [[IMG:id]] tokens before sending and
// restored afterwards; any image the model drops is re-attached. Stored files are never touched.
async function reviewArticle(articleId, userId = null) {
  const art = await db.one('SELECT * FROM articles WHERE id = ?', [articleId]);
  if (!art) throw new Error('Article not found');
  if (!['raw', 'edited'].includes(art.status)) throw new Error(`AI review is only available for Raw or Edited articles (this one is ${art.status})`);

  await db.query("UPDATE articles SET status = 'reviewing', ai_error = NULL WHERE id = ?", [articleId]);
  try {
    const source = art.raw_html || '';
    const { html: tokenised, ids } = html.imagesToTokens(source);
    const custom = (await settings.get('ai_prompt')) || '';
    const system = `${custom.trim() || DEFAULT_PROMPT}\n\n${RESPONSE_SHAPE}`;
    const user = `Working title: ${art.title}\nAuthor: ${art.author_name || 'unknown'}\n\nARTICLE HTML:\n${tokenised}`;

    const out = await callGemini(system, user);
    let body = html.fixMarkdown(String(out.body_html || ''));
    const restored = html.tokensToImages(body, ids);
    body = html.clean(restored.html);

    const notes = [];
    if (Array.isArray(out.changes) && out.changes.length) notes.push(...out.changes.map(String));
    if (restored.restored.length) notes.push(`${restored.restored.length} photo(s) were re-attached at the end because the AI moved them - check their position.`);

    const title = String(out.title || art.title).replace(/\*+/g, '').trim().slice(0, 290) || art.title;
    await db.query(
      `UPDATE articles SET status = 'edited', title = ?, subtitle = ?, author_name = COALESCE(NULLIF(?, ''), author_name),
         edited_html = ?, summary = ?, ai_notes = ? WHERE id = ?`,
      [title, String(out.subtitle || '').slice(0, 290) || null, String(out.author_line || '').slice(0, 190), body,
        String(out.summary || '') || null, notes.join('\n') || null, articleId]
    );
    await db.query("INSERT INTO article_versions (article_id, label, title, html, user_id) VALUES (?, 'AI edit', ?, ?, ?)",
      [articleId, title, body, userId]);
    await activity.log({ articleId, editionId: art.edition_id, userId, action: 'ai_review', detail: `${ids.length} image(s) preserved` });
    require('./folders').afterStageChange(articleId);
    return { ok: true, title, imagesPreserved: ids.length, restored: restored.restored.length };
  } catch (e) {
    await db.query('UPDATE articles SET status = ?, ai_error = ? WHERE id = ?', [art.status, e.message.slice(0, 1000), articleId]);
    await activity.log({ articleId, editionId: art.edition_id, userId, action: 'ai_error', detail: e.message });
    throw e;
  }
}

async function testConnection() {
  const out = await callGemini('Reply as JSON {"ok": true}.', 'ping');
  return !!out;
}

module.exports = { reviewArticle, testConnection, DEFAULT_PROMPT };
