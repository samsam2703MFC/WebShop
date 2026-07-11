# 🤝 Przekazanie projektu programiście — Sklep internetowy L'Atelier By

**Dla:** przejmującego programisty / informatyka
**Repozytorium:** https://github.com/samsam2703MFC/WebShop
**Gałąź robocza:** `feat/multi-shop-routing` (cała praca opisana poniżej znajduje się tutaj, nie na `main`)
**Status:** cały kod jest napisany i przetestowany. Pozostało **wdrożenie na hostingu klienta**.

> ## ✅ WYBRANA ARCHITEKTURA — pełny React + PHP, na serwerze klienta, **BEZ WooCommerce**
> Cała strona działa na hostingu współdzielonym klienta: **statyczny frontend React + `php-api/`
> (PHP) + MySQL `ws_`**. Bez Node.js, bez VPS, bez WooCommerce (zostało usunięte z repo).
> Serwuj pliki React i API PHP z **tego samego serwera** (ten sam origin → brak problemów
> z CORS i mixed-content).

---

## 1. Czym jest ten projekt

Wielosklepowy ("franczyzowy") sklep internetowy z jedzeniem dla **L'Atelier By**
(piekarnia / lunch, Belgia). Kilka sklepów (Halle, Corbais, Gosselies, Sombreffe,
Gembloux), każdy z własnymi cenami, dziennym stanem produkcji (stock), dostawą B2B do
biur (trasy/biura), regułami dostępności i promocją 4+1 (cross-portion).

## 2. Architektura (decyzje podjęte — proszę nie zmieniać)

```
┌───────────────────────────┐     HTTPS/JSON      ┌──────────────────────────┐
│  Frontend React (PWA)      │  ───────────────▶   │  API PHP  (php-api/)      │
│  (GitHub Pages, build Vite)│                     │  czyta bazę ws_           │
└───────────────────────────┘                     └────────────┬─────────────┘
                                                                │ PDO (localhost)
                                                   ┌────────────▼─────────────┐
                                                   │  MySQL  schemat "ws_"     │
                                                   │  = ŹRÓDŁO PRAWDY (33 tab.) │
                                                   └──────────────────────────┘
```

- **Baza MySQL `ws_` jest jedynym źródłem prawdy** (katalog, ceny per sklep, dzienny
  stan magazynowy, menu, sieć B2B, klienci, zamówienia) — 33 tabele.
- **API jest w PHP** (`php-api/`), ponieważ klient korzysta z **hostingu współdzielonego**
  (brak SSH, brak Node.js). Działa na dowolnym hostingu PHP 8+ z lokalną bazą.
- **Frontend to React** (build Vite, instalowalna PWA) serwowany przez GitHub Pages.
  Przełącza się z danych demo na żywe API przez ustawienie jednej zmiennej (`BASE_URL` w `api-config.js`).
- **WooCommerce NIE jest używany** — stack React + PHP całkowicie go zastępuje, a kod
  WooCommerce został usunięty z repo.

## 3. Aktualny status

| Komponent | Status |
|---|---|
| Schemat bazy (`backend/schema/ws_schema.sql`, 33 tabele) | ✅ gotowy do importu |
| Dane 5 realnych sklepów (`backend/schema/seed-shops.sql`) | ✅ gotowe do importu |
| **API PHP** (`php-api/`) — wszystkie endpointy, auth, płatności | ✅ napisane i przetestowane |
| Panel administracyjny (`php-api/admin/`) — produkty, ceny, stany, zamówienia | ✅ napisane |
| Podłączenie frontendu na żywo (`api-config.js`) | ⏳ **wymaga URL API** |
| Wdrożenie na hostingu klienta | ⏳ **DO ZROBIENIA (Twoje zadanie)** |

## 4. Co pozostało do zrobienia — krok po kroku

### Krok 1 — Baza danych (w phpMyAdmin)
1. W istniejącej bazie (`test-webshop_db`) **zaimportuj** `backend/schema/ws_schema.sql`
   → tworzy 33 tabele. *(Uwaga: `portion` jest w backtickach, bo to słowo zastrzeżone
   MariaDB — zostaw tak.)*
2. **Zaimportuj** `backend/schema/seed-shops.sql` → 5 sklepów (ID to identyfikatory
   Franchise Buddy: Halle=4, Corbais=2, Gosselies=3, Sombreffe=5, Gembloux=10).

### Krok 2 — Wczytaj katalog
Wprowadź ok. 27 produktów przez panel administracyjny (`php-api/admin/`) albo zaimportuj
masowo skrypt INSERT w phpMyAdmin (jeden wiersz na produkt × sklep do `ws_products` /
`ws_product_prices` / `ws_product_stock`).

Następnie uzupełnij pozostałe dane franczyzowe: `ws_product_prices`,
`ws_product_stock`, `ws_slots`, `ws_calendar_rules`, `ws_shop_availability`,
`ws_offices`, `ws_tours`, `ws_delivery_fee_rules`, `ws_pricing_rules`, `ws_vouchers`.
Wzorcowe zapytania: `backend/schema/api-queries.sql`.

### Krok 3 — Wdróż API PHP (to jest backend)
1. Wgraj folder `php-api/` na hosting (np. `public_html/api/`).
2. Edytuj `php-api/config.php` z prawdziwymi danymi bazy:
   ```php
   'host' => 'localhost',
   'name' => 'test-webshop_db',
   'user' => '...',
   'pass' => '...',            // prawdziwe hasło do bazy
   'auth_secret' => '...'      // długi losowy ciąg (podpisuje tokeny sesji)
   ```
