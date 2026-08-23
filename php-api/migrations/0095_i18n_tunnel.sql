-- 0095 — Tunnel de commande : libellés restés en français (fr + nl).
--
-- Relevé sur captures d'une boutique en néerlandais : l'ossature du tunnel
-- (retour au panier, étapes, boutons) et plusieurs libellés de champs
-- restaient en français au milieu d'écrans traduits.
--
-- IDEMPOTENTE : INSERT IGNORE.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'co.backToCart', 'fr', 'Retour au panier'),
  ('ui', 'co.backToCart', 'nl', 'Terug naar winkelmandje'),
  ('ui', 'co.step1', 'fr', 'Coordonnées'),
  ('ui', 'co.step1', 'nl', 'Gegevens'),
  ('ui', 'co.step2', 'fr', 'Créneau'),
  ('ui', 'co.step2', 'nl', 'Tijdslot'),
  ('ui', 'co.step3', 'fr', 'Paiement'),
  ('ui', 'co.step3', 'nl', 'Betaling'),
  ('ui', 'co.continue', 'fr', 'Continuer'),
  ('ui', 'co.continue', 'nl', 'Doorgaan'),
  ('ui', 'co.processing', 'fr', 'Traitement…'),
  ('ui', 'co.processing', 'nl', 'Bezig…'),
  ('ui', 'co.payAmount', 'fr', 'Payer · {amount}'),
  ('ui', 'co.payAmount', 'nl', 'Betalen · {amount}'),
  ('ui', 'co.slot.titleCollect', 'fr', 'Créneau de collecte'),
  ('ui', 'co.slot.titleCollect', 'nl', 'Afhaalmoment'),
  ('ui', 'co.slot.titleDelivery', 'fr', 'Créneau de livraison'),
  ('ui', 'co.slot.titleDelivery', 'nl', 'Leveringsmoment'),
  ('ui', 'co.apply', 'fr', 'Appliquer'),
  ('ui', 'co.apply', 'nl', 'Toepassen'),
  ('ui', 'co.applied', 'fr', 'Appliqué'),
  ('ui', 'co.applied', 'nl', 'Toegepast'),
  ('ui', 'co.voucher.availableOne', 'fr', 'Vous avez un code promo'),
  ('ui', 'co.voucher.availableOne', 'nl', 'Je hebt een promocode'),
  ('ui', 'co.voucher.availableMany', 'fr', 'Vos codes promo disponibles'),
  ('ui', 'co.voucher.availableMany', 'nl', 'Je beschikbare promocodes'),
  ('ui', 'co.askInvoice', 'fr', 'Demander une facture'),
  ('ui', 'co.askInvoice', 'nl', 'Een factuur aanvragen'),
  ('ui', 'co.askInvoiceNamed', 'fr', 'Demander une facture nominative'),
  ('ui', 'co.askInvoiceNamed', 'nl', 'Een factuur op naam aanvragen');
