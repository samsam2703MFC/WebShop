-- 0052 — Jeton d'appareil par boutique (tablette Kitchen).
--
-- Le mode tablette a quitté le back-office franchisé : la tablette de comptoir
-- tourne désormais sous Kitchen, qui porte son propre appareil et sa propre
-- session. Le PIN (bo_users, bo_pin_session) n'est plus l'entrée de la
-- tablette ; il reste en base et l'API continue de le servir, pour ne pas
-- fermer d'un coup les tablettes encore en service.
--
-- Ce que Kitchen doit recevoir : l'URL du back-office et UN JETON, saisis dans
-- ses paramètres. Ce jeton n'est pas le jeton administrateur ERP — celui-ci
-- donne accès aux marges, aux coûts et aux paramètres réseau, et n'a rien à
-- faire sur une tablette de comptoir.
--
-- Le jeton est stocké HACHÉ (SHA-256) : une fuite de la base ne donne aucun
-- jeton utilisable. Il n'est affiché en entier qu'à la génération, comme un mot
-- de passe. `token_prefix` garde les premiers caractères en clair, uniquement
-- pour que le franchisé reconnaisse le jeton actif dans son écran.
--
-- Un seul jeton ACTIF par boutique : régénérer révoque le précédent (révoqué =
-- revoked_at daté, jamais supprimé — la trace de qui a eu accès reste).

CREATE TABLE IF NOT EXISTS ws_shop_device_token (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT          NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  token_prefix VARCHAR(12)  NOT NULL DEFAULT '',
  label        VARCHAR(120) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME     NULL,
  revoked_at   DATETIME     NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_shop_active (shop_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
