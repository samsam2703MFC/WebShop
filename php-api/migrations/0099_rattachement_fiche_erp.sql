-- 0099 — Rattacher un compte webshop à une fiche client de la boutique.
--
-- Un client qui achète au comptoir depuis des années existe côté ERP. Quand il
-- crée un compte webshop, l'inscription le reconnaît par son TÉLÉPHONE
-- (8125 fiches sur 8156 en ont un ; 574 seulement ont un e-mail). Relier les
-- deux fiches lui rendrait son historique d'achats et sa fidélité.
--
-- MAIS : le webshop n'a AUCUNE vérification d'identité — ni OTP SMS ni e-mail
-- (le code le signale déjà pour /auth/set-password). Saisir un numéro ne prouve
-- pas qu'on le possède. Un rattachement automatique donnerait donc l'historique
-- d'achats de quelqu'un à qui connaît son numéro. C'est exclu.
--
-- Le rattachement passe par une DEMANDE que le franchisé arbitre — il connaît
-- ses clients — exactement comme le rattachement à un bureau (0047).
--
-- Additif et idempotent (information_schema + PREPARE). Aucune colonne
-- existante n'est modifiée ni supprimée.

CREATE TABLE IF NOT EXISTS ws_client_link_requests (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  client_id      INT NOT NULL,              -- compte webshop demandeur (client.id)
  erp_client_id  INT NOT NULL,              -- fiche ERP visée
  shop_id        INT NULL,                  -- boutique arbitrant la demande
  -- Concordances GELÉES au moment de la demande. L'index ERP a un TTL et la
  -- fiche peut changer entre la demande et la décision : le franchisé doit
  -- arbitrer sur ce qui a été comparé, pas sur un état recalculé plus tard.
  match_json     TEXT NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending | linked | rejected | canceled
  decided_by     VARCHAR(190) NULL,
  reject_reason  VARCHAR(200) NULL,
  decided_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_clr_status (status),
  KEY idx_clr_shop (shop_id),
  KEY idx_clr_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une seule demande EN COURS par compte : sans cette garde, un client pourrait
-- empiler les demandes vers des fiches différentes jusqu'à ce qu'un franchisé
-- pressé en valide une. L'unicité porte sur (client, statut) via une colonne
-- générée qui ne vaut le client que tant que la demande est pendante.
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ws_client_link_requests
     ADD COLUMN pending_key INT AS (IF(status = ''pending'', client_id, NULL)) STORED,
     ADD UNIQUE KEY uniq_clr_pending (pending_key)',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ws_client_link_requests'
    AND column_name = 'pending_key');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Le lien retenu, porté par le compte : c'est lui qui fait foi ensuite.
-- NULL = compte non rattaché, et c'est l'état normal de l'écrasante majorité.
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE client ADD COLUMN erp_client_id INT NULL',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'client'
    AND column_name = 'erp_client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Une fiche ERP ne se rattache qu'à UN compte webshop. Index simple et non
-- unique : deux fiches ERP en doublon (ça existe) ne doivent pas faire échouer
-- la migration ; l'unicité est vérifiée à l'écriture, où l'on peut l'expliquer.
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE client ADD INDEX idx_client_erp (erp_client_id)',
  'DO 0')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'client'
    AND index_name = 'idx_client_erp');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
