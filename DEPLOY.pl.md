# 🚀 Przewodnik wdrożenia — Sklep L'Atelier By (dla Szymona)

Wszystko jest napisane i przetestowane. Ten przewodnik prowadzi projekt od obecnego
stanu (frontend działa w trybie demo) do **pełnej produkcji** na serwerze klienta.

- **Repozytorium:** https://github.com/samsam2703MFC/WebShop — pracuj na gałęzi **`feat/multi-shop-routing`**; wdrażana jest gałąź `main`.
- **Frontend na żywo:** https://samsam2703mfc.github.io/WebShop/ (obecnie dane demo)

---

## 0. Architektura (co gdzie działa)

```
┌────────────────────────┐   HTTPS/JSON   ┌───────────────────────┐   PDO/localhost   ┌──────────────┐
│  Frontend (React PWA)   │ ─────────────▶ │  PHP API  (php-api/)   │ ────────────────▶ │  MySQL  ws_  │
│  GitHub Pages (Actions) │                │  na serwerze klienta   │                   │  33 tabele   │
└────────────────────────┘                └───────────────────────┘                   └──────────────┘
```

- **Frontend** = **PWA zbudowana przez Vite** (React). Hostowana na **GitHub Pages**, wdrażana automatycznie
  przez **GitHub Actions** przy każdym pushu do `main` (`.github/workflows/deploy.yml`). Instalowalna, offline app-shell.
- **Backend** = **`php-api/`** (czysty PHP + PDO). Działa na **hostingu współdzielonym** klienta (PHP 8+). Bez Node, bez VPS.
- **Baza danych** = schemat **`ws_`** (`backend/schema/ws_schema.sql`, 33 tabele) = jedyne źródło prawdy.
- **WooCommerce NIE jest używane** (usunięte z repozytorium). `backend/` zawiera teraz wyłącznie schemat SQL (`backend/schema/`).

---

## 1. Baza danych (phpMyAdmin) ⏱️ ~5 min

1. Otwórz phpMyAdmin, wybierz bazę danych (np. `test-webshop_db`).
2. **Zaimportuj** `backend/schema/ws_schema.sql` → tworzy 33 tabele. Zawiera już
   **wszystko** (rabaty, notatki, konta firmowe, opcje płatności, pola gościa…).
   *(Pliki `alter-*.sql` są TYLKO dla bazy, która istniała przed tymi funkcjami. Na świeżej bazie pomiń je.)*
3. **Zaimportuj** `backend/schema/seed-shops.sql` → 5 prawdziwych sklepów (Halle=4, Corbais=2, Gosselies=3, Sombreffe=5, Gembloux=10).
4. ⚠️ `portion` to zastrzeżone słowo w MariaDB i jest już ujęte w backticki w schemacie — zostaw to.

## 2. Backend — PHP API ⏱️ ~10 min

1. **Wgraj** cały folder **`php-api/`** na hosting, np. `public_html/api/`.
2. **Edytuj `php-api/config.php`** wpisując prawdziwe wartości:
   ```php
   'db' => [
     'host' => 'localhost',            // MySQL jest lokalny na hostingu
     'port' => '3306',
     'name' => 'test-webshop_db',
     'user' => 'test_webshop_user',
     'pass' => '••••••',               // prawdziwe hasło do bazy
   ],
   'auth_secret'  => '••• długi losowy •••',   // podpisuje tokeny sesji klienta
   'admin_token'  => '••• długi losowy •••',   // chroni panel administracyjny
   'cors_origins' => ['https://samsam2703mfc.github.io'],  // origin frontendu — WYMAGANE
   'stripe_secret'=> '',               // sk_live_… później (Krok 5)
   'mail_from'    => 'no-reply@atelierby.be',
   ```
3. **Wymagania na hoście:**
   - **HTTPS** na domenie API (obowiązkowe — frontend jest na HTTPS, przeglądarki blokują mieszaną treść).
   - Włączony **`mod_rewrite`** (dołączony `.htaccess` kieruje wszystkie żądania do `index.php`).
   - Nagłówek `Authorization` musi docierać do PHP (obsługiwane przez `.htaccess`; niektóre hosty wymagają też `CGIPassAuth On` lub PHP jako FastCGI).
4. **Test:** otwórz `https://<domena-api>/api/shops` w przeglądarce → musi zwrócić sklepy jako JSON.

## 3. Podłączenie frontendu do backendu ⏱️ ~2 min

Są dwa sposoby uruchomienia frontendu. **Opcja A (zalecana)** umieszcza wszystko na
hostingu klienta przez FTP — ten sam origin, brak CORS, nic do edycji.

### Opcja A — wszystko na hostingu klienta (auto-deploy FTP) ✅
Repo zawiera workflow `.github/workflows/deploy-ftp.yml`, który przy każdym pushu do
`main` buduje PWA i wgrywa **frontend do web root** oraz **`php-api/` do `/api`** przez
**FTPS**. Ponieważ mają ten sam origin, `api-config.js` automatycznie wykrywa API pod
`/api` — **brak `BASE_URL` do edycji, brak CORS**.

Jednorazowa konfiguracja w **GitHub → Settings → Secrets and variables → Actions**:
| Rodzaj | Nazwa | Wartość |
|---|---|---|
| Secret | `FTP_SERVER` | np. `ftp.atelierby.online` |
| Secret | `FTP_USERNAME` | użytkownik konta FTP |
| Secret | `FTP_PASSWORD` | hasło konta FTP |
| Variable | `FTP_ENABLED` | `true` (włącza workflow — do tego czasu jest uśpiony) |
| Variable *(opc.)* | `FTP_WEB_DIR` | katalog web root (domyślnie `./`) |
| Variable *(opc.)* | `FTP_API_DIR` | katalog API (domyślnie `./api/`) |
| Variable *(opc.)* | `FTP_WEB_BASE` | ścieżka bazowa jeśli w podfolderze (domyślnie `/`) |

