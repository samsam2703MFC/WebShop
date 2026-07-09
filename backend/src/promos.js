/* Promos & vouchers bridge → ws_pricing_rules, ws_vouchers. */
import { Router } from 'express';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

export function createPromosRouter(db) {
  const r = Router();

  /* GET /pricing/promos/cross-portion?shopId= — the 4+1 rule for a shop. */
  r.get('/pricing/promos/cross-portion', wrap(async (req, res) => {
    const { shopId } = req.query;
    const [[row]] = await db.query(
      `SELECT x AS buy, y AS free, threshold, label
         FROM ws_pricing_rules
        WHERE rule_type = 'cross_portion' AND active = 1 AND (shop_id = ? OR shop_id IS NULL)
        ORDER BY shop_id IS NULL LIMIT 1`,
      [shopId || null]
    );
    res.json(row
      ? { active: true, buy: row.buy, free: row.free, threshold: row.threshold, scope: 'crossPortion', label: row.label }
      : { active: false });
  }));

  /* POST /vouchers/redeem — { code, subtotal }. */
  r.post('/vouchers/redeem', wrap(async (req, res) => {
    const { code, subtotal = 0 } = req.body || {};
    const [[v]] = await db.query(
      `SELECT code, type, value, min_order FROM ws_vouchers
        WHERE code = ? AND active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_uses IS NULL OR used_count < max_uses) LIMIT 1`,
      [String(code || '').trim().toUpperCase()]
    );
    if (!v) return res.json({ ok: false, message: 'Code invalide' });
    if (Number(subtotal) < Number(v.min_order)) {
      return res.json({ ok: false, message: `Minimum ${v.min_order} €` });
    }
    let discount = 0;
    if (v.type === 'percent') discount = Math.round(Number(subtotal) * Number(v.value)) / 100;
    else if (v.type === 'fixed') discount = Number(v.value);
    res.json({ ok: true, discount, voucher: { code: v.code, type: v.type, value: v.value }, message: 'Code appliqué' });
  }));

  return r;
}
