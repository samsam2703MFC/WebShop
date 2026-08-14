-- 0073 — On retire les deux colonnes qui ne décident plus rien.
--
-- CE QUI RESTE DANS ws_products POUR DÉCIDER DU WEBSHOP, et c'est tout :
--     active            → publié au catalogue (0 = brouillon : nulle part)
--     click_and_collect → vendu en click & collect
--     office_delivery   → vendu en livraison au bureau
--     price             → prix de vente (<= 0 = non fixé, donc hors vente)
--
-- brand_whitelist. Une troisième notion d'accès réseau, qui recouvrait les deux
-- canaux sans le dire. Elle retenait 73 produits actifs sur 90 — le catalogue
-- Traiteur entier — et les boutiques n'en voyaient que 17. Plus lue par aucune
-- requête depuis que le modèle a été simplifié.
--
-- brand_mandatory. Le verrou « produit obligatoire » : il empêchait une
-- boutique de retirer un produit de son assortiment. Ce levier-là n'existe
-- plus — une boutique ne choisit plus ce qu'elle vend, elle saisit ses
-- quantités — donc le verrou n'a plus rien à verrouiller. Il faisait aussi
-- réapparaître des produits en brouillon (« active = 1 OR brand_mandatory = 1 »)
-- : une seconde raison de le retirer, celle-là contraire au modèle.
--
-- UN DROP EST IRRÉVERSIBLE, et c'est assumé : il a été demandé explicitement,
-- après que le nombre de produits concernés a été mesuré et annoncé. La donnée
-- n'est reprise nulle part — elle ne veut plus rien dire dans le nouveau
-- modèle, la conserver n'aiderait personne à revenir en arrière.
--
-- À SAVOIR POUR LA CONSOLE MARQUE (autre dépôt) : ses bascules « au catalogue
-- réseau » et « obligatoire » écrivaient ces colonnes. L'API IGNORE désormais
-- ces champs au lieu de les écrire — un POST qui les envoie encore ne provoque
-- donc pas d'erreur, il n'a simplement aucun effet. Les retirer de l'écran
-- reste à faire là-bas.
--
-- IDEMPOTENTE : chaque DROP est conditionné à la présence de la colonne.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_products');

-- ── D'ABORD LES DÉCLENCHEURS, SINON LE DROP CASSE LA TABLE ────────────────
-- migrations/optional/0044b posait deux TRIGGER sur ws_products dont le corps
-- lit NEW.brand_mandatory. Ils ne passent pas par migrate.sh (« à jouer à la
-- main ») : rien ici ne sait s'ils ont été posés sur cette installation. Or un
-- trigger dont le corps référence une colonne disparue ne devient pas inerte —
-- il fait ÉCHOUER chaque INSERT et chaque UPDATE sur ws_products. Toute la
-- console marque, tout import ERP : en erreur, et le lien avec un DROP joué la
-- veille serait introuvable.
-- Ils n'ont d'ailleurs plus rien à garder : le verrou « obligatoire » n'existe
-- plus. DROP IF EXISTS ne coûte rien là où ils n'ont jamais été posés.
DROP TRIGGER IF EXISTS trg_ws_products_mandatory_bi;
DROP TRIGGER IF EXISTS trg_ws_products_mandatory_bu;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'brand_whitelist');
SET @s := IF(@t = 0 OR @c = 0, 'DO 0', 'ALTER TABLE ws_products DROP COLUMN brand_whitelist');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'brand_mandatory');
SET @s := IF(@t = 0 OR @c = 0, 'DO 0', 'ALTER TABLE ws_products DROP COLUMN brand_mandatory');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- RATTRAPAGE : une base où `webshop` ET `click_and_collect` coexistent. C'est
-- arrivé au rejeu double avant que la 0071 soit gardée — elle recréait
-- `webshop` après le renommage de la 0072. La colonne en trop n'est lue par
-- personne ; on la retire, sinon elle traîne indéfiniment sur les bases qui ont
-- connu cet état.
SET @a := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'webshop');
SET @b := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'ws_products' AND column_name = 'click_and_collect');
SET @s := IF(@t = 0 OR @a = 0 OR @b = 0, 'DO 0', 'ALTER TABLE ws_products DROP COLUMN webshop');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
