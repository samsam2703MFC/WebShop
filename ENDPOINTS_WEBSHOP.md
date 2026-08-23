# Webshop — la liste complète des endpoints

> Relevé du 23/08/2026, vérifié dans le code des deux côtés : chaque route citée
> existe dans `php-api/index.php` (ligne indiquée) ET a son consommateur dans le
> front. L'architecture tient en deux phrases : **le navigateur ne parle qu'à
> l'API PHP du webshop**, servie sur la même origine sous `{base}/api`
> (`api-config.js:30-31`) ; **seul le serveur PHP parle à l'ERP** Franchise
> Buddy et aux services tiers.

---

## A. API du webshop (PHP) — ce que le navigateur appelle

### 1. Socle

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /health` | sonde de vie | 528 |
| `GET /config` | configuration publique | 535 |
| `GET /i18n?scope=` | dictionnaire fr/nl (table `ws_i18n`) — chargé au boot, caché en localStorage | 593 |
| `GET /shops` | annuaire des boutiques (`webshop_enabled=1`, avec `default_lang`/`languages`) | 549 |
| `GET /brand?shopId=` | thème/branding de la boutique | 575 |
| `GET /erp/probe?lang=` | diagnostic de la liaison ERP (traductions) | 620 |
| `GET /webshop-link?clientId=` | lien retour depuis la PWA cliente | 653 |

### 2. Catalogue

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /catalog/categories?shopId=&lang=` | catégories + sous-catégories, libellés traduits via alias ERP | 693 |
| `GET /catalog/products?shopId=&mode=&date=&lang=` | LE catalogue servi : prix résolus, allergènes, portions, menus, canaux | 785 |
| `GET /catalog/assortments?shopId=` | gammes saisonnières mises en avant | 1179 |
| `GET /catalog/bundles?productId=` | formules d'un produit (composeur de menu) | 975 |
| `GET /catalog/stock?shopId=&date=&mode=` | stock du jour par produit | 1049 |
| `POST /catalog/stock/reserve` | réservation de panier (expire seule) | 1077 |
| `POST /catalog/stock/release` | libération (ligne retirée / mode changé) | 1118 |
| `POST /catalog/cross-sell` | suggestions de vente croisée, filtrées SERVEUR | 798 |
| `POST /catalog/cross-sell/stat` | mesure d'efficacité des suggestions | 886 |
| `GET /catalog/why?productId=` | explication d'exclusion (diagnostic) | 903 |

### 3. Dates & créneaux

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /availability/settings?shopId=` | heures, durées, délais de la boutique | 1521 |
| `GET /availability/days?shopId=&mode=` | jours ouverts/fermés du calendrier | 1688 |
| `GET /availability/slots` = `GET /calendar/slots` (`?shopId=&mode=&date=`) | créneaux : explicites (`ws_slots`) sinon générés des vraies heures — jamais inventés | 1531 |
| `GET /calendar/cutoff?shopId=&mode=` | heure limite de commande | 1558 |
| `GET /calendar/exceptions?shopId=` | jours exceptionnels | 1682 |

### 4. Paiement & prix

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /payment-methods?shopId=&profile=&companyId=&mode=&lang=` | moyens autorisés (boutique × profil × mode), libellés traduits, **`family`** décide du paiement hébergé | 1499 |
| `POST /payments/checkout {orderId}` | crée la session Stripe → `checkoutUrl` | 3591 |
| `POST /payments/stripe-webhook` | appelé par STRIPE (signé) — seule preuve d'encaissement | 3625 |
| `GET /pricing/promos/cross-portion?shopId=` | règle « portions croisées » (x achetées → y offertes) | 1202 |

### 5. Commandes

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `POST /orders` | LA commande : prix/remises recalculés serveur, idempotence `requestKey`, stock, bons — contrat détaillé en réponse `{orderId, orderRef, total, paymentType…}` | 2311 |
| `GET /orders/:id` | relecture (propriétaire ou admin seulement) | 2970 |

