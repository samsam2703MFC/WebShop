/* =====================================================================
   WEBSHOP — VIES VAT validation (mock)
   ---------------------------------------------------------------------
   Real VIES doesn't expose a CORS-friendly browser endpoint, so in
   production you must proxy through your own backend. This module:

     1. Exposes a single async function: WSVies.check({ vat, country })
        that returns { valid, data?, error? }.
     2. By default uses a mock that simulates network latency, success
        for known VAT codes, "invalid" for unknown ones, and a "service
        unavailable" branch you can trigger with the magic VAT 'TIMEOUT'.
     3. To plug a real endpoint, set window.WSVies.endpoint =
        'https://your-backend/vies?country={country}&vat={vat}' and the
        helper will hit that URL and pass the JSON straight back.

   Returned shape on success:
     { valid: true, data: {
         vat, country, name, address, postalCode, city
     }}
   On failure:
     { valid: false, error: { code: 'invalid'|'unavailable', message } }
   ===================================================================== */

(function () {
  const SAMPLE_DB = {
    'BE0123456789': {
      country: 'BE',
      name: 'BAKERY ATELIER SA',
      address: 'Rue du Pain 12',
      postalCode: '1000',
      city: 'Bruxelles',
    },
    'BE0876543210': {
      country: 'BE',
      name: 'EUREKA CONSULTING SPRL',
      address: 'Avenue Louise 250',
      postalCode: '1050',
      city: 'Ixelles',
    },
    'NL123456789B01': {
      country: 'NL',
      name: 'AMSTERDAM TRADING BV',
      address: 'Herengracht 100',
      postalCode: '1015 BS',
      city: 'Amsterdam',
    },
    'FR12345678901': {
      country: 'FR',
      name: 'PARIS NEGOCE SARL',
      address: '5 Rue de Rivoli',
      postalCode: '75001',
      city: 'Paris',
    },
    'DE123456789': {
      country: 'DE',
      name: 'BERLIN HANDELS GMBH',
      address: 'Friedrichstraße 88',
      postalCode: '10117',
      city: 'Berlin',
    },
  };

  function normalize(vat, country) {
    const raw = String(vat || '').toUpperCase().replace(/[\s.\-_]/g, '');
    // If the user typed the country prefix in the VAT input, keep it.
    if (/^[A-Z]{2}/.test(raw)) return raw;
    // Otherwise prepend the chosen country.
    return (country || '').toUpperCase() + raw;
  }

  async function mockCheck({ vat, country }) {
    await new Promise((r) => setTimeout(r, 700 + Math.random() * 600));
    const key = normalize(vat, country);

    // Special trigger to demonstrate the unavailable branch
    if (/TIMEOUT$/.test(key)) {
      return { valid: false, error: { code: 'unavailable', message: 'VIES indisponible. Veuillez réessayer.' } };
    }
    if (SAMPLE_DB[key]) {
      const hit = SAMPLE_DB[key];
      return {
        valid: true,
        data: {
          vat: key,
          country: hit.country,
          name: hit.name,
          address: hit.address,
          postalCode: hit.postalCode,
          city: hit.city,
        },
      };
    }
    return { valid: false, error: { code: 'invalid', message: 'Ce numéro de TVA n’a pas été reconnu.' } };
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
      // Expecting backend to return either { valid:false } or { valid:true, data:{...} }
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
    const cleaned = String(opts.vat || '').replace(/\s/g, '');
    if (!cleaned) {
      return { valid: false, error: { code: 'invalid', message: 'Veuillez saisir un numéro de TVA.' } };
    }
    if (window.WSVies.endpoint) {
      return liveCheck({ ...opts, endpoint: window.WSVies.endpoint });
    }
    return mockCheck(opts);
  }

  window.WSVies = {
    endpoint: null, // set this to your proxy URL to use real VIES
    check,
    SAMPLE_VATS: Object.keys(SAMPLE_DB),
  };
})();
