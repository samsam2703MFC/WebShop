-- 0093 — Information consommateur : les 14 allergènes majeurs (fr + nl).
--
-- Règlement (UE) n° 1169/2011. Les dénominations néerlandaises ci-dessous sont
-- celles de l'annexe II du règlement dans sa version néerlandaise : c'est une
-- mention réglementaire, pas une formulation commerciale — elle ne s'invente
-- pas et ne se paraphrase pas.
--
-- Les clés reprennent les identifiants internes des allergènes
-- (allergen.<id>), pour que l'écran et les puces de fiche produit lisent la
-- même valeur. Le libellé FR du code reste la SOURCE de repli.
--
-- IDEMPOTENTE : INSERT IGNORE — une valeur éditée après coup n'est pas écrasée.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  -- les 14 allergènes (annexe II du règlement 1169/2011)
  ('ui', 'allergen.gluten',      'fr', 'Gluten'),
  ('ui', 'allergen.gluten',      'nl', 'Gluten'),
  ('ui', 'allergen.crustaceans', 'fr', 'Crustacés'),
  ('ui', 'allergen.crustaceans', 'nl', 'Schaaldieren'),
  ('ui', 'allergen.eggs',        'fr', 'Œufs'),
  ('ui', 'allergen.eggs',        'nl', 'Eieren'),
  ('ui', 'allergen.fish',        'fr', 'Poisson'),
  ('ui', 'allergen.fish',        'nl', 'Vis'),
  ('ui', 'allergen.peanuts',     'fr', 'Arachides'),
  ('ui', 'allergen.peanuts',     'nl', 'Pinda''s'),
  ('ui', 'allergen.soy',         'fr', 'Soja'),
  ('ui', 'allergen.soy',         'nl', 'Soja'),
  ('ui', 'allergen.milk',        'fr', 'Lait'),
  ('ui', 'allergen.milk',        'nl', 'Melk'),
  ('ui', 'allergen.nuts',        'fr', 'Fruits à coque'),
  ('ui', 'allergen.nuts',        'nl', 'Noten'),
  ('ui', 'allergen.celery',      'fr', 'Céleri'),
  ('ui', 'allergen.celery',      'nl', 'Selderij'),
  ('ui', 'allergen.mustard',     'fr', 'Moutarde'),
  ('ui', 'allergen.mustard',     'nl', 'Mosterd'),
  ('ui', 'allergen.sesame',      'fr', 'Sésame'),
  ('ui', 'allergen.sesame',      'nl', 'Sesamzaad'),
  ('ui', 'allergen.sulphites',   'fr', 'Sulfites'),
  ('ui', 'allergen.sulphites',   'nl', 'Sulfiet'),
  ('ui', 'allergen.lupin',       'fr', 'Lupin'),
  ('ui', 'allergen.lupin',       'nl', 'Lupine'),
  ('ui', 'allergen.molluscs',    'fr', 'Mollusques'),
  ('ui', 'allergen.molluscs',    'nl', 'Weekdieren'),

  -- écran « Information consommateur »
  ('ui', 'allergen.eyebrow',  'fr', 'Information consommateur'),
  ('ui', 'allergen.eyebrow',  'nl', 'Consumenteninformatie'),
  ('ui', 'allergen.title',    'fr', 'Les __14 allergènes__ majeurs'),
  ('ui', 'allergen.title',    'nl', 'De __14 belangrijkste__ allergenen'),
  ('ui', 'allergen.lede',     'fr', 'Les pictogrammes utilisés sur nos fiches produit. Conforme au règlement européen n° 1169/2011.'),
  ('ui', 'allergen.lede',     'nl', 'De pictogrammen op onze productfiches. Conform de Europese verordening nr. 1169/2011.'),
  ('ui', 'allergen.foot',     'fr', 'Règlement UE n° 1169/2011 — substances ou produits provoquant des allergies ou intolérances.'),
  ('ui', 'allergen.foot',     'nl', 'Verordening (EU) nr. 1169/2011 — stoffen of producten die allergieën of intoleranties veroorzaken.'),
  ('ui', 'allergen.listAria', 'fr', 'Liste des allergènes'),
  ('ui', 'allergen.listAria', 'nl', 'Lijst van allergenen'),
  ('ui', 'allergen.title2',   'fr', 'Allergènes'),
  ('ui', 'allergen.title2',   'nl', 'Allergenen');
