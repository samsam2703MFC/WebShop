-- 0055 — Lignes de commande : composants de menu rattachés à leur ligne mère.
--
-- Une commande contenant un menu n'enregistrait QUE le produit déclencheur.
-- La composition — formule choisie, contenu de chaque emplacement — était
-- envoyée par le panier puis jetée par le serveur : la boutique voyait
-- « Menu Midi » sans savoir quoi préparer, et le client ne pouvait pas
-- vérifier sa commande.
--
-- Chaque choix devient donc une LIGNE à part entière, rattachée à sa ligne
-- mère par parent_line_id. NULL = ligne vendue normalement (tout l'existant,
-- donc rien ne change pour les commandes déjà passées).
--
-- Pourquoi une colonne plutôt que des lignes indifférenciées : les écrans
-- comptent COUNT(*) et SUM(qty) sur cette table. Sans marqueur, un menu à
-- trois composants compterait pour quatre pièces vendues, faussant la charge,
-- la capacité des créneaux et le panier moyen. Avec la colonne, chaque écran
-- choisit : les compteurs COMMERCIAUX ignorent les composants (ils sont
-- inclus dans le prix du menu), les écrans de PRODUCTION les comptent (il
-- faut bien les fabriquer).

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_order_lines'
            AND column_name = 'parent_line_id'),
  'DO 0',
  'ALTER TABLE ws_order_lines ADD COLUMN parent_line_id INT NULL DEFAULT NULL COMMENT ''Ligne mere quand cette ligne est un composant de menu — NULL = ligne vendue'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql2 := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'ws_order_lines'
            AND index_name = 'idx_oline_parent'),
  'DO 0',
  'CREATE INDEX idx_oline_parent ON ws_order_lines (parent_line_id)'
));
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;
