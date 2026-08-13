-- 0066 — Rendre ws_office_delivery_settings ÉCRIVABLE, et honnête.
--
-- CE QUI SE PASSAIT. L'écran « Paramètres livraison — bureau » proposait de
-- régler un cut-off et un temps de dépôt par bureau. Il n'existait AUCUN
-- endpoint d'écriture pour cette table : la saisie partait dans l'overlay
-- générique ws_bo_store. Et comme la table n'est pas dans la liste TYPED du
-- chargeur, cet overlay ÉCRASAIT l'affichage au rechargement.
--
-- Le résultat était pire qu'une perte de donnée : la console affichait la
-- valeur saisie, pendant que le checkout lisait la vraie table
-- ws_office_delivery_settings (index.php, calcul des créneaux). Deux vérités,
-- et c'est la mauvaise qui était montrée à celui qui paramètre.
--
-- La table est une table ERP HÉRITÉE : aucune migration ne la crée, elle
-- existe déjà sur les installations en service — exactement le piège de la
-- 0063. On ne la RECRÉE donc pas aveuglément : on la crée si elle manque, et
-- on complète ses colonnes si elle est là.
--
-- CE QU'ELLE VEUT DIRE. Une ligne ici est une DÉROGATION pour un bureau : sans
-- ligne, le bureau suit sa tournée (ws_tour_availability). C'est ce que fait
-- déjà le checkout, et l'écran le dira désormais au lieu de laisser croire que
-- chaque bureau porte son propre horaire.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_office_delivery_settings');

-- ── 1. La table, si elle n'existe pas ───────────────────────────────────────
SET @s := IF(@t > 0, 'DO 0',
  "CREATE TABLE ws_office_delivery_settings (
     id              INT AUTO_INCREMENT PRIMARY KEY,
     office_id       INT NOT NULL              COMMENT 'ws_offices.id',
     shop_id         INT NULL                  COMMENT 'boutique livreuse',
     tour_id         INT NULL                  COMMENT 'tournee derogatoire ; NULL = celle du bureau',
     allowed_days    VARCHAR(32) NULL          COMMENT 'jours derogatoires, CSV 1..7 ; NULL = ceux de la tournee',
     delivery_cutoff TIME NULL                 COMMENT 'heure limite ; NULL = celle de la tournee',
     cutoff_offset   TINYINT NOT NULL DEFAULT 1 COMMENT 'nb de jours AVANT la livraison (1 = J-1)',
     active          TINYINT(1) NOT NULL DEFAULT 1,
     created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_office_delivery_setting (office_id, shop_id),
     KEY idx_ods_office (office_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Le décalage de jour, sur une table héritée ───────────────────────────
-- « J-1 » était une CHAÎNE codée en dur dans l'API, recollée derrière l'heure.
-- Un bureau qui commande l'avant-veille était donc inexprimable, et le format
-- « 17:00 J-1 » n'était analysable par personne. Le décalage devient un
-- nombre : 0 = le jour même, 1 = la veille, 2 = l'avant-veille.
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_delivery_settings' AND column_name = 'cutoff_offset');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  "ALTER TABLE ws_office_delivery_settings ADD COLUMN cutoff_offset TINYINT NOT NULL DEFAULT 1
   COMMENT 'nb de jours AVANT la livraison (1 = J-1)'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Les colonnes que le checkout lit déjà, si elles manquaient ───────────
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_delivery_settings' AND column_name = 'delivery_cutoff');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_office_delivery_settings ADD COLUMN delivery_cutoff TIME NULL');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_delivery_settings' AND column_name = 'shop_id');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_office_delivery_settings ADD COLUMN shop_id INT NULL');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- AUCUNE LIGNE N'EST CRÉÉE. Un bureau sans dérogation suit sa tournée, et
-- c'est la règle par défaut : pré-remplir la table reviendrait à figer
-- aujourd'hui l'horaire de chaque bureau, qui cesserait alors de suivre les
-- changements de sa tournée.
