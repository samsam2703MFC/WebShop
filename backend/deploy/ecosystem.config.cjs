/* pm2 config — keeps the Buddy API running and restarts it on crash/reboot.
 *   npm install -g pm2
 *   pm2 start deploy/ecosystem.config.cjs
 *   pm2 save && pm2 startup     # survive server reboot
 */
module.exports = {
  apps: [{
    name: 'buddy-api',
    script: 'src/buddy-server.js',
    cwd: __dirname + '/..',
    instances: 1,
    autorestart: true,
    max_memory_restart: '300M',
    env: { NODE_ENV: 'production', PORT: 3002 },
  }],
};
