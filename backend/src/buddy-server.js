/* Buddy API server — serves the storefront contracts from the real ws_ INT
 * schema by mounting every table-linked bridge router. This is the API the
 * frontend (window.WSXxx) points at when BASE_URL is set.
 *
 * Run:  node src/buddy-server.js   (PORT defaults to 3002)
 */
import express from 'express';
import { config } from './config.js';
import { webshopDb } from './db.js';
import { createCatalogRouter } from './catalog.js';
import { createPromosRouter } from './promos.js';
import { createAvailabilityRouter } from './availability.js';
import { createNetworkRouter } from './network.js';
import { createOrdersRouter } from './orders.js';
import { createAuthRouter } from './auth.js';
import { createPaymentsRouter } from './payments.js';

export const app = express();

app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (origin && (config.corsOrigins.includes(origin) || config.corsOrigins.includes('*') || !config.corsOrigins.length)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Credentials', 'true');
    res.set('Access-Control-Allow-Headers', 'Content-Type, X-WP-Nonce, Authorization');
    res.set('Access-Control-Allow-Methods', 'GET, POST, PATCH, OPTIONS');
  }
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});
app.use(express.json());

app.get('/health', async (_req, res) => {
  try { await webshopDb.query('SELECT 1'); res.json({ ok: true }); }
  catch (e) { res.status(500).json({ ok: false, error: e.message }); }
});

/* Every bridge is a router linked to its ws_ tables. */
app.use(createCatalogRouter(webshopDb));       // /shops /brand /catalog/*
app.use(createPromosRouter(webshopDb));        // /pricing/promos/* /vouchers/redeem
app.use(createAvailabilityRouter(webshopDb));  // /availability/* /calendar/*
app.use(createNetworkRouter(webshopDb));       // /tours /offices /delivery-fees/*
app.use(createOrdersRouter(webshopDb));        // /orders
app.use(createAuthRouter(webshopDb));          // /auth/* (bcrypt + token)
app.use(createPaymentsRouter(webshopDb));      // /payments/* (Stripe)

const port = Number(process.env.PORT || 3002);
if (import.meta.url === `file://${process.argv[1]}`) {
  app.listen(port, () => console.log(`Buddy API (ws_ schema) listening on :${port}`));
}
