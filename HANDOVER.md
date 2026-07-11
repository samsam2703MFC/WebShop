# 🤝 Developer Handover — L'Atelier By Webshop

**For:** the incoming developer / IT person
**Repo:** https://github.com/samsam2703MFC/WebShop
**Working branch:** `feat/multi-shop-routing` (all the work below lives here, not `main`)
**Status:** all code is written & tested. What remains is **deployment on the client's hosting**.

> ## ✅ CHOSEN ARCHITECTURE — full React + PHP, on the client's server, **NO WooCommerce**
> The whole site runs on the client's shared hosting: **static React frontend + `php-api/`
> (PHP) + MySQL `ws_`**. No Node.js, no VPS, no WooCommerce (it has been removed from the
> repo). Serve the React files and the PHP API from the **same server** (same origin → no
> CORS or mixed-content issues).

---

## 1. What this project is

A multi-shop ("franchise") food webshop for **L'Atelier By** (bakery/lunch, Belgium).
Several shops (Halle, Corbais, Gosselies, Sombreffe, Gembloux), each with its own
per-shop pricing, per-day production stock, B2B office delivery (tours/offices),
availability rules, and a 4+1 cross-portion promo.

## 2. Architecture (decided — please don't re-litigate)

```
┌───────────────────────────┐     HTTPS/JSON      ┌──────────────────────────┐
│  React frontend (PWA)      │  ───────────────▶   │  PHP API  (php-api/)      │
│  (GitHub Pages, Vite build)│                     │  reads the ws_ database   │
└───────────────────────────┘                     └────────────┬─────────────┘
                                                                │ PDO (localhost)
                                                   ┌────────────▼─────────────┐
                                                   │  MySQL  "ws_" schema      │
                                                   │  = MASTER (33 tables)     │
                                                   └──────────────────────────┘
```

- **The `ws_` MySQL database is the single source of truth** (catalogue, per-shop
  price, per-day stock, menus, B2B network, customers, orders) — 33 tables.
- **The API is PHP** (`php-api/`), because the client is on **shared hosting** (no SSH,
  no Node.js). It runs on any PHP 8+ host with the DB local.
- **The frontend is React** (Vite build, installable PWA) served from GitHub Pages. It
  switches from demo data to the live API by setting one variable (`BASE_URL` in `api-config.js`).
- **WooCommerce is NOT used** — the React + PHP stack replaces it entirely, and the
  WooCommerce code has been removed from the repo.

## 3. Current status

| Component | Status |
|---|---|
| Database schema (`backend/schema/ws_schema.sql`, 33 tables) | ✅ ready to import |
| Seed of the 5 real shops (`backend/schema/seed-shops.sql`) | ✅ ready to import |
| **PHP API** (`php-api/`) — all endpoints, auth, payments | ✅ written & tested |
| Back-office (`php-api/admin/`) — products, prices, stock, orders | ✅ written |
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
Enter the ~27 products via the back-office (`php-api/admin/`), or bulk-import an
INSERT script in phpMyAdmin (one row per product × shop into `ws_products` /
`ws_product_prices` / `ws_product_stock`).

Then fill the rest of the franchise data: `ws_product_prices`,
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

### Step 6 — Back-office, emails & other built-ins
Everything a shop platform needs (catalogue, cart, orders, payment, customer accounts)
is handled by the React frontend + `php-api/`. Notable built-ins:

- ✅ **Admin back-office** — `php-api/admin/index.html` (open `https://<domain>/api/admin/`).
  Manage products, per-shop prices, daily stock, and order statuses. Protected by
  `admin_token` (set it in `config.php`). Admin endpoints: `/admin/products`,
  `/admin/price`, `/admin/stock`, `/admin/orders`, `/admin/orders/:id/status`.
- ✅ **Order-confirmation emails** — sent on `POST /orders` via PHP `mail()`
  (set `mail_from` in `config.php`; pass `email` in the order or use the logged-in
  customer). Best-effort: an email failure never blocks the order.
- ⚠️ Still Stripe-only for payments (no other gateways / no refunds UI) + no PDF
  invoice yet — add later if needed.

## 5. Key files & where things are

| Path | What |
|---|---|
| `php-api/` | **The backend API** (PHP). `index.php` = all routes; `config.php` = credentials. |
| `backend/schema/ws_schema.sql` | The 33-table `ws_` schema (canonical). |
| `backend/schema/seed-shops.sql` | The 5 shops. |
| `backend/schema/api-queries.sql` | Reference SQL for every endpoint. |
| `DEPLOY.md` / `DEPLOY.pl.md` | Step-by-step deployment runbook (EN / PL). |
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

- **Shared hosting = PHP API.** `php-api/` runs on any PHP 8+ host — no Node.js,
  no process manager, no VPS needed.
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
`config.php` → point `api-config.js` at the API URL → push.** Optionally add Stripe.
Estimated effort: a few hours, mostly data entry + hosting config.

Questions about any endpoint or table: the SQL in `backend/schema/api-queries.sql` and
the code in `php-api/index.php` are the reference.
