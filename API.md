# Webshop — API Specification

Complete contract for every backend endpoint the storefront + admin need.
Each section maps to one frontend stub (`window.WSXxx`) so the FE switches
from in-memory fixture to live HTTP by setting `endpoint`.

> **Conventions**
> - All requests/responses are `application/json`.
> - All endpoints accept cookies (`credentials: 'include'`).
> - Money values are decimal **euros** (e.g. `12.50`), never cents.
> - Dates/times are ISO 8601 (`2026-05-08`, `2026-05-08T11:30:00+02:00`).
> - IDs are opaque strings; never assume numeric.
> - All list endpoints return arrays (or `{ data: [...] }` — both accepted by the FE).

---

## 1. Shops — `WSShops`

Base: `/shops` · Frontend: `webshop-shops-api.jsx`

### `GET /shops`

List all shops the customer can browse.

**Response** — `Shop[]`

```json
[
  {
    "id": "chatelain",
    "name": "Maison Châtelain",
    "city": "Bruxelles",
    "address": "Rue du Bailli 42, 1050 Ixelles",
    "accent": "#8D1D2C",
    "capabilities": { "collect": true, "delivery": true },
    "openingHours": { "mon": "07:00-19:00", "...": "..." }
  }
]
```

### `GET /shops/:id`

Single shop by id. Same shape as one element above.

---

## 2. Catalog — `WSCatalog`

Base: `/catalog` · Frontend: `webshop-catalog-api.jsx`

### `GET /catalog/products?shopId=&cat=`

List products. Optional `shopId` filters to what's available at that shop;
optional `cat` filters by top-level category.

**Response** — `Product[]`

```json
[
  {
    "id": "p-tarte-fraises",
    "cat": "tarts",
    "subCat": "tarts-fruit",
    "name": "Tarte aux fraises",
    "description": "...",
    "price": 24.00,
    "img": "https://cdn/p-tarte-fraises.png",
    "allergens": ["gluten", "milk", "egg"],
    "badge": "4+1",
    "portions": true,
    "portionUnits": { "quart": 1, "demi": 2, "entier": 4 },
    "crossPortion": true,
    "offer": { "type": "buy_x_get_y_free", "x": 4, "y": 1, "unit": "portion" },
    "options": [
      { "id": "bread", "label": "Pain", "kind": "single", "required": true,
        "choices": [
          { "id": "bg", "label": "Pain blanc", "priceDelta": 0 },
          { "id": "bs", "label": "Pain seigle", "priceDelta": 0.5 }
        ] }
    ],
    "available_bundles": [
      { "id": "b-menu", "name": "Menu", "description": "...",
        "price_modifier": 3.5, "recommended": false,
        "advantages": ["Économisez 1,00 €"],
        "included": [{ "label": "Sandwich Club" }],
        "configurable": [
          { "id": "drink", "label": "Boisson", "kind": "single", "required": true,
            "choices": [{ "id": "water", "label": "Eau plate", "priceDelta": 0 }] }
        ]
      }
    ],
    "stock": { "available": true, "soldOut": false, "lowStock": false }
  }
]
```

### `GET /catalog/products/:id`

Single product (same shape).

### `GET /catalog/bundles?productId=`

Bundles attached to a product. Returns the `available_bundles` array directly.

### `GET /catalog/assortments?shopId=`

Seasonal assortments / curated boxes.

```json
[
  { "id": "paques", "label": "Pâques", "img": "...",
    "tagline": "Sélection chocolatée — disponible jusqu'au 7 avril",
    "validFrom": "2026-03-01", "validUntil": "2026-04-07",
    "products": ["p-…", "p-…"] }
]
```

---

## 3. Calendar / Slots / Cutoff — `WSCalendar`

Base: `/calendar` · Frontend: `webshop-calendar-api.jsx`

### `GET /calendar/days?shopId=&mode=&from=&to=`

`mode` is `collect` | `delivery`. `from`/`to` are inclusive ISO dates.

**Response**

```json
[
  { "iso": "2026-05-08", "available": true, "reason": null },
  { "iso": "2026-05-09", "available": false, "reason": "closed" },
  { "iso": "2026-05-10", "available": false, "reason": "fully_booked" }
]
```

