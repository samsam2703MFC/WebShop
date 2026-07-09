/* Orders bridge → ws_orders, ws_order_lines (server-side pricing from
   ws_product_prices, per-day stock decrement in ws_product_stock). */
import { Router } from 'express';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

export function createOrdersRouter(db) {
  const r = Router();

  /* POST /orders — { shopId, mode, basket:[{productId, qty, portion?}], customerId?,
     slotId?, slotLabel?, deliveryDate?, paymentMethod?, lang? }.
     Prices are computed server-side; client totals are ignored. */
  r.post('/orders', wrap(async (req, res) => {
    const {
      shopId, mode = 'collect', basket = [], customerId = null,
      slotId = null, slotLabel = null, deliveryDate = null,
      paymentMethod = 'cash', lang = 'fr',
    } = req.body || {};
    if (!shopId || !Array.isArray(basket) || !basket.length) {
      return res.status(400).json({ error: 'shopId et basket requis' });
    }

    // 1. Server-side pricing from the DB (never trust client prices).
    let subtotal = 0; const lines = [];
    for (const item of basket) {
      const [[p]] = await db.query(
        `SELECT p.id, p.name, COALESCE(pp.price, p.price) AS price
           FROM ws_products p
           LEFT JOIN ws_product_prices pp ON pp.product_id = p.id AND pp.shop_id = ? AND pp.active = 1
          WHERE p.id = ? AND p.active = 1`,
        [shopId, item.productId]
      );
      if (!p) continue;
      const qty = Math.max(1, Number(item.qty) || 1);
      subtotal += Number(p.price) * qty;
      lines.push({ productId: p.id, name: p.name, qty, unit: Number(p.price), portion: item.portion || null });
    }
    if (!lines.length) return res.status(400).json({ error: 'aucun produit valide' });
    const total = Math.round(subtotal * 100) / 100;
    const ref = 'WS-' + Date.now();

    // 2. Persist order + lines.
    const [ins] = await db.query(
      `INSERT INTO ws_orders
         (order_ref, shop_id, customer_id, mode, status, slot_id, slot_label, delivery_date,
          subtotal, total, payment_method, payment_status, lang, delivery_mode)
       VALUES (?,?,?,?, 'pending', ?,?,?, ?,?, ?, 'pending', ?, ?)`,
      [ref, shopId, customerId, mode, slotId, slotLabel, deliveryDate,
       total, total, paymentMethod, lang, mode === 'delivery' ? 'office_delivery' : 'collect']
    );
    const orderId = ins.insertId;
    for (const l of lines) {
      await db.query(
        'INSERT INTO ws_order_lines (order_id, product_id, product_name, qty, unit_price, `portion`) VALUES (?,?,?,?,?,?)',
        [orderId, l.productId, l.name, l.qty, l.unit, l.portion]
      );
      // Decrement per-day stock where it's tracked (ignored if no row for today).
      await db.query(
        `UPDATE ws_product_stock SET qty_sold = qty_sold + ?
          WHERE product_id = ? AND shop_id = ? AND date = CURDATE() AND (mode = ? OR mode IS NULL)`,
        [l.qty, l.productId, shopId, mode]
      );
    }

    res.json({ ok: true, orderId, orderRef: ref, total });
  }));

  /* GET /orders/:id — by numeric id or order_ref, with its lines. */
  r.get('/orders/:id', wrap(async (req, res) => {
    const [[o]] = await db.query('SELECT * FROM ws_orders WHERE id = ? OR order_ref = ? LIMIT 1', [req.params.id, req.params.id]);
    if (!o) return res.status(404).json({ error: 'Commande introuvable' });
    const [lines] = await db.query('SELECT * FROM ws_order_lines WHERE order_id = ?', [o.id]);
    res.json({ ...o, lines });
  }));

  return r;
}
