-- 0032 — Source de la commande (webshop / pos) : renseignée automatiquement
-- par le checkout webshop ; les ventes de caisse pourront poser « pos ».
-- Idempotent (patron 0020).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN source VARCHAR(20) NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='source');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rétro-remplissage : l'existant vient du webshop.
UPDATE ws_orders SET source='webshop' WHERE source IS NULL OR source='';
