-- ============================================================================
-- alter-tour-afternoon-window.sql
-- Adds support for a SECOND daily delivery window per tour (e.g. an afternoon
-- run) on an existing database. A tour/day can now have several windows, each
-- with its own hours + order cutoff, configured in ws_tour_availability.
--
-- Run once on a DB created before this feature. (Fresh installs from
-- ws_schema.sql already include window_label.)
-- ============================================================================

-- 1) Discriminator so a (tour, shop, day) can hold more than one window.
ALTER TABLE ws_tour_availability
  ADD COLUMN window_label VARCHAR(16) NOT NULL DEFAULT 'morning' AFTER delivery_day;

-- 2) Widen the unique key to include the window.
ALTER TABLE ws_tour_availability
  DROP INDEX uq_tour_avail,
  ADD UNIQUE KEY uq_tour_avail (tour_id, shop_id, delivery_day, window_label);

-- ----------------------------------------------------------------------------
-- Example: give ONE tour an afternoon window (delivery 17:00, order cutoff 15:00)
-- for every weekday it already delivers in the morning. Replace <TOUR_ID>/<SHOP_ID>.
-- Only the tours you insert here get the afternoon slot; all others stay morning-only.
-- ----------------------------------------------------------------------------
-- INSERT INTO ws_tour_availability
--   (tour_id, shop_id, delivery_day, window_label, delivery_start, delivery_end, cutoff_time, active)
-- SELECT tour_id, shop_id, delivery_day, 'afternoon', '17:00:00', '18:00:00', '15:00:00', 1
--   FROM ws_tour_availability
--  WHERE tour_id = <TOUR_ID> AND shop_id = <SHOP_ID> AND window_label = 'morning';
