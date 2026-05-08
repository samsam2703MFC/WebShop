/* =====================================================================
   WSPricing — basket pricing / promos / payment-methods API stub
   ---------------------------------------------------------------------
   The frontend MUST NOT contain pricing rules. Every rule below is a
   backend concern; this module is the seam.
   To wire a real backend, set:
     window.WSPricing.endpoint = 'https://your-host/pricing';
   Endpoints expected:
     POST {endpoint}/quote               -> { subtotal, discounts:[…], total, lines:[…] }
       body: { shopId, mode, basket:[{productId, qty, portion?, options?}], voucher? }
     GET  {endpoint}/payment-methods?shopId=&mode=  -> [{id,label,sub}]
     GET  {endpoint}/promos/cross-portion?shopId=   -> { x, y, threshold, label, eligibleCats:[…] }

   ─── FALLBACK BEHAVIOUR ─────────────────────────────────────────────
   The fallback below is intentionally a thin local mirror so the demo
   storefront still computes a sensible total when no backend is wired.
   It is NOT canonical. Production must use the API.
   ===================================================================== */
(function () {
  // TODO[BACKEND]: replace this constant with `GET /promos/cross-portion`.
  // Cross-portion 4+1 promo — frontend reads it through getCrossPortionRule().
  // Every (x+y) eligible portions = x paid + y free. With x:4, y:1 →
  // 4 paid portions earns 1 free. Basket of 5 → 1 free, 10 → 2, 15 → 3.
  const FALLBACK_CROSS_PORTION = {
    x: 4, y: 1, threshold: 4,
    label: '4 quarts achetés, 1 offert (le moins cher)',
    quarterValueFactor: 0.27, // freebie = basePrice × this
  };

  // TODO[BACKEND]: replace with `GET /payment-methods`.
  const FALLBACK_PAYMENT_METHODS = [
    { id: 'bancontact', label: 'Bancontact',   sub: 'Paiement instantané' },
    { id: 'visa',       label: 'Carte bancaire', sub: 'Visa · Mastercard · Amex' },
    { id: 'paypal',     label: 'PayPal',         sub: 'Compte PayPal' },
    { id: 'invoice',    label: 'Facture office', sub: 'Réservé Office validés' },
  ];

  // TODO[BACKEND]: replace with `POST /quote`. The frontend should send
  // basket lines + context, and render whatever the server returns.
  // The fallback below recomputes locally for demo continuity only.
  const FALLBACK_PORTION_UNITS = { quart: 1, demi: 2, entier: 4 };

  function fallbackCrossPortion(basket) {
    if (!Array.isArray(basket) || !basket.length) return null;
    const items = [];
    for (const l of basket) {
      if (!l.crossPortion || !l.portion) continue;
      const u = FALLBACK_PORTION_UNITS[l.portion] || 0;
      if (u <= 0) continue;
      const qv = (l.basePrice || 0) * FALLBACK_CROSS_PORTION.quarterValueFactor;
      const total = u * (l.qty || 0);
      for (let i = 0; i < total; i++) items.push({ price: qv, name: l.name });
    }
    if (!items.length) return null;
    const groupSize = FALLBACK_CROSS_PORTION.x + FALLBACK_CROSS_PORTION.y;
    items.sort((a, b) => a.price - b.price);
    const cycles = Math.floor(items.length / groupSize);
    const freeCount = cycles * FALLBACK_CROSS_PORTION.y;
    let savings = 0; const freeNames = [];
    for (let i = 0; i < freeCount; i++) { savings += items[i].price; freeNames.push(items[i].name); }
    const remainder = items.length % groupSize;
    return {
      eligibleCount: items.length, groupSize, cycles, freeCount, savings, freeNames,
      toNext: cycles >= 1 && remainder === 0 ? 0 : groupSize - remainder,
      status: cycles >= 1 ? (cycles >= 2 ? 'boosted' : 'active') : 'dormant',
      threshold: FALLBACK_CROSS_PORTION.x,
    };
  }

  const api = {
    endpoint: null,

    async getCrossPortionRule({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/promos/cross-portion?shopId=${encodeURIComponent(shopId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return FALLBACK_CROSS_PORTION;
    },

    async listPaymentMethods({ shopId, mode } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/payment-methods?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return FALLBACK_PAYMENT_METHODS;
    },

    /* Quote a basket. Backend returns final totals + every applied
       discount; frontend just renders them. The fallback recomputes
       locally so the demo still adds up to the same numbers. */
    async quote({ shopId, mode, basket, voucher } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/quote`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shopId, mode, basket, voucher }),
          });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      // FALLBACK — demo only. Mirrors the in-page logic.
      const subtotal = (basket || []).reduce((t, l) => t + (l.price || 0) * (l.qty || 0), 0);
      const cross = fallbackCrossPortion(basket);
      const discounts = [];
      if (cross && cross.savings) {
        discounts.push({ code: 'cross-portion', label: cross.cycles >= 1 ? `${cross.freeCount} quart(s) offert(s)` : null, amount: cross.savings, meta: cross });
      }
      // TODO[BACKEND]: collect mode promos must come from `/quote`,
      // not be inferred client-side. This 5% pickup discount is demo seed.
      if (mode === 'collect') {
        discounts.push({ code: 'pickup-5', label: 'Retrait −5%', amount: subtotal * 0.05 });
      }
      // Voucher resolution lives in webshop-vouchers.jsx (also a stub).
      let voucherDiscount = 0; let voucherInfo = null;
      if (voucher && typeof window.validateVoucher === 'function') {
        const v = window.validateVoucher(voucher, { subtotal, shopId });
        if (v && v.ok) { voucherDiscount = v.discount || 0; voucherInfo = { code: voucher, ...v }; }
      }
      if (voucherDiscount) discounts.push({ code: 'voucher', label: voucherInfo?.message, amount: voucherDiscount });
      const totalDiscount = discounts.reduce((t, d) => t + (d.amount || 0), 0);
      const total = Math.max(0, subtotal - totalDiscount);
      return {
        subtotal, discounts, total, voucher: voucherInfo,
        cross, // demo extra so the UI strip can render directly
      };
    },
  };

  if (typeof window !== 'undefined') window.WSPricing = api;
})();
