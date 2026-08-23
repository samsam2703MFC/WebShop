-- 0089 — Libellés du PANIER (écran 2 du câblage i18n), fr + nl.
--
-- Les pluriels ont leur PROPRE clé (…One / …Many) plutôt qu'un « s » ajouté
-- par le JS : « quart/quarts » ne se traduit pas en collant une lettre, et
-- d'autres langues n'accordent pas comme le français. Les variables ({n},
-- {name}, {amount}) sont substituées par t(clé, {…}) — jamais concaténées
-- par l'appelant, pour que le traducteur puisse les déplacer dans la phrase.
--
-- IDEMPOTENTE : INSERT IGNORE, une valeur éditée après coup n'est pas écrasée.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  -- en-tête
  ('ui', 'cart.back',              'fr', 'Retour'),
  ('ui', 'cart.back',              'nl', 'Terug'),
  ('ui', 'cart.title',             'fr', 'Récapitulatif de commande'),
  ('ui', 'cart.title',             'nl', 'Overzicht van je bestelling'),
  ('ui', 'cart.mode.collect',      'fr', 'Collecte en magasin'),
  ('ui', 'cart.mode.collect',      'nl', 'Afhalen in de winkel'),
  ('ui', 'cart.mode.delivery',     'fr', 'Livraison au bureau'),
  ('ui', 'cart.mode.delivery',     'nl', 'Levering op kantoor'),

  -- lignes
  ('ui', 'cart.empty',             'fr', 'Votre panier est vide.'),
  ('ui', 'cart.empty',             'nl', 'Je winkelmandje is leeg.'),
  ('ui', 'cart.notePlaceholder',   'fr', 'Note (ex : sans oignon)'),
  ('ui', 'cart.notePlaceholder',   'nl', 'Opmerking (bv. zonder ui)'),
  ('ui', 'cart.remove',            'fr', 'Retirer du panier'),
  ('ui', 'cart.remove',            'nl', 'Uit winkelmandje verwijderen'),
  ('ui', 'cart.removeItem',        'fr', 'Retirer {name} du panier'),
  ('ui', 'cart.removeItem',        'nl', '{name} uit winkelmandje verwijderen'),
  ('ui', 'cart.offer',             'fr', 'Offre {label}'),
  ('ui', 'cart.offer',             'nl', 'Aanbieding {label}'),

  -- totaux
  ('ui', 'cart.subtotal',          'fr', 'Sous-total'),
  ('ui', 'cart.subtotal',          'nl', 'Subtotaal'),
  ('ui', 'cart.shopDiscount',      'fr', 'Remise boutique'),
  ('ui', 'cart.shopDiscount',      'nl', 'Winkelkorting'),
  ('ui', 'cart.deliveryFee',       'fr', 'Frais de livraison'),
  ('ui', 'cart.deliveryFee',       'nl', 'Leveringskosten'),
  ('ui', 'cart.deliveryFree',      'fr', 'Offerts'),
  ('ui', 'cart.deliveryFree',      'nl', 'Gratis'),
  ('ui', 'cart.deliveryFreeFrom',  'fr', 'offerts dès {amount}'),
  ('ui', 'cart.deliveryFreeFrom',  'nl', 'gratis vanaf {amount}'),
  ('ui', 'cart.deliveryRemaining', 'fr', 'Encore {amount} pour la livraison gratuite'),
  ('ui', 'cart.deliveryRemaining', 'nl', 'Nog {amount} voor gratis levering'),
  ('ui', 'cart.total',             'fr', 'Total TTC'),
  ('ui', 'cart.total',             'nl', 'Totaal incl. btw'),
  ('ui', 'cart.checkout',          'fr', 'Passer au paiement'),
  ('ui', 'cart.checkout',          'nl', 'Naar de betaling'),

  -- suggestions « pour accompagner »
  ('ui', 'xsell.title',            'fr', 'Pour accompagner'),
  ('ui', 'xsell.title',            'nl', 'Lekker erbij'),
  ('ui', 'xsell.add',              'fr', 'Ajouter au panier'),
  ('ui', 'xsell.add',              'nl', 'Toevoegen aan winkelmandje'),
  ('ui', 'xsell.addItem',          'fr', 'Ajouter {name}'),
  ('ui', 'xsell.addItem',          'nl', '{name} toevoegen'),

  -- offre cumulable (quarts offerts) — pluriels séparés
  ('ui', 'cross.title',            'fr', 'Offre cumulable · {n} quarts achetés, 1 offert'),
  ('ui', 'cross.title',            'nl', 'Stapelaanbieding · {n} kwarten gekocht, 1 gratis'),
  ('ui', 'cross.freeOne',          'fr', '{n} quart offert'),
  ('ui', 'cross.freeOne',          'nl', '{n} kwart gratis'),
  ('ui', 'cross.freeMany',         'fr', '{n} quarts offerts'),
  ('ui', 'cross.freeMany',         'nl', '{n} kwarten gratis'),
  ('ui', 'cross.toNextOne',        'fr', 'Plus qu''une portion pour profiter de l''offre.'),
  ('ui', 'cross.toNextOne',        'nl', 'Nog één portie om van de aanbieding te genieten.'),
  ('ui', 'cross.toNextMany',       'fr', 'Plus que {n} portions pour profiter de l''offre.'),
  ('ui', 'cross.toNextMany',       'nl', 'Nog {n} porties om van de aanbieding te genieten.'),
  ('ui', 'cross.hint',             'fr', 'Le quart le moins cher est offert automatiquement · cumul tartes, quiches & gâteaux (entier = 4 portions, demi = 2, quart = 1).'),
  ('ui', 'cross.hint',             'nl', 'Het goedkoopste kwart is automatisch gratis · geldt voor taarten, quiches & gebak (heel = 4 porties, half = 2, kwart = 1).'),
  ('ui', 'cross.nudgeOne',         'fr', '+1 portion pour un quart de plus offert.'),
  ('ui', 'cross.nudgeOne',         'nl', '+1 portie voor nog een gratis kwart.'),
  ('ui', 'cross.nudgeMany',        'fr', '+{n} portions pour un quart de plus offert.'),
  ('ui', 'cross.nudgeMany',        'nl', '+{n} porties voor nog een gratis kwart.');
