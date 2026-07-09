/* Pull-side sync — validated orders WooCommerce → Buddy (this DB).
 *
 * Woo owns the checkout; Buddy consolidates orders after validation and, as
 * the stock master, decrements its own delivery_stock so the next push keeps
 * Woo aligned (absolute values → no double-decrement).
 *
 * Principles:
 *   * Cursor per shop in sync_state (`pull_orders:<shopId>`), a modified-at
 *     ISO timestamp; drained oldest-first, boundary re-pull is idempotent.
 *   * Upsert by order id; lines replaced each time (snapshot).
 *   * Master stock decremented ONCE per order (guarded by first-insert).
 *   * 1 Woo = 1 shop, so every pulled order belongs to that shop.
 *
 * Run:  npm run sync:pull
 */
import { webshopDb } from '../src/db.js';
import { logSync } from './lib.js';

const DEFAULT_SINCE = '1970-01-01T00:00:00Z';
const STATUSES = process.env.PULL_STATUSES || 'processing,completed';
const PAGE = 100;
const stateKey = (shopId) => `pull_orders:${shopId}`;

/* Woo status → ws_orders status enum. */
function mapStatus(s) {
  switch (s) {
    case 'processing': return 'paid';
    case 'completed':  return 'delivered';
    case 'on-hold':    return 'deferred_billing';
    case 'pending':    return 'pending_payment';
    case 'failed':     return 'payment_failed';
    case 'cancelled':
    case 'refunded':   return 'canceled';
    default:           return 'paid';
  }
}

async function getCursor(shopId) {
  const [[row]] = await webshopDb.query('SELECT v FROM sync_state WHERE k = ?', [stateKey(shopId)]);
  return row ? row.v : DEFAULT_SINCE;
}
async function setCursor(shopId, since) {
  await webshopDb.query(
    'INSERT INTO sync_state (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
    [stateKey(shopId), since]
  );
}
/* ISO8601 (with tz) → MySQL DATETIME in UTC ('YYYY-MM-DD HH:MM:SS'). */
function toMysqlDate(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d.toISOString().slice(0, 19).replace('T', ' ');
}

async function productIdBySku(sku) {
  if (!sku) return null;
  const [[row]] = await webshopDb.query('SELECT id FROM ws_products WHERE external_id = ?', [sku]);
  return row ? row.id : null;
}

/* Upsert one order + its lines. Returns true only on first insert (so stock is
   decremented exactly once). */
async function upsertOrder(shopId, o) {
  const [[existing]] = await webshopDb.query('SELECT id FROM ws_orders WHERE id = ?', [o.id]);
  const isNew = !existing;
  const t = o.totals;
  await webshopDb.query(
    `INSERT INTO ws_orders
       (id, shop_id, mode, status, customer_id, customer_email, customer_name, customer_phone,
        subtotal_ttc, discount_ttc, total_ttc, total_htva, total_tva, currency,
        stripe_payment_intent_id, paid_at, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE status=VALUES(status), total_ttc=VALUES(total_ttc),
       total_htva=VALUES(total_htva), total_tva=VALUES(total_tva),
       subtotal_ttc=VALUES(subtotal_ttc), discount_ttc=VALUES(discount_ttc),
       paid_at=VALUES(paid_at), updated_at=CURRENT_TIMESTAMP`,
    [o.id, shopId, o.mode || 'collect', mapStatus(o.status),
     o.customer?.id || null, o.customer?.email || null, o.customer?.name || null, o.customer?.phone || null,
     t.subtotal_ttc, t.discount_ttc, t.total_ttc, t.total_htva, t.total_tva,
     o.currency || 'EUR', o.stripe?.payment_intent || null, toMysqlDate(o.paid), toMysqlDate(o.created)]
  );

  // Lines are a snapshot: replace them on every upsert.
  await webshopDb.query('DELETE FROM ws_order_lines WHERE order_id = ?', [o.id]);
  for (const l of o.lines || []) {
    const pid = await productIdBySku(l.sku);
    if (!pid) continue; // product not in Buddy → skip line
    await webshopDb.query(
      `INSERT INTO ws_order_lines
         (order_id, product_id, name, qty, unit_price_ttc, vat_rate, line_ttc, line_htva, line_tva)
       VALUES (?,?,?,?,?,?,?,?,?)`,
      [o.id, pid, l.name, l.qty, l.unit_ttc, l.vat_rate ?? 0, l.line_ttc, l.line_htva, l.line_tva]
    );
    // Master stock: decrement once, only for tracked (non-NULL) stock.
    if (isNew) {
      await webshopDb.query(
        `UPDATE ws_product_shops SET delivery_stock = GREATEST(0, delivery_stock - ?)
          WHERE product_id = ? AND shop_id = ? AND delivery_stock IS NOT NULL`,
        [l.qty, pid, shopId]
      );
    }
  }
  return isNew;
}

/* Pull one shop, draining pages until caught up. */
export async function pullShop(shop) {
  let since = await getCursor(shop.id);
  let pulled = 0, inserted = 0, guard = 0;
  while (guard++ < 100) {
    const url = `${shop.woo_base_url.replace(/\/+$/, '')}/wp-json/atelier/v1/sync/orders`
      + `?since=${encodeURIComponent(since)}&statuses=${encodeURIComponent(STATUSES)}&limit=${PAGE}`;
    let resp, body;
    try {
      resp = await fetch(url, { headers: { 'X-Atelier-Sync-Token': shop.sync_token } });
      body = await resp.json().catch(() => ({}));
    } catch (e) {
      await logSync('event', 'pull', shop.id, 'error', `network: ${e.message}`);
      throw new Error(`pull ${shop.id} failed: ${e.message}`);
    }
    if (!resp.ok) {
      await logSync('event', 'pull', shop.id, 'error', `HTTP ${resp.status}`);
      throw new Error(`pull ${shop.id} failed: HTTP ${resp.status}`);
    }
    const orders = body.orders || [];
    for (const o of orders) { if (await upsertOrder(shop.id, o)) inserted++; }
    pulled += orders.length;
    if (body.next_since && body.next_since !== since) { since = body.next_since; await setCursor(shop.id, since); }
    if (orders.length < PAGE) break; // caught up
  }
  await logSync('event', 'pull', shop.id, inserted ? 'inserted' : 'skipped', `pulled=${pulled} new=${inserted}`);
  return { shop: shop.id, pulled, inserted };
}

/* Live, wired shops (same predicate as the push). */
export async function pullableShops() {
  const [rows] = await webshopDb.query(
    `SELECT id, name, woo_base_url, sync_token FROM ws_shops
      WHERE active = 1 AND status = 'live'
        AND woo_base_url IS NOT NULL AND sync_token IS NOT NULL`
  );
  return rows;
}

export async function pullAll() {
  const shops = await pullableShops();
  const results = [];
  for (const shop of shops) {
    try { results.push(await pullShop(shop)); }
    catch (e) { results.push({ shop: shop.id, error: e.message }); }
  }
  return results;
}

if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  pullAll()
    .then((r) => { console.log(JSON.stringify(r, null, 2)); })
    .then(() => webshopDb.end())
    .then(() => process.exit(0))
    .catch((e) => { console.error(e); process.exit(1); });
}
