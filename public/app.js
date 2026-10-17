/* The Missing Link - Editorial Desk (vanilla JS single-page app) */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const view = $('#view');
  let me = null;
  let sections = [];
  let poller = null;

  const STAGES = [
    { key: 'raw', label: 'Raw', color: 'var(--raw)' },
    { key: 'edited', label: 'AI edited', color: 'var(--edited)' },
    { key: 'in_review', label: 'With reviewers', color: 'var(--in_review)' },
    { key: 'compiled', label: 'Compiled', color: 'var(--compiled)' },
    { key: 'published', label: 'WP draft', color: 'var(--published)' },
  ];
  const STAGE_LABEL = { raw: 'Raw', reviewing: 'AI editing…', edited: 'AI edited', in_review: 'With reviewers', compiled: 'Compiled', published: 'WP draft' };
  const STAGE_COLOR = { raw: 'var(--raw)', reviewing: 'var(--reviewing)', edited: 'var(--edited)', in_review: 'var(--in_review)', compiled: 'var(--compiled)', published: 'var(--published)' };
  const stageBadge = (s) => `<span class="badge st" style="--c:${STAGE_COLOR[s]}">${s === 'reviewing' ? '<span class="spin" style="border-top-color:#fff"></span>' : ''}${STAGE_LABEL[s] || s}</span>`;
  const fmt = (d) => (d ? new Date(d).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
  const fmtDay = (d) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : '');

  // ---------- API ----------
  async function api(path, opts = {}) {
    const init = { method: opts.method || 'GET', headers: {}, credentials: 'same-origin' };
    if (opts.form) init.body = opts.form;
    else if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    const res = await fetch('/api' + path, init);
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && path !== '/login') { showLogin(); throw new Error('Please sign in'); }
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
  }

  function toast(msg, bad = false) {
    const t = document.createElement('div');
    t.className = 'toast' + (bad ? ' bad' : '');
    t.textContent = msg;
    $('#toasts').appendChild(t);
    setTimeout(() => t.remove(), bad ? 7000 : 4000);
  }

  async function busy(btn, fn, label) {
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spin"></span> ${label || 'Working…'}`;
    try { return await fn(); } catch (e) { toast(e.message, true); } finally { btn.disabled = false; btn.innerHTML = old; }
  }

  function modal(title, html, { wide = false } = {}) {
    $('#modalClose').onclick = closeModal;
    $('#modalTitle').textContent = title;
    $('#modalBody').innerHTML = html;
    $('.modal-card').classList.toggle('wide', wide);
    $('#modal').classList.remove('hidden');
    return $('#modalBody');
  }
  const closeModal = () => $('#modal').classList.add('hidden');
  $('#modalClose').onclick = closeModal;
  $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });

  // ---------- Auth ----------
  function showLogin() { $('#app').classList.add('hidden'); $('#login').classList.remove('hidden'); }
  function showApp() {
    $('#login').classList.add('hidden'); $('#app').classList.remove('hidden');
    $('#whoami').innerHTML = `<strong>${esc(me.name)}</strong><br><span class="muted">${me.role === 'admin' ? 'Administrator' : 'Editor'}</span>`;
    $$('.admin-only').forEach((el) => el.classList.toggle('hidden', me.role !== 'admin'));
  }
  $('#loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    try {
      me = (await api('/login', { method: 'POST', body: { email: f.get('email'), password: f.get('password') } })).user;
      $('#loginError').textContent = '';
      await boot();
    } catch (err) { $('#loginError').textContent = err.message; }
  });
  $('#logoutBtn').onclick = async () => { await api('/logout', { method: 'POST' }).catch(() => {}); me = null; showLogin(); };

  // ---------- Router ----------
  const routes = [
    [/^#?\/?$/, home],
    [/^#\/editions$/, editions],
    [/^#\/edition\/(\d+)$/, folderView],
    [/^#\/edition\/(\d+)\/f\/(\d+)$/, folderView],
    [/^#\/edition\/(\d+)\/board$/, edition],
    [/^#\/edition\/(\d+)\/reviewers$/, editionReviewers],
    [/^#\/article\/(\d+)$/, article],
    [/^#\/settings$/, settingsPage],
    [/^#\/users$/, usersPage],
  ];
  async function route() {
    if (!me) return;
    if (poller) { clearInterval(poller); poller = null; }
    const h = location.hash || '#/';
    $$('.sidebar nav a').forEach((a) => a.classList.toggle('active', h === a.getAttribute('href') || (a.dataset.nav === 'editions' && /edition|article/.test(h))));
    for (const [re, fn] of routes) {
      const m = h.match(re);
      if (m) {
        view.innerHTML = '<p class="muted">Loading…</p>';
        try { await fn(...m.slice(1)); } catch (e) { view.innerHTML = `<div class="card"><p class="error-text">${esc(e.message)}</p></div>`; }
        return;
      }
    }
    location.hash = '#/';
  }
  window.addEventListener('hashchange', route);

  async function boot() {
    try { me = (await api('/me')).user; } catch { me = null; }
    if (!me) return showLogin();
    showApp();
    sections = await api('/sections');
    route();
  }

  const sectionOptions = (sel) => `<option value="">— No section —</option>` + sections.map((s) => `<option value="${s.id}" ${String(sel) === String(s.id) ? 'selected' : ''}>${s.num}. ${esc(s.name)}</option>`).join('');

  // ---------- Overview ----------
  async function home() {
    const [st, eds] = await Promise.all([api('/stats'), api('/editions')]);
    const tile = (k, label) => `<div class="tile" style="--c:${STAGE_COLOR[k]}"><div class="n">${st.byStage[k] || 0}</div><div class="l">${label}</div></div>`;
    view.innerHTML = `
      <div class="page-head"><div><h1>Overview</h1><div class="muted">Everything in the editorial pipeline right now</div></div>
        <a class="btn primary" href="#/editions">Open library</a></div>
      <div class="tiles">${tile('raw', 'Raw articles')}${tile('edited', 'AI edited')}${tile('in_review', 'With reviewers')}${tile('compiled', 'Compiled')}${tile('published', 'WP drafts')}
        <div class="tile" style="--c:${st.overdue ? 'var(--danger)' : 'var(--line)'}"><div class="n">${st.pendingReviews}</div><div class="l">Reviews awaited${st.overdue ? ` · <strong style="color:var(--danger)">${st.overdue} overdue</strong>` : ''}</div></div></div>
      <div class="grid2">
        <div class="card"><h3>Issues</h3>${eds.length ? eds.slice(0, 6).map((e) => `<div class="list-row"><div><a href="#/edition/${e.id}"><strong>${esc(e.name)}</strong></a><div class="muted">${e.year} · ${e.article_count} article(s)${e.waiting_count ? ` · ${e.waiting_count} file(s) waiting` : ''}</div></div><a class="btn small" href="#/edition/${e.id}">Open</a></div>`).join('') : '<p class="muted">No issues yet.</p>'}</div>
        <div class="card"><h3>Recent activity</h3><ul class="activity">${st.recent.map((a) => `<li><strong>${esc(a.action.replace(/_/g, ' '))}</strong> ${a.title ? `· <a href="#/article/${a.article_id}">${esc(a.title)}</a>` : ''}<div class="muted">${esc(a.user_name || 'system')} · ${fmt(a.created_at)}</div></li>`).join('') || '<li class="muted">Nothing yet.</li>'}</ul></div>
      </div>`;
  }

  // ---------- Library: Year > Issue ----------
  async function editions() {
    const isAdmin = me.role === 'admin';
    const [eds, s] = await Promise.all([api('/editions'), isAdmin ? api('/settings') : null]);
    const years = [...new Set(eds.map((e) => e.year))];
    const tplNames = s ? s.folder_template.split('\n').map((l) => l.split('=')[0].trim()).filter(Boolean) : [];
    view.innerHTML = `
      <div class="page-head"><div><h1>Library</h1><div class="muted">Year › Issue › folders. Each issue holds its folders, articles, photos and reviewers.</div></div></div>
      ${isAdmin ? `<div class="card"><h3>New issue</h3>
        <form id="newEd" class="row" style="align-items:flex-end"><label style="max-width:120px;margin:0">Year<input name="year" type="number" min="2000" max="2100" value="${new Date().getFullYear()}" required></label>
        <label class="grow" style="margin:0">Issue name<input name="name" placeholder="e.g. November 2026" required></label>
        <label style="margin:0 0 10px"><input type="checkbox" name="generate" checked style="display:inline;width:auto;margin-right:6px">Create the folders automatically</label>
        <button class="btn primary">Create issue</button></form>
        <p class="muted" style="font-size:12.5px;margin:10px 0 0">Folders: ${tplNames.map(esc).join(' · ')}${s.folder_sections === '1' ? `, each with a folder per section (${sections.length})` : ''}. Change this under <a href="#/settings">Settings</a>.</p></div>` : ''}
      ${years.length ? years.map((y) => `<div class="year-block"><h2 class="year-h">${y}</h2><div class="issues">
        ${eds.filter((e) => e.year === y).map((e) => `<a class="issue" href="#/edition/${e.id}">
          <div class="issue-name">📁 ${esc(e.name)}</div>
          <div class="chips">${e.folder_count ? '' : '<span class="badge warn">No folders yet</span>'}
            ${e.waiting_count ? `<span class="badge warn">${e.waiting_count} file(s) waiting</span>` : ''}
            <span class="badge">${e.article_count} article(s)</span>${e.published_count ? `<span class="badge ok">${e.published_count} in WordPress</span>` : ''}</div></a>`).join('')}
      </div></div>`).join('') : `<div class="card"><p class="muted" style="margin:0">No issues yet.${isAdmin ? ' Create the first one above.' : ' An administrator creates issues.'}</p></div>`}`;
    $('#newEd')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      try {
        const r = await api('/editions', { method: 'POST', body: { year: f.get('year'), name: f.get('name'), generate: !!f.get('generate') } });
        toast(r.folders ? `Issue created with ${r.folders.created} folders` : 'Issue created');
        location.hash = `#/edition/${r.id}`;
      } catch (err) { toast(err.message, true); }
    });
  }

  // Shared header for an issue: tabs + the step-by-step pipeline.
  function issueHead(ed, tab) {
    const n = (k) => ed.articles.filter((a) => a.status === k || (k === 'raw' && a.status === 'reviewing')).length;
    const step = (num, label, count, btn, done) => `<div class="pstep ${done ? 'done' : ''}"><div class="pnum">${num}</div><div class="grow"><div class="plabel">${label}</div><div class="muted" style="font-size:12px">${count}</div></div>${btn || ''}</div>`;
    const html = `
      <div class="page-head"><div><div class="crumbs"><a href="#/editions">Library</a> / ${ed.year}</div><h1>${esc(ed.name)}</h1></div>
        <div class="row"><button class="btn" id="contribBtn">Contributor link</button></div></div>
      <div class="pipeline">
        ${step(1, 'Gather files', `${ed.waiting_count} waiting in folders`, `<button class="btn small primary" data-pipe="process" ${ed.waiting_count ? '' : 'disabled'}>Process</button>`)}
        ${step(2, 'AI review', `${n('raw')} raw article(s)`, `<button class="btn small" data-pipe="ai" ${ed.articles.some((a) => a.status === 'raw') ? '' : 'disabled'}>AI review all</button>`)}
        ${step(3, 'Reviewers', `${n('edited')} edited · ${n('in_review')} with reviewers`, `<button class="btn small" data-pipe="send" ${n('edited') ? '' : 'disabled'}>Send reviews</button>`)}
        ${step(4, 'Compile', `${n('compiled')} compiled`, `<button class="btn small" data-pipe="compile" ${n('in_review') ? '' : 'disabled'}>Move approved</button>`)}
        ${step(5, 'WordPress drafts', `${n('published')} sent${me.role === 'admin' ? '' : ' · admin only'}`, '')}
      </div>
      <div class="tabs"><a href="#/edition/${ed.id}" class="${tab === 'folders' ? 'on' : ''}">Folders</a><a href="#/edition/${ed.id}/board" class="${tab === 'board' ? 'on' : ''}">Pipeline board</a><a href="#/edition/${ed.id}/reviewers" class="${tab === 'reviewers' ? 'on' : ''}">Reviewers</a></div>`;
    const wire = (reload) => {
      $('#contribBtn').onclick = () => {
        const body = modal('Contributor link', `<p class="muted" style="margin-top:0">Writers upload their own articles and photos here. Their submissions appear in the Raw folder of the section they choose.</p>
          <div class="copy"><input readonly value="${esc(ed.submitUrl)}"><button class="btn small" id="copySub">Copy</button></div>
          <label style="margin-top:14px"><input type="checkbox" id="subOpen" ${ed.submissions_open ? 'checked' : ''} style="display:inline;width:auto;margin-right:6px">Accepting submissions</label>`);
        $('#copySub', body).onclick = () => { navigator.clipboard.writeText(ed.submitUrl); toast('Contributor link copied'); };
        $('#subOpen', body).onchange = async (e) => { await api(`/editions/${ed.id}`, { method: 'PATCH', body: { submissions_open: e.target.checked } }); ed.submissions_open = e.target.checked; toast(e.target.checked ? 'Submissions open' : 'Submissions closed'); };
      };
      const acts = {
        process: async () => showProcessResult(await api(`/editions/${ed.id}/process`, { method: 'POST' }), ed.id),
        ai: async () => { const r = await api(`/editions/${ed.id}/ai-review-all`, { method: 'POST' }); toast(r.queued ? `${r.queued} article(s) queued for AI review — they are edited one by one and this page updates automatically` : 'No Raw articles to review'); },
        send: async () => { const r = await api(`/editions/${ed.id}/send-reviews`, { method: 'POST' }); toast(r.articles ? `Sent ${r.sent} review email(s) for ${r.articles} article(s)${r.failed.length ? `, ${r.failed.length} failed` : ''}` : 'No AI-edited articles with reviewers yet — add reviewers first', !r.articles || !!r.failed.length); },
        compile: async () => { const r = await api(`/editions/${ed.id}/compile`, { method: 'POST', body: { onlyApproved: true } }); toast(`${r.moved} approved article(s) moved to Compiled`); },
      };
      $$('[data-pipe]').forEach((b) => { b.onclick = () => busy(b, async () => { await acts[b.dataset.pipe](); reload(); }); });
    };
    return { html, wire };
  }

  function showProcessResult(r, editionId) {
    const body = modal('Files processed', `
      <p style="margin-top:0"><strong>${r.created.length}</strong> article(s) created${r.waiting ? `, <strong>${r.waiting}</strong> file(s) still waiting` : ''}.</p>
      ${r.created.length ? `<ul class="filelist">${r.created.map((c) => `<li><a href="#/article/${c.articleId}">${esc(c.title)}</a> <span class="muted">· ${esc(c.folder)}</span></li>`).join('')}</ul>` : ''}
      ${r.notes.length ? `<div class="notes">${r.notes.map(esc).join('\n')}</div>` : ''}
      <div class="row" style="margin-top:14px"><span class="grow"></span>${r.created.length ? '<button class="btn primary" id="goAi">Run AI review on new articles</button>' : ''}<button class="btn" id="okBtn">Close</button></div>`);
    $('#okBtn', body).onclick = closeModal;
    $('#goAi', body)?.addEventListener('click', (e) => busy(e.target, async () => {
      const x = await api(`/editions/${editionId}/ai-review-all`, { method: 'POST' });
      toast(`AI review started for ${x.queued} article(s)`); closeModal(); route();
    }));
  }

  // ---------- Issue: folders ----------
  async function folderView(id, folderId) {
    const [ed, tree] = await Promise.all([api(`/editions/${id}`), api(`/editions/${id}/folders`)]);
    const head = issueHead(ed, 'folders');
    const isAdmin = me.role === 'admin';
    if (!tree.length) {
      view.innerHTML = `${head.html}<div class="card"><h3>No folders yet</h3>
        <p class="muted">${isAdmin ? 'Create the stage and section folders for this issue.' : 'An administrator needs to create the folders for this issue.'}</p>
        ${isAdmin ? '<button class="btn primary" id="genBtn">Create folders</button>' : ''}</div>`;
      head.wire(() => folderView(id));
      $('#genBtn')?.addEventListener('click', (e) => busy(e.target, async () => { const r = await api(`/editions/${id}/folders/generate`, { method: 'POST' }); toast(`${r.created} folders created`); folderView(id); }));
      return;
    }
    const flat = [];
    const walk = (list) => list.forEach((f) => { flat.push(f); walk(f.children); });
    walk(tree);
    const selId = Number(folderId) || (tree.find((f) => f.stage === 'raw') || tree[0]).id;
    const c = await api(`/folders/${selId}`);
    const f = c.folder;
    const openIds = new Set(c.chain.map((x) => x.id));
    const badge = (n) => `${n.totalWaiting ? `<span class="tb warn" title="files waiting">${n.totalWaiting}</span>` : ''}${n.totalArticles ? `<span class="tb" title="articles">${n.totalArticles}</span>` : ''}`;
    const node = (n) => {
      const link = `<a href="#/edition/${id}/f/${n.id}" class="tn ${n.id === selId ? 'sel' : ''}">${n.stage && n.kind === 'stage' ? `<span class="dot" style="--c:${STAGE_COLOR[n.stage]}"></span>` : '📁 '}<span class="grow">${esc(n.name)}</span>${badge(n)}</a>`;
      if (!n.children.length) return `<li>${link}</li>`;
      return `<li><details ${openIds.has(n.id) ? 'open' : ''}><summary>${link}</summary><ul>${n.children.map(node).join('')}</ul></details></li>`;
    };
    const stageName = { raw: 'Raw', edited: 'AI edited', in_review: 'With reviewers', compiled: 'Compiled', published: 'WordPress draft' }[f.stage];
    const help = f.stage === 'raw'
      ? (f.kind === 'article' ? 'All files in this folder become ONE article when processed.' : 'Gather this section\'s files here. Put each article\'s document and photos in its own article folder, or drop them here directly. Press <strong>Process</strong> when everything is in.')
      : f.stage ? `Shows the articles that are now at <strong>${stageName}</strong>. When an article arrives, a copy of its text, photos and original files is also saved in this folder on the server — nothing is moved or deleted.`
        : 'Plain folder for any files (layouts, adverts, notes).';
    const kb = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
    const icon = (m) => (/^image\//.test(m) ? '🖼️' : /pdf/.test(m) ? '📕' : '📄');

    view.innerHTML = `${head.html}
      <div class="fx">
        <aside class="card tree"><div class="tree-head"><strong>${esc(ed.year)} › ${esc(ed.name)}</strong>${isAdmin ? '<button class="btn small ghost" id="regen" title="Add any folders missing from the template">Sync</button>' : ''}</div><ul class="troot">${tree.map(node).join('')}</ul></aside>
        <section class="card pane">
          <div class="crumbs">${c.chain.map((x) => `<a href="#/edition/${id}/f/${x.id}">${esc(x.name)}</a>`).join(' › ')}</div>
          <div class="row" style="justify-content:space-between;align-items:flex-start;margin-bottom:6px"><h2 class="pane-h">📁 ${esc(f.name)}</h2>
            <div class="row">${c.canUpload ? '<button class="btn primary small" id="upF">Upload files</button>' : ''}
              ${c.canSubfolder && f.kind !== 'article' && f.kind !== 'stage' ? '<button class="btn small" id="newSub">New article folder</button>' : ''}
              ${c.canProcess ? `<button class="btn small accent" id="procF" ${f.totalWaiting ? '' : 'disabled'}>Process ${f.totalWaiting || ''} file(s)</button>` : ''}
              <a class="btn small" href="/api/folders/${f.id}/zip">Download ZIP</a></div></div>
          <p class="muted" style="margin:0 0 14px">${help}</p>
          ${c.canUpload ? '<div class="dropzone slim" id="dzF">Drop files here — Word, PDF, JPG, PNG…</div><input type="file" id="fiF" multiple hidden accept=".docx,.doc,.pdf,.jpg,.jpeg,.png,.gif,.webp,.txt,.md,.rtf,.odt">' : ''}
          ${f.children.length ? `<div class="subs">${f.children.map((k) => `<a class="sub" href="#/edition/${id}/f/${k.id}"><span>📁 ${esc(k.name)}</span><span class="chips">${k.totalWaiting ? `<span class="badge warn">${k.totalWaiting} waiting</span>` : ''}${k.totalArticles ? `<span class="badge">${k.totalArticles} article(s)</span>` : ''}</span></a>`).join('')}</div>` : ''}
          ${c.waiting.length ? `<h3 class="sec-h">Waiting to be processed (${c.waiting.length})</h3><table class="ftable"><tbody>
            ${c.waiting.map((w) => `<tr><td>${icon(w.mime)} <a href="/files/${w.id}" target="_blank">${esc(w.original_name)}</a></td><td class="muted">${kb(w.size)}</td>
              <td style="text-align:right">${c.sectionArticles.length ? `<select class="attach" data-file="${w.id}" style="width:auto;display:inline-block;margin:0;padding:4px 8px;font-size:12.5px"><option value="">Attach to article…</option>${c.sectionArticles.map((a) => `<option value="${a.id}">${esc(a.title)}</option>`).join('')}</select>` : ''}
              ${isAdmin ? `<button class="icon-btn" title="Remove from folder (kept on the server)" data-rm="${w.id}">×</button>` : ''}</td></tr>`).join('')}</tbody></table>` : ''}
          ${c.articles.length || f.stage ? `<h3 class="sec-h">Articles${f.stage && f.kind !== 'article' ? ` at ${stageName}` : ''} (${c.articles.length})</h3>` : ''}
          ${c.articles.map((a) => `<div class="frow"><div class="grow"><a href="#/article/${a.id}"><strong>${esc(a.title)}</strong></a> ${stageBadge(a.status)}
              <div class="muted" style="font-size:12.5px">${a.author_name ? esc(a.author_name) + ' · ' : ''}${a.files.filter((x) => x.kind === 'image').length} photo(s) · ${a.files.filter((x) => x.kind !== 'image').map((x) => esc(x.original_name)).join(', ') || 'no documents'}</div></div>
              <div class="mini">${a.files.filter((x) => x.kind === 'image').slice(0, 4).map((x) => `<img src="/files/${x.id}" loading="lazy" alt="">`).join('')}</div></div>`).join('') || (f.stage ? '<p class="muted" style="margin:0">None yet.</p>' : '')}
          <p class="muted" style="font-size:12px;margin:16px 0 0">On the server: <code>${esc(c.diskPath)}</code></p>
        </section>
      </div>`;
    head.wire(() => folderView(id, selId));

    const reload = () => folderView(id, selId);
    const sendFiles = async (list) => {
      if (!list.length) return;
      const fd = new FormData();
      [...list].forEach((x) => fd.append('files[]', x));
      const dz = $('#dzF');
      dz.innerHTML = '<span class="spin"></span> Uploading…';
      try { const r = await api(`/folders/${selId}/files`, { method: 'POST', form: fd }); toast(`${r.saved.length} file(s) saved in ${f.name}`); reload(); } catch (e) { toast(e.message, true); dz.textContent = 'Drop files here — Word, PDF, JPG, PNG…'; }
    };
    if (c.canUpload) {
      const dz = $('#dzF'); const fi = $('#fiF');
      $('#upF').onclick = () => fi.click();
      dz.onclick = () => fi.click();
      fi.onchange = () => sendFiles(fi.files);
      dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('over'); };
      dz.ondragleave = () => dz.classList.remove('over');
      dz.ondrop = (e) => { e.preventDefault(); dz.classList.remove('over'); sendFiles(e.dataTransfer.files); };
    }
    $('#newSub')?.addEventListener('click', async () => {
      const name = prompt('Article folder name (e.g. the article title or the writer\'s name)');
      if (!name) return;
      try { const k = await api(`/folders/${selId}/subfolders`, { method: 'POST', body: { name } }); location.hash = `#/edition/${id}/f/${k.id}`; } catch (e) { toast(e.message, true); }
    });
    $('#procF')?.addEventListener('click', (e) => busy(e.target, async () => {
      const onlyPhotos = c.waiting.length && c.waiting.every((w) => /^image\//.test(w.mime)) && !f.children.some((k) => k.totalWaiting);
      const photosAsArticle = onlyPhotos && f.kind === 'section' ? confirm('This folder only has photos. Make them ONE article (e.g. a photo feature)?\n\nCancel = leave them waiting so you can attach them to articles.') : false;
      showProcessResult(await api(`/folders/${selId}/process`, { method: 'POST', body: { photosAsArticle } }), id);
      reload();
    }, 'Processing…'));
    $('#regen')?.addEventListener('click', (e) => busy(e.target, async () => { const r = await api(`/editions/${id}/folders/generate`, { method: 'POST' }); toast(r.created ? `${r.created} missing folder(s) added` : 'All folders are in place'); reload(); }));
    $$('.attach').forEach((s) => { s.onchange = async () => { if (!s.value) return; try { await api(`/files/${s.dataset.file}/attach`, { method: 'POST', body: { article_id: s.value } }); toast('Attached to the article'); reload(); } catch (e) { toast(e.message, true); } }; });
    $$('[data-rm]').forEach((b) => { b.onclick = async () => { if (!confirm('Remove this file from the folder? It stays saved on the server.')) return; try { await api(`/files/${b.dataset.rm}/waiting`, { method: 'DELETE' }); reload(); } catch (e) { toast(e.message, true); } }; });
    if (ed.articles.some((a) => a.status === 'reviewing' || +a.ai_queued)) poller = setInterval(() => { if (location.hash.startsWith(`#/edition/${id}`)) reload(); }, 8000);
  }

  // ---------- Issue: pipeline board ----------
  async function edition(id) {
    const ed = await api(`/editions/${id}`);
    const head = issueHead(ed, 'board');
    const filter = sessionStorage.getItem('secFilter') || '';
    const arts = ed.articles.filter((a) => !filter || String(a.section_id) === filter);
    const col = (stage) => {
      const list = arts.filter((a) => a.status === stage.key || (stage.key === 'raw' && a.status === 'reviewing'));
      return `<div class="col"><div class="col-head"><span><span class="dot" style="--c:${stage.color}"></span>${stage.label}</span><span class="muted">${list.length}</span></div>
        ${list.map(card).join('') || '<div class="muted" style="font-size:12px;padding:4px">Empty</div>'}</div>`;
    };
    const card = (a) => {
      const chips = [];
      if (a.status === 'reviewing') chips.push('<span class="badge" style="color:var(--reviewing)"><span class="spin"></span> AI editing</span>');
      else if (+a.ai_queued && a.status === 'raw') chips.push('<span class="badge" style="color:var(--reviewing)">Queued for AI</span>');
      if (a.ai_error) chips.push('<span class="badge bad">AI error</span>');
      chips.push(`<span class="badge">${a.image_count} photo${a.image_count == 1 ? '' : 's'}</span>`);
      if (a.reviewer_count) chips.push(`<span class="badge ${a.changes_count ? 'bad' : a.approved_count == a.reviewer_count ? 'ok' : 'warn'}">${a.approved_count}/${a.reviewer_count} approved</span>`);
      if (a.next_due) chips.push(`<span class="badge ${new Date(a.next_due) < new Date() ? 'bad' : ''}">due ${fmtDay(a.next_due)}</span>`);
      if (a.source === 'contributor') chips.push('<span class="badge">contributor</span>');
      return `<a class="art" href="#/article/${a.id}"><div class="t">${esc(a.title)}</div><div class="m">${a.section_num ? `§${a.section_num} ${esc(a.section_name)}` : 'No section'}${a.author_name ? ` · ${esc(a.author_name)}` : ''}</div><div class="chips">${chips.join('')}</div></a>`;
    };
    view.innerHTML = `${head.html}
      <div class="toolbar">
        <button class="btn" id="uploadBtn">Quick upload (skip folders)</button>
        <button class="btn" id="newBtn">Write in editor</button>
        <span class="grow"></span>
        <select id="secFilter" style="width:auto;margin:0"><option value="">All sections</option>${sections.map((s) => `<option value="${s.id}" ${filter === String(s.id) ? 'selected' : ''}>${s.num}. ${esc(s.name)}</option>`).join('')}</select>
      </div>
      <div class="board">${STAGES.map(col).join('')}</div>`;
    head.wire(() => edition(id));
    $('#secFilter').onchange = (e) => { sessionStorage.setItem('secFilter', e.target.value); edition(id); };
    $('#uploadBtn').onclick = () => uploadModal(id);
    $('#newBtn').onclick = () => newArticleModal(id);
    if (ed.articles.some((a) => a.status === 'reviewing' || +a.ai_queued)) poller = setInterval(() => { if (location.hash === `#/edition/${id}/board`) edition(id); }, 6000);
  }

  function uploadModal(editionId) {
    const body = modal('Upload files', `
      <p class="muted" style="margin-top:0">Word (.docx), PDF, text and photos (JPG, PNG, WEBP, GIF). Every photo inside a Word file is extracted, and originals are always kept.</p>
      <div class="dropzone" id="dz">Drop files here or click to choose</div><input type="file" id="fi" multiple hidden accept=".docx,.doc,.pdf,.jpg,.jpeg,.png,.gif,.webp,.txt,.md,.rtf,.odt">
      <ul class="filelist" id="fl"></ul>
      <div class="grid2"><label>Section<select id="upSec">${sectionOptions('')}</select></label>
      <label>Author (optional)<input id="upAuthor"></label></div>
      <label>How to group<select id="upMode"><option value="single">All files belong to ONE article</option><option value="each">One article per document</option></select></label>
      <label>Title (optional — taken from the document if empty)<input id="upTitle"></label>
      <div class="row"><span class="grow"></span><button class="btn primary" id="upGo">Upload</button></div>`);
    let files = [];
    const dz = $('#dz', body);
    const fi = $('#fi', body);
    const render = () => { $('#fl', body).innerHTML = files.map((f) => `<li>${esc(f.name)} <span class="muted">(${(f.size / 1024).toFixed(0)} KB)</span></li>`).join(''); };
    dz.onclick = () => fi.click();
    fi.onchange = () => { files = [...files, ...fi.files]; render(); };
    dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('over'); };
    dz.ondragleave = () => dz.classList.remove('over');
    dz.ondrop = (e) => { e.preventDefault(); dz.classList.remove('over'); files = [...files, ...e.dataTransfer.files]; render(); };
    $('#upGo', body).onclick = (e) => busy(e.target, async () => {
      if (!files.length) throw new Error('Choose at least one file');
      const fd = new FormData();
      files.forEach((f) => fd.append('files[]', f));
      fd.append('section_id', $('#upSec', body).value);
      fd.append('author_name', $('#upAuthor', body).value);
      fd.append('mode', $('#upMode', body).value);
      fd.append('title', $('#upTitle', body).value);
      const r = await api(`/editions/${editionId}/upload`, { method: 'POST', form: fd });
      closeModal();
      const notes = r.created.flatMap((c) => c.notes);
      toast(`${r.created.length} article(s) created${notes.length ? ' — see notes on the article' : ''}`);
      if (r.created.length === 1) location.hash = `#/article/${r.created[0].articleId}`; else edition(editionId);
    }, 'Uploading…');
  }

  function newArticleModal(editionId) {
    const body = modal('New article', `
      <label>Title<input id="naTitle" required></label>
      <div class="grid2"><label>Section<select id="naSec">${sectionOptions('')}</select></label><label>Author<input id="naAuthor"></label></div>
      <p class="muted">You will write or paste the text in the editor on the next screen.</p>
      <div class="row"><span class="grow"></span><button class="btn primary" id="naGo">Create</button></div>`);
    $('#naGo', body).onclick = (e) => busy(e.target, async () => {
      const r = await api(`/editions/${editionId}/articles`, { method: 'POST', body: { title: $('#naTitle', body).value, section_id: $('#naSec', body).value, author_name: $('#naAuthor', body).value, html: '' } });
      closeModal();
      location.hash = `#/article/${r.articleId}`;
    });
  }

  // ---------- Reviewers (edition) ----------
  async function editionReviewers(id) {
    const [ed, list] = await Promise.all([api(`/editions/${id}`), api(`/editions/${id}/reviewers`)]);
    const st = (r) => r.status === 'approved' ? '<span class="badge ok">Approved</span>' : r.status === 'changes' ? '<span class="badge bad">Changes</span>' : r.status === 'sent' ? `<span class="badge ${new Date(r.due_at) < new Date() ? 'bad' : 'warn'}">Sent · due ${fmtDay(r.due_at)}</span>` : '<span class="badge">Not sent</span>';
    const head = issueHead(ed, 'reviewers');
    view.innerHTML = `${head.html}
      <div class="card"><h3>Add reviewers in bulk</h3>
        <p class="muted" style="margin-top:0">One per line: <code>Name, email, Article title</code> (you can paste straight from a spreadsheet).</p>
        <textarea id="bulk" rows="5" placeholder="Regi Thomas, regi@example.com, The Fine Print of Everyday Life"></textarea>
        <div class="row" style="margin-top:8px"><span class="grow"></span><button class="btn primary" id="bulkGo">Add reviewers</button></div></div>
      <div class="card"><h3>All reviewers (${list.length})</h3>${list.length ? `<table><thead><tr><th>Reviewer</th><th>Article</th><th>Status</th><th>Comments</th></tr></thead><tbody>
        ${list.map((r) => `<tr><td><strong>${esc(r.name)}</strong><div class="muted">${esc(r.email)}</div></td><td>${r.article_id ? `<a href="#/article/${r.article_id}">${esc(r.article_title)}</a>` : '—'}</td><td>${st(r)}${r.reminded_at ? '<div class="muted" style="font-size:12px">reminder sent</div>' : ''}</td><td style="max-width:280px">${esc(r.comments || '')}</td></tr>`).join('')}
      </tbody></table>` : '<p class="muted">No reviewers yet. Add them here or on each article.</p>'}</div>`;
    head.wire(() => editionReviewers(id));
    $('#bulkGo').onclick = (e) => busy(e.target, async () => {
      const r = await api(`/editions/${id}/reviewers/import`, { method: 'POST', body: { text: $('#bulk').value } });
      toast(`${r.added} reviewer(s) added${r.skipped.length ? `, ${r.skipped.length} line(s) skipped (check the article title)` : ''}`, !!r.skipped.length);
      editionReviewers(id);
    });
  }

  // ---------- Article ----------
  let quill = null;
  async function article(id) {
    const a = await api(`/articles/${id}`);
    const images = a.files.filter((f) => f.kind === 'image');
    const others = a.files.filter((f) => f.kind !== 'image');
    const current = a.status === 'raw' || a.status === 'reviewing' ? a.raw_html : (a.edited_html || a.raw_html);
    const order = ['raw', 'edited', 'in_review', 'compiled', 'published'];
    const idx = order.indexOf(a.status === 'reviewing' ? 'raw' : a.status);
    const isAdmin = me.role === 'admin';

    const actions = [];
    if (a.status === 'raw') actions.push('<button class="btn primary block" data-act="ai">Run AI review</button>');
    if (a.status === 'reviewing') actions.push('<button class="btn block" disabled><span class="spin"></span> AI is editing…</button>');
    if (a.status === 'edited') actions.push('<button class="btn primary block" data-act="send">Send to reviewers</button>', '<button class="btn block" data-act="ai">Run AI review again</button>', '<button class="btn block" data-act="to-compiled">Skip review → Compiled</button>', '<button class="btn block" data-act="to-raw">Back to Raw</button>');
    if (a.status === 'in_review') actions.push('<button class="btn primary block" data-act="to-compiled">Move to Compiled</button>', '<button class="btn block" data-act="send">Resend review emails</button>', '<button class="btn block" data-act="to-edited">Back to AI edited</button>');
    if (a.status === 'compiled') actions.push(isAdmin ? '<button class="btn accent block" data-act="publish">Send to WordPress as draft</button>' : '<p class="muted" style="margin:0">An administrator will publish this to WordPress as a draft.</p>', '<button class="btn block" data-act="to-edited">Back to AI edited</button>');
    if (a.status === 'published') actions.push(`${a.wp_post_id ? `<a class="btn block" target="_blank" href="${esc(a.wp_link || '#')}">View WordPress draft #${a.wp_post_id}</a>` : ''}`, isAdmin ? '<button class="btn block" data-act="publish">Update WordPress draft</button>' : '');

    view.innerHTML = `
      <div class="page-head"><div><div class="crumbs"><a href="#/editions">Library</a> / <a href="#/edition/${a.edition_id}">${esc(a.edition_name)}</a></div>
        <div class="row">${stageBadge(a.status)}<span class="muted">${a.source === 'contributor' ? 'Submitted by contributor' : ''}</span></div></div>
        <div class="row"><button class="btn" id="previewBtn">Preview</button><button class="btn primary" id="saveBtn">Save</button></div></div>
      <div class="stepper">${['Raw', 'AI edited', 'Reviewers', 'Compiled', 'WP draft'].map((l, i) => `<div class="${i < idx ? 'done' : i === idx ? 'now' : ''}">${l}</div>`).join('')}</div>
      <div class="article-layout">
        <div>
          ${a.ai_error ? `<div class="notes err" style="margin-bottom:10px">AI review failed: ${esc(a.ai_error)}</div>` : ''}
          <input class="title-input" id="fTitle" value="${esc(a.title)}" placeholder="Title">
          <input class="sub-input" id="fSub" value="${esc(a.subtitle || '')}" placeholder="Subtitle (optional)">
          <div class="grid2"><label>Section<select id="fSec">${sectionOptions(a.section_id)}</select></label><label>Author<input id="fAuthor" value="${esc(a.author_name || '')}"></label></div>
          <div class="muted" style="font-size:12px;margin-bottom:6px">Editing the ${a.status === 'raw' || a.status === 'reviewing' ? '<strong>original</strong>' : '<strong>edited</strong>'} text. Earlier versions are kept under Versions.</div>
          <div class="editor-wrap"><div id="editor"></div></div>
          ${a.summary ? `<div class="card" style="margin-top:14px"><h3>Summary (used as WordPress excerpt)</h3><textarea id="fSummary" rows="3">${esc(a.summary)}</textarea></div>` : ''}
        </div>
        <div class="side">
          <div class="card"><h3>Next step</h3><div style="display:flex;flex-direction:column;gap:8px">${actions.join('')}</div>
            ${a.ai_notes ? `<div class="notes" style="margin-top:10px"><strong>Notes</strong>\n${esc(a.ai_notes)}</div>` : ''}</div>
          <div class="card"><h3>Photos (${images.length})<span class="row" style="gap:6px"><button class="btn small" id="aiImg">✨ AI image</button><button class="btn small" id="addImg">Add</button></span></h3>
            ${images.length ? `<div class="gallery">${images.map((f) => `<div class="thumb ${a.featured_file_id === f.id ? 'featured' : ''}">${a.featured_file_id === f.id ? '<span class="star">FEATURED</span>' : ''}<img src="/files/${f.id}" loading="lazy" alt="${esc(f.original_name)}"><div class="acts"><button data-feat="${f.id}">Feature</button><button data-ins="${f.id}">Insert</button></div></div>`).join('')}</div>
            <p class="muted" style="font-size:12px;margin:8px 0 0">Featured photo becomes the 1200×600 WordPress image. Photos are never deleted when the article moves between stages.</p>` : '<p class="muted" style="margin:0">No photos yet.</p>'}
            <input type="file" id="imgIn" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx"></div>
          <div class="card"><h3>Reviewers (${a.reviewers.length})</h3>
            ${a.reviewers.map((r) => `<div class="list-row" style="padding:8px 0"><div><strong>${esc(r.name)}</strong><div class="muted" style="font-size:12px">${esc(r.email)}</div>
              ${r.comments ? `<div style="font-size:12.5px;margin-top:4px">“${esc(r.comments)}”</div>` : ''}</div>
              <div style="text-align:right">${r.status === 'approved' ? '<span class="badge ok">Approved</span>' : r.status === 'changes' ? '<span class="badge bad">Changes</span>' : r.status === 'sent' ? `<span class="badge ${new Date(r.due_at) < new Date() ? 'bad' : 'warn'}">Due ${fmtDay(r.due_at)}</span>` : `<span class="badge">Not sent</span> <button class="icon-btn" title="Remove" data-delrev="${r.id}">×</button>`}</div></div>`).join('')}
            <form id="revForm" style="margin-top:8px"><div class="grid2"><input name="name" placeholder="Name" required style="margin:0"><input name="email" type="email" placeholder="Email" required style="margin:0"></div>
            <button class="btn small" style="margin-top:8px">Add reviewer</button></form></div>
          <div class="card"><h3>Files</h3>${others.length ? others.map((f) => `<div class="list-row" style="padding:6px 0"><a href="/files/${f.id}">${esc(f.original_name)}</a><span class="badge">${f.kind}</span></div>`).join('') : '<p class="muted" style="margin:0">No documents.</p>'}</div>
          <div class="card"><h3>Versions</h3>${a.versions.map((v) => `<div class="list-row" style="padding:6px 0"><div><strong>${esc(v.label)}</strong><div class="muted" style="font-size:12px">${esc(v.user_name || 'system')} · ${fmt(v.created_at)}</div></div><button class="btn small" data-ver="${v.id}">View</button></div>`).join('')}</div>
          <div class="card"><h3>Activity</h3><ul class="activity">${a.activity.map((x) => `<li>${esc(x.action.replace(/_/g, ' '))}${x.detail ? ` — <span class="muted">${esc(x.detail)}</span>` : ''}<div class="muted" style="font-size:12px">${esc(x.user_name || 'system')} · ${fmt(x.created_at)}</div></li>`).join('')}</ul></div>
          ${isAdmin ? '<button class="btn danger small" id="delArt">Delete article (files are kept)</button>' : ''}
        </div>
      </div>`;

    quill = new Quill('#editor', {
      theme: 'snow',
      modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline'], ['blockquote', { list: 'ordered' }, { list: 'bullet' }], ['link', 'clean']] },
      placeholder: 'Write or paste the article…',
      readOnly: a.status === 'reviewing',
    });
    quill.clipboard.dangerouslyPasteHTML(current || '');
    let dirty = false;
    quill.on('text-change', () => { dirty = true; });
    ['fTitle', 'fSub', 'fSec', 'fAuthor', 'fSummary'].forEach((k) => $('#' + k)?.addEventListener('input', () => { dirty = true; }));

    const save = async (silent) => {
      const body = { title: $('#fTitle').value, subtitle: $('#fSub').value, section_id: $('#fSec').value, author_name: $('#fAuthor').value, html: quill.root.innerHTML };
      if ($('#fSummary')) body.summary = $('#fSummary').value;
      await api(`/articles/${id}`, { method: 'PUT', body });
      dirty = false;
      if (!silent) toast('Saved');
    };
    $('#saveBtn').onclick = (e) => busy(e.target, () => save(), 'Saving…');
    $('#previewBtn').onclick = () => modal($('#fTitle').value, `<div class="preview">${$('#fSub').value ? `<p><em>${esc($('#fSub').value)}</em></p>` : ''}${quill.root.innerHTML}</div>`, { wide: true });

    const act = {
      ai: async () => { if (dirty) await save(true); toast('AI review running — this takes up to a minute'); const r = await api(`/articles/${id}/ai-review`, { method: 'POST' }); toast(`AI review done with ${r.provider} — ${r.imagesPreserved} photo(s) kept in place`); },
      send: async () => { if (dirty) await save(true); const r = await api(`/articles/${id}/send-review`, { method: 'POST' }); toast(`Sent to ${r.sent.length} reviewer(s), due in ${r.days} day(s)${r.failed.length ? ` — ${r.failed.length} failed: ${r.failed.map((f) => f.error).join('; ')}` : ''}`, !!r.failed.length); },
      'to-compiled': async () => { if (dirty) await save(true); await api(`/articles/${id}/stage`, { method: 'POST', body: { to: 'compiled' } }); toast('Moved to Compiled'); },
      'to-edited': async () => { await api(`/articles/${id}/stage`, { method: 'POST', body: { to: 'edited' } }); toast('Moved back to AI edited'); },
      'to-raw': async () => { if (!confirm('Go back to the original text? The edited version stays under Versions.')) return; await api(`/articles/${id}/stage`, { method: 'POST', body: { to: 'raw' } }); },
      publish: async () => { if (dirty) await save(true); const r = await api(`/articles/${id}/publish`, { method: 'POST' }); toast(`WordPress draft #${r.postId} ready with ${r.images} photo(s)`); window.open(r.editUrl, '_blank'); },
    };
    $$('[data-act]').forEach((b) => { b.onclick = () => busy(b, async () => { await act[b.dataset.act](); article(id); }); });

    $('#addImg').onclick = () => $('#imgIn').click();
    $('#aiImg').onclick = () => aiImageModal(id, a, { save, isDirty: () => dirty, insert: (fid) => { const r = quill.getSelection(true); quill.insertEmbed(r ? r.index : quill.getLength(), 'image', `/files/${fid}`); dirty = true; } });
    $('#imgIn').onchange = async (e) => {
      const fd = new FormData();
      [...e.target.files].forEach((f) => fd.append('files[]', f));
      try { if (dirty) await save(true); await api(`/articles/${id}/files`, { method: 'POST', form: fd }); toast('Files added'); article(id); } catch (err) { toast(err.message, true); }
    };
    $$('[data-feat]').forEach((b) => { b.onclick = async () => { if (dirty) await save(true); await api(`/articles/${id}`, { method: 'PUT', body: { featured_file_id: Number(b.dataset.feat) } }); article(id); }; });
    $$('[data-ins]').forEach((b) => { b.onclick = () => { const r = quill.getSelection(true); quill.insertEmbed(r ? r.index : quill.getLength(), 'image', `/files/${b.dataset.ins}`); dirty = true; toast('Photo inserted — remember to Save'); }; });
    $$('[data-delrev]').forEach((b) => { b.onclick = async () => { await api(`/reviewers/${b.dataset.delrev}`, { method: 'DELETE' }); article(id); }; });
    $('#revForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      try { await api(`/articles/${id}/reviewers`, { method: 'POST', body: { name: f.get('name'), email: f.get('email') } }); article(id); } catch (err) { toast(err.message, true); }
    };
    $$('[data-ver]').forEach((b) => {
      b.onclick = async () => {
        const v = await api(`/articles/${id}/versions/${b.dataset.ver}`);
        const body = modal(`${v.label} — ${fmt(v.created_at)}`, `<div class="row" style="margin-bottom:12px"><span class="grow muted">${esc(v.title || '')}</span><button class="btn primary small" id="restore">Load into editor</button></div><div class="preview">${v.html}</div>`, { wide: true });
        $('#restore', body).onclick = () => { quill.clipboard.dangerouslyPasteHTML(v.html); dirty = true; closeModal(); toast('Loaded — press Save to keep it'); };
      };
    });
    $('#delArt')?.addEventListener('click', async () => {
      if (!confirm('Delete this article? Its uploaded files stay in storage.')) return;
      await api(`/articles/${id}`, { method: 'DELETE' });
      location.hash = `#/edition/${a.edition_id}`;
    });
    window.onbeforeunload = () => (dirty ? true : undefined);
    if (a.status === 'reviewing') poller = setInterval(async () => { const x = await api(`/articles/${id}`); if (x.status !== 'reviewing') article(id); }, 5000);
  }

  // ---------- AI image ----------
  function aiImageModal(id, a, ed) {
    let made = null;
    const body = modal('AI image', `
      <label>Describe the picture<textarea id="aiPrompt" rows="4" placeholder="e.g. A mountain trail at sunrise with hikers in the distance, wide view"></textarea></label>
      <div class="row" style="margin-bottom:12px"><button class="btn" id="aiSuggest">Suggest from article</button><span class="grow"></span><button class="btn primary" id="aiGo">Generate</button></div>
      <div id="aiOut"></div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Made with Cloudflare Workers AI (free daily allowance). Every picture is saved with this article's photos. It is good practice to mark AI pictures as illustrations.</p>`, { wide: true });
    const done = async () => { closeModal(); if (ed.isDirty()) await ed.save(true); article(id); };
    $('#aiSuggest', body).onclick = (e) => busy(e.target, async () => { $('#aiPrompt', body).value = (await api(`/articles/${id}/ai-image/prompt`, { method: 'POST' })).prompt; }, 'Thinking…');
    $('#aiGo', body).onclick = (e) => busy(e.target, async () => {
      made = await api(`/articles/${id}/ai-image`, { method: 'POST', body: { prompt: $('#aiPrompt', body).value } });
      $('#aiOut', body).innerHTML = `<img src="/files/${made.id}" alt="" style="display:block;max-width:100%;max-height:360px;margin:0 auto;border-radius:8px;border:1px solid var(--line)">
        <div class="row" style="margin-top:10px"><button class="btn primary" id="aiIns">Insert into text</button><button class="btn" id="aiFeat">Make featured image</button><span class="grow"></span><button class="btn ghost" id="aiKeep">Keep in photos only</button></div>`;
      $('#aiIns', body).onclick = () => { ed.insert(made.id); toast('AI image inserted'); done(); };
      $('#aiFeat', body).onclick = async () => { if (ed.isDirty()) await ed.save(true); await api(`/articles/${id}`, { method: 'PUT', body: { featured_file_id: made.id } }); toast('Featured image set'); closeModal(); article(id); };
      $('#aiKeep', body).onclick = done;
    }, 'Generating…');
    $('#modalClose').onclick = () => { if (made) done(); else closeModal(); };
  }

  // ---------- Settings ----------
  async function settingsPage() {
    const s = await api('/settings');
    view.innerHTML = `
      <div class="page-head"><div><h1>Settings</h1><div class="muted">Administrators only</div></div><button class="btn primary" id="saveSet">Save settings</button></div>
      <div class="grid2">
        <div class="card"><h3>Review workflow</h3>
          <label>Review deadline (days after sending)<input id="review_days" type="number" min="1" max="30" value="${esc(s.review_days)}"></label>
          <label>CC on every review email (comma separated)<input id="review_cc" value="${esc(s.review_cc)}"></label>
          <label>Email sender name<input id="email_from_name" value="${esc(s.email_from_name)}"></label>
          <div class="row"><input id="testTo" placeholder="Send test to (defaults to you)" style="margin:0" class="grow"><button class="btn" id="testMail">Send test email</button></div>
          <p class="muted" style="font-size:12px">SMTP is ${s.env.smtp ? '<strong>configured</strong>' : '<strong style="color:var(--danger)">not configured</strong> (set SMTP_USER / SMTP_PASS in .env)'}.</p></div>
        <div class="card"><h3>AI review</h3>
          <label>Main AI provider<select id="ai_provider">${[['gemini', 'Google Gemini'], ['groq', 'Groq (free tier)']].map(([v, l]) => `<option value="${v}" ${s.ai_provider === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label><input type="checkbox" id="ai_fallback" ${s.ai_fallback === '1' ? 'checked' : ''} style="display:inline;width:auto;margin-right:6px">If the main provider fails, try the other one</label>
          <div class="grid2"><label>Gemini model<input id="gemini_model" value="${esc(s.gemini_model)}" placeholder="gemini-2.5-flash"></label>
            <label>Groq model<input id="groq_model" value="${esc(s.groq_model)}" placeholder="openai/gpt-oss-120b"></label></div>
          <label>Editing instructions (leave empty for the default)<textarea id="ai_prompt" rows="7" placeholder="${esc(s.default_prompt)}">${esc(s.ai_prompt)}</textarea></label>
          <div class="row"><button class="btn" data-testai="gemini">Test Gemini</button><button class="btn" data-testai="groq">Test Groq</button></div>
          <h3 style="margin-top:16px">AI images</h3>
          <label>Image model (Cloudflare Workers AI)<input id="image_model" value="${esc(s.image_model)}" placeholder="@cf/black-forest-labs/flux-1-schnell"></label>
          <label>Style added to every picture<input id="image_style" value="${esc(s.image_style)}"></label>
          <p class="muted" style="font-size:12px;margin:0">Cloudflare ${s.env.image ? 'is set up' : '<strong style="color:var(--danger)">not set up</strong> (CF_ACCOUNT_ID and CF_API_TOKEN in .env)'}</p>
          <p class="muted" style="font-size:12px;margin:8px 0 0">Keys in .env: Gemini ${s.env.gemini ? 'set' : '<strong style="color:var(--danger)">missing</strong> (GEMINI_API_KEY)'} · Groq ${s.env.groq ? 'set' : '<strong style="color:var(--danger)">missing</strong> (GROQ_API_KEY)'}</p></div>
        <div class="card"><h3>WordPress</h3>
          <label>Site URL<input id="wp_url" value="${esc(s.wp_url)}"></label>
          <label>Username<input id="wp_user" value="${esc(s.wp_user)}"></label>
          <label>Application password<input id="wp_app_password" value="${esc(s.wp_app_password)}" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"></label>
          <label>Article layout<select id="wp_layout">${[['et_full_width_page', 'Full width'], ['et_no_sidebar', 'No sidebar'], ['et_right_sidebar', 'Right sidebar'], ['et_left_sidebar', 'Left sidebar']].map(([v, l]) => `<option value="${v}" ${s.wp_layout === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label><input type="checkbox" id="crop_featured" ${s.crop_featured === '1' ? 'checked' : ''} style="display:inline;width:auto;margin-right:6px">Crop featured image to 1200 × 600</label>
          <button class="btn" id="testWp">Test connection</button></div>
        <div class="card"><h3>Issue folder structure</h3>
          <label>Folders created for every new issue — one per line, <code>Folder name = stage</code><textarea id="folder_template" rows="7" style="font-family:ui-monospace,monospace">${esc(s.folder_template)}</textarea></label>
          <p class="muted" style="font-size:12px;margin-top:-6px">Stages: <code>raw</code> (files are gathered here), <code>edited</code>, <code>in_review</code>, <code>compiled</code>, <code>published</code> (final / WordPress). A line without <code>= stage</code> makes a plain folder.</p>
          <label><input type="checkbox" id="folder_sections" ${s.folder_sections === '1' ? 'checked' : ''} style="display:inline;width:auto;margin-right:6px">Add a folder for each section inside every folder</label>
          <p class="muted" style="font-size:12px;margin:0">Existing issues get missing folders with the <strong>Sync</strong> button on their folder tree. Folders are never deleted.</p></div>
        <div class="card"><h3>Server</h3><p class="muted" style="margin:0 0 8px">Uploads are stored in <code>${esc(s.env.storageDir)}</code>. Keep this folder outside the web folder and include it in backups.</p>
          <p class="muted" style="margin:0 0 8px">Upload limit: ${esc(s.env.uploadLimit)} (change it in hPanel → PHP Configuration if needed).</p>
          <p class="muted" style="margin:0">Background job (reminders, AI queue) last ran: ${s.env.cron === 'never' ? '<strong style="color:var(--danger)">never — add the Cron Job (see README)</strong>' : esc(s.env.cron)}</p></div>
      </div>`;
    const ids = ['review_days', 'review_cc', 'email_from_name', 'ai_provider', 'gemini_model', 'groq_model', 'ai_prompt', 'image_model', 'image_style', 'wp_url', 'wp_user', 'wp_app_password', 'wp_layout', 'folder_template'];
    const collect = () => Object.fromEntries([...ids.map((k) => [k, $('#' + k).value]), ['crop_featured', $('#crop_featured').checked ? '1' : '0'], ['folder_sections', $('#folder_sections').checked ? '1' : '0'], ['ai_fallback', $('#ai_fallback').checked ? '1' : '0']]);
    $('#saveSet').onclick = (e) => busy(e.target, async () => { await api('/settings', { method: 'PUT', body: collect() }); toast('Settings saved'); settingsPage(); });
    $('#testMail').onclick = (e) => busy(e.target, async () => { await api('/settings', { method: 'PUT', body: collect() }); const r = await api('/settings/test-email', { method: 'POST', body: { to: $('#testTo').value } }); toast(`Test email sent${r.cc.length ? ` (CC ${r.cc.join(', ')})` : ''}`); });
    $$('[data-testai]').forEach((b) => { b.onclick = () => busy(b, async () => { await api('/settings', { method: 'PUT', body: collect() }); await api('/settings/test-ai', { method: 'POST', body: { provider: b.dataset.testai } }); toast(`${b.dataset.testai === 'groq' ? 'Groq' : 'Gemini'} is responding`); }); });
    $('#testWp').onclick = (e) => busy(e.target, async () => { await api('/settings', { method: 'PUT', body: collect() }); const r = await api('/settings/test-wp', { method: 'POST' }); toast(`Connected to WordPress as ${r.name}`); });
  }

  // ---------- Users ----------
  async function usersPage() {
    const users = await api('/users');
    view.innerHTML = `
      <div class="page-head"><div><h1>Users</h1><div class="muted">Editors run AI reviews and manage reviewers. Only administrators send articles to WordPress.</div></div></div>
      <div class="card"><h3>Add a user</h3><form id="uForm"><div class="grid2"><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label>
        <label>Password (min 8)<input name="password" type="password" minlength="8" required></label><label>Role<select name="role"><option value="editor">Editor</option><option value="admin">Administrator</option></select></label></div>
        <button class="btn primary">Add user</button></form></div>
      <div class="card"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
      ${users.map((u) => `<tr><td>${esc(u.name)}</td><td>${esc(u.email)}</td><td>${u.role === 'admin' ? 'Administrator' : 'Editor'}</td><td>${u.active ? '<span class="badge ok">Active</span>' : '<span class="badge">Disabled</span>'}</td>
        <td class="row"><button class="btn small" data-role="${u.id}" data-to="${u.role === 'admin' ? 'editor' : 'admin'}">Make ${u.role === 'admin' ? 'editor' : 'admin'}</button>
        <button class="btn small" data-active="${u.id}" data-to="${u.active ? 0 : 1}">${u.active ? 'Disable' : 'Enable'}</button><button class="btn small" data-pw="${u.id}">Reset password</button></td></tr>`).join('')}</tbody></table></div>`;
    $('#uForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(e.target));
      try { await api('/users', { method: 'POST', body: f }); toast('User added'); usersPage(); } catch (err) { toast(err.message, true); }
    };
    const upd = async (id, body) => { try { await api(`/users/${id}`, { method: 'PUT', body }); usersPage(); } catch (err) { toast(err.message, true); } };
    $$('[data-role]').forEach((b) => { b.onclick = () => upd(b.dataset.role, { role: b.dataset.to }); });
    $$('[data-active]').forEach((b) => { b.onclick = () => upd(b.dataset.active, { active: b.dataset.to === '1' }); });
    $$('[data-pw]').forEach((b) => { b.onclick = () => { const p = prompt('New password (min 8 characters)'); if (p) upd(b.dataset.pw, { password: p }); }; });
  }

  boot();
})();
