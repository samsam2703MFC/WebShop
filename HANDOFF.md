# Webshop — Code Handoff

Storefront and admin built as a multi-file React-via-Babel prototype. This
document captures **what was cleaned up**, **what remains hardcoded**, and
**what Claude Code (or any backend team) needs to wire to make the project
production-ready**.

---

## 1. Architecture overview

### Entry points

| File | Purpose |
|---|---|
| `webshop-full.html` | Customer storefront. Loads all API stubs + the React bundle. |
| `tournee-admin.html` | Internal admin (tours, vouchers, direct links). Loads `admin-bundle.jsx`. |

### Storefront load order (matters)

`webshop-full.html` loads, in this exact order:

1. React + Babel
2. **API stubs** (plain JS, must run before React):
   - `webshop-vouchers.jsx` — voucher loading & validation
   - `webshop-i18n.jsx` — translations + customer store
   - `webshop-vies.jsx` — VAT validation (VIES service)
   - `webshop-shops-api.jsx` → `window.WSShops`
   - `webshop-offices-api.jsx` → `window.WSOffices`
   - `webshop-calendar-api.jsx` → `window.WSCalendar`
   - `webshop-catalog-api.jsx` → `window.WSCatalog`
   - `webshop-brand-api.jsx` → `window.WSBrand`
   - `webshop-pricing-api.jsx` → `window.WSPricing`
3. React modules (`webshop-i18n-react.jsx`, `webshop-allergens.jsx`, `webshop-full-bundle.jsx`)

### Each API stub follows the same pattern

```js
window.WSXxx = {
  endpoint: null,                  // set to backend URL to switch from fixture → HTTP
  async list(...) { /* fetch if endpoint, else fixture */ },
  ...
};
```

Wiring a backend is a one-liner:

```js
window.WSShops.endpoint    = 'https://api.atelier.be/shops';
window.WSCatalog.endpoint  = 'https://api.atelier.be/catalog';
window.WSCalendar.endpoint = 'https://api.atelier.be/calendar';
window.WSOffices.endpoint  = 'https://api.atelier.be/offices';
window.WSBrand.endpoint    = 'https://api.atelier.be/brand';
window.WSPricing.endpoint  = 'https://api.atelier.be/pricing';
```

The fixtures (in-memory seeds) keep the demo running until endpoints exist.

---

## 2. What was cleaned up in this pass

- **Deleted dead files:** `webshop.html`, `webshop-bundle.jsx`, `webshop.jsx`,
  `bundle.jsx` (older standalone storefront superseded by `webshop-full.html`).
- **Removed dead state:** `_W_SLOTS_DEPRECATED` placeholder.
- **Added `WSPricing` API stub** (`webshop-pricing-api.jsx`) — basket
  quoting, promos, payment methods. Frontend logic now has a clear seam.
- **Tagged every remaining hardcoded business rule** with a
  `TODO[BACKEND]:` marker so they're greppable in CI.

---

## 3. Frontend = UI only · Backend gaps

The frontend now contains **only UI / display logic** plus thin fallback
fixtures used until a backend is wired. Search the code for `TODO[BACKEND]`
to find every seam.

### Backend work required (cannot be completed inside Claude Code alone)

These items need a real server — no amount of frontend refactor can replace
them:

| Concern | Current frontend | Backend endpoint to build |
|---|---|---|
| **Catalog** (products, prices, allergens, options, bundles, badges) | `W_PRODUCTS` array seeded into `window._CATALOG_SEED`; read via `WSCatalog.listProducts()` | `GET /catalog/products`, `GET /catalog/products/:id`, `GET /catalog/bundles`, `GET /catalog/assortments` |
| **Shops** (address, accent color, capabilities) | `W_SHOPS` object exposed to `window.W_SHOPS`; read via `WSShops.list()` | `GET /shops` |
| **Calendar** (open days, slots, cutoff) | `FALLBACK_RULES` inside `webshop-calendar-api.jsx` | `GET /calendar/days`, `GET /calendar/slots`, `GET /calendar/cutoff` |
| **Offices** (B2B accounts, validation status, tour assignment) | `W_OFFICES_SEED` into `window._AUTH_STORE.offices`; read via `WSOffices` | `GET /offices`, `POST /offices`, `PATCH /offices/:id/status` |
| **Auth / users** | `W_USERS_SEED` with **plaintext passwords** — demo only, must not ship | `POST /auth/login`, `GET /me`, `PATCH /me`, `POST /auth/logout`, password reset, fidelity-app linking |
| **Tours** | `W_TOURS` constant in `webshop-full-bundle.jsx` | `GET /tours?shopId=` (and admin CRUD) |
| **Pricing / promos** (cross-portion 4+1, pickup −5%, bundle savings, voucher application) | `WSPricing.quote()` fallback in `webshop-pricing-api.jsx` | `POST /pricing/quote`, `GET /pricing/payment-methods`, `GET /pricing/promos/cross-portion` |
| **Vouchers** (redemption, usage tracking, scope enforcement) | `loadVouchers()` reads `localStorage.atelier_vouchers` (admin app writes it there) | `GET /vouchers`, `POST /vouchers/:id/redeem` (server-side enforcement of usage limit + minOrder + scope) |
| **VIES VAT validation** | `webshop-vies.jsx` calls a public test endpoint with a fallback | proper backend proxy with rate limiting and caching |
| **Brand theming** | `WSBrand.get()` returns empty fallback | `GET /brand?shopId=` returning `{ tokens, fonts, logo, strings }` per shop / franchise |
| **Order placement** | None — checkout currently only computes a quote | `POST /orders`, `POST /orders/:id/pay` |

