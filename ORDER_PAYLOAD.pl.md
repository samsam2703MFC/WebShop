# Kontrakt zapisu zamówienia — pełen payload

> Źródła (stan repo, gałąź `claude/order-payload-distributor-fx9cjs`):
> `webshop-full-bundle.jsx:3997-4030` (budowa payloadu) · `webshop-orders-api.jsx`
> (transport) · `api-config.js` (adres) · `php-api/index.php:2595-3262`
> (`POST /orders` — walidacja + INSERT) · `php-api/index.php:4019-4032`
> (`POST /payments/checkout`) · `backend/schema/ws_schema.sql` (DDL).

## 0. Który mechanizm jest w użyciu

Odpowiedź obejmuje **oba warianty naraz**, bo w tej aplikacji są to dwa ogniwa
jednego łańcucha:

```
przeglądarka ──HTTP POST /orders──►  php-api/index.php  ──PDO/INSERT──►  MySQL (ws_orders, ws_order_lines)
   (wariant a)                        ta sama transakcja                      (wariant b)
```

**Nie ma zewnętrznego dystrybutora ani wypychania zamówienia do ERP.**
Sprawdzone: brak `curl_init` / klienta HTTP w `php-api/erp_*.php`, brak tabeli
`ws_outbox`/`fb_outbox`, katalog `php-api/cron/` zawiera wyłącznie dwa pliki SQL
(`clientb2b-top5-active.sql`, `sync-clientb2b.sql`). Synchronizacja z Franchise
Buddy jest **wewnątrz tej samej bazy** (schematy `fb_*` i `ws_*` obok siebie), a
podniesienie zamówień do ERP jest w `ERP_SYNC.md` §2 opisane jako **plan**, nie
jako działający kod. Jedyne wyjście na zewnątrz w ścieżce zamówienia to Stripe
(krok 2, §A.6).

Uwaga: `API.md:691` opisuje starszy, wyidealizowany kształt payloadu (`price`,
`basePrice`, `name` w koszyku, `bundleSlots` jako obiekty z etykietami, metoda
`bancontact`). **Poniższy dokument opisuje kod, nie `API.md`.**

---

# A. Warstwa HTTP — `POST /orders`

## A.1. Endpoint i metoda

| | |
|---|---|
| **Metoda** | `POST` |
| **URL** | `{BASE_URL}/orders` |
| **`BASE_URL`** | wyliczany w `api-config.js`: `location.origin + basePath + 'api'` — zawsze **to samo pochodzenie** co aplikacja. Dla `https://atelierby.online/webshop/index.html` → `https://atelierby.online/webshop/api/orders` |
| **Wywołanie** | `window.WSOrders.place(payload)` → `fetch(endpoint, {method:'POST', credentials:'include', …})` |
| **Idempotencja** | `requestKey` w body (patrz A.3) — powtórzenie tej samej klucza zwraca istniejące zamówienie, nie tworzy drugiego |

## A.2. Nagłówki żądania

```http
POST /api/orders HTTP/1.1
Content-Type: application/json
Authorization: Bearer <token>        # tylko gdy klient zalogowany
Cookie: <sesja>                      # fetch z credentials:'include'
```

- `Authorization` pochodzi z `WSAuth.authHeaders()` (`webshop-auth-api.jsx:25`):
  `localStorage['ws_auth_token']` → `Bearer <token>`. Token to podpisany HMAC-SHA256
  `{id, exp}` w base64url (`php-api/lib.php`, `sign_token`/`verify_token`) — **bez tabeli sesji**.
- Brak nagłówka = zamówienie **gościa** (nie błąd 401).
- **Nie ma nagłówka CSRF.** `api-config.js` ma zakomentowany szkielet `window._WS_CSRF`, nieużywany.
- CORS (`php-api/index.php:12-27`) dopuszcza `Content-Type, Authorization, X-Admin-Token, X-Pin-Token`
  i metody `GET, POST, PATCH, OPTIONS`, tylko dla origin z `cfg()['cors_origins']`. W produkcji
  same-origin, więc CORS nie wchodzi w grę.

