-- 0040 — Catégorie automatique (sens SANS RISQUE uniquement) : une catégorie
-- encore active alors que PLUS AUCUN de ses produits n'est en ligne se
-- désactive. Le sens montant (réactivation) n'est PAS appliqué en masse —
-- il ne se fait qu'au fil des bascules produits (une catégorie coupée
-- volontairement, ex. « B2B », ne doit pas ressusciter d'un bloc). Idempotent.
UPDATE ws_categories c
   SET c.active = 0
 WHERE c.active = 1
   AND NOT EXISTS (SELECT 1 FROM ws_products p WHERE p.cat_id = c.id AND p.active = 1);
