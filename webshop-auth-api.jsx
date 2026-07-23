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
    async login({ identifier, email, password, phonePrefix, authMethod }) {
      const ident = (identifier || email || '').trim();
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/login`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ identifier: ident, password, phonePrefix: phonePrefix || '+32', authMethod }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          // Compte existant sans mot de passe -> le front bascule sur "définir un mot de passe".
          return { ok: false, needsPassword: !!j.needsPassword, error: j.message || j.error?.message || 'Identifiants incorrects.' };
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
    async register({ email, phone, phonePrefix, password, firstName, lastName, postalCode, locality, authMethod }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/register`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, phone, phonePrefix: phonePrefix || '+32', password, firstName, lastName, postalCode, locality, authMethod }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          // 409 { exists:true } -> le compte existe déjà (proposer set-password).
          return { ok: false, error: j.error || j.message || "Erreur lors de l'inscription.", exists: !!j.exists, status: r.status };
        } catch (_) {}
      }
      // Go-live : plus de creation de compte local fictif.
      return { ok: false, error: 'Service inscription indisponible — réessayez.' };
    },

    /* ── Set / update password (compte existant) ─────────────────────── */
    /* ⚠️ Sans vérification d'identité (pas d'OTP) — prototype uniquement. */
    async setPassword({ email, phone, phonePrefix, identifier, password }) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/set-password`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, phone, phonePrefix: phonePrefix || '+32', identifier, password }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          return { ok: false, error: j.error || j.message || 'Échec de la mise à jour.' };
        } catch (_) {}
      }
      return { ok: false, error: 'Service indisponible.' };
    },

    /* ── SSO handoff (PWA -> webshop) : échange un jeton à usage unique
       contre une session webshop. */
    async handoff(token) {
      if (!token) return { ok: false, error: 'Jeton manquant.' };
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/handoff`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token }),
          });
          const j = await r.json();
          if (r.ok) { if (j.token) setToken(j.token); return { ok: true, user: j.user }; }
          return { ok: false, error: j.error || j.message || 'Lien invalide.' };
        } catch (_) {}
      }
      return { ok: false, error: 'Service indisponible.' };
    },

    /* ── Vérification TVA + liaison société (persistée) — miroir de la PWA
       POST /client/billing : VIES côté serveur, puis raison sociale/adresse/
       verified_at écrits sur la fiche client partagée. */
    async billingVerify({ vat, country }) {
      if (!api.endpoint) return { ok: false, error: { code: 'unavailable', message: 'Service indisponible.' } };
      try {
        const r = await fetch(`${api.endpoint}/billing-verify`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ vat, country }),
        });
        const j = await r.json();
        if (r.ok && j.valid) return { ok: true, data: j.data, user: j.user };
        return { ok: false, error: j.error || { code: 'invalid', message: j.message || 'TVA non reconnue.' } };
      } catch (_) {
        return { ok: false, error: { code: 'unavailable', message: 'VIES indisponible. Réessayez.' } };
      }
    },

    /* ── Bureau (site de livraison) — liste par shop + liaison (parité PWA) ── */
    async listOfficeSites({ shopId }) {
      if (!api.endpoint || !shopId) return [];
      try {
        const base = api.endpoint.replace(/\/auth\/?$/, '');
        const r = await fetch(`${base}/office-sites?shopId=${encodeURIComponent(shopId)}`, { credentials: 'include' });
        if (r.ok) { const j = await r.json(); return Array.isArray(j) ? j : []; }
      } catch (_) {}
      return [];
    },
    async setOfficeSite(siteId) {
      if (!api.endpoint) return { ok: false, error: 'Service indisponible.' };
      try {
        const r = await fetch(`${api.endpoint}/office`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ siteId: siteId || null }),
        });
        const j = await r.json();
        if (r.ok) return { ok: true, user: j.user };
        return { ok: false, error: j.error || 'Échec de la liaison.' };
      } catch (_) {
        return { ok: false, error: 'Réseau indisponible.' };
      }
    },

    /* ── Config front (ws_param en liste blanche) : flag onglet Fidélité,
       icônes des deux touches de première position de la nav catégories…
       Le repli reprend les mêmes références média que les défauts serveur
       (des chemins vers la bibliothèque, pas des fichiers en dur). ── */
    async config() {
      const FALLBACK = {
        fidelityTabEnabled: true,
        categoryNavAllIcon: '/webshop/assets/all.png',
        categoryNavBackIcon: '/webshop/assets/back.png',
      };
      if (!api.endpoint) return FALLBACK;
      try {
        const base = api.endpoint.replace(/\/auth\/?$/, '');
        const r = await fetch(`${base}/config`, { credentials: 'include' });
        if (r.ok) return { ...FALLBACK, ...(await r.json()) };
      } catch (_) {}
      return FALLBACK;
    },

    /* ── Mes achats : liste unifiée tickets + commandes (12 mois, paginée) ── */
    async listPurchases({ filter, page, perPage } = {}) {
      if (!api.endpoint) return { items: [], total: 0, canRequestInvoice: false };
      try {
        const qs = new URLSearchParams();
        if (filter) qs.set('filter', filter);
        if (page) qs.set('page', String(page));
        if (perPage) qs.set('perPage', String(perPage));
        const r = await fetch(`${api.endpoint}/purchases?${qs}`, { credentials: 'include', headers: authHeaders() });
        if (r.ok) return await r.json();
      } catch (_) {}
      return { items: [], total: 0, canRequestInvoice: false };
    },

    /* ── Demande de facture (to_invoice 1/0 + destinataire) sur un ticket ── */
    async requestInvoice({ ref, want, billingEntityId }) {
      if (!api.endpoint) return { ok: false, error: 'Service indisponible.' };
      try {
        const r = await fetch(`${api.endpoint}/purchases/request-invoice`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ ref, want: want ? 1 : 0, billingEntityId: billingEntityId ?? null }),
        });
        const j = await r.json();
        if (r.ok) return { ok: true, notice: j.notice };
        return { ok: false, error: j.error || 'Échec de la demande.' };
      } catch (_) { return { ok: false, error: 'Réseau indisponible.' }; }
    },

    /* ── Sécurité : changement de mot de passe de la session ── */
    async changePassword({ password }) {
      if (!api.endpoint) return { ok: false, error: 'Service indisponible.' };
      try {
        const r = await fetch(`${api.endpoint}/password`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ password }),
        });
        const j = await r.json();
        return r.ok ? { ok: true } : { ok: false, error: j.error || 'Échec.' };
      } catch (_) { return { ok: false, error: 'Réseau indisponible.' }; }
    },

    /* ── Société sans n° TVA (non assujettie) / archivage du lien ── */
    async addCompanyNoVat({ name, address, postalCode, city }) {
      if (!api.endpoint) return { ok: false, error: 'Service indisponible.' };
      try {
        const r = await fetch(`${api.endpoint}/billing-company`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ name, address, postalCode, city }),
        });
        const j = await r.json();
        if (r.ok) return { ok: true, user: j.user };
        return { ok: false, error: j.error || 'Échec de l\'ajout.' };
      } catch (_) { return { ok: false, error: 'Réseau indisponible.' }; }
    },
    async unlinkCompany() {
      if (!api.endpoint) return { ok: false, error: 'Service indisponible.' };
      try {
        const r = await fetch(`${api.endpoint}/billing-company/unlink`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
        });
        const j = await r.json();
        if (r.ok) return { ok: true, user: j.user };
        return { ok: false, error: j.error || 'Échec.' };
      } catch (_) { return { ok: false, error: 'Réseau indisponible.' }; }
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
      // Go-live : plus de mutation d'un store local fictif.
      return { ok: false, error: 'Service profil indisponible — réessayez.' };
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
      return { ok: false }; // Go-live : jamais de faux succès
    },
  };

  window.WSAuth = api;
})();
