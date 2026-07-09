/* WooCommerce product export CSV  →  ws_ INSERT SQL (Buddy master, INT schema).
 *
 * Usage:  node wc-csv-to-ws-sql.mjs <export.csv> [shopId] [catIdBase] > ws-import.sql
 *   shopId    INT id of the shop (default 2 = Corbais / atelierby.online)
 *   catIdBase starting INT id for created categories (default 100, avoids the
 *             1..N range used by hand-seeded categories)
 *
 * Mapping (Woo CSV column → ws_ table.column), matching ws_schema.sql (INT keys):
 *   ID                         → ws_products.id       (stable Woo↔Buddy key; UGS/SKU is empty)
 *   Nom                        → ws_products.name
 *   Description                → ws_products.description
 *   Tarif régulier ("14,5")    → ws_products.price     (FR decimal comma → dot)
 *   Publié                     → ws_products.active
 *   Catégories                 → ws_categories (slug, INT id) + ws_products.cat_id
 *   Méta : _atelier_portions       → ws_products.portions
 *   Méta : _atelier_cross_portion  → ws_products.cross_portion
 *   Méta : _atelier_no_delivery    → ws_product_shops.no_delivery
 *   Méta : _atelier_allergens (JSON) → ws_product_allergens (one row per allergen)
 */
import fs from 'node:fs';

const [csvPath, shopArg, baseArg] = process.argv.slice(2);
if (!csvPath) { console.error('usage: wc-csv-to-ws-sql.mjs <export.csv> [shopId] [catIdBase]'); process.exit(1); }
const SHOP_ID = Number(shopArg || 2);
const CAT_BASE = Number(baseArg || 100);

/* Minimal RFC-4180 CSV parser: quotes, "" escapes, commas-in-quotes, CRLF. */
function parseCSV(text) {
  const rows = []; let row = [], field = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
      else field += c;
    } else if (c === '"') q = true;
    else if (c === ',') { row.push(field); field = ''; }
    else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
    else if (c !== '\r') field += c;
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}

const sq = (v) => `'${String(v ?? '').replace(/'/g, "''")}'`;
const money = (v) => { const n = Number(String(v ?? '').replace(',', '.').trim()); return Number.isFinite(n) ? n : 0; };
const bool = (v) => (String(v).trim() === '1' ? 1 : 0);
const slug = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

const rows = parseCSV(fs.readFileSync(csvPath, 'utf8')).filter((r) => r.length > 1);
const header = rows.shift();
const idx = Object.fromEntries(header.map((h, i) => [h.trim(), i]));
const at = (r, name) => r[idx[name]] ?? '';

const catBySlug = new Map();   // slug → {id, label}
let nextCatId = CAT_BASE;
const products = [], prodShops = [], allergens = [];

for (const r of rows) {
  const id = Number(at(r, 'ID'));
  if (!id) continue;
  const label = at(r, 'Catégories').split(',')[0].trim(); // first category
  let catId = null;
  if (label) {
    const s = slug(label);
    if (!catBySlug.has(s)) catBySlug.set(s, { id: nextCatId++, label });
    catId = catBySlug.get(s).id;
  }
  products.push({
    id, catId,
    name: at(r, 'Nom'),
    desc: at(r, 'Description'),
    price: money(at(r, 'Tarif régulier')),
    portions: bool(at(r, 'Méta : _atelier_portions')),
    cross: bool(at(r, 'Méta : _atelier_cross_portion')),
    active: bool(at(r, 'Publié')),
  });
  prodShops.push({ id, no_delivery: bool(at(r, 'Méta : _atelier_no_delivery')) });
  const rawAll = at(r, 'Méta : _atelier_allergens').trim();
  if (rawAll) { try { for (const a of JSON.parse(rawAll)) allergens.push({ id, allergen: a }); } catch { /* skip */ } }
}

const out = [];
out.push('-- Generated from WooCommerce export by wc-csv-to-ws-sql.mjs');
out.push(`-- shop_id = ${SHOP_ID}, category ids from ${CAT_BASE}`);
out.push('SET NAMES utf8mb4;\n');

out.push(`-- Categories (${catBySlug.size}) — explicit INT ids from ${CAT_BASE}`);
out.push('INSERT INTO ws_categories (id, shop_id, slug, label, active) VALUES');
out.push([...catBySlug].map(([s, c]) => `  (${c.id}, ${SHOP_ID}, ${sq(s)}, ${sq(c.label)}, 1)`).join(',\n')
  + '\nON DUPLICATE KEY UPDATE label = VALUES(label), slug = VALUES(slug);\n');

out.push(`-- Products (${products.length}) — id = WooCommerce ID (stable key)`);
out.push('INSERT INTO ws_products (id, cat_id, name, description, price, portions, cross_portion, active) VALUES');
out.push(products.map((p) =>
  `  (${p.id}, ${p.catId ?? 'NULL'}, ${sq(p.name)}, ${sq(p.desc)}, ${p.price.toFixed(2)}, ${p.portions}, ${p.cross}, ${p.active})`
).join(',\n') + '\nON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), cat_id=VALUES(cat_id), portions=VALUES(portions), cross_portion=VALUES(cross_portion), active=VALUES(active);\n');

out.push('-- Availability per shop');
out.push('INSERT INTO ws_product_shops (product_id, shop_id, no_delivery, active) VALUES');
out.push(prodShops.map((p) => `  (${p.id}, ${SHOP_ID}, ${p.no_delivery}, 1)`).join(',\n')
  + '\nON DUPLICATE KEY UPDATE no_delivery = VALUES(no_delivery);\n');

out.push('-- Allergens (clean re-import for these products)');
out.push(`DELETE FROM ws_product_allergens WHERE product_id IN (${products.map((p) => p.id).join(',')});`);
if (allergens.length) {
  out.push('INSERT INTO ws_product_allergens (product_id, allergen) VALUES');
  out.push(allergens.map((a) => `  (${a.id}, ${sq(a.allergen)})`).join(',\n') + ';');
}

process.stdout.write(out.join('\n') + '\n');
console.error(`✓ ${products.length} produits, ${catBySlug.size} catégories, ${allergens.length} allergènes → shop ${SHOP_ID}`);
