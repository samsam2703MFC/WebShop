# WEBSHOP_AUDIT.md — Go-live Readiness Audit

**Date:** 2026-06-10 · **Repo:** `samsam2703MFC/WebShop` @ `eeaccc1` · **Auditor:** Claude Code

---

## ⚡ Executive summary — read this first

**❌ The mission's core premise does not match this repository.**

The mission states: *"This repo contains our webshop and the general shop/ERP database (Franchise Buddy). Both databases are MySQL."*

What this repo actually contains:

| Expected | Found |
|---|---|
| Webshop frontend | ✅ Yes — React 18 UMD + Babel Standalone, static files, no build step |
| API layer (server) | ❌ **None.** 13 browser-side `window.WSXxx` stubs running on in-memory seed data |
| Webshop MySQL DB | ❌ **Does not exist.** `DATABASE.md` is a written *specification* for a future `ws_` schema — no migrations, no `.sql` files, no live database |
| General/ERP MySQL DB (Franchise Buddy) | ❌ **Not in this repo.** "Franchise" appears only as admin-console branding ("Console franchise") and in handoff notes |
| Env vars / DB connections | ❌ None. Zero `process.env`, `.env`, or connection strings anywhere |
| Stripe / any PSP | ❌ None. The checkout payment step is cosmetic — radio buttons, no charge is ever made |
| Backend runtime (package.json, composer.json, Dockerfile…) | ❌ None. Not even Node tooling — the site is pure static files on GitHub Pages |

**Consequence:** Phases 2 (DB sync) and 3 (Stripe) cannot be implemented *in this repo as it stands* — there is no server for the sync worker or the Stripe webhook handler to live in, and no databases to sync between. The real prerequisite is **Phase 0: stand up the backend** (API server + webshop MySQL DB), for which this repo is unusually well prepared — see §6.

Everything below audits what exists, against your checklist.

---

## 1. Architecture map

```
┌─────────────────────────── THIS REPO ───────────────────────────┐
│  GitHub Pages (static hosting)                                   │
│                                                                   │
│  webshop-full.html ── React UMD + Babel (CDN)                     │
│       │                                                           │
│  webshop-full-bundle.jsx  (storefront UI + business logic)        │
│       │ calls                                                     │
│  13 × window.WSXxx API stubs (one .jsx file each)                 │
│       │                                                           │
│       ├─ endpoint === null  → in-memory seed data  ← TODAY        │
│       └─ endpoint set via api-config.js → fetch() JSON/HTTPS      │
│                                            │                      │
└────────────────────────────────────────────┼──────────────────────┘
                                             ▼
                              ┌──────── DOES NOT EXIST ────────┐
                              │  API server (~40 endpoints,     │
                              │  contracts written in API.md)   │
                              │  Webshop MySQL DB (schema       │
                              │  written in DATABASE.md, ~25    │
                              │  ws_* tables)                   │
                              │  Sync from Franchise Buddy      │
                              │  Stripe integration             │
                              └─────────────────────────────────┘
```

Also in the repo but separate from the storefront: an **admin console** (`admin-bundle.jsx`, "Console franchise") and a **tournée driver app** (`tournee-*.jsx`) — same pattern, same stubs, no backend.

