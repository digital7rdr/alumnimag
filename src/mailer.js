const nodemailer = require('nodemailer');
const config = require('./config');
const settings = require('./settings');

let transport = null;
function getTransport() {
  if (config.smtp.transport === 'json') {
    if (!transport) {
      transport = nodemailer.createTransport({ jsonTransport: true });
      const send = transport.sendMail.bind(transport);
      transport.sendMail = async (msg) => { const info = await send(msg); console.log('[mail:json]', info.message); return info; };
    }
    return transport;
  }
  if (!config.smtp.user || !config.smtp.pass) throw new Error('SMTP_USER / SMTP_PASS are not set in .env');
  if (!transport) {
    transport = nodemailer.createTransport({
      host: config.smtp.host,
      port: config.smtp.port,
      secure: config.smtp.secure,
      auth: { user: config.smtp.user, pass: config.smtp.pass },
    });
  }
  return transport;
}

function splitList(v) {
  return String(v || '').split(/[,;\s]+/).map((s) => s.trim()).filter(Boolean);
}

// Every review notification CCs the editorial inbox (default alumnikarunya@gmail.com).
async function sendReviewMail({ to, subject, html, text }) {
  const s = await settings.all();
  const cc = splitList(s.review_cc).filter((a) => a.toLowerCase() !== String(to).toLowerCase());
  const from = config.smtp.from || config.smtp.user;
  const info = await getTransport().sendMail({
    from: `"${s.email_from_name}" <${from}>`,
    to,
    cc: cc.length ? cc : undefined,
    replyTo: cc[0] || from,
    subject,
    html,
    text,
  });
  return { messageId: info.messageId, cc };
}

async function sendMail({ to, subject, html, text }) {
  const s = await settings.all();
  const from = config.smtp.from || config.smtp.user;
  return getTransport().sendMail({ from: `"${s.email_from_name}" <${from}>`, to, subject, html, text });
}

module.exports = { sendReviewMail, sendMail, splitList };
