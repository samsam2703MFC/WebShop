# Dane potrzebne do zakończenia wdrożenia polityk prywatności i cookies — uzupełnione z kodu

Stan na 28.10.2026. Odpowiedzi pochodzą z trzech repozytoriów: **WebShop**, **landing** i **consultant_BO** (Cockpit), z ich kodu, seedów SQL i dokumentacji.

**Zasady wypełnienia:**
- Kod pokazuje, jak system działa technicznie. Nie potwierdza umów, właścicieli kont ani decyzji organizacyjnych.
- Gdzie kod nic nie mówi, wpisano „**do ustalenia**”.
- Gdzie kod nie przewiduje żadnej reguły, wpisano „**brak ustalonych zasad**”.
- W nawiasach podano źródło w formacie `repo/plik:linia`.
- Dwie informacje o hostingu pochodzą z publicznego rejestru adresów IP (ipinfo.io), nie z kodu. Są tak oznaczone.

---

## 1. Sprzedawcy — franczyzobiorcy

Dane sklepów są w jednym miejscu: `WebShop/backend/schema/seed-shops.sql:9-23` (tabela `ws_shops`). Komentarz w pliku: „Tes 5 boutiques réelles (Franchise Buddy)”.

**Identyfikatory:**
- Identyfikator sklepu w aplikacji to jego id w ERP Franchise Buddy, równe `ws_shops.id`.
- Landing numeruje sklepy inaczej: `lp_od_shops.sid` (`landing/lp_od_install.php:364-370`).

### Halle
- Nazwa sklepu: Atelier by - Halle
- Identyfikator sklepu w aplikacji: **4** (slug `halle`); na landingu sid 1
- Pełna nazwa firmy sprzedawcy: **Neutralles SRL**.
  - consultant_BO pisze „Neutralle SRL” (bez „s”), z przedstawicielem „Nathan Charlot” (`consultant_BO/sql/seed.sql:733`).
  - Poprawną pisownię trzeba potwierdzić.
- Adres siedziby: **do ustalenia**.
  - W bazie jest tylko adres sklepu: Auguste Demaeghtlaan 227, 1500 Halle.
- Numer przedsiębiorstwa BCE/KBO: brak w bazie.
  - Z numeru VAT wynika **1024.074.728** (w Belgii numer VAT to „BE” + numer przedsiębiorstwa).
  - Do potwierdzenia w KBO Public Search.
- Numer VAT: **BE1024074728**
- E-mail kontaktowy: halle@atelierby.be. Landing podaje inną domenę: halle@**l**atelierby.be.
- Telefon: 0472.24.49.58. Czy udostępniać go klientom: **do ustalenia**.

### Corbais
- Nazwa sklepu: Atelier by Berlo - Corbais
- Identyfikator: **2** (slug `corbais`); na landingu sid 2
- Pełna nazwa firmy: **Berdiff s.a.** Tę samą nazwę ma rekord `franchisee_shop` w ERP (`consultant_BO/public/api/marketing/src/Repository/ErpSyncRepository.php:64-65`).
- Adres siedziby: **do ustalenia**. Adres sklepu: Grand Route 20, 1435 Corbais.
- BCE/KBO: z numeru VAT wynika **0451.766.810** — do potwierdzenia.
- Numer VAT: **BE0451.766.810**
- E-mail: corbais@atelierby.be (landing: corbais@latelierby.be)
- Telefon: 010.65.83.83. Udostępnianie klientom: **do ustalenia**.

### Gosselies
- Nazwa sklepu: Atelier by Max & Sandra - Gosselies
- Identyfikator: **3** (slug `gosselies`); na landingu sid 3
- Pełna nazwa firmy: w bazie „**Max & Sandra De Cnop**”.
  - To imiona i nazwisko, nie firma.
  - **Do ustalenia:** forma prawna i właściwa nazwa.
- Adres siedziby: **do ustalenia**. Adres sklepu: Rue du Pircha 202, 6041 Gosselies.
- BCE/KBO: z numeru VAT wynika **0794.645.378** — do potwierdzenia.
- Numer VAT: **BE0794.645.378**
- E-mail: gosselies@atelierby.be (landing: gosselies@latelierby.be)
- Telefon: 071.218.001. Udostępnianie klientom: **do ustalenia**.

