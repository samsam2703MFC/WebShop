# Checklist — vers zéro table locale, point par point

> Version ACTIONNABLE de `AUDIT_ZERO_TABLE_LOCALE.md` — à cocher dans l'ordre.
> État au 31/08/2026, branche `claude/client-orders-post-endpoint-pk5azl`.
>
> ⚠️ « Drop Verdict B » (demandé le 31/08) : **pas encore possible.** Chaque
> case des vagues 2-3 dit CE QUI DOIT EXISTER D'ABORD. Dropper aujourd'hui =
> vente cassée (le catalogue servi s'appuie encore sur le squelette local, les
> commandes deviennent illisibles pour le suivi client et le BO, le paiement
> Stripe perd son ancrage) — et une partie du Verdict B, ce sont des tables
> **de l'ERP** (`client`, `voucher_*`, `promotion`, `product_portion`…) : les
> dropper détruirait les données de Franchise Buddy, pas les nôtres.

## Vague 0 — fait sur la branche ✔

- [x] Portion ERP figée à la vente (`ws_order_lines.portion_id`, migration 0103)
- [x] Fin du parcours invité (401 API + mur de connexion)
- [x] La commande naît côté ERP à la confirmation (`erp_orders.php`, migration 0104, cron de reprise)
- [x] P0 corrigé : `erp_catalog.php` branché (les bascules prix/assortiment étaient lettre morte)
- [x] **Verdict A — vestiges supprimés (migration 0105)** : `ws_customers`,
      `ws_product_prices`, `ws_product_availability`, `ws_category_availability`
      (+ code : repli géo retiré, JOIN BO → `client`, `basket_pa` réduit à
      `no_delivery`, `/admin/price` supprimé, schéma et seed à jour)

## Vague 1 — activer & vérifier (toi, en prod)

> Mode d'emploi copier-coller (SQL, curl, cron, contrôles feu vert/rouge,
> rollback) : **`RUNBOOK_VAGUE1.md`**. Aucune table à dropper ici — les
> suppressions (0105) s'appliquent seules au déploiement via `migrate.sh`.

- [ ] Déployer la branche ; `migrate.sh` applique 0103 → 0105 (contrôle : `tables_restantes = 0`)
- [ ] **Vérifier le P0** : `ws_param.catalog_source` était-il déjà à `'erp'` ?
      Si oui, les prix servis jusqu'ici étaient les LOCAUX — contrôler quelques commandes récentes
- [ ] Poser `ws_param.catalog_source = 'erp'` ; contrôler `/erp/probe` et le bandeau (503 honnête si ERP muet)
- [ ] Poser `ws_param.orders_push = 'erp'` ; surveiller `ws_orders.erp_push_error` les premiers jours (un 422 = portion sans prix boutique)
- [ ] Ajouter le cron : `php php-api/cron/erp-orders-push.php` toutes les 5 min

## Vague 2 — demandes à l'ERP (Szymon) — chaque livraison débloque un drop de la vague 3

- [ ] **Commandes** : GET des commandes d'un client + statut « pending »/« cancelled » (ou DELETE) + champs livraison/frais/créneau/paiement
- [ ] Réponse à la question déjà posée : **annuler une `client_order`** (« unpicked » ne convient pas — la boutique produirait quand même)
- [ ] Sens de `po_hash` et `id_transaction` (jamais envoyés en attendant)
- [ ] **API clients finaux** (CRUD + rattachement boutique) + décision : **où vit le mot de passe** (l'ERP n'en veut pas — prévoir une table d'auth locale sinon)
- [ ] **API bons/promotions** (lecture + rachat) — sinon les écritures directes `voucher_*`/`promotion` restent
- [ ] **Catalogue** : photos en lot / URL stable + `allergens_evaluated` (évaluée-sans-allergène ≠ non renseignée)
- [ ] Endpoint **départements B2B** (`b2b_client_company_department`)
- [ ] Endpoints **fidélité** (tickets — remplacent `pwa_purchases`/`pwa_purchase_items`/`pwa_invoices`)
- [ ] Colonnes boutique webshop dans `GET /shops` (`webshop_enabled`, langues, `landing_config`)

## Vague 3 — drops côté webshop, au fil des livraisons (moi)

- [ ] `ws_orders` + `ws_order_lines` → lecture ERP (suivi client, BO tournées/préparation, stats, factures, notes) puis drop
- [ ] Les ~90 lectures/écritures SQL de `client` → API clients ; créer la table d'auth locale si l'ERP ne porte pas le mot de passe
- [ ] `voucher_*`/`promotion*` en SQL → API bons ; câbler `id_transaction` sur la commande ERP (aujourd'hui NULL)
- [ ] Migrer les **porteurs de menus** en produits ERP (préalable obligatoire — ids locaux dans l'espace ERP)
- [ ] Réplica catalogue (`ws_products`, `ws_categories`, `ws_category_subs`, `ws_product_shops`, `ws_product_allergens`, `ws_tags`) → pass-through pur, puis drop
- [ ] `product_portion`/`shop_product_portion_price` en SQL → API portions (meurt avec le réplica)
- [ ] `pwa_purchases`/`pwa_purchase_items`/`pwa_invoices` → endpoint fidélité

## Vague 4 — décisions produit (toi + Szymon) — sans décision, ces tables RESTENT

- [ ] Bureaux B2B (10 tables, `ws_offices`…) : l'ERP absorbe le métier, ou périmètre webshop assumé ?
- [ ] Tournées & zones (8 tables, `ws_tours`…) : idem
- [ ] Créneaux & calendrier (5 tables) : idem
- [ ] Stock du jour & réservations (3 tables) : idem — l'ERP n'a pas de stock jour×canal
- [ ] Frais de livraison (`ws_delivery_fee_rules`) : lié aux champs livraison de la commande ERP
- [ ] Menus/formules (4 tables `ws_bundle*`) : lié à la migration des porteurs
- [ ] Cross-sell (6), promo cumulée→cadeau (3), avis (3) : garder, faire absorber, ou abandonner la fonctionnalité
- [ ] `ws_i18n` : basculer vers des fichiers statiques versionnés (pas besoin de l'ERP)
- [ ] **Assumer noir sur blanc : ERP en panne = vente coupée (503)** — c'est la contrepartie du zéro-table

## Vague 5 — état de service (en dernier, ou jamais)

- [ ] `bo_*` (auth back-office) : déplacer vers les employés ERP, ou garder
- [ ] `ws_stripe_event` (idempotence webhook — critique), `ws_rate_limit`, `ws_param`, `ws_shop_device_token`, `ws_incidents`, `ws_bo_store`, `auth_handoff` : vivent tant que `php-api/` vit