3. Test w przeglądarce: `https://<domena>/api/shops` → musi zwrócić sklepy jako JSON.
   - API wymaga `mod_rewrite` (dla dołączonego `.htaccess`) oraz przekazywania nagłówka
     `Authorization` do PHP (obsłużone w `.htaccess`; niektóre hostingi wymagają też
     `CGIPassAuth On` lub PHP w trybie FastCGI).

### Krok 4 — Podłącz frontend
W `api-config.js` ustaw:
```js
const BASE_URL = 'https://<domena>/api';
```
Commit i push → GitHub Pages wdroży automatycznie. Sklep czyta teraz prawdziwą bazę.
**Frontend musi być HTTPS-do-HTTPS** (GitHub Pages jest na HTTPS, więc API też musi być
na HTTPS — użyj certyfikatu SSL hostingu).

### Krok 5 — Płatności (opcjonalnie, gdy gotowe)
Wstaw klucz Stripe w `php-api/config.php` (`'stripe_secret' => 'sk_live_…'`).
`POST /payments/checkout` zwróci wtedy URL do Stripe Checkout. Bez klucza zwraca 503,
a reszta API działa dalej.

### Krok 6 — Panel administracyjny, e-maile i inne wbudowane funkcje
Wszystko, czego potrzebuje sklep (katalog, koszyk, zamówienia, płatności, konta
klientów), obsługuje frontend React + `php-api/`. Najważniejsze wbudowane funkcje:

- ✅ **Panel administracyjny** — `php-api/admin/index.html` (otwórz `https://<domena>/api/admin/`).
  Zarządzanie produktami, cenami per sklep, dziennym stanem i statusami zamówień.
  Chroniony przez `admin_token` (ustaw w `config.php`). Endpointy: `/admin/products`,
  `/admin/price`, `/admin/stock`, `/admin/orders`, `/admin/orders/:id/status`.
- ✅ **E-maile potwierdzające zamówienie** — wysyłane przy `POST /orders` przez PHP `mail()`
  (ustaw `mail_from` w `config.php`; przekaż `email` w zamówieniu lub użyj zalogowanego
  klienta). Best-effort: błąd e-maila nigdy nie blokuje zamówienia.
- ⚠️ Płatności nadal tylko Stripe (brak innych bramek / interfejsu zwrotów) + brak faktur
  PDF — do dodania później w razie potrzeby.

## 5. Kluczowe pliki i lokalizacje

| Ścieżka | Co to |
|---|---|
| `php-api/` | **Backend API** (PHP). `index.php` = wszystkie trasy; `config.php` = dane dostępowe. |
| `backend/schema/ws_schema.sql` | Schemat `ws_` (33 tabele, wzorcowy). |
| `backend/schema/seed-shops.sql` | 5 sklepów. |
| `backend/schema/api-queries.sql` | Wzorcowy SQL dla każdego endpointu. |
| `DEPLOY.md` / `DEPLOY.pl.md` | Instrukcja wdrożenia krok po kroku (EN / PL). |
| `api-config.js` | Frontend: ustaw tutaj `BASE_URL`, aby przejść na żywo. |

## 6. Endpointy API (serwowane przez `php-api/`)

```
GET  /shops                          GET  /brand?shopId=
GET  /catalog/categories?shopId=     GET  /catalog/products?shopId=
GET  /catalog/stock?shopId=&mode=
GET  /availability/settings|days     GET  /calendar/slots|cutoff|exceptions
GET  /pricing/promos/cross-portion   POST /vouchers/redeem
GET  /tours  /offices  /offices/:id  POST /delivery-fees/quote
POST /orders                         GET  /orders/:id
POST /auth/register|login   GET/PATCH /auth/me
POST /payments/checkout
```
- Ceny/sumy są zawsze liczone **po stronie serwera** (wartości od klienta są ignorowane).
- Hasła używają PHP `password_hash`/`password_verify` (bcrypt). Sesje to podpisany token
  HMAC (`Authorization: Bearer <token>`) — bez tabeli sesji.

## 7. Ważne uwagi / pułapki

- **Hosting współdzielony = API w PHP.** `php-api/` działa na dowolnym hoście PHP 8+ —
  bez Node.js, bez menedżera procesów, bez VPS.
- **Baza musi być lokalna względem API** (PDO `localhost`). Na hostingu współdzielonym
  MySQL zwykle nie jest dostępny z zewnątrz — dlatego API działa na tym samym hoście.
- **Sekrety nigdy nie trafiają do Git.** Dane z `config.php`, klucze Stripe i hasło do
  bazy istnieją tylko na serwerze. `.env` jest ignorowany przez Git.
- 🔴 **Zmień hasło do bazy** — było udostępnione jawnie podczas konfiguracji; zmień je w
  phpMyAdmin i zaktualizuj `config.php`.
- Wielosklepowość: każde wywołanie API przyjmuje `shopId` (całkowite ID sklepu, np. 2 = Corbais).

## 8. Podsumowanie przekazania

Wszystko jest zakodowane i przetestowane. Aby uruchomić produkcyjnie, programista musi:
**zaimportować schemat + sklepy → wczytać katalog → wgrać `php-api/` i ustawić
`config.php` → wskazać `api-config.js` na URL API → zrobić push.** Opcjonalnie dodać
Stripe. Szacowany nakład: kilka godzin, głównie wprowadzanie danych + konfiguracja hostingu.

Pytania o dowolny endpoint lub tabelę: wzorem jest SQL w `backend/schema/api-queries.sql`
oraz kod w `php-api/index.php`.