### Sombreffe
- Nazwa sklepu: Atelier by Harmonie - Sombreffe
- Identyfikator: **5** (slug `sombreffe`); na landingu sid 4
- Pełna nazwa firmy: **Antalramo s.r.l.**
- Adres siedziby: **do ustalenia**. Adres sklepu: Chaussée de Nivelles 136/D, 5140 Sombreffe.
- BCE/KBO: z numeru VAT wynika **1011.185.606** — do potwierdzenia.
- Numer VAT: **BE1011.185.606**
- E-mail: sombreffe@atelierby.be (landing: sombreffe@latelierby.be)
- Telefon: 071.195.862. Udostępnianie klientom: **do ustalenia**.

### Gembloux — w bazie nieaktywny (`active = FALSE`)
- Nazwa sklepu: Atelier by Berlo - Gembloux
- Identyfikator: **10** (slug `gembloux`); na landingu sid 5
- Pełna nazwa firmy: w bazie „**Pascal Wauters**”. **Do ustalenia:** firma i forma prawna.
- Adres siedziby: **do ustalenia**. W bazie jest tylko miasto.
- BCE/KBO, numer VAT: **do ustalenia** (w bazie puste).
- E-mail: gembloux@atelierby.be
- Telefon: 0725038049. Uwaga: ten sam numer występuje w teście jako polski numer +48 (`WebShop/php-api/tests/tel_fiche_test.php:7`).

### Wavre
- Wavre istnieje tylko na landingu: sid 6, wavre@latelierby.be.
- Z webshopu usunięto go decyzją (`WebShop/backend/schema/migrate-unify-shops.sql:131-132`).
- **Do ustalenia:** czy Wavre w ogóle sprzedaje online.

### Źródło tych danych w API lub panelu
- **Kolumny w bazie:** `ws_shops` / `shops` mają `legal_name`, `vat`, adres sklepu, e-mail i telefon (`WebShop/backend/schema/ws_schema.sql:46-67`).
  - **Brak** kolumn: numer BCE, forma prawna, adres siedziby.
- **API i panele:**
  - Żaden endpoint API ani ekran panelu nie wyświetla ani nie edytuje `legal_name` ani `vat`.
  - `POST /franchisee/shop-update` zmienia tylko nazwę, adres sklepu, lokalizację i język.
- **ERP:** pełne dane prawne sklepu są w ERP Franchise Buddy:
  - tabela `franchisee_shop`: `name` = nazwa prawna, `representative_name`;
  - `GET /shops/{id}` zwraca `tax_number` i dane bankowe.
  - Repozytoria nie zawierają tych wartości.
- **Seed:** `ON DUPLICATE KEY UPDATE` nie odświeża `vat`, ulicy ani kodu pocztowego (`seed-shops.sql:24-26`). Wartości w bazie produkcyjnej mogą więc różnić się od pliku — **sprawdzić w bazie**.
- **Jak dziś przedstawia się sprzedawca:**
  - E-mail z potwierdzeniem zamówienia jest podpisany tylko „L'Atelier By”; nadawca no-reply@atelierby.be (`WebShop/php-api/lib.php:108-116`).
  - Zgoda przy rejestracji: „…la politique de confidentialité de L'Atelier By” (`WebShop/public/inscription.html:179`).
  - Faktury wystawia ERP. Komunikat dla klienta brzmi: „Votre facture sera émise par la boutique en début de mois prochain” (`WebShop/php-api/index.php:4603`).
  - Żaden e-mail ani dokument webshopu nie podaje nazwy prawnej ani numeru VAT franczyzobiorcy.

### Dodatkowo: franczyzodawca (L'Atelier By / ASIMA)
- **Tabela `lp_legal` na landingu** (źródło stopki prawnej i strony `mentions-legales.html`) zawiera tylko przykłady (`landing/lp_install_legal.php:46-57`):
  - nazwa i forma: „L'Atelier By”, „SRL”;
  - „Adresse à compléter”, BCE „0XXX.XXX.XXX”, VAT „BE 0XXX.XXX.XXX”;
  - „Hébergeur à compléter”;
  - privacy@atelierby.be;
  - retencja „3 ans”.
  - **To nie są ustalone wartości.**
- **ASIMA** występuje w kodzie tylko dwa razy:
  - jako właściciel Meta Business Managera (`consultant_BO/docs/brand-guard.md:43`);
  - w danych testowych.
- **Do ustalenia:**
  - pełna nazwa, adres siedziby, BCE i VAT spółki ASIMA;
  - relacja między ASIMA a marką „L'Atelier By”.

---

## 2. Wykorzystanie danych przez ASIMA i franczyzobiorców — faktyczne działanie w kodzie