## A.3. Body — pełen obiekt zamówienia

Dokładnie to, co konstruuje `handlePay()` (`webshop-full-bundle.jsx:3997-4030`):

```jsonc
{
  "shopId": 3,
  "requestKey": "ws-m8x2k1qa-7f3d9b2c",
  "mode": "delivery",
  "deliveryDate": "2026-09-04",

  "slot": {
    "slotId": "s-09",
    "label": "09:00–10:00",
    "date": "2026-09-04"
  },

  "basket": [
    {
      "productId": 142,
      "qty": 2,
      "portion": "demi",
      "note": "bez orzechów",
      "options": [ { "label": "Sauce : pesto" } ],
      "bundleId": 7,
      "bundleSlots": { "12": 41, "13": [55, 56] }
    },
    {
      "productId": 20,
      "qty": 1,
      "portion": null,
      "note": null,
      "options": [],
      "bundleId": null,
      "bundleSlots": {}
    }
  ],

  "voucher": "BIENVENUE10",
  "giftCode": "GIFT-8H2K",
  "note": "Proszę zadzwonić przy odbiorze",

  "companyId": 12,
  "onAccount": true,

  "customer": {
    "id": 87,
    "email": "marie@acme.be",
    "firstName": "Marie",
    "lastName": "Dubois",
    "phone": "+32 472 11 22 33",
    "officeId": 12
  },

  "payment": { "method": "stripe" },

  "delivery": {
    "office_client_id": 12,
    "office_delivery_site_id": 34,
    "office_delivery_site_name": "ACME — Bâtiment B",
    "address": "Rue de la Loi 155, 1040 Bruxelles",
    "tournee_id": 5,
    "tournee_stop_id": 88,
    "payment_type": "deferred",
    "delivery_fee_applied": false,
    "delivery_fee_amount": 0,
    "free_delivery_minimum": 75,
    "delivery_mode": "office_delivery"
  },

  "total": 48.50,

  "invoice": {
    "requested": true,
    "vat": "BE0123456789",
    "po": "PO-2026-118",
    "note": "Proszę zadzwonić przy odbiorze"
  }
}
```

**Warianty:** `customer` dla gościa to `{firstName, lastName, email, phone}` (bez `id`,
bez `officeId`). `delivery` jest **`null`** w trybie `collect` lub gdy brak biura.
`invoice` jest **`null`**, gdy klient nie zaznaczył faktury. `voucher`, `giftCode`,
`note`, `companyId` są `null`, gdy nieużyte.

## A.4. Pole po polu — typ, źródło, czy serwer ufa

Legenda kolumny **Serwer**: ✅ używane · ♻️ **przeliczane od nowa** (wartość z klienta
ignorowana) · ⛔️ czytane w ogóle nie jest · 🔒 nadpisywane sesją.

