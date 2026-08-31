# Runbook — Vague 1 : activer & vérifier en production

> Mode d'emploi pas-à-pas des 5 cases « Vague 1 » de `CHECKLIST_ZERO_TABLE.md`.
> Chaque étape = un geste + un CONTRÔLE + un feu vert/rouge + le rollback.
> Les SQL se collent dans phpMyAdmin (base du webshop). **Ne passe à l'étape
> suivante que si le contrôle est vert.**
>
> Rappel : la Vague 1 ne droppe RIEN à la main — les suppressions (0105) sont
> appliquées automatiquement par `migrate.sh` au déploiement, une seule fois.

---

## Étape 0 — Sauvegarde (obligatoire, comme toujours)

phpMyAdmin → base du webshop → **Exporter** → SQL avec DROP TABLE → garde le
fichier. C'est le filet pour TOUTE la vague.

**Feu vert :** le `.sql` est sur ton disque.

---

## Étape 1 — Déployer la branche (les migrations 0103 → 0105 partent avec)

Le workflow déploie sur push **`main`** : merge la branche
`claude/client-orders-post-endpoint-pk5azl` dans `main` (PR ou merge direct),
push → rsync + `migrate.sh` s'exécutent.

**Contrôle** (phpMyAdmin) :

```sql
SELECT version, applied_at FROM ws_schema_migrations
 WHERE version IN ('0103_order_line_portion_id.sql',
                   '0104_orders_erp_link.sql',
                   '0105_drop_vestiges.sql');

-- Les 4 vestiges doivent avoir disparu (doit rendre 0 / 0) :
SELECT (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('ws_customers','ws_product_prices',
                              'ws_product_availability','ws_category_availability')) AS tables_restantes,
       (SELECT COUNT(*) FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE()
           AND referenced_table_name IN ('ws_customers','ws_product_prices',
                                         'ws_product_availability','ws_category_availability')) AS fk_restantes;
```

