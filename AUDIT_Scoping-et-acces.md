# Audit Scoping & accès — WebShop

> **Portée** : tout le repo. **Règle jugée** : *aucun front ne lit la base en direct ; tout passe par l'API PHP, authentifiée et bornée à la boutique/tenant de la session (client bearer / PIN / device / cookie BO) ; l'id fourni dans l'URL ou le corps n'élargit jamais la portée.*
> **Exclus du jugement** : `node_modules/`, `dist/`, `php-api/migrations/`, `backend/schema/` (SQL), fixtures `*.example.sql`, docs `*.md`. Audit **en lecture seule** — aucun fichier existant modifié.
> Méthode : reconnaissance → signaux → recherche exhaustive (4 passes croisées sur `php-api/index.php`, 11 647 lignes + `php-api/bo/` + tout le front) → cartographie inverse → verdict par cas d'usage. Chaque constat sévère a été relu ligne à ligne.

---

## 1. Synthèse

**Compteurs** — 6 cas d'usage **conformes** · 8 **à adapter** · 2 **manquants** · **9 occurrences** de violation brute de la règle.

Le socle est sain : le front ne touche **jamais** la base (tout passe par `window.WSXxx → fetch`), l'auth client est un jeton HMAC vérifié (signature + expiration), les sessions PIN/device/cookie-BO sont **bornées côté serveur** à leur boutique, et l'id d'URL est ignoré au profit de la portée de session dans l'écrasante majorité des routes. Les défauts sont des **trous ponctuels**, pas un vice d'architecture.

**5 chantiers prioritaires**

| P | Chantier | Conséquence si rien n'est fait |
|---|----------|--------------------------------|
| **P1** | `POST /payments/checkout` : lier à `auth_uid()` + vérifier `customer_id` (`index.php:3476`) | N'importe qui, sans compte, énumère les commandes d'autrui, bascule leur `payment_method`/`payment_status` et lit leur contenu via la session Stripe générée. |
| **P2** | `POST /franchisee/onboard-office` : ignorer `body.shop`, forcer `$shopId` (`index.php:9403`) | Un porteur de PIN de la boutique A crée bureau + client B2B + site de livraison rattachés à la boutique B (écriture inter-tenant). |
| **P2** | Identité **invité** des promos : exiger une preuve de possession de l'e-mail (`index.php:1296/1314/1368`) | Connaître l'e-mail d'un invité suffit à lire sa progression fidélité et à réclamer/lire son code cadeau. |
| **P2** | Garde au démarrage contre les secrets `change-me-*` (`config.example.php:13,15`) | Une prod déployée sans variable d'env tourne en silence sur un jeton **public connu** → jetons client forgeables + admin réseau ouvert. |
| **P3** | Durcir CORS (`*` + credentials) et l'anti-abus des POST publics (`index.php:10-20`, `2869`, `784`) | Combinaison CORS dangereuse si `WS_CORS=*` ; création de comptes et gonflage de compteurs sans limite. |

---

## 2. Comment ce repo est organisé (chemins)

