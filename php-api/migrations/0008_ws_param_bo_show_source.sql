-- 0008 — Paramètre d'affichage de l'origine des données du back-office franchisor.
-- 'bo_show_source' : '1' = afficher la table source sur chaque page (phase debug),
-- '0' = masquer. Lu par le front via /franchisor/params (SRV('params')).
-- Idempotent : n'écrase pas une valeur déjà choisie (ON DUPLICATE KEY = no-op).
INSERT INTO ws_param (param_key, param_value) VALUES ('bo_show_source', '1')
ON DUPLICATE KEY UPDATE param_value = param_value;
