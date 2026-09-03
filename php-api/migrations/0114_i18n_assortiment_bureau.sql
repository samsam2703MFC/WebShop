-- 0114 — Libellés du webshop pour un bureau à prix masqués / assortiment réduit (fr + nl).
-- IDEMPOTENTE : INSERT IGNORE.
INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'cart.officeCovered', 'fr', 'Pris en charge par votre bureau.'),
  ('ui', 'cart.officeCovered', 'nl', 'Ten laste van uw kantoor.'),
  ('ui', 'cart.officeNoPay',   'fr', 'Aucun paiement : {name} est facturé selon son contrat.'),
  ('ui', 'cart.officeNoPay',   'nl', 'Geen betaling: {name} wordt volgens contract gefactureerd.'),
  ('ui', 'catalog.officeSelection', 'fr', 'Sélection de votre bureau · {count} produits'),
  ('ui', 'catalog.officeSelection', 'nl', 'Selectie van uw kantoor · {count} producten');
