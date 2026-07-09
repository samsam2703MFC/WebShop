/* Availability & calendar bridge → ws_shop_availability, ws_calendar_rules,
   ws_slots, ws_shop_exceptions. */
import { Router } from 'express';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

export function createAvailabilityRouter(db) {
  const r = Router();

  /* GET /availability/settings?shopId= — the shop's opening config. */
  r.get('/availability/settings', wrap(async (req, res) => {
    const { shopId } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [[row]] = await db.query('SELECT * FROM ws_shop_availability WHERE shop_id = ?', [shopId]);
    res.json(row || {});
  }));

  /* GET /calendar/slots?shopId=&mode= — time slots for a mode. */
  r.get('/calendar/slots', wrap(async (req, res) => {
    const { shopId, mode = 'collect' } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [rows] = await db.query(
      'SELECT id, mode, label, sort_order FROM ws_slots WHERE shop_id = ? AND mode = ? AND active = 1 ORDER BY sort_order',
      [shopId, mode]
    );
    res.json(rows);
  }));

  /* GET /calendar/cutoff?shopId=&mode= — order deadline + open days for a mode. */
  r.get('/calendar/cutoff', wrap(async (req, res) => {
    const { shopId, mode = 'collect' } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [[row]] = await db.query(
      'SELECT cutoff_hour, cutoff_minutes, lead_hours, open_days FROM ws_calendar_rules WHERE shop_id = ? AND mode = ? AND active = 1 LIMIT 1',
      [shopId, mode]
    );
    res.json(row || {});
  }));

  /* GET /calendar/exceptions?shopId= — upcoming closures / modified days. */
  r.get('/calendar/exceptions', wrap(async (req, res) => {
    const { shopId } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const [rows] = await db.query(
      'SELECT exception_date, type, reason FROM ws_shop_exceptions WHERE shop_id = ? AND exception_date >= CURDATE() ORDER BY exception_date',
      [shopId]
    );
    res.json(rows);
  }));

  return r;
}
