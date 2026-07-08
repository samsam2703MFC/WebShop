# Atelier Webshop Bridge — WooCommerce plugin

Makes **WooCommerce the commerce engine** behind the existing React storefront,
*without changing the front-end*. The plugin exposes the same `window.WSXxx`
contracts (see `../API.md`) under `/wp-json/atelier/v1/`, so you only set
`BASE_URL` in `../api-config.js` and the storefront runs unchanged.

```
React storefront (GitHub Pages)
        │  api-config.js → https://shop.example/wp-json/atelier/v1
        ▼
Atelier Webshop Bridge  ──►  WooCommerce
  /catalog/products            products + tax classes (6% / 21%)
  /vouchers/redeem             coupons
  /pricing/quote               server-side price (client totals ignored)
  /orders                      WC orders + WooCommerce Stripe (card+Bancontact)
  /delivery-fees/*  ┐
  /pricing/quote B2B ├─ plugin tables (no WooCommerce equivalent):
  deferred payment  ┘   wp_atelier_delivery_sites, wp_atelier_fee_rules
```

## Why this split

WooCommerce already solves the standard commerce (products, coupons, VAT,
orders, refunds, admin UI, **Stripe cards + Bancontact** via the official
gateway). The **B2B layer is custom** — office delivery sites, per-site fee
priority (site → office → tournée → shop → global), deferred/monthly billing,
tournées — and has no WooCommerce equivalent, so the plugin owns those tables
and serves them itself.

## Install

1. Install & activate **WooCommerce** (≥ 8.0).
2. Install & activate the official **WooCommerce Stripe Gateway**, enable
   *card* + *Bancontact*, add your Stripe keys, register the webhook.
3. Copy this `woocommerce-bridge/` folder to
   `wp-content/plugins/atelier-webshop-bridge/` and activate it
   (creates the two B2B tables + demo seed on activation).
4. Configure WooCommerce for Belgium: country `BE`, currency `EUR`,
   *prices include tax* = yes, and two tax rates — standard **21 %** and a
   reduced **6 %** class (`reduit-6`) for food.
5. Point `../api-config.js`:
   `const BASE_URL = 'https://your-wp/wp-json/atelier/v1';`

## Product fields → WooCommerce

| Front-end field | WooCommerce source |
|---|---|
| `price` (TTC) | product price (prices-include-tax) |
| `vat_rate` | tax class → 6 (`reduit-6`) / 21 (standard) |
| `cat` | first product category slug |
| `no_delivery` | product meta `_atelier_no_delivery` |
| `lead_time` | product meta `_atelier_lead_time` |
| `delivery_stock` | product meta `_atelier_delivery_stock` |
| `portions` / `crossPortion` / `has_menu_options` | product meta `_atelier_*` |
| `allergens` | product meta `_atelier_allergens` (JSON) |

## Endpoints implemented

`GET /shops` · `GET /catalog/categories` · `GET /catalog/products` ·
`POST /pricing/quote` · `GET /pricing/payment-methods` ·
`GET /pricing/promos/cross-portion` · `POST /vouchers/redeem` ·
`POST /delivery-fees/quote` · `POST /delivery-fees/sites` · `GET /tours` ·
`POST /orders` · `GET /orders/:id`

## Money & tax

- `Atelier_Pricing::quote()` is the single source of truth — **client prices
  and totals are ignored** (same rule as the Node backend). Verified: a client
  that posts `total: 1.00` still gets charged the server-computed amount.
- The delivery fee from the quote is TTC; the plugin converts it to a net
  WooCommerce order-item fee so WC re-adds 21 % VAT and the **WC order total
  equals the quote total** exactly (verified €32.50 = €32.50).
- VAT split (HTVA/TVA) uses WooCommerce tax classes — 6 % food, 21 % standard.

## Orders & payment

- **Immediate** (collect, or delivery to an immediate-payment site): order is
  created `pending`, and `/orders` returns `checkoutUrl` = the WooCommerce
  order-pay page, which renders the Stripe **card + Bancontact** fields. The
  Stripe gateway handles PaymentIntents, SCA/3DS and webhooks.
- **Deferred B2B** (site `payment_type = deferred`): no online payment; the
  order is created `on-hold` and reported as `deferred_billing` for monthly
  invoicing.

## Not yet wired (intentionally out of scope here)

- **Auth / SSO** (`WSAuth`) — the PWA-to-webshop SSO in `uploads/spec.txt`
  belongs in a dedicated auth step; the bridge currently treats storefront
  reads as public and takes the customer identity from the order payload.
- **Availability slots** (`WSAvailability`) — the slot/cutoff engine can be
  ported to plugin routes the same way; not required for the commerce path.
- The **Franchise Buddy → WooCommerce sync** replaces the Node sync target:
  the worker upserts WooCommerce products (via `wc_get_product`/CRUD or the WC
  REST API) instead of the `ws_products` table. See `../WOOCOMMERCE.md`.
