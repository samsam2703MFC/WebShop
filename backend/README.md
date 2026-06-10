# Webshop Backend — API + Sync + Stripe

Node 22 / Express / MySQL. Serves the endpoint contracts documented in
`../API.md` from the **webshop dedicated database**, which is fed from the
general ERP database (**Franchise Buddy**) by a near-real-time one-way sync.
Customer payments run through **Stripe hosted Checkout** (cards + Bancontact).

```
Franchise Buddy (MySQL)          webshop DB (MySQL)            GitHub Pages
┌──────────────────────┐  sync   ┌────────────────┐   HTTPS    ┌────────────┐
│ fb_articles, fb_stock│ ─────▶  │ ws_products,…  │ ◀───────── │ storefront │
│ fb_outbox (triggers) │ worker  │ ws_orders      │  this API  └────────────┘
└──────────────────────┘  1 s    └────────────────┘                │
        ▲ only the outbox is touched      ▲                        ▼
        │ — never business tables         └── webhooks ◀──── Stripe Checkout
```

## Setup

```bash
cd backend
npm install
cp .env.example .env        # fill in DB credentials + Stripe keys
npm run migrate             # 001 webshop schema · 002 demo ERP (dev only) · 003 outbox · 004 seed
npm run sync:full           # initial load general DB → webshop DB
npm start                   # API on :3001
npm run sync:worker         # near-real-time outbox consumer (run as a service)
```

Production processes (systemd/pm2): `npm start` and `npm run sync:worker`,
plus an hourly cron for `npm run sync:reconcile`.

## Sync (Phase 2)

- **Architecture: trigger-based outbox** (chosen over Debezium/binlog CDC —
  one-way feed of 5 entities; a 1 s poll meets the seconds-level latency
  target without operating a Kafka/Connect cluster).
- `migrations/003_general_db_outbox.sql` is the **only change ever applied to
  the general DB** (must be approved/run by the ERP DBA in production).
- Events carry no payload — the worker re-reads the current source row, so
  duplicates and out-of-order events converge to the latest state.
- Idempotent upserts keyed on `external_id` (SKU / code).
  **Deactivation, never hard delete.**
- Field mapping lives in `sync/field-mapping.json` — not in code.
- Synced scope: products, categories, boutiques, per-boutique stock/price,
  promotions. Costing/suppliers/royalties/HR/accounting are never read.
- Monitoring: `GET /sync/status` (worker heartbeat, outbox backlog,
  24 h action counts, recent errors) backed by `sync_log` / `sync_state`.
- Recovery: `npm run sync:full` (initial load / disaster recovery),
  `npm run sync:reconcile` (hourly drift detection + repair).

## Stripe (Phase 3)

- **Hosted Checkout** (vs embedded Payment Element): Bancontact + cards out
  of the box, zero card data on our origin (PCI SAQ A), Stripe handles
  3DS/SCA, and the redirect flow suits a static-hosted storefront.
- `POST /orders`: server-side quote (client prices/totals are **ignored**),
  order persisted as `pending_payment`, Checkout Session created, customer
  redirected. Deferred B2B sites skip Stripe → `deferred_billing`.
- `POST /stripe/webhook`: signature-verified (raw body), **idempotent** via
  `ws_stripe_events` (Stripe retries are skipped), guarded transitions
  (a paid order can never be un-paid by a late event).
  Handled: `checkout.session.completed`, `…async_payment_succeeded` (Bancontact
  settles asynchronously), `…async_payment_failed`, `…expired`,
  `payment_intent.succeeded|payment_failed|canceled`.
- VAT: per-product `vat_rate` synced from the ERP (BE 6 % food / 21 %
  standard); HTVA/TVA split stored on every order line and order.
- Card data is never stored — only `stripe_payment_intent_id` / session id.

### End-to-end test procedure (test mode)

```bash
# 1. Put test keys in .env (sk_test_…, whsec_… from `stripe listen`)
stripe listen --forward-to localhost:3001/stripe/webhook
# 2. Place an order through the storefront (BASE_URL → this API)
# 3. Pay with 4242 4242 4242 4242 (card) — order → paid
#    Pay with Bancontact test flow      — order → paid via async_payment_succeeded
#    Use card 4000 0000 0000 0002       — declined → order → payment_failed
# 4. Verify: GET /orders/:id and the ws_stripe_events table
```

## Tests

```bash
npm test    # 14 tests: sync mapping/idempotency/deactivation/out-of-order,
            # webhook idempotency + status guards, VAT split, fee resolution
```

Tests run against the local dev databases (`npm run migrate` first).

## Env vars

See `.env.example`. Names only: `WEBSHOP_DB_*`, `GENERAL_DB_*`, `PORT`,
`CORS_ORIGINS`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`,
`CHECKOUT_SUCCESS_URL`, `CHECKOUT_CANCEL_URL`, `SYNC_POLL_MS`, `SYNC_BATCH_SIZE`.
Secrets live only in `.env` (gitignored) / the host's secret store — never in
the repo, never in the static frontend.
