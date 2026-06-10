/* Webshop API server — serves the contracts documented in ../API.md
   from the webshop MySQL DB (which is fed by the Phase 2 sync).
   The frontend switches from demo seeds to this API by setting
   BASE_URL in api-config.js — no frontend changes needed. */
import express from 'express';
import crypto from 'node:crypto';
import { config } from './config.js';
import { webshopDb, generalDb } from './db.js';
import { quote, resolveDeliveryFee, applyVoucher } from './pricing.js';
import {
  stripeEnabled, createCheckoutSession, verifyWebhook, handleStripeEvent,
} from './stripe.js';

export const app = express();

/* CORS — storefront is on GitHub Pages, API elsewhere. */
app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (origin && (config.corsOrigins.includes(origin) || config.corsOrigins.includes('*'))) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Credentials', 'true');
    res.set('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token');
    res.set('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
  }
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

/* Stripe webhook needs the RAW body for signature verification —
   register it before express.json(). */
app.post('/stripe/webhook', express.raw({ type: 'application/json' }), async (req, res) => {
  let event;
  try {
    event = verifyWebhook(req.body, req.headers['stripe-signature']);
  } catch (e) {
    return res.status(400).json({ error: `Webhook signature verification failed` });
  }
  try {
    const result = await handleStripeEvent(event);
    res.json({ received: true, ...result });
  } catch (e) {
    console.error('webhook handler error:', e);
    res.status(500).json({ error: 'handler error' }); // 5xx → Stripe retries
  }
});

app.use(express.json());

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  if (e && e.status) return res.status(e.status).json({ error: e.message });
  console.error(e);
  res.status(500).json({ error: 'Erreur interne' });
});

/* ── Health & sync monitoring ──────────────────────────────────────── */
app.get('/health', wrap(async (_req, res) => {
  await webshopDb.query('SELECT 1');
  res.json({ ok: true });
}));

app.get('/sync/status', wrap(async (_req, res) => {
  const [state] = await webshopDb.query('SELECT k, v, updated_at FROM sync_state');
  const [last24h] = await webshopDb.query(
    `SELECT entity, action, COUNT(*) n FROM sync_log
     WHERE created_at > NOW() - INTERVAL 24 HOUR GROUP BY entity, action`
  );
  const [errors] = await webshopDb.query(
    `SELECT entity, external_id, detail, created_at FROM sync_log
     WHERE action = 'error' AND created_at > NOW() - INTERVAL 24 HOUR
     ORDER BY id DESC LIMIT 20`
  );
  let outboxPending = null;
  try {
    const [[{ n }]] = await generalDb.query('SELECT COUNT(*) n FROM fb_outbox WHERE processed_at IS NULL');
    outboxPending = n;
  } catch { /* general DB may be unreachable; status still useful */ }
  res.json({
    state: Object.fromEntries(state.map((s) => [s.k, s.v])),
    outbox_pending: outboxPending,
    last_24h: last24h,
    recent_errors: errors,
  });
}));

/* ── Shops ─────────────────────────────────────────────────────────── */
app.get('/shops', wrap(async (_req, res) => {
  const [rows] = await webshopDb.query(
    'SELECT id, name, address, accent, opening_hours, click_collect FROM ws_shops WHERE active = 1'
  );
  res.json(rows);
}));

/* ── Catalog ───────────────────────────────────────────────────────── */
app.get('/catalog/categories', wrap(async (_req, res) => {
  const [rows] = await webshopDb.query(
    'SELECT id, label, img FROM ws_categories WHERE active = 1 ORDER BY sort_order'
  );
  res.json(rows);
}));

app.get('/catalog/products', wrap(async (req, res) => {
  const { shopId } = req.query;
  if (!shopId) return res.status(400).json({ error: 'shopId requis' });
  const [rows] = await webshopDb.query(
    `SELECT p.id, p.cat, p.name, p.description,
            COALESCE(ps.price_override, p.price) AS price,
            p.vat_rate, p.img, p.allergens, p.portions,
            p.cross_portion AS crossPortion, p.has_menu_options,
            p.no_delivery, ps.delivery_stock, p.lead_time
     FROM ws_products p
     JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ? AND ps.available = 1
     WHERE p.active = 1
     ORDER BY p.cat, p.name`,
    [shopId]
  );
  res.json(rows.map((r) => ({
    ...r,
    portions: !!r.portions, crossPortion: !!r.crossPortion,
    has_menu_options: !!r.has_menu_options, no_delivery: !!r.no_delivery,
  })));
}));