**Kto zarządza kontami klientów?**
- **Technicznie:** konto główne jest w ERP **TFBuddy / Franchise Buddy** (`atelierby.tfbuddy.com/api/v1`). Przez API ERP idą:
  - rejestracja i logowanie (e-mail lub telefon + hasło);
  - zmiana i reset hasła (e-mail resetu wysyła ERP);
  - edycja profilu.
- Webshop trzyma lokalną kopię w tabeli `client`, bez hasła (`WebShop/php-api/index.php:3873-3953, 4121-4196`).
- Połączenie konta webshopu z kartą klienta założoną w sklepie nie jest automatyczne. Klient składa prośbę, decyduje franczyzobiorca (`index.php:10973-11019`).
- **Do ustalenia:**
  - kto organizacyjnie zarządza kontami (ASIMA czy franczyzobiorcy);
  - kto jest stroną umowy z TFBuddy.

**Czy jedno konto klienta działa we wszystkich sklepach?**
- **Tak.** Logowanie nie wymaga wyboru sklepu.
- Konto ma dwa pola sklepu:
  - `id_main_shop` — sklep administracyjny w ERP;
  - `preferred_shop_id` — sklep wybrany przez klienta.
- Zamówienie trafia do sklepu wybranego przy zakupie.
- Historia zakupów pokazuje zamówienia i paragony ze wszystkich sklepów.
- To samo konto działa w aplikacji PWA, przez logowanie przekazywane między aplikacjami.

**Czy franczyzobiorca widzi wyłącznie klientów i zamówienia swojego sklepu?**
- **Częściowo** — zależy od sposobu wejścia do panelu:
  - Sesja PIN tabletu sklepu i token urządzenia kuchni: tylko własny sklep (`index.php:7061-7147`).
  - Panel franczyzobiorcy otwierany **tokenem administratora**: bez parametru `?shop=` pokazuje całą sieć (`index.php:7057, 7120-7147`). Tak jest dziś w praktyce, według `WebShop/GO_LIVE_CHECKLIST.md:168`.
  - Nowy panel `/bo/franchisee/*` ogranicza dostęp do sklepów przypisanych użytkownikowi (`bo_user_shops`).
- **Wyjątki także w ramach jednego sklepu:**
  - statystyki zamówień klienta liczone ze wszystkich sklepów;
  - biura bez przypisanej trasy widoczne dla każdego sklepu;
  - wspólne szablony CRM;
  - bony i reklamacje przyjmują dowolnego klienta, bez sprawdzenia, czy należy do sklepu.
- Żaden test nie sprawdza izolacji danych między sklepami (`WebShop/AUDIT_Scoping-et-acces.md:138`).
- **Do ustalenia:** docelowy zakres widoczności (decyzja organizacyjna). Potem poprawki techniczne.

**Kto w ASIMA ma dostęp do danych klientów i zamówień?**

Kod opisuje role i klucze, nie osoby:
- **Jeden wspólny token administratora** otwiera `/franchisor/*`, `/admin/*` i `/franchisee/*`. Daje to:
  - wyszukiwanie klientów w całej sieci (imię, e-mail, telefon);
  - mapę klientów z kodem pocztowym i obrotem;
  - prospekty B2B;
  - wszystkie zamówienia sieci.
- **Nowy panel `/bo`:** role `siege` (cała sieć) i `franchise` (przypisane sklepy).
- **Cockpit (consultant_BO):**
  - logowanie jest domyślnie **wyłączone** — dostęp zakłada przekierowanie z ERP (`consultant_BO/src/auth.php:19-24`);
  - moduł marketingu działa zawsze z uprawnieniami marki (`BRAND_ADMIN`).
- **Konta techniczne ERP** (konsultant, admin) pobierają pełną listę klientów ERP. Webshop trzyma ją w pliku tymczasowym na serwerze: około 14,5 MB, odświeżana co 15 minut (`WebShop/php-api/erp_clients.php:70-113`).
- **CMS landingu:** wspólny „kod dnia”.
- **Do ustalenia:** imienna lista osób i zespołów.

**Do jakich celów ASIMA wykorzystuje te dane?**

Cele, które są w kodzie:
- **Analityka sieci:**
  - KPI i obrót według sklepów;
  - analiza geograficzna klientów;
  - skuteczność bonów według sklepów;
  - raport CRM;
  - analiza sprzedaży w Cockpicie.
- **Marketing:**
  - **Newsletter Cockpitu**, wysyłany przez markę i przez franczyzobiorców:
    - segmenty: ostatni zakup, koszyk, produkty, urodziny;
    - automaty: klienci nieaktywni od 45 dni, trzeci zakup, urodziny;
    - śledzenie otwarć i kliknięć;
    - **domyślnie tryb testowy**.
  - **Bony:** kampanie marki, także kierowane do konkretnego klienta.
  - **Reklama i pomiar:** Google Tag Manager, Google Ads, Meta Pixel na landingu; GA4 przygotowane, ale nieaktywne.