| Pole | Typ | Serwer | Uwaga |
|---|---|---|---|
| `shopId` | int | ✅ **wymagane** | brak → `400 {"error":"shopId et basket requis"}` |
| `requestKey` | string ≤64, `[A-Za-z0-9_-]` | ✅ | `'ws-' + base36(Date.now()) + '-' + rand`, stabilny na całą sesję kreatora; sanityzowany serwerowo; zapisywany do `ws_orders.request_key` (UNIQUE, migracja 0051) |
| `mode` | `"collect"` \| `"delivery"` | ✅ | domyślnie `collect` |
| `deliveryDate` | `YYYY-MM-DD` (**czas lokalny**, `isoOf`) | ✅ | fallback: `slot.date`, potem `date('Y-m-d')` serwera |
| `slot.slotId` | int \| string | ✅ częściowo | zapisywane tylko gdy **numeryczne**; `"s-09"` → `slot_id = NULL` |
| `slot.label` | string | ✅ | z niego regex `(\d{1,2}):(\d{2})` wyciąga `slot_start` do kontroli pojemności |
| `slot.date` | `YYYY-MM-DD` | ✅ | fallback dla `deliveryDate` |
| `basket[].productId` | int | ✅ **wymagane** | produkt musi istnieć i mieć `active=1`, inaczej linia jest **po cichu pomijana** |
| `basket[].qty` | int | ✅ | `max(1, (int)qty)` |
| `basket[].portion` | `"quart"`\|`"demi"`\|`"entier"`\|null | ✅ | ≠ `entier` → cena z `erp_portion_options()`; brak ceny porcji → **409** |
| `basket[].note` | string | ✅ | przycinane do 255 znaków |
| `basket[].options` | `[{label}]` | ⛔️ | **serwer nigdy tego nie czyta** — czysto wyświetlanie w koszyku |
| `basket[].bundleId` | int \| null | ✅ | weryfikowany wobec `ws_bundles` |
| `basket[].bundleSlots` | `{ [slotId]: choiceId \| choiceId[] }` | ✅ | **same ID, bez etykiet i bez cen**; rozwiązywane przez `bundle_compose()` |
| `voucher` | string \| null | ✅ | `strtoupper(trim())`, pełna rewalidacja (ważność, `min_order`, `shop_id`, targeting, limit/klienta, zakres produktowy) |
| `giftCode` | string \| null | ✅ | nieprawidłowy → **po cichu ignorowany**, zamówienie przechodzi |
| `note` | string | ✅ | przycinane do 1000 znaków |
| `companyId` | int \| null | ✅ | e-mail musi być w `ws_office_emails` dla tego biura, inaczej **403** |
| `onAccount` | bool | ✅ | działa tylko gdy biuro ma `deferred_billing_enabled` |
| `customer.id` | int | 🔒 | **nadpisywane** przez `auth_uid()`. Bez tokenu → zamówienie gościa. (Wyjątkowo `customer.id` z body trafia do `$buyerId` — użyte **wyłącznie** do zwolnienia własnych rezerwacji stocku, `index.php:3004`) |
| `customer.email` | string ≤190 | ✅ | → `guest_email` tylko dla gościa; dla zalogowanego bierze się `client.email` |
| `customer.firstName`+`lastName` | string ≤190 | ✅ | sklejane w `guest_name`, tylko dla gościa |
| `customer.phone` | string | ✅ | `norm_phone()`; front **nie wysyła** `phonePrefix` → prefiks domyślny `+32` |
| `customer.officeId` | int | ⛔️ | nieużywane — biuro idzie przez `companyId` / `delivery.office_client_id` |
| `payment.method` | `"stripe"`\|`"shop"`\|`"deferred"`\|… | ✅ | walidowane przez `payment_family()` + `allowed_methods($shop,$profile)`; puste → **400**, `shop`+`delivery` → **400**, spoza listy → **400** |
| `delivery.office_client_id` | int | ✅ | → `ws_orders.office_client_id` (jeśli nie ma `companyId`) |
| `delivery.office_delivery_site_id` | int | ✅ | gdy puste, a biuro znane → serwer **sam dobiera** domyślny site z trasą (`index.php:2932-2965`) |
| `delivery.office_delivery_site_name` | string ≤120 | ✅ | zapisywane dosłownie |
| `delivery.tournee_id` | int | ✅ częściowo | używane **tylko** do wyszukania reguły opłaty `level='tour'`; **nie trafia** do `ws_orders.tour_id` |
| `delivery.tournee_stop_id` | int | ✅ | zapisywane |
| `delivery.address` | string | ⛔️ | nieczytane |
| `delivery.payment_type` | string | ♻️ | wyliczane z `ws_delivery_fee_rules` (+ `deferred` przy `onAccount`) |
| `delivery.delivery_fee_applied` / `delivery_fee_amount` / `free_delivery_minimum` | | ♻️ | liczone serwerowo z `ws_delivery_fee_rules` |
| `delivery.delivery_mode` | string | ♻️ | serwer ustawia `office_delivery` \| `collect` z `mode` |
| `total` | number | ♻️ | **wysyłane, ale całkowicie ignorowane** — cena to `ws_products.price` przez `prix_produits()` |
| `invoice.requested` | bool | ✅ | → `invoice_requested` (0/1) |
| `invoice.vat` | string ≤40 | ✅ | → `invoice_vat` |
| `invoice.po` | string ≤100 | ✅ | → `po_number` |
| `invoice.note` | string | ⛔️ | duplikat `note`; czytane jest tylko `note` z korzenia |

