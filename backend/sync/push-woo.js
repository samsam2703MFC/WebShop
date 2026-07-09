/* Push-side sync — the mirror of the ERP→webshop worker.
 *
 * This DB (the Buddy master) owns price & stock; WooCommerce is the
 * storefront. Here we PUSH absolute values to each shop's WooCommerce bridge
 * (POST /wp-json/atelier/v1/sync/products), keyed by SKU.
 *
 * Principles:
 *   * Absolute values (set, not delta) → idempotent, replay-safe, self-healing.
 *   * One POST per shop; the bridge resolves SKU → Woo product itself.
 *   * Per-shop auth (X-Atelier-Sync-Token = ws_shops.sync_token).
 *   * Shops without woo_base_url OR sync_token are skipped (not yet wired).
 *   * delivery_stock NULL = unlimited → stock omitted so Woo leaves it alone.
 *
 * Run:  npm run sync:push          (all live, wired shops)
 */
import { webshopDb } from '../src/db.js';
import { logSync } from './lib.js';

/* Live shops that are actually wired for push (URL + token present). */
export async function pushableShops() {
  const [rows] = await webshopDb.query(
    `SELECT id, name, woo_base_url, sync_token
       FROM ws_shops
      WHERE active = 1 AND status = 'live'
        AND woo_base_url IS NOT NULL AND sync_token IS NOT NULL`
  );
  return rows;
}

/* Absolute {sku, price, stock?} rows for one shop, keyed by SKU. */
export async function rowsForShop(shopId) {
  const [rows] = await webshopDb.query(
    `SELECT p.external_id AS sku,
            COALESCE(ps.price_override, p.price) AS price,
            ps.delivery_stock AS stock
       FROM ws_product_shops ps
       JOIN ws_products p ON p.id = ps.product_id
      WHERE ps.shop_id = ? AND ps.available = 1
        AND p.active = 1 AND p.external_id IS NOT NULL`,
    [shopId]
  );
  return rows.map((r) => {
    const item = { sku: r.sku, price: Number(r.price) };
    if (r.stock !== null && r.stock !== undefined) item.stock = Number(r.stock);
    return item;
  });
}

/* Push one shop. Returns a summary; logs to sync_log. */
export async function pushShop(shop) {
  const items = await rowsForShop(shop.id);
  if (!items.length) {
    await logSync('full', 'push', shop.id, 'skipped', 'no items');
    return { shop: shop.id, sent: 0, skipped: 'no items' };
  }
  const url = shop.woo_base_url.replace(/\/+$/, '') + '/wp-json/atelier/v1/sync/products';
  let resp, body;
  try {
    resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Atelier-Sync-Token': shop.sync_token },
      body: JSON.stringify({ items }),
    });
    body = await resp.json().catch(() => ({}));
  } catch (e) {
    await logSync('full', 'push', shop.id, 'error', `network: ${e.message}`);
    throw new Error(`push ${shop.id} failed: ${e.message}`);
  }
  if (!resp.ok) {
    await logSync('full', 'push', shop.id, 'error',
      `HTTP ${resp.status}: ${JSON.stringify(body).slice(0, 200)}`);
    throw new Error(`push ${shop.id} failed: HTTP ${resp.status}`);
  }
  const missing = Array.isArray(body.missing) ? body.missing.length : 0;
  await logSync('full', 'push', shop.id, 'updated',
    `sent=${items.length} updated=${body.updated ?? '?'} missing=${missing}`);
  return { shop: shop.id, sent: items.length, updated: body.updated, missing: body.missing || [] };
}

/* Push every wired shop. Never throws — collects per-shop errors. */
export async function pushAll() {
  const shops = await pushableShops();
  const results = [];
  for (const shop of shops) {
    try { results.push(await pushShop(shop)); }
    catch (e) { results.push({ shop: shop.id, error: e.message }); }
  }
  return results;
}

/* CLI entry. */
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  pushAll()
    .then((r) => { console.log(JSON.stringify(r, null, 2)); })
    .then(() => webshopDb.end())
    .then(() => process.exit(0))
    .catch((e) => { console.error(e); process.exit(1); });
}
