-- 0061 — Aligner client.preferred_shop_id sur id_main_shop quand il est VIDE.
--
-- Deux colonnes désignent la boutique d'un client :
--   • id_main_shop        — rattachement ERP historique ;
--   • preferred_shop_id   — boutique choisie par le client (webshop), ajoutée
--                           plus tard par alter-client-webshop-auth.sql.
--
-- La console franchisé cloisonne sur preferred_shop_id dès que la colonne
-- existe. Les clients créés AVANT elle — et ceux créés depuis par des chemins
-- qui ne renseignent que id_main_shop, comme /franchisee/route-office — ont donc
-- preferred_shop_id à NULL : ils n'apparaissent dans AUCUNE boutique, et depuis
-- que les écritures suivent la même règle, ils ne sont plus modifiables non
-- plus. Un client rattaché en base, invisible et intouchable à l'écran.
--
-- Cette migration ne fait que RECOPIER un rattachement déjà présent. Elle
-- n'invente aucune boutique :
--   • preferred_shop_id déjà renseigné → intact, le choix du client fait foi ;
--   • id_main_shop vide, nul, ou pointant sur une boutique inexistante →
--     laissé à NULL. Un client sans rattachement RESTE sans rattachement, et
--     l'écran le montrera plutôt que de le ranger arbitrairement quelque part.
--
-- Idempotente : rejouée, elle ne trouve plus rien à faire.

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'client'
            AND column_name = 'preferred_shop_id')
  AND EXISTS(SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'client'
                AND column_name = 'id_main_shop')
  AND EXISTS(SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'shops'),
  'UPDATE client c
      JOIN shops s ON s.id = c.id_main_shop
       SET c.preferred_shop_id = c.id_main_shop
     WHERE c.preferred_shop_id IS NULL
       AND c.id_main_shop IS NOT NULL
       AND c.id_main_shop > 0',
  'DO 0'
));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Et pour que le trou ne se recreuse pas ───────────────────────────────────
-- AUCUN des six chemins de création de client ne renseigne preferred_shop_id :
-- inscription webshop, commande invité, devis B2B, aiguillage franchisé,
-- onboarding bureau, import. Rattraper l'existant sans traiter la création
-- reviendrait à refaire cette migration tous les mois.
--
-- La règle est portée par un TRIGGER plutôt que recopiée en six endroits : elle
-- couvre aussi les écritures que le code PHP ne fait pas — synchronisation ERP,
-- import SQL, correction à la main. Un défaut par défaut, jamais une écrasure :
-- il ne s'applique QUE si preferred_shop_id est laissé vide.

DROP TRIGGER IF EXISTS trg_client_preferred_shop_bi;

DELIMITER $$

CREATE TRIGGER trg_client_preferred_shop_bi BEFORE INSERT ON client
FOR EACH ROW
BEGIN
  IF NEW.preferred_shop_id IS NULL AND NEW.id_main_shop IS NOT NULL AND NEW.id_main_shop > 0 THEN
    SET NEW.preferred_shop_id = NEW.id_main_shop;
  END IF;
END$$

DELIMITER ;

-- Volontairement PAS de trigger sur UPDATE : preferred_shop_id est la boutique
-- CHOISIE par le client. La remplir d'office à chaque modification empêcherait
-- de la changer, et écraserait ce choix par le rattachement ERP. Les rares
-- déplacements voulus (aiguillage /franchisee/route-office) sont explicites
-- dans le code.

-- Contrôle après passage — ce qui reste sans boutique est RÉEL, pas un oubli :
--   SELECT COUNT(*) AS sans_boutique FROM client
--    WHERE active = 1 AND preferred_shop_id IS NULL;
--   SELECT preferred_shop_id, COUNT(*) FROM client
--    WHERE active = 1 GROUP BY preferred_shop_id ORDER BY 2 DESC;
