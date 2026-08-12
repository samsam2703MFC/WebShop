-- 0022 — Raccroche les bureaux existants (ws_offices.shop_id NULL) à leur
-- boutique via la zone de chalandise couvrant leur code postal.
-- Ex. « Fiduciaire Lemaire » (1500, pending, shop NULL) apparaissait dans les
-- Validations de TOUS les franchisés alors que le 1500 appartient à la zone
-- « Brabant Flamand Sud » (Atelier by - Halle). Même règle que le rattachement
-- prospect (lp_office_lead / zip_shop).
-- Idempotent : ne touche que les shop_id NULL ; rejouable sans effet.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='ws_offices' AND column_name='shop_id');
SET @s := (SELECT IF(@has=1,
  'UPDATE ws_offices o
     JOIN ws_franchisor_catchment c
       ON c.active = 1 AND c.shop_id IS NOT NULL
      AND o.postal_code IS NOT NULL AND o.postal_code <> ''''
      AND c.postcodes REGEXP CONCAT(''(^|[^0-9])'', o.postal_code, ''($|[^0-9])'')
      SET o.shop_id = c.shop_id
    WHERE o.shop_id IS NULL', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