### Hardcoded business rules still in the UI (intentional, with TODO markers)

These are kept as **synchronous fallbacks** so the UI renders during async
quote calls, but they MUST be replaced once `/pricing/quote` is live:

- **Cross-portion 4+1 promo** (`CROSS_PORTION_OFFER` / `computeCrossPortionOffer`
  in `webshop-full-bundle.jsx` ~line 1200). Render strip in basket.
- **Pickup promo −5%** (`subtotal * 0.05` in `Basket` and `CheckoutWizard`).
- **Portion units** (`PORTION_UNITS = { quart:1, demi:2, entier:4 }` ~line
  454). Should live on each product (varies by product type).
- **Quarter value factor 0.27** (used to value the freebie). Should be
  product-level metadata.
- **Payment methods** (`W_PAYMENTS` ~line 2351) — replace with
  `WSPricing.listPaymentMethods({ shopId, mode })`.

Every one of the above has a `// TODO[BACKEND]:` comment above it.

---

## 4. Admin app

`admin-bundle.jsx` is **auto-generated** (concatenated from `design-canvas.jsx`,
`tournee-data.jsx`, `tournee-shell.jsx`, `tournee-card-{a,b,c}.jsx`, `app.jsx`,
`qr.jsx`, `vouchers.jsx`, `links.jsx`, `admin-app.jsx`). Edit the source files;
regenerate the bundle.

The vouchers admin currently writes to `localStorage.atelier_vouchers` so
the storefront can pick them up. **Both sides must move to a real
`/vouchers` endpoint.**

---

## 5. Performance notes

- React loads via UMD CDN with SRI pins. For production, compile via Vite/esbuild
  and code-split the storefront from the admin.
- `webshop-full-bundle.jsx` is 3000+ lines and Babel-transpiled at runtime —
  measurable cold-start cost. Pre-compile before shipping.
- Product / catalog data should be paginated server-side (`W_PRODUCTS` has
  ~30 demo items; production will have hundreds → thousands).
- `WSCatalog` / `WSPricing` calls have no caching layer; add SWR-style
  revalidation when wired to a real API.

---

## 6. What Claude Code can do next (frontend-only)

These are doable inside the current setup without a backend:

1. **Replace the synchronous `computeCrossPortionOffer` with an async
   `useQuote(basket)` hook** that calls `WSPricing.quote()` and renders
   whatever it returns (so swapping in a real endpoint requires zero UI
   change).
2. **Replace the `W_PAYMENTS` array reference with an effect** that calls
   `WSPricing.listPaymentMethods()` on mount + on shop/mode change.
3. **Move `W_TOURS` behind a `WSTours` API stub** mirroring the others.
4. **Split `webshop-full-bundle.jsx`** by feature (catalog, basket, checkout,
   profile) into separate babel-loaded files using the global-`window` pattern
   already established in the project.
5. **Replace the homemade fidelity QR with a real QR encoder** — `qr.jsx`
   already exists in the admin bundle and can be lifted to the storefront.

Items 1–3 are pure refactors with no behavior change. Item 4 will need
careful scope-export discipline (each Babel script gets its own scope).

---

## 7. Conventions

- Never import from `/projects/*` paths at runtime — only at build/copy time.
- Never read `W_*` fixtures directly from feature components — always go
  through the `WS*` API stub.
- Every backend-bound business rule is marked `// TODO[BACKEND]:`.
- File naming: `webshop-*.jsx` = storefront, `*-api.jsx` = data-access stub,
  `*-bundle.jsx` = generated/concatenated artifact.
