# Unification des bons (vouchers) — notes de migration

But : `voucher_campaign` / `voucher_code` deviennent la **seule source de vérité** ;
`ws_vouchers` (webshop) est absorbé puis remplacé par une **vue de compat**.

## Fichiers

| Ordre | Fichier | Rôle | Auto-appliqué par `migrate.sh` |
|---|---|---|---|
| 1 | `0005_voucher_unify_schema.sql` | Schéma : dimensions + tables + FK | ✅ (au déploiement) |
| 2 | `0006_voucher_unify_data.sql` | Migration des 2 bons (transaction, idempotent) | ✅ |
| 3 | `0007_ws_vouchers_compat_view.sql` | Rename legacy + VUE `ws_vouchers` | ✅ (déployé AVEC le code Étape 4) |
| — | `rollback/000{5,6,7}_*_rollback.sql` | Retour arrière (jouer 7→6→5) | ❌ (sous-dossier ignoré) |

> ✅ **Étape 4 livrée** : le code webshop écrit désormais via le modèle ERP
> (`voucher_code.usage_count` + `voucher_redemption`) et l'upsert franchisor cible
> `promotion/voucher_campaign/voucher_code` ; `free_delivery` offre réellement le port.
> `0007` (bascule en vue) est donc déployé **en même temps** que ce code.

`migrate.sh` applique chaque `migrations/*.sql` **une fois** (suivi `ws_schema_migrations`),
dans l'ordre des noms. **Un merge sur `main` déclenche l'application en prod** via
`deploy-sftp.yml`. Ne merger qu'après validation.

## Décisions & mapping

- **Boutique** : `ws_shops.id = franchisee_shop.id` (identité confirmée — Halle=4 des deux côtés).
  `voucher_campaign.id_shop` = `franchisee_shop.id` ; `NULL` = réseau.
- **Marque** : hypothèse `brand.id = 1 = ws_brands.id = 1` (tout `franchisee_shop.id_brand=1`).
  FK `id_brand → brand` **non auto-appliquée** (mapping non confirmé) → fournie dans
  `optional/0005b_voucher_brand_fk.sql`, à jouer à la main après vérif `brand.id=1`.
- **Remise niveau commande** : le moteur `promotion` est produit-centré (BXGY/bundle/
  remise produit planifiée) → **nouveau satellite `promotion_order_discount`** + type
  `promotion.promotion_type = 'ORDER_DISCOUNT'`. `min_order` (€) y vit (`min_order_amount`).
- **Types** : `percent→PERCENT`, `fixed→FIXED`, `free_delivery→FREE_DELIVERY`.
- **used_count** → `voucher_code.usage_count`. **Aucune** ligne `voucher_redemption`
  fabriquée (historique non reconstructible).
- **3ᵉ système `promo_code`** (code panier amount/percent + `min_order_value`, sans
  free_delivery, non relié à `promotion`) : **hors périmètre** de ce brief, noté pour
  convergence future.

## Requêtes de vérification post-migration

```sql
-- 1) Les 2 bons existent dans le modèle unifié (attendu : 2).
SELECT COUNT(*) AS codes_ws
FROM voucher_code vco
JOIN voucher_campaign_channel vcc ON vcc.id_voucher_campaign=vco.id_voucher_campaign AND vcc.channel='WS'
WHERE vco.code IN ('BIENVENUE10','LIVRAISONOFF');

-- 2) Chaîne complète par code (attendu : 1 ligne par code, aucune colonne NULL de jointure).
SELECT vco.code, p.promotion_type, pod.discount_kind, pod.discount_value, pod.min_order_amount,
       vc.id_brand, vc.id_shop, vc.usage_limit_total, vco.usage_count, vco.valid_to
FROM voucher_code vco
JOIN voucher_campaign vc          ON vc.id=vco.id_voucher_campaign
JOIN promotion p                  ON p.id=vc.id_promotion
JOIN promotion_order_discount pod ON pod.id_promotion=p.id
JOIN voucher_campaign_channel vcc ON vcc.id_voucher_campaign=vc.id AND vcc.channel='WS'
WHERE vco.code IN ('BIENVENUE10','LIVRAISONOFF');

-- 3) La vue reprojette fidèlement l'ancien contrat (attendu : mêmes valeurs qu'avant).
SELECT * FROM ws_vouchers ORDER BY code;

-- 4) Cohérence vue ↔ legacy (attendu : 0 divergence sur type/value/min_order/max_uses/used_count/expires).
SELECT l.code,
       (v.type<=>l.type) AS ok_type, (v.value<=>l.value) AS ok_value,
       (v.min_order<=>l.min_order) AS ok_min, (v.max_uses<=>l.max_uses) AS ok_max,
       (v.used_count<=>l.used_count) AS ok_used, (v.expires_at<=>l.expires_at) AS ok_exp
FROM ws_vouchers_legacy l
JOIN ws_vouchers v ON v.code=l.code
WHERE l.shop_id IS NULL;   -- bons réseau (les 2 concernés)

-- 5) Unicité des codes entre systèmes : un code ne doit pas diverger entre legacy et modèle.
SELECT code, COUNT(*) FROM (
  SELECT code FROM voucher_code
  UNION ALL SELECT code FROM ws_vouchers_legacy
) t GROUP BY code HAVING COUNT(*) > 2;   -- >2 ⇒ doublon anormal

-- 5-bis) Collision éventuelle avec le 3ᵉ système promo_code (informatif).
SELECT code FROM promo_code WHERE code IN (SELECT code FROM voucher_code);

-- 6) Canaux orphelins (attendu : 0).
SELECT vcc.* FROM voucher_campaign_channel vcc
LEFT JOIN voucher_campaign vc ON vc.id=vcc.id_voucher_campaign WHERE vc.id IS NULL;

-- 7) Idempotence : relancer 0006 ne crée pas de doublon (attendu : 1 par code).
SELECT code, COUNT(*) FROM voucher_code GROUP BY code HAVING COUNT(*)>1;
```

