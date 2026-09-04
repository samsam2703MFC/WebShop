-- 0125 — CRM DE PROSPECTION B2B (console franchisé). Un tableau par campagne :
-- des cartes (bureaux, clients, prospects libres) que le franchisé déplace de
-- colonne en colonne. Les colonnes sont PARAMÉTRABLES par boutique — le cycle
-- change d'une saison à l'autre, la table ne doit pas l'imposer.
CREATE TABLE IF NOT EXISTS ws_crm_colonne (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  cle VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  ordre INT NOT NULL DEFAULT 0,
  couleur VARCHAR(16) NULL,
  gagne TINYINT NOT NULL DEFAULT 0,
  perdu TINYINT NOT NULL DEFAULT 0,
  actif TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY u_shop_cle (shop_id, cle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_crm_carte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  campagne_id INT NULL,
  campagne_nom VARCHAR(120) NULL,
  cible_type VARCHAR(16) NOT NULL DEFAULT 'prospect',
  cible_id INT NULL,
  nom VARCHAR(160) NOT NULL,
  colonne VARCHAR(40) NOT NULL,
  ordre INT NOT NULL DEFAULT 0,
  voucher_code VARCHAR(60) NULL,
  contact_nom VARCHAR(120) NULL,
  contact_role VARCHAR(60) NULL,
  contact_tel VARCHAR(40) NULL,
  contact_email VARCHAR(160) NULL,
  adresse VARCHAR(255) NULL,
  note TEXT NULL,
  prochaine_action DATE NULL,
  cree_le DATETIME NULL,
  maj DATETIME NULL,
  KEY k_shop_camp (shop_id, campagne_id),
  KEY k_colonne (colonne)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal des gestes : qui a envoyé le mail, qui est passé, qui a téléphoné.
-- Sans lui, une carte en colonne « relance » ne dit pas ce qui a déjà été fait.
CREATE TABLE IF NOT EXISTS ws_crm_geste (
  id INT AUTO_INCREMENT PRIMARY KEY,
  carte_id INT NOT NULL,
  type VARCHAR(24) NOT NULL,
  texte VARCHAR(255) NULL,
  par VARCHAR(80) NULL,
  quand DATETIME NULL,
  KEY k_carte (carte_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
