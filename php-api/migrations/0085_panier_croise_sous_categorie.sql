-- 0085 — Panier Croisé : déclencheur au grain SOUS-catégorie (demande du 23/08).
--
-- « Catégorie · Pâtisserie » déclenchait trop large : la marque veut viser
-- « Tartes », pas toute la Pâtisserie. Colonne ADDITIVE : les déclencheurs
-- existants (product_id / cat_id) continuent de se déclencher à l'identique ;
-- l'écran marque n'AJOUTE désormais que des sous-catégories. AUCUN seed.

ALTER TABLE ws_cross_sell_trigger
  ADD COLUMN sub_id INT NULL DEFAULT NULL COMMENT 'ws_category_subs.id — declencheur sous-categorie';
