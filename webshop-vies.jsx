/* =====================================================================
   WEBSHOP — Validation TVA (VIES)
   ---------------------------------------------------------------------
   VIES n'expose pas d'endpoint navigateur compatible CORS : la
   vérification passe TOUJOURS par notre backend (window.WSVies.endpoint,
   câblé par api-config.js).

   GO-LIVE — aucune donnée simulée. L'ancien module embarquait un mock
   (SAMPLE_DB) qui FABRIQUAIT l'identité d'une société (nom, adresse,
   code postal, ville) pour 5 numéros d'exemple, et rejetait tout numéro
   réel absent de cette liste. Une société pouvait donc être « validée »
   avec une raison sociale et une adresse inventées, ou une vraie société
   refusée. Supprimé : sans endpoint configuré, on renvoie explicitement
   « service indisponible » — jamais une identité inventée.

   Forme de retour en succès :
     { valid: true, data: { vat, country, name, address, postalCode, city } }
   En échec :
     { valid: false, error: { code: 'invalid'|'unavailable', message } }
   ===================================================================== */

(function () {
  function normalize(vat, country) {
    const raw = String(vat || '').toUpperCase().replace(/[\s.\-_]/g, '');
    // Si l'utilisateur a saisi le préfixe pays dans le champ TVA, on le garde.
    if (/^[A-Z]{2}/.test(raw)) return raw;
    // Sinon on préfixe avec le pays choisi.
    return (country || '').toUpperCase() + raw;
  }

  async function liveCheck({ vat, country, endpoint }) {
    const key = normalize(vat, country);
    const url = endpoint
      .replace('{vat}', encodeURIComponent(key))
      .replace('{country}', encodeURIComponent(country || key.slice(0, 2)));
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!res.ok) {
        return { valid: false, error: { code: 'unavailable', message: 'VIES indisponible (HTTP ' + res.status + ').' } };
      }
      const json = await res.json();
      // Le backend renvoie soit { valid:false }, soit { valid:true, data:{...} }.
      if (json && json.valid && json.data) {
        return { valid: true, data: { vat: key, ...json.data } };
      }
      if (json && json.valid === false) {
        return { valid: false, error: { code: 'invalid', message: 'Ce numéro de TVA n’a pas été reconnu.' } };
      }
      return { valid: false, error: { code: 'unavailable', message: 'Réponse VIES inattendue.' } };
    } catch (e) {
      return { valid: false, error: { code: 'unavailable', message: 'VIES indisponible. Veuillez réessayer.' } };
    }
  }

  async function check(opts) {
    const cleaned = String((opts && opts.vat) || '').replace(/\s/g, '');
    if (!cleaned) {
      return { valid: false, error: { code: 'invalid', message: 'Veuillez saisir un numéro de TVA.' } };
    }
    if (!window.WSVies.endpoint) {
      // Pas de service de vérification câblé : on le dit. Aucune validation
      // locale ne peut faire foi sur l'identité d'une société.
      return { valid: false, error: { code: 'unavailable', message: 'Vérification TVA indisponible. Veuillez réessayer plus tard.' } };
    }
    return liveCheck({ ...opts, endpoint: window.WSVies.endpoint });
  }

  window.WSVies = {
    endpoint: null, // câblé par api-config.js vers le proxy VIES du backend
    check,
  };
})();