app.get('/catalog/assortments', wrap(async (_req, res) => res.json([])));
app.get('/catalog/bundles', wrap(async (_req, res) => res.json([])));

/* ── Pricing ───────────────────────────────────────────────────────── */
app.post('/pricing/quote', wrap(async (req, res) => {
  const { shopId, mode, basket, voucherCode, officeContext } = req.body || {};
  res.json(await quote({ shopId, mode, basket, voucherCode, officeContext }));
}));

app.get('/pricing/payment-methods', wrap(async (req, res) => {
  const { mode, siteId, officeClientId, tourneeId, shopId } = req.query;
  if (mode === 'delivery' && (siteId || officeClientId)) {
    const fee = await resolveDeliveryFee({ siteId, officeClientId, tourneeId, shopId, subtotal: 0 });
    if (fee.payment_type === 'deferred') {
      return res.json([{ id: 'deferred', label: 'Paiement différé', sub: 'Facturation mensuelle · paiement sur facture' }]);
    }
  }
  res.json([
    { id: 'bancontact', label: 'Bancontact', sub: 'Paiement instantané' },
    { id: 'visa', label: 'Carte bancaire', sub: 'Visa · Mastercard · Amex' },
  ]);
}));

app.get('/pricing/promos/cross-portion', wrap(async (_req, res) => {
  res.json({ active: true, buy: 4, free: 1, scope: 'crossPortion' });
}));

/* ── Vouchers ──────────────────────────────────────────────────────── */
app.post('/vouchers/redeem', wrap(async (req, res) => {
  const { code, shopId, subtotal } = req.body || {};
  const r = await applyVoucher((code || '').trim().toUpperCase(), { shopId, subtotal: Number(subtotal) || 0 });
  if (!r.ok) return res.json({ ok: false, message: r.message || 'Code invalide' });
  res.json({ ok: true, discount: r.discount, voucher: r.voucher, message: 'Code appliqué' });
}));

/* ── Delivery fees ─────────────────────────────────────────────────── */
app.post('/delivery-fees/quote', wrap(async (req, res) => {
  const { siteId, officeClientId, tourneeId, shopId, subtotal } = req.body || {};
  const fee = await resolveDeliveryFee({ siteId, officeClientId, tourneeId, shopId, subtotal: Number(subtotal) || 0 });
  let site = null;
  if (siteId) {
    const [[s]] = await webshopDb.query('SELECT * FROM ws_office_delivery_sites WHERE id = ?', [siteId]);
    site = s || null;
  }
  res.json({ ...fee, site });
}));

app.post('/delivery-fees/sites', wrap(async (req, res) => {
  const { officeClientId } = req.body || {};
  const [rows] = await webshopDb.query(
    'SELECT * FROM ws_office_delivery_sites WHERE office_client_id = ? AND active = 1',
    [officeClientId]
  );
  res.json(rows);
}));

/* ── Offices & tours ───────────────────────────────────────────────── */
app.get('/offices', wrap(async (_req, res) => {
  const [rows] = await webshopDb.query("SELECT * FROM ws_offices WHERE status = 'validated'");
  res.json(rows);
}));
app.get('/offices/:id', wrap(async (req, res) => {
  const [[row]] = await webshopDb.query('SELECT * FROM ws_offices WHERE id = ?', [req.params.id]);
  if (!row) return res.status(404).json({ error: 'Office introuvable' });
  res.json({ ...row, tourId: row.tour_id, defaultSiteId: row.default_site_id });
}));
app.get('/tours', wrap(async (_req, res) => {
  const [rows] = await webshopDb.query('SELECT id, name, shop_id AS shopId, time_window AS `window`, days FROM ws_tours WHERE active = 1');
  res.json(rows);
}));
app.get('/tours/:id', wrap(async (req, res) => {
  const [[row]] = await webshopDb.query(
    'SELECT id, name, shop_id AS shopId, time_window AS `window`, days FROM ws_tours WHERE id = ?', [req.params.id]);
  if (!row) return res.status(404).json({ error: 'Tournée introuvable' });
  res.json(row);
}));

