-- 0031 — Le checkout (POST /orders) écrivait des colonnes que la table
-- ws_orders (créée avant les migrations) n'a jamais reçues → « Unknown
-- column » → erreur 500 au paiement (comptant inclus). On aligne le schéma :
-- chaque colonne attendue est ajoutée si absente. Idempotent (patron 0020).

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN mode VARCHAR(20) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='mode');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT ''pending''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN slot_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='slot_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN slot_label VARCHAR(120) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='slot_label');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN delivery_date DATE NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='delivery_date');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='subtotal');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN promo_amount DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='promo_amount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN webshop_discount DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='webshop_discount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN voucher_code VARCHAR(80) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='voucher_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN voucher_discount DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='voucher_discount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN total DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='total');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN payment_method VARCHAR(40) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='payment_method');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT ''pending''','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='payment_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN lang VARCHAR(8) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='lang');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN note VARCHAR(1000) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN delivery_mode VARCHAR(30) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='delivery_mode');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN office_client_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='office_client_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN office_delivery_site_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='office_delivery_site_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN office_delivery_site_name VARCHAR(200) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='office_delivery_site_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN tournee_stop_id INT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='tournee_stop_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN payment_type VARCHAR(20) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='payment_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN delivery_fee_applied TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='delivery_fee_applied');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN delivery_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='delivery_fee_amount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN free_delivery_minimum DECIMAL(10,2) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='free_delivery_minimum');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN guest_email VARCHAR(190) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='guest_email');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN guest_name VARCHAR(190) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='guest_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN guest_phone VARCHAR(30) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='guest_phone');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN guest_phone_prefix VARCHAR(8) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='guest_phone_prefix');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
