/* Server-side pricing engine — the single source of truth for money.
   Client-sent prices/totals are NEVER trusted: every order and every
   Stripe amount is computed here from the webshop DB. */
import { webshopDb } from './db.js';

const r2 = (n) => Math.round(n * 100) / 100;

/* VAT split for a TTC amount at a given Belgian rate (6 food / 21 std). */
export function vatSplit(ttc, ratePct) {
  const htva = r2(ttc / (1 + ratePct / 100));
  return { htva, tva: r2(ttc - htva) };
}

/* Resolve delivery fee: site → office → tour → shop → global. */
export async function resolveDeliveryFee({ siteId, officeClientId, tourneeId, shopId, subtotal }) {
  const levels = [
    ['site',   'site_id',          siteId],
    ['office', 'office_client_id', officeClientId],
    ['tour',   'tour_id',          tourneeId],
    ['shop',   'shop_id',          shopId],
  ];
  let rule = null, level = 'global';
  for (const [lvl, col, val] of levels) {
    if (!val) continue;
    const [[r]] = await webshopDb.query(
      `SELECT * FROM ws_delivery_fee_rules WHERE level = ? AND ${col} = ? AND active = 1 LIMIT 1`,
      [lvl, val]
    );
    if (r) { rule = r; level = lvl; break; }
  }
  if (!rule) {
    const [[g]] = await webshopDb.query(
      `SELECT * FROM ws_delivery_fee_rules WHERE level = 'global' AND active = 1 LIMIT 1`
    );
    rule = g || { free_delivery: 0, always_charge: 0, fee_amount: 0, free_delivery_minimum: 0, payment_type: 'immediate' };
  }
  let fee = 0;
  if (!rule.free_delivery) {
    if (rule.always_charge) fee = Number(rule.fee_amount);
    else if (subtotal < Number(rule.free_delivery_minimum)) fee = Number(rule.fee_amount);
  }
  return {
    fee_amount: r2(fee),
    free_delivery: !!rule.free_delivery,
    always_charge: !!rule.always_charge,
    free_delivery_minimum: Number(rule.free_delivery_minimum || 0),
    amount_remaining_for_free:
      fee > 0 && rule.free_delivery_minimum > 0 ? r2(Math.max(0, rule.free_delivery_minimum - subtotal)) : 0,
    payment_type: rule.payment_type || 'immediate',
    resolved_level: level,
  };
}

/* Validate a voucher; returns { ok, discount, voucher } or { ok:false, message }. */
export async function applyVoucher(code, { shopId, subtotal }) {
  if (!code) return { ok: false, message: null };
  const [[v]] = await webshopDb.query('SELECT * FROM ws_vouchers WHERE code = ? AND active = 1', [code]);
  if (!v) return { ok: false, message: 'Code invalide' };
  if (v.expires_at && new Date(v.expires_at) < new Date()) return { ok: false, message: 'Code expiré' };
  if (v.max_uses !== null && v.used_count >= v.max_uses) return { ok: false, message: 'Code épuisé' };
  if (v.shop_id && v.shop_id !== shopId) return { ok: false, message: 'Code non valable dans cette boutique' };
  if (subtotal < Number(v.min_order)) return { ok: false, message: `Minimum de commande €${Number(v.min_order).toFixed(2)}` };
  const discount = v.kind === 'percent' ? r2(subtotal * Number(v.value) / 100) : Math.min(r2(Number(v.value)), subtotal);
  return { ok: true, discount, voucher: { code: v.code, kind: v.kind, value: Number(v.value) } };
}

/* Full server-side quote for a basket.
   basket: [{ productId, qty, portion?, options? }] — qty/ids only, no prices.
   Throws { status, message } on invalid baskets. */