**Pola przyjmowane przez serwer, których front NIE wysyła** (dla integratorów):
`lang` (→ `ws_orders.lang`, domyślnie `'fr'` — front nigdy tego nie ustawia),
`fiscalTicketNo` ≤40, `fiscalTicketUrl` ≤255 (migracja 0085 — bilet fiskalny z kasy),
`customer.phonePrefix`, oraz płaskie aliasy `slotId` / `slotLabel` / `email` /
`paymentMethod` i snake→camel w `delivery` (`siteId`, `officeClientId`, `tourId`,
`siteName`, `tourneeStopId`).

**Pola koszyka, które zostają w przeglądarce** (`basket.map` na `index:4009` je odcina):
`name`, `price`, `basePrice`, `crossPortion`, `cat`, `offerDiscount`, `offerLabel`.

## A.5. Odpowiedzi

Wszystkie: `Content-Type: application/json; charset=utf-8`,
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache`
(`php-api/lib.php:35`, `json_out()`).

**200 — zapisane**
```json
{
  "ok": true,
  "orderId": 4821,
  "orderRef": "WS-175620841273",
  "subtotal": 52.00,
  "promo": 6.50,
  "webshopDiscount": 0,
  "voucherDiscount": 0,
  "deliveryFee": 3.00,
  "paymentType": "immediate",
  "onAccount": false,
  "total": 48.50
}
```
> `orderRef` = `'WS-' . time() . rand(10,99)`. **`total` z odpowiedzi jest kwotą wiążącą** —
> front nadpisuje nim swój podgląd (`webshop-full-bundle.jsx:4087`).

**200 — deduplikacja** (ten sam `requestKey`): `{"ok":true,"orderId":…,"orderRef":…,"total":…,"deduplique":true}`

**400** — `{"error":"shopId et basket requis"}` · `{"error":"aucun produit valide"}` ·
`{"error":"Moyen de paiement requis","profile":"guest","allowed":["stripe","shop"]}` ·
`{"error":"Moyen de paiement non autorisé pour ce profil","profile":…,"allowed":[…]}`

**403** — `{"error":"Cet e-mail n'est pas rattaché à ce compte entreprise"}`

**409** — konflikt stanu, transakcja wycofana:
```json
{"error":"Stock insuffisant","product":"Tarte aux fraises","available":3}
{"error":"Créneau complet","slot":"09:00–10:00"}
{"error":"Tournée complète pour cette date","date":"2026-09-04"}
{"error":"« Bûche de Noël » n'est pas disponible à la date choisie — …"}
{"error":"Prix indisponible pour « … » dans cette boutique — …"}
{"error":"Prix de portion indisponible pour « … » — …"}
{"error":"…","code":"office_delivery"}
{"error":"Aucun moyen de paiement n'est disponible pour cette boutique","profile":"guest"}
```

**500** — `{"error":"Commande non enregistrée — erreur serveur","detail":"<300 znaków>"}`
(cały handler jest pod `try/catch`; ROLLBACK gwarantowany).

**Parsowanie po stronie klienta** (`webshop-orders-api.jsx`): `r.ok` → `{ok:true, ...json}`.
Inaczej `throw new Error(error + " : « " + product + " »" + " (il en reste N)" + " — " + detail)`.

## A.6. Krok 2 — płatność online: `POST {BASE_URL}/payments/checkout`

Wywoływane **po** `place()`, tylko gdy rodzina metody = `stripe` i płatność nie jest odroczona.

```http
POST /api/payments/checkout
Content-Type: application/json
Authorization: Bearer <token>