- **`php-api/index.php`** (11 647 l.) — LE contrôleur frontal. `dispatch($m,$p)` (dès `:490`) route par méthode+chemin ; c'est la surface d'API et le cœur de l'audit.
- **`php-api/lib.php`** — primitives d'auth : `sign_token`/`verify_token` (HMAC, `:67-79`), `auth_uid()` (`:87`), `require_admin()` (`:94`).
- **`php-api/bo/`** — 2ᵉ back-office (consoles web) : `guard.php` (session cookie signée + CSRF + `bo_scope_shop_ids`), `auth.php` (login), `routes.php` (données), `bootstrap.php` (`bo_dispatch`).
- **`php-api/admin/`, `php-api/cron/`, `php-api/tools/`** — outillage ops (partiellement couvert, cf. §8).
- **Front client (servi navigateur)** : `index.html → src/main.jsx` (build Vite) et `webshop-full.html → webshop-full-bundle.jsx` + `webshop-*-api.jsx` (stubs `window.WSXxx`) + `api-config.js`.
- **Autres fronts** : `admin-app.jsx`/`admin-bundle.jsx` (proto design, sans jeton), `tournee-*.jsx` (proto), `public/admin-reviews.html` (console avis), `public/inscription.html` (auto-inscription).
- **`franchise-buddy/menus.js`** — module **Node/Express + mysql2** (accès DB direct **hors** de l'API PHP) ; non servi au navigateur mais à surveiller (cf. §8).
- **Auth, en 4 identités** : jeton client `Authorization: Bearer` (HMAC) ; `X-Pin-Token` (tablette, bornée boutique+sections) ; `X-Device-Token` (Kitchen, bornée boutique + liste blanche d'écrans) ; `X-Admin-Token` réseau ; cookie signé BO (`/bo/*`).
- **Config** : `php-api/config.php` (secrets réels) et `deploy/deploy.env` sont **git-ignorés** (`.gitignore:5,7`) ; seuls les gabarits `*.example` sont versionnés.

---

## 3. Signaux retenus pour cet audit

Une **violation** de la règle = l'un de ces motifs :

1. **Front → base** : `mysqli`/`new PDO`/`pg_*`/`SELECT … FROM`/identifiants DB dans un fichier `.js/.jsx/.html` servi au navigateur.
2. **Écriture publique non authentifiée** : route `POST/PATCH/DELETE` qui écrit sans `auth_uid()`, sans jeton, sans signature.
3. **Portée élargie par l'entrée** : pour une identité **bornée** (PIN/device), lecture/écriture qui prend la boutique dans `qp('shop')`/`$b['shop']`/`$b['shopId']` au lieu du `$shopId` forcé de session.
4. **Filtre tenant absent** : requête sur une table à `shop_id` sans `$scope(...)`/`shop_id = $shopId` (renvoie tous les tenants).
5. **IDOR client** : lecture/écriture d'un enregistrement possédé par un client via un id d'URL/corps sans comparaison à `auth_uid()`.
6. **Identité auto-déclarée** : identité acceptée depuis l'entrée (ex. `guestEmail`) sans preuve de possession.
7. **Secret faible / divulgué** : secret de signature ou jeton admin avec repli non vide connu, ou config commitée.
8. **CORS trop large** : `Access-Control-Allow-Origin: *` conjugué à `Allow-Credentials: true`.
9. **Divulgation** : message d'erreur brut (schéma, nom de base, ligne) renvoyé au client.

Exclusions explicites : SQL de schéma/migrations, `*.example.*`, `node_modules`, `dist`, docs `.md`.

---

## 4. Tableau principal (un besoin = une ligne)

| # | Module | Cas d'usage / besoin | Constat (fichier:ligne) | Verdict | Ce qu'il faut faire | Effort | Prio | Conséquence si rien |
|---|--------|----------------------|--------------------------|---------|---------------------|--------|------|---------------------|
| 1 | Front | Aucune lecture DB directe | Aucun `PDO/mysqli/SELECT` hors `php-api` ; tout via `webshop-*-api.jsx` (`webshop-catalog-api.jsx:50`, `webshop-orders-api.jsx:32`) | **CONFORME** | — | — | — | — |
| 2 | Auth client | Session = jeton signé, non forgeable | `sign_token`/`verify_token` HMAC-SHA256 + `hash_equals` + `exp` (`lib.php:67-79`) ; `auth_uid()` (`lib.php:87`) | **CONFORME** | — | — | — | — |
| 3 | Données perso client | Lecture/écriture bornée au propriétaire | 15 routes `/auth/*`, `/orders/:id`, `/auth/purchases` comparent à `auth_uid()` (`index.php:2862`, `3228`, `3471`) | **CONFORME** | — | — | — | — |
| 4 | Paiement | `checkout` réservé au propriétaire de la commande | **Aucun `auth_uid`** ; `UPDATE ws_orders … WHERE id=?` sur `body.orderId` (`index.php:3478,3487`) | **À ADAPTER** | Exiger `auth_uid()` et `AND customer_id = $uid` avant lookup/update | S | **P1** | Altération + divulgation de commandes inter-clients (id séquentiel/énumérable) |
| 5 | Console PIN | Portée = boutique de session, jamais l'URL | `onboard-office` : `body.shop` écrase `$shopId` (`index.php:9403`) puis `INSERT … shop_id` (`9428`) | **À ADAPTER** | Retirer la branche `$b['shop']` ; forcer `$obShop=$shopId` pour une session PIN | S | **P2** | Écriture inter-tenant (bureau/client/site rattachés à une autre boutique) |
| 6 | Console PIN | Reste des routes franchisé scopées | `$shopId` forcé (`index.php:4991,4994`), `$scope()` (`4996`), gardes ownership (`5008,5016`) | **CONFORME** | — | — | — | — |
| 7 | Tablette Kitchen | Device token borné + liste blanche | `$DEVICE_ENDPOINTS` (`index.php:4930`) ; chaque écran re-scoppe (`5227,7702,8360`) | **CONFORME** | — | — | — | — |
| 8 | Console web BO | Cookie signé + CSRF + scope boutique | `require_bo` + `bo_csrf` (`bo/guard.php:120-138`) ; `bo_shop_where`/`bo_assert_shop_allowed` (`bo/routes.php:12,58`) | **CONFORME** | — | — | — | — |
| 9 | Admin réseau | `/admin/*` `/franchisor/*` = jeton réseau | `require_admin()` en tête de bloc (`index.php:3597,9671`) ; 36 routes couvertes | **CONFORME** | — | — | — | — |
| 10 | Fidélité invité | Identité invité prouvée | `guestEmail` accepté comme identité sans preuve (`index.php:1301,1320,1375` ; `promo_lib.php:20-25`) | **À ADAPTER** | Preuve de possession (lien e-mail signé) ou retirer l'accès invité aux lectures/claims | M | **P2** | Lecture de progression + vol du code cadeau d'un invité par simple e-mail |
| 11 | Secrets déploiement | Refus si secret non configuré | Repli **non vide** `change-me-*` ; `require_admin` ne 503 que si **vide** (`config.example.php:13,15` ; `lib.php:95`) | **MANQUANT** | Garde au boot : refuser tout si `auth_secret`/`admin_token` ∈ défauts connus | S | **P2** | Prod silencieuse sur jeton public → usurpation totale (client + admin) |
| 12 | Anti-abus public | Limiter les POST publics écrivains | `/auth/register` sans rate-limit (`index.php:2869`) ; `/catalog/cross-sell/stat` sans auth ni limite (`784,797`) | **À ADAPTER** | `rate_limit()` (déjà utilisé en `2999`) sur register ; auth **ou** limite sur stat | S | **P3** | Spam de comptes ; gonflage arbitraire des compteurs d'analytics |
| 13 | CORS | Origines fermées, pas de credentials en `*` | Reflète l'origine **et** `Allow-Credentials: true`, `*` permis si listé (`index.php:10-20`) | **À ADAPTER** | Interdire la conjonction `*` + credentials ; garder l'allowlist explicite | S | **P3** | Si `WS_CORS=*` un jour : requêtes credentgiées cross-origin |
| 14 | Console avis franchisé | Vue avis bornée sans jeton réseau | `public/admin-reviews.html` : jeton **réseau** en `localStorage` + scope par `?shop=` client (`:71,105,67-69`) | **À ADAPTER** | Router la console franchisé sur `/franchisee/reviews` (déjà créé) + jeton non-réseau | M | **P3** | Le filtre `?shop=` n'est pas une frontière : retirer le param = vue réseau |
| 15 | Divulgation erreur | Pas de détail interne au client | `/shops` renvoie `message`+`ligne`+`base` DB en 500 (`index.php:547-556`) | **À ADAPTER** | Logguer le détail, ne renvoyer qu'un message générique | S | **P3** | Fuite du nom de base / schéma / lignes au client |

---

## 5. Violations à supprimer — un bloc par occurrence

### V1 — `POST /payments/checkout` sans auth ni ownership · `index.php:3476-3488` · **P1**
```php
$o = row("SELECT * FROM ws_orders WHERE id=? OR order_ref=? LIMIT 1", [$b['orderId'] ?? 0, $b['orderId'] ?? '']);
...
q("UPDATE ws_orders SET payment_method='stripe', payment_status='pending' WHERE id=?", [$o['id']]);
```
Aucun `auth_uid()`. L'`id` est auto-incrément séquentiel ; `order_ref = 'WS-'.time().rand(10,99)` (`index.php:2568`) — les deux sont énumérables. **Conséquence nommée** : un appelant anonyme bascule le `payment_method`/`payment_status` de **n'importe quelle** commande et récupère le contenu (lignes, total) de la commande d'autrui via la session Stripe renvoyée. *Limite* : le webhook Stripe est signé (`index.php:3510`), donc pas de passage à `paid` frauduleux — d'où P1 et non P0.

### V2 — `POST /franchisee/onboard-office` : `body.shop` élargit la portée · `index.php:9403,9428` · **P2**
```php
$obShop = (isset($b['shop']) && $b['shop'] !== '') ? (int) $b['shop'] : (zip_shop($obZip) ?? $shopId);
q("INSERT INTO ws_offices (… shop_id) VALUES (… $obShop)");
```
Route mappée à la section `offices` (`index.php:11344`) → **atteignable par une session PIN**. Le `$shopId` forcé n'est plus que l'ultime repli. Aussi écrit dans `client.id_main_shop`/`preferred_shop_id` (`9458-9459`) et `ws_office_delivery_sites.shop_id` (`9483-9484`). **Conséquence** : un PIN de la boutique A rattache des entités à la boutique B. *Limite* : l'objet naît `active=0`/non validé → P2.

### V3 — Identité invité auto-déclarée (promos) · `index.php:1296,1314,1368` · **P2**
`promo_customer_ref()` (`promo_lib.php:20-25`) accepte `guest:<email>` sans aucune preuve. En invité :
- `/promo/:id/progress` (`1301,1307`) lit la progression + le `voucher_code` d'un e-mail arbitraire ;
- `/promo/:id/claim` (`1320,1349-1352`) crée/verrouille et **renvoie** le code cadeau de cet e-mail ;
- `/promo/redeem` (`1375`) sonde la validité d'un code pour un e-mail arbitraire.
**Conséquence** : l'e-mail d'un invité suffit à lire sa progression fidélité et à s'emparer de son code. Les appelants connectés (`ref = client:<uid>` issu du jeton) sont sûrs.

### V4 — Écriture d'analytics non authentifiée · `index.php:784,797` · **P3**
`POST /catalog/cross-sell/stat` → `INSERT … ON DUPLICATE KEY UPDATE impressions=impressions+…, adds=adds+…`, sans auth ni limite. **Conséquence** : intégrité des compteurs de cross-sell (impressions/adds) falsifiable à volonté.

### V5 — Divulgation d'erreur DB · `index.php:547-556` · **P3**
Le repli 500 de `/shops` renvoie `detail` (message d'exception), `ligne` et `base` (nom de la base) au client. **Conséquence** : fuite d'information interne exploitable en reconnaissance.

---

## 6. Existant à adapter — un bloc par élément

### A1 — Secrets à repli non vide (garde de boot manquante) · `config.example.php:13,15` + `lib.php:94-99`
`auth_secret => …?: 'change-me-long-random'` et `admin_token => …?: 'change-me-admin-token'`. `require_admin()` ne renvoie 503 que si le jeton attendu est **vide** — or le repli n'est jamais vide. **Ce qui change** : au démarrage, refuser de servir si `auth_secret`/`admin_token` appartiennent à une liste de défauts connus (et exiger une longueur minimale). **Impacté** : tout le modèle de jetons (client forgeables si `auth_secret` = défaut ; admin réseau ouvert si `admin_token` = défaut). *La valeur réelle de prod n'est pas dans le repo — cf. §8.*

### A2 — CORS `*` + credentials · `index.php:10-20`
La conjonction `Allow-Credentials: true` + acceptation de `'*'` dans `cors_origins` est permise par le code (le défaut `WS_CORS` est pourtant une origine fixe). **Ce qui change** : ne jamais émettre `Allow-Credentials: true` quand l'origine résolue vient d'un `'*'` ; conserver l'allowlist stricte. **Impacté** : sécurité cross-origin si un opérateur met un jour `WS_CORS=*`. *Note* : le commentaire `:14-18` évoque une surcharge `?api=` qui **n'existe pas** dans le front (doc obsolète, cf. §7).

### A3 — Anti-abus des POST publics · `index.php:2869` (register), `1697/2033/2062` (leads)
`/auth/register` écrit `client` sans `rate_limit()` (contrairement à `/auth/login:2999`). Les formulaires publics `/zone-request` (`2033`) et `/b2b/event-request` (`2062`) n'ont qu'un rate-limit IP maison — **conformes par conception** (formulaires de prospect), notés pour le risque de spam résiduel. **Ce qui change** : ajouter `rate_limit()` à `register`. **Impacté** : intégrité de la table `client` / coûts.

### A4 — Console avis franchisé scopée par l'URL · `public/admin-reviews.html:67-105`
La page porte un jeton **réseau** (`X-Admin-Token`, `localStorage['rv_admin_token']`) et distingue « franchisé » par `?shop=<id>` **côté client**. **Ce qui change** : la console franchisé doit appeler la route native **`/franchisee/reviews`** (créée dans une tâche précédente, bornée à la boutique de session, sans jeton réseau) et non `/admin/reviews?shop=`. **Impacté** : un porteur du jeton retire `?shop=` et obtient la vue réseau — le filtre n'est pas une frontière.

### A5 — `/auth/set-password` sans OTP · `index.php:3038-3074`
Déjà durci (rate-limité, réservé aux comptes **sans** mot de passe, cf. garde `:3071`) et **documenté** comme choix produit. **Ce qui change** : ajouter un OTP e-mail/SMS avant mise en prod définitive. **Impacté** : prise de compte sur un compte importé sans mot de passe, connaissant l'e-mail. Verdict : **à adapter, P2**, mais partiellement mitigé.

---

## 7. Manques transverses (contrat, convention, doc, tests)

- **Deux systèmes d'auth back-office en parallèle** : `/franchisee/*` (jeton PIN, logique `$scope()` dans `index.php`) **et** `/bo/*` (cookie signé, `bo_shop_where()` dans `bo/routes.php`). Les deux sont scopés, mais la logique de portée est **dupliquée** — risque de divergence au prochain contributeur (une correction sur l'un oublie l'autre). *Convention.*
- **Contrat de portée non centralisé** : le forçage `$shopId` (`index.php:4991`) et l'assertion `bo_assert_shop_allowed` (`bo/guard.php:106`) sont corrects mais reposent sur la **discipline** de chaque route. `onboard-office` (V2) est précisément l'endroit où la discipline a lâché. *Aucun garde-fou structurel (helper obligatoire) n'empêche une route d'oublier le scope.*
- **Contrat d'erreur incohérent** : `/shops` divulgue le détail DB (`:547`), alors que le handler global masque (`:37`). *Uniformiser la politique d'erreur.*
- **Doc obsolète** : le commentaire CORS (`index.php:14-18`) documente une surcharge `?api=` absente du front (confirmé : aucun `?api=` dans les fichiers navigateur). *Retirer la mention.*
- **Tests de portée** : il existe `php-api/bo/test-isolation.sh` et `php-api/tests/` — l'isolation `/bo/` est testée ; **aucun test** ne couvre le forçage de scope des routes `/franchisee/*` ni l'ownership des routes `/auth|/orders|/payments`. *Les trous V1/V2/V3 ne seraient pas détectés par la CI actuelle.*
- **Énumérabilité des références** : `order_ref` = timestamp + 2 chiffres, `id` séquentiel (`index.php:2568`) — acceptable **seulement** parce que les routes propriétaires vérifient l'ownership ; devient critique dès qu'une route l'oublie (V1). *Convention : toute route acceptant un id de commande DOIT joindre `customer_id`.*

---

## 8. Zones non couvertes par l'audit

- **Valeurs réelles de `php-api/config.php`** : hors repo (git-ignoré). La solidité de tout le modèle (jetons client, admin réseau, CORS) **dépend** de `WS_AUTH_SECRET`/`WS_ADMIN_TOKEN`/`WS_CORS` réellement posés en prod — **non vérifiable ici** (cf. A1).
- **`franchise-buddy/menus.js`** : module Node/Express + `mysql2` avec `pool.query` (`:30-43`) — un **accès DB direct hors de l'API PHP**. Non servi au navigateur, mais s'il est déployé, c'est un second chemin d'accès aux données à auditer séparément (scope/auth non vérifiés).
- **`php-api/admin/index.html`, `php-api/cron/*`, `php-api/tools/*`, `php-api/pdf.php`, `invite_doc.php`, `qr.php`** : outillage admin/ops non énuméré route par route.
- **`bo/auth.php`** (flux login/logout/password des consoles) : lu pour le scope, non audité pour la robustesse du login (brute-force, verrouillage).
- **Assignations RBAC réelles** (`bo_users.sections`, `bo_user_shops`) : dépendantes des données, non observables depuis le code seul — un compte PIN sur-doté en sections élargit de fait sa surface.
- **Chaîne de build/déploiement** (`.github/workflows/`, `deploy/deploy.sh`) : non auditée pour l'injection de secrets.
