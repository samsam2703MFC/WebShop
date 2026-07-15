-- ============================================================================
-- alter-route-max-items.sql
-- Max items per route (tournée). ws_tours is the route table referenced by
-- ws_clientb2b.route_id. NULL = no cap. A finer per-window cap already exists
-- in ws_tour_availability.max_items (per tour/day/window).
-- Run once on an existing DB.
-- ============================================================================
ALTER TABLE ws_tours
  ADD COLUMN max_items INT AFTER name;   -- NULL = illimité

-- Exemple : plafonner une route à 120 articles
-- UPDATE ws_tours SET max_items = 120 WHERE id = <ROUTE_ID>;
