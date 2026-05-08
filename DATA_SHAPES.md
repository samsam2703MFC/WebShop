# L'Atelier By — Precise Data Shapes

> This document lists **every field the UI actually reads**, with the exact
> component and context where it is consumed. If a field is not listed here,
> the frontend ignores it. Treat any field marked **required** as a hard
> dependency — a missing or null value will break the UI.

---

## Table of Contents

1. [Product](#1-product)
2. [Option group & choices](#2-option-group--choices)
3. [Upsell](#3-upsell)
4. [Bundle plan](#4-bundle-plan)
5. [Bundle slot & slot choices](#5-bundle-slot--slot-choices)
6. [Category & Subcategory](#6-category--subcategory)
7. [Assortment](#7-assortment)
8. [Basket line (client-side model)](#8-basket-line-client-side-model)
9. [Shop](#9-shop)
10. [User](#10-user)
11. [Office](#11-office)
12. [Tour](#12-tour)
13. [Voucher](#13-voucher)
14. [Pricing quote response](#14-pricing-quote-response)
15. [Calendar day](#15-calendar-day)
16. [Calendar slot](#16-calendar-slot)
17. [Calendar cutoff](#17-calendar-cutoff)
18. [Payment method](#18-payment-method)
19. [Cross-portion promo rule](#19-cross-portion-promo-rule)
20. [Brand / theme config](#20-brand--theme-config)
21. [VIES VAT validation response](#21-vies-vat-validation-response)
22. [Portion system explained](#22-portion-system-explained)

---

## 1. Product

Returned by `GET /catalog/products` and `GET /catalog/products/:id`.

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
  "crossPortion": true,
  "offer": { ... },
  "options": [ ... ],
  "upsells": [ ... ],
  "available_bundles": [ ... ],
  "has_menu_options": true,
  "portionUnits": { "quart": 1, "demi": 2, "entier": 4 },
  "stock": { "available": true, "soldOut": false }
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | number \| string | **yes** | Basket line `productId`, `getProduct()`, `listBundles()` |
| `cat` | string | **yes** | Category filtering, placeholder image fallback, eyebrow label in product detail |
| `subCat` | string | no | Sub-category filtering, more precise placeholder fallback |
| `name` | string | **yes** | Product card, product detail header, basket line label |
| `description` | string | no | Product detail modal body text (falls back to a generic line if null) |
| `price` | number | **yes** | Base price — all portion/option/bundle deltas build on this value |
| `img` | string \| null | no | Product card photo and detail hero. `null` triggers a category-based SVG placeholder |
| `allergens` | string[] | no | Allergen icon row on card and in product detail. Empty array = row hidden |
| `badge` | string \| null | no | Small pill overlaid on photo (`"4+1"`, `"Du jour"`, `"Nouveau"`, etc.) |
| `portions` | boolean | **yes** | `true` → shows the quart/demi/entier portion selector in the detail modal |
| `crossPortion` | boolean | no | `true` → line participates in the basket-level 4+1 cross-portion promo |
| `offer` | object \| null | no | Single-product offer strip (buy-x-get-y or second-at-pct). See [Offer](#offer) |
| `options` | array | no | Customisation option groups (bread choice, sauce, etc.). See [§ 2](#2-option-group--choices) |
| `upsells` | array | no | Add-on upsell chips (salad, soup, drink). See [§ 3](#3-upsell) |
| `available_bundles` | array | no | Meal-deal plans. See [§ 4](#4-bundle-plan). **Required when `has_menu_options: true`** |
| `has_menu_options` | boolean | no | `true` → renders the bundle carousel in the detail modal |
| `portionUnits` | object | no | Override the default `{quart:1, demi:2, entier:4}` unit mapping. Needed if product slices differently (e.g. 6-piece cake vs 4-piece tart) |
| `no_delivery` | boolean | no | `true` → product is collect-only. Delivery mode shows "Retrait seulement" badge, add button disabled, CTA blocked in detail modal |
| `delivery_stock` | integer \| null | no | Maximum units available for delivery per ordering window. `null`/absent = unlimited. UI shows remaining count; add button and qty spinner capped at this value |
| `stock.available` | boolean | no | Reserved for future low-stock / sold-out state (not currently rendered) |

### Offer

Nested inside `product.offer`. Controls the per-product offer strip and the `+1` nudge button.

```json
{
  "type": "buy_x_get_y_free",
  "x": 4,
  "y": 1,
  "unit": "portion"
}
```

```json
{
  "type": "second_at_pct",
  "pct": 30,
  "unit": "piece"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `type` | string | **yes** | `"buy_x_get_y_free"` or `"second_at_pct"` |
| `x` | number | yes (for buy_x) | Number to buy |
| `y` | number | yes (for buy_x) | Number given free (always the cheapest) |
| `pct` | number | yes (for second_at_pct) | Discount % on the second unit |
| `unit` | `"portion"` \| `"piece"` | **yes** | `"portion"` = count in portion-units (1/4/2/4), `"piece"` = count by qty |

---

## 2. Option group & choices

Nested inside `product.options[]`.  
Rendered as a segmented radio group in the product detail modal.  
Required groups block the "Ajouter au panier" CTA until satisfied.

```json
{
  "id": "sauce",
  "label": "Accompagnement",
  "required": false,
  "kind": "single",
  "choices": [
    { "id": "none",  "label": "Sans",          "delta": 0   },
    { "id": "pesto", "label": "Pesto maison",  "delta": 0.5 },
    { "id": "tomato","label": "Coulis tomate", "delta": 0.5 }
  ]
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Key in `sel` state, sent in basket `options[]` |
| `label` | string | **yes** | Group header text above the segmented control |
| `required` | boolean | **yes** | `true` → shows "Requis" tag, auto-opens accordion, blocks add CTA if empty |
| `kind` | string | no | Currently only `"single"` is implemented (radio behaviour) |
| `choices` | array | **yes** | Min 1 choice |

### Choice

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Stored in `sel[optionId]`, sent in order payload |
| `label` | string | **yes** | Button text |
| `delta` | number | no | Added to unit price. Shown as `"+€X.XX"` in the button if `> 0`. `0` or absent = no badge |

---

## 3. Upsell

Nested inside `product.upsells[]`.  
Rendered as image-chip toggles in the "Pour accompagner" accordion.

```json
{
  "id": "salad",
  "label": "Petite salade",
  "img": "https://cdn.atelier.be/icons/salads.png",
  "delta": 4.5
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Toggle state key |
| `label` | string | **yes** | Chip text |
| `img` | string \| null | no | If present → `pdm-imgchip` with image tile; if absent → plain `pdm-chip` |
| `delta` | number | **yes** | Price addition. Shown as `"+X.XX"` in the chip |

---

## 4. Bundle plan

Nested inside `product.available_bundles[]`.  
Rendered as a horizontal carousel of cards in the product detail modal.  
"À la carte" (no bundle) is always prepended automatically by the UI — do **not** include it in the API response.

```json
{
  "id": "b-full",
  "name": "Full Menu",
  "description": "1 Sandwich Club + 1 boisson + 1 dessert",
  "included": [
    { "label": "Sandwich Club" }
  ],
  "slots": [ ... ],
  "price_modifier": 5.50,
  "advantages": ["Économisez 2,50 €", "Le plus complet"],
  "recommended": true
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Sent as `bundleId` in basket line and order payload |
| `name` | string | **yes** | Bundle card header (`"Menu"`, `"Full Menu"`, etc.) |
| `description` | string | **yes** | Bundle card body text |
| `included` | `{label: string}[]` | no | Checklist inside the card showing what's already included (e.g. `"Sandwich Club"`) |
| `slots` | array | no | Extra choices the customer must configure after picking this bundle. See [§ 5](#5-bundle-slot--slot-choices) |
| `price_modifier` | number | **yes** | Added to the product's base price. `0` renders as `"Inclus"`. Positive renders as `"+X.XX €"` |
| `advantages` | string[] | no | **Currently not rendered** in the carousel cards. Reserved for a future "benefits" row |
| `recommended` | boolean | no | `true` → shows a `"Best option"` badge on the card AND auto-selects this bundle when the modal opens |

### Important: `has_menu_options` flag

The bundle carousel only renders when `product.has_menu_options === true`.  
Set this to `true` whenever `available_bundles` is non-empty.

---

## 5. Bundle slot & slot choices

Nested inside `bundle.slots[]`.  
Revealed only when the customer picks a bundle (progressive disclosure).  
Rendered as chip/imgchip selectors inside the expanded bundle card.

```json
{
  "id": "drink",
  "label": "Boisson",
  "required": true,
  "choices": [
    { "id": "d1", "label": "Eau plate 33cl",   "img": "https://cdn.../cold-drink.png", "delta": 0   },
    { "id": "d2", "label": "Limonade maison",  "img": "https://cdn.../lemonade.png",   "delta": 0.5 },
    { "id": "d3", "label": "Café",             "img": "https://cdn.../hot-drink.png",  "delta": 0   }
  ]
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Key in `bundleSlots` state: `bundleSlots[slot.id] = choiceId`. Sent in order payload as `bundleSlots: { slotId: { id, label, delta } }` |
| `label` | string | **yes** | Slot group header inside the expanded bundle card |
| `required` | boolean | **yes** | `true` → blocks "Ajouter au panier" until a choice is made; shows "Requis" badge |
| `choices` | array | **yes** | |

### Slot choice

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Stored as selected value for this slot |
| `label` | string | **yes** | Chip text |
| `img` | string \| null | no | `null` → plain `pdm-chip`; present → `pdm-imgchip` with image tile |
| `delta` | number | no | Added to total price. Shown as `"+X.XX"` if `> 0`. `0` or absent = no badge |

---

## 6. Category & Subcategory

Categories are used for the horizontal filter tabs at the top of the product grid.  
They are currently hardcoded in the frontend (`W_CATEGORIES`) and not fetched from the API.  
If you want them to be dynamic in the future, add `GET /catalog/categories` — the shape below is what the UI expects.

### Category

```json
{
  "id": "tarts",
  "label": "Tartes",
  "img": "https://cdn.atelier.be/cat-tarts.png",
  "subs": [ ... ]
}
```

| Field | Type | Notes |
|---|---|---|
| `id` | string | Used as `cat` filter param in product queries |
| `label` | string | Tab label |
| `img` | string | Category icon (1:1 square, SVG or PNG) |
| `subs` | array | Zero or more subcategories |

### Subcategory

```json
{
  "id": "tarts-fruit",
  "label": "Fruits",
  "img": "https://cdn.atelier.be/tart-fruit.png"
}
```

| Field | Type | Notes |
|---|---|---|
| `id` | string | Used as `subCat` filter (client-side, no API param needed) |
| `label` | string | Sub-tab label |
| `img` | string | Thumbnail shown in sub-tab tile |

---

## 7. Assortment

Returned by `GET /catalog/assortments`. Rendered as special "season" category tabs.

```json
{
  "id": "paques",
  "label": "Pâques",
  "img": "https://cdn.atelier.be/season-paques.png",
  "tagline": "Sélection chocolatée — disponible jusqu'au 7 avril",
  "validFrom": "2026-03-01",
  "validUntil": "2026-04-07",
  "products": ["p-chocolat-paques"]
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Used as `season:<id>` in category selection state |
| `label` | string | **yes** | Tab label |
| `img` | string | **yes** | Tab tile image |
| `tagline` | string | no | Shown as a tooltip or sub-line (currently rendered in `CategoryRow`) |
| `validFrom` / `validUntil` | string | no | Not currently read by UI — use for server-side filtering only |
| `products` | string[] | no | List of product ids in this assortment. UI uses this to filter the grid when the season tab is active |

---

## 8. Basket line (client-side model)

Not fetched from the API — built locally when the customer confirms a product in the detail modal.  
This is the exact shape sent inside the `basket[]` array to `POST /orders` and `POST /pricing/quote`.

```json
{
  "line": 3,
  "productId": 1,
  "name": "Tarte aux fraises — 1/2",
  "qty": 1,
  "price": 12.00,
  "portion": "demi",
  "cat": "tarts",
  "crossPortion": true,
  "basePrice": 24.00,
  "offerDiscount": 0,
  "offerLabel": null,
  "options": [
    { "label": "Pesto maison" },
    { "label": "Formule · Full Menu" },
    { "label": "Boisson · Limonade maison" },
    { "label": "+ Petite salade" }
  ]
}
```

| Field | Type | Notes |
|---|---|---|
| `line` | number | Auto-incremented client-side. Used as React key. Not sent to the server |
| `productId` | number \| string | Matches `product.id` |
| `name` | string | Product name + portion suffix (`" — 1/2"` or `" — 1/4"`) |
| `qty` | number | |
| `price` | number | Unit price = base × portionFactor + option deltas + bundle delta + upsell deltas |
| `portion` | `"quart"` \| `"demi"` \| `"entier"` \| null | null for non-portionable products |
| `cat` | string | Copied from `product.cat` |
| `crossPortion` | boolean | Copied from `product.crossPortion` |
| `basePrice` | number | Copied from `product.price` — used for cross-portion free quarter value calc |
| `offerDiscount` | number | Per-line offer discount already applied (used for display in basket) |
| `offerLabel` | string \| null | Short label like `"4+1"` or `"2e −30%"` |
| `options` | `{label: string}[]` | Flattened list of all selections: option labels + bundle name + slot labels + upsells |

---

## 9. Shop

Returned by `GET /shops`.

```json
{
  "id": "chatelain",
  "name": "Maison Châtelain",
  "city": "Bruxelles",
  "address": "Rue du Bailli 42, 1050 Ixelles",
  "accent": "#8D1D2C"
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | `shopId` in every API call. Used for deep links, voucher shop scoping, tour filtering |
| `name` | string | **yes** | Shop chip pill, shop picker modal, basket footer, navbar variants B and C |
| `city` | string | **yes** | Secondary label in shop picker and navbar C |
| `address` | string | **yes** | Shop address shown in basket footer, navbar B bar, checkout summary |
| `accent` | string | **yes** | CSS hex color used for the shop chip dot, category tab active state, CTA background |

---

## 10. User

Returned by `POST /auth/login`, `POST /auth/register`, `GET /auth/me`, `PATCH /auth/me`.

```json
{
  "id": "u1",
  "email": "marie@acme.be",
  "firstName": "Marie",
  "lastName": "Dubois",
  "company": "ACME Avocats",
  "phone": "+32 472 11 22 33",
  "postalCode": "1040",
  "isBusiness": true,
  "officeId": "off-acme",
  "preferredShopId": "chatelain",
  "fidelityApp": {
    "active": true,
    "linkedAt": "2026-01-12T09:30:00Z"
  },
  "invoice": {
    "country": "BE",
    "vat": "BE0123456789",
    "name": "ACME Avocats SA",
    "address": "Rue de la Loi 120",
    "postalCode": "1040",
    "city": "Bruxelles"
  }
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Sent as `customer.id` in order payload |
| `email` | string | **yes** | Account modal header, avatar initials fallback, order payload |
| `firstName` | string | **yes** | Navbar avatar initial, account modal greeting, order payload |
| `lastName` | string | **yes** | Order payload |
| `company` | string | no | Account modal "Entreprise" field |
| `phone` | string | no | Account modal, order payload |
| `postalCode` | string | no | Account modal |
| `isBusiness` | boolean | no | `true` → shows the invoice/facturation section in account modal |
| `officeId` | string \| null | no | Determines delivery mode eligibility. If null = personal customer |
| `preferredShopId` | string \| null | no | Pre-selects shop on login. Also shown in "Boutique préférée" selector |
| `fidelityApp.active` | boolean | **yes** | `true` → shows the "Tout est dans votre application" fidelity info banner |
| `fidelityApp.linkedAt` | string \| null | no | Not currently displayed, but stored |
| `invoice.country` | string | no | ISO 2-letter code, pre-fills VIES country selector (`"BE"` default) |
| `invoice.vat` | string | no | Pre-fills VAT number field |
| `invoice.name` | string | no | Legal company name — auto-filled from VIES validation |
| `invoice.address` | string | no | Invoice address — auto-filled from VIES |
| `invoice.postalCode` | string | no | Auto-filled from VIES |
| `invoice.city` | string | no | Auto-filled from VIES |

---

## 11. Office

Returned by `GET /offices`, `GET /offices/:id`, `POST /offices`.

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

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Linked from `user.officeId`. Sent as `delivery.officeId` in order |
| `name` | string | **yes** | Displayed in account modal office section |
| `contact` | string | no | Office contact name — displayed in account modal |
| `phone` | string | no | Displayed in account modal |
| `email` | string | no | Not currently displayed on storefront |
| `address` | string | no | Not currently displayed on storefront |
| `tourId` | string \| null | **yes** | If non-null + `status = "validated"` → delivery mode is unlocked for linked users. Sent as `delivery.tourId` in order |
| `status` | string | **yes** | `"validated"` + non-null `tourId` = delivery active. `"pending"` = delivery unavailable (shown as "en attente") |

**Delivery unlock condition:**  
`user.officeId !== null` **AND** `office.status === "validated"` **AND** `office.tourId !== null`

### New office request payload (POST /offices body)

The UI sends these fields from the "Demande de bureau" form:

```json
{
  "name": "New Company SPRL",
  "vat": "BE0987654321",
  "address": "Avenue Louise 50",
  "postalCode": "1050",
  "city": "Bruxelles",
  "contact": "Sophie Martin",
  "email": "sophie@newco.be",
  "phone": "+32 471 00 11 22",
  "preferredShopId": "chatelain",
  "requestedBy": "sophie@newco.be"
}
```

| Field | Required | Notes |
|---|---|---|
| `name` | **yes** | Company name |
| `vat` | no | Optional VAT number entered during request |
| `address` | **yes** | |
| `postalCode` | **yes** | |
| `city` | **yes** | |
| `contact` | **yes** | Contact person full name |
| `email` | **yes** | Contact email |
| `phone` | **yes** | Contact phone |
| `preferredShopId` | **yes** | Shop the office wants delivery from |
| `requestedBy` | no | Email of the logged-in user who submitted the form |

---

## 12. Tour

Returned by `GET /tours` and `GET /tours/:id`.

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

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Matched against `office.tourId`. Sent as `delivery.tourId` in order |
| `name` | string | **yes** | Not currently displayed on storefront (used in admin) |
| `shopId` | string | **yes** | Used to filter available tours by shop |
| `window` | string | no | Delivery window string e.g. `"11:30–13:30"` — not currently displayed on storefront |
| `days` | string | no | Days of operation e.g. `"lun-ven"` — not currently displayed |
| `active` | boolean | no | Inactive tours should not be returned to the customer-facing app |

---

## 13. Voucher

Managed by the admin app via `GET /vouchers`, `POST /vouchers`, etc.  
The storefront only calls `POST /vouchers/redeem` and receives a subset.

### Stored shape (admin)

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

| Field | Type | Required | Notes |
|---|---|---|---|
| `code` | string | **yes** | Case-insensitive. Normalised to uppercase by the frontend |
| `type` | string | **yes** | `"percent"`, `"amount"`, `"shipping"` |
| `value` | number | **yes** | Percentage or fixed euro amount |
| `minOrder` | number | no | Minimum basket subtotal in €. `0` or absent = no minimum |
| `scope` | string | no | `"order"` (whole basket) or `"category"` (only lines matching `scopeRef`) |
| `scopeRef` | string \| null | no | Category id when `scope = "category"` |
| `channel` | string | **yes** | `"webshop"` codes work on storefront. `"office"` codes are rejected with `reason: "channel"` |
| `shopIds` | string[] | no | Empty = valid everywhere. Non-empty = only valid at listed shops |
| `validFrom` | string | no | `YYYY-MM-DD` |
| `validUntil` | string | no | `YYYY-MM-DD`. End of day (23:59:59) |
| `usage.used` | number | **yes** | Atomically incremented on each successful redemption |
| `usage.limit` | number \| null | no | `null` = unlimited |
| `status` | string | **yes** | `active`, `scheduled`, `expired`, `exhausted`, `disabled` |

### Redeem response (storefront)

```json
{
  "ok": true,
  "voucher": { "id": "...", "code": "BIENVENUE10", "type": "percent", "value": 10, "scope": "order" },
  "discount": 2.85,
  "message": "−10% appliqué"
}
```

| Field | Required | Notes |
|---|---|---|
| `ok` | **yes** | Boolean. Always HTTP 200; `false` = code rejected |
| `voucher` | yes (if ok) | Minimal voucher info for receipt display |
| `discount` | yes (if ok) | Computed discount in € |
| `message` | **yes** | Displayed verbatim to the customer in the checkout UI |
| `reason` | yes (if !ok) | Machine-readable rejection reason — see full list in `API.md § 6` |

---

## 14. Pricing quote response

Returned by `POST /pricing/quote`. The frontend renders this verbatim.

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
        "toNext": 0,
        "status": "active"
      }
    },
    { "code": "pickup-5", "label": "Retrait −5%", "amount": 1.20 },
    { "code": "voucher",  "label": "−10% appliqué", "amount": 2.40 }
  ],
  "total": 13.92,
  "voucher": {
    "ok": true,
    "code": "BIENVENUE10",
    "discount": 2.40,
    "message": "−10% appliqué"
  },
  "cross": {
    "eligibleCount": 4,
    "groupSize": 5,
    "cycles": 0,
    "freeCount": 0,
    "savings": 0,
    "toNext": 1,
    "status": "dormant",
    "threshold": 4
  },
  "lines": [
    { "productId": 1, "lineTotal": 24.00, "appliedOffers": ["cross-portion"] }
  ]
}
```

| Field | Required | Where used |
|---|---|---|
| `subtotal` | **yes** | Basket subtotal row |
| `discounts[]` | **yes** | Each entry is rendered as a discount row in the basket summary |
| `discounts[].code` | **yes** | Used for conditional display logic (e.g. which icon to show) |
| `discounts[].label` | **yes** | Displayed as the row label |
| `discounts[].amount` | **yes** | Displayed as `"−€X.XX"` |
| `discounts[].meta` | no | Cross-portion meta — drives the progress strip display |
| `total` | **yes** | Final total row ("Total TTC") |
| `voucher` | no | Drives voucher confirmation message display |
| `cross` | no | Drives the cross-portion progress strip in the basket |
| `lines` | no | For receipt / order confirmation detail |

---

## 15. Calendar day

Returned by `GET /calendar/days`.

```json
{ "iso": "2026-05-09", "available": true, "reason": null }
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `iso` | string | **yes** | `YYYY-MM-DD`. Matched against date picker cells |
| `available` | boolean | **yes** | `false` → cell is greyed-out and `disabled` |
| `reason` | string \| null | no | Not currently displayed; reserved for tooltip or aria-label |

---

## 16. Calendar slot

Returned by `GET /calendar/slots`.

```json
{ "id": "s-09", "label": "09:00–10:00", "capacity": 20, "remaining": 3 }
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Sent as `slot.slotId` in order payload |
| `label` | string | **yes** | Shown in slot picker button and order confirmation |
| `capacity` | number | no | Not currently displayed |
| `remaining` | number | no | Not currently displayed. `0` could be used to show "Complet" |
| `soldOut` | boolean | no | If `true`, disables the slot button |

---

## 17. Calendar cutoff

Returned by `GET /calendar/cutoff`.

```json
{ "hour": 16, "minutes": 0, "leadHours": 2 }
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `hour` | number | **yes** | Hour of the daily cutoff (0–23) |
| `minutes` | number | **yes** | Minutes of the daily cutoff (0–59) |
| `leadHours` | number | **yes** | Minimum hours before a slot that an order can be placed. Slots within this window on the same day are disabled |

---

## 18. Payment method

Returned by `GET /pricing/payment-methods`.

```json
{ "id": "bancontact", "label": "Bancontact", "sub": "Paiement instantané" }
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `id` | string | **yes** | Sent verbatim as `payment.method` in the order payload |
| `label` | string | **yes** | Main label in the payment picker |
| `sub` | string | no | Subtitle / descriptor line below the label |
| `requires` | string | no | If `"validated_office"`, the option is only shown to users with an active delivery office |

---

## 19. Cross-portion promo rule

Returned by `GET /pricing/promos/cross-portion`.

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

| Field | Type | Required | Where used |
|---|---|---|---|
| `x` | number | **yes** | Number of portions to buy |
| `y` | number | **yes** | Number of free portions awarded (always the cheapest) |
| `threshold` | number | **yes** | Minimum count before promo activates. Usually equals `x` |
| `label` | string | no | Displayed in the cross-portion strip header |
| `quarterValueFactor` | number | **yes** | A free quarter's value = `product.basePrice × this`. Default `0.27` |
| `eligibleCats` | string[] | no | Empty or absent = all `crossPortion: true` products participate |

---

## 20. Brand / theme config

Returned by `GET /brand`.

```json
{
  "tokens": {
    "color-primary": "#8D1D2C",
    "color-text": "#1F1612",
    "color-bg": "#FAF7F5"
  },
  "fonts": [
    { "family": "Souvenir", "url": "https://cdn.atelier.be/fonts/Souvenir-Light.woff2", "weight": 300, "style": "normal" }
  ],
  "logo": "https://cdn.atelier.be/logo.svg",
  "strings": { "nav.collect": "Click & Collect" }
}
```

| Field | Type | Required | Where used |
|---|---|---|---|
| `tokens` | object | no | Each key → CSS custom property `--<key>` on `:root`. Picked up automatically by `webshop.css` |
| `fonts[].family` | string | yes (if font entry) | `@font-face font-family` |
| `fonts[].url` | string | yes (if font entry) | `@font-face src` |
| `fonts[].weight` | number | no | Default `400` |
| `fonts[].style` | string | no | Default `"normal"` |
| `logo` | string | no | Not currently rendered by the storefront (future use) |
| `strings` | object | no | Merged into `WSI18n` — can override any copy key |

---

## 21. VIES VAT validation response

Returned by `GET /vies`.

```json
{
  "valid": true,
  "data": {
    "vat": "BE0123456789",
    "country": "BE",
    "name": "ACME Avocats SA",
    "address": "Rue de la Loi 120",
    "postalCode": "1040",
    "city": "Bruxelles"
  }
}
```

When `valid: true`, the UI auto-fills the invoice fields in the account modal:

| `data` field | Auto-fills |
|---|---|
| `vat` | `invoice.vat` (normalized) |
| `country` | `invoice.country` |
| `name` | `invoice.name` |
| `address` | `invoice.address` |
| `postalCode` | `invoice.postalCode` |
| `city` | `invoice.city` |

On `valid: false`, `error.message` is shown verbatim to the user.

---

## 22. Portion system explained

This is the most complex part of the data model. A single product can be sold as a quarter, half, or whole.

### Portion factors

The UI uses these hardcoded factors to compute the portion price from the base product price:

| Portion | Factor | Displayed as |
|---|---|---|
| `quart` | `0.27` | "1/4" |
| `demi` | `0.52` | "1/2" |
| `entier` | `1.00` | "Entière" |

These factors can be overridden per-product via `product.portionUnits` (not the price factors directly, but the unit counts used for the 4+1 promo):

| Portion | Default `portionUnits` value | Meaning |
|---|---|---|
| `quart` | `1` | 1 quarter-unit |
| `demi` | `2` | 2 quarter-units |
| `entier` | `4` | 4 quarter-units |

### Cross-portion promo calculation

1. For every `crossPortion: true` line in the basket, expand into portion-units:  
   `units = qty × portionUnitsFor(portion)`
2. For each unit, compute its quarter value: `quarterValue = line.basePrice × 0.27`
3. Collect all units into a flat array sorted by `quarterValue` ascending (cheapest first)
4. For every `x + y` (default: 5) units, the first `y` (default: 1, the cheapest) are free
5. `freeCount = Math.floor(totalUnits / (x + y)) × y`
6. `savings = sum of the freeCount cheapest quarter values`

### Portion price on product card

The product card shows `product.price` (the full "entier" price) with "à partir de" if options exist.  
The detail modal computes the actual unit price:

```
unitPrice = product.price × portionFactor + sum(selected option deltas) + bundleModifier + sum(upsell deltas)
```

where `portionFactor` is `0.27`, `0.52`, or `1.00` depending on the chosen portion.
