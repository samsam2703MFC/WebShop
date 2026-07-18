-- OPTIONNEL (non auto-appliqué : sous-dossier ignoré par migrate.sh).
-- FK voucher_campaign.id_brand → brand(id). À jouer À LA MAIN une fois le mapping
-- marque confirmé. Prérequis : la table `brand` (ERP) existe ET brand.id=1 existe
-- (toutes les campagnes ont id_brand=1 par défaut).
--
-- Vérifier d'abord :
--   SELECT COUNT(*) FROM brand WHERE id=1;                       -- doit valoir 1
--   SELECT DISTINCT id_brand FROM voucher_campaign;              -- doit être inclus dans brand.id
--
-- Puis appliquer (idempotent) :
SET @has_brand := (SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema=DATABASE() AND table_name='brand');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema=DATABASE() AND table_name='voucher_campaign'
                   AND constraint_name='fk_vc_brand');
SET @s := IF(@has_brand=1 AND @has_fk=0,
  'ALTER TABLE voucher_campaign ADD CONSTRAINT fk_vc_brand FOREIGN KEY (id_brand) REFERENCES brand(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
