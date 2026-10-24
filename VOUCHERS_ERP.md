# Bons de réduction — créer et encaisser par l'API ERP (TFBuddy)

> Établi le 24/10/2026 à la demande de Sam (« est-ce que tu vois la possibilité de me créer des vouchers avec les
> API disponibles ? » — « fais-le via sonde pour être sûr, et ensuite crée ta documentation » — « rien en local »).
> Sources :
> - la spécification OpenAPI publique de l'ERP ;
> - quatre passages d'une sonde en lecture, exécutée sur le serveur avec le compte admin ERP du cockpit
>   (`consultant_BO` : `tools/sonde_vouchers.php`, `.github/workflows/sonde-vouchers.yml`) ;
> - un essai d'écriture avec une campagne de test, accepté par Sam (« oui crée la campagne de test » : cinq passages
>   puis un nettoyage, `tools/essai_voucher.php`, `.github/workflows/essai-vouchers.yml`).
>
> Chaque affirmation ci-dessous vient de l'une de ces sources. Ce qui reste inconnu est dit comme tel.

## En bref

- **Oui, l'API ERP permet de créer des bons.** Le compte admin du cockpit a le droit d'écrire : la sonde reçoit
  422 (validation) et 404 (introuvable), jamais 403. La chaîne complète passe par l'API : campagne → promotion
  (la remise) → codes → activation.
- **Mais aujourd'hui, les bons du webshop sont 100 % locaux.** La base `atelierby_db` que lisent le webshop et le
  cockpit est sur `localhost` du serveur 185.180.206.46. Ce **n'est pas** la base de l'ERP : l'ERP ne connaît
  aucun des 3 bons du webshop, et le webshop n'accepte aucun des bons créés dans l'ERP (§4). Ça contredit, pour
  les bons en tout cas, l'hypothèse « même base » d'`AUDIT_API_VS_DB.md`.
- **La règle « rien en local » se tient côté création ET côté paiement.** L'ERP expose `POST /vouchers/validate`,
  `/redeem` et `/reverse` (§3). Le webshop doit valider et encaisser là, et plus dans `voucher_code`.
- **L'essai de bout en bout a marché** (24/10/2026) : créer une promotion, une campagne, un code, tout activer,
  valider (`valid: true`) puis encaisser (`status: COMPLETED`), uniquement par l'API.
- **Un point bloque les commandes webshop : l'annulation.** `reverse` exige l'`id_transaction` de l'encaissement.
  Or `redeem` n'accepte qu'une vraie transaction de caisse ERP (une inexistante : 500). Une commande webshop
  encaisse donc sans transaction, et l'API ne permet alors plus de l'annuler. À régler avec TFBuddy (§5).
- **Trois manques empêchent de reprendre les bons actuels tels quels :** pas de remise sur le panier entier
  (ni % ni € sur la commande, ni minimum de commande), pas de « livraison offerte », pas de canal
  (webshop / caisse). C'est à demander à TFBuddy (§5).

## 1. Accès

| | |
|---|---|
| Base | `https://atelierby.tfbuddy.com/api/v1` (hôte 164.132.85.61) |
| Spécification | `https://atelierby.tfbuddy.com/api/documentation/api.php` (OpenAPI 3.0, 933 routes, relue le 24/10/2026) |
| Connexion | `POST /admin/auth/login` `{phone, password}` → `access_token` (~1800 s). Repli : `POST /admin/authenticate` `{login, password}` |
| En-tête | `Authorization: Bearer <token>` (sans jeton : 401 `TOKEN_MISSING`, « Brak tokenu ») |
| Permission | `MENU.BUNDLE.MANAGE` (sinon 403) — **le compte admin du cockpit l'a** |
| Erreurs | `{status: "error", description: "<CODE>", errors: {champ: message}}` |

Le webshop a déjà un client ERP : `php-api/erp_alias.php` (`erp_login()`, `erp_token()`, réglages
`ws_param.erp_api_base`, `erp_auth_phone`, `erp_auth_password`). Mais il se connecte au réalm **consultant**
(`POST /consultant/auth/login`) et ne fait que des GET.

