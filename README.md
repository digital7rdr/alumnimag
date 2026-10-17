# The Missing Link — Editorial Desk

Editorial dashboard for the alumni magazine, served at **drive.readthemissinglink.net**.
It replaces the Google Drive pipeline: articles, photos and reviewers live in MySQL and on the
server's disk, the AI review runs on Google Gemini, and finished articles go to WordPress as drafts.

It is plain PHP 8.1+ with MySQL, so it runs on ordinary Hostinger shared hosting (no Node.js needed).
All libraries are included in the repository (PHPMailer, smalot/pdfparser, Quill) — no Composer or npm step.

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
- **PM2 restart loops / lock-file CI failures** — no long-running process to crash; PHP runs per request and a cron job does the background work.

## Folders on the server

```
~/alumnimag/                          app code (this repository) + .env      <- private
~/alumnimag-storage/                  uploaded files and the issue folders   <- private, back this up
~/domains/readthemissinglink.net/public_html/drive/   web folder: a copy of public/ made by bin/deploy.sh
```

Only `public/` is web-facing. `bin/deploy.sh` copies it into the subdomain's web folder and writes
`app-root.php`, which tells it where the private code lives.

## Install on Hostinger (shared hosting)

1. **Code** — clone the repository to `~/alumnimag` (see the deploy key steps in the project notes) and
   `cp .env.example .env`, then fill in `.env`. The database tables are created automatically.
2. **PHP version** — hPanel → drive.readthemissinglink.net → *PHP Configuration*: choose PHP 8.2 or newer.
   On the same page raise `upload_max_filesize` (e.g. 64M) and `post_max_size` (e.g. 256M) if large uploads fail.
3. **Deploy**
   ```bash
   cd ~/alumnimag && bash bin/deploy.sh
   ```
   It copies the web files, removes Hostinger's `default.php`, and runs `bin/check.php`
   (PHP extensions, `.env` values, database, storage folder). Every line should say `ok`.
4. **Cron job** (reminders after the 2-day deadline, "AI review all" queue) — hPanel → *Advanced → Cron Jobs*,
   every 5 minutes:
   ```
   /usr/bin/php /home/u604557415/alumnimag/bin/cron.php
   ```
   (Use the PHP path shown by `which php` over SSH if different.) Settings → *Server* shows when it last ran.
5. **AI keys** — set `GEMINI_API_KEY` (https://aistudio.google.com/apikey) and/or `GROQ_API_KEY`
   (https://console.groq.com/keys, free tier) in `.env`. Choose the main provider in *Settings → AI review*;
   when it fails (no credit, rate limit), the other one is used automatically if its key is set.
   Groq is text-only (no image generation); its free tier limits tokens per minute, so very long articles may need Gemini.
6. **AI images (optional)** — the ✨ AI image button on an article writes a picture description from the article and
   generates the picture with Cloudflare Workers AI (free daily allowance, FLUX model). In the Cloudflare dashboard open
   *AI → Workers AI → Use REST API*, create the API token and copy the Account ID, then set `CF_ACCOUNT_ID` and
   `CF_API_TOKEN` in `.env`. Pictures are saved with the article's photos and can be inserted or made the featured image.
7. **Gmail** — on alumnikarunya@gmail.com create an App Password (Google Account → Security → App passwords)
   and put it in `SMTP_PASS`.
8. **WordPress** — on an administrator account go to *Users → Profile → Application Passwords*, create one,
   and enter the username and password in *Settings → WordPress*.
   Copy `wordpress/tml-bridge.php` to `wp-content/mu-plugins/` so drafts get the Divi layout settings.
9. Open https://drive.readthemissinglink.net, sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`, add editors under *Users*.

### Updating

```bash
cd ~/alumnimag && git pull && bash bin/deploy.sh
```

## Development

```bash
php tests/run.php                                   # unit tests (no database needed)
ALUMNIMAG_ENV=/path/to/test.env php -S localhost:8080 -t public public/index.php
```

`MAIL_TRANSPORT=log` writes emails to `STORAGE_DIR/tmp/mail.log` instead of sending them;
`GEMINI_BASE_URL` can point at a mock server.
