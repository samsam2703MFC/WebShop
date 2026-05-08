// webshop-vouchers.jsx — Voucher loading/validation + deep-link param parsing.
// Loaded into the storefront BEFORE webshop-full-bundle.jsx so the helpers
// are global by the time CheckoutWizard / ShopFrame need them.

(function () {
  // ─────────────────────────────────────────────────────────────────────────
  // Read codes from localStorage (admin-managed); fall back to a small seed
  // so the storefront still has real codes before the admin app is opened.
  // Shape mirrors what the admin saves:
  //   { id, code, type, value, minOrder, scope, channel, shopIds,
  //     validFrom, validUntil, usage:{used,limit}, status }
  // ─────────────────────────────────────────────────────────────────────────
  function loadVouchers() {
    try {
      const raw = localStorage.getItem('atelier_vouchers');
      if (raw) {
        const arr = JSON.parse(raw);
        if (Array.isArray(arr) && arr.length) return arr;
      }
    } catch (e) {}
    return [
      { id: 'v_seed1', code: 'BIENVENUE10', type: 'percent', value: 10, minOrder: 0, scope: 'order',
        channel: 'webshop', shopIds: [], validFrom: '2026-01-01', validUntil: '2026-12-31',
        usage: { used: 142, limit: null }, status: 'active' },
      { id: 'v_seed2', code: 'PRINTEMPS5', type: 'amount', value: 5, minOrder: 25, scope: 'order',
        channel: 'webshop', shopIds: [], validFrom: '2026-04-01', validUntil: '2026-06-30',
        usage: { used: 38, limit: 500 }, status: 'active' },
      { id: 'v_seed3', code: 'PAIN-OFF', type: 'percent', value: 15, minOrder: 0, scope: 'category',
        scopeRef: 'pains', channel: 'webshop', shopIds: ['chatelain', 'sablon'],
        validFrom: '2026-05-01', validUntil: '2026-05-31',
        usage: { used: 9, limit: 100 }, status: 'active' },
    ];
  }

  function validateVoucher(code, ctx) {
    ctx = ctx || {};
    if (!code) return { ok: false, reason: 'empty' };
    const all = loadVouchers();
    const v = all.find((x) => x.code.toUpperCase() === String(code).toUpperCase());
    if (!v) return { ok: false, reason: 'unknown', message: 'Code inconnu' };
    if (v.status === 'expired')   return { ok: false, reason: 'expired',   message: 'Ce code a expiré', voucher: v };
    if (v.status === 'exhausted') return { ok: false, reason: 'exhausted', message: "Ce code n'est plus disponible", voucher: v };
    if (v.status === 'scheduled') return { ok: false, reason: 'scheduled', message: "Ce code n'est pas encore actif", voucher: v };
    const now = new Date();
    if (v.validFrom && new Date(v.validFrom) > now) {
      return { ok: false, reason: 'scheduled', message: "Ce code n'est pas encore actif", voucher: v };
    }
    if (v.validUntil) {
      const u = new Date(v.validUntil); u.setHours(23, 59, 59, 999);
      if (u < now) return { ok: false, reason: 'expired', message: 'Ce code a expiré', voucher: v };
    }
    if (v.usage && v.usage.limit && v.usage.used >= v.usage.limit) {
      return { ok: false, reason: 'exhausted', message: "Ce code n'est plus disponible", voucher: v };
    }
    if (v.channel === 'office') {
      return { ok: false, reason: 'channel', message: 'Code réservé aux clients Office', voucher: v };
    }
    if (v.shopIds && v.shopIds.length && ctx.shopId && !v.shopIds.includes(ctx.shopId)) {
      return { ok: false, reason: 'shop', message: 'Code non valable dans cette boutique', voucher: v };
    }
    const sub = ctx.subtotal || 0;
    if (v.minOrder && sub < v.minOrder) {
      return { ok: false, reason: 'minOrder',
        message: `Minimum €${Number(v.minOrder).toFixed(2)} requis`, voucher: v };
    }
    let discount = 0;
    if (v.type === 'percent') discount = sub * (v.value / 100);
    else if (v.type === 'amount') discount = Math.min(v.value, sub);
    // 'shipping' = free shipping signal — not modeled here
    return {
      ok: true, voucher: v, discount,
      message: v.type === 'percent' ? `−${v.value}% appliqué`
            : v.type === 'amount'   ? `−€${Number(v.value).toFixed(2)} appliqué`
            : 'Livraison offerte',
    };
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Deep-link parser. Admin's link generator writes
  //   ?shop=&mode=&voucher=&category=&product=&open=product
  // Honor them at first mount in ShopFrame.
  // ─────────────────────────────────────────────────────────────────────────
  function parseDeepLink() {
    if (typeof window === 'undefined') return {};
    try {
      const p = new URLSearchParams(window.location.search);
      const out = {};
      if (p.get('shop'))      out.shopId    = p.get('shop');
      if (p.get('mode'))      out.mode      = p.get('mode');
      if (p.get('voucher'))   out.voucher   = p.get('voucher').toUpperCase();
      if (p.get('category'))  out.cat       = p.get('category');
      if (p.get('product'))   out.productId = p.get('product');
      if (p.get('open') === 'product' && p.get('product')) out.openProduct = p.get('product');
      return out;
    } catch (e) { return {}; }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // WSVouchers — API stub for voucher redemption.
  // The admin app reads/writes localStorage.atelier_vouchers directly while
  // there is no backend. The storefront always goes through WSVouchers.redeem()
  // at checkout so the seam is clean for production.
  //
  // To wire a real backend, set:
  //   window.WSVouchers.endpoint = 'https://your-host/vouchers';
  // Endpoints expected:
  //   POST {endpoint}/redeem             -> { ok, voucher, discount, message } or { ok:false, reason, message }
  //   GET  {endpoint}                    -> Voucher[]  (admin only)
  // ─────────────────────────────────────────────────────────────────────────
  const WSVouchers = {
    endpoint: null,

    /* Validate + redeem a voucher code server-side.
       Returns { ok, voucher, discount, message } on success,
       or { ok:false, reason, message } on failure. */
    async redeem({ code, shopId, subtotal, basket } = {}) {
      if (WSVouchers.endpoint) {
        try {
          const r = await fetch(`${WSVouchers.endpoint}/redeem`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, shopId, subtotal, basket }),
          });
          const j = await r.json();
          return j; // server returns { ok, voucher, discount, message } or { ok:false, reason, message }
        } catch (_) {}
      }
      // Fallback: client-side validation from localStorage / seed.
      // TODO[BACKEND]: remove once POST /vouchers/redeem is live.
      return validateVoucher(code, { shopId, subtotal });
    },

    /* List all vouchers — admin use only. */
    async list() {
      if (WSVouchers.endpoint) {
        try {
          const r = await fetch(WSVouchers.endpoint, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return loadVouchers();
    },
  };

  Object.assign(window, { loadVouchers, validateVoucher, parseDeepLink, WSVouchers });
})();