{ "orderId": 4821 }
```
→ `200 {"ok":true,"orderId":4821,"checkoutUrl":"https://checkout.stripe.com/…"}` — front robi
`window.location.href = checkoutUrl`.
→ `404 {"error":"Commande introuvable"}` · `503 {"error":"Paiement indisponible (Stripe non configuré)","orderId":…,"status":…}` · `502 {"error":"Échec Stripe"}`

**`payment_status='paid'` ustawia wyłącznie webhook** `POST /payments/stripe-webhook`
(weryfikacja HMAC-SHA256 nagłówka `Stripe-Signature`, tolerancja 5 min, idempotencja przez
`ws_stripe_event`, kontrola zgodności `amount_total` z `ws_orders.total` do 1 centa).
Powrót przeglądarki nigdy nie oznacza zapłaty.

---

# B. Warstwa bazy — kontrakt INSERT

Wszystko dzieje się w **jednej transakcji PDO** (`$pdo->beginTransaction()`,
`index.php:2979`) — wszystko albo nic. Zapis idzie przez `q($sql, $params)` =
`PDO::prepare()->execute()`, czyli **zawsze parametry wiązane**, nigdy konkatenacja.

## B.1. Kolejność operacji w transakcji

| # | Operacja | Blokada / skutek |
|---|---|---|
| 1 | Zwolnienie **własnych** rezerwacji kupującego | `UPDATE ws_stock_reservation SET released_at=NOW()` + przeliczenie `ws_product_stock.qty_reserved` |
| 2 | Kontrola stocku, per linia | `SELECT … FROM ws_product_stock … FOR UPDATE`; brak wiersza → limit z `ws_product_stock_defaults` (dzień ISO × kanał); brak i tam → **bez limitu** |
| 3 | Pojemność slotu | `SELECT … FROM ws_slot_capacity … FOR UPDATE` |
| 4 | Pojemność trasy B2B | `ws_tour_availability` vs `COUNT(*)` z `ws_orders` |
| 5 | **INSERT `ws_orders`** (dynamiczny) | `$oid = lastInsertId()` |
| 6 | **INSERT `ws_order_lines`** — linia matka | `$parentId = lastInsertId()` |
| 7 | **INSERT `ws_order_lines`** — komponenty menu | `parent_line_id = $parentId` |
| 8 | Dekrementacja stocku | `UPDATE ws_product_stock SET qty_sold = qty_sold + ?`; gdy 0 wierszy **i** istnieje minimum tygodniowe → `INSERT … ON DUPLICATE KEY UPDATE` |
| 9 | Linia prezentowa 0 € + `ws_promo_progress.redeemed_at` | tylko przy ważnym `giftCode` |
| 10 | `ws_slot_capacity` +1 zamówienie, +`totalQty` sztuk | |
| 11 | `voucher_code.usage_count` +1, `INSERT voucher_redemption` | we **własnym** `try/catch` — błąd tu **nie anuluje** zamówienia |
| 12 | `COMMIT` | potem e-mail `send_order_email()` i `json_out()` |

## B.2. `ws_orders` — dokładny obiekt przekazany do INSERT

Handler buduje asocjacyjną tablicę `$ordVals`, po czym **odsiewa kolumny nieistniejące
w bazie** (`col_exists`) i składa `INSERT INTO ws_orders (…) VALUES (?,?,…)` — dlatego
starszy schemat nie wywala 500, tylko po cichu pomija kolumny.

```php
$ordVals = [
  'order_ref'                 => 'WS-175620841273',   // 'WS-' . time() . rand(10,99)
  'shop_id'                   => 3,                    // $b['shopId']
  'customer_id'               => 87,                   // auth_uid() — NIGDY z body
  'guest_email'               => null,                 // tylko gdy customer_id === null
  'guest_name'                => null,                 // firstName + ' ' + lastName
  'guest_phone'               => null,                 // norm_phone()[1]
  'guest_phone_prefix'        => null,                 // norm_phone()[0], domyślnie '+32'
  'mode'                      => 'delivery',
  'status'                    => 'pending',            // 'confirmed' gdy $onAccount
  'slot_id'                   => null,                 // int TYLKO gdy is_numeric($b['slotId'])
  'slot_label'                => '09:00–10:00',
  'delivery_date'             => '2026-09-04',         // fallback date('Y-m-d') — nigdy NULL
  'subtotal'                  => 52.00,                // Σ (unit + suppl) * qty  [SERWER]
  'promo_amount'              => 6.50,                 // cross_portion_rule()    [SERWER]
  'webshop_discount'          => 0.00,                 // shops.discount_type/value
  'voucher_code'              => null,                 // po pełnej rewalidacji
  'voucher_discount'          => 0.00,
  'total'                     => 48.50,                // max(0, sub − promo − wsDisc − voucher + fee)
  'payment_method'            => 'stripe',             // 'account' gdy $onAccount
  'payment_status'            => 'pending',            // stałe; zmienia je TYLKO webhook
  'lang'                      => 'fr',                 // $b['lang'] ?? 'fr'
  'note'                      => 'Proszę zadzwonić…',  // ≤1000
  'delivery_mode'             => 'office_delivery',    // z $mode, nie z body
  'office_client_id'          => 12,                   // companyId ?? delivery.office_client_id
  'office_delivery_site_id'   => 34,                   // z body lub auto-dobrany
  'office_delivery_site_name' => 'ACME — Bâtiment B',
  'tournee_stop_id'           => 88,
  'payment_type'              => 'immediate',          // ws_delivery_fee_rules / 'deferred'
  'delivery_fee_applied'      => 1,                    // 0|1
  'delivery_fee_amount'       => 3.00,
  'free_delivery_minimum'     => 75.00,
  'po_number'                 => 'PO-2026-118',        // ≤100
  'invoice_requested'         => 1,                    // 0|1
  'invoice_vat'               => 'BE0123456789',       // ≤40
  'source'                    => 'webshop',            // stałe
  'fiscal_ticket_no'          => null,                 // ≤40  (migracja 0085)
  'fiscal_ticket_url'         => null,                 // ≤255 (migracja 0085)
  'request_key'               => 'ws-m8x2k1qa-7f3d9b2c', // null gdy kolumny brak
];
```

**Typy kolumn** (`ws_schema.sql` + migracje 0011/0031/0032/0051/0085): `order_ref VARCHAR(50) UNIQUE`,
`shop_id/customer_id/slot_id/office_client_id/office_delivery_site_id/tournee_stop_id INT`,
`guest_email VARCHAR(190)`, `guest_name VARCHAR(190)`, `guest_phone VARCHAR(30)`,
`guest_phone_prefix VARCHAR(8)`, `mode VARCHAR(20)`, `status VARCHAR(30) DEFAULT 'pending'`,
`slot_label VARCHAR(120)`, `delivery_date DATE`, kwoty `DECIMAL(10,2)`,
`payment_method VARCHAR(40)`, `payment_status VARCHAR(30)`, `lang VARCHAR(8)`,
`note VARCHAR(1000)`, `delivery_mode VARCHAR(30)`, `office_delivery_site_name VARCHAR(200)`,
`payment_type VARCHAR(20)`, `delivery_fee_applied TINYINT(1)`,
`delivery_fee_amount/free_delivery_minimum DECIMAL(10,2)`, `po_number VARCHAR(100)`,
`invoice_requested TINYINT(1)`, `invoice_vat VARCHAR(40)`, `source VARCHAR(20)`,
`request_key VARCHAR(64) UNIQUE`, `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`.

**Kolumny schematu, których `POST /orders` NIE wypełnia:** `tour_id`, `delivered_at`,
`delivery_proof`, `prep_by`, `rating` — zostają NULL/domyślne, uzupełnia je back-office.

## B.3. `ws_order_lines` — trzy rodzaje wierszy

INSERT jest **stały**, nie dynamiczny:

**(1) Linia sprzedana** — jedna na pozycję koszyka:
```sql
INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note)
VALUES (?,?,?,?,?,?,?)
-- [4821, 142, 'Tarte aux fraises', 2, 21.00, 'demi', 'bez orzechów']
```
`unit_price` = `round(cena_produktu_lub_porcji + ws_bundles.price_modifier, 2)`.
Ceny **wyłącznie** z `prix_produits()` / `erp_portion_options()` — cena z przeglądarki
nie jest nigdy używana. `portion` w backtickach (słowo zarezerwowane MariaDB).

**(2) Komponent menu** — jeden wiersz na wybór, tylko gdy istnieje `parent_line_id` (migracja 0055):
```sql
INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`, note, parent_line_id)
VALUES (?,?,?,?,?,?,?,?)
-- [4821, 88, 'Cake Nature', 2, 5.00, null, 'Supplément de « Menu Midi »', 917]
-- [4821, 91, 'Limonade',    2, 0.00, null, 'Inclus dans « Menu Midi »',   917]
```
`unit_price` = **wyłącznie `delta` wyboru** (`ws_bundle_slot_choices.delta`). Składnik wliczony
w formułę zostaje na 0 € — inaczej obrót liczyłby się dwa razy. Suma linii = `total`, bo
dopłata została zdjęta z linii matki. `product_id` z `ws_bundle_slot_choices.product_id`
(migracja 0057), z awaryjnym dopasowaniem po nazwie.

