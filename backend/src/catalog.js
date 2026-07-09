/* Catalog API against the real ws_ schema (backend/schema/ws_schema.sql, INT keys).
 *
 * Express routes that read the DB and return JSON. The frontend (WSShops /
 * WSCatalog) points at them; it never touches MySQL directly. Per-shop price
 * comes from ws_product_prices (fallback to the global ws_products.price);
 * per-day stock from ws_product_stock. Shop is identified by its INT id.
 *
 * Mount:  app.use(createCatalogRouter(webshopDb))
 */
import { Router } from 'express';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e);
  res.status(500).json({ error: 'Erreur interne' });
});

export function createCatalogRouter(db) {
  const r = Router();

  /* GET /shops — all active shops. */
  r.get('/shops', wrap(async (_req, res) => {
    const [rows] = await db.query(
      `SELECT id, slug, name, city, email, phone, accent, tint, logo_url,
              TRIM(CONCAT_WS(' ', street, street_num)) AS address
         FROM ws_shops WHERE active = 1 ORDER BY name`
    );
    res.json(rows);
  }));

  /* GET /catalog/categories?shopId= — a shop's categories. */
  r.get('/catalog/categories', wrap(async (req, res) => {
    const { shopId } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [rows] = await db.query(
      `SELECT id, slug, label, img, sort_order
         FROM ws_categories
        WHERE active = 1 AND (shop_id = ? OR shop_id IS NULL)
        ORDER BY sort_order, label`,
      [shopId]
    );
    res.json(rows);
  }));

  /* GET /catalog/products?shopId= — products available at a shop, with the
     per-shop price, category label and aggregated allergens. */
  r.get('/catalog/products', wrap(async (req, res) => {
    const { shopId } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [rows] = await db.query(
      `SELECT p.id, p.cat_id, p.sub_cat_id, c.label AS category,
              p.name, p.description, p.badge,
              p.portions, p.cross_portion, p.has_menu_options,
              COALESCE(pp.price, p.price) AS price,   -- prix boutique, sinon global
              ps.no_delivery,
              al.allergens
         FROM ws_products p
         JOIN ws_product_shops ps
           ON ps.product_id = p.id AND ps.shop_id = ? AND ps.active = 1
         LEFT JOIN ws_product_prices pp
           ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
         LEFT JOIN ws_categories c ON c.id = p.cat_id
         LEFT JOIN (
              SELECT product_id, JSON_ARRAYAGG(allergen) AS allergens
                FROM ws_product_allergens GROUP BY product_id
         ) al ON al.product_id = p.id
        WHERE p.active = 1
        ORDER BY c.sort_order, p.name`,
      [shopId, shopId]
    );
    res.json(rows.map((x) => ({
      ...x,
      portions: !!x.portions,
      cross_portion: !!x.cross_portion,
      has_menu_options: !!x.has_menu_options,
      no_delivery: !!x.no_delivery,
      // JSON_ARRAYAGG returns a JSON string; parse it (NULL when no allergens).
      allergens: x.allergens ? JSON.parse(x.allergens) : [],
    })));
  }));

  /* GET /catalog/stock?shopId=&date=YYYY-MM-DD&mode=collect
     Available units per product for a day = qty_total - reserved - sold.
     Products with no stock row = unlimited (absent from the result). */
  r.get('/catalog/stock', wrap(async (req, res) => {
    const { shopId, date, mode } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const day = date || new Date().toISOString().slice(0, 10);
    const [rows] = await db.query(
      `SELECT product_id,
              GREATEST(0, qty_total - qty_reserved - qty_sold) AS available
         FROM ws_product_stock
        WHERE shop_id = ? AND date = ? AND active = 1
          AND (mode = ? OR mode IS NULL)`,
      [shopId, day, mode || 'collect']
    );
    res.json(rows);
  }));

  return r;
}
