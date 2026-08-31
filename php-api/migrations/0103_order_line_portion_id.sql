-- 0103 — Lignes de commande : identifiant ERP de la portion vendue.
--
-- Le checkout RÉSOUT déjà la portion ERP pour la tarifer (erp_portion_options,
-- clé pp_id = product_portion.id) puis n'en gardait que le LIBELLÉ (« quart »,
-- « demi »). Or la remontée des commandes vers l'ERP (POST /api/v1/client-orders,
-- table client_order_product) attend id_product_portion — l'identifiant
-- numérique. Re-déduire l'id depuis le libellé au moment de la remontée est
-- fragile : une portion désactivée ou recréée entre la vente et la remontée ne
-- se retrouve plus, et l'ERP répond 422. On fige donc l'id AU MOMENT DE LA
-- VENTE, à côté du libellé (conservé pour l'affichage). NULL = pièce entière,
-- ou ligne sans portion (composant de menu, cadeau).

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_order_lines'
            AND column_name = 'portion_id'),
  'DO 0',
  'ALTER TABLE ws_order_lines ADD COLUMN portion_id INT NULL DEFAULT NULL COMMENT ''Portion ERP vendue (product_portion.id) — NULL = piece entiere ou ligne sans portion'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Reprise de l'existant : les lignes déjà vendues avec une portion retrouvent
-- leur id ERP par le MÊME vocabulaire que la vente (erp_portion_options —
-- one_half/demi/1/2, etc.). Sous garde : la table ERP peut manquer dans un
-- environnement local — la colonne reste alors NULL et rien ne casse.
SET @sql2 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'product_portion'),
  'UPDATE ws_order_lines l
     JOIN (SELECT id_product,
                  CASE LOWER(TRIM(portion_type))
                    WHEN ''one_half''    THEN ''demi''     WHEN ''half''    THEN ''demi''
                    WHEN ''demi''        THEN ''demi''     WHEN ''1/2''     THEN ''demi''
                    WHEN ''one_quarter'' THEN ''quart''    WHEN ''quarter'' THEN ''quart''
                    WHEN ''quart''       THEN ''quart''    WHEN ''1/4''     THEN ''quart''
                    WHEN ''one_eighth''  THEN ''huitieme'' WHEN ''eighth''  THEN ''huitieme''
                    WHEN ''huitieme''    THEN ''huitieme'' WHEN ''1/8''     THEN ''huitieme''
                  END AS v,
                  MIN(id) AS pp_id
             FROM product_portion
            GROUP BY id_product, v) pp
       ON pp.id_product = l.product_id
      AND pp.v IS NOT NULL
      AND pp.v = LOWER(TRIM(l.`portion`))
      SET l.portion_id = pp.pp_id
    WHERE l.portion_id IS NULL',
  'DO 0'
));
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;
