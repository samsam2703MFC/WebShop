// webshop-vouchers.jsx : Voucher loading/validation + deep-link param parsing.
// Loaded into the storefront BEFORE webshop-full-bundle.jsx so the helpers
// are global by the time CheckoutWizard / ShopFrame need them.

(function () {
  // ─────────────────────────────────────────────────────────────────────────
  // GO-LIVE : les bons vivent en base (promotion → voucher_campaign →
  // voucher_code) et SEUL le serveur peut les valider : il connaît les
  // compteurs d'utilisation, le ciblage client/bureau, le périmètre produit
  // et les dates. L'ancien couple loadVouchers()/validateVoucher() lisait
  // localStorage et recalculait la remise dans le navigateur : un code
  // pouvait être « accepté » sans exister en base, ou une remise différer
  // de celle réellement facturée. Supprimé, aucune validation locale.
  // ─────────────────────────────────────────────────────────────────────────

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
      if (p.get('shop') || p.get('shopId')) out.shopId = p.get('shop') || p.get('shopId'); // accepte ?shop= et ?shopId=
      if (p.get('mode'))      out.mode      = p.get('mode');
      if (p.get('voucher'))   out.voucher   = p.get('voucher').toUpperCase();
      if (p.get('category'))  out.cat       = p.get('category');
      if (p.get('sub'))       out.sub       = p.get('sub');      // sous-catégorie active (lien direct)
      if (p.get('product'))   out.productId = p.get('product');
      if (p.get('open') === 'product' && p.get('product')) out.openProduct = p.get('product');
      return out;
    } catch (e) { return {}; }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // WSVouchers : API stub for voucher redemption.
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
    async redeem({ code, shopId, subtotal, basket, customerId } = {}) {
      if (WSVouchers.endpoint) {
        try {
          const r = await fetch(`${WSVouchers.endpoint}/redeem`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, shopId, subtotal, basket, customerId: customerId ?? null }),
          });
          const j = await r.json();
          return j; // server returns { ok, voucher, discount, message } or { ok:false, reason, message }
        } catch (_) {}
      }
      // Le serveur est le SEUL juge d'un code : injoignable ou non configuré,
      // on refuse : jamais de validation « à blanc » côté client.
      return { ok: false, reason: 'offline', message: 'Service codes promo indisponible, réessayez.' };
    },

    /* Bons DISPONIBLES pour ce client + boutique (marketing : affichage +
       application en un clic). Renvoie [] sans endpoint ou en cas d'erreur. */
    async available({ shopId, customerId, subtotal } = {}) {
      if (!WSVouchers.endpoint) return [];
      try {
        const qs = new URLSearchParams({ shopId: shopId || '' });
        if (customerId != null && customerId !== '') qs.set('customerId', String(customerId));
        if (subtotal != null) qs.set('subtotal', String(subtotal));
        // `lang` : le libellé du bon (« Livraison offerte », « dès 30 € ») est
        // COMPOSÉ par le serveur : le front reçoit une phrase déjà faite et ne
        // peut donc pas la traduire lui-même.
        const lg = (window.WSI18n && window.WSI18n.getLang && window.WSI18n.getLang()) || '';
        if (lg) qs.set('lang', lg);
        const r = await fetch(`${WSVouchers.endpoint}/available?${qs}`, { credentials: 'include' });
        if (r.ok) { const j = await r.json(); return Array.isArray(j) ? j : []; }
      } catch (_) {}
      return [];
    },

    /* Liste des bons (usage admin) : base uniquement, sinon erreur. */
    async list() {
      if (!WSVouchers.endpoint) throw new Error('API bons non configurée.');
      const r = await fetch(WSVouchers.endpoint, { credentials: 'include' });
      if (!r.ok) throw new Error('Bons indisponibles (HTTP ' + r.status + ').');
      return await r.json();
    },
  };

  Object.assign(window, { parseDeepLink, WSVouchers });
})();