**(3) Linia prezentowa** — przy ważnym `giftCode`, `qty=1`, `unit_price=0`,
`note = 'Cadeau — campagne <id>'`, **bez** dekrementacji stocku.

**Kolumny `bundle_id`, `options`, `bundle_slots` (JSON) istnieją w `ws_schema.sql`,
ale nie są zapisywane** — kompozycja menu jest materializowana jako wiersze potomne (2).

## B.4. Skutki uboczne poza `ws_orders` / `ws_order_lines`

| Tabela | Zapis |
|---|---|
| `ws_product_stock` | `qty_sold += qty`; ewentualny `INSERT … ON DUPLICATE KEY UPDATE` z minimum tygodniowego. Brak minimum = **brak limitu** → celowo nie tworzy wiersza |
| `ws_stock_reservation` | `released_at = NOW()` dla rezerwacji kupującego w tym dniu/kanale |
| `ws_slot_capacity` | `current_orders += 1`, `current_items += Σ qty` |
| `voucher_code` | `usage_count += 1` |
| `voucher_redemption` | `INSERT (…, status='CONFIRMED', channel='WS', request_key='WS-ORDER-<oid>')`, tylko gdy `franchisee_shop.id = shopId` istnieje |
| `ws_promo_progress` | `redeemed_at = CURRENT_TIMESTAMP` dla wykorzystanego prezentu |
| `ws_stripe_event` | dopiero z webhooka, nie z `POST /orders` |

