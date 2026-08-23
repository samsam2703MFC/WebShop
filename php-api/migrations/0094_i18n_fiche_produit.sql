-- 0094 — Modale FICHE PRODUIT : libellés restés en français (fr + nl).
--
-- Relevé à l'écran sur une boutique en néerlandais : la ligne allergènes était
-- bien traduite, mais le sur-titre (« Notre sélection »), la formule « À la
-- carte », la mention « Inclus » et le bouton d'ajout restaient en français.
-- Ils vivaient dans des littéraux JS (objets, expressions ternaires), pas dans
-- du JSX — d'où leur absence des relevés précédents.
--
-- IDEMPOTENTE : INSERT IGNORE.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  -- sur-titre selon la famille de produit
  ('ui', 'pd.eyebrow.selection', 'fr', 'Notre sélection'),
  ('ui', 'pd.eyebrow.selection', 'nl', 'Onze selectie'),
  ('ui', 'pd.eyebrow.sandwich',  'fr', 'Sandwich'),
  ('ui', 'pd.eyebrow.sandwich',  'nl', 'Broodje'),
  ('ui', 'pd.eyebrow.dish',      'fr', 'Plat du jour'),
  ('ui', 'pd.eyebrow.dish',      'nl', 'Dagschotel'),

  -- formule « produit seul »
  ('ui', 'pd.alaCarte',     'fr', 'À la carte'),
  ('ui', 'pd.alaCarte',     'nl', 'À la carte'),
  ('ui', 'pd.alaCarteDesc', 'fr', 'Le produit seul, sans formule.'),
  ('ui', 'pd.alaCarteDesc', 'nl', 'Enkel het product, zonder formule.'),
  ('ui', 'pd.included',     'fr', 'Inclus'),
  ('ui', 'pd.included',     'nl', 'Inbegrepen'),

  -- bouton principal, dans ses quatre états
  ('ui', 'pd.chooseOptions',  'fr', 'Choisissez vos options'),
  ('ui', 'pd.chooseOptions',  'nl', 'Kies je opties'),
  ('ui', 'pd.outOfStock',     'fr', 'Stock épuisé'),
  ('ui', 'pd.outOfStock',     'nl', 'Voorraad uitgeput'),
  ('ui', 'pd.notForDelivery', 'fr', 'Non disponible en livraison'),
  ('ui', 'pd.notForDelivery', 'nl', 'Niet beschikbaar voor levering'),
  -- stock livraison restant (pluriel = clé distincte, pas un « s » ajouté par le JS)
  ('ui', 'pd.unitsLeftOne',  'fr', '{n} unité disponible'),
  ('ui', 'pd.unitsLeftOne',  'nl', '{n} stuk beschikbaar'),
  ('ui', 'pd.unitsLeftMany', 'fr', '{n} unités disponibles'),
  ('ui', 'pd.unitsLeftMany', 'nl', '{n} stuks beschikbaar');
