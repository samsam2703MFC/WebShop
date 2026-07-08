/*
 * Generate a WooCommerce product-import CSV from the storefront's mockup
 * catalog (W_PRODUCTS seed in webshop-full-bundle.jsx).
 *
 *   node woocommerce-bridge/tools/generate-products-csv.mjs
 *
 * Output: woocommerce-bridge/sample-products.csv — canonical WooCommerce
 * import columns + _atelier_* meta + Belgian 6% tax class. Verified against
 * WooCommerce 10.9 (26 products, 0 failures). Import via WooCommerce →
 * Products → Import.
 */
import fs from 'node:fs';

const bundlePath = new URL('../../webshop-full-bundle.jsx', import.meta.url).pathname;
const outPath = new URL('../sample-products.csv', import.meta.url).pathname;

const src = fs.readFileSync(bundlePath, 'utf8');
// Extract the W_PRODUCTS array literal (data only, no function calls).
const start = src.indexOf('const W_PRODUCTS = [');
const end = src.indexOf('\n];', start);
const arrText = src.slice(start + 'const W_PRODUCTS = '.length, end + 2);
// eslint-disable-next-line no-new-func
const W_PRODUCTS = Function('return ' + arrText)();

const CAT_NAME = {
  tarts: 'Tartes', plats: 'Plats', salades: 'Salades', sweet: 'Sucré',
  breads: 'Pains', vienn: 'Viennoiseries', sandwiches: 'Sandwiches',
};

const cols = [
  'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
  'Short description', 'Description', 'Regular price', 'Categories',
  'Tax status', 'Tax class',
  'Meta: _atelier_no_delivery', 'Meta: _atelier_lead_time',
  'Meta: _atelier_delivery_stock', 'Meta: _atelier_portions',
  'Meta: _atelier_cross_portion', 'Meta: _atelier_allergens',
];

const esc = (v) => {
  if (v === null || v === undefined) v = '';
  v = String(v);
  return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
};

const rows = [cols.join(',')];
for (const p of W_PRODUCTS) {
  const row = {
    'Type': 'simple',
    'SKU': 'WS-' + p.id,
    'Name': p.name,
    'Published': 1,
    'Is featured?': 0,
    'Visibility in catalog': 'visible',
    'Short description': p.description || '',
    'Description': p.description || '',
    'Regular price': p.price,
    'Categories': CAT_NAME[p.cat] || 'Divers',
    'Tax status': 'taxable',
    'Tax class': 'reduit-6', // all bakery/food = Belgian 6%
    'Meta: _atelier_no_delivery': p.no_delivery ? 1 : 0,
    'Meta: _atelier_lead_time': p.lead_time || 0,
    'Meta: _atelier_delivery_stock': p.delivery_stock ?? '',
    'Meta: _atelier_portions': p.portions ? 1 : 0,
    'Meta: _atelier_cross_portion': p.crossPortion ? 1 : 0,
    'Meta: _atelier_allergens': p.allergens ? JSON.stringify(p.allergens) : '',
  };
  rows.push(cols.map((c) => esc(row[c])).join(','));
}

fs.writeFileSync(outPath, rows.join('\n') + '\n');
console.log(`Generated ${W_PRODUCTS.length} products → ${outPath}`);
