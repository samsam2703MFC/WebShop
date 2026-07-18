-- ============================================================================
-- seed-bo-users.example.sql — Comptes de démonstration des deux back-offices.
-- Prérequis : bo_users / bo_user_shops créés (ws_schema.sql ou
-- alter-bo-brand-comms.sql). Idempotent (INSERT IGNORE sur e-mail unique).
--
-- Mot de passe des DEUX comptes de démo : Test1234!
-- Régénère TES propres hash en prod :
--   php -r 'echo password_hash("TON_MDP", PASSWORD_BCRYPT), "\n";'
-- ============================================================================

-- Franchiseur (réseau / siège) : role = 'siege'
INSERT IGNORE INTO bo_users (email, password_hash, display_name, role, active) VALUES
  ('siege@atelierby.be',
   '$2y$12$8M07fBtkOYN3n0ZOdI7UEeaDcprr7.fOuSAo2dv0fPubng0m1VpPu',
   'Siège — Réseau', 'siege', 1);

-- Franchisé (borné à ses boutiques) : role = 'franchise'
INSERT IGNORE INTO bo_users (email, password_hash, display_name, role, active) VALUES
  ('franchise@atelierby.be',
   '$2y$12$8M07fBtkOYN3n0ZOdI7UEeaDcprr7.fOuSAo2dv0fPubng0m1VpPu',
   'Franchisé — Bruxelles', 'franchise', 1);

-- Portée du franchisé : ses boutiques (adapte les shop_id à ton parc réel).
-- Ici on rattache le franchisé aux boutiques 1 et 2 si elles existent.
INSERT IGNORE INTO bo_user_shops (user_id, shop_id)
SELECT u.id, s.id
  FROM bo_users u
  JOIN ws_shops s ON s.id IN (1, 2)
 WHERE u.email = 'franchise@atelierby.be';
