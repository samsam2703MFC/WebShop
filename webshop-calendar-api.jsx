/* =====================================================================
   WSCalendar — calendar/slots/cutoff API stub
   ---------------------------------------------------------------------
   The UI must NEVER hardcode dates, slots, days or cutoffs. It calls
   these helpers, which default to the in-memory shop/tour seeds.
   To wire a real backend, set:
     window.WSCalendar.endpoint = 'https://your-host/calendar';
   …and the helpers will switch from local fixtures to live HTTP.

   Endpoints expected on the backend:
     GET  {endpoint}/days?shopId=&mode=&from=&to=     -> [{iso, available, reason?}]
     GET  {endpoint}/slots?shopId=&mode=&date=        -> [{id, label, capacity?}]
     GET  {endpoint}/cutoff?shopId=&mode=             -> {hour, minutes, leadHours}
   ===================================================================== */
(function () {
  function pad(n) { return String(n).padStart(2, '0'); }
  function isoOf(d) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }
  function parseISO(s) { const [y,m,d] = s.split('-').map(Number); return new Date(y, m-1, d); }

  // Fallback rules (used only if no endpoint is configured).
  // NOTE: these are seeded for the demo. In production this code path is
  // replaced by HTTP calls; the frontend never inspects these directly.
  const FALLBACK_RULES = {
    // weekday 1=Mon … 7=Sun (ISO)
    openDays: { collect: [1,2,3,4,5,6], delivery: [1,2,3,4,5] },
    cutoff:   { collect: { hour: 16, minutes: 0, leadHours: 2 },
                delivery: { hour: 11, minutes: 0, leadHours: 20 } },
    slots: {
      collect:  [{id:'s-08', label:'08:00–09:00'},{id:'s-09', label:'09:00–10:00'},
                 {id:'s-10', label:'10:00–11:00'},{id:'s-12', label:'12:00–13:00'},
                 {id:'s-14', label:'14:00–15:00'},{id:'s-16', label:'16:00–17:00'}],
      delivery: [{id:'d-am', label:'08:30–10:30'},{id:'d-mid', label:'11:30–13:30'}],
    },
  };

  const api = {
    endpoint: null,
    async listDays({ shopId, mode, from, to }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/days?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      const open = FALLBACK_RULES.openDays[mode] || FALLBACK_RULES.openDays.collect;
      const out = [];
      const start = parseISO(from), end = parseISO(to);
      for (let d = new Date(start); d <= end; d.setDate(d.getDate()+1)) {
        const w = ((d.getDay()+6)%7)+1; // 1..7 Mon..Sun
        const ok = open.includes(w);
        out.push({ iso: isoOf(d), available: ok, reason: ok ? null : 'closed' });
      }
      return out;
    },
    async listSlots({ shopId, mode, date }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/slots?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}&date=${encodeURIComponent(date)}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return FALLBACK_RULES.slots[mode] || FALLBACK_RULES.slots.collect;
    },
    async getCutoff({ shopId, mode }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/cutoff?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return FALLBACK_RULES.cutoff[mode] || FALLBACK_RULES.cutoff.collect;
    },
    isoOf, parseISO,
  };
  window.WSCalendar = api;
})();
