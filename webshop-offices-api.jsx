/* =====================================================================
   WSOffices — annuaire des bureaux (B2B) : SERVEUR UNIQUEMENT
   ---------------------------------------------------------------------
   Go-live : aucune donnée locale, aucun repli, aucun faux succès. Les
   bureaux viennent de /api/offices ; si l'appel échoue, l'écran doit
   afficher l'erreur — jamais une liste fabriquée.
     window.WSOffices.endpoint = '<base>/offices'   (câblé par l'app)
   ===================================================================== */
(function () {
  function noEndpoint() {
    throw new Error('API bureaux non configurée — please debug.');
  }

  /* L'API n'authentifie QUE par `Authorization: Bearer` (auth_uid() ne lit pas
     de cookie) : `credentials: 'include'` ne transporte aucune session. Un
     appel protégé sans cet en-tête renvoie donc 401 même pour un client
     connecté — c'est ce qui rendait la demande de rattachement impossible. */
  const authHeaders = () =>
    (window.WSAuth && typeof window.WSAuth.authHeaders === 'function') ? window.WSAuth.authHeaders() : {};

  const api = {
    endpoint: null,

    // Bureaux VALIDÉS de la boutique (préférée) auxquels le client peut se
    // lier lui-même. Un bureau appartient à une boutique → toujours scoper.
    async listApproved(shopId) {
      if (!api.endpoint) noEndpoint();
      const q = '?status=validated' + (shopId ? '&shopId=' + encodeURIComponent(shopId) : '');
      const r = await fetch(api.endpoint + q, { credentials: 'include' });
      if (!r.ok) throw new Error('Bureaux indisponibles (' + r.status + ') — please debug.');
      const j = await r.json();
      return Array.isArray(j) ? j : [];
    },

    // « Mon bureau n'est pas dans la liste » — demande de RATTACHEMENT envoyée
    // au franchisé. Ne crée ni ne lie aucun bureau : c'est le franchisé qui
    // décide (BO › Demandes de rattachement → écrit client.office_id).
    async contactFranchise(payload) {
      if (!api.endpoint) return { ok: false, error: 'API bureaux non configurée — demande non enregistrée.' };
      try {
        const r = await fetch(api.endpoint + '/contact', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify(payload),
        });
        const j = await r.json().catch(() => ({}));
        if (r.ok) return j && j.ok === false ? j : { ok: true, ...j };
        return { ok: false, error: j.error || j.message || ('Échec de l\'envoi (' + r.status + ').') };
      } catch (e) {
        return { ok: false, error: 'Réseau indisponible — demande non enregistrée.' };
      }
    },

    // Un bureau par id (avec ses sites de livraison).
    async get(id) {
      if (!id) return null;
      if (!api.endpoint) noEndpoint();
      const r = await fetch(api.endpoint + '/' + encodeURIComponent(id), { credentials: 'include' });
      if (r.status === 404) return null;
      if (!r.ok) throw new Error('Bureau indisponible (' + r.status + ') — please debug.');
      return await r.json();
    },
  };
  window.WSOffices = api;
})();
