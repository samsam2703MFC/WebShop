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

  /* GET /availability/days?shopId=&mode=&from=YYYY-MM-DD&to=YYYY-MM-DD
     Computes, for each date in the range, whether the shop is orderable:
     open weekday (ISO day in *_open_days) AND not a 'closed' exception. */
  r.get('/availability/days', wrap(async (req, res) => {
    const { shopId, mode = 'collect' } = req.query;
    if (!shopId) return res.status(400).json({ error: 'shopId requis' });
    const from = req.query.from || new Date().toISOString().slice(0, 10);
    const to = req.query.to || new Date(Date.now() + 30 * 86400e3).toISOString().slice(0, 10);

    const [[av]] = await db.query('SELECT collect_open_days, delivery_open_days FROM ws_shop_availability WHERE shop_id = ?', [shopId]);
    const col = mode === 'delivery' ? 'delivery_open_days' : 'collect_open_days';
    let openDays = av && av[col] ? JSON.parse(av[col]) : (mode === 'delivery' ? [1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6]);

    const [exc] = await db.query(
      `SELECT DATE_FORMAT(exception_date, '%Y-%m-%d') AS d, type
         FROM ws_shop_exceptions WHERE shop_id = ? AND exception_date BETWEEN ? AND ?`,
      [shopId, from, to]
    );
    const closed = new Set(exc.filter((e) => e.type === 'closed').map((e) => e.d));

    const days = [];
    for (let d = new Date(from + 'T00:00:00Z'), end = new Date(to + 'T00:00:00Z'); d <= end; d.setUTCDate(d.getUTCDate() + 1)) {
      const iso = d.toISOString().slice(0, 10);
      const isoDay = ((d.getUTCDay() + 6) % 7) + 1;   // Mon=1 … Sun=7
      let reason = null;
      if (!openDays.includes(isoDay)) reason = 'closed';
      else if (closed.has(iso)) reason = 'holiday';
      days.push({ date: iso, available: !reason, reason });
    }
    res.json(days);
  }));

  return r;
}
