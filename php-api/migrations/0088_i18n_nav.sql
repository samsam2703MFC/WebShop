-- 0088 — Libellés de la barre de navigation (écran 1 du câblage i18n).
--
-- Le texte RÉEL des boutons nav, en fr + nl. Corrige au passage les clés
-- nav.mode.* de 0086 (qui portaient des formes courtes « À emporter » /
-- « Livraison », pas le libellé affiché) et ajoute les clés manquantes.
--
-- ON DUPLICATE KEY UPDATE : migrate.sh joue chaque migration UNE fois, donc
-- corriger ici les deux valeurs de 0086 est sûr (pas de ré-exécution qui
-- écraserait une édition ultérieure).

INSERT INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'nav.mode.collect',      'fr', 'Click & Collect'),
  ('ui', 'nav.mode.collect',      'nl', 'Click & Collect'),
  ('ui', 'nav.mode.delivery',     'fr', 'Livraison au bureau'),
  ('ui', 'nav.mode.delivery',     'nl', 'Levering op kantoor'),
  ('ui', 'nav.mode.closed',       'fr', 'Fermé'),
  ('ui', 'nav.mode.closed',       'nl', 'Gesloten'),
  ('ui', 'nav.datepill.pickup',   'fr', 'Date de retrait'),
  ('ui', 'nav.datepill.pickup',   'nl', 'Afhaaldatum'),
  ('ui', 'nav.datepill.delivery', 'fr', 'Date de livraison'),
  ('ui', 'nav.datepill.delivery', 'nl', 'Leverdatum'),
  ('ui', 'nav.account',           'fr', 'Compte'),
  ('ui', 'nav.account',           'nl', 'Account')
ON DUPLICATE KEY UPDATE value = VALUES(value);
