/* =====================================================================
   WSTours — delivery tours (tournées) API stub
   ---------------------------------------------------------------------
   The UI must NEVER read W_TOURS directly. It calls these helpers,
   which default to the in-memory W_TOURS seed while no backend is wired.
   To wire a real backend, set:
     window.WSTours.endpoint = 'https://your-host/tours';
   Endpoints expected:
     GET  {endpoint}?shopId=             -> Tour[]
     GET  {endpoint}/:id                 -> Tour
   Tour shape: { id, name, shopId, window, days, active }
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,

    /* List all tours, optionally filtered by shopId. */
    async list({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const qs = shopId ? `?shopId=${encodeURIComponent(shopId)}` : '';
          const r = await fetch(`${api.endpoint}${qs}`, { credentials: 'include' });
          if (r.ok) {
            const j = await r.json();
            return Array.isArray(j) ? j : (j.tours || j.data || []);
          }
        } catch (_) {}
      }
      // Fallback: W_TOURS constant from webshop-full-bundle.jsx.
      // TODO[BACKEND]: remove once GET /tours is live.
      const seed = window.W_TOURS;
      if (!seed) return [];
      const all = Object.values(seed);
      return shopId ? all.filter((t) => t.shopId === shopId) : all;
    },

    /* Get a single tour by id. */
    async get(id) {
      if (!id) return null;
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      // Fallback.
      return (window.W_TOURS && window.W_TOURS[id]) || null;
    },
  };

  window.WSTours = api;
})();
