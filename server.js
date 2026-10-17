const path = require('path');
const express = require('express');
const session = require('express-session');
const MySQLStore = require('express-mysql-session')(session);
const helmet = require('helmet');
const config = require('./src/config');
const { migrate } = require('./src/migrate');
const storage = require('./src/storage');
const reviews = require('./src/reviews');
const { router: api } = require('./src/routes/api');
const publicRoutes = require('./src/routes/public');

async function main() {
  storage.ensureDirs();
  await migrate();

  const app = express();
  app.set('trust proxy', 1);
  app.use(helmet({ contentSecurityPolicy: false, crossOriginEmbedderPolicy: false }));
  app.use(express.json({ limit: '5mb' }));
  app.use(express.urlencoded({ extended: false, limit: '5mb' }));

  app.use(session({
    name: 'tml.sid',
    secret: config.sessionSecret,
    resave: false,
    saveUninitialized: false,
    store: new MySQLStore({ ...config.db, createDatabaseTable: true, schema: { tableName: 'sessions' } }),
    cookie: { httpOnly: true, sameSite: 'lax', secure: config.nodeEnv === 'production' && config.baseUrl.startsWith('https'), maxAge: 7 * 24 * 3600 * 1000 },
  }));

  app.get('/health', (req, res) => res.json({ status: 'ok', time: new Date().toISOString() }));
  app.use('/api', api);
  app.use(publicRoutes);
  app.use('/vendor/quill', express.static(path.join(__dirname, 'node_modules', 'quill', 'dist'), { maxAge: '7d' }));
  app.use(express.static(path.join(__dirname, 'public'), { index: 'index.html' }));
  app.get('*', (req, res) => res.sendFile(path.join(__dirname, 'public', 'index.html')));

  app.use((err, req, res, next) => {
    console.error('[server]', err.message);
    if (req.path.startsWith('/api')) return res.status(400).json({ error: err.message });
    res.status(400).send(err.message);
  });

  app.listen(config.port, () => console.log(`[server] The Missing Link dashboard on port ${config.port} (storage: ${config.storageDir})`));
  reviews.startScheduler();
}

main().catch((e) => {
  console.error('[server] failed to start:', e);
  process.exit(1);
});
