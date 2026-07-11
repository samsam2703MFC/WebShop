# 🚀 Deployment Guide — L'Atelier By Webshop (for Szymon)

Everything is coded and tested. This guide takes the project from the current
state (frontend live in demo mode) to **full production** on the client's server.

- **Repo:** https://github.com/samsam2703MFC/WebShop — work on branch **`feat/multi-shop-routing`**; `main` is what gets deployed.
- **Live frontend:** https://samsam2703mfc.github.io/WebShop/ (currently demo data)

---

## 0. Architecture (what runs where)

```
┌────────────────────────┐   HTTPS/JSON   ┌───────────────────────┐   PDO/localhost   ┌──────────────┐
│  Frontend (React PWA)   │ ─────────────▶ │  PHP API  (php-api/)   │ ────────────────▶ │  MySQL  ws_  │
│  GitHub Pages (Actions) │                │  on the client server  │                   │  33 tables   │
└────────────────────────┘                └───────────────────────┘                   └──────────────┘
```

- **Frontend** = a **Vite-built PWA** (React). Hosted on **GitHub Pages**, auto-deployed by
  **GitHub Actions** on every push to `main` (`.github/workflows/deploy.yml`). Installable, offline app-shell.
- **Backend** = **`php-api/`** (plain PHP + PDO). Runs on the client's **shared hosting** (PHP 8+). No Node, no VPS.
- **Database** = the **`ws_` schema** (`backend/schema/ws_schema.sql`, 33 tables) = single source of truth.
- **WooCommerce is NOT used** (removed from the repo). `backend/` now holds only the SQL schema (`backend/schema/`).

---

## 1. Database (phpMyAdmin) ⏱️ ~5 min

1. Open phpMyAdmin, select the database (e.g. `test-webshop_db`).
2. **Import** `backend/schema/ws_schema.sql` → creates the 33 tables. It already
   contains **everything** (discounts, notes, company accounts, payment options, guest fields…).
   *(The `alter-*.sql` files are ONLY for a DB that already existed before those features. On a fresh DB, skip them.)*
3. **Import** `backend/schema/seed-shops.sql` → the 5 real shops (Halle=4, Corbais=2, Gosselies=3, Sombreffe=5, Gembloux=10).
4. ⚠️ `portion` is a MariaDB reserved word and is already backticked in the schema — leave it.

## 2. Backend — PHP API ⏱️ ~10 min

1. **Upload** the whole **`php-api/`** folder to the hosting, e.g. `public_html/api/`.
2. **Edit `php-api/config.php`** with the real values:
   ```php
   'db' => [
     'host' => 'localhost',            // MySQL is local to the hosting
     'port' => '3306',
     'name' => 'test-webshop_db',
     'user' => 'test_webshop_user',
     'pass' => '••••••',               // the real DB password
   ],
   'auth_secret'  => '••• long random •••',   // signs customer session tokens
   'admin_token'  => '••• long random •••',   // protects the back-office
   'cors_origins' => ['https://samsam2703mfc.github.io'],  // the frontend origin — REQUIRED
   'stripe_secret'=> '',               // sk_live_… later (Step 5)
   'mail_from'    => 'no-reply@atelierby.be',
   ```
3. **Requirements on the host:**
   - **HTTPS** on the API domain (mandatory — the frontend is HTTPS, browsers block mixed content).
   - **`mod_rewrite`** enabled (the included `.htaccess` routes all requests to `index.php`).
   - The `Authorization` header must reach PHP (handled by `.htaccess`; some hosts also need `CGIPassAuth On` or PHP as FastCGI).
4. **Test:** open `https://<api-domain>/api/shops` in a browser → must return the shops as JSON.

## 3. Connect the frontend to the backend ⏱️ ~2 min

1. Edit **`api-config.js`** at the repo root:
   ```js
   const BASE_URL = 'https://<api-domain>/api';   // e.g. https://atelierby.online/api
   ```
   *(that single line switches all `window.WSXxx` stubs from demo data to the live API.)*