Następnie push do `main` (lub uruchom workflow ręcznie) → strona działa na domenie
klienta. **`config.php` na serwerze nigdy nie jest nadpisywany ani usuwany** przez deploy.
*(Preferuj FTPS; jeśli host oferuje tylko zwykły FTP, zmień `protocol: ftps` → `ftp` w workflow.)*

### Opcja B — frontend na GitHub Pages, API gdzie indziej
Zachowaj deploy Pages i wskaż API na **innym** hoście, wpisując URL na sztywno w
`api-config.js` (zastąp linię auto-wykrywania przez `const BASE_URL = 'https://<domena-api>/api';`),
potem push do `main`. W tym przypadku ustaw `cors_origins` w `config.php` dokładnie na
`https://samsam2703mfc.github.io`.

> Na GitHub Pages aplikacja zawsze działa w **trybie demo** (auto-wykrywane) — przydatne jako podgląd/staging.

## 4. Wypełnienie danych (panel administracyjny lub phpMyAdmin) ⏱️ na bieżąco

Po podłączeniu wypełnij dane franczyzy. Dwa sposoby: **panel administracyjny** pod
`https://<domena-api>/api/admin/` (wpisz `admin_token`), lub phpMyAdmin.

| Dane | Tabela / miejsce |
|---|---|
| Produkty, cena per sklep, dzienny stan magazynowy | panel admin, lub `ws_products` / `ws_product_prices` / `ws_product_stock` |
| Kategorie, zestawy/menu | `ws_categories`, `ws_bundles` |
| Przedziały czasowe, deadline kalendarza, dni otwarcia | `ws_slots`, `ws_calendar_rules`, `ws_shop_availability` |
| Czas realizacji i deadline per produkt / per tryb | `ws_product_availability` (`*_lead_time`, `*_cutoff_override`, `*_enabled`) |
| B2B: biura, trasy, punkty dostaw, opłaty | `ws_offices`, `ws_tours`, `ws_office_delivery_sites`, `ws_tour_availability`, `ws_delivery_fee_rules` |
| B2B: płatność odroczona + e-maile firmowe | zakładka "Entreprises" w panelu → `ws_offices.deferred_billing_enabled`, `ws_office_emails` |
| **Metody płatności per sklep × profil** | panel admin → `ws_shop_payment_options` (profil guest/registered/company × metoda stripe/shop/deferred) |
| Promocja cross-portion, rabat sklepu, kupony | `ws_pricing_rules`, `ws_shops.webshop_discount_*`, `ws_vouchers` |

**Masowy import katalogu:** przygotuj skrypt INSERT dla `ws_products` / `ws_product_prices` /
`ws_product_stock` (jeden wiersz na produkt × sklep) i zaimportuj go w phpMyAdmin.
Referencyjny SQL dla każdej tabeli i przypadku: `backend/schema/api-queries.sql`.

## 5. Płatności i dodatki (gdy gotowe)

- **Stripe:** wpisz `sk_live_…` w `config.php` (`stripe_secret`). Dodaj webhook w Stripe →
  URL `https://<domena-api>/api/payments/webhook`, zdarzenie `checkout.session.completed`. Płatność kartą wtedy działa.
- **E-maile z zamówieniami:** ustaw `mail_from` w `config.php` (wysyłane przez PHP `mail()` przy każdym zamówieniu).
- **Cron:** niepotrzebny. PHP API rezerwuje stan magazynowy w ramach transakcji zamówienia
  (`SELECT … FOR UPDATE` na `ws_product_stock`), więc nie ma zadania w tle do zaplanowania.

## 6. Lista kontrolna weryfikacji ✅

- [ ] `https://<domena-api>/api/shops` zwraca JSON (5 sklepów)
- [ ] `https://<domena-api>/api/catalog/products?shopId=2` zwraca produkty
- [ ] Panel admin otwiera się pod `/api/admin/` z `admin_token`
- [ ] Frontend (po `BASE_URL` + redeploy) pokazuje prawdziwe sklepy i katalog
- [ ] Złóż testowe zamówienie → wiersz pojawia się w `ws_orders` (+ linia w `ws_order_lines`)
- [ ] Zakup jako gość działa; lista metod płatności zgadza się z konfiguracją sklep×profil
- [ ] (mobilnie) strona instaluje się na ekranie głównym i otwiera na pełnym ekranie

## 7. Kluczowe fakty i referencje

- **Redeploy frontendu** = push do `main` (Actions buduje `dist/` → Pages). Aby zmienić URL API, edytuj `api-config.js`, push.
- **Lokalne uruchomienie frontendu:** `npm install && npm run dev` (Vite).
- **Endpointy API:** `/shops /brand /catalog/* /catalog/stock /availability/* /calendar/* /pricing/promos/* /vouchers/redeem /payment-methods /tours /offices /delivery-fees/quote /companies /orders /auth/* /payments/* /admin/*`.
- **Bezpieczeństwo:** sekrety żyją tylko w `config.php` na serwerze, nigdy w Git. `.env`/`config.php` są w `.gitignore`. 🔴 Zmień hasło bazy, jeśli było udostępnione otwartym tekstem.
- **Pełne przykłady SQL pole-po-polu:** `backend/schema/api-queries.sql`.

---

**Minimum do działania:** Kroki 1 → 2 → 3. Następnie wypełnij dane (Krok 4) i włącz Stripe (Krok 5).
Pytania o dowolny endpoint lub tabelę: kod jest w `php-api/index.php`, a SQL w `backend/schema/`.
