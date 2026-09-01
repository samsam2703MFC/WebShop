/* =====================================================================
   WSTours : delivery tours (tournées) API stub
   ---------------------------------------------------------------------
   The UI must NEVER read W_TOURS directly. It calls these helpers,
   which default to the in-memory W_TOURS seed while no backend is wired.
   To wire a real backend, set:
     window.WSTours.endpoint = 'https://your-host/tours';
   Endpoints expected:
     GET  {endpoint}?shopId=             -> Tour[]
     GET  {endpoint}/:id                 -> Tour
   Tour shape: { id, name, shopId, window, days, active }
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,

    /* Liste des tournées (table ws_tours), éventuellement filtrée par boutique.
       GO-LIVE : plus aucun repli sur la constante W_TOURS, une tournée
       inventée enverrait un client vers une livraison qui n'existe pas.
       Erreur réseau/serveur => on lève, l'appelant affiche l'erreur. */
    async list({ shopId } = {}) {
      if (!api.endpoint) throw new Error('API tournées non configurée.');
      const qs = shopId ? `?shopId=${encodeURIComponent(shopId)}` : '';
      const r = await fetch(`${api.endpoint}${qs}`, { credentials: 'include' });
      if (!r.ok) throw new Error('Tournées indisponibles (HTTP ' + r.status + ').');
      const j = await r.json();
      return Array.isArray(j) ? j : (j.tours || j.data || []);
    },

    /* Une tournée par id : même règle : vraie donnée ou erreur. */
    async get(id) {
      if (!id) return null;
      if (!api.endpoint) throw new Error('API tournées non configurée.');
      const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}`, { credentials: 'include' });
      if (!r.ok) throw new Error('Tournée indisponible (HTTP ' + r.status + ').');
      return await r.json();
    },
  };

  window.WSTours = api;
})();
