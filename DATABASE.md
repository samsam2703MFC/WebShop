# L'Atelier By — Database Documentation

> Complete reference for the `ws_` schema. Every table, column, relationship,
> constraint and business rule is documented here. Use this file as the single
> source of truth when building the backend.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Conventions](#2-conventions)
3. [Schema diagram](#3-schema-diagram)
4. [Tables](#4-tables)
   - [ws_shops](#ws_shops)
   - [ws_categories](#ws_categories)
   - [ws_category_subs](#ws_category_subs)
   - [ws_products](#ws_products)
   - [ws_product_allergens](#ws_product_allergens)
   - [ws_product_shops](#ws_product_shops)
   - [ws_product_prices](#ws_product_prices)
   - [ws_product_options](#ws_product_options)
   - [ws_product_option_choices](#ws_product_option_choices)
   - [ws_bundles](#ws_bundles)
   - [ws_bundle_slots](#ws_bundle_slots)
   - [ws_bundle_slot_choices](#ws_bundle_slot_choices)
   - [ws_assortments](#ws_assortments)
   - [ws_tours](#ws_tours)
   - [ws_offices](#ws_offices)
   - [ws_customers](#ws_customers)
   - [ws_orders](#ws_orders)
   - [ws_order_lines](#ws_order_lines)
   - [ws_vouchers](#ws_vouchers)
   - [ws_calendar_rules](#ws_calendar_rules)
   - [ws_slots](#ws_slots)
   - [ws_pricing_rules](#ws_pricing_rules)
   - [ws_product_stock](#ws_product_stock)
   - [ws_stock_reservations](#ws_stock_reservations)
5. [Key business rules](#5-key-business-rules)
6. [Common queries](#6-common-queries)
7. [Backend cron jobs](#7-backend-cron-jobs)
8. [Activation checklist](#8-activation-checklist)

---

## 1. Overview

The webshop is a **multi-shop** system. Every piece of data that varies per
shop (prices, stock, delivery tours, calendar rules) carries a `shop_id`
foreign key. The global catalog (`ws_products`) stores names, images and
allergens once; shop-specific overrides live in separate tables.

**Architecture layers:**

```
Global catalog        ws_products, ws_categories, ws_bundles, ws_vouchers
Per-shop config       ws_product_shops, ws_product_prices, ws_calendar_rules, ws_slots, ws_pricing_rules
Per-shop + date       ws_product_stock
Delivery network      ws_tours → ws_offices → ws_customers
Orders                ws_orders → ws_order_lines
```

---

## 2. Conventions

| Convention | Rule |
|---|---|
| Prefix | All tables start with `ws_` |
| Primary keys | `id` — `SERIAL` for auto-increment tables, `VARCHAR(50)` for named entities |
| Money | `DECIMAL(10,2)` in **euros** — never cents |
| Dates | `DATE` for calendar dates, `TIMESTAMP` for instants (UTC) |
| Boolean active flag | Every table that can be soft-deleted has `active BOOLEAN DEFAULT TRUE` |
| `sort_order` | `INT DEFAULT 0` — ascending, ties broken by `id` |
| Soft delete | Set `active = FALSE` — never `DELETE` rows that are referenced |

---

## 3. Schema Diagram

```
ws_shops
  │
  ├── ws_categories ──── ws_category_subs
  │
  ├── ws_product_shops ──── ws_products ──── ws_product_allergens
  ├── ws_product_prices         │
  │                             ├── ws_product_options ── ws_product_option_choices
  │                             ├── ws_bundles ── ws_bundle_slots ── ws_bundle_slot_choices
  │                             └── ws_assortments
  │
  ├── ws_calendar_rules
  ├── ws_slots
  ├── ws_pricing_rules
  ├── ws_product_stock ──── ws_stock_reservations
  │
  └── ws_tours
        └── ws_offices
               └── ws_customers
                      └── ws_orders
                             └── ws_order_lines

ws_vouchers (global, optionally shop-scoped)
```

---

## 4. Tables

---

### ws_shops

One row per physical or virtual shop location.

```sql
CREATE TABLE ws_shops (
  id        VARCHAR(50)  PRIMARY KEY,
  name      VARCHAR(100) NOT NULL,
  city      VARCHAR(100),
  address   VARCHAR(200),
  phone     VARCHAR(30),
  email     VARCHAR(100),
  accent    VARCHAR(20),   -- hex brand colour e.g. #8D1D2C
  tint      VARCHAR(20),   -- hex light background e.g. #fdf6f0
  logo_url  VARCHAR(255),
  active    BOOLEAN DEFAULT TRUE
);
```

| Column | Notes |
|---|---|
| `id` | Slug-style: `chatelain`, `ixelles`. Used in URL deep-links (`?shop=chatelain`) |
| `accent` | Primary brand colour — injected as CSS variable `--color-primary` |
| `tint` | Light background tint — injected as `--color-bg` |
| `logo_url` | Optional. If absent the UI uses the text brand name |

---

### ws_categories

Product categories displayed in the horizontal filter strip.

```sql
CREATE TABLE ws_categories (
  id          VARCHAR(50)  PRIMARY KEY,
  shop_id     VARCHAR(50)  REFERENCES ws_shops(id),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE
);
```

| Column | Notes |
|---|---|
| `shop_id` | Each shop defines its own category list |
| `img` | Square thumbnail shown in the category chip |
| `sort_order` | Display order left-to-right in the filter strip |

---

### ws_category_subs

Sub-categories shown as a secondary row when a parent category is active.

```sql
CREATE TABLE ws_category_subs (
  id          VARCHAR(50)  PRIMARY KEY,
  category_id VARCHAR(50)  REFERENCES ws_categories(id),
  label       VARCHAR(100) NOT NULL,
  img         VARCHAR(255),
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE
);
```

---

### ws_products

Global product catalog. Names, images and allergens are defined once here.
Shop-specific prices and availability live in `ws_product_prices` and
`ws_product_shops`.

```sql
CREATE TABLE ws_products (
  id               SERIAL       PRIMARY KEY,
  cat              VARCHAR(50)  REFERENCES ws_categories(id),
  sub_cat          VARCHAR(50)  REFERENCES ws_category_subs(id),
  name             VARCHAR(200) NOT NULL,
  description      TEXT,
  price            DECIMAL(10,2) NOT NULL,  -- global default price
  img              VARCHAR(255),
  badge            VARCHAR(50),             -- "Du jour", "Nouveau", "4+1", null
  portions         BOOLEAN DEFAULT FALSE,   -- enables quart/demi/entier selector
  cross_portion    BOOLEAN DEFAULT FALSE,   -- participates in 4+1 basket promo
  has_menu_options BOOLEAN DEFAULT FALSE,   -- enables bundle/menu carousel
  active           BOOLEAN DEFAULT TRUE
);
```

| Column | Notes |
|---|---|
| `price` | Global fallback. Overridden per shop in `ws_product_prices` |
| `portions` | `TRUE` → product can be ordered as quart (×0.27), demi (×0.52), entier (×1.0) |
| `cross_portion` | `TRUE` → counted in the basket-level 4 portions achetées → 1 offerte promo |
| `has_menu_options` | `TRUE` → `ws_bundles` rows must exist for this product |
| `badge` | Short promo pill overlaid on the card photo |

---

### ws_product_allergens

Many-to-many: allergens present in a product.

```sql
CREATE TABLE ws_product_allergens (
  product_id  INT         REFERENCES ws_products(id),
  allergen    VARCHAR(50) NOT NULL,
  PRIMARY KEY (product_id, allergen)
);
```

**Valid allergen values:** `gluten` `milk` `egg` `fish` `almond` `sesame`
`peanut` `soy` `shellfish` `mustard` `celery` `lupin` `molluscs` `sulphites`

---

### ws_product_shops

Controls which products are available at each shop, and whether delivery
is blocked for that product at that shop.

```sql
CREATE TABLE ws_product_shops (
  product_id  INT         REFERENCES ws_products(id),
  shop_id     VARCHAR(50) REFERENCES ws_shops(id),
  no_delivery BOOLEAN DEFAULT FALSE,  -- TRUE = collect only at this shop
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id)
);
```

**Rule:** if no row exists for a `(product_id, shop_id)` pair, the product
is considered **available** at that shop with `no_delivery = FALSE`.

---

### ws_product_prices

Per-shop price override. When a row exists here the webshop ignores
`ws_products.price` for that shop.

```sql
CREATE TABLE ws_product_prices (
  product_id  INT           REFERENCES ws_products(id),
  shop_id     VARCHAR(50)   REFERENCES ws_shops(id),
  price       DECIMAL(10,2) NOT NULL,
  active      BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (product_id, shop_id)
);
```

**Price resolution order:**
1. `ws_product_prices` row for `(product_id, shop_id)` — if exists and active
2. `ws_products.price` — global default fallback

---

### ws_product_options

Customisation option groups for a product (e.g. "Bread type", "Sauce").

```sql
CREATE TABLE ws_product_options (
  id          VARCHAR(50)  PRIMARY KEY,
  product_id  INT          REFERENCES ws_products(id),
  label       VARCHAR(100) NOT NULL,
  required    BOOLEAN DEFAULT FALSE,
  sort_order  INT DEFAULT 0,
  active      BOOLEAN DEFAULT TRUE
);
```

| Column | Notes |
|---|---|
| `required` | `TRUE` → blocks "Ajouter au panier" until a choice is made |

---

### ws_product_option_choices

Individual choices within an option group.

```sql
CREATE TABLE ws_product_option_choices (
  id        VARCHAR(50)   PRIMARY KEY,
  option_id VARCHAR(50)   REFERENCES ws_product_options(id),
  label     VARCHAR(100)  NOT NULL,
  delta     DECIMAL(10,2) DEFAULT 0,  -- price addition (can be negative)
  sort_order INT DEFAULT 0,
  active    BOOLEAN DEFAULT TRUE
);
```

---

### ws_bundles

Meal-deal plans attached to a product. Displayed as a horizontal carousel
in the product detail modal. Requires `ws_products.has_menu_options = TRUE`.

```sql
CREATE TABLE ws_bundles (
  id             VARCHAR(50)   PRIMARY KEY,
  product_id     INT           REFERENCES ws_products(id),
  name           VARCHAR(100)  NOT NULL,
  description    TEXT,
  price_modifier DECIMAL(10,2) DEFAULT 0,  -- added to product base price
  sort_order     INT DEFAULT 0,
  active         BOOLEAN DEFAULT TRUE
);
```

> **"À la carte"** (no bundle) is always prepended by the UI automatically.
> Do **not** create a bundle row for it.

---

### ws_bundle_slots

Composition slots inside a bundle (e.g. "Boisson", "Dessert").

```sql
CREATE TABLE ws_bundle_slots (
  id         VARCHAR(50)  PRIMARY KEY,
  bundle_id  VARCHAR(50)  REFERENCES ws_bundles(id),
  label      VARCHAR(100) NOT NULL,
  required   BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE
);
```

---

### ws_bundle_slot_choices

Options available for a bundle slot (e.g. "Eau", "Jus d'orange").

```sql
CREATE TABLE ws_bundle_slot_choices (
  id         VARCHAR(50)   PRIMARY KEY,
  slot_id    VARCHAR(50)   REFERENCES ws_bundle_slots(id),
  label      VARCHAR(100)  NOT NULL,
  img        VARCHAR(255),             -- if present → image chip; absent → text chip
  delta      DECIMAL(10,2) DEFAULT 0,
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE
);
```

---

### ws_assortments

Seasonal or curated product collections shown in the category strip as
"Saisons" entries.

```sql
CREATE TABLE ws_assortments (
  id      VARCHAR(50)  PRIMARY KEY,
  shop_id VARCHAR(50)  REFERENCES ws_shops(id),
  label   VARCHAR(100) NOT NULL,
  img     VARCHAR(255),
  active  BOOLEAN DEFAULT TRUE
);
```

---

### ws_tours

Delivery tour routes. Each tour belongs to one shop and groups a set of
offices that receive deliveries together.

```sql
CREATE TABLE ws_tours (
  id      VARCHAR(50)  PRIMARY KEY,
  shop_id VARCHAR(50)  REFERENCES ws_shops(id),
  name    VARCHAR(100) NOT NULL,
  active  BOOLEAN DEFAULT TRUE
);
```

---

### ws_offices

Delivery addresses (company offices). Each office is on one tour. A
customer links their account to one office to enable delivery mode.

```sql
CREATE TABLE ws_offices (
  id          VARCHAR(50)  PRIMARY KEY,
  tour_id     VARCHAR(50)  REFERENCES ws_tours(id),
  name        VARCHAR(200) NOT NULL,
  address     VARCHAR(200),
  postal_code VARCHAR(20),
  city        VARCHAR(100),
  contact     VARCHAR(100),  -- contact person name
  email       VARCHAR(100),
  phone       VARCHAR(30),
  vat         VARCHAR(30),
  status      VARCHAR(20)  DEFAULT 'pending',
  active      BOOLEAN DEFAULT TRUE,
  created_at  TIMESTAMP DEFAULT NOW()
);
```

| `status` value | Meaning |
|---|---|
| `pending` | Office requested, awaiting admin validation |
| `validated` | Approved — customers linked to this office can use delivery |
| `rejected` | Rejected — delivery disabled for this office |

**Delivery eligibility rule:**
`customer.office_id` → `ws_offices.status = 'validated'` → `ws_tours.active = TRUE`
→ delivery mode is available for this customer.

---

### ws_customers

Registered customers. Authentication is cookie-based (no token stored here).

```sql
CREATE TABLE ws_customers (
  id                  VARCHAR(50)  PRIMARY KEY,
  email               VARCHAR(200) UNIQUE NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,       -- bcrypt
  first_name          VARCHAR(100),
  last_name           VARCHAR(100),
  phone               VARCHAR(30),
  office_id           VARCHAR(50)  REFERENCES ws_offices(id),
  preferred_shop_id   VARCHAR(50)  REFERENCES ws_shops(id),
  preferred_lang      VARCHAR(5)   DEFAULT 'fr',   -- fr | nl | en | de
  is_business         BOOLEAN DEFAULT FALSE,
  fidelity_active     BOOLEAN DEFAULT FALSE,
  fidelity_linked_at  TIMESTAMP,
  invoice_country     VARCHAR(5)   DEFAULT 'BE',
  invoice_vat         VARCHAR(30),
  invoice_name        VARCHAR(200),
  invoice_address     VARCHAR(200),
  invoice_postal_code VARCHAR(20),
  invoice_city        VARCHAR(100),
  active              BOOLEAN DEFAULT TRUE,
  created_at          TIMESTAMP DEFAULT NOW()
);
```

| Column | Notes |
|---|---|
| `office_id` | `NULL` = no delivery. Set after customer picks/creates an office |
| `preferred_shop_id` | Loaded automatically on login to set the active shop |
| `preferred_lang` | Used by `WSI18n` for UI language |
| `is_business` | `TRUE` → invoice section shown in account modal |
| `fidelity_active` | `TRUE` → QR fidelity card active |

---

### ws_orders

One row per placed order.

```sql
CREATE TABLE ws_orders (
  id               VARCHAR(50)   PRIMARY KEY,
  shop_id          VARCHAR(50)   REFERENCES ws_shops(id),
  customer_id      VARCHAR(50)   REFERENCES ws_customers(id),  -- NULL for guests
  mode             VARCHAR(20)   NOT NULL,   -- collect | delivery
  status           VARCHAR(30)   DEFAULT 'pending',
  slot_id          VARCHAR(50),
  slot_label       VARCHAR(100),
  delivery_date    DATE,
  subtotal         DECIMAL(10,2),
  promo_amount     DECIMAL(10,2) DEFAULT 0,
  voucher_code     VARCHAR(50),
  voucher_discount DECIMAL(10,2) DEFAULT 0,
  total            DECIMAL(10,2),
  payment_method   VARCHAR(30),              -- bancontact | card | cash
  payment_status   VARCHAR(30)   DEFAULT 'pending',
  lang             VARCHAR(5)    DEFAULT 'fr',
  created_at       TIMESTAMP DEFAULT NOW()
);
```

| `status` value | Meaning |
|---|---|
| `pending` | Placed, awaiting payment confirmation |
| `confirmed` | Payment received |
| `preparing` | Kitchen is preparing |
| `ready` | Ready for collect / loaded for delivery |
| `delivered` | Completed |
| `cancelled` | Cancelled |

| `payment_status` value | Meaning |
|---|---|
| `pending` | Awaiting |
| `paid` | Confirmed |
| `failed` | Failed |
| `refunded` | Refunded |

---

### ws_order_lines

Individual product lines within an order.

```sql
CREATE TABLE ws_order_lines (
  id           SERIAL        PRIMARY KEY,
  order_id     VARCHAR(50)   REFERENCES ws_orders(id),
  product_id   INT           REFERENCES ws_products(id),
  product_name VARCHAR(200),               -- snapshot at order time
  qty          INT           NOT NULL,
  unit_price   DECIMAL(10,2),              -- snapshot at order time
  portion      VARCHAR(20),               -- quart | demi | entier | NULL
  bundle_id    VARCHAR(50),
  options      JSONB,                     -- [{ optionId, choiceId, label, delta }]
  bundle_slots JSONB                      -- { slotId: choiceId }
);
```

> `product_name` and `unit_price` are **snapshots** — they preserve the
> values at order time even if the catalog changes later.

---

### ws_vouchers

Discount codes redeemable at checkout.

```sql
CREATE TABLE ws_vouchers (
  code        VARCHAR(50)   PRIMARY KEY,
  type        VARCHAR(20)   NOT NULL,   -- percent | fixed | free_delivery
  value       DECIMAL(10,2),           -- % for percent, € for fixed
  min_order   DECIMAL(10,2) DEFAULT 0, -- minimum basket total to apply
  max_uses    INT,                     -- NULL = unlimited
  used_count  INT           DEFAULT 0,
  expires_at  TIMESTAMP,               -- NULL = no expiry
  active      BOOLEAN DEFAULT TRUE
);
```

| `type` | `value` meaning |
|---|---|
| `percent` | Percentage off total e.g. `10` = 10% |
| `fixed` | Fixed euro amount off e.g. `5.00` = €5 |
| `free_delivery` | Delivery fee waived (value ignored) |

---

### ws_calendar_rules

Delivery and collect cutoff times and open days per shop.

```sql
CREATE TABLE ws_calendar_rules (
  id              SERIAL      PRIMARY KEY,
  shop_id         VARCHAR(50) REFERENCES ws_shops(id),
  mode            VARCHAR(20) NOT NULL,  -- collect | delivery
  open_days       INT[],                 -- ISO weekdays: 1=Mon … 7=Sun
  cutoff_hour     INT         NOT NULL,  -- 0–23
  cutoff_minutes  INT         DEFAULT 0, -- 0–59
  lead_hours      INT         DEFAULT 0, -- min hours between order and delivery
  active          BOOLEAN DEFAULT TRUE
);
```

**Example:** delivery cutoff at 10:00, open Mon–Fri:
```sql
INSERT INTO ws_calendar_rules (shop_id, mode, open_days, cutoff_hour, cutoff_minutes, lead_hours)
VALUES ('chatelain', 'delivery', '{1,2,3,4,5}', 10, 0, 20);
```

> `cutoff_hour` and `cutoff_minutes` are used by the frontend to disable
> same-day delivery after that time. The value is loaded via
> `WSCalendar.getCutoff({ shopId, mode: 'delivery' })`.

---

### ws_slots

Available time slots for collect and delivery, per shop.

```sql
CREATE TABLE ws_slots (
  id         VARCHAR(50)  PRIMARY KEY,
  shop_id    VARCHAR(50)  REFERENCES ws_shops(id),
  mode       VARCHAR(20)  NOT NULL,   -- collect | delivery
  label      VARCHAR(100) NOT NULL,   -- "08:30–10:30"
  sort_order INT DEFAULT 0,
  active     BOOLEAN DEFAULT TRUE
);
```

---

### ws_pricing_rules

Business rules for basket-level promotions. Currently supports the
cross-portion 4+1 promo.

```sql
CREATE TABLE ws_pricing_rules (
  id        SERIAL      PRIMARY KEY,
  shop_id   VARCHAR(50) REFERENCES ws_shops(id),
  rule_type VARCHAR(50) NOT NULL,   -- cross_portion
  x         INT,                    -- buy X portions
  y         INT,                    -- get Y free (the cheapest)
  threshold INT,                    -- minimum portions before promo activates
  label     VARCHAR(200),
  active    BOOLEAN DEFAULT TRUE
);
```

**Example:** 4 portions achetées → 1 offerte:
```sql
INSERT INTO ws_pricing_rules (shop_id, rule_type, x, y, threshold, label)
VALUES ('chatelain', 'cross_portion', 4, 1, 4, '4 quarts achetés, 1 offert');
```

---

### ws_product_stock

Daily production quantities per product, shop and mode. The backend
computes `qty_available = qty_total - qty_reserved - qty_sold`.

```sql
CREATE TABLE ws_product_stock (
  id           SERIAL      PRIMARY KEY,
  product_id   INT         REFERENCES ws_products(id),
  shop_id      VARCHAR(50) REFERENCES ws_shops(id),
  date         DATE        NOT NULL,
  mode         VARCHAR(20),          -- collect | delivery | NULL = both
  qty_total    INT         NOT NULL, -- total prepared for the day
  qty_reserved INT         DEFAULT 0,-- held in active baskets (15-min hold)
  qty_sold     INT         DEFAULT 0,-- confirmed paid orders
  active       BOOLEAN DEFAULT TRUE,
  UNIQUE (product_id, shop_id, date, mode)
);
```

| Column | Who writes it |
|---|---|
| `qty_total` | Baker / admin sets this the night before |
| `qty_reserved` | Incremented by `POST /stock/reserve`, decremented by release |
| `qty_sold` | Incremented when order is confirmed (`payment_status = paid`) |

**API response adds:**
```json
{ "qty_available": 7 }   // qty_total - qty_reserved - qty_sold
```

---

### ws_stock_reservations

Temporary holds on stock for logged-in customers during checkout.
Holds expire after **15 minutes**. A background cron releases expired rows.

```sql
CREATE TABLE ws_stock_reservations (
  id          VARCHAR(50) PRIMARY KEY,
  product_id  INT         REFERENCES ws_products(id),
  shop_id     VARCHAR(50) REFERENCES ws_shops(id),
  date        DATE        NOT NULL,
  mode        VARCHAR(20) NOT NULL,    -- collect | delivery
  qty         INT         NOT NULL,
  customer_id VARCHAR(50) REFERENCES ws_customers(id),
  expires_at  TIMESTAMP   NOT NULL,    -- created_at + 15 minutes
  released    BOOLEAN DEFAULT FALSE,
  created_at  TIMESTAMP DEFAULT NOW()
);
```

**Lifecycle:**
```
Add to basket (logged-in)  →  INSERT reservation, expires_at = NOW() + 15min
Remove from basket         →  UPDATE released = TRUE
Checkout confirmed         →  UPDATE released = TRUE + qty_sold += qty
Logout / mode change       →  UPDATE released = TRUE (all for customer)
Cron every 5 min           →  UPDATE released = TRUE WHERE expires_at < NOW()
```

---

## 5. Key Business Rules

### Delivery eligibility
A customer can use delivery mode only if **all three** conditions are met:
1. `ws_customers.office_id` is not NULL
2. `ws_offices.status = 'validated'`
3. `ws_tours.active = TRUE` (the tour linked to that office)

### Same-day delivery cutoff
Loaded from `ws_calendar_rules` where `shop_id = :shopId AND mode = 'delivery'`.
After `cutoff_hour:cutoff_minutes` local time, the delivery mode pill is
disabled and today's date is blocked in the date picker.

### Price resolution (per product per shop)
```
ws_product_prices (shop-specific, active = TRUE)  ←  wins
       ↓ else
ws_products.price  (global default)
```

### Product availability per shop
```sql
-- A product is shown at a shop if:
COALESCE(ws_product_shops.active, TRUE) = TRUE
AND ws_products.active = TRUE
```

### Stock reservation (15 min hold)
- Only for **logged-in** customers
- Guest/anonymous baskets: stock shown read-only, no hold placed
- One `ws_stock_reservations` row per `(product, shop, date, mode, customer)`
- On confirmed order: `qty_sold += qty`, reservation released

### Cross-portion 4+1 promo
Rule loaded from `ws_pricing_rules` where `rule_type = 'cross_portion'`.
- Only products with `cross_portion = TRUE` participate
- Every `x + y` portion-units → `y` cheapest are free
- Portion-units: quart = 1, demi = 2, entier = 4

### Anonymous orders (collect only)
- `ws_orders.customer_id` may be NULL for guest collect orders
- Guest orders must include contact info in the order payload

---

## 6. Common Queries

### Products for a shop with correct price
```sql
SELECT
  p.*,
  COALESCE(pp.price, p.price)       AS price,
  COALESCE(ps.no_delivery, FALSE)   AS no_delivery,
  COALESCE(ps.active, TRUE)         AS shop_active
FROM ws_products p
LEFT JOIN ws_product_shops ps
  ON ps.product_id = p.id AND ps.shop_id = :shopId
LEFT JOIN ws_product_prices pp
  ON pp.product_id = p.id AND pp.shop_id = :shopId AND pp.active = TRUE
WHERE p.active = TRUE
  AND COALESCE(ps.active, TRUE) = TRUE
ORDER BY p.id;
```

### Available stock for a day
```sql
SELECT
  s.product_id,
  s.qty_total,
  s.qty_reserved,
  s.qty_sold,
  (s.qty_total - s.qty_reserved - s.qty_sold) AS qty_available
FROM ws_product_stock s
WHERE s.shop_id = :shopId
  AND s.date    = :date
  AND (s.mode = :mode OR s.mode IS NULL)
  AND s.active  = TRUE;
```

### Offices available for a shop (validated only)
```sql
SELECT o.*
FROM ws_offices o
JOIN ws_tours t ON t.id = o.tour_id
WHERE t.shop_id = :shopId
  AND o.status  = 'validated'
  AND o.active  = TRUE
  AND t.active  = TRUE
ORDER BY o.name;
```

### Customer delivery eligibility
```sql
SELECT
  c.id,
  o.status     AS office_status,
  t.active     AS tour_active,
  (o.status = 'validated' AND t.active = TRUE) AS can_deliver
FROM ws_customers c
LEFT JOIN ws_offices o ON o.id = c.office_id
LEFT JOIN ws_tours   t ON t.id = o.tour_id
WHERE c.id = :customerId;
```

### Active voucher validation
```sql
SELECT *
FROM ws_vouchers
WHERE code    = :code
  AND active  = TRUE
  AND (expires_at IS NULL OR expires_at > NOW())
  AND (max_uses   IS NULL OR used_count < max_uses);
```

---

## 7. Backend Cron Jobs

| Job | Frequency | SQL |
|---|---|---|
| Release expired reservations | Every 5 min | `UPDATE ws_stock_reservations SET released = TRUE WHERE expires_at < NOW() AND released = FALSE` — then `UPDATE ws_product_stock SET qty_reserved = qty_reserved - r.qty` |
| Daily stock reset | Each morning | `INSERT INTO ws_product_stock ...` — baker or admin sets `qty_total` for the day |
| Expired voucher cleanup | Daily | `UPDATE ws_vouchers SET active = FALSE WHERE expires_at < NOW()` |

---

## 8. Activation Checklist

To switch the webshop from seed/demo data to live database:

**1. Create all tables** using the SQL above (PostgreSQL recommended).

**2. Seed initial data:**
- Insert shops into `ws_shops`
- Insert categories into `ws_categories` + `ws_category_subs`
- Insert products into `ws_products` + `ws_product_allergens`
- Insert `ws_product_shops` rows (or rely on the default "all products available" rule)
- Insert `ws_product_prices` for any shop-specific price overrides
- Insert `ws_tours` + `ws_offices`
- Insert `ws_calendar_rules` (cutoff times + open days per shop per mode)
- Insert `ws_slots` (time slots per shop per mode)
- Insert `ws_pricing_rules` (cross-portion 4+1 rule per shop)

**3. Set daily stock** in `ws_product_stock` for each shop + date + mode.

**4. Wire the API stubs** — edit `api-config.js`:
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
window.WSBrand.endpoint     = BASE_URL + '/brand';
```

**5. Set up the cron** for releasing expired stock reservations (every 5 min).

**6. Verify** by checking `GET /catalog/products?shopId=your-shop-id` returns
products with the correct shop price and availability.

---

---

## 9. Availability System Tables

Added to support the central availability engine (`WSAvailability`). These tables
are the database source for `GET /availability/settings`, `GET /availability/days`,
`GET /availability/slots`, and `POST /availability/validate`.

---

### ws_shop_availability

Per-shop availability configuration. One row per shop. Replaces the hardcoded
`FALLBACK_RULES` seed in `webshop-calendar-api.jsx` and `webshop-availability-api.jsx`.

```sql
CREATE TABLE ws_shop_availability (
  shop_id                    VARCHAR(50) PRIMARY KEY REFERENCES ws_shops(id),
  collect_enabled            BOOLEAN DEFAULT TRUE,
  delivery_enabled           BOOLEAN DEFAULT TRUE,
  collect_open_days          JSONB    DEFAULT '[1,2,3,4,5,6]',  -- ISO weekdays Mon=1..Sun=7
  delivery_open_days         JSONB    DEFAULT '[1,2,3,4,5]',
  collect_hours_start        TIME     DEFAULT '08:00',
  collect_hours_end          TIME     DEFAULT '19:00',
  delivery_hours_start       TIME     DEFAULT '08:30',
  delivery_hours_end         TIME     DEFAULT '13:30',
  collect_slot_duration_min  INT      DEFAULT 60,   -- minutes per slot
  delivery_slot_duration_min INT      DEFAULT 120,
  collect_cutoff_hour        SMALLINT DEFAULT 16,   -- no same-day collect after 16:00
  collect_cutoff_minute      SMALLINT DEFAULT 0,
  collect_lead_hours         SMALLINT DEFAULT 2,
  delivery_cutoff_hour       SMALLINT DEFAULT 11,   -- no same-day delivery after 11:00
  delivery_cutoff_minute     SMALLINT DEFAULT 0,
  delivery_lead_hours        SMALLINT DEFAULT 20,
  collect_capacity_per_slot  INT      DEFAULT 15,
  delivery_capacity_per_slot INT      DEFAULT 30,
  timezone                   VARCHAR(50) DEFAULT 'Europe/Brussels',
  updated_at                 TIMESTAMP DEFAULT NOW()
);
```

**Notes:**
- `collect_open_days` / `delivery_open_days` — JSON array of ISO weekday integers (1=Mon…7=Sun).
- Cutoff = latest time a customer can order for *today*. Orders after cutoff must choose tomorrow or later.
- `lead_hours` = backend processing time before a slot can be fulfilled. Used by the
  availability engine to validate `next_available_date`.

---

### ws_shop_exceptions

Holiday and exceptional closure calendar. Overrides `ws_shop_availability.collect_open_days`
and `delivery_open_days` for specific dates.

```sql
CREATE TABLE ws_shop_exceptions (
  id                       SERIAL PRIMARY KEY,
  shop_id                  VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  exception_date           DATE        NOT NULL,
  type                     VARCHAR(20) NOT NULL CHECK (type IN ('closed','modified')),
  reason                   VARCHAR(200),                   -- shown as tooltip in date picker
  collect_hours_start      TIME,                           -- only for type='modified'
  collect_hours_end        TIME,
  delivery_hours_start     TIME,
  delivery_hours_end       TIME,
  collect_enabled          BOOLEAN,                        -- NULL = inherit from shop_availability
  delivery_enabled         BOOLEAN,
  created_by               INT REFERENCES ws_customers(id),
  created_at               TIMESTAMP DEFAULT NOW(),
  UNIQUE (shop_id, exception_date)
);
```

**Examples:**
```sql
-- National holiday: all modes closed
INSERT INTO ws_shop_exceptions (shop_id, exception_date, type, reason)
  VALUES ('chatelain', '2026-07-21', 'closed', 'Fête nationale belge');

-- Modified hours on Christmas Eve
INSERT INTO ws_shop_exceptions (shop_id, exception_date, type, reason,
  collect_hours_start, collect_hours_end, delivery_enabled)
  VALUES ('chatelain', '2026-12-24', 'modified', 'Veille de Noël — fermeture anticipée',
          '08:00', '14:00', FALSE);
```

---

### ws_product_availability

Per-product, per-shop availability override for lead times and channel restrictions.
When absent for a product+shop combination, defaults from the product's global
`no_delivery` flag and `ws_shop_availability` cutoffs apply.

```sql
CREATE TABLE ws_product_availability (
  id                       SERIAL PRIMARY KEY,
  product_id               INT         NOT NULL REFERENCES ws_products(id),
  shop_id                  VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,    -- days needed before collect date (D+0=same day)
  delivery_lead_time       SMALLINT DEFAULT 0,    -- days needed before delivery date
  collect_cutoff_override  TIME,                  -- NULL = use shop default
  delivery_cutoff_override TIME,                  -- NULL = use shop default
  max_qty_per_day          INT,                   -- NULL = unlimited
  max_qty_per_slot         INT,                   -- NULL = unlimited
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE (product_id, shop_id)
);
CREATE INDEX idx_prod_avail_shop ON ws_product_availability(shop_id);
```

**Lead time logic (backend):**
If `collect_lead_time = 2`, the earliest available collect date is `today + 2 days`.
The availability engine greys out dates in the date picker and shows `next_available_date`.

---

### ws_category_availability

Category-level availability defaults. Applied when `ws_product_availability` has no
row for a product. More specific product rows always win.

```sql
CREATE TABLE ws_category_availability (
  id                       SERIAL PRIMARY KEY,
  category_id              VARCHAR(50) NOT NULL REFERENCES ws_categories(id),
  shop_id                  VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  collect_enabled          BOOLEAN DEFAULT TRUE,
  delivery_enabled         BOOLEAN DEFAULT TRUE,
  collect_lead_time        SMALLINT DEFAULT 0,
  delivery_lead_time       SMALLINT DEFAULT 0,
  collect_cutoff_override  TIME,
  delivery_cutoff_override TIME,
  active                   BOOLEAN DEFAULT TRUE,
  UNIQUE (category_id, shop_id)
);
```

---

### ws_slot_capacity

Real-time slot utilisation. One row per shop + mode + date + slot window.
Created on demand when a customer books a slot; updated by the order pipeline.

```sql
CREATE TABLE ws_slot_capacity (
  id               SERIAL PRIMARY KEY,
  shop_id          VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  mode             VARCHAR(20) NOT NULL CHECK (mode IN ('collect','delivery')),
  slot_date        DATE        NOT NULL,
  slot_start       TIME        NOT NULL,
  slot_end         TIME        NOT NULL,
  max_orders       INT         NOT NULL,
  max_items        INT,
  current_orders   INT         NOT NULL DEFAULT 0,
  current_items    INT         NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP DEFAULT NOW(),
  UNIQUE (shop_id, mode, slot_date, slot_start)
);
CREATE INDEX idx_slot_cap_date ON ws_slot_capacity(shop_id, slot_date, mode);
```

**Backend logic:**
- `GET /availability/slots` returns rows from `ws_slot_capacity` where `current_orders < max_orders`.
- Slots with `current_orders >= max_orders` are returned with `available: false, reason: 'full'`.
- If no `ws_slot_capacity` row exists for the date, the backend generates slots from
  `ws_slots` + `ws_shop_availability.collect/delivery_slot_duration_min` and inserts them.

---

### ws_tour_availability

Delivery schedule for each tournée — which days it runs, its cutoff, and capacity.

```sql
CREATE TABLE ws_tour_availability (
  id               SERIAL PRIMARY KEY,
  tour_id          VARCHAR(50) NOT NULL REFERENCES ws_tours(id),
  shop_id          VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  delivery_day     SMALLINT    NOT NULL CHECK (delivery_day BETWEEN 1 AND 7), -- ISO weekday
  delivery_start   TIME        NOT NULL,
  delivery_end     TIME        NOT NULL,
  cutoff_time      TIME        NOT NULL,  -- last order time for this day's delivery
  max_orders       INT,                   -- NULL = unlimited
  max_items        INT,
  active           BOOLEAN DEFAULT TRUE,
  UNIQUE (tour_id, shop_id, delivery_day)
);
```

---

### ws_office_delivery_settings

Per-office delivery configuration. Defines which days an office is served and its
specific cutoff, overriding the tour's default where necessary.

```sql
CREATE TABLE ws_office_delivery_settings (
  id                  SERIAL PRIMARY KEY,
  office_id           VARCHAR(50) NOT NULL REFERENCES ws_offices(id),
  shop_id             VARCHAR(50) NOT NULL REFERENCES ws_shops(id),
  tour_id             VARCHAR(50) REFERENCES ws_tours(id),
  allowed_days        JSONB,              -- [1,2,3,4,5] — NULL = inherit from tour
  delivery_cutoff     TIME,               -- NULL = inherit from tour_availability
  delivery_notes      VARCHAR(500),       -- shown to customer at checkout
  active              BOOLEAN DEFAULT TRUE,
  UNIQUE (office_id, shop_id)
);
```

---

### Availability Resolution Priority

When the backend computes availability for a product + shop + date + mode:

```
1. ws_product_availability  (most specific — product + shop)
   ↓ if no row
2. ws_category_availability (category + shop)
   ↓ if no row
3. ws_shop_availability     (shop defaults)
   ↓ merged with
4. ws_shop_exceptions       (date overrides)
```

Lead time: `effective_lead_time = MAX(product_lead_time, category_lead_time)`
Cutoff: first non-null of `product_cutoff_override → category_cutoff_override → shop_cutoff`

---

### Updated Activation Checklist

In addition to the existing steps, populate the new tables:

```sql
-- Shop availability (one per shop)
INSERT INTO ws_shop_availability (shop_id, collect_cutoff_hour, delivery_cutoff_hour, ...)
  VALUES ('chatelain', 16, 11, ...);

-- Public holidays (Belgium)
INSERT INTO ws_shop_exceptions (shop_id, exception_date, type, reason)
  VALUES ('chatelain', '2026-07-21', 'closed', 'Fête nationale belge'),
         ('chatelain', '2026-11-11', 'closed', 'Armistice'),
         ('chatelain', '2026-12-25', 'closed', 'Noël');

-- Tour schedules
INSERT INTO ws_tour_availability (tour_id, shop_id, delivery_day, delivery_start, delivery_end, cutoff_time)
  VALUES ('tour-bxl-mid', 'chatelain', 1, '11:30', '13:30', '11:00'),  -- Monday
         ('tour-bxl-mid', 'chatelain', 2, '11:30', '13:30', '11:00'),  -- Tuesday
         ...;

-- Wire WSAvailability endpoint
window.WSAvailability.endpoint = BASE_URL + '/availability';
```

---

## 10. Delivery Fee System Tables

Delivery fees are resolved per **delivery site** (not per office client). One office client can have multiple delivery addresses, each with its own fee rules, payment type, and tournée stop.

### Fee resolution priority

```
delivery site rule → office client rule → tournée rule → shop rule → global rule
```

### ws_office_delivery_sites

One row per physical delivery address for a B2B office client.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `VARCHAR(36) PK` | UUID |
| `office_client_id` | `VARCHAR(36) FK → ws_offices.id` | The office company this site belongs to |
| `name` | `VARCHAR(120)` | Display name, e.g. "ACME — Rue de la Loi" |
| `address` | `VARCHAR(250)` | Street address |
| `floor_room` | `VARCHAR(120)` | Floor / room / door code (optional) |
| `contact_name` | `VARCHAR(120)` | On-site contact name |
| `contact_phone` | `VARCHAR(30)` | On-site contact phone |
| `tournee_id` | `VARCHAR(36) FK → ws_tours.id` | Tournée that serves this site |
| `tournee_stop_id` | `VARCHAR(36)` | Stop ID within the tournée route |
| `shop_id` | `VARCHAR(36) FK → ws_shops.id` | Shop this site is attached to |
| `active` | `BOOLEAN DEFAULT TRUE` | Soft-delete / deactivate |
| `created_at` | `TIMESTAMPTZ DEFAULT now()` | |
| `updated_at` | `TIMESTAMPTZ DEFAULT now()` | |

**Constraints:** Each office client must have at least one active delivery site before delivery orders are permitted.

### ws_delivery_fee_rules

Stores fee rules at each priority level. The `level` column determines which entity the rule applies to.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `VARCHAR(36) PK` | UUID |
| `level` | `ENUM('site','office','tour','shop','global')` | Resolution level |
| `site_id` | `VARCHAR(36) NULL FK → ws_office_delivery_sites.id` | Set when level = 'site' |
| `office_client_id` | `VARCHAR(36) NULL FK → ws_offices.id` | Set when level = 'office' |
| `tour_id` | `VARCHAR(36) NULL FK → ws_tours.id` | Set when level = 'tour' |
| `shop_id` | `VARCHAR(36) NULL FK → ws_shops.id` | Set when level = 'shop' |
| `free_delivery` | `BOOLEAN DEFAULT FALSE` | No fee regardless of order amount |
| `always_charge` | `BOOLEAN DEFAULT FALSE` | Fee applies even above the free threshold |
| `fee_amount` | `DECIMAL(8,2) DEFAULT 0` | Fee in EUR |
| `free_delivery_minimum` | `DECIMAL(8,2) DEFAULT 0` | Order amount above which fee is waived |
| `payment_type` | `ENUM('immediate','deferred') DEFAULT 'immediate'` | Payment flow for the checkout |
| `active` | `BOOLEAN DEFAULT TRUE` | |
| `created_at` | `TIMESTAMPTZ DEFAULT now()` | |

**Constraint:** At most one active rule per `(level, site_id/office_client_id/tour_id/shop_id)` combination. Global rule: enforce `level = 'global'` with a partial unique index.

**Fee computation (backend pseudo-code):**
```
if rule.free_delivery → fee = 0
elif rule.always_charge → fee = rule.fee_amount
elif subtotal >= rule.free_delivery_minimum → fee = 0
else → fee = rule.fee_amount
```

### Order metadata fields (ws_orders)

Add these columns to `ws_orders` to record the delivery site resolution:

| Column | Type | Notes |
|--------|------|-------|
| `office_client_id` | `VARCHAR(36) NULL` | Office client reference |
| `office_delivery_site_id` | `VARCHAR(36) NULL` | Specific site reference |
| `office_delivery_site_name` | `VARCHAR(120) NULL` | Snapshot of site name at order time |
| `payment_type` | `ENUM('immediate','deferred') DEFAULT 'immediate'` | Determines invoicing flow |
| `delivery_fee_applied` | `BOOLEAN DEFAULT FALSE` | Whether a fee was charged |
| `delivery_fee_amount` | `DECIMAL(8,2) DEFAULT 0` | Actual fee charged |
| `free_delivery_minimum` | `DECIMAL(8,2) DEFAULT 0` | Threshold in effect at order time |
| `tournee_stop_id` | `VARCHAR(36) NULL` | Tournée stop within the route |
| `delivery_mode` | `ENUM('office_delivery','collect') DEFAULT 'collect'` | |

### Sample data

```sql
-- Delivery sites for ACME Avocats
INSERT INTO ws_office_delivery_sites (id, office_client_id, name, address, floor_room, contact_name, contact_phone, tournee_id, tournee_stop_id, shop_id)
VALUES
  ('site-acme-loi',  'off-acme', 'ACME Avocats — Rue de la Loi',  'Rue de la Loi 120, 1040 Bxl',    '4e étage, salle Themis',  'Marie Dubois',   '+32 472 11 22 33', 'tour-bxl-mid', 'stop-acme-loi',  'chatelain'),
  ('site-acme-arts', 'off-acme', 'ACME Avocats — Place des Arts', 'Place des Arts 7, 1210 Saint-Josse', 'Réception',             'Pierre Fontaine', '+32 472 33 44 55', 'tour-bxl-am',  'stop-acme-arts', 'sablon');

-- Fee rules
INSERT INTO ws_delivery_fee_rules (id, level, site_id, free_delivery, always_charge, fee_amount, free_delivery_minimum, payment_type)
VALUES
  ('rule-site-loi',  'site', 'site-acme-loi',  FALSE, FALSE, 4.50, 40.00, 'deferred'),
  ('rule-site-arts', 'site', 'site-acme-arts', TRUE,  FALSE, 0,    0,     'immediate');

-- Global fallback rule
INSERT INTO ws_delivery_fee_rules (id, level, free_delivery, always_charge, fee_amount, free_delivery_minimum, payment_type)
VALUES ('rule-global', 'global', FALSE, FALSE, 7.00, 50.00, 'immediate');

-- Wire WSDeliveryFees endpoint
-- window.WSDeliveryFees.endpoint = BASE_URL + '/delivery-fees';
```

---

*See also: `API.md` for endpoint contracts · `DATA_SHAPES.md` for frontend field reference · `CLAUDE.md` for file ownership rules*
