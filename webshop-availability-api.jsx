/* =====================================================================
   WSAvailability — central availability engine API stub
   ---------------------------------------------------------------------
   Single source of truth for all availability decisions.
   The frontend must NEVER calculate availability itself. It sends
   context (shop, date, mode, basket) and renders what the API returns.

   To wire a real backend, set:
     window.WSAvailability.endpoint = 'https://your-host/availability';

   Endpoints expected on the backend:
     GET  {endpoint}/settings?shopId=
          -> ShopAvailabilitySettings

     GET  {endpoint}/days?shopId=&mode=&from=&to=
          -> [{iso, available, reason?, type?}]
          reason codes: 'closed' | 'holiday' | 'exception' | 'lead_time'

     GET  {endpoint}/slots?shopId=&mode=&date=
          -> [{id, label, capacity, current_orders, available, reason?}]
          reason codes: 'full' | 'cutoff_passed' | 'closed'

     POST {endpoint}/validate
          body: { shopId, mode, date, basket:[{productId, qty, lead_time?}] }
          -> { valid, issues:[{ productId, reason, next_available_date? }] }
          reason codes: 'lead_time' | 'no_delivery' | 'stock_empty' | 'shop_closed'

     POST {endpoint}/context
          body: { shopId, mode, date, basket? }
          -> AvailabilityContext

   ShopAvailabilitySettings shape:
     {
       shop_id, collect_enabled, delivery_enabled,
       collect_hours:   { start: 'HH:MM', end: 'HH:MM' },
       delivery_hours:  { start: 'HH:MM', end: 'HH:MM' },
       collect_slot_duration:  int (minutes),
       delivery_slot_duration: int (minutes),
       collect_cutoff:   { hour, minutes, lead_hours },
       delivery_cutoff:  { hour, minutes, lead_hours },
       collect_capacity_per_slot:  int,
       delivery_capacity_per_slot: int,
       timezone: 'Europe/Brussels',
     }

   AvailabilityContext shape:
     {
       shop_open, shop_reason?,
       collect_enabled, delivery_enabled,
       collect_cutoff:  { hour, minutes, passed, label },
       delivery_cutoff: { hour, minutes, passed, label },
       unavailable_product_ids: [productId],
       next_available_date: 'YYYY-MM-DD' | null,
     }
   ===================================================================== */
(function () {
  // Go-live : plus aucun seed de disponibilite (horaires, cutoffs, creneaux
  // simules). Sans reponse API : listes vides ou erreur explicite - jamais
  // de donnees fabriquees.
  function noApi() { throw new Error('API disponibilites indisponible.'); }

  // ── Helpers ────────────────────────────────────────────────────────────
  function pad(n) { return String(n).padStart(2, '0'); }
  function isoOf(d) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }
  function parseISO(s) { const [y,m,d] = s.split('-').map(Number); return new Date(y, m-1, d); }
  function fmtCutoff(h, m) { return `${pad(h)}h${m > 0 ? pad(m) : ''}`; }

  // ── API ────────────────────────────────────────────────────────────────
  const api = {
    endpoint: null,

    // ── Settings ──────────────────────────────────────────────────────────
    // Full shop availability configuration. Used by the admin panel
    // and by getContext() when assembling the fallback response.
    async getShopSettings({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(
            `${api.endpoint}/settings?shopId=${encodeURIComponent(shopId||'')}`,
            { credentials: 'include' }
          );
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      noApi();
    },

    // ── Available days ────────────────────────────────────────────────────
    // Returns an array of {iso, available, reason?} for every day
    // in the from–to range. The frontend uses this to grey out dates in
    // the DatePill calendar. Reason codes: 'closed' | 'holiday' | 'exception'.
    async listAvailableDays({ shopId, mode, from, to } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(
            `${api.endpoint}/days?shopId=${encodeURIComponent(shopId||'')}` +
            `&mode=${encodeURIComponent(mode||'')}` +
            `&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
            { credentials: 'include' }
          );
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return []; // pas d'API -> aucun jour annonce
    },

    // ── Slots with capacity ───────────────────────────────────────────────
    // Returns slots enriched with {capacity, current_orders, available, reason?}.
    // When no endpoint is set, returns the seed with a simulated load.
    // reason codes: 'full' | 'cutoff_passed'
    async listSlots({ shopId, mode, date } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(
            `${api.endpoint}/slots?shopId=${encodeURIComponent(shopId||'')}` +
            `&mode=${encodeURIComponent(mode||'')}` +
            `&date=${encodeURIComponent(date||'')}`,
            { credentials: 'include' }
          );
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return []; // pas d'API -> aucun creneau propose
    },

    // ── Cart validation ───────────────────────────────────────────────────
    // Validates basket lines against the selected date+mode+shop.
    // Returns { valid, issues:[{productId, reason, next_available_date?}] }
    // Called before checkout confirmation and whenever date/mode/shop change.
    // reason codes: 'lead_time' | 'no_delivery' | 'stock_empty' | 'shop_closed'
    async validateCart({ shopId, mode, date, basket } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/validate`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shopId, mode, date, basket }),
          });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      noApi();
    },

    // ── Combined context ──────────────────────────────────────────────────
    // Assembles all availability signals for a shop/mode/date/basket.
    // Frontend calls this once per context change and renders whatever it returns.
    // Internally assembles from other stubs when no endpoint is configured.
    async getContext({ shopId, mode, date, basket } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/context`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shopId, mode, date, basket }),
          });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      noApi();
    },

    isoOf,
    parseISO,
    fmtCutoff,
  };

  window.WSAvailability = api;
})();
