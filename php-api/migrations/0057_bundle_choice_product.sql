-- 0057 — Un choix de menu EST un produit : ws_bundle_slot_choices.product_id.
--
-- Les choix ne portaient qu'un `label` en texte libre. Tout le reste de la
-- chaîne devait donc deviner le produit par son NOM :
--   • la vignette du choix (catalogue) le cherchait par nom ;
--   • la commande, depuis la migration 0055, écrivait une ligne par choix et
--     ne trouvait aucun produit dès que le libellé était générique
--     (« Boisson », « Dessert au choix ») ou seulement approchant.
-- Résultat : des lignes de menu enregistrées avec product_id NULL — la boutique
-- voit quoi préparer, mais rien ne relie ce choix au catalogue : ni stock, ni
-- prix, ni allergènes, ni statistiques.
--
-- L'identifiant devient donc la donnée portée, le libellé n'étant plus qu'un
-- affichage. NULL reste toléré (choix historiques non encore rattachés), et la
-- résolution par nom subsiste en REPLI — mais elle cesse d'être la règle.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_bundle_slot_choices'
            AND column_name = 'product_id'),
  'DO 0',
  'ALTER TABLE ws_bundle_slot_choices ADD COLUMN product_id INT NULL DEFAULT NULL COMMENT ''Produit du catalogue — donnee portee par le choix ; le label nest quun affichage'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql2 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'ws_bundle_slot_choices'
            AND index_name = 'idx_choice_product'),
  'DO 0',
  'CREATE INDEX idx_choice_product ON ws_bundle_slot_choices (product_id)'
));
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- Reprise de l'existant : on rattache UNIQUEMENT les choix dont le libellé
-- correspond EXACTEMENT à un produit actif, et à un seul. La collation de la
-- base ignore déjà casse et accents. Aucun rapprochement approximatif : faire
-- préparer le mauvais produit coûte plus cher que de laisser le champ vide, que
-- le back-office signalera.
UPDATE ws_bundle_slot_choices c
   SET c.product_id = (
     SELECT p.id FROM ws_products p
      WHERE p.name = c.label AND p.active = 1
      LIMIT 1)
 WHERE c.product_id IS NULL
   AND (SELECT COUNT(*) FROM ws_products p2 WHERE p2.name = c.label AND p2.active = 1) = 1;
