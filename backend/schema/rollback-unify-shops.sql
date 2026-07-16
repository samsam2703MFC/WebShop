-- ============================================================================
-- rollback-unify-shops.sql
-- Annule la migration d'unification des boutiques, par phase.
-- ============================================================================

-- ── Rollback PHASE 1 (create + populate `shops`) ────────────────────────────
-- Phase 1 est NON destructive (ws_shops / lp_shops intacts, aucune FK modifiée) :
-- il suffit de supprimer la table cible.
DROP TABLE IF EXISTS shops;

-- ── Rollback PHASE 2 (re-câblage FK) — si Phase 2 a été jouée ───────────────
-- Phase 2 repointe les FK des 21 tables ws_ (shop_id) + lp_shop_hours/services vers shops.
-- Rollback : re-pointer ces FK vers ws_shops / lp_shops (les valeurs shop_id des
-- lignes ws sont inchangées car shops.id == ws_shops.id ; pour les lignes lp, restaurer
-- shop_id = legacy_lp_id AVANT de re-créer la FK vers lp_shops). Détail livré avec la Phase 2.

-- ── Rollback PHASE 3 (vues de compat + bascule code) ───────────────────────
-- Phase 3 renomme ws_shops→ws_shops_legacy / lp_shops→lp_shops_legacy et crée des VUES
-- ws_shops/lp_shops sur `shops`. Rollback : DROP VIEW ws_shops; DROP VIEW lp_shops;
-- RENAME TABLE ws_shops_legacy TO ws_shops, lp_shops_legacy TO lp_shops;

-- NB : les anciennes tables ne sont PHYSIQUEMENT supprimées qu'en Phase 4 (migration
-- séparée), donc tant que Phase 4 n'est pas jouée, tout rollback est complet et sûr.