`reason` enum: `closed` · `fully_booked` · `cutoff` · `holiday`.

### `GET /calendar/slots?shopId=&mode=&date=`

Time slots for a given day.

```json
[
  { "id": "s-08", "label": "08:00–09:00", "capacity": 12, "remaining": 7 },
  { "id": "s-09", "label": "09:00–10:00", "capacity": 12, "remaining": 0, "soldOut": true }
]
```

### `GET /calendar/cutoff?shopId=&mode=`

```json
{ "hour": 16, "minutes": 0, "leadHours": 2 }
```

> `leadHours` = how far in advance an order must be placed.

---

## 4. Offices (B2B) — `WSOffices`

Base: `/offices` · Frontend: `webshop-offices-api.jsx`

### `GET /offices?status=validated`

List offices filtered by status (`pending` | `validated` | `rejected`).
Returns only `validated` for the customer-facing self-link picker.

```json
[
  { "id": "off-acme", "name": "ACME Avocats",
    "contact": "Marie Dubois", "phone": "+32 472 11 22 33",
    "email": "marie@acme.be", "address": "Rue de la Loi 120, 1040 Bxl",
    "tourId": "tour-bxl-mid", "status": "validated" }
]
```

### `GET /offices/:id`

Single office (any status).

### `POST /offices`

Customer self-creates a new office request — saved as `pending`.

**Request**

```json
{ "name": "Borderline & Co.", "contact": "Lou Mercier",
  "phone": "+32 470 12 34 56", "email": "lou@borderline.be",
  "address": "Place Stéphanie 4, 1050 Bxl" }
```

**Response** — created `Office` (status: `pending`).

### `PATCH /offices/:id` *(admin)*

Approve / reject / re-assign tour.

```json
{ "status": "validated", "tourId": "tour-bxl-mid" }
```

---

## 5. Tours — `WSTours` *(stub to add)*

Base: `/tours` · Frontend: not yet wired (still uses `W_TOURS` in
`webshop-full-bundle.jsx`).

### `GET /tours?shopId=`

```json
[
  { "id": "tour-bxl-mid", "name": "Bruxelles Midi",
    "shopId": "chatelain", "window": "11:30–13:30",
    "days": "lun-ven", "active": true }
]
```

### `POST /tours` · `PATCH /tours/:id` · `DELETE /tours/:id` *(admin)*

Standard CRUD.

---

## 6. Auth / Users — *(stub to add)*

Base: `/auth` and `/me`

### `POST /auth/login`

```json
{ "email": "marie@acme.be", "password": "***" }
```

**Response**

```json
{ "user": { "id": "u1", "email": "marie@acme.be", "firstName": "Marie",
    "lastName": "Dubois", "officeId": "off-acme",
    "preferredShopId": "chatelain",
    "fidelityApp": { "active": true, "linkedAt": "2026-04-01T08:30:00Z" },
    "isBusiness": true,
    "invoice": { "country": "BE", "vat": "BE0123456789", "name": "ACME Avocats", "address": "..." }
  }
}
```

(Cookie session set by server.)

### `POST /auth/logout` · `POST /auth/register` · `POST /auth/password-reset`

Standard flows.

### `GET /me`

Returns `{ user }` — same shape as login response.

### `PATCH /me`

Partial update of profile fields. Body = `Partial<User>`.

### `POST /me/fidelity-link/start` · `POST /me/fidelity-link/confirm`

Two-step QR linking with the mobile app.

```json
// start → server returns
{ "payload": "latelier://fidelity/link?u=u1&n=8x2k", "expiresAt": "..." }
// confirm → server returns
{ "linkedAt": "2026-05-08T10:14:00Z" }
```

---

## 7. Pricing / Quote / Promos — `WSPricing`

Base: `/pricing` · Frontend: `webshop-pricing-api.jsx`

### `POST /pricing/quote`

The single source of truth for basket totals. Frontend sends the basket;
server returns subtotal + every discount line + final total.

**Request**

```json
{
  "shopId": "chatelain",
  "mode": "collect",
  "basket": [
    { "productId": "p-tarte-fraises", "qty": 2, "portion": "demi",
      "options": [{ "id": "bread", "choiceId": "bs" }],
      "bundleId": null, "bundleSlots": {} }
  ],
  "voucher": "BIENVENUE10"
}
```

