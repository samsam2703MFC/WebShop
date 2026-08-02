/* =====================================================================
   WSPayments — allowed payment methods per shop AND per profile.
     window.WSPayments.endpoint = BASE_URL + '/payment-methods';

   GET {endpoint}?shopId=&profile=guest|registered|company&companyId=&mode=
     -> [{ method: 'stripe'|'shop'|'deferred', label }]

   `mode` est indispensable : en livraison, le serveur écarte « paiement en
   boutique » — le client ne s'y rend jamais. Sans ce paramètre il était
   proposé, et la commande partait au bureau sans encaissement possible.

   Returns [] when no endpoint (demo mode); the UI then falls back to its
   built-in list.
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,
    async list({ shopId, profile = 'guest', companyId, mode } = {}) {
      if (!api.endpoint || !shopId) return [];
      try {
        let u = `${api.endpoint}?shopId=${encodeURIComponent(shopId)}&profile=${encodeURIComponent(profile)}`;
        if (companyId) u += `&companyId=${encodeURIComponent(companyId)}`;
        if (mode) u += `&mode=${encodeURIComponent(mode)}`;
        const r = await fetch(u, { credentials: 'include' });
        if (!r.ok) return [];
        const j = await r.json();
        return Array.isArray(j) ? j : [];
      } catch (_) { return []; }
    },
  };
  window.WSPayments = api;
})();
