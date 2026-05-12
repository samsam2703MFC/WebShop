# L'Atelier By — Webshop · Project Guide for Claude

## Stack

- **Frontend only** — no build step. React 18 UMD + Babel Standalone loaded from CDN.
- **Entry point** — `index.html` redirects to `webshop-full.html`.
- **Deployment** — GitHub Pages (`https://samsam2703MFC.github.io/WebShop/`).
- **Backend** — not wired yet. All API stubs fall back to in-memory seed data.

---

## File Ownership

### 🔴 NEVER overwrite — Logic & API layer

These files contain business logic, API integration, and seed data.
**Do not replace, reformat, or regenerate these files from a design tool.**

| File | What it does |
|------|-------------|
| `webshop-auth-api.jsx` | `window.WSAuth` — login, register, session |
| `webshop-catalog-api.jsx` | `window.WSCatalog` — products, categories, bundles, assortments |
| `webshop-calendar-api.jsx` | `window.WSCalendar` — slots, open days, delivery cutoff per shop |
| `webshop-availability-api.jsx` | `window.WSAvailability` — central availability engine (days, slots, validation) |
| `webshop-pricing-api.jsx` | `window.WSPricing` — quote, vouchers, cross-portion rule |
| `webshop-shops-api.jsx` | `window.WSShops` — shop directory |
| `webshop-offices-api.jsx` | `window.WSOffices` — delivery offices |
| `webshop-tours-api.jsx` | `window.WSTours` — delivery tours |
| `webshop-orders-api.jsx` | `window.WSOrders` — place & track orders |
| `webshop-brand-api.jsx` | `window.WSBrand` — per-shop theme tokens |
| `webshop-vouchers.jsx` | `window.WSVouchers` — voucher validation |
| `webshop-vies.jsx` | `window.WSVies` — VAT number lookup |
| `webshop-i18n.jsx` | `window.WSI18n` — translations (fr/nl/en/de) |
| `webshop-delivery-fees-api.jsx` | `window.WSDeliveryFees` — delivery fee resolution per site/office/tour/shop |
| `api-config.js` | Sets `BASE_URL` to activate live HTTP endpoints |

### 🟡 MERGE carefully — React logic + visual components

`webshop-full-bundle.jsx` contains **both** business logic and visual JSX.
When a design tool regenerates this file, Claude Code must merge the new visual parts
while preserving the logic sections listed below.

**Must be preserved inside `webshop-full-bundle.jsx`:**
- All `window.WSXxx` calls (API integration)
- All `useState` / `useEffect` hooks in `ShopFrame`
- `handleMode` — delivery gating + 10:00 cutoff logic
- `handleDate` — date change + cutoff revert
- `userOffice` / `userTour` async load chain
- `deliveryCutoffMinutes` — loaded from `WSCalendar.getCutoff({ shopId })`
- `deliveryCutoffPassed` — computed from API cutoff, refreshed every minute
- `computeCrossPortionOffer(basket, rule)` — rule loaded from `WSPricing.getCrossPortionRule()`
- `ProductCard` props: `mode`, `basketQty` — needed for `no_delivery` / `delivery_stock`
- `ProductDetail` delivery restriction checks (`deliveryBlocked`, `deliveryStockLeft`)
- `AccountModal` props: `office`, `tour` — passed from ShopFrame async state
- `CheckoutWizard` props: `office`, `tour` — passed from ShopFrame async state
- Seed constants (`W_PRODUCTS`, `W_SHOPS`, etc.) and `window._CATALOG_SEED` exposure

### 🟢 FREE to redesign — Pure visual files

Replace or regenerate these freely. They contain only styling and markup.

| File | What it does |
|------|-------------|
| `webshop.css` | Main storefront styles |
| `webshop-detail.css` | Product detail modal styles |
| `webshop-i18n.css` | Language switcher styles |
| `webshop-profile-extras.css` | Account modal extras |
| `webshop-allergens.css` | Allergen icon styles |
| `webshop-i18n-react.jsx` | `<LangChip>` / `<LangMenu>` visual components |
| `webshop-allergens.jsx` | `<AllergensModal>` visual component |

---

## Key Product Fields (from API)

| Field | Type | Effect |
|-------|------|--------|
| `no_delivery` | boolean | Hides add button in delivery mode, shows "Retrait seulement" badge |
| `delivery_stock` | integer | Max units available for delivery; UI shows count, caps qty |
| `portions` | boolean | Enables quart / demi / entier selector |
| `crossPortion` | boolean | Line participates in basket-level 4+1 promo |
| `has_menu_options` | boolean | Enables bundle/menu carousel in product detail |

---

## Delivery Rules

- **Cutoff time** — loaded per shop from `WSCalendar.getCutoff({ shopId, mode: 'delivery' })`. Never hardcoded.
- **Eligibility** — user must be logged in + have a validated office + tour (`userCanDeliver`).
- **Multi-shop** — all API calls pass `shopId`. Switching shops reloads cutoff, categories, products, assortments.

---

## How to Wire a Real Backend

Edit `api-config.js` and set the base URL:

```js
const BASE_URL = 'https://your-api.com';
window.WSAuth.endpoint      = BASE_URL + '/auth';
window.WSCatalog.endpoint   = BASE_URL + '/catalog';
window.WSCalendar.endpoint  = BASE_URL + '/calendar';
window.WSPricing.endpoint   = BASE_URL + '/pricing';
window.WSShops.endpoint     = BASE_URL + '/shops';
window.WSOffices.endpoint   = BASE_URL + '/offices';
window.WSTours.endpoint     = BASE_URL + '/tours';
window.WSOrders.endpoint    = BASE_URL + '/orders';
window.WSBrand.endpoint         = BASE_URL + '/brand';
window.WSAvailability.endpoint  = BASE_URL + '/availability';
```

All stubs automatically switch from seed data to live HTTP. No other changes needed.

---

## Design ↔ Code Workflow

1. **Claude Design** generates new JSX/CSS visuals
2. Drop the files into this repo
3. **Claude Code** (this session) merges: keeps all logic, adopts new visual markup
4. Push to GitHub → Pages deploys automatically

See `API.md` for full endpoint contracts and `DATA_SHAPES.md` for field-by-field data shapes.
