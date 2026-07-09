# 🤝 Developer Handover — L'Atelier By Webshop

**For:** the incoming developer / IT person
**Repo:** https://github.com/samsam2703MFC/WebShop
**Working branch:** `feat/multi-shop-routing` (all the work below lives here, not `main`)
**Status:** all code is written & tested. What remains is **deployment on the client's hosting**.

---

## 1. What this project is

A multi-shop ("franchise") food webshop for **L'Atelier By** (bakery/lunch, Belgium).
Several shops (Halle, Corbais, Gosselies, Sombreffe, Gembloux), each with its own
per-shop pricing, per-day production stock, B2B office delivery (tours/offices),
availability rules, and a 4+1 cross-portion promo.

## 2. Architecture (decided — please don't re-litigate)

```
┌───────────────────────────┐     HTTPS/JSON      ┌──────────────────────────┐
│  React frontend           │  ───────────────▶   │  PHP API  (php-api/)      │
│  (GitHub Pages, no build) │                     │  reads the ws_ database   │
└───────────────────────────┘                     └────────────┬─────────────┘
                                                                │ PDO (localhost)
                                                   ┌────────────▼─────────────┐
                                                   │  MySQL  "ws_" schema      │
                                                   │  = MASTER (33 tables)     │
                                                   └────────────┬─────────────┘
                              optional sync (push prices/stock, pull orders)
                                                   ┌────────────▼─────────────┐
                                                   │  WooCommerce (atelierby   │
                                                   │  .online) — sales engine  │
                                                   └──────────────────────────┘
```

- **The `ws_` MySQL database is the single source of truth** (catalogue, per-shop
  price, per-day stock, menus, B2B network, customers, orders). It has 33 tables and
  encodes things WooCommerce cannot represent natively.
- **The API is PHP** (`php-api/`), because the client is on **shared hosting** (no SSH,
  no Node.js). It runs on any PHP 8+ host with the DB local.
  *(A Node.js equivalent exists in `backend/` — use it only if you move to a VPS.)*
- **The frontend is static React** served from GitHub Pages. It switches from demo
  data to the live API by setting one variable (`BASE_URL` in `api-config.js`).
- **WooCommerce is optional** — a sales/checkout engine that can be kept in sync.

## 3. Current status

| Component | Status |
|---|---|
| Database schema (`backend/schema/ws_schema.sql`, 33 tables) | ✅ ready to import |
| Seed of the 5 real shops (`backend/schema/seed-shops.sql`) | ✅ ready to import |
| **PHP API** (`php-api/`) — all endpoints, auth, payments | ✅ written & tested |
| WooCommerce → DB product importer (`backend/sync/import-csv.js` + `.../tools/wc-csv-to-ws-sql.mjs`) | ✅ tool ready |
| WooCommerce sync push/pull + bridge plugin (`woocommerce-bridge/`) | ✅ ready (optional) |
| Frontend live wiring (`api-config.js`) | ⏳ **needs the API URL** |
| Deployment on the client's hosting | ⏳ **TO DO (your job)** |

## 4. What remains to do — step by step

### Step 1 — Database (in phpMyAdmin)
1. In the existing database (`test-webshop_db`), **Import** `backend/schema/ws_schema.sql`
   → creates the 33 tables. *(Note: `portion` is backticked because it's a MariaDB
   reserved word — keep it.)*
2. **Import** `backend/schema/seed-shops.sql` → the 5 shops (IDs are the Franchise
   Buddy IDs: Halle=4, Corbais=2, Gosselies=3, Sombreffe=5, Gembloux=10).

### Step 2 — Load the catalogue
The client already has ~27 products in WooCommerce (atelierby.online). Two ways:
- **Generate SQL from a WooCommerce CSV export** (no Node needed):
  `node woocommerce-bridge/tools/wc-csv-to-ws-sql.mjs export.csv 2 > import.sql`
  then import `import.sql` in phpMyAdmin (`2` = shop id Corbais).
- Or run the direct importer if Node is available: `npm run import:csv -- export.csv 2`.

Then fill the franchise data that WooCommerce doesn't hold: `ws_product_prices`,
`ws_product_stock`, `ws_slots`, `ws_calendar_rules`, `ws_shop_availability`,
`ws_offices`, `ws_tours`, `ws_delivery_fee_rules`, `ws_pricing_rules`, `ws_vouchers`.
Reference queries: `backend/schema/api-queries.sql`.