- **CRM prospekcji B2B:** e-mail, SMS i telefon do firm i biur.
- **Lojalność:**
  - karty i pieczątki we wspólnych tabelach PWA;
  - promocje za skumulowane zakupy.
- **Obsługa klienta:**
  - reklamacje;
  - prośby o połączenie konta z kartą klienta;
  - odpowiedzi na opinie Google — szkice generuje Anthropic.
- **Akcje i gry:**
  - „La Fève d'Or”:
    - telefon lub e-mail gracza;
    - osobne, wersjonowane zgody (serwisowa i marketingowa);
    - polecenia z podwójnym potwierdzeniem;
    - paragon weryfikowany w ERP.
  - „Galette des Rois”:
    - zdjęcia klientów z imieniem i sklepem, za zgodą, moderowane przed publikacją;
    - e-mail z bonem do znajomego.
- **Leady:**
  - kandydaci na franczyzę (zapisywane z adresem IP i przeglądarką);
  - biura zapisywane od razu do tabeli klientów `client`;
  - formularze B2B webshopu.

**Czy ASIMA prowadzi wspólne analizy, marketing lub obsługę klienta?**
- Technicznie **tak** — patrz wyżej.
- Kontener Google Tag Manager prowadzi **zewnętrzna agencja** (`WebShop/GA4.md:37-50`).
  - Nazwa agencji: **do ustalenia**.
  - Zawartość kontenera nie jest w repozytorium.

**Kto decyduje o celach i zakresie analityki?** — **do ustalenia**

**Czy istnieją umowy dotyczące przetwarzania danych między ASIMA a franczyzobiorcami?**
- **Do ustalenia.**
- W repozytoriach nie ma żadnej umowy ani odniesienia do takiej umowy.

**Jeśli tak, gdzie można je znaleźć lub kto je posiada?** — **do ustalenia**

---

## 3. Faktyczni dostawcy usług

### Hosting, baza danych i kopie zapasowe
- **Dostawca hostingu:** wg publicznego rejestru IP (nie z kodu) serwer **185.180.206.46** to VPS **cyber_Folks S.A.**, nazwa hosta `vpshd1175.cyber-folks.pl`, Poznań, Polska.
  - Do potwierdzenia umową.
  - W kodzie jest tylko adres IP.
  - Strona `mentions-legales.html` ma w tym miejscu placeholder.
- **Co działa na tym serwerze** (jeden serwer Ubuntu, Apache, PHP 8, MySQL):
  - webshop, landing i Cockpit;
  - panel konsultanta;
  - aplikacje TFBuddy;
  - poczta Roundcube.
- **Domeny:** atelierby.be, latelierby.be, webshop.atelierby.be, atelierby.online.
- **Podmiot będący stroną umowy:** **do ustalenia**.
- **Baza danych:** MySQL na tym samym serwerze, jedna baza `atelierby_db`. Zawiera:
  - tabele webshopu (`ws_`), landingu (`lp_`) i Cockpitu (`ceo_`, `mar_`);
  - kopię tabel ERP, w tym klientów (`client`).
- **Kopie zapasowe:** **brak automatycznych kopii** w kodzie. Istnieją tylko:
  - ręczny eksport phpMyAdmin na prywatny dysk (`WebShop/RUNBOOK-shops-unify.md:13-17`);
  - doraźne zrzuty `.sql` w `/root` na serwerze, kompresowane po 30 dniach i **nigdy nieusuwane** (`landing/.github/workflows/nettoyage-disque.yml`).
  - **Do ustalenia:** czy cyber_Folks robi kopie w ramach usługi.
- **Państwa lub regiony przechowywania:** Polska według rejestru IP. Do potwierdzenia u dostawcy.
- **Państwa, z których dostawca może mieć dostęp:** **do ustalenia**.
- **Umowa, warunki, dokumentacja ochrony danych:** **do ustalenia**.
- **Inni dostawcy infrastruktury:**
  - **ERP TFBuddy / Franchise Buddy:**
    - osobny serwer `atelierby.tfbuddy.com`, IP 164.132.85.61;
    - wg rejestru IP: **OVH SAS, Lille, Francja**;
    - w praktyce największy odbiorca danych klientów: konta, hasła, profile, numery VAT;
    - **brak w ankiecie — trzeba go dopisać**.
  - **GitHub:**
    - repozytoria kodu;
    - wdrożenia przez GitHub Actions (SSH);
    - GitHub Pages wciąż publikuje demo webshopu i stronę B2B.
  - **Cloudflare R2:** zdjęcia produktów z ERP; bez danych klientów, ale z adresem IP odwiedzającego.

