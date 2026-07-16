# L'Atelier By — Backend API Specification

> **One-line activation**  
> Edit `api-config.js` and set `const BASE_URL = 'https://your-backend-host'`.  
> That single change wires all 11 stubs simultaneously.

## Conventions

- All requests and responses are `application/json`.
- All endpoints send `credentials: 'include'` (cookie-based sessions).
- Money values are decimal **euros** (`12.50`), never cents.
- Dates are ISO 8601: `2026-05-08` for calendar dates, `2026-05-08T11:30:00+02:00` for timestamps.
- IDs are opaque strings; never assume numeric.
- Error responses always use the format defined in [§ Error format](#error-format).

---

## Table of Contents

1. [Authentication — `/auth`](#1-authentication--auth)
2. [Shops — `/shops`](#2-shops--shops)
3. [Catalog — `/catalog`](#3-catalog--catalog)
4. [Calendar — `/calendar`](#4-calendar--calendar)
5. [Pricing — `/pricing`](#5-pricing--pricing)
6. [Vouchers — `/vouchers`](#6-vouchers--vouchers)
7. [Orders — `/orders`](#7-orders--orders)
8. [Offices — `/offices`](#8-offices--offices)
9. [Tours — `/tours`](#9-tours--tours)
10. [Brand — `/brand`](#10-brand--brand)
11. [VIES — `/vies`](#11-vies--vies)
12. [Data Shapes Reference](#12-data-shapes-reference)
13. [Error Format](#error-format)
14. [Auth & Authorization Summary](#auth--authorization-summary)
15. [Caching Headers](#caching-headers)
16. [Rate Limits](#rate-limits)
17. [Implementation Priority](#implementation-priority)

---

## 1. Authentication — `/auth`

Frontend stub: `webshop-auth-api.jsx` → `window.WSAuth`

Session is **cookie-based**: the server sets an HttpOnly, Secure, SameSite=Lax cookie on login/register and clears it on logout. The frontend never reads the cookie directly.

---

### POST /auth/login

Authenticate a customer with email + password.

**Request body**
```json
{
  "email": "marie@acme.be",
  "password": "secret"
}
```

**Response 200**
```json
{
  "user": {
    "id": "u1",
    "email": "marie@acme.be",
    "firstName": "Marie",
    "lastName": "Dubois",
    "officeId": "off-acme",
    "preferredShopId": "chatelain",
    "fidelityApp": {
      "active": true,
      "linkedAt": "2026-01-12T09:30:00Z"
    }
  }
}
```

**Response 401**
```json
{ "error": { "code": "invalid_credentials", "message": "Identifiants incorrects." } }
```

---

### POST /auth/register

Create a new customer account. Sets a session cookie on success.

**Request body**
```json
{
  "email": "nouveau@client.be",
  "password": "secret",
  "firstName": "Jean",
  "lastName": "Dupont"
}
```

**Response 201** — same `{ user }` shape as login.

**Response 409**
```json
{ "error": { "code": "email_taken", "message": "Un compte existe déjà avec cet email." } }
```

---

### POST /auth/logout

Clears the session cookie. No request body. Always succeeds.

**Response 200** — `{}`

---

### GET /auth/me

Returns the authenticated user, or 401 if no active session. Called on page load to restore a session.

**Response 200**
```json
{ "user": { ...same shape as login response... } }
```

**Response 401** — no active session (frontend silently treats user as logged out).

---

### PATCH /auth/me

Update the current user's profile. Only send the fields to update.

**Request body** (all fields optional)
```json
{
  "firstName": "Marie",
  "lastName": "Martin",
  "preferredShopId": "sablon",
  "fidelityApp": { "active": true, "linkedAt": "2026-05-08T10:00:00Z" }
}
```

**Response 200**
```json
{ "user": { ...updated user object... } }
```

---

### POST /auth/password-reset

Send a password-reset email. Always returns 200 regardless of whether the address exists (prevents user enumeration).

**Request body**
```json
{ "email": "marie@acme.be" }
```

**Response 200** — `{}`

---

## 2. Shops — `/shops`

Frontend stub: `webshop-shops-api.jsx` → `window.WSShops`

The shop list is the entry point of the app. It is cached in-memory after the first fetch.

---

### GET /shops

Return all active shops.

**Response 200**
```json
[
  {
    "id": "chatelain",
    "name": "Maison Châtelain",
    "city": "Bruxelles",
    "address": "Rue du Bailli 42, 1050 Ixelles",
    "accent": "#8D1D2C",
    "capabilities": {
      "collect": true,
      "delivery": true
    },
    "openingHours": {
      "mon": "07:00–19:00",
      "tue": "07:00–19:00",
      "wed": "07:00–19:00",
      "thu": "07:00–19:00",
      "fri": "07:00–19:00",
      "sat": "08:00–18:00",
      "sun": null
    }
  }
]
```

Wrapping with `{ "shops": [...] }` is also accepted by the stub.

| Field | Type | Notes |
|---|---|---|
| `id` | string | Slug — used as `shopId` everywhere |
| `name` | string | Display name |
| `city` | string | For grouping in the shop picker |
| `address` | string | Full street address |
| `accent` | string | CSS hex color for the shop chip pill |
| `capabilities.collect` | boolean | Click & Collect enabled |
| `capabilities.delivery` | boolean | Office delivery enabled |
| `openingHours` | object | Keys `mon`–`sun`, value `"HH:MM–HH:MM"` or `null` (closed) |

Only active shops are returned (`ws_shops.active = 1`). One storefront serves every
shop; `webshop-shop-router.jsx` (`window.WSShopRouter`) tracks which `shopId` is active
and every API call is scoped by it.

### Managing shops

Shops live in `ws_shops`. Seed the 5 real shops with `backend/schema/seed-shops.sql`,
and add/edit shops in phpMyAdmin (or via the back-office). Set `active = 1` to make a
shop appear in `/shops`.

### GET /webshop-link

Resolves the webshop URL for a logged-in PWA user, so the **PWA footer** can deep-link
to *their preferred shop's* webshop instead of the generic storefront.

```
GET /webshop-link?clientId=123
→ { "url": "https://…/webshop?shop=halle", "shopId": 3, "slug": "halle" }
```

Resolution: `client.preferred_shop_id` → shop, then the URL is
1. the shop's absolute link `shops.landing_config.webshop_url` if set, else
2. `<webshop_base>?shop=<slug>` (`webshop_base` in `php-api/config.php`), else
3. `<webshop_base>` (not logged in / no preferred shop / column absent).

Works before and after the shops unification (`shops` else `ws_shops`) and never 500s
if `client.preferred_shop_id` doesn't exist yet — it falls back to the generic link.

---

## 3. Catalog — `/catalog`

Frontend stub: `webshop-catalog-api.jsx` → `window.WSCatalog`

---

### GET /catalog/products

Return products, optionally filtered.

**Query params**

| Param | Type | Notes |
|---|---|---|
| `shopId` | string | Filter to products available at this shop |
| `cat` | string | Filter by top-level category (e.g. `tarts`, `breads`, `sandwiches`) |

**Response 200** — array of [Product](#product)

---

### GET /catalog/products/:id

Return a single product by id.

**Response 200** — [Product](#product)  
**Response 404** — not found

---

### GET /catalog/bundles

Return bundle meal-deal plans for a product.

**Query params**

| Param | Required |
|---|---|
| `productId` | Yes |

**Response 200** — array of [Bundle](#bundle)

---

### GET /catalog/assortments

Return seasonal / themed assortments displayed on the homepage hero.

**Query params**

| Param | Notes |
|---|---|
| `shopId` | Optional — return assortments available at that shop |

**Response 200**
```json
[
  {
    "id": "paques",
    "label": "Pâques",
    "img": "https://cdn.atelier.be/season-paques.png",
    "tagline": "Sélection chocolatée — disponible jusqu'au 7 avril",
    "validFrom": "2026-03-01",
    "validUntil": "2026-04-07",
    "products": ["p-chocolat-paques", "p-oeuf-noisette"]
  }
]
```

---

## 4. Calendar — `/calendar`

Frontend stub: `webshop-calendar-api.jsx` → `window.WSCalendar`

Three sub-endpoints control the date picker, time slot selector, and order cutoff enforcement.

---

### GET /calendar/days

Day-level availability over a date range. Used to grey out unavailable days in the calendar picker.

**Query params**

| Param | Type | Required | Notes |
|---|---|---|---|
| `shopId` | string | Yes | |
| `mode` | `collect` \| `delivery` | Yes | |
| `from` | `YYYY-MM-DD` | Yes | Start of window (inclusive) |
| `to` | `YYYY-MM-DD` | Yes | End of window (inclusive, typically +2 months) |

**Response 200**
```json
[
  { "iso": "2026-05-08", "available": true,  "reason": null },
  { "iso": "2026-05-09", "available": false, "reason": "closed" },
  { "iso": "2026-05-10", "available": false, "reason": "fully_booked" },
  { "iso": "2026-05-11", "available": false, "reason": "cutoff" }
]
```

`reason` values: `closed` (shop closed that day), `fully_booked` (capacity reached), `cutoff` (past the order deadline for that day), `holiday`.

**Demo fallback rules**

| Mode | Open days |
|---|---|
| collect | Monday – Saturday |
| delivery | Monday – Friday |

---

### GET /calendar/slots

Available time slots for a specific day.

**Query params**

| Param | Type | Required |
|---|---|---|
| `shopId` | string | Yes |
| `mode` | `collect` \| `delivery` | Yes |
| `date` | `YYYY-MM-DD` | Yes |

**Response 200**
```json
[
  { "id": "s-08", "label": "08:00–09:00", "capacity": 20, "remaining": 14 },
  { "id": "s-09", "label": "09:00–10:00", "capacity": 20, "remaining": 0, "soldOut": true },
  { "id": "d-am", "label": "08:30–10:30" }
]
```

`capacity` and `remaining` are optional. If omitted the UI renders all returned slots as selectable.

**Demo fallback slot sets**

| Mode | Slots |
|---|---|
| collect | 08:00–09:00, 09:00–10:00, 10:00–11:00, 12:00–13:00, 14:00–15:00, 16:00–17:00 |
| delivery | 08:30–10:30, 11:30–13:30 |

---

### GET /calendar/cutoff

The ordering cutoff rule for a shop/mode combination. Used to disable same-day slots that are too close.

**Query params**

| Param | Type | Required |
|---|---|---|
| `shopId` | string | Yes |
| `mode` | `collect` \| `delivery` | Yes |

**Response 200**
```json
{
  "hour": 16,
  "minutes": 0,
  "leadHours": 2
}
```

`leadHours` — minimum number of hours before the desired pickup/delivery time that an order must be placed. The UI uses this to grey out slots on the selected date.

**Demo fallback values**

| Mode | Cutoff | Lead time |
|---|---|---|
| collect | 16:00 | 2 h |
| delivery | 11:00 | 20 h |

---

## 5. Pricing — `/pricing`

Frontend stub: `webshop-pricing-api.jsx` → `window.WSPricing`

The frontend must not contain pricing logic. All discount computation happens server-side and is returned via `/pricing/quote`.

---

### POST /pricing/quote

Compute the basket total, applying all active discounts (cross-portion promo, pickup discount, voucher, etc.). The frontend renders exactly what the server returns.

**Request body**
```json
{
  "shopId": "chatelain",
  "mode": "collect",
  "basket": [
    {
      "productId": 1,
      "name": "Tarte aux fraises",
      "qty": 2,
      "portion": "demi",
      "price": 12.00,
      "basePrice": 24.00,
      "crossPortion": true,
      "options": [
        { "id": "sauce", "choiceId": "pesto", "delta": 0.5 }
      ],
      "bundleId": null,
      "bundleSlots": null
    }
  ],
  "voucher": "BIENVENUE10"
}
```

**Basket line fields**

| Field | Type | Notes |
|---|---|---|
| `productId` | string/number | |
| `name` | string | Display name |
| `qty` | number | |
| `portion` | `quart` \| `demi` \| `entier` \| null | Only for portionable products |
| `price` | number | Line unit price (after portion + options) |
| `basePrice` | number | Full product price (for cross-portion value calc) |
| `crossPortion` | boolean | Whether this line participates in the 4+1 promo |
| `options` | array | Selected options (`choiceId` + `delta`) |
| `bundleId` | string \| null | Selected bundle plan id |
| `bundleSlots` | object \| null | Bundle slot selections: `{ slotId: { id, label, delta } }` |

**Response 200**
```json
{
  "subtotal": 24.00,
  "discounts": [
    {
      "code": "cross-portion",
      "label": "1 quart offert · Tarte aux fraises",
      "amount": 6.48,
      "meta": {
        "cycles": 1,
        "freeCount": 1,
        "freeNames": ["Tarte aux fraises"],
        "threshold": 4,
        "toNext": 0
      }
    },
    { "code": "pickup-5",  "label": "Retrait −5%",    "amount": 1.20 },
    { "code": "voucher",   "label": "−10% appliqué",  "amount": 2.40 }
  ],
  "total": 13.92,
  "voucher": {
    "ok": true,
    "code": "BIENVENUE10",
    "discount": 2.40,
    "message": "−10% appliqué"
  },
  "lines": [
    {
      "productId": 1,
      "lineTotal": 24.00,
      "appliedOffers": ["cross-portion"]
    }
  ],
  "cross": {
    "eligibleCount": 4,
    "groupSize": 5,
    "cycles": 0,
    "freeCount": 0,
    "savings": 0,
    "toNext": 1,
    "status": "dormant",
    "threshold": 4
  }
}
```

**discount.code values**

| Code | Trigger |
|---|---|
| `cross-portion` | Cross-product 4+1 portions promo |
| `pickup-5` | 5% discount for Click & Collect mode |
| `voucher` | Applied voucher code |
| custom | Any additional server-side promotion |

**cross.status values**: `dormant` (threshold not yet reached), `active` (1 free cycle), `boosted` (2+ free cycles).

---

### GET /pricing/payment-methods

Return payment methods available for a specific shop and order mode.

**Query params**

| Param | Notes |
|---|---|
| `shopId` | Optional |
| `mode` | `collect` \| `delivery` (optional) |

**Response 200**
```json
[
  { "id": "bancontact", "label": "Bancontact",      "sub": "Paiement instantané" },
  { "id": "visa",       "label": "Carte bancaire",  "sub": "Visa · Mastercard · Amex" },
  { "id": "paypal",     "label": "PayPal",           "sub": "Compte PayPal" },
  { "id": "invoice",    "label": "Facture office",   "sub": "Réservé Office validés", "requires": "validated_office" }
]
```

`id` is sent verbatim as `payment.method` in the order payload.

---

### GET /pricing/promos/cross-portion

Return the active cross-portion "4+1" promo configuration. Different shops may run different rules.

**Query params**

| Param | Notes |
|---|---|
| `shopId` | Optional |

**Response 200**
```json
{
  "x": 4,
  "y": 1,
  "threshold": 4,
  "label": "4 quarts achetés, 1 offert (le moins cher)",
  "quarterValueFactor": 0.27,
  "eligibleCats": ["tarts", "plats"]
}
```

`quarterValueFactor` — the free quarter's monetary value = `product.basePrice × quarterValueFactor`.  
Example: tarte at €24 → 1 quarter = €6.48 → free quarter worth €6.48.  
`eligibleCats` — if provided, only products in these categories participate in the promo.

---

## 6. Vouchers — `/vouchers`

Frontend stub: `webshop-vouchers.jsx` → `window.WSVouchers`

The admin app manages vouchers (create / edit / deactivate). The storefront only calls `POST /vouchers/redeem`.

---

### POST /vouchers/redeem

Validate a voucher code server-side and atomically increment `usage.used` on success.

**Request body**
```json
{
  "code": "BIENVENUE10",
  "shopId": "chatelain",
  "subtotal": 28.50,
  "basket": [
    { "productId": 1, "qty": 1, "price": 12.00 }
  ]
}
```

**Response 200 — valid**
```json
{
  "ok": true,
  "voucher": {
    "id": "v_seed1",
    "code": "BIENVENUE10",
    "type": "percent",
    "value": 10,
    "scope": "order"
  },
  "discount": 2.85,
  "message": "−10% appliqué"
}
```

**Response 200 — invalid** (always HTTP 200; `ok: false` signals the error)
```json
{
  "ok": false,
  "reason": "minOrder",
  "message": "Minimum €25.00 requis"
}
```

**reason values**

| Value | Meaning |
|---|---|
| `unknown` | Code does not exist |
| `expired` | Past `validUntil` date |
| `scheduled` | Before `validFrom` date |
| `exhausted` | `usage.used >= usage.limit` |
| `channel` | Code is office-only, not valid on the webshop |
| `shop` | Code is restricted to specific shops not including this one |
| `minOrder` | Basket subtotal is below `minOrder` threshold |
| `category` | Basket contains no items from the required category |

---

### GET /vouchers

Admin-only. Return all vouchers.

**Response 200** — array of [Voucher](#voucher)

---

### POST /vouchers

Admin-only. Create a new voucher.

**Request body** — [Voucher](#voucher) (without `id`, `usage.used`)

**Response 201** — created Voucher

---

### PATCH /vouchers/:id

Admin-only. Update a voucher (change value, extend validity, deactivate, etc.).

**Request body** — partial Voucher fields

**Response 200** — updated Voucher

---

### DELETE /vouchers/:id

Admin-only. Delete a voucher permanently.

**Response 204** — no content

---

## 7. Orders — `/orders`

Frontend stub: `webshop-orders-api.jsx` → `window.WSOrders`

---

### POST /orders

Place a new order. This is the final step of the checkout wizard. The server validates slot availability, inventory, voucher, and payment before confirming.

**Request body**
```json
{
  "shopId": "chatelain",
  "mode": "collect",
  "slot": {
    "slotId": "s-09",
    "label": "09:00–10:00",
    "date": "2026-05-12"
  },
  "basket": [
    {
      "productId": 1,
      "name": "Tarte aux fraises",
      "qty": 1,
      "portion": "demi",
      "price": 12.00,
      "basePrice": 24.00,
      "crossPortion": true,
      "options": [
        { "id": "sauce", "choiceId": "pesto", "delta": 0.5 }
      ],
      "bundleId": null,
      "bundleSlots": null
    },
    {
      "productId": 20,
      "name": "Sandwich Club",
      "qty": 2,
      "price": 9.50,
      "options": [
        { "id": "bread", "choiceId": "white", "delta": 0 },
        { "id": "sauce", "choiceId": "mayo",  "delta": 1.0 }
      ],
      "bundleId": "b-full",
      "bundleSlots": {
        "drink":   { "id": "d2", "label": "Limonade maison", "delta": 0.5 },
        "dessert": { "id": "s1", "label": "Cookie",          "delta": 0 }
      }
    }
  ],
  "voucher": "BIENVENUE10",
  "customer": {
    "id": "u1",
    "email": "marie@acme.be",
    "firstName": "Marie",
    "lastName": "Dubois",
    "phone": "+32 472 11 22 33",
    "officeId": "off-acme"
  },
  "payment": {
    "method": "bancontact"
  },
  "delivery": {
    "officeId": "off-acme",
    "tourId": "tour-bxl-mid",
    "address": "Rue de la Loi 120, 1040 Bruxelles"
  },
  "total": 34.65,
  "invoice": {
    "companyName": "ACME Avocats",
    "vatNumber": "BE0123456789",
    "address": "Rue de la Loi 120, 1040 Bruxelles"
  }
}
```

**Field notes**
- `delivery` — only included when `mode = "delivery"`.
- `customer.id` — only included when the user is authenticated.
- `invoice` — only included when the customer requests a VAT invoice (B2B checkout flow).

**Response 201**
```json
{
  "orderId": "ord-abc123",
  "status": "pending_payment",
  "total": 34.65,
  "slot": "09:00–10:00",
  "payment": "bancontact",
  "paymentUrl": "https://payment.provider.com/pay/xyz"
}
```

`paymentUrl` — optional. If present the UI redirects the user to the payment provider immediately after order creation.

**Response 422 — rejected**
```json
{ "error": { "code": "slot_full", "message": "Ce créneau n'est plus disponible." } }
```

Common error codes: `slot_full`, `product_unavailable`, `voucher_invalid`, `payment_failed`, `cutoff_passed`.

---

### GET /orders/:id

Fetch a single order (used on the confirmation screen and order detail page).

**Response 200** — [Order](#order) object  
**Response 403** — order belongs to a different customer  
**Response 404** — not found

---

### GET /orders/me

Return all orders placed by the current authenticated user, newest first.

**Response 200** — array of [Order](#order) objects

---

### POST /orders/:id/cancel

Request cancellation. The server enforces business rules (cutoff time, current status, etc.).

**Response 200**
```json
{ "ok": true }
```

**Response 422**
```json
{ "ok": false, "error": { "code": "too_late", "message": "La commande ne peut plus être annulée." } }
```

---

## 8. Offices — `/offices`

Frontend stub: `webshop-offices-api.jsx` → `window.WSOffices`

An office is a B2B company account. A customer links their user account to an office to unlock the delivery mode.

---

### GET /offices

Return offices the customer can link to. The storefront always requests `?status=validated`.

**Query params**

| Param | Values |
|---|---|
| `status` | `pending` \| `validated` \| `rejected` |

**Response 200** — array of [Office](#office)

---

### GET /offices/:id

Return a single office by id (any status).

**Response 200** — [Office](#office)  
**Response 404** — not found

---

### POST /offices

Customer self-registers a new office delivery request. Created with `status: "pending"` and requires admin approval before a `tourId` is assigned.

**Request body**
```json
{
  "name": "New Company SPRL",
  "contact": "Sophie Martin",
  "phone": "+32 471 00 11 22",
  "email": "sophie@newco.be",
  "address": "Avenue Louise 50, 1050 Bruxelles"
}
```

**Response 201** — [Office](#office) (with `status: "pending"`, `tourId: null`)

---

### PATCH /offices/:id *(admin)*

Approve, reject, or re-assign the delivery tour for an office.

**Request body**
```json
{ "status": "validated", "tourId": "tour-bxl-mid" }
```

**Response 200** — updated [Office](#office)

---

**Office lifecycle**
```
pending → validated  (admin assigns tourId and approves)
        → rejected   (admin rejects)
```

A user can only order in delivery mode when:
1. `user.officeId` points to an office with `status = "validated"`
2. That office has a non-null `tourId`

---

## 9. Tours — `/tours`

Frontend stub: `webshop-tours-api.jsx` → `window.WSTours`

Delivery tours define the recurring delivery windows per shop.

---

### GET /tours

Return all tours, optionally filtered by shop.

**Query params**

| Param | Notes |
|---|---|
| `shopId` | Optional — filter by shop |

**Response 200**
```json
[
  {
    "id": "tour-bxl-mid",
    "name": "Bruxelles Midi",
    "shopId": "chatelain",
    "window": "11:30–13:30",
    "days": "lun-ven",
    "active": true
  }
]
```

Wrapping with `{ "tours": [...] }` or `{ "data": [...] }` is also accepted by the stub.

---

### GET /tours/:id

Return a single tour by id.

**Response 200** — [Tour](#tour)  
**Response 404** — not found

---

### POST /tours · PATCH /tours/:id · DELETE /tours/:id *(admin)*

Standard CRUD for admin tour management.

---

## 10. Brand — `/brand`

Frontend stub: `webshop-brand-api.jsx` → `window.WSBrand`

Called on first load and whenever the active shop changes. Applies CSS custom properties, injects web fonts, and optionally overrides i18n strings.

---

### GET /brand

Return theming configuration for a shop (or global defaults if no `shopId` given).

**Query params**

| Param | Notes |
|---|---|
| `shopId` | Optional — enables per-shop theming |

**Response 200**
```json
{
  "tokens": {
    "color-primary": "#8D1D2C",
    "color-text": "#1F1612",
    "color-bg": "#FAF7F5",
    "radius-card": "14px"
  },
  "fonts": [
    {
      "family": "Souvenir",
      "url": "https://cdn.atelier.be/fonts/Souvenir-Light.woff2",
      "weight": 300,
      "style": "normal"
    }
  ],
  "logo": "https://cdn.atelier.be/logo.svg",
  "strings": {
    "nav.collect": "Click & Collect",
    "nav.delivery": "Livraison au bureau"
  }
}
```

`tokens` — each key is written to `:root` as `--<key>` CSS custom property.  
`fonts` — injected as `@font-face` rules in `<head>`.  
`strings` — optional i18n overrides merged into `window.WSI18n`.  
All four fields are optional; an empty response is valid (defaults remain).

---

## 11. VIES — `/vies`

Frontend stub: `webshop-vies.jsx` → `window.WSVies`

Validates EU VAT numbers for the B2B invoice checkout flow. VIES has no CORS-friendly browser endpoint, so all validation must be proxied through your backend (with caching and rate limiting).

---

### GET /vies

**Query params**

| Param | Type | Required | Notes |
|---|---|---|---|
| `country` | ISO 2-letter country code | Yes | e.g. `BE`, `NL`, `FR` |
| `vat` | string | Yes | With or without country prefix |

**Response 200 — valid**
```json
{
  "valid": true,
  "data": {
    "vat": "BE0123456789",
    "country": "BE",
    "name": "BAKERY ATELIER SA",
    "address": "Rue du Pain 12",
    "postalCode": "1000",
    "city": "Bruxelles"
  }
}
```

**Response 200 — invalid**
```json
{
  "valid": false,
  "error": {
    "code": "invalid",
    "message": "Ce numéro de TVA n'a pas été reconnu."
  }
}
```

**Response 200 — VIES service unavailable**
```json
{
  "valid": false,
  "error": {
    "code": "unavailable",
    "message": "VIES indisponible. Veuillez réessayer."
  }
}
```

Always return HTTP 200 with `valid: false` on error. A 5xx would break the checkout flow — the user needs a meaningful message.

---

## 12. Data Shapes Reference

### Product

```json
{
  "id": 1,
  "cat": "tarts",
  "subCat": "tarts-fruit",
  "name": "Tarte aux fraises",
  "description": "Fraises gariguette, crème diplomate, pâte sablée maison.",
  "price": 24.00,
  "img": "https://cdn.atelier.be/products/tarte-fraises.png",
  "allergens": ["gluten", "milk", "egg"],
  "badge": "4+1",
  "portions": true,
  "portionUnits": { "quart": 1, "demi": 2, "entier": 4 },
  "crossPortion": true,
  "offer": {
    "type": "buy_x_get_y_free",
    "x": 4,
    "y": 1,
    "unit": "portion"
  },
  "options": [
    {
      "id": "sauce",
      "label": "Accompagnement",
      "required": false,
      "kind": "single",
      "choices": [
        { "id": "none",  "label": "Sans",          "delta": 0 },
        { "id": "pesto", "label": "Pesto maison",  "delta": 0.5 }
      ]
    }
  ],
  "upsells": [
    { "id": "salad", "label": "Petite salade", "img": "...", "delta": 4.5 },
    { "id": "soup",  "label": "Soupe du jour", "img": "...", "delta": 4.0 }
  ],
  "available_bundles": [],
  "stock": { "available": true, "soldOut": false, "lowStock": false }
}
```

**offer.type values**

| Type | Behaviour |
|---|---|
| `buy_x_get_y_free` | Buy X (counted in `unit`), get Y free (cheapest item) |
| `second_at_pct` | Second unit at `pct`% off |

**unit values**: `portion` (counts in portionUnits), `piece` (counts by quantity).

**allergen values** (EU 14 major allergens): `gluten`, `crustacean`, `egg`, `fish`, `peanut`, `soy`, `milk`, `almond`, `celery`, `mustard`, `sesame`, `sulphite`, `lupin`, `mollusc`.

---

### Bundle

A meal-deal plan attached to a product (e.g. Sandwich + drink + dessert).

```json
{
  "id": "b-full",
  "name": "Full Menu",
  "description": "1 Sandwich Club + 1 boisson + 1 dessert",
  "included": [{ "label": "Sandwich Club" }],
  "slots": [
    {
      "id": "drink",
      "label": "Boisson",
      "required": true,
      "choices": [
        { "id": "d1", "label": "Eau plate 33cl",  "img": "...", "delta": 0 },
        { "id": "d2", "label": "Limonade maison", "img": "...", "delta": 0.5 },
        { "id": "d3", "label": "Café",             "img": "...", "delta": 0 }
      ]
    },
    {
      "id": "dessert",
      "label": "Dessert",
      "required": true,
      "choices": [
        { "id": "s1", "label": "Cookie",    "img": "..." },
        { "id": "s2", "label": "Cupcake",   "img": "..." },
        { "id": "s3", "label": "Madeleine", "img": "..." }
      ]
    }
  ],
  "price_modifier": 5.50,
  "advantages": ["Économisez 2,50 €", "Boisson + dessert inclus"],
  "recommended": true
}
```

`price_modifier` — added to the base product price when this bundle is selected.

---

### User

```json
{
  "id": "u1",
  "email": "marie@acme.be",
  "firstName": "Marie",
  "lastName": "Dubois",
  "officeId": "off-acme",
  "preferredShopId": "chatelain",
  "fidelityApp": {
    "active": true,
    "linkedAt": "2026-01-12T09:30:00Z"
  }
}
```

`fidelityApp.active` — whether the user has linked the physical loyalty-card mobile app.  
`officeId` — if set and the office is validated with a tour, the delivery mode is unlocked.

---

### Office

```json
{
  "id": "off-acme",
  "name": "ACME Avocats",
  "contact": "Marie Dubois",
  "phone": "+32 472 11 22 33",
  "email": "marie@acme.be",
  "address": "Rue de la Loi 120, 1040 Bruxelles",
  "tourId": "tour-bxl-mid",
  "status": "validated"
}
```

`status`: `pending` | `validated` | `rejected`

---

### Tour

```json
{
  "id": "tour-bxl-mid",
  "name": "Bruxelles Midi",
  "shopId": "chatelain",
  "window": "11:30–13:30",
  "days": "lun-ven",
  "active": true
}
```

---

### Voucher

```json
{
  "id": "v_seed1",
  "code": "BIENVENUE10",
  "type": "percent",
  "value": 10,
  "minOrder": 0,
  "scope": "order",
  "scopeRef": null,
  "channel": "webshop",
  "shopIds": [],
  "validFrom": "2026-01-01",
  "validUntil": "2026-12-31",
  "usage": { "used": 142, "limit": null },
  "status": "active"
}
```

**type values**: `percent`, `amount` (fixed euro), `shipping` (free delivery signal).  
**scope values**: `order` (applies to entire subtotal), `category` (only products in `scopeRef` category).  
**channel values**: `webshop` (storefront), `office` (B2B admin only — rejected on the webshop).  
**status values**: `active`, `scheduled` (before validFrom), `expired`, `exhausted`, `disabled`.

---

### Order

```json
{
  "orderId": "ord-abc123",
  "status": "confirmed",
  "shopId": "chatelain",
  "mode": "collect",
  "slot": {
    "slotId": "s-09",
    "label": "09:00–10:00",
    "date": "2026-05-12"
  },
  "basket": [...],
  "customer": { "id": "u1", "email": "marie@acme.be", "firstName": "Marie", "lastName": "Dubois" },
  "payment": { "method": "bancontact" },
  "delivery": null,
  "voucher": "BIENVENUE10",
  "total": 34.65,
  "invoice": null,
  "createdAt": "2026-05-08T14:23:00Z",
  "paymentUrl": null
}
```

**Order status values**

| Status | Meaning |
|---|---|
| `pending_payment` | Created, awaiting payment confirmation |
| `confirmed` | Payment received, order accepted |
| `ready` | Ready for pickup / out for delivery |
| `completed` | Delivered or collected by customer |
| `cancelled` | Cancelled by customer or admin |

---

## Error Format

All non-2xx responses must follow this shape:

```json
{
  "error": {
    "code": "voucher_min_order",
    "message": "Minimum €25.00 requis",
    "details": {
      "required": 25.00,
      "subtotal": 18.40
    }
  }
}
```

`code` — machine-readable slug (used by the frontend for conditional logic).  
`message` — human-readable French string displayed directly to the user.  
`details` — optional extra context (not displayed, used for debugging).

---

## Auth & Authorization Summary

| Endpoint / resource | Auth required | Role |
|---|---|---|
| `GET /shops`, `GET /catalog/*`, `GET /calendar/*` | None | Guest |
| `POST /pricing/quote`, `POST /vouchers/redeem` | None | Guest |
| `GET /brand`, `GET /vies` | None | Guest |
| `POST /auth/login`, `POST /auth/register`, `POST /auth/password-reset` | None | Guest |
| `GET /auth/me`, `PATCH /auth/me` | Session cookie | Customer |
| `POST /orders`, `GET /orders/me` | Session cookie | Customer |
| `GET /orders/:id`, `POST /orders/:id/cancel` | Session cookie | Owner or admin |
| `POST /offices` | Session cookie | Customer |
| `GET /offices/:id` | Session cookie | Customer (own office) or admin |
| `PATCH /offices/:id` | Session cookie | Admin |
| `GET /vouchers`, `POST /vouchers`, `PATCH /vouchers/:id`, `DELETE /vouchers/:id` | Session cookie | Admin |
| `POST /tours`, `PATCH /tours/:id`, `DELETE /tours/:id` | Session cookie | Admin |

Use HttpOnly session cookies for authentication. Add a `X-CSRF-Token` header on all mutation requests.

**CSRF token setup** (already scaffolded in `api-config.js`):
```js
document.addEventListener('wsauth:login', function (e) {
  const token = e.detail && e.detail.csrfToken;
  if (token) window._WS_CSRF = token;
});
```

---

## Caching Headers

| Endpoint | Cache-Control |
|---|---|
| `GET /shops` | `public, max-age=60, stale-while-revalidate=300` |
| `GET /catalog/products`, `GET /catalog/assortments` | `public, max-age=60, stale-while-revalidate=300` |
| `GET /brand` | `public, max-age=300, stale-while-revalidate=3600` |
| `GET /calendar/days`, `GET /calendar/slots` | `private, max-age=30` |
| `GET /calendar/cutoff`, `GET /pricing/promos/*` | `public, max-age=300` |
| `POST /pricing/quote` | `no-store` |
| `GET /auth/me`, `PATCH /auth/me` | `no-store` |
| `POST /orders`, `GET /orders/*` | `no-store` |
| `GET /vies` | Server-side cache keyed by VAT number (recommended TTL: 24 h) |

---

## Rate Limits

| Endpoint | Limit |
|---|---|
| `POST /auth/login`, `POST /auth/password-reset` | 5 req/min per IP |
| `POST /auth/register` | 3 req/min per IP |
| `POST /vouchers/redeem` | 10 req/min per session |
| `GET /vies` | 30 req/min per session (bypassed on server cache hit) |
| All other endpoints | 120 req/min per session |

---

## Implementation Priority

Build in this order to unlock each storefront feature progressively.

| Priority | Endpoint(s) | Unlocks |
|---|---|---|
| 1 | `GET /shops` | Shop selector, app bootstrap |
| 2 | `GET /catalog/products` | Product grid and categories |
| 3 | `GET /calendar/days` + `/slots` + `/cutoff` | Date/time picker in checkout |
| 4 | `POST /auth/login` + `POST /auth/register` + `GET /auth/me` | Login / registration modal |
| 5 | `POST /pricing/quote` | Live basket totals and discount lines |
| 6 | `POST /vouchers/redeem` | Promo code input |
| 7 | `POST /orders` | Checkout completion |
| 8 | `GET /orders/me` + `GET /orders/:id` | Order history and confirmation page |
| 9 | `GET /offices` + `POST /offices` | B2B office self-registration |
| 10 | `GET /tours` | Delivery mode and tour assignment |
| 11 | `GET /brand` | Per-shop CSS theming |
| 12 | `GET /vies` | B2B VAT invoice (VIES proxy) |

---

## Deep Links

The admin panel generates shareable deep-link URLs. They are parsed client-side by `parseDeepLink()` in `webshop-vouchers.jsx` — no backend endpoint is needed.

```
https://atelier.be/?shop=chatelain&mode=collect&voucher=BIENVENUE10&category=tarts&product=1&open=product
```

| Param | Effect |
|---|---|
| `shop` | Pre-selects the shop |
| `mode` | Pre-selects `collect` or `delivery` |
| `voucher` | Pre-fills the voucher code field |
| `category` | Scrolls to the category |
| `product` | Pre-selects a product |
| `open=product` | Opens the product detail drawer |

Note: the voucher in the URL is still validated server-side via `POST /vouchers/redeem` at quote time.
