-- 0098 — Tunnel : démarrage du paiement hébergé + retour de la page Stripe.
--
-- La redirection vers la page de paiement Stripe ne se produisait jamais :
-- le front attendait `checkoutUrl` de POST /orders, qui ne le rend pas, et
-- personne n'appelait POST /payments/checkout, qui le fabrique. Une commande
-- « carte » était donc confirmée à l'écran sans paiement. Le correctif fait
-- l'appel manquant — ces clés portent les trois messages qui l'accompagnent :
--   · co.payStartFail   : la commande est enregistrée mais la session de
--     paiement n'a pas pu être créée (Stripe non configuré, panne) — dire que
--     réessayer est sûr, la clé d'idempotence reprend LA MÊME commande ;
--   · co.payCanceled(+Sub) : retour ?canceled=1 — la commande existe et reste
--     impayée. On ne promet aucun écran de « relance » qui n'existe pas.
--
-- IDEMPOTENTE : INSERT IGNORE.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'co.payStartFail', 'fr', 'La commande {ref} est enregistrée, mais le paiement n''a pas pu démarrer : {reason} Réessayez — la même commande sera reprise, jamais dupliquée.'),
  ('ui', 'co.payStartFail', 'nl', 'Bestelling {ref} is geregistreerd, maar de betaling kon niet starten: {reason} Probeer opnieuw — dezelfde bestelling wordt hervat, nooit gedupliceerd.'),

  ('ui', 'co.payCanceled',    'fr', 'Paiement annulé'),
  ('ui', 'co.payCanceled',    'nl', 'Betaling geannuleerd'),
  ('ui', 'co.payCanceledSub', 'fr', 'La commande {ref} est enregistrée mais n''a pas été payée. Contactez la boutique pour la régler ou l''annuler.'),
  ('ui', 'co.payCanceledSub', 'nl', 'Bestelling {ref} is geregistreerd maar niet betaald. Neem contact op met de winkel om te betalen of te annuleren.');