2. Commit & push to **`main`** → **GitHub Actions rebuilds & redeploys** the PWA automatically (~1-2 min).
3. Reload https://samsam2703mfc.github.io/WebShop/ → it now shows **real data**.

> **CORS** must match: `cors_origins` in `config.php` must contain exactly `https://samsam2703mfc.github.io`.

## 4. Populate the data (back-office or phpMyAdmin) ⏱️ ongoing

Once connected, fill in the franchise data. Two ways: the **admin panel** at
`https://<api-domain>/api/admin/` (enter the `admin_token`), or phpMyAdmin.

| Data | Table / where |
|---|---|
| Products, per-shop price, daily stock | admin panel, or `ws_products` / `ws_product_prices` / `ws_product_stock` |
| Categories, bundles/menus | `ws_categories`, `ws_bundles` |
| Time slots, calendar cutoff, open days | `ws_slots`, `ws_calendar_rules`, `ws_shop_availability` |
| Per-product / per-mode lead time & cutoff | `ws_product_availability` (`*_lead_time`, `*_cutoff_override`, `*_enabled`) |
| B2B: offices, tours, delivery sites, fees | `ws_offices`, `ws_tours`, `ws_office_delivery_sites`, `ws_tour_availability`, `ws_delivery_fee_rules` |
| B2B: deferred billing + company e-mails | admin panel "Entreprises" tab → `ws_offices.deferred_billing_enabled`, `ws_office_emails` |
| **Payment methods per shop × profile** | admin panel → `ws_shop_payment_options` (profile guest/registered/company × method stripe/shop/deferred) |
| Cross-portion promo, webshop discount, vouchers | `ws_pricing_rules`, `ws_shops.webshop_discount_*`, `ws_vouchers` |

**Bulk catalogue import:** prepare an INSERT script for `ws_products` / `ws_product_prices` /
`ws_product_stock` (one row per product × shop) and import it in phpMyAdmin.
Reference SQL for every table and case: `backend/schema/api-queries.sql`.

## 5. Payments & extras (when ready)

- **Stripe:** put `sk_live_…` in `config.php` (`stripe_secret`). Add a webhook in Stripe →
  URL `https://<api-domain>/api/payments/webhook`, event `checkout.session.completed`. Card payment then works.
- **Order e-mails:** set `mail_from` in `config.php` (sent via PHP `mail()` on each order).
- **Cron:** none required. The PHP API reserves stock inline inside the order transaction
  (`SELECT … FOR UPDATE` on `ws_product_stock`), so there is no background job to schedule.

## 6. Verification checklist ✅

- [ ] `https://<api-domain>/api/shops` returns JSON (5 shops)
- [ ] `https://<api-domain>/api/catalog/products?shopId=2` returns products
- [ ] Admin panel opens at `/api/admin/` with the `admin_token`
- [ ] Frontend (after `BASE_URL` + redeploy) shows real shops & catalogue
- [ ] Place a test order → row appears in `ws_orders` (+ line in `ws_order_lines`)
- [ ] Guest checkout works; payment method list matches the shop×profile config
- [ ] (mobile) the site installs to the home screen and opens full-screen

## 7. Key facts & reference

- **Frontend redeploy** = push to `main` (Actions builds `dist/` → Pages). To change the API URL, edit `api-config.js`, push.
- **Local dev of the frontend:** `npm install && npm run dev` (Vite).
- **API endpoints:** `/shops /brand /catalog/* /catalog/stock /availability/* /calendar/* /pricing/promos/* /vouchers/redeem /payment-methods /tours /offices /delivery-fees/quote /companies /orders /auth/* /payments/* /admin/*`.
- **Security:** secrets live only in `config.php` on the server, never in Git. `.env`/`config.php` are git-ignored. 🔴 Rotate the DB password if it was shared in plaintext.
- **Full field-by-field SQL examples:** `backend/schema/api-queries.sql`.

---

**Minimum to be functional:** Steps 1 → 2 → 3. Then populate data (Step 4) and enable Stripe (Step 5).
Questions on any endpoint or table: the code is in `php-api/index.php` and the SQL in `backend/schema/`.
