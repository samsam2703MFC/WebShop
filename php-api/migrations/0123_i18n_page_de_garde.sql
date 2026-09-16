-- 0123 — Libellés de la page de garde du webshop (catégories illustrées à
-- l'arrivée, avant la grille). fr + nl, comme les autres libellés d'interface.
-- IDEMPOTENTE : INSERT IGNORE — une valeur éditée après coup n'est jamais écrasée.
INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'cover.title',  'fr', 'Que souhaitez-vous commander ?'),
  ('ui', 'cover.title',  'nl', 'Wat wilt u bestellen?'),
  ('ui', 'cover.aria',   'fr', 'Catégories'),
  ('ui', 'cover.aria',   'nl', 'Categorieën'),
  ('ui', 'cover.count',  'fr', '{count} produits'),
  ('ui', 'cover.count',  'nl', '{count} producten'),
  ('ui', 'cover.count1', 'fr', '1 produit'),
  ('ui', 'cover.count1', 'nl', '1 product'),
  ('ui', 'cover.all',    'fr', 'Voir tout le catalogue · {count} produits'),
  ('ui', 'cover.all',    'nl', 'Volledige catalogus · {count} producten'),
  ('ui', 'cover.season', 'fr', 'Gamme de saison'),
  ('ui', 'cover.season', 'nl', 'Seizoensassortiment');
