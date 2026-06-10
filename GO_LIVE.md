# GO-LIVE CHECKLIST — Webshop

Phase order: infrastructure → sync → payments → switch-over → monitoring.

## 1. Infrastructure
- [ ] Provision backend host (VPS / Railway / Fly.io …) with Node 22 + access to both MySQL instances
- [ ] DNS: `api.<your-domain>` → backend host
- [ ] SSL: HTTPS on the API (Let's Encrypt / platform TLS) — Stripe webhooks and the Pages frontend both require it
- [ ] Create production webshop MySQL DB; run `npm run migrate` (001 + 004 only — **never 002** in production)
- [ ] `.env` filled from `.env.example`; secrets in the host's secret store, never committed

## 2. General DB (Franchise Buddy) — requires ERP DBA approval
- [ ] Review + run `backend/migrations/003_general_db_outbox.sql` on the production ERP (outbox table + triggers — the only change to the general DB)
- [ ] Create a dedicated MySQL user for the sync: `SELECT` on source tables + `SELECT, UPDATE` on `fb_outbox` only
- [ ] Adjust `sync/field-mapping.json` to the real ERP table/column names

## 3. Initial sync
- [ ] `npm run sync:full` — initial load (products, categories, boutiques, stock, promotions)
- [ ] Spot-check: product count, prices TTC, VAT rates (6 % food / 21 % standard), accents/utf8mb4
- [ ] Start `npm run sync:worker` as a supervised service (systemd/pm2, auto-restart)
- [ ] Cron the reconcile job hourly: `0 * * * * cd /srv/backend && node sync/reconcile.js`
- [ ] Verify `GET /sync/status`: outbox backlog ≈ 0, no recent errors

## 4. Stripe
- [ ] Test mode end-to-end first: `stripe listen --forward-to …/stripe/webhook`, card 4242…, Bancontact test flow, declined card → `payment_failed`
- [ ] Register the production webhook endpoint in the Stripe Dashboard: `https://api.<domain>/stripe/webhook` — events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `payment_intent.payment_failed`
- [ ] Swap to live keys in `.env` (`sk_live_…`, live `whsec_…`); restart API
- [ ] Bancontact enabled in Stripe Dashboard → Payment methods
- [ ] `CHECKOUT_SUCCESS_URL` / `CANCEL_URL` point at the production storefront
- [ ] One real €1 live transaction + refund to validate the full loop

## 5. Frontend switch-over
- [ ] `api-config.js`: set `BASE_URL = 'https://api.<domain>'` — all stubs switch from demo seeds to live HTTP
- [ ] `CORS_ORIGINS` includes `https://samsam2703mfc.github.io`
- [ ] Push → GitHub Pages deploys; hard-refresh and verify catalog loads from the API (Network tab)
- [ ] Place a test order in each mode: collect (Stripe) + B2B delivery (deferred)

## 6. Monitoring & operations
- [ ] Uptime check on `GET /health` and `GET /sync/status`
- [ ] Alert if `outbox_pending` grows or `recent_errors` is non-empty
- [ ] Stripe Dashboard email alerts for failed webhooks
- [ ] DB backups: daily dump of the webshop DB (orders are the only non-recoverable data — catalog can always be re-synced)
- [ ] Logs: API + worker stdout shipped to the host's log system

## Rollback plan
- Frontend: set `BASE_URL = null` → instant return to demo mode (site stays up)
- Sync: stop the worker; data freezes but the shop keeps selling; `sync:full` recovers after fix
- Payments: orders stuck `pending_payment` expire via `checkout.session.expired` → auto-canceled
