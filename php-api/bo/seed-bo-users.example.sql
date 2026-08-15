-- ============================================================================
-- seed-bo-users.example.sql — GABARIT de comptes back-office (NON exécutable tel quel).
-- Prérequis : bo_users / bo_user_shops créés (ws_schema.sql ou
-- alter-bo-brand-comms.sql). Idempotent (INSERT IGNORE sur e-mail unique).
--
-- AUDIT GO-LIVE (15/08) : ce fichier contenait un mot de passe de démo EN
-- CLAIR (« Test1234! ») avec un hash bcrypt RÉUTILISABLE et des e-mails du
-- domaine réel — le tout servi publiquement depuis l'API. Neutralisé :
--   • le mot de passe et le hash exploitables sont retirés (placeholders) ;
--   • les e-mails passent en example.invalid (jamais un compte réel) ;
--   • bo/, tests/, cron/, tools/ sont désormais exclus du déploiement et
--     protégés par .htaccess (seeds/scripts non téléchargeables).
-- ACTION PROD REQUISE : vérifier que bo_users ne contient PAS
--   siege@atelierby.be / franchise@atelierby.be avec l'ancien hash, et les
--   désactiver le cas échéant (UPDATE bo_users SET active=0 WHERE email IN (…)).
--
-- POUR CRÉER DE VRAIS COMPTES : remplace les placeholders par des e-mails
-- réels et des hash générés depuis un mot de passe FORT jamais commité —
-- un hash distinct par compte :
--   php -r 'echo password_hash("MOT_DE_PASSE_FORT", PASSWORD_BCRYPT), "\n";'
-- ============================================================================

-- Franchiseur (réseau / siège) : role = 'siege'
INSERT IGNORE INTO bo_users (email, password_hash, display_name, role, active) VALUES
  ('siege@example.invalid',
   '$2y$12$REMPLACER_PAR_UN_HASH_GENERE_LOCALEMENT_ET_JAMAIS_COMMITE_00000',
   'Siège — Réseau', 'siege', 1);

-- Franchisé (borné à ses boutiques) : role = 'franchise'
INSERT IGNORE INTO bo_users (email, password_hash, display_name, role, active) VALUES
  ('franchise@example.invalid',
   '$2y$12$REMPLACER_PAR_UN_HASH_GENERE_LOCALEMENT_ET_JAMAIS_COMMITE_00000',
   'Franchisé — Boutique', 'franchise', 1);

-- Portée du franchisé : ses boutiques (adapte les shop_id à ton parc réel).
INSERT IGNORE INTO bo_user_shops (user_id, shop_id)
SELECT u.id, s.id
  FROM bo_users u
  JOIN ws_shops s ON s.id IN (1, 2)
 WHERE u.email = 'franchise@example.invalid';
