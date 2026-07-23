/* =====================================================================
   WSOffices — offices API stub
   ---------------------------------------------------------------------
   The UI must NEVER hardcode offices. It calls these helpers, which
   default to the in-memory _AUTH_STORE (already seeded for the demo).
   To wire a real backend, set:
     window.WSOffices.endpoint = 'https://your-host/offices';
   …and the helpers will switch from local-store to live HTTP.
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,

    // List APPROVED offices for a given (preferred) shop that the customer
    // can self-link to. Offices belong to a shop → always scope by shopId.
    async listApproved(shopId) {
      if (api.endpoint) {
        try {
          const q = '?status=validated' + (shopId ? '&shopId=' + encodeURIComponent(shopId) : '');
          const r = await fetch(api.endpoint + q, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (e) { /* fall through */ }
      }
      const store = (window._AUTH_STORE && window._AUTH_STORE.offices) || {};
      return Object.values(store).filter((o) =>
        o && o.status === 'validated' && (!shopId || o.shopId === shopId || o.preferredShopId === shopId));
    },

    // "My office isn't listed" — ask the franchise to contact it.
    // Does NOT create or link an office; it sends a request to the franchise.
    async contactFranchise(payload) {
      if (api.endpoint) {
        try {
          const r = await fetch(api.endpoint + '/contact', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
          if (r.ok) return await r.json();
          const j = await r.json().catch(() => ({}));
          return { ok: false, error: j.message || 'Échec de l\'envoi.' };
        } catch (e) { /* fall through */ }
      }
      return { ok: false, error: 'Service indisponible — demande non envoyée.' }; // Go-live : jamais de faux succès
    },

    // Get a single office by id (any status).
    async get(id) {
      if (!id) return null;
      if (api.endpoint) {
        try {
          const r = await fetch(api.endpoint + '/' + encodeURIComponent(id), { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (e) { /* fall through */ }
      }
      const store = (window._AUTH_STORE && window._AUTH_STORE.offices) || {};
      return store[id] || null;
    },

    // Submit a new office request — saved as PENDING approval.
    async requestNew(payload) {
      if (api.endpoint) {
        try {
          const r = await fetch(api.endpoint, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
          if (r.ok) return await r.json();
        } catch (e) { /* fall through */ }
      }
      // Go-live : plus de creation locale fictive de bureau.
      throw new Error('API bureaux indisponible — demande non enregistrée.');
    },
  };
  window.WSOffices = api;
})();