Les routes `/admin/voucher-*` demandent un jeton **admin**. Il faut donc ajouter au webshop une connexion
`/admin/auth/login` avec un compte admin qui a `MENU.BUNDLE.MANAGE`. Les identifiants restent côté serveur, comme
ceux du cockpit (`consultant_BO/src/erp_api.php`, réglage `erpAdmin`).

## 2. Le modèle ERP : campagne → promotion → codes

Un bon ERP, c'est trois choses :

1. **Une promotion** : elle porte la remise. Pour être déclenchée par un code, elle doit être en
   `activation_mode: VOUCHER_ONLY`.
2. **Une campagne** : elle porte les règles d'usage (dates, limites, client requis) et pointe vers **une**
   promotion.
3. **Des codes** : un code commun (`SHARED`, via `shared_code`) ou des codes uniques (`UNIQUE`, à générer).

Une campagne n'est utilisable que si l'API la dit complète : `configuration_complete: true`. Sinon,
`missing_requirements` dit ce qui manque. Valeurs relevées : `PROMOTION_NOT_ASSIGNED`, `PROMOTION_NOT_FOUND`,
`PROMOTION_NOT_ACTIVE`, `SHARED_CODE_NOT_CONFIGURED`, `VOUCHER_CODES_NOT_GENERATED`.

**Règles apprises pendant l'essai** (refus de l'ERP, mot pour mot) :

| Règle | Refus de l'ERP |
|---|---|
| Une promotion ACTIVE doit avoir au moins un créneau `schedule[]` (`day_of_week` 1–7, `time_from`, `time_to` au format `HH:MM`) | 422 `INVALID_PROMOTION_DATA` « At least one schedule window is required for ACTIVE promotions. » |
| Une campagne `UNIQUE` ne passe ACTIVE qu'avec au moins un code déjà ACTIVE : activer le code d'abord | 422 « ACTIVE UNIQUE campaign requires at least one active code. » |
| Pour activer une campagne `UNIQUE`, utiliser `PATCH /admin/voucher-campaigns/{id}` `{status: "ACTIVE", code_type: "UNIQUE", shared_code: null}`. `PATCH …/status` la refuse, parce que la fiche rend un `shared_code` calculé (le code unique). Comportement à signaler à TFBuddy | 422 « shared_code is not supported for UNIQUE campaigns. » |
| Les dates de début (`valid_from`) sont comparées en **UTC**. Un début « maintenant, heure de Bruxelles » n'a pas encore commencé. L'ERP rend `created_at` à l'heure locale, mais `activated_at` et `disabled_at` en UTC | `VOUCHER_NOT_STARTED` « Voucher campaign has not started yet. » |
| Les dates d'un code ne se modifient pas (seul son statut change) : un code mal daté se remplace | — |
| Détacher la promotion (`DELETE …/promotion`) repasse la campagne en DRAFT | — |
| Promotion supprimable par `DELETE /admin/promotions/scheduled-product-discount/{id}`, même après un encaissement | 200 « Promotion deleted » |

### 2.1 Créer un bon, étape par étape

Séquence vérifiée par l'essai du 24/10/2026 (promotion #35, campagne #9) :

