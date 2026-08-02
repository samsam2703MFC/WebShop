-- 0054 — Seuil d'alerte stock PAR PRODUIT (et par boutique).
--
-- L'alerte « sous seuil » comparait chaque produit au même nombre :
-- ws_param('stock.default_min_threshold') = 10 pour tout le catalogue. Une
-- baguette et un gâteau d'anniversaire déclenchaient donc la même alerte à
-- 10 pièces.
--
-- Conséquence : l'alerte hurle en permanence sur les produits à forte rotation,
-- et reste muette sur ceux qu'on ne fait qu'à quelques exemplaires — exactement
-- ceux où la rupture coûte cher. Une alerte qui se trompe tout le temps finit
-- par ne plus être lue, ce qui est pire que pas d'alerte du tout.
--
-- Le seuil vit dans ws_product_shops : c'est une décision d'EXPLOITATION, propre
-- à chaque boutique. La même tarte n'a pas le même rythme à Corbais et à
-- Louvain-la-Neuve.
--
-- NULL = pas de seuil propre → le paramètre global s'applique, comme avant. La
-- colonne est donc additive : rien ne change tant que personne ne la renseigne.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'ws_product_shops'
            AND column_name = 'min_threshold'),
  'DO 0',
  'ALTER TABLE ws_product_shops ADD COLUMN min_threshold INT NULL DEFAULT NULL COMMENT ''Seuil alerte stock bas — NULL = parametre global'''
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
