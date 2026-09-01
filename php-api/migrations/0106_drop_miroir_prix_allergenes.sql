-- 0106 — Suppression de deux tables du miroir produits devenues sans effet.
--
-- CE QUI EST SUPPRIMÉ, ET POURQUOI SEULEMENT CELLES-LÀ.
--
--   ws_product_prices (16 lignes) — depuis la bascule du 31/08, le prix
--   annoncé et débité est portion_price servi par l'ERP. Cette table ne fixait
--   plus rien, mais POST /admin/price y écrivait encore et répondait « ok » :
--   on enregistrait un montant que personne ne débitait jamais. Une deuxième
--   source silencieuse — exactement ce que le résolveur unique existe pour
--   empêcher, et ce qui a déjà coûté cher trois fois ici.
--
--   ws_product_allergens (20 lignes) — les allergènes servis viennent de
--   l'ERP (42 produits sur 81 en portent, vocabulaire `cereals_gluten`). La
--   sous-requête locale était écrasée juste après : 20 lignes ne pouvaient
--   pas expliquer 42 produits documentés.
--
-- Les deux sont des FEUILLES : l'inventaire 0105 l'a établi contre le schéma
-- réel, aucune clé étrangère ne les retient. C'est le contrôle qui manquait à
-- 0102 ce matin, qui avait échoué en ERROR 1828.
--
-- CE QUI N'EST PAS SUPPRIMÉ, ET QUI NE PEUT PAS L'ÊTRE ICI.
--
--   product, product_portion, product_availability_period_connection,
--   shop_product_portion_price appartiennent à Franchise Buddy : elles vivent
--   dans CE schéma et `product` est retenue par treize clés étrangères venant
--   de transaction_product, todo_task et onze tables promotion_*. Les
--   supprimer ne nettoierait pas un miroir, ça casserait l'ERP. Cette décision
--   n'appartient pas à ce dépôt.
--
--   ws_products (666 lignes, 9 clés étrangères), ws_categories,
--   ws_category_subs, ws_product_shops, ws_product_stock,
--   ws_product_stock_defaults, ws_shop_availability sont encore lues par le
--   code qui sert la boutique — ws_products à elle seule 74 fois. Les
--   supprimer aujourd'hui n'est pas une migration, c'est une refonte.
--
--   ws_i18n (806 lignes) porte les libellés de l'INTERFACE (scope='ui'), que
--   nul endpoint ne sert. Elle n'est pas un miroir.
--
-- Idempotente : DROP TABLE IF EXISTS.

DROP TABLE IF EXISTS ws_product_prices;
DROP TABLE IF EXISTS ws_product_allergens;

-- Ce qui reste du miroir, dit dans le journal de déploiement.
SELECT table_name AS `table_restante`, table_rows AS lignes
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('ws_product_prices','ws_product_allergens')
 ORDER BY table_name;
