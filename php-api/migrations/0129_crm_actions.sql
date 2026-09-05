-- 0129 — ACTIONS DE PROSPECTION : MODÈLES ET PIÈCES JOINTES.
-- Depuis la fiche d'une cible, le franchisé écrit (mail, SMS) ou appelle, à
-- partir d'un modèle où les variables ({contact}, {bon}, {campagne}…) se
-- remplissent seules. Les modèles sont ceux de la boutique (shop_id) ; une
-- ligne à shop_id NULL est un modèle du réseau, lisible par toutes.
-- Les pièces jointes sont une bibliothèque par boutique : un fichier déposé
-- une fois (carte, assortiment, flyer) se joint à autant de mails qu'on veut.
CREATE TABLE IF NOT EXISTS ws_crm_modele (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  type VARCHAR(8) NOT NULL DEFAULT 'mail',
  nom VARCHAR(80) NOT NULL,
  sujet VARCHAR(200) NULL,
  corps TEXT NULL,
  ordre INT NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  maj DATETIME NULL,
  KEY k_shop_type (shop_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ws_crm_piece (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  nom VARCHAR(120) NOT NULL,
  chemin VARCHAR(200) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  taille INT NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  cree_le DATETIME NULL,
  KEY k_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