### Wysyłka e-maili
- **Nazwa dostawcy** — zależy od aplikacji:
  - **Webshop:** funkcja PHP `mail()`, czyli serwer pocztowy własnego VPS-a. Brak zewnętrznego dostawcy. Nadawca: no-reply@atelierby.be.
  - **Landing:** **Brevo** (API transakcyjne), awaryjnie SMTP, potem `mail()` (`landing/ERP-INTEGRATION.md:194-201`).
  - **Cockpit:** własny klient SMTP. Serwer SMTP ustawiany w bazie (`ceo_app_setting.smtp` lub `crm_settings`). Dostawca: **do ustalenia**.
  - **ERP TFBuddy:** wysyła e-mail resetu hasła klienta.
- **Podmiot będący stroną umowy:** **do ustalenia** (konto Brevo, konto SMTP Cockpitu).
- **Rodzaje wysyłanych wiadomości:**
  - **Webshop:**
    - potwierdzenie zamówienia;
    - powiadomienia wewnętrzne: prośba o strefę dostawy, zapytanie B2B, nowe biuro;
    - zaproszenia dla biur z załącznikiem PDF;
    - e-maile CRM do prospektów B2B.
  - **Landing:**
    - zgłoszenie kandydata na franczyzę (powiadomienie wewnętrzne i potwierdzenie dla kandydata);
    - bon Galette do znajomego;
    - e-mail ze zdjęciem „korony”;
    - bon zwycięzcy Fève d'Or;
    - e-maile poleceń.
  - **Cockpit:**
    - newsletter do klientów (domyślnie tryb testowy);
    - raporty wewnętrzne;
    - przypomnienia o wizytach;
    - alerty Brand Guard;
    - briefy dla agencji;
    - zamówienia centrali zakupowej.
- **Znany region przetwarzania:** **do ustalenia** (Brevo, SMTP Cockpitu).
- **Umowa lub dokumentacja:** **do ustalenia**.

### Wysyłka SMS
- **Nazwa faktycznie używanego dostawcy:** **SMSAPI** (`api.smsapi.pl`, `WebShop/php-api/sms.php`).
  - Według komentarza w kodzie konto jest polskie (`sms.php:79-80`).
  - To jedyny dostawca SMS. Cockpit i landing nie wysyłają SMS-ów.
- **Podmiot będący stroną umowy:** **do ustalenia** — kto jest właścicielem konta.
- **Do czego wykorzystywane są SMS-y?**
  1. **Kod jednorazowy** przy ustawianiu hasła lub gdy klient go zapomniał. Kod generuje i przechowuje SMSAPI, ważny 180 sekund.
  2. **SMS-y z CRM** do prospektów B2B: dowolna treść albo szablon.
  - SMS-ów o zamówieniach **nie ma**.
  - Czy token SMSAPI jest ustawiony na produkcji: **do ustalenia**.
- **Znany region przetwarzania:** **do ustalenia** (do sprawdzenia w dokumentacji SMSAPI).
- **Umowa lub dokumentacja:** **do ustalenia**.

### Stripe
- **Właściciel konta Stripe:** **do ustalenia**.
  - Kod ma jeden globalny klucz `stripe_secret` w `WebShop/php-api/config.php`.
  - To jedno konto dla wszystkich sklepów.
- **Podmiot będący stroną umowy:** **do ustalenia**.
- **Czy używany jest Stripe Connect?** **Nie.** W kodzie nie ma `stripe_account`, `application_fee` ani `transfer_data`.
- **Czy franczyzobiorcy mają osobne konta połączone?** **Nie** (w kodzie). Płatności wszystkich sklepów trafiają na jedno konto.
- **Czy klientom udostępniono Stripe Link?** **Do sprawdzenia w panelu Stripe.**
  - Kod nie wybiera metod płatności (nie przekazuje `payment_method_types`).
  - O dostępnych metodach, także o Link, decydują ustawienia panelu.
