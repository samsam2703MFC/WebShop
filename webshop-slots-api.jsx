// webshop-slots-api.jsx — window.WSSlots
//
// Delivery SLOTS for the active office. A "slot" is a delivery window served by
// the office's tour (ws_tour_availability): each row = one window (morning
// 'midi', evening/afternoon 'soir') with its own delivery time + order cutoff.
// Per-tour: only offices whose tour has an evening window get the 'soir' slot.
//
// The frontend renders ONLY what this API returns (no hour/label/colour is
// hardcoded in the components). Contract consumed by webshop-full-bundle.jsx:
//   listSlots({ officeId, date }) -> [{ slot_type, route_id, orderable,
//                                       cutoff_label, cta:{theme,icon,label} }, …]
//   nextSlot({ officeId, date })  -> one slot object (default selection) | null
//   requestEvening({ officeId })  -> { ok }   (customer asks for an evening run)
//
// In production, set WSSlots.endpoint (api-config.js) to BASE_URL + '/slots';
// php-api reads ws_tour_availability and computes `orderable` from cutoff_time.
(function () {
  const api = {
    endpoint: null,

    async listSlots({ officeId, date } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(
            `${api.endpoint}?officeId=${encodeURIComponent(officeId || '')}` +
            `&date=${encodeURIComponent(date || '')}`,
            { credentials: 'include' }
          );
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      // DEMO fallback: an office whose tour runs midday AND evening.
      // (Prod: one row per window in ws_tour_availability; `orderable` computed
      //  server-side from cutoff_time vs now for the requested date.)
      return [
        {
          slot_type: 'midi', route_id: 'midi',
          delivery_time: '12:00', cutoff: '11:00', cutoff_label: '11h00',
          orderable: true,
          cta: { theme: 'lunch', icon: 'lunch', label: 'Midi' },
        },
        {
          slot_type: 'soir', route_id: 'soir',
          delivery_time: '17:00', cutoff: '15:00', cutoff_label: '15h00',
          orderable: true,
          cta: { theme: 'evening', icon: 'evening', label: 'Soirée' },
        },
      ];
    },

    async nextSlot({ officeId, date } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(
            `${api.endpoint}/next?officeId=${encodeURIComponent(officeId || '')}` +
            `&date=${encodeURIComponent(date || '')}`,
            { credentials: 'include' }
          );
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      const list = await api.listSlots({ officeId, date });
      return list.find((s) => s.orderable !== false) || list[0] || null;
    },

    async requestEvening({ officeId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/request-evening`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ officeId }),
          });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return { ok: true };
    },
  };

  window.WSSlots = api;
})();
