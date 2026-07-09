# Integrating the storefront with WooCommerce

This documents the **WooCommerce integration option** for the L'Atelier By
webshop — chosen architecture: **headless WooCommerce + a WordPress plugin**
that speaks the storefront's `WSXxx` contracts. The React front-end is **not
modified**; it points `api-config.js` at the plugin's REST namespace.

> The plugin lives in [`woocommerce-bridge/`](woocommerce-bridge/) with its own
> README. This file is the architecture overview and decision record.

## The key enabler

The front-end talks only to `window.WSXxx` services, each with a swappable
`endpoint`. It does not know or care what is behind them. So "use WooCommerce
as the engine" = **implement the `WSXxx` contracts on top of WooCommerce**. No
front-end rewrite.

## What runs where

| Concern | Owner |
|---|---|
| Products, categories, prices, VAT (6 %/21 %) | **WooCommerce** |
| Coupons (= vouchers) | **WooCommerce** |
| Orders, refunds, admin UI | **WooCommerce** |
| Payments — cards + **Bancontact** | **WooCommerce Stripe Gateway** (official plugin) |
| Storefront API in `WSXxx` shapes | **Atelier Webshop Bridge** plugin |
| B2B: delivery sites, per-site fees, deferred billing, tournées | **Bridge plugin** (own tables) |
| Product feed from Franchise Buddy | **Sync worker**, target switched to WooCommerce |

## Why headless + plugin (vs. the alternatives)

- **Plugin (chosen):** one stack (WordPress), one deploy, secrets stay
  server-side, and the business gets WooCommerce's admin for products/orders/
  coupons/refunds for free. The custom B2B logic lives in PHP next to the data.
- **Node BFF calling WC REST:** keeps the Node backend but adds a second system
  to host and splits order/payment state across two apps.
- **Store API direct from the browser:** fine for a plain catalog, but can't
  express the B2B pricing (per-site fees, deferred payment) or the server-side
  price guarantee.
- **Native WooCommerce theme:** would throw away the custom React UI.

## Franchise Buddy → WooCommerce sync

The Phase-2 design is unchanged; only the **target** moves from the `ws_`
tables to WooCommerce:

- Outbox + triggers on the general DB stay exactly as in `backend/migrations/003`.
- The worker's `upsertTarget` writes a **WooCommerce product** instead of a
  `ws_products` row: match by SKU (`wc_get_product_id_by_sku`), set name/price/
  description/category, map VAT to the tax class (`reduit-6` / standard), and
  write the B2B flags to `_atelier_*` meta. Deactivation = set product status
  `draft` (never delete), same rule as before.
- `field-mapping.json` keeps its role; only the target column names change to
  WooCommerce product properties/meta keys.

## Multi-shop: Buddy master → per-shop Woo push

Each shop is its own WooCommerce site (WordPress Multisite). Buddy (the `ws_`
DB) is the master for **price and stock** and pushes **absolute values** to each
shop's bridge — one POST per shop, keyed by SKU:

```
POST {woo_base_url}/wp-json/atelier/v1/sync/products
  Header: X-Atelier-Sync-Token: <ws_shops.sync_token>   (== Woo option atelier_sync_token)
  Body:   { "items": [ { "sku": "CROIS-001", "price": 1.40, "stock": 120 }, … ] }
```

- **Absolute, not delta** → idempotent, replay-safe, self-healing. `/sync/stock`
  and `/sync/price` also exist for single-dimension pushes.
- The bridge (`Atelier_Sync`) resolves `SKU → Woo product` itself, sets
  `regular_price` (TTC) and `stock_quantity` (manage_stock on), and reports
  `{updated, missing}`. Promotions/sale prices are **not** touched.
- Sender: `backend/sync/push-woo.js` (`npm run sync:push`) — reads
  `COALESCE(price_override, price)` and `delivery_stock` per shop, skips shops
  without `woo_base_url`+`sync_token`, and logs to `sync_log` (entity `push`).
  `delivery_stock` NULL = unlimited → `stock` omitted (Woo left untouched).

Setup per shop: `PATCH /admin/shops/:id { woo_base_url, status:"live" }`, set
`ws_shops.sync_token`, and mirror it on Woo: `wp option update atelier_sync_token "<secret>"`.

## Verified end-to-end (against WooCommerce 10.9, WordPress, PHP 8.4)

Built and tested live in a real WordPress + WooCommerce install:

- `GET /catalog/products` → VAT derived per tax class (wine 21 %, food 6 %),
  `no_delivery` / `lead_time` from product meta.
- `POST /pricing/quote` → server-side totals with WooCommerce coupon
  `BIENVENUE10` and HTVA/TVA split.
- `POST /delivery-fees/quote` → priority **site → office → tournée → shop →
  global** (site €4.50 deferred; free above €40; global fallback €7).
- `POST /orders` (deferred B2B) → **real WooCommerce order**, client-sent
  `total: 1.00` ignored, WC order total = quote total = **€32.50**, B2B meta
  (site, tournée, payment_type) persisted, status `on-hold` → `deferred_billing`.
- `POST /orders` (immediate) → order `pending` + `checkoutUrl` to the WC
  order-pay page where Stripe renders card + Bancontact.

## Go-live delta vs. the Node backend

Most of `GO_LIVE.md` still applies. Differences:
- Host is WordPress (not a Node service); the storefront stays on GitHub Pages.
- Stripe is configured **in the WooCommerce Stripe Gateway** (keys + webhook in
  the WordPress admin), not in `.env`.
- Tax is configured as WooCommerce tax rates/classes (6 % `reduit-6`, 21 %
  standard) rather than a `vat_rate` column.
- The sync service points at WooCommerce; run it as a WP-CLI command or a small
  companion worker using the WC REST API.
