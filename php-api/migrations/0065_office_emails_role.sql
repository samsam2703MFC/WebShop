-- 0065 — Rattraper ws_office_emails, qui EXISTAIT DÉJÀ.
--
-- CE QUI S'EST PASSÉ. La 0063 a créé ws_office_emails en CREATE TABLE IF NOT
-- EXISTS. Or la table existait déjà en production — créée hors du système de
-- migrations, du temps de /admin/company-emails, avec les colonnes
-- (office_id, email, contract_url, active) et SANS `role`. La 0063 a donc été
-- un NO-OP silencieux : enregistrée comme appliquée, sans effet.
--
-- Résultat mesuré par la sonde check-endpoints : /franchisee/ws-office-emails
-- répondait HTTP 500. L'écran « Contacts e-mail » lit `e.role`, colonne
-- absente ⇒ erreur SQL à chaque chargement.
--
-- CETTE MIGRATION NE RECRÉE RIEN. Elle ajoute ce qui manque à la table telle
-- qu'elle est, et ne touche ni contract_url ni les lignes déjà écrites :
-- /admin/company-emails s'en sert toujours.

-- ── 1. La colonne `role` ────────────────────────────────────────────────────
-- Les lignes existantes prennent 'Principal' : c'est ce que l'ancien écran
-- leur faisait déjà porter implicitement (une adresse par compte, sans rôle).
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_emails' AND column_name = 'role');
SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_office_emails');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  "ALTER TABLE ws_office_emails ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'Principal'
   COMMENT 'Principal · Facturation · Livraison — ce que ce contact recoit'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. L'unicité doit inclure le rôle ───────────────────────────────────────
-- L'ancienne table est unique sur (office_id, email) : une même adresse ne
-- pouvait donc pas être à la fois « Facturation » et « Livraison » — ce que le
-- nouvel écran propose. On remplace cette clé par (office_id, email, role).
--
-- /admin/company-email fait un ON DUPLICATE KEY UPDATE qui s'appuyait sur
-- l'ancienne clé : il continue de fonctionner, puisqu'il n'écrit pas `role` et
-- retombe donc toujours sur 'Principal' — même triplet, même collision.
--
-- L'INDEX DE REMPLACEMENT D'ABORD. La table porte une clé étrangère
-- (office_id → ws_offices.id) que l'ancien index unique (office_id, email)
-- servait, étant son préfixe gauche. Supprimer cet index sans autre index sur
-- office_id fait échouer MariaDB :
--     ERROR 1553 : Cannot drop index 'uq_office_email': needed in a foreign
--     key constraint
-- C'est exactement ce qui s'est produit en production, et comme migrate.sh
-- s'arrête à la première erreur, TOUS les déploiements suivants ont été
-- bloqués. L'ordre n'est donc pas un détail de style : l'index de
-- remplacement doit exister AVANT la suppression.
SET @a := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.statistics s
            WHERE s.table_schema = DATABASE() AND s.table_name = 'ws_office_emails'
              AND s.INDEX_NAME = 'idx_office_emails_office');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_office_emails ADD KEY idx_office_emails_office (office_id)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Le nom de l'ancien index n'est pas connu d'avance (table créée hors
-- migrations) : on le RETROUVE par ses colonnes au lieu de le deviner.
SET @idx := (SELECT INDEX_NAME FROM information_schema.statistics s
              WHERE s.table_schema = DATABASE() AND s.table_name = 'ws_office_emails'
                AND s.NON_UNIQUE = 0 AND s.INDEX_NAME <> 'PRIMARY'
              GROUP BY s.INDEX_NAME
             HAVING GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) = 'office_id,email'
              LIMIT 1);
SET @s := IF(@idx IS NULL, 'DO 0',
             CONCAT('ALTER TABLE ws_office_emails DROP INDEX ', @idx));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- La nouvelle clé n'est posée que si aucune ne couvre déjà ce triplet.
SET @a := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.statistics s
            WHERE s.table_schema = DATABASE() AND s.table_name = 'ws_office_emails'
              AND s.INDEX_NAME = 'uniq_office_email_role');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_office_emails ADD UNIQUE KEY uniq_office_email_role (office_id, email, role)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Les colonnes de confort, si elles manquent ───────────────────────────
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_office_emails' AND column_name = 'created_at');
SET @s := IF(@t = 0 OR @a > 0, 'DO 0',
  'ALTER TABLE ws_office_emails ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (L'index sur office_id est posé PLUS HAUT, avant la suppression de l'ancien
--  unique : il y est indispensable ; ici, il serait trop tard.)
