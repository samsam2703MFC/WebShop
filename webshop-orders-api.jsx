/* =====================================================================
   WSOrders — order placement API stub
   ---------------------------------------------------------------------
   The checkout wizard calls WSOrders.place(payload) to confirm an order.
   To wire a real backend, set:
     window.WSOrders.endpoint = 'https://your-host/orders';
   Endpoints expected:
     POST {endpoint}                     -> { orderId, status, total, paymentUrl? }
     GET  {endpoint}/:id                 -> Order
     GET  {endpoint}/me                  -> Order[]  (current customer)
     POST {endpoint}/:id/cancel          -> { ok }

   Request body for POST {endpoint}:
   {
     shopId, mode,
     slot:     { date, slotId, label },
     basket:   [{ productId, qty, portion?, options?, bundleId?, bundleSlots? }],
     voucher:  "CODE" | null,
     customer: { id?, email, firstName, lastName, phone, officeId? },
     payment:  { method },
     delivery: { officeId?, tourId?, address? }   // delivery mode only
   }
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,

    /* Place an order. Throws on network / server error. */
    async place(payload) {
      if (api.endpoint) {
        // Forward the bearer token (if logged in) so the backend links the
        // order to the customer's account.
        let authHeader = {};
        try { const t = localStorage.getItem('ws_auth_token'); if (t) authHeader = { 'Authorization': 'Bearer ' + t }; } catch (_) {}
        const r = await fetch(api.endpoint, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeader },
          body: JSON.stringify(payload),
        });
        const j = await r.json().catch(() => ({}));
        if (r.ok) return { ok: true, ...j };
        // L'API renvoie {error:"…", detail:"…"} — on AFFICHE la cause réelle
        // (avant : « Erreur 500 » générique alors que le motif était dans la réponse).
        const base = (typeof j.error === 'string' && j.error) ? j.error : (j.error?.message || `Erreur ${r.status}`);
        throw new Error(base + (j.detail ? (' — ' + j.detail) : ''));
      }
      // Go-live : plus de simulation de commande. Sans API configurée, on
      // refuse — une commande ne peut jamais « réussir » à blanc.
      throw new Error('API commandes indisponible — commande non enregistrée.');
    },

    /* Fetch a single order (e.g. for the confirmation page). */
    async get(id) {
      if (!id) return null;
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return null;
    },

    /* List the current customer's orders. */
    async listMine() {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/me`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return [];
    },

    /* Cancel an order (server enforces cutoff rules). */
    async cancel(id) {
      if (!id) return { ok: false };
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}/cancel`, {
            method: 'POST', credentials: 'include',
          });
          const j = await r.json().catch(() => ({}));
          return { ok: r.ok, ...j };
        } catch (_) {}
      }
      return { ok: false, error: 'API commandes indisponible' }; // Go-live : jamais de faux succès
    },
  };

  window.WSOrders = api;
})();