**Response**

```json
{
  "subtotal": 48.00,
  "discounts": [
    { "code": "cross-portion", "label": "1 quart offert · Tarte aux fraises", "amount": 6.48,
      "meta": { "cycles": 1, "freeCount": 1, "freeNames": ["Tarte aux fraises"], "threshold": 4 } },
    { "code": "pickup-5",      "label": "Retrait −5%",                          "amount": 2.40 },
    { "code": "voucher",       "label": "−10% appliqué",                        "amount": 4.80 }
  ],
  "total": 34.32,
  "voucher": { "ok": true, "code": "BIENVENUE10", "discount": 4.80 },
  "lines": [
    { "productId": "p-tarte-fraises", "lineTotal": 48.00, "appliedOffers": ["cross-portion"] }
  ]
}
```

### `GET /pricing/payment-methods?shopId=&mode=`

```json
[
  { "id": "bancontact", "label": "Bancontact",   "sub": "Paiement instantané" },
  { "id": "visa",       "label": "Carte bancaire", "sub": "Visa · Mastercard · Amex" },
  { "id": "paypal",     "label": "PayPal",         "sub": "Compte PayPal" },
  { "id": "invoice",    "label": "Facture office", "sub": "Réservé Office validés", "requires": "office" }
]
```

### `GET /pricing/promos/cross-portion?shopId=`

The cross-portion 4+1 rule.

```json
{ "x": 4, "y": 1, "threshold": 4,
  "label": "4 portions achetées · 1 quart offert",
  "quarterValueFactor": 0.27,
  "eligibleCats": ["tarts", "plats-traiteur"] }
```

---

## 8. Vouchers — `WSVouchers` *(stub to add)*

Currently `loadVouchers()` reads `localStorage.atelier_vouchers` (the admin
app writes there). Replace with a real API.

Base: `/vouchers`

### `GET /vouchers` *(admin)*

```json
[
  { "id": "v_…", "code": "BIENVENUE10", "type": "percent", "value": 10,
    "minOrder": 0, "scope": "order", "scopeRef": null,
    "channel": "webshop", "shopIds": [],
    "validFrom": "2026-01-01", "validUntil": "2026-12-31",
    "usage": { "used": 142, "limit": null },
    "status": "active" }
]
```

`type` enum: `percent` · `amount` · `shipping`.
`scope` enum: `order` · `category` · `product`.
`status` enum: `active` · `scheduled` · `expired` · `exhausted` · `disabled`.

### `POST /vouchers` · `PATCH /vouchers/:id` · `DELETE /vouchers/:id` *(admin)*

Standard CRUD.

### `POST /vouchers/redeem`

Server-side validation + atomic increment of `usage.used`. Replaces the
client-side `validateVoucher()`.

```json
// request
{ "code": "BIENVENUE10", "shopId": "chatelain",
  "subtotal": 48.00, "basket": [...] }
// response (success)
{ "ok": true, "voucher": { ... }, "discount": 4.80,
  "message": "−10% appliqué" }
// response (failure)
{ "ok": false, "reason": "minOrder",
  "message": "Minimum €25.00 requis" }
```

`reason` enum: `unknown` · `expired` · `scheduled` · `exhausted` ·
`channel` · `shop` · `minOrder` · `category` · `product`.

---

## 9. Brand / Theming — `WSBrand`

Base: `/brand` · Frontend: `webshop-brand-api.jsx`

### `GET /brand?shopId=`

Per-shop or per-franchise theming. Frontend writes `tokens` into CSS
custom properties on `:root`.

```json
{
  "tokens": {
    "color-primary": "#8D1D2C",
    "color-text":    "#1F1612",
    "radius-card":   "14px"
  },
  "fonts": [
    { "family": "Souvenir", "url": "https://cdn/.../Souvenir.woff2",
      "weight": 400, "style": "normal" }
  ],
  "logo": "https://cdn/atelier-logo.svg",
  "strings": { "checkout.cta": "Payer la commande" }
}
```

---

## 10. VAT (VIES) — `WSVies`

Base: `/vies` (proxy) · Frontend: `webshop-vies.jsx`

### `GET /vies/check?vat=BE0123456789`

