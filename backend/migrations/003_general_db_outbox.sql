-- =====================================================================
-- 003 — Outbox table + triggers on the general DB (Franchise Buddy)
-- =====================================================================
-- ⚠️  This is the ONLY change ever made to the general DB (per the
-- agreed rules). Triggers append one row per change to fb_outbox;
-- the webshop sync worker consumes and marks rows processed.
-- The general DB's business tables are never written by the webshop.
--
-- Why outbox over binlog CDC (Debezium): one-way feed of 5 entities,
-- seconds-level latency satisfied by a 1 s poll, no Kafka/Connect
-- cluster to operate, idempotent by construction, trivially auditable.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fb_outbox (
  id            BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity        VARCHAR(40)  NOT NULL,   -- 'article' | 'famille' | 'boutique' | 'stock' | 'promo'
  op            ENUM('upsert','delete') NOT NULL,
  ref_id        VARCHAR(130) NOT NULL,   -- sku / code / "sku|boutique" composite
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at  TIMESTAMP    NULL,
  KEY idx_outbox_pending (processed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_articles_ins $$
CREATE TRIGGER trg_articles_ins AFTER INSERT ON fb_articles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('article', 'upsert', NEW.sku); END $$

DROP TRIGGER IF EXISTS trg_articles_upd $$
CREATE TRIGGER trg_articles_upd AFTER UPDATE ON fb_articles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('article', 'upsert', NEW.sku); END $$

DROP TRIGGER IF EXISTS trg_articles_del $$
CREATE TRIGGER trg_articles_del AFTER DELETE ON fb_articles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('article', 'delete', OLD.sku); END $$

DROP TRIGGER IF EXISTS trg_familles_ins $$
CREATE TRIGGER trg_familles_ins AFTER INSERT ON fb_familles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('famille', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_familles_upd $$
CREATE TRIGGER trg_familles_upd AFTER UPDATE ON fb_familles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('famille', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_familles_del $$
CREATE TRIGGER trg_familles_del AFTER DELETE ON fb_familles FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('famille', 'delete', OLD.code); END $$

DROP TRIGGER IF EXISTS trg_boutiques_ins $$
CREATE TRIGGER trg_boutiques_ins AFTER INSERT ON fb_boutiques FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('boutique', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_boutiques_upd $$
CREATE TRIGGER trg_boutiques_upd AFTER UPDATE ON fb_boutiques FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('boutique', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_boutiques_del $$
CREATE TRIGGER trg_boutiques_del AFTER DELETE ON fb_boutiques FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('boutique', 'delete', OLD.code); END $$

DROP TRIGGER IF EXISTS trg_stock_ins $$
CREATE TRIGGER trg_stock_ins AFTER INSERT ON fb_stock FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('stock', 'upsert', CONCAT(NEW.sku, '|', NEW.boutique_code)); END $$

DROP TRIGGER IF EXISTS trg_stock_upd $$
CREATE TRIGGER trg_stock_upd AFTER UPDATE ON fb_stock FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('stock', 'upsert', CONCAT(NEW.sku, '|', NEW.boutique_code)); END $$

DROP TRIGGER IF EXISTS trg_stock_del $$
CREATE TRIGGER trg_stock_del AFTER DELETE ON fb_stock FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('stock', 'delete', CONCAT(OLD.sku, '|', OLD.boutique_code)); END $$

DROP TRIGGER IF EXISTS trg_promos_ins $$
CREATE TRIGGER trg_promos_ins AFTER INSERT ON fb_promos FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('promo', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_promos_upd $$
CREATE TRIGGER trg_promos_upd AFTER UPDATE ON fb_promos FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('promo', 'upsert', NEW.code); END $$

DROP TRIGGER IF EXISTS trg_promos_del $$
CREATE TRIGGER trg_promos_del AFTER DELETE ON fb_promos FOR EACH ROW
BEGIN INSERT INTO fb_outbox (entity, op, ref_id) VALUES ('promo', 'delete', OLD.code); END $$

DELIMITER ;
