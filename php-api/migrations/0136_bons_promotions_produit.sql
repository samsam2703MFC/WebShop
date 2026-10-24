-- 0136 — Bons : PROMOTIONS PRODUIT déclenchées par le code.
-- Le module marketing pose, à l'étape « Prix » d'une campagne, une promotion
-- produit par produit (−15 %, prix barré, 2 achetés → 1 offert). Un bon de la
-- campagne peut les déclencher : au webshop, elles ne valent qu'avec ce code.
--
-- Une ligne = une promotion d'un produit, rattachée à la campagne de bons
-- (voucher_campaign.id) qui porte le code. Le moteur (voucher_promos.php) les
-- chiffre, la même fonction pour l'aperçu du panier et pour la commande.
--   mechanic   : PERCENT | CROSSED_PRICE | BUY_X_GET_Y
--   percent    : remise en %, pour PERCENT
--   price      : prix TTC imposé à la pièce entière, pour CROSSED_PRICE
--   buy_qty / get_qty : x achetées → y offertes, pour BUY_X_GET_Y
--   max_qty    : pièces au plus qui en profitent par commande ; NULL = sans limite
--   cumulative : 0 = une pièce déjà dans l'offre croisée « 4 + 1 » n'en profite pas
-- Table du webshop (préfixe ws_), pas une table ERP : rien n'est ajouté aux
-- tables de promotions de l'ERP, que la caisse lit — ces promotions ne valent
-- qu'au webshop. Pas de clé étrangère vers voucher_campaign : le socle de test
-- n'a pas les tables de bons de l'ERP. Idempotente (IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS ws_voucher_product_promos (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_voucher_campaign INT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED NOT NULL,
  mechanic            VARCHAR(20)  NOT NULL,
  percent             DECIMAL(5,2)     NULL,
  price               DECIMAL(10,2)    NULL,
  buy_qty             SMALLINT UNSIGNED NULL,
  get_qty             SMALLINT UNSIGNED NULL,
  max_qty             SMALLINT UNSIGNED NULL,
  cumulative          TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          SMALLINT     NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vpp_campaign_product (id_voucher_campaign, product_id),
  KEY idx_vpp_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
