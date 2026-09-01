/* =====================================================================
   WSCalendar : calendar/slots/cutoff API stub
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

  // Go-live : plus de regles seed (jours, cutoffs, creneaux simules).
  function noApi() { throw new Error('API calendrier indisponible.'); }

  const api = {
    endpoint: null,
    async listDays({ shopId, mode, from, to }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/days?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
          // Ne JAMAIS confondre « aucun jour ouvrable » et « le serveur n'a pas
          // répondu » : les deux donnent un calendrier vide à l'écran. Sans
          // cette trace, une panne ressemble trait pour trait à une boutique
          // fermée toute la semaine, et personne ne va chercher plus loin.
          console.error('[calendrier] jours indisponibles, HTTP ' + r.status);
        } catch (e) { console.error('[calendrier] jours injoignables', e); }
      }
      return []; // pas d'API -> aucun jour annonce
    },
    async listSlots({ shopId, mode, date }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/slots?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}&date=${encodeURIComponent(date)}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
          console.error('[calendrier] créneaux indisponibles, HTTP ' + r.status);
        } catch (e) { console.error('[calendrier] créneaux injoignables', e); }
      }
      return []; // pas d'API -> aucun creneau propose
    },
    async getCutoff({ shopId, mode }) {
      if (api.endpoint) {
        try {
          const u = `${api.endpoint}/cutoff?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}`;
          const r = await fetch(u, { credentials: 'include' });
          if (r.ok) return await r.json();
          console.error('[calendrier] cut-off indisponible, HTTP ' + r.status);
        } catch (e) { console.error('[calendrier] cut-off injoignable', e); }
      }
      noApi();
    },
    isoOf, parseISO,
  };
  window.WSCalendar = api;
})();