### Step 3 — Deploy the PHP API (this is the backend)
1. Upload the `php-api/` folder to the hosting (e.g. `public_html/api/`).
2. Edit `php-api/config.php` with the real DB credentials:
   ```php
   'host' => 'localhost',
   'name' => 'test-webshop_db',
   'user' => '...',
   'pass' => '...',            // the real DB password
   'auth_secret' => '...'      // a long random string (signs session tokens)
   ```
3. Test in a browser: `https://<domain>/api/shops` → must return the shops as JSON.
   - The API needs `mod_rewrite` (for the included `.htaccess`) and the
     `Authorization` header passed to PHP (handled by the `.htaccess`; some hosts
     also need `CGIPassAuth On` or PHP running as FastCGI).

### Step 4 — Wire the frontend
In `api-config.js` set:
```js
const BASE_URL = 'https://<domain>/api';
```
Commit & push → GitHub Pages redeploys automatically. The storefront now reads the
real database. **The frontend must be HTTPS-to-HTTPS** (GitHub Pages is HTTPS, so the
API must be HTTPS too — use the hosting's SSL certificate).

### Step 5 — Payments (optional, when ready)
Put a Stripe secret key in `php-api/config.php` (`'stripe_secret' => 'sk_live_…'`).
`POST /payments/checkout` then returns a Stripe Checkout URL. Without a key it returns
503 and the rest of the API keeps working.

### Step 6 — WooCommerce sync (optional)
If they keep WooCommerce as the sales engine: install the plugin
`woocommerce-bridge/` (zip provided), set a shared `atelier_sync_token` on both sides,
and schedule `sync:push` (prices/stock → Woo) and `sync:pull` (orders → DB).
See `WOOCOMMERCE.md` and `GO_LIVE.md`.

## 5. Key files & where things are

| Path | What |
|---|---|
| `php-api/` | **The backend API** (PHP). `index.php` = all routes; `config.php` = credentials. |
| `backend/schema/ws_schema.sql` | The 33-table `ws_` schema (canonical). |
| `backend/schema/seed-shops.sql` | The 5 shops. |
| `backend/schema/api-queries.sql` | Reference SQL for every endpoint. |
| `woocommerce-bridge/` | WordPress plugin (optional Woo integration) + CSV tools. |
| `backend/` (Node) | Node.js version of the API — **only if you move to a VPS**. |
| `GO_LIVE.md`, `WOOCOMMERCE.md` | Deployment runbooks. |
| `api-config.js` | Frontend: set `BASE_URL` here to go live. |

## 6. API endpoints (served by `php-api/`)

```
GET  /shops                          GET  /brand?shopId=
GET  /catalog/categories?shopId=     GET  /catalog/products?shopId=
GET  /catalog/stock?shopId=&mode=
GET  /availability/settings|days     GET  /calendar/slots|cutoff|exceptions
GET  /pricing/promos/cross-portion   POST /vouchers/redeem
GET  /tours  /offices  /offices/:id  POST /delivery-fees/quote
POST /orders                         GET  /orders/:id
POST /auth/register|login   GET/PATCH /auth/me
POST /payments/checkout
```
- Prices/totals are always computed **server-side** (client values are ignored).
- Passwords use PHP `password_hash`/`password_verify` (bcrypt). Sessions are a signed
  HMAC bearer token (`Authorization: Bearer <token>`) — no session table.

## 7. Important notes / gotchas

- **Shared hosting = PHP API.** Do NOT try to run the Node `backend/` there; it needs
  Node.js + a process manager (VPS only).
- **The DB must stay local to the API** (PDO `localhost`). On shared hosting MySQL is
  usually not reachable from outside, which is why the API runs on the same host.
- **Secrets never go in Git.** `config.php` credentials, Stripe keys, and the DB
  password live only on the server. `.env` is git-ignored.
- 🔴 **Rotate the database password** — it was shared in plaintext during setup; please
  change it in phpMyAdmin and update `config.php`.
- Multi-shop: every API call takes `shopId` (the integer shop id, e.g. 2 = Corbais).

## 8. Handoff summary

Everything is coded and tested. To go live you (the developer) need to:
**import the schema + shops → load the catalogue → upload `php-api/` and set
`config.php` → point `api-config.js` at the API URL → push.** Optionally add Stripe
and the WooCommerce sync. Estimated effort: a few hours, mostly data entry + hosting
config.

Questions about any endpoint or table: the SQL in `backend/schema/api-queries.sql` and
the code in `php-api/index.php` are the reference.
