-- 0082 — Demandes d'événements B2B de la landing publique.
--
-- Constat d'audit avant go-live (14/08) : le formulaire « Événements » de la
-- landing /landing/b2b affichait « Votre demande a été transmise à la
-- boutique de X » puis se vidait — SANS RIEN ENVOYER NULLE PART (résidu de
-- mode démo). Chaque prospect réel était perdu en silence.
--
-- Cette table reçoit désormais les demandes (route POST /b2b/event-request,
-- sur le modèle de /zone-request : rate-limit par IP + mail d'alerte).
-- consent_at : horodatage du consentement coché — la case était exigée à
-- l'écran mais son acceptation n'était tracée nulle part.
--
-- IDEMPOTENTE : CREATE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `ws_b2b_event_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(60) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `guests` int(11) DEFAULT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `shop_id` int(11) DEFAULT NULL COMMENT 'boutique couvrant la zone (0/NULL = hors zone, direction réseau)',
  `postal_code` varchar(10) DEFAULT NULL,
  `budget` varchar(60) DEFAULT NULL,
  `company` varchar(190) DEFAULT NULL,
  `vat` varchar(40) DEFAULT NULL,
  `contact_name` varchar(190) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `consent_at` timestamp NULL DEFAULT NULL,
  `source_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_b2b_evt_shop` (`shop_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