export async function quote({ shopId, mode, basket, voucherCode, officeContext }) {
  if (!Array.isArray(basket) || basket.length === 0) throw { status: 400, message: 'Panier vide' };
  if (!['collect', 'delivery'].includes(mode)) throw { status: 400, message: 'Mode invalide' };

  const lines = [];
  let subtotal = 0;

  for (const item of basket) {
    const qty = Math.max(1, Math.min(99, parseInt(item.qty, 10) || 1));
    const [[p]] = await webshopDb.query(
      `SELECT p.id, p.name, p.price, p.vat_rate, p.no_delivery, p.active,
              ps.available, ps.price_override, ps.delivery_stock
       FROM ws_products p
       LEFT JOIN ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = ?
       WHERE p.id = ?`,
      [shopId, item.productId]
    );
    if (!p || !p.active) throw { status: 422, message: `Produit ${item.productId} indisponible` };
    if (p.available === 0) throw { status: 422, message: `« ${p.name} » indisponible dans cette boutique` };
    if (mode === 'delivery' && p.no_delivery) throw { status: 422, message: `« ${p.name} » est en retrait seulement` };
    if (mode === 'delivery' && p.delivery_stock !== null && qty > p.delivery_stock)
      throw { status: 422, message: `Stock livraison insuffisant pour « ${p.name} »` };

    const unit = Number(p.price_override ?? p.price);
    const lineTtc = r2(unit * qty);
    const { htva, tva } = vatSplit(lineTtc, Number(p.vat_rate));
    lines.push({
      productId: p.id, name: p.name, qty,
      portion: item.portion || null, options: item.options || [],
      unit_price_ttc: unit, vat_rate: Number(p.vat_rate),
      line_ttc: lineTtc, line_htva: htva, line_tva: tva,
    });
    subtotal = r2(subtotal + lineTtc);
  }

  // Collect promo — read from synced ws_promotions (PROMO-WEB5), not hardcoded.
  let discount = 0;
  const discounts = [];
  if (mode === 'collect') {
    const [[promo]] = await webshopDb.query(
      `SELECT * FROM ws_promotions WHERE active = 1 AND kind = 'percent'
       AND (shop_id IS NULL OR shop_id = ?)
       AND (starts_at IS NULL OR starts_at <= CURDATE())
       AND (ends_at IS NULL OR ends_at >= CURDATE()) LIMIT 1`,
      [shopId]
    );
    if (promo) {
      const d = r2(subtotal * Number(promo.value) / 100);
      discount = r2(discount + d);
      discounts.push({ label: promo.label, amount: d });
    }
  }

  const voucher = await applyVoucher(voucherCode, { shopId, subtotal });
  if (voucherCode && !voucher.ok) throw { status: 422, message: voucher.message || 'Code invalide' };
  if (voucher.ok) {
    discount = r2(discount + voucher.discount);
    discounts.push({ label: `Code ${voucher.voucher.code}`, amount: voucher.discount });
  }

  let deliveryFee = null;
  let feeAmount = 0;
  if (mode === 'delivery') {
    deliveryFee = await resolveDeliveryFee({ ...(officeContext || {}), shopId, subtotal });
    feeAmount = deliveryFee.fee_amount;
  }

  const totalTtc = r2(Math.max(0, subtotal - discount + feeAmount));
  // VAT on the goods proportionally after discount; fee is 21% (service).
  const goodsTtc = r2(Math.max(0, subtotal - discount));
  const ratio = subtotal > 0 ? goodsTtc / subtotal : 0;
  let totalHtva = 0, totalTva = 0;
  for (const l of lines) {
    const adj = r2(l.line_ttc * ratio);
    const { htva, tva } = vatSplit(adj, l.vat_rate);
    totalHtva = r2(totalHtva + htva); totalTva = r2(totalTva + tva);
  }
  if (feeAmount > 0) {
    const { htva, tva } = vatSplit(feeAmount, 21);
    totalHtva = r2(totalHtva + htva); totalTva = r2(totalTva + tva);
  }

  return {
    lines, subtotal_ttc: subtotal, discounts, discount_ttc: discount,
    voucher: voucher.ok ? voucher.voucher : null,
    delivery_fee: deliveryFee,
    total_ttc: totalTtc, total_htva: totalHtva, total_tva: totalTva,
    currency: 'EUR',
  };
}