**Feu vert :** 3 lignes dans `ws_schema_migrations`, `tables_restantes = 0`,
`fk_restantes = 0`, et le workflow GitHub est vert.
**Feu rouge :** le workflow est rouge → lire la sortie de `migrate.sh` (la
migration en échec n'est PAS marquée appliquée : corrigeable et rejouable).

---

## Étape 2 — Vérifier le P0 : quels prix ont été servis jusqu'ici ?

`erp_catalog.php` n'était pas branché avant ce déploiement : même si
`catalog_source` valait `'erp'`, les prix servis étaient les LOCAUX. À
mesurer sur les commandes récentes :

```sql
-- Le paramètre était-il posé ? (ligne absente ou vide = non — moins grave)
SELECT param_key, param_value FROM ws_param
 WHERE param_key IN ('catalog_source','orders_push','erp_api_base');

-- Signal rouge n°1 : des lignes récentes facturées 1,00 € (le « remplissage »)
SELECT COUNT(*) AS lignes_a_1_euro
  FROM ws_order_lines l JOIN ws_orders o ON o.id = l.order_id
 WHERE l.unit_price = 1.00 AND o.created_at >= NOW() - INTERVAL 30 DAY;

-- Signal rouge n°2 : pièces entières récentes facturées AU PRIX LOCAL
SELECT o.order_ref, o.created_at, l.product_name, l.qty,
       l.unit_price AS facture, p.price AS prix_local,
       CASE WHEN ABS(l.unit_price - p.price) < 0.005
            THEN 'PRIX LOCAL SERVI' ELSE 'autre source (ERP probable)' END AS verdict
  FROM ws_order_lines l
  JOIN ws_orders  o ON o.id = l.order_id
  JOIN ws_products p ON p.id = l.product_id
 WHERE (l.`portion` IS NULL OR l.`portion` IN ('','entier'))
   AND (l.parent_line_id IS NULL)
   AND o.created_at >= NOW() - INTERVAL 14 DAY
 ORDER BY o.created_at DESC LIMIT 100;
```

**Feu vert :** `lignes_a_1_euro = 0` et pas de « PRIX LOCAL SERVI » aberrant.
**Feu rouge :** des commandes à 1,00 € (ou très sous-facturées) → liste-les et
décide du geste commercial (remboursement/contact) AVANT de communiquer ;
techniquement, l'étape 3 stoppe l'hémorragie.

---

## Étape 3 — Activer le catalogue/prix ERP

```sql
INSERT INTO ws_param (param_key, param_value) VALUES ('catalog_source','erp')
  ON DUPLICATE KEY UPDATE param_value = 'erp';
```

**Contrôles** (dans la minute) :
1. `https://<ton-domaine>/api/erp/probe` → la liaison doit être « configurée +
   jointe » ; `erp_notes` vide.
2. Ouvre le webshop : les prix affichés = les prix Franchise Buddy (Coca 50 cl
   à 2,80 €, plus jamais 1,00 €). Produits sans prix ERP : absents de la carte
   (voulu).
3. Passe une commande test au comptoir : le total doit refléter le prix ERP.

**Feu vert :** prix ERP à l'écran ET au ticket.
**Feu rouge :** catalogue en 503 permanent → l'ERP est injoignable depuis le
serveur (base/jeton dans `ws_param.erp_api_base` / `erp_api_token`, ou
reconnexion `erp_auth_phone`/`erp_auth_password`). **Rollback :**

```sql
UPDATE ws_param SET param_value = '' WHERE param_key = 'catalog_source';
```

---

## Étape 4 — Activer la remontée des commandes vers l'ERP

Pré-requis : la route `POST /api/v1/client-orders` doit être DÉPLOYÉE côté
Franchise Buddy (demande à Szymon si besoin). Test rapide depuis le serveur :

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST "<ERP_BASE>/client-orders" \
  -H "Authorization: Bearer <JETON>" -H "Content-Type: application/json" -d '{}'
# attendu : 400 (NO_PRODUCTS_FOR_ORDER) = la route existe. 404 = pas déployée.
```

Puis :

```sql
INSERT INTO ws_param (param_key, param_value) VALUES ('orders_push','erp')
  ON DUPLICATE KEY UPDATE param_value = 'erp';
```

**Contrôle** après la première commande confirmée (comptoir/sur-compte :
immédiat ; Stripe : après l'encaissement) :

```sql
SELECT id, order_ref, status, payment_status, erp_order_id, erp_push_error
  FROM ws_orders ORDER BY id DESC LIMIT 10;
```

**Feu vert :** `erp_order_id` rempli, `erp_push_error` NULL — et la commande
visible dans Franchise Buddy (statut « new »).
**Feu rouge :** `erp_push_error` parlant — `HTTP 404` = route ERP absente ;
`HTTP 422` = portion invalide/sans prix boutique ; `HTTP 401` = jeton. Rien
n'est perdu : le cron (étape 5) repousse dès que la cause est levée.
**Rollback :**

```sql
UPDATE ws_param SET param_value = '' WHERE param_key = 'orders_push';
```

---

## Étape 5 — Le cron de reprise

Sur l'hébergement (cPanel → tâches cron, ou crontab) :

```cron
*/5 * * * *  php /chemin/vers/api/cron/erp-orders-push.php >> /var/log/ws-erp-push.log 2>&1
```

**Contrôle :** lance-le une fois à la main —
`php php-api/cron/erp-orders-push.php` doit répondre
« remontée ERP : X poussée(s), Y échec(s), Z en attente d'encaissement. »
(ou « remontée inactive » tant que l'étape 4 n'est pas faite).

**Feu vert :** la ligne de bilan s'écrit dans le log toutes les 5 minutes.

---

## Surveillance des premiers jours (une requête, matin et soir)

```sql
-- Commandes confirmées PAS ENCORE côté ERP + leur motif
SELECT order_ref, created_at, payment_method, payment_status, erp_push_error
  FROM ws_orders
 WHERE erp_order_id IS NULL AND status <> 'cancelled'
   AND created_at >= NOW() - INTERVAL 3 DAY
 ORDER BY id DESC;
```

Vide (hors Stripe non payés) = tout part. Ensuite : cocher la Vague 1 dans
`CHECKLIST_ZERO_TABLE.md`, et la suite se joue chez Szymon (Vague 2).
