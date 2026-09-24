-- 0131 — Titre des suggestions du Panier Croisé sur la FICHE PRODUIT.
-- Le panier dit « Pour accompagner » : sur une fiche produit, rien n'accompagne
-- encore rien, le client regarde un produit qu'il n'a pas pris. D'où un titre
-- propre à cet emplacement. IDEMPOTENTE : INSERT IGNORE.
INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'xsell.titleProduct', 'fr', 'Souvent commandé avec'),
  ('ui', 'xsell.titleProduct', 'nl', 'Vaak samen besteld');