```text
1. POST  /admin/promotions/scheduled-product-discount   (ou bundles / buy-x-get-y / quantity)  → 201 {data:{id}}
         { name, status:"DRAFT", activation_mode:"VOUCHER_ONLY", shop_scope_type:"SELECTED_SHOPS", shop_ids:[4],
           reward_product_ids:[…] | reward_category_ids:[…], discount_type:"PERCENTAGE_DISCOUNT", discount_value:1,
           valid_from (UTC), valid_to, max_applications_per_transaction:1,
           schedule:[{day_of_week:1..7, time_from:"00:00", time_to:"23:59"}] }
2. POST  /admin/voucher-campaigns                                                         → 201 {data:{campaign:{id}}}
         { name, status:"DRAFT", code_type:"UNIQUE"|"SHARED", shared_code? (SHARED seulement),
           valid_from (UTC), valid_to, usage_limit_total?, usage_limit_per_code?, usage_limit_per_customer?, requires_customer? }
3. PUT   /admin/voucher-campaigns/{id}/promotion  { id_promotion, confirm_activation_mode_change:false }   → 200
4. UNIQUE : un code précis  POST …/{id}/codes { code, status:"DISABLED", usage_limit, valid_from:null, valid_to } → 201 {data:{id,…}}
            ou en nombre    POST …/{id}/codes/generate { count, length?=10 (≥6), prefix? }        → 201 {data:{items:[…]}}
5. Activer, DANS CET ORDRE :
         PATCH /admin/voucher-codes/{id_code}/status                 { status:"ACTIVE" }
         PATCH /admin/promotions/scheduled-product-discount/{id}     { …corps complet…, status:"ACTIVE" }
         PATCH /admin/voucher-campaigns/{id}                         { status:"ACTIVE", code_type:"UNIQUE", shared_code:null }
6. GET   /admin/voucher-campaigns/{id}   → configuration_complete: true, missing_requirements: []
```

À noter : une promotion `VOUCHER_ONLY` active n'apparaît pas dans `GET /shops/{id}/scheduled-product-discounts/active`.
Elle ne se déclenche que par son code.

### 2.2 Les routes (spécification + sonde)

