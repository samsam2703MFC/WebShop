# Bons de réduction — créer et encaisser par l'API ERP (TFBuddy)

> Établi le 24/10/2026 à la demande de Sam (« est-ce que tu vois la possibilité de me créer des vouchers avec les
> API disponibles ? » — « fais-le via sonde pour être sûr, et ensuite crée ta documentation » — « rien en local »).
> Sources : la spécification OpenAPI publique de l'ERP, et quatre passages d'une sonde exécutée sur le serveur
> avec le compte admin ERP du cockpit (`consultant_BO` : `tools/sonde_vouchers.php`,
> `.github/workflows/sonde-vouchers.yml`). Chaque affirmation ci-dessous vient de l'un ou de l'autre ; ce qui reste
> inconnu est dit comme tel.

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
`SHARED_CODE_NOT_CONFIGURED`, `VOUCHER_CODES_NOT_GENERATED`.

### 2.1 Créer un bon, étape par étape

```text
1. POST  /admin/promotions/scheduled-product-discount   (ou bundles / buy-x-get-y / quantity)
         { name, status:"DRAFT", activation_mode:"VOUCHER_ONLY", shop_scope_type, shop_ids[],
           reward_category_ids[] | reward_product_ids[], discount_type, discount_value, valid_from, valid_to }
2. POST  /admin/voucher-campaigns
         { name, code_type:"SHARED"|"UNIQUE", shared_code?, valid_from?, valid_to?,
           usage_limit_total?, usage_limit_per_code?, usage_limit_per_customer?, requires_customer? }
3. PUT   /admin/voucher-campaigns/{id}/promotion       { id_promotion, confirm_activation_mode_change? }
4. UNIQUE : POST /admin/voucher-campaigns/{id}/codes/generate   { count, length?=10 (≥6), prefix? }
   ou un code précis : POST /admin/voucher-campaigns/{id}/codes  { code, status?, id_customer?, valid_from?, valid_to?, usage_limit? }
5. GET   /admin/voucher-campaigns/{id}                 → vérifier configuration_complete = true
6. PATCH /admin/voucher-campaigns/{id}/status          { status:"ACTIVE" }
```

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

La spécification liste ces routes **sans en décrire le contrat**. Voici ce que la sonde a pu lire sans vrai code
(code inexistant, jeton faux : rien n'a été validé ni encaissé) :

| Route | Relevé |
|---|---|
| `POST /vouchers/validate` | Toujours HTTP 200 : `{valid, validation_token, campaign, promotion, cart_addition_offer, product_selection, errors[{code, message}]}`. Sans code : `VOUCHER_NOT_FOUND` « Voucher code is empty. » ; code inconnu : `VOUCHER_NOT_FOUND` « Voucher code does not exist. ». Le champ du code s'appelle `code`. |
| `POST /vouchers/redeem` | Exige d'abord `request_key` (422 `VOUCHER_REQUEST_KEY_REQUIRED`), puis un bon existant : 404 `VOUCHER_NOT_FOUND`, qu'on envoie `code`, `validation_token` ou rien. Le champ exact qui désigne le bon reste à confirmer. |
| `POST /vouchers/reverse` | Exige `request_key`, puis un identifiant de transaction (422 `VOUCHER_TRANSACTION_ID_REQUIRED`). |

Le compte admin est accepté sur ces trois routes.

**Inconnu à ce jour** (il faut un vrai code actif pour le lire) :
- les champs du panier attendus par `validate` (magasin, lignes, montant) ;
- ce que `redeem` rend ;
- le nom exact du champ transaction de `reverse` ;
- ce qu'une commande webshop doit fournir comme « transaction », puisqu'elle n'est pas un ticket de caisse ERP.

Pour le lire, il faut une campagne de test **active** dans l'ERP. Comme l'API ne supprime rien, elle y restera :
c'est à Sam de dire oui avant qu'on la crée.

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

Les numéros 2, 3 et 4 existent des deux côtés, mais ce ne sont **pas** les mêmes campagnes (0 nom commun). Concrètement :
- un bon créé dans le BO du webshop (`POST /franchisor/voucher`, `/franchisee/voucher`, `/client-voucher`,
  `/onboard-office`, tous via `ws_voucher_upsert`) n'existe que dans la base locale. L'ERP, et donc la caisse, ne
  le voient pas ;
- un bon créé dans l'ERP (par exemple les invitations bureau Odoo) est refusé au paiement du webshop, qui ne lit
  que la vue locale `ws_vouchers`.

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

## 6. Plan proposé (« rien en local »)

0. **Accès** : un compte admin ERP pour le webshop (§1), connecté côté serveur.
1. **Paiement** : à la cotation, `POST /vouchers/validate` → garder `validation_token`. À la confirmation,
   `POST /vouchers/redeem` avec `request_key = order_ref` (idempotent). À l'annulation ou au remboursement,
   `POST /vouchers/reverse`. Le contrat se termine avec une campagne de test (accord de Sam requis, §3).
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
