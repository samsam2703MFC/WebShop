-- ============================================================================
-- migrate-unify-shops-phase4.sql  —  PHASE 4 : suppression des tables legacy
-- ⚠️ DESTRUCTIF ET IRRÉVERSIBLE. À ne jouer qu'après une période de stabilisation
--    (Phases 1–3 en prod, code recâblé, sauvegarde complète prise).
-- ⚠️ Après cette phase, plus de rollback possible vers ws_shops/lp_shops d'origine.
--
-- Les VUES ws_shops / lp_shops (Phase 3) restent en place tant que du code les lit.
-- Ne supprimer QUE les tables *_legacy.
-- ============================================================================

-- Vérifie qu'une sauvegarde existe et que rien ne casse AVANT de décommenter.
DROP TABLE IF EXISTS ws_shops_legacy;
DROP TABLE IF EXISTS lp_shops_legacy;

-- Optionnel (quand tout le code lit `shops` directement, plus aucune vue nécessaire) :
--   DROP VIEW IF EXISTS ws_shops;
--   DROP VIEW IF EXISTS lp_shops;
