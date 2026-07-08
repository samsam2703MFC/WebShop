# Franchise Buddy — Menus (Node/Express + MySQL)

Reference implementation to add configurable **menus** (options, bundles,
upsells) to Franchise Buddy and expose them in the products API, in the exact
shape the storefront consumes. See `../FRANCHISE_BUDDY_MENUS_API.md` (contract)
and `../DATABASE_MENUS.md` (model).

## 1. Create the tables

```bash
mysql your_fb_database < 001_menus.sql
```

Adjust `product_id` references if your products table isn't keyed by an INT id.

## 2. Serve menus nested in the products API

`menus.js` uses raw `mysql2` (works with any Node stack; maps 1:1 to
Prisma/Sequelize models if you use one). It batch-loads menu data in a few
queries (no N+1) and maps DB columns `code`/`price_delta`/`image` → API fields
`id`/`delta`/`img`.

```js
const mysql = require('mysql2/promise');
const { attachMenus, mountMenuRoutes } = require('./menus');
const pool = mysql.createPool({ /* your FB DB config */ });

// Enrich your existing products list/detail:
app.get('/api/v1/products/', async (req, res) => {
  const products = await loadProducts();          // your existing query
  await attachMenus(pool, products);              // adds options/available_bundles/upsells
  res.json(products);
});

// …or expose a standalone route:
mountMenuRoutes(pool, app);                        // GET /api/v1/products/:id/menus
```

Each product gains:
```jsonc
{
  "has_menu_options": true,
  "options": [ { "id", "label", "kind", "required", "choices": [ { "id", "label", "delta" } ] } ],
  "available_bundles": [ { "id", "name", "description", "price_modifier", "recommended",
                           "advantages": [], "slots": [ { "id", "label", "required",
                           "choices": [ { "id", "label", "img", "delta" } ] } ] } ],
  "upsells": [ { "id", "label", "img", "delta" } ]
}
```

## 3. Verified

Migration + assembly tested on MySQL: seeding *Sandwich Club* (options
bread/sauce, *Full Menu* with drink+dessert slots, salad upsell) produces the
contract JSON verbatim (field mapping + `advantages` JSON + `has_menu_options`).

## 4. Prices & order selection

Compute the line price **server-side** (never trust the client):
```
price + Σ delta(options) + price_modifier(bundle) + Σ delta(slot choices) + Σ delta(upsells)
```
The storefront returns the selection as
`{ bundleId, options:{…}, bundleSlots:{…}, upsells:[…] }` (see the contract doc)
— resolve those `id`/`code` values back against these tables when creating the
order.

## Adapting to an ORM

- **Prisma**: model each table; use nested `include` (options→choices,
  menus→slots→choices) instead of the manual grouping in `menus.js`.
- **Sequelize**: define `hasMany` associations and `include` the tree; then map
  to the API field names (`code→id`, `price_delta→delta`, `image→img`).
