-- Ajoute la remise webshop paramétrable par boutique (si la base existe déjà).
-- À importer une seule fois dans phpMyAdmin.
ALTER TABLE ws_shops
  ADD COLUMN webshop_discount_type  VARCHAR(20)   DEFAULT 'percent' AFTER logo_url,
  ADD COLUMN webshop_discount_value DECIMAL(10,2) DEFAULT 0         AFTER webshop_discount_type;

ALTER TABLE ws_orders
  ADD COLUMN webshop_discount DECIMAL(10,2) DEFAULT 0 AFTER promo_amount;