## B.5. Reguły niezmienne (do przekazania integratorowi)

1. **Cena nigdy nie pochodzi od klienta.** `total`, `price`, `basePrice`, `delta` z payloadu są
   ignorowane lub w ogóle nieczytane. Jedyne źródło: `ws_products.price` przez `prix_produits()`,
   ceny porcji przez `erp_portion_options()`, dopłaty przez `ws_bundles.price_modifier` +
   `ws_bundle_slot_choices.delta`.
2. **Tożsamość nigdy nie pochodzi od klienta.** `$b['customerId'] = auth_uid() ?: null` — nadpisanie
   bezwarunkowe, żeby nie dało się złożyć zamówienia w cudzym imieniu.
3. **Brak ceny = odmowa (409), nie cena zastępcza.** Żadnych fallbacków ani mnożników porcji.
4. **Idempotencja przez `requestKey`** — dwuklik, zerwane połączenie i ponowienie dają to samo
   `orderId` (`deduplique: true`).
5. **Zapłata tylko z webhooka Stripe** — `payment_status` zostaje `pending`, dopóki podpisany
   event nie potwierdzi kwoty.
6. **INSERT do `ws_orders` jest dynamiczny** — dodanie kolumny do payloadu bez migracji nic nie
   zepsuje, ale też nic nie zapisze. Kolumnę trzeba dołożyć migracją w `php-api/migrations/`.
