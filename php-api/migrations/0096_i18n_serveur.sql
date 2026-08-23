-- 0096 — Libellés COMPOSÉS PAR LE SERVEUR (fr + nl).
--
-- Moyens de paiement et libellés de bons : le front reçoit une phrase déjà
-- faite, il ne peut donc pas la traduire lui-même. Le serveur la compose
-- désormais dans la langue demandée (?lang=), à partir de ces clés.
--
-- Relevé à l'écran sur une boutique en néerlandais : « Carte / Bancontact
-- (en ligne) », « Paiement en boutique », « Livraison offerte » et « dès 30 € »
-- s'affichaient en français au milieu d'un écran de paiement néerlandais.
--
-- IDEMPOTENTE : INSERT IGNORE.

INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  -- moyens de paiement (clé = pay.<method>)
  ('ui', 'pay.stripe',   'fr', 'Carte / Bancontact (en ligne)'),
  ('ui', 'pay.stripe',   'nl', 'Kaart / Bancontact (online)'),
  ('ui', 'pay.shop',     'fr', 'Paiement en boutique'),
  ('ui', 'pay.shop',     'nl', 'Betaling in de winkel'),
  ('ui', 'pay.deferred', 'fr', 'Sur compte (facturation)'),
  ('ui', 'pay.deferred', 'nl', 'Op rekening (facturatie)'),

  -- libellés de bons. Les variables sont substituées par le serveur : le
  -- traducteur peut les DÉPLACER dans la phrase (l'ordre des mots diffère).
  ('ui', 'voucher.freeDelivery', 'fr', 'Livraison offerte'),
  ('ui', 'voucher.freeDelivery', 'nl', 'Gratis levering'),
  ('ui', 'voucher.fromAmount',   'fr', 'dès {montant} €'),
  ('ui', 'voucher.fromAmount',   'nl', 'vanaf {montant} €'),
  ('ui', 'voucher.someProduct',  'fr', 'un produit'),
  ('ui', 'voucher.someProduct',  'nl', 'een product'),
  ('ui', 'voucher.productFree',  'fr', '{produit} offert'),
  ('ui', 'voucher.productFree',  'nl', '{produit} gratis'),
  ('ui', 'voucher.onProduct',    'fr', '{remise} sur {qte}{produit}'),
  ('ui', 'voucher.onProduct',    'nl', '{remise} op {qte}{produit}');
