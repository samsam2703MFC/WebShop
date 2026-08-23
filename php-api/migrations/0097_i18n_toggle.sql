-- 0097 — Infobulle « pas encore de bureau ? » (fr + nl).
-- Restée en dur dans le JSX du sélecteur de mode, repérée en le restructurant.
INSERT IGNORE INTO ws_i18n (scope, k, lang, value) VALUES
  ('ui', 'office.notYetTip', 'fr', 'Pas encore de bureau ? Vérifiez si votre zone est desservie et faites votre demande.'),
  ('ui', 'office.notYetTip', 'nl', 'Nog geen kantoor? Kijk na of je zone bediend wordt en dien je aanvraag in.');