/* ── Orders + Stripe Checkout ──────────────────────────────────────── */
app.post('/orders', wrap(async (req, res) => {
  const {
    shopId, mode, slot, basket, voucher, customer, delivery, date,
  } = req.body || {};

  // 1. Server-side pricing — client prices/totals are ignored entirely.
  const officeContext = delivery ? {
    siteId: delivery.office_delivery_site_id,
    officeClientId: delivery.office_client_id,
    tourneeId: delivery.tournee_id,
  } : {};
  const q = await quote({ shopId, mode, basket, voucherCode: voucher || null, officeContext });

  const paymentType = (mode === 'delivery' && q.delivery_fee) ? q.delivery_fee.payment_type : 'immediate';
  const orderId = 'ord-' + crypto.randomUUID();
  const initialStatus = paymentType === 'deferred' ? 'deferred_billing' : 'pending_payment';

  // 2. Persist order + lines.
  await webshopDb.query(
    `INSERT INTO ws_orders (id, shop_id, mode, status, slot_id, slot_label, order_date,
       customer_id, customer_email, customer_name, customer_phone,
       office_client_id, office_delivery_site_id, office_delivery_site_name,
       delivery_address, tournee_id, tournee_stop_id, delivery_mode,
       payment_type, delivery_fee_applied, delivery_fee_amount, free_delivery_minimum,
       subtotal_ttc, discount_ttc, voucher_code, total_ttc, total_htva, total_tva)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      orderId, shopId, mode, initialStatus,
      slot?.slotId || null, slot?.label || null, date || null,
      customer?.id || null, customer?.email || null,
      [customer?.firstName, customer?.lastName].filter(Boolean).join(' ') || null,
      customer?.phone || null,
      delivery?.office_client_id || null, delivery?.office_delivery_site_id || null,
      delivery?.office_delivery_site_name || null, delivery?.address || null,
      delivery?.tournee_id || null, delivery?.tournee_stop_id || null,
      delivery ? 'office_delivery' : null,
      paymentType,
      q.delivery_fee && q.delivery_fee.fee_amount > 0 ? 1 : 0,
      q.delivery_fee ? q.delivery_fee.fee_amount : 0,
      q.delivery_fee ? q.delivery_fee.free_delivery_minimum : 0,
      q.subtotal_ttc, q.discount_ttc, q.voucher ? q.voucher.code : null,
      q.total_ttc, q.total_htva, q.total_tva,
    ]
  );
  for (const l of q.lines) {
    await webshopDb.query(
      `INSERT INTO ws_order_lines (order_id, product_id, name, qty, \`portion\`, options,
         unit_price_ttc, vat_rate, line_ttc, line_htva, line_tva)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
      [orderId, l.productId, l.name, l.qty, l.portion, JSON.stringify(l.options),
       l.unit_price_ttc, l.vat_rate, l.line_ttc, l.line_htva, l.line_tva]
    );
  }

  // 3a. Deferred B2B: no online payment — confirmed for monthly invoicing.
  if (paymentType === 'deferred') {
    return res.json({ ok: true, orderId, status: 'deferred_billing', total: q.total_ttc, payment: 'deferred' });
  }

  // 3b. Immediate: Stripe hosted Checkout (cards + Bancontact).
  if (!stripeEnabled()) {
    return res.status(503).json({
      error: 'Paiement indisponible (Stripe non configuré)', orderId, status: 'pending_payment',
    });
  }
  const session = await createCheckoutSession({ orderId, quote: q, customerEmail: customer?.email });
  await webshopDb.query(
    'UPDATE ws_orders SET stripe_session_id = ? WHERE id = ?', [session.id, orderId]
  );
  res.json({ ok: true, orderId, status: 'pending_payment', total: q.total_ttc, checkoutUrl: session.url });
}));

app.get('/orders/:id', wrap(async (req, res) => {
  const [[order]] = await webshopDb.query('SELECT * FROM ws_orders WHERE id = ?', [req.params.id]);
  if (!order) return res.status(404).json({ error: 'Commande introuvable' });
  const [lines] = await webshopDb.query('SELECT * FROM ws_order_lines WHERE order_id = ?', [order.id]);
  delete order.stripe_session_id; // not for the client
  res.json({ ...order, lines });
}));

/* ── start ─────────────────────────────────────────────────────────── */
if (process.env.NODE_ENV !== 'test') {
  app.listen(config.port, () => console.log(`webshop API listening on :${config.port} (stripe: ${stripeEnabled() ? 'on' : 'OFF'})`));
}
