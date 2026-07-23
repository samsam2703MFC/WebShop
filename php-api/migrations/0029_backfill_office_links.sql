-- 0029 — Réparation des clients « livraison au bureau » sans office (ex. XLG
-- Facility S.A.) : activés avant les correctifs 0028, office_delivery=1 mais
-- le trigger n'avait rien créé (is_b2b=0 ou pas de transition) → invisibles
-- dans l'écran Offices et dans le flux tournée. Idempotent (rejouable).

-- 1. Un client en livraison au bureau est une personne morale : is_b2b=1.
UPDATE client SET is_b2b=1
 WHERE office_delivery=1 AND (is_b2b IS NULL OR is_b2b=0);

-- 2. Créer les offices manquants (nom = raison sociale, validés, livrables).
INSERT INTO ws_offices (client_id, shop_id, name, postal_code, city, email, phone, status, active)
SELECT c.id, NULLIF(c.id_main_shop, 0),
       COALESCE(NULLIF(TRIM(c.company_name), ''), NULLIF(TRIM(c.name), ''), CONCAT('Client #', c.id)),
       c.zip, c.locality, c.email, c.phone, 'validated', 1
  FROM client c
 WHERE c.office_delivery = 1
   AND NOT EXISTS (SELECT 1 FROM ws_offices o WHERE o.client_id = c.id);

-- 3. Double lien : client.office_id (lu par le GET du menu Clients) ↔
--    ws_offices.client_id (écrit par les triggers).
UPDATE client c JOIN ws_offices o ON o.client_id = c.id
   SET c.office_id = o.id
 WHERE c.office_id IS NULL;

UPDATE ws_offices o JOIN client c ON c.office_id = o.id
   SET o.client_id = c.id
 WHERE o.client_id IS NULL;

-- 4. Les offices des clients actifs en livraison au bureau sont livrables.
UPDATE ws_offices o JOIN client c ON c.id = o.client_id
   SET o.active = 1, o.status = 'validated'
 WHERE c.office_delivery = 1;
