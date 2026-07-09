/* Delivery network bridge → ws_tours, ws_offices, ws_office_delivery_sites,
   ws_delivery_fee_rules. */
import { Router } from 'express';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

export function createNetworkRouter(db) {
  const r = Router();

  /* GET /tours?shopId= — delivery tours (optionally filtered by shop). */
  r.get('/tours', wrap(async (req, res) => {
    const { shopId } = req.query;
    const [rows] = shopId
      ? await db.query('SELECT id, shop_id AS shopId, name FROM ws_tours WHERE active = 1 AND shop_id = ?', [shopId])
      : await db.query('SELECT id, shop_id AS shopId, name FROM ws_tours WHERE active = 1');
    res.json(rows);
  }));

  /* GET /offices — validated B2B offices. */
  r.get('/offices', wrap(async (_req, res) => {
    const [rows] = await db.query(
      `SELECT id, tour_id AS tourId, name, address, postal_code AS postalCode, city,
              contact, email, phone, vat, status
         FROM ws_offices WHERE status = 'validated' AND active = 1`
    );
    res.json(rows);
  }));

  /* GET /offices/:id — one office + its delivery sites. */
  r.get('/offices/:id', wrap(async (req, res) => {
    const [[o]] = await db.query('SELECT * FROM ws_offices WHERE id = ?', [req.params.id]);
    if (!o) return res.status(404).json({ error: 'Office introuvable' });
    const [sites] = await db.query(
      'SELECT id, name, address, floor_room AS floorRoom, contact_name AS contactName, shop_id AS shopId FROM ws_office_delivery_sites WHERE office_client_id = ? AND active = 1',
      [req.params.id]
    );
    res.json({ ...o, sites });
  }));

  /* POST /delivery-fees/quote — resolve the most specific active fee rule.
     Priority: site > office > tour > shop > global. */
  r.post('/delivery-fees/quote', wrap(async (req, res) => {
    const { siteId = null, officeClientId = null, tourId = null, shopId = null } = req.body || {};
    const [rows] = await db.query(
      `SELECT id, level, free_delivery AS freeDelivery, always_charge AS alwaysCharge,
              fee_amount AS feeAmount, free_delivery_minimum AS freeDeliveryMinimum, payment_type AS paymentType
         FROM ws_delivery_fee_rules
        WHERE active = 1 AND (
              (level = 'site'   AND site_id          = ?) OR
              (level = 'office' AND office_client_id = ?) OR
              (level = 'tour'   AND tour_id          = ?) OR
              (level = 'shop'   AND shop_id          = ?) OR
              (level = 'global'))
        ORDER BY FIELD(level, 'site', 'office', 'tour', 'shop', 'global') LIMIT 1`,
      [siteId, officeClientId, tourId, shopId]
    );
    res.json(rows[0] || null);
  }));

  return r;
}
