-- 0085 — Panier Croisé : déclencheur au grain SOUS-catégorie (demande du 23/08).
--
-- « Catégorie · Pâtisserie » déclenchait trop large : la marque veut viser
-- « Tartes », pas toute la Pâtisserie. Colonne ADDITIVE : les déclencheurs
-- existants (product_id / cat_id) continuent de se déclencher à l'identique ;
-- l'écran marque n'AJOUTE désormais que des sous-catégories. AUCUN seed.

-- IDEMPOTENTE + tolère l'absence de la table (garde information_schema, comme
-- 0081/0085_ticket). Sans elle, l'ADD COLUMN nu échouait au REJEU (2ᵉ passe CI /
-- redéploiement : « Duplicate column name 'sub_id' ») et sur toute base sans
-- ws_cross_sell_trigger. Sur la prod, où la table existe et sub_id manque, l'ADD
-- s'exécute exactement comme avant.
SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_cross_sell_trigger');
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_cross_sell_trigger' AND column_name = 'sub_id');
SET @s := IF(@t = 0 OR @c > 0, 'DO 0',
  "ALTER TABLE ws_cross_sell_trigger
     ADD COLUMN sub_id INT NULL DEFAULT NULL COMMENT 'ws_category_subs.id — declencheur sous-categorie'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
