/* =====================================================================
   WSPayments — allowed payment methods per shop AND per profile.
     window.WSPayments.endpoint = BASE_URL + '/payment-methods';

   GET {endpoint}?shopId=&profile=guest|registered|company&companyId=
     -> [{ method: 'stripe'|'shop'|'deferred', label }]

   Returns [] when no endpoint (demo mode); the UI then falls back to its
   built-in list.
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,
    async list({ shopId, profile = 'guest', companyId } = {}) {
      if (!api.endpoint || !shopId) return [];
      try {
        let u = `${api.endpoint}?shopId=${encodeURIComponent(shopId)}&profile=${encodeURIComponent(profile)}`;
        if (companyId) u += `&companyId=${encodeURIComponent(companyId)}`;
        const r = await fetch(u, { credentials: 'include' });
        if (!r.ok) return [];
        const j = await r.json();
        return Array.isArray(j) ? j : [];
      } catch (_) { return []; }
    },
  };
  window.WSPayments = api;
})();
