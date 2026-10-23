-- 0133 — Bandeau de consentement aux cookies (webshop-consent.jsx) : Google
-- Analytics (mesure d'audience) et Google Ads (publicité) ne déposent rien sans
-- accord ; refuser est aussi simple qu'accepter. Entrée « Cookies » dans le menu
-- des langues pour revenir sur son choix. IDEMPOTENTE : INSERT IGNORE.
INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'consent.title',          'fr', 'Cookies et mesure'),
  ('ui', 'consent.title',          'nl', 'Cookies en meting'),
  ('ui', 'consent.body',           'fr', 'Avec votre accord, nous mesurons la fréquentation du webshop (Google Analytics) et l’efficacité de nos publicités (Google Ads). Sans accord, rien n’est déposé à ces fins, et votre commande fonctionne exactement de la même façon.'),
  ('ui', 'consent.body',           'nl', 'Met uw toestemming meten we het bezoek aan de webshop (Google Analytics) en de doeltreffendheid van onze advertenties (Google Ads). Zonder toestemming wordt hiervoor niets geplaatst, en uw bestelling werkt precies hetzelfde.'),
  ('ui', 'consent.analytics',      'fr', 'Mesure d’audience'),
  ('ui', 'consent.analytics',      'nl', 'Bezoekersmeting'),
  ('ui', 'consent.analytics.help', 'fr', 'Google Analytics : pages vues, paniers et commandes, sans vous identifier.'),
  ('ui', 'consent.analytics.help', 'nl', 'Google Analytics: bekeken pagina’s, winkelmandjes en bestellingen, zonder u te identificeren.'),
  ('ui', 'consent.ads',            'fr', 'Publicité'),
  ('ui', 'consent.ads',            'nl', 'Advertenties'),
  ('ui', 'consent.ads.help',       'fr', 'Google Ads : savoir quelles annonces mènent à une commande, et vous montrer des annonces adaptées.'),
  ('ui', 'consent.ads.help',       'nl', 'Google Ads: weten welke advertenties tot een bestelling leiden, en u passende advertenties tonen.'),
  ('ui', 'consent.accept',         'fr', 'Tout accepter'),
  ('ui', 'consent.accept',         'nl', 'Alles aanvaarden'),
  ('ui', 'consent.refuse',         'fr', 'Tout refuser'),
  ('ui', 'consent.refuse',         'nl', 'Alles weigeren'),
  ('ui', 'consent.choose',         'fr', 'Choisir'),
  ('ui', 'consent.choose',         'nl', 'Kiezen'),
  ('ui', 'consent.save',           'fr', 'Enregistrer mes choix'),
  ('ui', 'consent.save',           'nl', 'Mijn keuzes opslaan'),
  ('ui', 'consent.manage',         'fr', 'Cookies'),
  ('ui', 'consent.manage',         'nl', 'Cookies');
