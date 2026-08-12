-- 0034 — delivery_date jamais NULL : les commandes existantes sans jour de
-- livraison prennent leur jour de création (même logique que le COALESCE
-- d'affichage ; le checkout pose désormais le jour même par défaut).
-- Idempotent.
UPDATE ws_orders SET delivery_date = DATE(created_at) WHERE delivery_date IS NULL;
