-- 0112 — Libellés du BUNDLE ERP (vignette + fiche produit), fr + nl.
--
-- Le bundle (promotion Franchise Buddy « Brownies + Coca-Cola » à prix
-- bundle) se signale sur la vignette et se choisit dans la fiche, en deux
-- options exclusives, le produit seul par défaut. Les libellés passent par
-- ws_i18n comme ceux du composeur de formules (0094).
--
-- IDEMPOTENTE : INSERT IGNORE.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  -- vignette : pastille et ligne « Avec … »
  ('ui', 'card.offer',      'fr', 'Bundle'),
  ('ui', 'card.offer',      'nl', 'Bundel'),
  ('ui', 'card.offerWith',  'fr', 'Avec {name}'),
  ('ui', 'card.offerWith',  'nl', 'Met {name}'),

  -- fiche : section et options
  ('ui', 'pd.offerTitle',     'fr', 'Bundle proposé'),
  ('ui', 'pd.offerTitle',     'nl', 'Voorgestelde bundel'),
  ('ui', 'pd.offerAlone',     'fr', '{name} seul'),
  ('ui', 'pd.offerAlone',     'nl', '{name} alleen'),
  ('ui', 'pd.offerAloneDesc', 'fr', 'Le produit, sans rien d’autre.'),
  ('ui', 'pd.offerAloneDesc', 'nl', 'Het product, zonder iets anders.'),
  ('ui', 'pd.offerSave',      'fr', '−{amount} €'),
  ('ui', 'pd.offerSave',      'nl', '−{amount} €'),
  ('ui', 'pd.offerAdd',       'fr', 'Ajouter le bundle'),
  ('ui', 'pd.offerAdd',       'nl', 'Bundel toevoegen'),
  ('ui', 'pd.offerIn',        'fr', 'Inclus dans le bundle'),
  ('ui', 'pd.offerIn',        'nl', 'Inbegrepen in de bundel');

SELECT k, lang, value FROM ws_i18n WHERE scope='ui' AND k IN ('card.offer','pd.offerTitle','pd.offerAdd') ORDER BY k, lang;
