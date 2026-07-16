-- ============================================================================
-- alter-auth-client-link.sql  —  RUN ONCE on atelierby_db
-- Auth commune : login par email OU téléphone, compte webshop rattaché au
-- client ERP. ws_customers garde les identifiants (email/phone/password_hash) ;
-- client_id = réf logique vers `client` (l'ERP n'a pas de mot de passe).
-- ============================================================================

ALTER TABLE ws_customers
  ADD COLUMN client_id INT AFTER phone,   -- lien vers client ERP
  ADD KEY idx_customers_phone (phone),    -- login par téléphone
  ADD KEY idx_customers_client (client_id);

-- Rattacher les comptes webshop existants à leur client ERP (match email ou téléphone).
UPDATE ws_customers w
  JOIN client c
    ON  c.email = w.email
    OR (w.phone <> '' AND c.phone = w.phone)
   SET w.client_id = c.id
 WHERE w.client_id IS NULL;