## Étape 4 — plan de modif du code webshop (PROPOSITION, non appliquée)

Cibles actuelles de `ws_vouchers` (à basculer sur le modèle ERP). En lecture, la **vue**
laisse tourner l'existant ; les **écritures** doivent changer (une vue n'est pas inscriptible).

| Fichier:ligne | Aujourd'hui | Proposé |
|---|---|---|
| `index.php:312` `POST /vouchers/redeem` | `SELECT … FROM ws_vouchers WHERE code=…` | Lire via `voucher_code`+`voucher_campaign`+`promotion_order_discount` **filtré canal WS** (`voucher_campaign_channel.channel='WS'`), + validité (`voucher_code.status`, `valid_to`, `usage_count<usage_limit`). Calcul remise identique (percent/fixed ; free_delivery → cf. ci-dessous). |
| `index.php:650` (commande) | idem lecture | idem lecture ERP. |
| `index.php:809` `UPDATE ws_vouchers SET used_count=used_count+1` | incrément direct | **Créer une `voucher_redemption`** (`request_key` = clé idempotente de la commande, `status='CONFIRMED'`, `channel='WS'`, `id_shop`=shop de la commande, `discount_value`) **et** `UPDATE voucher_code SET usage_count=usage_count+1`. Transaction avec la commande. |
| `index.php:789/796` (ws_orders) | stocke `voucher_code`/`voucher_discount` | inchangé (garde la trace sur la commande). |
| `index.php:1573` `POST /franchisor/voucher` (upsert) | `INSERT … ws_vouchers` | Upsert `promotion(+order_discount)` → `voucher_campaign(SHARED, id_brand, id_shop)` → `voucher_code` → `voucher_campaign_channel('WS')`. |
| `index.php:1427` `GET /franchisor/vouchers` | `SELECT … ws_vouchers` | Lire la **vue** `ws_vouchers` (aucun changement) ou directement le modèle. |
| `bo/routes.php:302` liste BO | `SELECT … ws_vouchers` | idem : vue OK en lecture. |
| Cron (DATABASE.md) `UPDATE ws_vouchers SET active=FALSE WHERE expires_at<NOW()` | sur table | Retirer (l'« expiration » = `valid_to`/`status`, calculé) ou pointer `ws_vouchers_legacy`. |

### ⚠️ `free_delivery` : no-op aujourd'hui
Le code webshop n'applique **pas** `free_delivery` (redeem/commande renvoient remise 0 ; la
gratuité de port vit dans `ws_delivery_fee_rules`). Après unification, `LIVRAISONOFF` reste
donc **sans effet** tant que l'Étape 4 ne branche pas `discount_kind='FREE_DELIVERY'` sur la
logique de frais (`$isFree`) au moment de la commande. À décider : rendre le port réellement
offert, ou garder l'existant.

## Limites connues
- La vue `ws_vouchers` n'est pas inscriptible : les écritures actuelles échoueront tant que
  l'Étape 4 n'est pas faite. Séquencer : (0005+0006+0007) → déployer le code Étape 4 → retirer legacy plus tard.
- La vue n'expose que les bons `ORDER_DISCOUNT` du canal WS ; les bons à mécanique produit
  n'y apparaissent pas (voulu).
- `ws_vouchers_legacy` est conservé comme filet — **ne pas supprimer**.
