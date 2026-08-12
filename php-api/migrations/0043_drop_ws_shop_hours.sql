-- 0043 — Suppression de la table morte `ws_shop_hours`.
-- La disponibilité boutique (jours/heures/durée créneau/cut-off/lead) est
-- pilotée par `ws_shop_availability` — SEULE table lue par le webshop et
-- désormais éditée directement par le BO franchisé (GET/POST shop-availability).
-- `ws_shop_hours` n'était référencée par AUCUNE requête (juste un libellé de
-- schéma dans le BO) : le BO écrivait en réalité dans ws_param (déconnecté),
-- ce câblage a été supprimé. IF EXISTS -> no-op si la table n'existe pas.
DROP TABLE IF EXISTS ws_shop_hours;
