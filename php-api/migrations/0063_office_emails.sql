-- 0063 — Les contacts e-mail d'un bureau, dans une vraie table.
--
-- CE QUI SE PASSAIT. L'écran « Contacts email » du back-office franchisé
-- écrivait dans l'overlay générique ws_bo_store (un blob JSON par table), et
-- la liste, elle, était RECONSTRUITE à chaque chargement depuis
-- ws_offices.email — une seule adresse par bureau, rôle forcé à « Principal ».
-- Ajouter un contact « Facturation » ou « Livraison » paraissait donc marcher,
-- puis disparaissait au rechargement suivant. Aucun e-mail n'a jamais été
-- envoyé à ces adresses.
--
-- POURQUOI UNE TABLE PLUTÔT QUE PLUSIEURS COLONNES SUR ws_offices. Un bureau a
-- un nombre variable de contacts, et chacun a un RÔLE qui décide de ce qu'il
-- reçoit : le récapitulatif d'invitation va au contact principal, la facture au
-- service comptable, l'avis de passage à la réception. Trois colonnes figées
-- auraient tenu jusqu'au quatrième contact.
--
-- ws_offices.email RESTE la fiche du bureau (son contact déclaré). Il n'est pas
-- recopié ici : l'API sert l'union des deux, en marquant celui qui vient de la
-- fiche — sans quoi deux vérités cohabiteraient et divergeraient.

CREATE TABLE IF NOT EXISTS ws_office_emails (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  office_id  INT          NOT NULL             COMMENT 'ws_offices.id',
  email      VARCHAR(190) NOT NULL,
  role       VARCHAR(32)  NOT NULL DEFAULT 'Principal'
             COMMENT 'Principal · Facturation · Livraison — ce que ce contact recoit',
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_office_email_role (office_id, email, role),
  KEY idx_office_emails_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aucun contact n'est pré-rempli : la table part vide et se remplit par
-- l'écran. Les adresses déjà connues restent servies depuis ws_offices.email,
-- à leur place, sans copie.
