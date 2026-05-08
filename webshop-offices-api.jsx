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

    // List APPROVED offices the customer can self-link to.
    async listApproved() {
      if (api.endpoint) {
        try {
          const r = await fetch(api.endpoint + '?status=validated', { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (e) { /* fall through */ }
      }
      const store = (window._AUTH_STORE && window._AUTH_STORE.offices) || {};
      return Object.values(store).filter((o) => o && o.status === 'validated');
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
      const store = window._AUTH_STORE && window._AUTH_STORE.offices;
      if (!store) throw new Error('offices store unavailable');
      const id = 'off-' + Date.now().toString(36);
      const office = {
        id,
        status: 'pending',
        tourId: null,
        ...payload,
      };
      store[id] = office;
      return office;
    },
  };
  window.WSOffices = api;
})();