**Env vars:** ❌ none exist. Nothing to list. (This is correct for a static site — secrets *cannot* be kept in this repo's code, which also means Stripe secret keys and DB credentials must live in the future backend, never here.)

---

## 2. API health check

### 2.1 Endpoint inventory — frontend stubs vs documented contracts

All stubs follow the same pattern: `endpoint = null` → seed fallback; endpoint set → live HTTP. `api-config.js` wires all of them from one `BASE_URL`.

| Stub (`window.`) | Methods | Documented in API.md | Backend exists |
|---|---|---|---|
| `WSAuth` | login, register, logout, me, updateMe, requestPasswordReset | ✅ §1 `/auth/*` | ❌ |
| `WSShops` | list, get, getCacheSync | ✅ §2 `/shops` | ❌ |
| `WSCatalog` | listProducts, getProduct, listCategories, listBundles, listAssortments, getStock, reserve, release | ✅ §3 `/catalog/*` | ❌ |
| `WSCalendar` | listDays, listSlots, getCutoff | ✅ §4 `/calendar/*` | ❌ |
| `WSAvailability` | getShopSettings, listAvailableDays, listSlots, validateCart, getContext | ✅ `/availability/*` | ❌ |
| `WSPricing` | quote, listPaymentMethods, getCrossPortionRule | ✅ §5 `/pricing/*` | ❌ |
| `WSVouchers` | redeem, list (+admin CRUD) | ✅ §6 `/vouchers/*` | ❌ |
| `WSOrders` | place, get, listMine, cancel | ✅ §7 `/orders/*` | ❌ |
| `WSOffices` | listApproved, get, requestNew | ✅ §8 `/offices/*` | ❌ |
| `WSDeliveryFees` | listSites, getSite, quote, listPaymentMethodsForSite | ✅ `/delivery-fees/*` | ❌ |
| `WSTours` | list, get | ✅ `/tours` | ❌ |
| `WSBrand` | get, apply | ✅ `/brand` | ❌ |
| `WSVies` | check (mock + live mode) | ✅ `/vies` | ❌ |

- ✅ **No dead endpoints, no missing handlers** on the frontend side: every stub method is consumed by the UI, and every UI data need goes through a stub. The decoupling is clean.
- ❌ **Wiring end-to-end (route → controller → query → DB): 0 of ~40.** There are no routes, controllers, or queries. 100 % of data is mock/seed.

### 2.2 Mock / hardcoded data flagged

| Item | Location | Severity |
|---|---|---|
| Entire catalog, shops, offices, tours, users from seed constants | `webshop-full-bundle.jsx` (`W_PRODUCTS`, `W_SHOPS`, `W_OFFICES_SEED`, `W_USERS_SEED`…) + each stub's fallback | Expected (demo mode), but **live data = backend required** |
| **Demo auth with plaintext passwords** (`mdp: demo`, hints printed in checkout UI) | `W_USERS_SEED`, `CheckoutStep1` | ❌ must never ship |
| **Hardcoded 5 % collect promo** computed client-side | `Basket`, `CheckoutWizard` (`subtotal * 0.05`) | ⚠️ flagged by its own TODO: must come from `WSPricing.quote()` |
| **Totals computed client-side** and sent in the order payload (`total`) | `CheckoutWizard.handlePay()` | ❌ violates your own Phase 3 rule "never trust client-side prices" — backend must recompute |
| **Payment step is theatre**: radio buttons (Bancontact/Visa/Apple Pay) with no PSP behind them; "Payer" resolves instantly | `CheckoutStep3`, `WSOrders.place` fallback | ❌ this is exactly the Phase 3 gap |
| 15 × `TODO[BACKEND]` markers | `webshop-full-bundle.jsx` | ⚠️ good — they map the seams precisely |

---

## 3. Database preparation (MySQL)

There is **no database to inspect** — so this section audits `DATABASE.md`, the schema *specification* the backend must be built from (~25 `ws_*` tables incl. the availability §9 and delivery-fee §10 systems).

| Check | Status | Detail |
|---|---|---|
| Schema matches API/stub data shapes | ✅ | `DATABASE.md` + `DATA_SHAPES.md` were co-written with the stubs; field names line up (spot-checked products, slots, fee rules, order metadata) |
| Indexes on lookup fields | ⚠️ | Only 6 index mentions in the whole spec. Missing explicit indexes on: `ws_products.cat`, `ws_product_shops(shop_id, product_id)`, `ws_orders(customer_id)`, `ws_office_delivery_sites(office_client_id)`, `ws_delivery_fee_rules(level, *_id)`, voucher `code` |
| `utf8mb4` charset specified | ❌ | Zero mentions. Must be mandated (product names contain é/×/œ…) — `utf8mb4` + `utf8mb4_unicode_ci` |
| `InnoDB` engine specified | ❌ | Zero mentions. Required for the FK constraints and row-level locking the spec assumes (stock reservations) |
| FK constraints | ⚠️ | FKs are described in prose/tables (`FK → ws_shops.id`) but there is no DDL with `ON DELETE`/`ON UPDATE` behaviour |
| Migrations | ❌ | None exist (no migration tooling chosen — nothing to run them) |
| Schema drift | ✅ n/a | Nothing to drift against yet; the spec is the single source of truth and is current with the frontend |
| **VAT rates** | ❌ **Critical gap for Phase 3** | Zero VAT mentions in `DATABASE.md`. Prices are implicitly TTC-only. Stripe + Belgian invoicing needs `vat_rate` (6 % food / 21 % standard) per product and HTVA/TVA split on order lines |
| SKU field | ⚠️ | Products are keyed on integer `id` only — no SKU column. Phase 2 sync needs a stable upstream key; add `erp_sku` / `external_id` with a unique index |

---

## 4. Queries (SQL/ORM review)

❌ **Not applicable — there are no queries.** No SQL, no ORM, no server code in the repo. The sample queries in `DATABASE.md` §6 are illustrative; they do look correct (they scope by `shop_id` and `active`), but nothing executes them.

Scoping logic that currently lives **client-side** and must move server-side:
- Active-product filtering, per-shop product availability (`ws_product_shops` semantics) — currently the stub returns the whole seed and the UI filters.
- `no_delivery` / `delivery_stock` gating — enforced only in the UI; a crafted POST to the future `/orders` could bypass it. Backend must re-validate (the spec's `WSAvailability.validateCart` contract is the right hook).
- Delivery-fee resolution (site → office → tour → shop → global) — implemented in the stub; backend must own it, frontend display only.

---

## 5. Documentation vs reality

| Doc | Verdict |
|---|---|
| `API.md` (~40 endpoint contracts, request/response examples) | ✅ Excellent, matches the stubs. This is effectively the backend's build spec |
| `DATABASE.md` | ✅ Thorough as a spec; ❌ gaps: charset/engine, VAT, SKU, full DDL (see §3) |
| `DATA_SHAPES.md` | ✅ Field-by-field, matches what the UI reads (verified for availability + delivery fees, which I built) |
| `CLAUDE.md` | ✅ Accurate file-ownership guide |
| `HANDOFF.md` / `uploads/spec.txt` | ⚠️ Contain additional unbuilt requirements (SSO from PWA, brand admin) — keep in scope for backend planning |
| README | ❌ None exists. `index.html` redirects to `webshop-full.html`; a README explaining demo mode vs wired mode would help onboarding |

---

## 6. Prioritized fix list

**P0 — prerequisite for everything (the real "Phase 0"):**
1. **Decide backend stack & hosting** (the repo is stack-agnostic by design — any stack that can serve the JSON contracts in `API.md` works; Node/Express or PHP/Laravel both fine). GitHub Pages keeps serving the frontend; backend lives elsewhere (e.g. a VPS / Railway / Fly.io) behind HTTPS + CORS.
2. **Create the webshop MySQL DB** from `DATABASE.md` — writing real DDL migrations with `InnoDB`, `utf8mb4`, the missing indexes from §3, and FK actions.
3. **Add to the schema:** `vat_rate` on products, HTVA/TVA columns on order lines, `external_id`/SKU (unique) on products/categories/shops for sync keying, and `sync_log` + `orders.stripe_payment_intent_id` columns (pre-staging Phases 2–3).
4. Implement the ~40 endpoints from `API.md` (the contracts are done; this is mechanical), then set `BASE_URL` in `api-config.js` — the frontend switches over with **zero frontend changes**.

**P1 — security/correctness items the audit exposed (fix in backend design):**
5. Server-side total recomputation in `POST /orders` (reject client `total`), server-side re-validation of delivery gating and stock.
6. Real auth (hashed passwords, sessions/JWT, CSRF — `api-config.js` already has a CSRF hook stub). Kill the demo-credentials hint in `CheckoutStep1`.
7. Remove the hardcoded 5 % client promo once `/pricing/quote` exists (TODOs already mark the spots).

**P2 — Phase 2 (sync) readiness notes, for when Franchise Buddy is accessible:**
8. Franchise Buddy is not in this repo — I need: where it runs, whether `binlog_format=ROW` is enabled, and whether we may create triggers/an outbox table on it. **Preliminary recommendation: trigger-based outbox (option b)** — Debezium (+ Kafka/Connect) is heavy operational machinery for a single one-way product/stock feed; an outbox table + small worker gives seconds-level latency, is idempotent by construction, and your fallback (full-sync CLI + hourly reconciliation) covers its failure modes. Will confirm once I can see the ERP schema.

**P3 — Phase 3 (Stripe) readiness notes:**
9. **Preliminary recommendation: Stripe-hosted Checkout** — Bancontact + cards supported out of the box, zero PCI surface, redirect flow fits the current `CheckoutWizard` step 3 cleanly (replace the fake payment radios with a single "Payer" → redirect). Embedded Payment Element only wins if you need the payment form visually inside the wizard; revisit after backend exists.
10. Blockers found for Stripe: no VAT data (item 3), client-side totals (item 5), no server for webhooks (item 1).

---

## 7. Verdict

| Phase | Status |
|---|---|
| Phase 1 audit | ✅ done — this report |
| Phase 2 sync | 🚫 **blocked** — no backend, no webshop DB, Franchise Buddy not accessible from this repo |
| Phase 3 Stripe | 🚫 **blocked** — same, plus VAT gap in schema |
| Frontend readiness for backend wiring | ✅ **excellent** — contracts written, stubs switchable via one config line, seams marked with TODOs |

**Recommended next step:** approve "Phase 0" (items 1–4 above). Tell me your preferred backend stack and where it will be hosted, and whether/how I can reach the Franchise Buddy MySQL instance — then I can scaffold the backend, write the real migrations, and proceed to Phases 2 and 3 in order.
