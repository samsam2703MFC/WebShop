-- Télémétrie de démonstration (adapte tour_id/shop_id à ton parc). Idempotent (upsert).
INSERT INTO ws_tour_tracking
  (tour_id, shop_id, driver_name, vehicle, status, lat, lng, next_label, next_city, eta, drift_minutes, stops_done, stops_total)
SELECT t.id, t.shop_id, 'Marek Kowalski', 'Renault Master frigo', 'en_route',
       50.8467, 4.3499, 'Maison Dandoy', 'Sablon', '10:24', 0, 3, 4
  FROM ws_tours t WHERE t.id = (SELECT MIN(id) FROM ws_tours)
ON DUPLICATE KEY UPDATE driver_name=VALUES(driver_name), vehicle=VALUES(vehicle),
       status=VALUES(status), lat=VALUES(lat), lng=VALUES(lng),
       next_label=VALUES(next_label), next_city=VALUES(next_city), eta=VALUES(eta),
       drift_minutes=VALUES(drift_minutes), stops_done=VALUES(stops_done), stops_total=VALUES(stops_total);
