/* =====================================================================
   WSAuth — authentication / session API stub
   ---------------------------------------------------------------------
   The UI must NEVER call _AUTH_STORE directly. It calls these helpers,
   which default to the in-memory seed while no backend is wired.
   To wire a real backend, set:
     window.WSAuth.endpoint = 'https://your-host/auth';
   Endpoints expected:
     POST {endpoint}/login               -> { user }
     POST {endpoint}/register            -> { user }
     POST {endpoint}/logout              -> 200
     GET  {endpoint}/me                  -> { user }
     PATCH {endpoint}/me                 -> { user }
     POST {endpoint}/password-reset      -> 200
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,

    /* ── Login ─────────────────────────────────────────────────────── */
    async login({ email, password }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/login`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password }),
          });
          const j = await r.json();
          if (r.ok) return { ok: true, user: j.user };
          return { ok: false, error: j.error?.message || 'Identifiants incorrects.' };
        } catch (_) {}
      }
      // Fallback: in-memory _AUTH_STORE (seeded by webshop-full-bundle.jsx).
      // TODO[BACKEND]: remove once POST /auth/login is live.
      const store = window._AUTH_STORE;
      if (!store) return { ok: false, error: 'Store unavailable.' };
      const u = store.users && store.users[String(email).trim().toLowerCase()];
      if (!u || u.password !== password) return { ok: false, error: 'Identifiants incorrects.' };
      return { ok: true, user: u };
    },

    /* ── Register ──────────────────────────────────────────────────── */
    async register({ email, password, firstName, lastName }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/register`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, firstName, lastName }),
          });
          const j = await r.json();
          if (r.ok) return { ok: true, user: j.user };
          return { ok: false, error: j.error?.message || "Erreur lors de l'inscription." };
        } catch (_) {}
      }
      // Fallback.
      // TODO[BACKEND]: remove once POST /auth/register is live.
      const store = window._AUTH_STORE;
      if (!store) return { ok: false, error: 'Store unavailable.' };
      const k = String(email).trim().toLowerCase();
      if (store.users && store.users[k]) return { ok: false, error: 'Un compte existe déjà avec cet email.' };
      const u = { id: 'u' + Date.now(), email: k, password, firstName, lastName, officeId: null, preferredShopId: null, fidelityApp: { active: false, linkedAt: null } };
      if (store.users) store.users[k] = u;
      return { ok: true, user: u };
    },

    /* ── Logout ─────────────────────────────────────────────────────── */
    async logout() {
      if (api.endpoint) {
        try { await fetch(`${api.endpoint}/logout`, { method: 'POST', credentials: 'include' }); } catch (_) {}
      }
      return { ok: true };
    },

    /* ── Session check (GET /me) ─────────────────────────────────────── */
    async me() {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/me`, { credentials: 'include' });
          if (r.ok) {
            const j = await r.json();
            return j.user || j || null;
          }
        } catch (_) {}
      }
      return null; // No session cookie in demo mode
    },

    /* ── Profile update (PATCH /me) ──────────────────────────────────── */
    async updateMe(patch) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/me`, {
            method: 'PATCH', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(patch),
          });
          if (r.ok) {
            const j = await r.json();
            return { ok: true, user: j.user || j };
          }
        } catch (_) {}
      }
      // Fallback: mutate in-memory store.
      const store = window._AUTH_STORE;
      if (store && store.users && patch.email) {
        const k = String(patch.email).trim().toLowerCase();
        if (store.users[k]) {
          store.users[k] = { ...store.users[k], ...patch };
          return { ok: true, user: store.users[k] };
        }
      }
      return { ok: false, error: 'Not found.' };
    },

    /* ── Password reset ─────────────────────────────────────────────── */
    async requestPasswordReset({ email }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/password-reset`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email }),
          });
          return { ok: r.ok };
        } catch (_) {}
      }
      return { ok: true }; // Demo: always succeeds silently
    },
  };

  window.WSAuth = api;
})();