Proxy to the EU VIES service, with caching + rate limiting on the backend.

```json
{ "ok": true, "valid": true, "country": "BE", "number": "0123456789",
  "name": "ACME Avocats", "address": "Rue de la Loi 120, 1040 Bxl",
  "checkedAt": "2026-05-08T10:00:00Z" }
```

---

## 11. Orders / Checkout — *(stub to add)*

Base: `/orders` — **not yet implemented in frontend**.

### `POST /orders`

Creates a draft order from a quote.

```json
{
  "shopId": "chatelain", "mode": "collect",
  "slot": { "date": "2026-05-09", "slotId": "s-09" },
  "basket": [...],
  "voucher": "BIENVENUE10",
  "customer": { "id": "u1", "officeId": "off-acme" },
  "payment": { "method": "bancontact" },
  "delivery": { "officeId": "off-acme", "tourId": "tour-bxl-mid" }
}
```

**Response**

```json
{ "orderId": "ord-abc123", "status": "pending_payment",
  "total": 34.32, "paymentUrl": "https://psp/.../redirect" }
```

### `GET /orders/:id` · `GET /me/orders`

Order status + history.

### `POST /orders/:id/cancel`

Cancellation rules enforced server-side (cutoff, mode, status).

---

## 12. Direct links / Deep links

The admin's link generator produces URLs like:

```
https://atelier.be/shop?shop=chatelain&mode=collect&voucher=BIENVENUE10&category=tarts&product=p-tarte-fraises&open=product
```

Parsed client-side by `webshop-vouchers.jsx → parseDeepLink()`. **No
backend endpoint** required — but the voucher attached must still be
re-validated via `POST /vouchers/redeem` at quote time.

---

## 13. Admin / Tournées

Base: `/admin/tours/:tourId` (existing in admin bundle).

Operations expected:
- `GET /admin/tours` — list with filters
- `GET /admin/tours/:id` — full detail (offices, slots, status)
- `PATCH /admin/tours/:id` — update window, days, route
- `POST /admin/tours/:id/offices` — attach office
- `DELETE /admin/tours/:id/offices/:officeId` — detach

Schema mirrors `Tour` and `Office` above.

---

## 14. Authentication & authorization summary

| Surface | Auth required | Roles |
|---|---|---|
| Storefront browse / quote | none | guest |
| Checkout (place order) | session cookie | customer |
| `/me/*` | session cookie | customer |
| `/offices POST` (self-create) | session cookie | customer |
| `/admin/*`, `/vouchers POST/PATCH`, `/tours POST/PATCH` | session cookie | `admin` role |

Use HTTP-only cookies for sessions; CSRF token header for mutations.

---

## 15. Error format

All non-2xx responses MUST follow:

```json
{
  "error": {
    "code": "voucher_min_order",
    "message": "Minimum €25.00 requis",
    "details": { "required": 25.00, "subtotal": 18.40 }
  }
}
```

Frontend stubs check `r.ok` and silently fall back to fixtures on failure;
production behaviour should surface `error.message` to the user.

---

## 16. Caching headers

Recommended:
- `/shops`, `/catalog/products`, `/brand` → `Cache-Control: public, max-age=60, stale-while-revalidate=300`
- `/calendar/*` → `private, max-age=30` (changes with bookings)
- `/pricing/quote`, `/me/*`, `/orders/*` → `no-store`

---

## 17. Rate limits

- `/auth/login`, `/auth/password-reset` — 5/min/IP
- `/vouchers/redeem` — 10/min/session
- `/vies/check` — 30/min/session (with server-side cache hit bypass)
- All others — default 120/min/session

---

## Quick switch-on

```js
// In webshop-full.html, after the api-stub scripts:
window.WSShops.endpoint    = 'https://api.atelier.be/shops';
window.WSCatalog.endpoint  = 'https://api.atelier.be/catalog';
window.WSCalendar.endpoint = 'https://api.atelier.be/calendar';
window.WSOffices.endpoint  = 'https://api.atelier.be/offices';
window.WSBrand.endpoint    = 'https://api.atelier.be/brand';
window.WSPricing.endpoint  = 'https://api.atelier.be/pricing';
```

That's the entire integration surface on the frontend side. Everything
else is contract testing on the backend.