- **Jakie inne metody lub funkcje płatności są włączone?**
  - **Integracja:** Stripe Checkout (przekierowanie na stronę Stripe) oraz webhook `/payments/stripe-webhook`.
  - **Etykieta w interfejsie:** „Carte / Bancontact”.
  - Faktycznie włączone metody: **do sprawdzenia w panelu**.
  - **Poza Stripe webshop obsługuje:**
    - płatność w sklepie;
    - zakup na rachunek B2B (faktura miesięczna wystawiana przez sklep);
    - płatność odroczoną.
  - **Przechowywanie zdarzeń:** webshop zapisuje treść zdarzeń Stripe w tabeli `ws_stripe_event`:
    - do 60 000 znaków;
    - mogą zawierać dane klienta;
    - bez terminu usunięcia.
- **Kto ma dostęp do panelu i ustawień?** **Do ustalenia.**
- **Umowa lub dokumentacja:** **do ustalenia**.

### Pozostałe usługi
- **Czy faktycznie używane są Google Maps?** **Tak**, zawsze po stronie serwera (klucz nie trafia do przeglądarki, `WebShop/php-api/geo_google.php`). Zastosowania:
  - autouzupełnianie adresu (Places) na tekście wpisywanym przez odwiedzającego w formularzach B2B i w panelu franczyzobiorcy;
  - geokodowanie adresów biur, miejsc dostawy i klientów B2B;
  - trasy dostaw (Directions, Routes);
  - opinie Google sklepów (Places Details oraz Google Business Profile przez OAuth);
  - w Cockpicie Google Places do reputacji i analizy konkurencji.
  - Czy klucz jest ustawiony na produkcji: **do ustalenia**.
- **Czy faktycznie używana jest zewnętrzna weryfikacja VAT?** **Tak**, po stronie serwera.
  - Numer VAT idzie najpierw do ERP, potem do **VIES** Komisji Europejskiej (`ec.europa.eu`) (`WebShop/php-api/index.php:15552-15575`).
  - Z VIES korzysta też panel franczyzobiorcy.
- **Czy działają inne narzędzia otrzymujące dane klientów lub odwiedzających?** **Tak.**
- **Jeśli tak, jakie i do czego służą?**
  - **Google Tag Manager `GTM-53D3FLG5`:**
    - webshop: z Consent Mode v2, przed wyborem wszystko odrzucone;
    - landing: **bez zgody** — patrz uwaga 1.
  - **Google Ads `AW-18385863943`:** w kontenerze GTM; konwersja zakupu (numer zamówienia, kwota).
  - **GA4:**
    - przygotowane: ładuje się tylko po zgodzie i po wpisaniu identyfikatora;
    - dziś identyfikatora nie ma, więc GA4 nie działa.
  - **Meta Pixel `1695954591629421`:**
    - na wszystkich stronach landingu i na stronie B2B (`evenements-b2b.html`);
    - ładowany **bez zgody**;
    - na webshopie go nie ma.
  - **ERP TFBuddy / Franchise Buddy:** konta klientów — patrz „Hosting”.
  - **Anthropic (API Claude):**
    - webshop wysyła imię autora i treść opinii Google, aby wygenerować szkic odpowiedzi (`WebShop/php-api/index.php:11545-11590`);
    - Cockpit wysyła zdjęcia lady sklepu oraz posty z Facebooka franczyzobiorców.
  - **Meta Graph API:** Cockpit tylko czyta strony Facebook franczyzobiorców (Brand Guard). Nie wysyła danych klientów.
  - **OpenStreetMap:**
    - Nominatim — adresy sklepów, po stronie serwera;
    - mapy, Overpass i Valhalla w przeglądarce Cockpitu — dla personelu.
  - **unpkg.com (CDN):**
    - React i Babel na stronie B2B, więc adres IP odwiedzającego trafia do CDN;
    - produkcyjny webshop ma React we własnej paczce.
  - **YouTube (nocookie), Vimeo, Google Drive:** osadzenia na landingu, jeśli wstawiono je w CMS.
  - **Cloudflare R2:** zdjęcia produktów.
  - **Web Push przeglądarek:** powiadomienia dla personelu (Cockpit).
  - **Nie znaleziono:** TikTok, Hotjar, Sentry, reCAPTCHA, Microsoft Clarity, Firebase (jako usługi), OpenAI.

---

## 4. Przechowywanie i usuwanie danych

