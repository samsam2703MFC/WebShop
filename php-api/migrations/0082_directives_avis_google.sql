-- 0082 — Directives de réponse aux avis Google.
--
-- La console marque définit, par tranche de note (1–5), le ton et les
-- consignes que la console franchisé applique pour GÉNÉRER un brouillon de
-- réponse (POST /franchisee/google-review-reply). La publication reste
-- manuelle : sans OAuth Business Profile, personne ne peut poster — le
-- franchisé copie le texte et le colle sur Google.
--
-- AUCUN seed : les directives naissent à l'écran (règle « aucune donnée
-- inventée »), et l'endpoint de génération dit honnêtement quelle tranche
-- manque tant qu'elle n'est pas écrite.
--
-- IDEMPOTENTE : CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS ws_review_guidelines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  note_min TINYINT NOT NULL COMMENT 'borne basse de la tranche (1-5)',
  note_max TINYINT NOT NULL COMMENT 'borne haute de la tranche (1-5)',
  tone VARCHAR(255) NOT NULL COMMENT 'le sens de la réponse, imposé au modèle',
  instructions TEXT NULL COMMENT 'quoi dire, quoi ne jamais dire',
  example_reply TEXT NULL COMMENT 'exemple du registre attendu (jamais recopié tel quel)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
