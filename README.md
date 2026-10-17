# The Missing Link — Editorial Desk

Editorial dashboard for the alumni magazine, served at **drive.readthemissinglink.net**.
It replaces the Google Drive pipeline: articles, photos and reviewers live in MySQL and on the
server's disk, the AI review runs on Google Gemini, and finished articles go to WordPress as drafts.

## Library: Year › Issue › folders

An administrator creates each issue under **Library** (year + issue name). Its folder tree is
created automatically, on the server under `STORAGE_DIR/library/<year>/<issue>/`:

```
2026/
  November 2026/
    01. Raw/                 <- files are gathered here
      Section 1 - Currently in Karunya/
      ...
      Section 7 - Like Unlike/
        Kilimanjaro/         <- optional article folder: everything in it = one article
    02. AI Edited/   (same section folders)
    03. With Reviewers/
    04. Compiled/
    05. Final/               <- WordPress draft stage
```

1. **Gather** — editors upload Word, PDF and photos into the Raw section folders (or an article folder inside them).
2. **Process** — turns the gathered files into articles: one per article folder, one per document in a
   section folder (photos go to the only document, or to the document whose name they start with).
   Unmatched photos wait in the folder until they are attached to an article.
3. The pipeline then continues: AI review → reviewers → Compiled → WordPress draft (admin).

Later stage folders are live views of the articles at that stage, and when an article reaches a stage a copy of
its text, photos and original files is written into that stage folder on the server. Nothing is ever moved or deleted.
Any folder can be downloaded as a ZIP. The folder list is set in *Settings → Issue folder structure*
(`Folder name = stage`, one per line); *Sync* on an issue adds folders that are missing.

## Workflow

| Stage | What happens | Who |
|---|---|---|
| **Raw** | Article arrives: uploaded file, written in the editor, or sent through the contributor link | Editors, contributors |
| **AI edited** | Gemini copy-edits the text. Photos are swapped for markers before the AI sees the text and put back afterwards | Editors |
| **With reviewers** | Each reviewer gets an email with a link to read the article and approve or request changes. Deadline is **2 days**, a reminder goes out when it passes, and **alumnikarunya@gmail.com is CC'd** on every review email | Editors |
| **Compiled** | Approved articles, ready for the site | Editors |
| **WP draft** | Sent to WordPress as a **draft** with category, edition tag, all photos and a 1200×600 featured image | **Administrators only** |

Accepted files: `.docx .doc .pdf .jpg .jpeg .png .gif .webp .txt .md .rtf .odt` (text is read from
.docx, .pdf, .txt and .md; other documents are stored and can be pasted into the editor).

## Problems from the old app that this design removes

- **Photos deleted after AI editing** — the AI only produces new text. Stored files are write-once; nothing deletes them.
  If the model drops a photo marker, the photo is re-attached and a note is shown.
- **Photos lost when moving Raw → Compiled → Final** — stages are a status on the article; files never move.
- **Only some pictures imported** — every picture in a Word file is extracted, including floating/anchored ones.
- **Drive storage quota / service-account errors** — no Google Drive any more.
- **`**` Markdown showing on the website** — AI output is HTML, with a Markdown clean-up safety net.
- **"Already imported" after deleting a WordPress post** — the draft is updated if it still exists, otherwise recreated.
- **Duplicate small/large photo** — the featured image is a separate 1200×600 crop; inline photos keep full size.
- **Review date 7 days** — now 2 days (Settings → Review workflow).
- **PM2 cluster restart loop / lock-file CI failures** — fork mode, committed `package-lock.json`, CI runs `npm ci && npm test`.

## Setup

1. **Database** — the app creates its own tables on first start (MySQL 5.7+/MariaDB 10.3+).
2. **Environment** — copy `.env.example` to `.env` and fill it in. Keep `STORAGE_DIR` *outside* the app folder
   (e.g. `/home/u604557415/alumnimag-storage`) so redeploys never touch uploaded files, and include it in backups.
3. **Gmail** — on alumnikarunya@gmail.com create an App Password (Google Account → Security → App passwords)
   and put it in `SMTP_PASS`.
4. **WordPress** — on an administrator account go to *Users → Profile → Application Passwords*, create one,
   and enter the username and password in *Settings → WordPress* (or in `.env`).
   Copy `wordpress/tml-bridge.php` to `wp-content/mu-plugins/` so drafts get the Divi layout settings.
5. **Install and start**
   ```bash
   npm ci
   npm start            # listens on $PORT
   ```
   On Hostinger's Node.js hosting, point the app at this repository, use `server.js` as the entry file,
   and add the `.env` values as environment variables. On a VPS use `pm2 start ecosystem.config.js`.
6. Sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`, then add editors under *Users*.

## Development

```bash
npm install
npm test        # unit tests
npm run dev     # auto-restart on change
```

`MAIL_TRANSPORT=json` logs emails instead of sending them; `GEMINI_BASE_URL` can point at a mock server.