| Route | Corps / paramètres | Réponse relevée |
|---|---|---|
| `GET /admin/voucher-campaigns` | `page`, `limit` (≤100), `sort_by`, `sort_direction`, `status`, `code_type`, `planned_promotion_type`, `name`, `promotion`, `id_promotion`, `valid_from_from`, `valid_to_to` | `{items[], pagination{page,limit,total,pages}}`. Un élément : `id, name, status, code_type, planned_promotion_type, id_promotion, promotion_name, promotion_type, valid_from, valid_to, configuration_complete, missing_requirements[]` |
| `POST /admin/voucher-campaigns` | `name`* · `description` · `planned_promotion_type` · `status` (DRAFT par défaut · ACTIVE · INACTIVE) · `code_type` (SHARED par défaut · UNIQUE) · `valid_from` · `valid_to` · `usage_limit_total` · `usage_limit_per_code` · `usage_limit_per_customer` (≥1) · `requires_customer` (false) · `shared_code` | 201. Sans `name` : 422 `INVALID_VOUCHER_CAMPAIGN_DATA` `{name: "name is required."}` (vérifié) |
| `GET /admin/voucher-campaigns/{id}` | — | `{campaign{id, name, description, status, code_type, planned_promotion_type, id_promotion, valid_from, valid_to, usage_limit_total, usage_limit_per_code, usage_limit_per_customer, requires_customer, created_at, updated_at}, promotion, shared_code, codes[], code_stats{active, disabled, total}, redemptions_total, configuration_complete, missing_requirements[]}` |
| `PATCH /admin/voucher-campaigns/{id}` | mêmes champs que la création, tous facultatifs | 200 |
| `PATCH /admin/voucher-campaigns/{id}/status` | `status`* (DRAFT · ACTIVE · INACTIVE) | 200 |
| `PUT /admin/voucher-campaigns/{id}/promotion` | `id_promotion`* · `confirm_activation_mode_change` (doit valoir true si la promotion n'est pas déjà VOUCHER_ONLY) | 200 · 409 si la campagne a déjà une autre promotion ou si la promotion sert ailleurs |
| `DELETE /admin/voucher-campaigns/{id}/promotion` | — | détache la promotion |
| `GET /admin/voucher-campaigns/{id}/available-promotions` | `page`, `limit` (≤100), `activation_mode` (AUTOMATIC · VOUCHER_ONLY), `promotion_type`, `assignment_status`, `search` | élément : `id, name, description, promotion_type, status, activation_mode, valid_from, valid_to, assignment_status` (ex. `REQUIRES_ACTIVATION_MODE_CHANGE`), `can_assign, requires_activation_mode_change, assigned_voucher_campaign_{id,name,status}` |
| `GET /admin/voucher-campaigns/{id}/codes` | `page`, `limit` (≤200), `status` (ACTIVE · DISABLED), `code`, `id_customer` | élément : `id, id_voucher_campaign, code, status, id_customer, office_id, valid_from, valid_to, usage_limit, usage_count, created_at, activated_at, disabled_at` |
| `POST /admin/voucher-campaigns/{id}/codes` | `code`* · `status` (ACTIVE) · `id_customer` · `valid_from` · `valid_to` · `usage_limit` | 201 |
| `POST /admin/voucher-campaigns/{id}/codes/generate` | `count`* (≥1) · `length` (10, ≥6) · `prefix` | 201 · 422 si la campagne n'est pas UNIQUE. Campagne inconnue : 404 `VOUCHER_CAMPAIGN_NOT_FOUND` (vérifié) |
| `PATCH /admin/voucher-codes/{id}/status` | `status`* (ACTIVE · DISABLED) | 200. Code inconnu : 404 `VOUCHER_CODE_NOT_FOUND` (vérifié) |

**Il n'existe pas de suppression** de campagne ni de code. On désactive : campagne `INACTIVE`, code `DISABLED`.

### 2.3 Les promotions qui peuvent porter la remise

| Route | Ce que la remise peut viser |
|---|---|
| `/admin/promotions/scheduled-product-discount` | `discount_type` : `PERCENTAGE_DISCOUNT` · `FIXED_AMOUNT_DISCOUNT` · `FIXED_PRICE`, sur `reward_product_ids` / `reward_category_ids` (moins `excluded_product_ids`) ; magasins : `shop_scope_type` ALL_SHOPS · SELECTED_SHOPS + `shop_ids` ; créneaux `schedule[]` ; `voucher_campaign_id` |
| `/admin/promotions/buy-x-get-y` | X achetés → Y offerts / remisés |
| `/admin/promotions/bundles` | prix de lot |
| `/admin/promotions/quantity` | remise par quantité |

Les champs détaillés de chaque type sont dans `consultant_BO/docs/api-promotions.md` (branche de déploiement du
cockpit).

## 3. Le paiement : valider, encaisser, annuler à l'ERP

La spécification liste ces routes **sans en décrire le contrat**. Ce qui suit vient de la sonde (codes inexistants)
et de l'essai (un vrai code actif, boutique 4). Le compte admin est accepté sur les trois routes.

### `POST /vouchers/validate`

- **Corps** : `{code, id_shop}`. `shop_id` n'est pas reconnu. Sans `id_shop` : `VOUCHER_NOT_AVAILABLE_IN_SHOP`.
  Des lignes de panier (`items`, `products`) ne changent pas la réponse.
- **Réponse** : toujours HTTP 200, `{valid, validation_token, campaign, promotion, cart_addition_offer, product_selection, errors[{code, message}]}`.
- **Bon valide** :
  - `validation_token` : un JWT d'environ 380 caractères ;
  - `campaign` et `promotion` : leurs fiches complètes ;
  - `cart_addition_offer` : `{requires_confirmation: true, title: "Voucher obejmuje: <produit>, -1%", items[{id_product, id_product_portion, quantity, weight_grams, product_name, description, display_regular_gross_price, …}]}` ;
  - `product_selection` : `{promotion_type, title, requires_selection, selection_available, unavailable_reason, max_applications_per_transaction, is_repeatable, groups[{key: "reward", role: "REWARD", …}]}`.

  Le bon est pensé pour une caisse : il propose d'ajouter ou de remiser des produits précis.
- **Erreurs relevées** : `VOUCHER_NOT_FOUND` (« Voucher code is empty. » / « … does not exist. »),
  `VOUCHER_CAMPAIGN_DRAFT`, `VOUCHER_CAMPAIGN_INACTIVE`, `VOUCHER_NOT_STARTED`, `VOUCHER_NOT_AVAILABLE_IN_SHOP`.

### `POST /vouchers/redeem`

- **Corps** : `{request_key, code, id_shop}`, avec `id_transaction` en option. `validation_token` seul ne désigne
  pas le bon : 404 `VOUCHER_NOT_FOUND`.
- **Réponse** : 200 `{idempotent: false, redemption: {id, id_voucher_code, id_voucher_campaign, id_promotion, id_transaction, id_shop, id_customer, id_employee, discount_value, status: "COMPLETED", reserved_at, expires_at, redeemed_at, request_key, quote_fingerprint, quote_snapshot, quote_updated_at}}`.
  L'usage du code passe à 1. Les champs `reserved_at` et `quote_*` laissent penser à un mode réservation / devis
  qui n'est pas documenté.
- **`discount_value` vaut `null`** : l'ERP ne calcule pas le montant à l'encaissement. Il ne l'a pas fait sans
  panier en tout cas.
- **`id_transaction` doit être une vraie transaction de caisse ERP** : 999999999 donne 500
  `FAILED_INSERT_VOUCHER_REDEMPTION`, et le code n'est pas consommé.
- **Erreurs relevées** : 422 `VOUCHER_REQUEST_KEY_REQUIRED`, 404 `VOUCHER_NOT_FOUND`, 409
  `VOUCHER_CAMPAIGN_DRAFT` / `VOUCHER_CAMPAIGN_INACTIVE` / `VOUCHER_NOT_STARTED` / `VOUCHER_NOT_AVAILABLE_IN_SHOP`.
- **`request_key`** sert d'idempotence (`idempotent` dans la réponse). Le rejeu n'a pas pu être essayé : la seule
  tentative avec transaction a échoué.

### `POST /vouchers/reverse`

- **Corps** : `{request_key, id_transaction}`. Le champ s'appelle `id_transaction` : `transaction_id` et
  `idTransaction` donnent 422 `VOUCHER_TRANSACTION_ID_REQUIRED`.
- **Le couple est comparé à l'encaissement.** Sur l'encaissement d'essai (fait sans transaction), envoyer son
  `request_key` avec `id_transaction: 999999999` donne 409 `VOUCHER_TRANSACTION_MISMATCH`. Et sans `id_transaction`
  la route refuse. Un encaissement sans transaction **ne s'annule donc pas** par l'API.
- **Erreurs relevées** : 422 `VOUCHER_REQUEST_KEY_REQUIRED` / `VOUCHER_TRANSACTION_ID_REQUIRED`,
  404 `VOUCHER_REDEMPTION_NOT_FOUND`, 409 `VOUCHER_TRANSACTION_MISMATCH`.

**Ce qu'il reste à obtenir de TFBuddy** pour une commande webshop :
- annuler un encaissement fait sans transaction (par `request_key` seul) ;
- ou un moyen de rattacher une commande webshop à une transaction ERP.

Un troisième système existe : `GET /promo-codes`. Il rend 2 codes de 2025 (`type_name: amount`, `min_order_value`,
`is_single_use`), sans lien avec les campagnes. C'est un héritage, à ne pas utiliser.

## 4. Ce qu'il y a aujourd'hui : deux mondes qui ne se voient pas

Croisement fait par la sonde le 24/10/2026 :

| | ERP (API, 164.132.85.61) | Base locale `atelierby_db` (webshop + cockpit, `localhost` de 185.180.206.46) |
|---|---|---|
| Campagnes | 8 (#1–#8) : 3 ACTIVE · 4 INACTIVE · 1 DRAFT · 5 SHARED · 3 UNIQUE | 3 (#2–#4), toutes au canal `WS` |
| Lesquelles | « Office invitation · Odoo (Sa) » (codes uniques par bureau, `office_id`), « Odoo XP », trois « [SONDE À SUPPRIMER] test technique 25/08 », … | `Webshop — BIENVENUE10`, `Webshop — LIVRAISONOFF`, `Boutique 2 — B2-…` (bon de bureau) |
| Promotions | BUY_X_GET_Y, BUNDLE, QUANTITY… Aucune campagne lue n'est complète (`configuration_complete: false`) | `ORDER_DISCOUNT`, un type créé par le webshop (migration 0005) et inconnu de l'ERP, avec sa table `promotion_order_discount` |
| Colonnes en plus | `office_id` sur les codes | `id_shop`, `id_brand`, `target_kind`, `target_id`, `reason_kind`, `reason_note`, `created_by` (migrations 0009, 0041) |

**Restes de l'essai dans l'ERP (24/10/2026) :**
- la campagne #9, « [SONDE COCKPIT — à désactiver] essai bons webshop 24/10 », est **complète mais hors service**,
  pour qu'on la voie dans le panel (mode `visible`, accord de Sam) :
  - campagne INACTIVE jusqu'au 31/12/2026 ;
  - promotion #36 rattachée et INACTIVE : 1 % sur la Baguette Poivre & Sel, Halle ;
  - 5 codes ZZSONDE… tous désactivés ;
- son encaissement #1 : boutique 4, sans transaction, montant `null`, impossible à annuler par l'API (§3) ;
- la promotion #35 du premier essai a été supprimée.

Après le nettoyage, la campagne vide (DRAFT, sans promotion) n'apparaissait pas dans le panel.

L'API ne supprime pas les campagnes. Pour retirer la campagne et l'encaissement, il faut demander à TFBuddy.

Les numéros 2, 3 et 4 existent des deux côtés, mais ce ne sont **pas** les mêmes campagnes (0 nom commun). Concrètement :
- un bon créé dans le BO du webshop (`POST /franchisor/voucher`, `/franchisee/voucher`, `/client-voucher`,
  `/onboard-office`, tous via `ws_voucher_upsert`) n'existe que dans la base locale. L'ERP, et donc la caisse, ne
  le voient pas ;
- un bon créé dans l'ERP (par exemple les invitations bureau Odoo) est refusé au paiement du webshop, qui ne lit
  que la vue locale `ws_vouchers`.

> **À arbitrer (24/10/2026).** Le même jour, le module marketing (PR #97 et #98) a étendu le modèle local :
> - campagnes de bons via `ws_voucher_upsert` ;
> - promotions produit portées par un code, dans la table locale `ws_voucher_product_promos` (migration 0136).
>
> Ces bons-là non plus ne sont pas vus par la caisse. Les bons du dashboard franchisé (§6.1), eux, sont écrits dans
> l'ERP. Les deux voies coexistent tant qu'on n'a pas choisi.

## 5. Ce que l'ERP ne sait pas encore exprimer — à demander à TFBuddy

| Besoin du webshop (aujourd'hui en local) | Dans l'API ERP |
|---|---|
| Remise **sur la commande** en % ou en € (`BIENVENUE10`) | ✗ Les promotions visent des produits ou des catégories. Une remise en % sur toutes les catégories (scheduled-product-discount) s'en approche ; un montant fixe sur le panier, non. |
| Minimum de commande (`min_order`) | ✗ Aucun champ sur les campagnes ni sur scheduled-product-discount. |
| Livraison offerte (`LIVRAISONOFF`) | ✗ Rien. |
| Bon réservé au webshop (canal `WS`) | ✗ Ni champ canal sur les campagnes, ni destination documentée sur les promotions. |
| Bon émis par une boutique (`id_shop`) | ~ Seulement par la promotion (`SELECTED_SHOPS` + `shop_ids`). La campagne n'a pas d'émetteur. |
| Bon ciblé client | ✓ `requires_customer` + `id_customer` sur le code. |
| Bon ciblé bureau | ~ `office_id` apparaît sur les codes (lecture), mais la spécification ne permet pas de l'écrire. |
| Motif (réclamation, geste commercial…) | ✗ Seulement `description` en texte libre. |
| Remise limitée à N unités d'un produit (`scope_product_id`, `scope_max_qty`) | ~ `reward_product_ids` + `max_applications_per_transaction`. |
| Annuler le bon d'une commande annulée ou remboursée | ✗ `reverse` exige l'`id_transaction` de l'encaissement, et une commande webshop n'a pas de transaction ERP (§3). |
| Montant de la remise à l'encaissement | ✗ `redeem` rend `discount_value: null`. Le webshop doit calculer la remise (à partir de `validate`) et la garder. |

## 6. Plan proposé (« rien en local »)

0. **Accès** : un compte admin ERP pour le webshop (§1), connecté côté serveur.
1. **Paiement** :
   - à la cotation, `POST /vouchers/validate {code, id_shop}`. On applique la remise à partir de `promotion` et de
     `cart_addition_offer` ;
   - à la confirmation, `POST /vouchers/redeem {request_key: "WS-ORDER-<order_ref>", code, id_shop}` ;
   - **l'annulation reste bloquée** tant que TFBuddy n'ouvre pas `reverse` sans transaction (§3, §5). D'ici là, une
     commande annulée laisse son bon consommé côté ERP.
2. **Création** : les écrans du BO (marque, boutique, fiche client, onboarding bureau) appellent la chaîne du §2.1
   au lieu de `ws_voucher_upsert`. Les bons de boutique passent par `shop_ids` de la promotion.
3. **Les 3 bons locaux** :
   - `Boutique 2 — B2-…` se recrée tel quel à l'ERP ;
   - `BIENVENUE10` se recrée en approximation (% sur toutes les catégories) ;
   - `LIVRAISONOFF` attend la livraison offerte côté ERP (§5).
4. **Ensuite seulement** : retirer `ws_voucher_upsert`, la vue `ws_vouchers` et les écritures dans `voucher_*` /
   `promotion_order_discount` de la base locale.
5. **Ménage dans l'ERP** : les trois campagnes « [SONDE À SUPPRIMER] test technique 25/08 » datent d'un essai
   antérieur. Faute de suppression, les passer `INACTIVE`.

### 6.1 Déjà fait côté cockpit : le bon dans le dashboard du franchisé (24/10/2026)

Le point 2 existe pour les promotions « X achetés + Y » du magasin. Dépôt `consultant_BO`, `src/promos_bons.php`,
contrat dans `docs/contrat-api.md`, entrée « Le bon de réduction (24/10/2026) » :
- **Où** : onglet Promotions du dashboard magasin. Il y a une étape « Bon de réduction » dans l'assistant, et un
  bouton « + Nouveau bon » qui commence par le bon puis enchaîne sur la promotion liée.
- **Les trois distributions** :
  - un code pour tous (SHARED) ;
  - N codes d'une utilisation (UNIQUE) ;
  - un code personnel par client du magasin, choisi parmi `GET /shops/{id}/clients` (UNIQUE, `requires_customer`).
- **L'ordre d'écriture**, celui du §2.1 :
  1. promotion `VOUCHER_ONLY` écrite inactive ;
  2. campagne en brouillon avec ses codes ;
  3. rattachement ;
  4. à l'envoi, promotion puis campagne activées (`PATCH /{id}`).

  Rien n'est gardé en local.
- **La liste** : « Voir les codes » (codes en clair, nom du client) et une bascule actif / hors service.

Ces codes passent par `/vouchers/validate` et `/redeem` comme ceux de l'essai (§2). Le webshop les lira une fois le
point 1 branché. Appliquer une « X + Y » au panier du webshop reste à faire.

## 7. Relancer la sonde

Dépôt `consultant_BO`, branche `claude/admiring-archimedes-8cr84j` :
- modifier `sonde-vouchers.trigger` et pousser ;
- ou « Run workflow », une fois le workflow sur la branche par défaut.

Le résultat est dans les journaux de l'action « Sonde — bons de l'API ERP (lecture seule) ».

Ce que fait la sonde :
- elle lit les campagnes, leurs fiches, leurs codes et les promotions rattachables ;
- elle tente trois écritures construites pour être refusées (422 / 404), ce qui vérifie le droit d'écrire sans rien
  créer ;
- elle lit les colonnes locales et croise les campagnes de l'API avec celles de la base ;
- elle lit le contrat de `/vouchers/*` avec un code inexistant.

Les codes sont masqués et les clients seulement comptés.

**L'essai d'écriture** (`.github/workflows/essai-vouchers.yml`, `tools/essai_voucher.php`) ne part que sur un
changement de `essai-vouchers.trigger`, dont le premier mot donne le mode :
- `essai` : reprend la campagne de test par son nom, ou la recrée, puis active, valide, encaisse et désactive dans
  un `finally` ;
- `nettoyage` : désactive, détache et supprime la promotion.

Il écrit dans l'ERP : à ne relancer qu'avec l'accord de Sam.
