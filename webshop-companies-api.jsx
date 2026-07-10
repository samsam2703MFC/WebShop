/* =====================================================================
   WSCompanies — B2B company accounts an e-mail may order for.
   Used by the checkout to offer "commander pour une entreprise".

   To wire a real backend, set:
     window.WSCompanies.endpoint = BASE_URL + '/companies';

   GET {endpoint}?email=<email>  ->  [{ id, name, vat, deferredBilling }]
     deferredBilling=true  -> the company allows "sur compte" (facturation)
     deferredBilling=false -> pay by company card ("Je paie pour ma société ?")

   While endpoint is null we return [] (no company accounts in demo mode).
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,
    _cache: {},

    async list(email) {
      const e = (email || '').trim().toLowerCase();
      if (!e || !api.endpoint) return [];
      if (api._cache[e]) return api._cache[e];
      try {
        const r = await fetch(`${api.endpoint}?email=${encodeURIComponent(e)}`, { credentials: 'include' });
        if (!r.ok) return [];
        const j = await r.json();
        const list = Array.isArray(j) ? j : (j.companies || []);
        api._cache[e] = list;
        return list;
      } catch (_) { return []; }
    },

    setEndpoint(url) { api.endpoint = url || null; api._cache = {}; },
  };
  window.WSCompanies = api;
})();
