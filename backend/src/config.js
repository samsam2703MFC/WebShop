import 'dotenv/config';

function req(name, fallback) {
  const v = process.env[name] ?? fallback;
  if (v === undefined) throw new Error(`Missing required env var ${name}`);
  return v;
}

export const config = {
  port: Number(req('PORT', 3001)),
  corsOrigins: req('CORS_ORIGINS', '').split(',').map((s) => s.trim()).filter(Boolean),

  webshopDb: {
    host: req('WEBSHOP_DB_HOST', 'localhost'),
    port: Number(req('WEBSHOP_DB_PORT', 3306)),
    user: req('WEBSHOP_DB_USER', 'webshop'),
    password: req('WEBSHOP_DB_PASSWORD', ''),
    database: req('WEBSHOP_DB_NAME', 'webshop'),
  },

  generalDb: {
    host: req('GENERAL_DB_HOST', 'localhost'),
    port: Number(req('GENERAL_DB_PORT', 3306)),
    user: req('GENERAL_DB_USER', 'webshop'),
    password: req('GENERAL_DB_PASSWORD', ''),
    database: req('GENERAL_DB_NAME', 'franchise_buddy'),
  },

  stripe: {
    secretKey: process.env.STRIPE_SECRET_KEY || null,
    webhookSecret: process.env.STRIPE_WEBHOOK_SECRET || null,
    successUrl: process.env.CHECKOUT_SUCCESS_URL || 'http://localhost:8080/?paid=1',
    cancelUrl: process.env.CHECKOUT_CANCEL_URL || 'http://localhost:8080/?canceled=1',
  },

  sync: {
    pollMs: Number(req('SYNC_POLL_MS', 1000)),
    batchSize: Number(req('SYNC_BATCH_SIZE', 200)),
  },
};
