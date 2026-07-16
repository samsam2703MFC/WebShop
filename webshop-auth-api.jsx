/* =====================================================================
   WSAuth — authentication / session API stub
   ---------------------------------------------------------------------
   The UI must NEVER call _AUTH_STORE directly. It calls these helpers,
   which default to the in-memory seed while no backend is wired.
   To wire a real backend, set:
     window.WSAuth.endpoint = 'https://your-host/auth';
   Endpoints expected:
     POST {endpoint}/register            -> { user, token }
     POST {endpoint}/login               -> { user, token }
     POST {endpoint}/logout              -> { ok }
     GET  {endpoint}/me                  -> { user }
     PATCH {endpoint}/me                 -> { user }
     POST {endpoint}/password-reset      -> { ok }

   Session: the storefront and API may live on different domains, so
   auth is by BEARER TOKEN (not cookies). login/register return a token
   which is stored in localStorage and sent as `Authorization: Bearer …`
   on authenticated calls.
   ===================================================================== */
(function () {
  const TOKEN_KEY = 'ws_auth_token';
  const getToken = () => { try { return localStorage.getItem(TOKEN_KEY); } catch (_) { return null; } };
  const setToken = (t) => { try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch (_) {} };
  const authHeaders = () => { const t = getToken(); return t ? { 'Authorization': 'Bearer ' + t } : {}; };

  const api = {
    endpoint: null,

    /* ── Login (identifiant = email OU téléphone) ──────────────────── */
    async login({ identifier, email, password }) {
      const ident = (identifier || email || '').trim();
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/login`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ identifier: ident, password }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          return { ok: false, error: j.message || j.error?.message || 'Identifiants incorrects.' };
        } catch (_) {}
      }
      // Fallback: in-memory _AUTH_STORE (email OU téléphone).
      const store = window._AUTH_STORE;
      if (!store || !store.users) return { ok: false, error: 'Store unavailable.' };
      const norm = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, '');
      let u = store.users[ident.toLowerCase()];
      if (!u) u = Object.values(store.users).find((x) => x.phone && norm(x.phone) === norm(ident));
      if (!u || u.password !== password) return { ok: false, error: 'Identifiants incorrects.' };
      return { ok: true, user: u };
    },

    /* ── Register ──────────────────────────────────────────────────── */
    async register({ email, phone, password, firstName, lastName }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/register`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, phone, password, firstName, lastName }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          return { ok: false, error: j.message || j.error?.message || "Erreur lors de l'inscription." };
        } catch (_) {}
      }
      // Fallback.
      const store = window._AUTH_STORE;
      if (!store) return { ok: false, error: 'Store unavailable.' };
      const k = String(email).trim().toLowerCase();
      if (store.users && store.users[k]) return { ok: false, error: 'Un compte existe déjà avec cet email.' };
      const u = { id: 'u' + Date.now(), email: k, phone: phone || '', password, firstName, lastName, officeId: null, preferredShopId: null, fidelityApp: { active: false, linkedAt: null } };
      if (store.users) store.users[k] = u;
      return { ok: true, user: u };
    },

    /* ── Logout ─────────────────────────────────────────────────────── */
    async logout() {
      if (api.endpoint) {
        try { await fetch(`${api.endpoint}/logout`, { method: 'POST', credentials: 'include', headers: authHeaders() }); } catch (_) {}
      }
      setToken(null);
      return { ok: true };
    },

    /* ── Session check (GET /me) ─────────────────────────────────────── */
    async me() {
      if (api.endpoint) {
        if (!getToken()) return null; // no session → don't bother the server
        try {
          const r = await fetch(`${api.endpoint}/me`, { credentials: 'include', headers: authHeaders() });
          if (r.ok) { const j = await r.json(); return j.user || j || null; }
          if (r.status === 401) setToken(null); // stale/expired token
        } catch (_) {}
        return null;
      }
      return null; // No session in demo mode
    },

    /* ── Profile update (PATCH /me) ──────────────────────────────────── */
    async updateMe(patch) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/me`, {
            method: 'PATCH', credentials: 'include',
            headers: { 'Content-Type': 'application/json', ...authHeaders() },
            body: JSON.stringify(patch),
          });
          if (r.ok) { const j = await r.json(); return { ok: true, user: j.user || j }; }
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
