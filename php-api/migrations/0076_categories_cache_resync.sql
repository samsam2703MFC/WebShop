-- 0076 — Resynchronisation du cache ws_categories.active (même geste que 0069).
--
-- Constat du 14/08 en production : Traiteur porteur de 33 produits actifs et
-- pourtant marqué INACTIVE. Cause : la cascade « Actif » par SOUS-CATÉGORIE
-- écrivait ws_products.active sans resynchroniser le cache — les routes
-- produit et catégorie le faisaient, elle non (corrigé dans l'API avec cette
-- migration). L'écran Stock du jour du franchisé filtrait sur ce cache : les
-- 33 produits étaient au BO marque et sur le webshop, invisibles chez le
-- franchisé.
--
-- Cette migration remet le cache d'équerre UNE fois ; l'API le tient à jour
-- ensuite, et l'écran franchisé ne filtre plus dessus de toute façon.
-- IDEMPOTENTE : recalcul complet, rejouable à l'identique.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'ws_categories');
SET @s := IF(@t = 0, 'DO 0',
  'UPDATE ws_categories c
      SET c.active = EXISTS(SELECT 1 FROM ws_products p
                             WHERE p.cat_id = c.id AND p.active = 1)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
