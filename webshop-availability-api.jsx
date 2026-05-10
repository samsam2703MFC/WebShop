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
  // ── Fallback seed ─────────────────────────────────────────────────────
  // Used only when no endpoint is configured (demo mode).
  // Values come from database table ws_shop_availability.

  const FALLBACK_SETTINGS = {
    collect_enabled: true,
    delivery_enabled: true,
    collect_hours:   { start: '08:00', end: '19:00' },
    delivery_hours:  { start: '08:30', end: '13:30' },
    collect_slot_duration:  60,   // minutes
    delivery_slot_duration: 120,  // minutes
    collect_cutoff:   { hour: 16, minutes: 0, lead_hours: 2 },
    delivery_cutoff:  { hour: 11, minutes: 0, lead_hours: 20 },
    collect_capacity_per_slot:  15,
    delivery_capacity_per_slot: 30,
    timezone: 'Europe/Brussels',
  };

  // Shop exception days — ws_shop_exceptions table.
  // Key: 'YYYY-MM-DD', value: { type: 'closed'|'modified', reason }
  // Example: '2026-07-21': { type: 'closed', reason: 'Fête nationale belge' }
  const FALLBACK_EXCEPTIONS = {};

  // Open weekdays per mode (1=Mon…7=Sun, ISO).
  // Comes from ws_shop_availability.collect_open_days / delivery_open_days.
  const FALLBACK_OPEN_DAYS = {
    collect:  [1, 2, 3, 4, 5, 6], // Mon–Sat
    delivery: [1, 2, 3, 4, 5],    // Mon–Fri
  };

  // Slot seed with capacity — ws_slots + ws_slot_capacity tables.
  // current_orders simulates partial load for demo UX.
  const FALLBACK_SLOTS = {
    collect: [
      { id: 's-08', label: '08:00–09:00', capacity: 15, current_orders: 0 },
      { id: 's-09', label: '09:00–10:00', capacity: 15, current_orders: 3 },
      { id: 's-10', label: '10:00–11:00', capacity: 15, current_orders: 8 },
      { id: 's-12', label: '12:00–13:00', capacity: 15, current_orders: 15 },
      { id: 's-14', label: '14:00–15:00', capacity: 15, current_orders: 2 },
      { id: 's-16', label: '16:00–17:00', capacity: 15, current_orders: 0 },
    ],
    delivery: [
      { id: 'd-am',  label: '08:30–10:30', capacity: 30, current_orders: 12 },
      { id: 'd-mid', label: '11:30–13:30', capacity: 30, current_orders: 5 },
    ],
  };

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
      return { shop_id: shopId, ...FALLBACK_SETTINGS };
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
      // Fallback: open-day rules + exception calendar
      const open = FALLBACK_OPEN_DAYS[mode] || FALLBACK_OPEN_DAYS.collect;
      const out = [];
      const start = parseISO(from), end = parseISO(to);
      for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
        const iso = isoOf(d);
        const exc = FALLBACK_EXCEPTIONS[iso];
        if (exc && exc.type === 'closed') {
          out.push({ iso, available: false, reason: exc.reason || 'exception', type: 'exception' });
        } else {
          const dow = ((d.getDay() + 6) % 7) + 1; // 1=Mon…7=Sun
          const ok = open.includes(dow);
          out.push({ iso, available: ok, reason: ok ? null : 'closed', type: ok ? null : 'weekday' });
        }
      }
      return out;
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
      const raw = FALLBACK_SLOTS[mode] || FALLBACK_SLOTS.collect;
      return raw.map((s) => {
        const full = s.current_orders >= s.capacity;
        return { ...s, available: !full, reason: full ? 'full' : null };
      });
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
      // Fallback: check lead times + no_delivery flag
      const issues = [];
      const today = new Date(); today.setHours(0, 0, 0, 0);
      const sel = typeof date === 'string' ? parseISO(date) : (date || today);
      const daysDiff = Math.max(0, Math.floor((sel - today) / 86400000));
      for (const line of (basket || [])) {
        const leadTime = line.lead_time || 0;
        if (leadTime > 0 && daysDiff < leadTime) {
          const next = new Date(today);
          next.setDate(next.getDate() + leadTime);
          issues.push({
            productId: line.productId,
            reason: 'lead_time',
            lead_days_required: leadTime,
            next_available_date: isoOf(next),
          });
        }
        if (mode === 'delivery' && line.no_delivery) {
          issues.push({ productId: line.productId, reason: 'no_delivery' });
        }
      }
      return { valid: issues.length === 0, issues };
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
      // Fallback: assemble from individual stubs
      const settings = await api.getShopSettings({ shopId });
      const isoDate = typeof date === 'string' ? date : (date instanceof Date ? isoOf(date) : isoOf(new Date()));
      const dayResult = await api.listAvailableDays({ shopId, mode, from: isoDate, to: isoDate });
      const dayInfo = dayResult[0] || { available: true, reason: null };

      const now = new Date();
      const nowMinutes = now.getHours() * 60 + now.getMinutes();
      const cc = settings.collect_cutoff  || FALLBACK_SETTINGS.collect_cutoff;
      const dc = settings.delivery_cutoff || FALLBACK_SETTINGS.delivery_cutoff;
      const ccMins = cc.hour * 60 + (cc.minutes || 0);
      const dcMins = dc.hour * 60 + (dc.minutes || 0);

      const today = new Date(); today.setHours(0, 0, 0, 0);
      const selDate = parseISO(isoDate);
      const isToday = selDate.toDateString() === today.toDateString();

      const cartValidation = basket && basket.length
        ? await api.validateCart({ shopId, mode, date: isoDate, basket })
        : { valid: true, issues: [] };

      return {
        shop_open: dayInfo.available,
        shop_reason: dayInfo.reason,
        collect_enabled: settings.collect_enabled,
        delivery_enabled: settings.delivery_enabled,
        collect_cutoff: {
          hour: cc.hour, minutes: cc.minutes || 0, lead_hours: cc.lead_hours,
          passed: isToday && nowMinutes >= ccMins,
          label: fmtCutoff(cc.hour, cc.minutes || 0),
        },
        delivery_cutoff: {
          hour: dc.hour, minutes: dc.minutes || 0, lead_hours: dc.lead_hours,
          passed: isToday && nowMinutes >= dcMins,
          label: fmtCutoff(dc.hour, dc.minutes || 0),
        },
        cart_valid: cartValidation.valid,
        cart_issues: cartValidation.issues,
      };
    },

    isoOf,
    parseISO,
    fmtCutoff,
  };

  window.WSAvailability = api;
})();
