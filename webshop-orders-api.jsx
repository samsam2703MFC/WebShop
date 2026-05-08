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
        const r = await fetch(api.endpoint, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const j = await r.json();
        if (r.ok) return { ok: true, ...j };
        const msg = j.error?.message || `Erreur ${r.status}`;
        throw new Error(msg);
      }
      // Demo fallback — simulate a successful order placement.
      // TODO[BACKEND]: remove once POST /orders is live.
      return {
        ok: true,
        orderId: 'ord-' + Date.now().toString(36),
        status: 'pending_payment',
        total: payload.total || 0,
        slot: payload.slot?.label || payload.slot?.slotId || '',
        payment: payload.payment?.method || '',
      };
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
      return { ok: true }; // Demo: always succeeds
    },
  };

  window.WSOrders = api;
})();
