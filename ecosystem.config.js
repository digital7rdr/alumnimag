// Only needed if you run the app on a VPS with PM2 (Hostinger's Node.js hosting starts server.js itself).
module.exports = {
  apps: [{
    name: 'alumnimag',
    script: 'server.js',
    exec_mode: 'fork', // fork, not cluster: avoids the old EADDRINUSE restart loop
    instances: 1,
    autorestart: true,
    max_memory_restart: '400M',
    env: { NODE_ENV: 'production' },
    time: true,
  }],
};
