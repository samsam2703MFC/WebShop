import fs from 'node:fs';

const src = fs.readFileSync('new URL('../../webshop-full-bundle.jsx', import.meta.url).pathname', 'utf8');
// Extract the W_PRODUCTS array literal (data only, no function calls).
const start = src.indexOf('const W_PRODUCTS = [');
const endMarker = '\n];';
const end = src.indexOf(endMarker, start);
const arrText = src.slice(start + 'const W_PRODUCTS = '.length, end + 2); // include closing ]
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
  // Configurable products (bundles/options) import as simple base products.
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

const out = rows.join('\n') + '\n';
fs.writeFileSync(new URL('../sample-products.csv', import.meta.url).pathname, out);
console.log(`Generated ${W_PRODUCTS.length} products → /tmp/atelier-produits-woocommerce.csv`);
console.log('Categories:', [...new Set(W_PRODUCTS.map((p) => CAT_NAME[p.cat] || 'Divers'))].join(', '));
