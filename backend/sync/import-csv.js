/* Direct WooCommerce CSV → ws_ import (no intermediate .sql).
 *
 * Reads a WooCommerce product export CSV and upserts it straight into the DB:
 * categories (lookup-or-create, no duplicates), products (keyed by the Woo ID),
 * per-shop availability, and allergens. Idempotent — safe to re-run.
 *
 * Usage:  npm run import:csv -- <export.csv> [shopId]
 *   shopId defaults to 2 (Corbais / atelierby.online).
 *
 * DB connection comes from backend/.env (WEBSHOP_DB_*), same as the server.
 */
import fs from 'node:fs';
import { webshopDb } from '../src/db.js';

const [csvPath, shopArg] = process.argv.slice(2);
if (!csvPath) { console.error('usage: node sync/import-csv.js <export.csv> [shopId]'); process.exit(1); }
const SHOP_ID = Number(shopArg || 2);

/* Minimal RFC-4180 CSV parser: quotes, "" escapes, commas-in-quotes, CRLF. */
function parseCSV(text) {
  const rows = []; let row = [], field = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) { if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; } else field += c; }
    else if (c === '"') q = true;
    else if (c === ',') { row.push(field); field = ''; }
    else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
    else if (c !== '\r') field += c;
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}
const money = (v) => { const n = Number(String(v ?? '').replace(',', '.').trim()); return Number.isFinite(n) ? n : 0; };
const bool = (v) => (String(v).trim() === '1' ? 1 : 0);
const slug = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/* Lookup-or-create a category for this shop; cached so we hit the DB once per slug. */
const catCache = new Map();
let catsCreated = 0;
async function categoryId(s, label) {
  if (!s) return null;
  if (catCache.has(s)) return catCache.get(s);
  const [[row]] = await webshopDb.query(
    'SELECT id FROM ws_categories WHERE shop_id = ? AND slug = ? LIMIT 1', [SHOP_ID, s]);
  let id = row?.id;
  if (!id) {
    const [res] = await webshopDb.query(
      'INSERT INTO ws_categories (shop_id, slug, label, active) VALUES (?,?,?,1)', [SHOP_ID, s, label]);
    id = res.insertId;
    catsCreated++;
  }
  catCache.set(s, id);
  return id;
}

async function main() {
  const rows = parseCSV(fs.readFileSync(csvPath, 'utf8')).filter((r) => r.length > 1);
  const header = rows.shift();
  const idx = Object.fromEntries(header.map((h, i) => [h.trim(), i]));
  const at = (r, name) => r[idx[name]] ?? '';

  let products = 0, allergens = 0;

  for (const r of rows) {
    const id = Number(at(r, 'ID'));
    if (!id) continue;

    const label = at(r, 'Catégories').split(',')[0].trim();
    const catId = label ? await categoryId(slug(label), label) : null;

    await webshopDb.query(
      `INSERT INTO ws_products (id, cat_id, name, description, price, portions, cross_portion, active)
       VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE cat_id=VALUES(cat_id), name=VALUES(name), description=VALUES(description),
         price=VALUES(price), portions=VALUES(portions), cross_portion=VALUES(cross_portion), active=VALUES(active)`,
      [id, catId, at(r, 'Nom'), at(r, 'Description'), money(at(r, 'Tarif régulier')),
       bool(at(r, 'Méta : _atelier_portions')), bool(at(r, 'Méta : _atelier_cross_portion')),
       bool(at(r, 'Publié'))]
    );

    await webshopDb.query(
      `INSERT INTO ws_product_shops (product_id, shop_id, no_delivery, active) VALUES (?,?,?,1)
       ON DUPLICATE KEY UPDATE no_delivery=VALUES(no_delivery)`,
      [id, SHOP_ID, bool(at(r, 'Méta : _atelier_no_delivery'))]
    );

    await webshopDb.query('DELETE FROM ws_product_allergens WHERE product_id = ?', [id]);
    const raw = at(r, 'Méta : _atelier_allergens').trim();
    if (raw) {
      let list = [];
      try { list = JSON.parse(raw); } catch { /* skip */ }
      for (const a of list) {
        await webshopDb.query(
          'INSERT IGNORE INTO ws_product_allergens (product_id, allergen) VALUES (?,?)', [id, a]);
        allergens++;
      }
    }
    products++;
  }
  console.log(`✓ importé : ${products} produits, ${catsCreated} catégories créées, ${allergens} allergènes → shop ${SHOP_ID}`);
}

main()
  .then(() => webshopDb.end())
  .then(() => process.exit(0))
  .catch((e) => { console.error(e); process.exit(1); });