### 6. Bons & promotions

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /vouchers/available?shopId=&lang=` | bons visibles du client (modèle ERP, canal WS), libellés composés serveur | 1215 |
| `POST /vouchers/redeem` | validation d'un code (compteurs, ciblage, périmètre produit) | 1291 |
| `GET /promo/active?shopId=` | campagnes de fidélité actives | 1390 |
| `GET /promo/:id/progress` | avancement du client | 1406 |
| `POST /promo/:id/claim` | réclamer la récompense | 1424 |
| `POST /promo/redeem` | consommer la récompense | 1478 |

### 7. Compte client

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `POST /auth/register` | inscription (email, CP obligatoire, localité si ambiguë) | 2984 |
| `POST /auth/login` | connexion → jeton HMAC 30 j (`Authorization: Bearer`) | 3113 |
| `POST /auth/set-password` | compte existant sans mot de passe | 3153 |
| `GET/PATCH /auth/me` | profil | 3484 / 3524 |
| `POST /auth/password` | changement de mot de passe | 3440 |
| `POST /auth/handoff` | jeton à usage unique venu de la PWA | 3203 |
| `POST /auth/office` | demande de rattachement à un bureau | 3275 |
| `GET /auth/office-delivery` | état livraison bureau du client | 3494 |
| `GET /auth/purchases` | historique d'achats | 3314 |
| `POST /auth/purchases/request-invoice` | demande de facture sur un achat | 3393 |
| `POST /auth/billing-verify` | vérification TVA société | 3223 |
| `POST /auth/billing-company` (+ `/unlink`) | lier/délier sa société de facturation | 3454 / 3478 |
| `GET /vies/:country/:vat` | relais serveur de la validation TVA européenne | 3197 |

### 8. Livraison bureau (B2B)

| Méthode & route | Sert à | Ligne |
|---|---|---|
| `GET /offices?q=` · `GET /offices/:id` | annuaire des bureaux | 1788 / 1793 |
| `POST /offices/contact` | « mon bureau n'y est pas » | 1812 |
| `GET /companies?email=` | sociétés liées au client (facturation différée) | 2246 |
| `POST /delivery-fees/quote` | frais de livraison résolus (site > office > tournée > boutique > global) + `payment_type` | 2277 |
| `GET /delivery-fees/sites?officeClientId=` · `/sites/:id` | sites de livraison d'un bureau | 2026 / 2036 |
| `GET /tours` · `GET /tours/:id` | tournées | 1765 / 1771 |
| `GET /slots?officeId=` · `/slots/next` · `POST /slots/request-evening` | créneaux de tournée | 2230-2238 |
| `GET /office-sites` | sites (vue transverse) | 2063 |
| `GET /delivery-zones` · `GET /zone-modalites` · `POST /zone-request` | zones desservies + demande d'extension | 2096-2148 |
| `POST /b2b/event-request` | demande événement/traiteur | 2177 |
| `GET /geo/postcodes?q=` · `GET /geo/postcode-polygons` | référentiel codes postaux (inscription, zones) | 1633 / 1575 |
| `POST /inscription` | formulaire d'inscription réseau (page dédiée) | 1878 |

---

## B. API ERP Franchise Buddy — ce que le SERVEUR consomme

Transport : `erp_get()` (`php-api/erp_alias.php:65`) — Bearer, cache disque,
échec → `null` + bandeau. Config en base : `ws_param.erp_api_base` / `erp_api_token`.

| Endpoint ERP | Sert à | État |
|---|---|---|
| `GET products/aliases?lang_code=` | noms de produits traduits (609/762 en nl) | ✅ en service |
| `GET product-categories/aliases` + `product-category-groups/aliases` | catégories traduites (75/81) | ✅ en service |
| `GET shops/{id}/products/available?lang_code=` | assortiment par boutique | ⚙️ adaptateur prêt (`erp_catalog.php`), inerte tant que `ws_param.catalog_source ≠ 'erp'` |
| `GET shops/{s}/products/{p}/portions/prices` | LA table de prix (portions) : `shop_price_gross` + `has_shop_price`/`is_ready_for_sale` | ⚙️ adaptateur prêt — table encore vide côté ERP |
| `GET products/{id}/portions` | portions candidates | disponible, non consommé (le SQL direct suffit) |
| `GET recipes/{id}` | **PHOTOS produit** : `main_photo_path`, `photo_1..3_path`, `shop_photo_path` — URL R2 **signée, expire en 3600 s** | ✅ vérifié le 23/08 : JPEG 160 Ko téléchargé (recette 519 → produit 6700106) |

**Photos — ce que le relevé impose.** L'URL étant signée et périssable, elle ne
peut PAS être stockée dans `ws_products.img` ni servie telle quelle au
navigateur : il faut un travail de synchronisation côté serveur qui télécharge
vers `assets/product_pictures/{id_product}.jpg` — exactement ce que
`sync_product_images.php` sait déjà consommer. Couverture observée (échantillon
30 recettes, boutique 2) : 3 avec photo, 0 avec `shop_photo_path` ; 422 produits
sur 575 ont une recette. Un appel PAR recette, pas de lot.

---

## C. Endpoints ERP à demander à Franchise Buddy

Les cinq du mail envoyé (cf. `AUDIT_API_VS_DB.md` §6), plus un sixième né du
relevé photos :

1. `GET /shops/{id}/products/prices` — prix de vente EN LOT, pièce entière **et**
   portions (le bloquant nº 1 : `/price` répond 404, `portions/prices` est à
   l'unité) ;
2. API clients finaux (création + lecture) ;
3. API bons/promotions (lecture + rachat) ;
4. périodes de disponibilité dans `products/available` ;
5. statut allergènes « évalué sans allergène » vs « non renseigné » ;
6. **photos en lot et/ou stables** : soit `photo_url` directement dans
   `products/available`, soit un endpoint groupé — 573 appels `recipes/{id}`
   pour un catalogue, avec des URLs qui expirent, n'est pas un chemin de
   production (utilisable en revanche pour une synchronisation nocturne).

---

## D. Déclaré côté front mais servi par PERSONNE (à trancher)

Méthodes présentes dans les modules JS, jamais appelées par l'interface ET sans
route serveur — code mort à supprimer, ou contrats à construire si le besoin
arrive (l'historique de commandes passe aujourd'hui par `/auth/purchases`) :

| Module | Méthode | Route fantôme |
|---|---|---|
| `webshop-orders-api.jsx` | `listMine()` | `GET /orders/me` |
| `webshop-orders-api.jsx` | `cancel(id)` | `POST /orders/:id/cancel` |
| `webshop-catalog-api.jsx` | `getProduct(id)` | `GET /catalog/products/:id` |
| `webshop-availability-api.jsx` | `validateCart()` | `POST /availability/validate` |
| `webshop-availability-api.jsx` | `context()` | `POST /availability/context` |
| `webshop-auth-api.jsx` | `passwordReset()` | `POST /auth/password-reset` |
| `webshop-auth-api.jsx` | `logout()` (appel réseau) | `POST /auth/logout` (le front efface le jeton localement) |

---

## E. Services tiers appelés par le serveur (jamais par le navigateur)

| Service | Rôle |
|---|---|
| Stripe (checkout + webhook signé) | encaissement en ligne |
| Google Geocode / Places / Business Profile | géocodage des sites, avis |
| Nominatim (OSM) | repli de géocodage |
| VIES | validation TVA (relayé par `/vies/...`) |
| Anthropic | brouillons de réponses aux avis |
