-- 0026 — ws_orders.rating : note 1–5 laissée par le client après son achat
-- (webshop/PWA). Lue par le BO franchisé : fiche client → droplist des
-- commandes (réclamation rattachée à un achat) affiche « ★ n/5 » si notée.
-- Idempotent MySQL 8 (même patron que 0020).
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE ws_orders ADD COLUMN rating TINYINT NULL','DO 0')
  FROM information_schema.columns WHERE table_schema=DATABASE()
   AND table_name='ws_orders' AND column_name='rating');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