| Rodzaj danych | Okres przechowywania | Od jakiego zdarzenia liczony? | Jak i przez kogo są usuwane? |
| --- | --- | --- | --- |
| Konta i profile klientów | brak ustalonych zasad | — | Nic w kodzie nie usuwa kont: ani w ERP (zarządza nim TFBuddy), ani w lokalnej kopii `client`. Brak funkcji „usuń konto”. |
| Zamówienia | brak ustalonych zasad | — | `ws_orders`, w tym dane zamówień bez konta (imię, e-mail, telefon), nigdy nie są usuwane. |
| Faktury i dokumenty rozliczeniowe | do ustalenia | — | Wystawia je ERP / sklep, poza webshopem. Webshop tylko je wyświetla (`pwa_invoices`). |
| Reklamacje i korespondencja | brak ustalonych zasad | — | Bez usuwania: reklamacje, uwagi klientów z tabletu sklepu (`ceo_tablette_remarque`, z adresem IP), akcje CRM. |
| Wiadomości e-mail i historia wysyłki | brak ustalonych zasad | — | Webshop nie prowadzi dziennika wysyłek. Landing: `lp_mail_log` (adresy odbiorców). Cockpit: `ceo_nl_contact` i `ceo_nl_envoi` (otwarcia, kliknięcia). Wszystko bez terminu. Wypis z newslettera tylko oznacza kontakt, nie usuwa go. Historia w Brevo: według ustawień konta. |
| Wiadomości SMS i historia wysyłki | brak ustalonych zasad | — | Kod jednorazowy: 180 s u SMSAPI. SMS z CRM: treść i maskowany numer w `ws_crm_actions`, bez terminu. Historia u SMSAPI: według ustawień konta. |
| Logi techniczne i bezpieczeństwa | brak ustalonych zasad | — | Patrz lista pod tabelą. |
| Kopie zapasowe | brak ustalonych zasad | — | Brak automatycznych kopii. Doraźne zrzuty `.sql` w `/root` są kompresowane po 30 dniach i nigdy nieusuwane. Ręczne eksporty na prywatny dysk. |

**Logi techniczne i bezpieczeństwa — szczegóły:**
- **Tabele w bazie:**
  - `ws_rate_limit`: pełny adres IP, nigdy nieczyszczona;
  - `bo_audit`, `ws_zone_requests`, `ws_b2b_event_requests`: adresy IP;
  - `lp_candidates`: adres IP i przeglądarka;
  - `ws_stripe_event`: treść zdarzeń Stripe.
- **Logi serwera:**
  - logi Apache zapisują adresy IP;
  - dziennik systemowy ograniczony do 200 MB;
  - archiwa w `/var/log` starsze niż 7 dni są usuwane tylko po ręcznym uruchomieniu workflow „Nettoyage disque”;
  - logi zadań cron Cockpitu nie są rotowane.
- **Plik z listą klientów:** pełna lista klientów ERP w katalogu tymczasowym, nadpisywana, ale nigdy nieusuwana.

**Inne zapisy bez terminu usunięcia:**
- zdjęcia klientów z akcji Galette (`landing/img/uploads/rois/`) — nigdy nieusuwane;
- opinie Google w Cockpicie (`ceo_shop_review`) — gromadzone bez końca;
- gra Fève d'Or — w ustawieniach jest `fd_retention_months = 24`, ale kod tego nie egzekwuje; zaplanowane czyszczenie oznaczono „À faire”.

**Czy działa automatyczne usuwanie danych?**
- **Nie.** Są tylko drobne wyjątki:
  - wygasłe sesje PIN;
  - rezerwacje stanu magazynowego;
  - sesje ERP po wylogowaniu;
  - wpisy `lp_fd_hits` po 2 dniach;
  - niepotwierdzone polecenia po 7 dniach.

**Czy istnieje procedura zamknięcia konta?**
- **Nie** — w webshopie i Cockpicie brak takiej funkcji.
- W grze Fève d'Or są funkcje eksportu i anonimizacji (`landing/fd/customers.php:208-224`), ale nic ich nie wywołuje.

**Kto może potwierdzić wymagane okresy księgowe i podatkowe?** — **do ustalenia** (księgowy / biuro rachunkowe).

---

## 5. Obsługa spraw dotyczących prywatności

- **Osoba lub zespół odpowiedzialny za skrzynkę:** **do ustalenia**.
  - Uwaga: adres **webshop@atelierby.be nie występuje nigdzie w kodzie**.
  - Strony i domyślne dane używają innych adresów:
    - `privacy@atelierby.be` (pole „DPO” w `lp_legal`, stopka prawna);
    - `contact@atelierby.be`;
    - `aide@latelierby.be` (zaproszenia dla biur);
    - `no-reply@atelierby.be` (nadawca e-maili z zamówieniami).
  - Wymieszane są dwie domeny: atelierby.be i latelierby.be.
  - Trzeba wpisać webshop@atelierby.be w `lp_legal` i w politykach.
- **Kto przekazuje zgłoszenia właściwemu franczyzobiorcy?** — **do ustalenia**
- **Kto realizuje dostęp, eksport, poprawienie lub usunięcie danych?** — **do ustalenia**.
  - Klient może sam poprawić swój profil (zmiana idzie do ERP).
  - Eksportu ani usuwania danych nie ma w kodzie.
- **Czy istnieje procedura weryfikacji tożsamości zgłaszającego?** **Do ustalenia.**
  - W kodzie brak takiej procedury.
  - Technicznie istnieje weryfikacja numeru telefonu kodem SMS, dziś używana przy haśle.
- **Czy istnieje procedura pilnowania terminów odpowiedzi?** — **do ustalenia**
- **Czy wyznaczono inspektora ochrony danych (IOD/DPO)?** **Do ustalenia.**
  - Pole „dpo_email” w `lp_legal` to tylko adres kontaktowy, nie wyznaczenie IOD.
- **Jeśli tak, jaki jest jego kontakt służbowy?** — **do ustalenia**
- **Kto przechowuje zatwierdzone wersje polityk i dokumentację mechanizmu zgód?** — **do ustalenia**. Stan w kodzie:
  - **Baner cookies webshopu:** wybór (wersja 1 i data) jest zapisany **tylko w przeglądarce** (`localStorage` `ws.consent`). Serwer nie prowadzi rejestru zgód.
  - **Fève d'Or:** wersjonowane zgody zapisane na serwerze.
  - **Zapisy biur:** akceptacja regulaminu zapisana na serwerze (`WebShop/php-api/index.php:2742-2744`).

---

## Uwagi z przeglądu kodu, które wpływają na treść polityk

1. **Landing nie ma banera zgody.**
   - Google Tag Manager i Meta Pixel ładują się od razu na wszystkich stronach landingu i na stronie B2B (`landing/index.html:5-28` i inne strony).
   - Tymczasem `mentions-legales.html` mówi, że cookies pomiarowe wymagają zgody.
   - Przed publikacją polityk trzeba dodać na landingu ten sam mechanizm zgody co w webshopie.
2. **Webshop nie ma strony ani linku do polityki prywatności i cookies.**
   - Baner cookies nie prowadzi do polityki.
   - Zgoda przy rejestracji (`public/inscription.html:179`) wspomina politykę, ale bez linku.
3. **Polityka musi wymienić odbiorców, których nie ma w ankiecie:**
   - ERP TFBuddy / Franchise Buddy;
   - Anthropic;
   - Meta (Pixel);
   - Google Ads i Google Tag Manager;
   - Google Business Profile;
   - agencję prowadzącą GTM;
   - Cloudflare;
   - GitHub;
   - unpkg.
4. **Wartości w `lp_legal` to przykłady, a nie decyzje:** „3 ans”, „privacy@atelierby.be”, „Hébergeur à compléter”.
5. **Rozbieżności do wyjaśnienia:**
   - pisownia „Neutralles” / „Neutralle”;
   - domeny atelierby.be / latelierby.be;
   - numery VAT w bazie produkcyjnej mogą różnić się od pliku seed.
6. **Po wylogowaniu pełny profil klienta zostaje w przeglądarce.**
   - Klucz `ws.customer` w `localStorage` nie jest czyszczony (`WebShop/webshop-auth-api.jsx:373-379`).
   - Ważne dla komputerów współdzielonych.
7. **Zakres dostępu do danych (sekcja 2) zależy od kilku słabych punktów technicznych:**
   - jeden wspólny token administratora, który można też podać w adresie URL (może więc trafić do logów);
   - Cockpit bez logowania;
   - stałe hasło do bazy wpisane wprost w plikach PHP landingu (np. `landing/lp_lead.php:9-13`);
   - punkty V1 i V2 z `WebShop/AUDIT_Scoping-et-acces.md`, nadal otwarte.

   Polityka nie powinna obiecywać ścisłej izolacji danych między sklepami, dopóki te punkty nie zostaną poprawione.

---

**Co trzeba jeszcze zdobyć poza kodem:**
- dane prawne ASIMA (nazwa, adres siedziby, BCE, VAT);
- adresy siedzib i formy prawne franczyzobiorców;
- umowy: cyber_Folks, TFBuddy, Brevo, SMSAPI, Stripe, Google, Meta, Anthropic;
- właściciele kont u tych dostawców;
- okresy przechowywania danych;
- osoby obsługujące skrzynkę webshop@atelierby.be;
- decyzja w sprawie IOD/DPO.
