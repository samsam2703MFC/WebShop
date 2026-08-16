/* =====================================================================
   WEBSHOP i18n — noyau (source unique : la table ws_i18n via GET /i18n)
   ---------------------------------------------------------------------
   « Rien en dur » : AUCUNE chaîne d'interface ne vit ici. Les libellés sont
   chargés au boot depuis l'API (/i18n → table ws_i18n) et fusionnés via
   merge(). Les composants lisent t('clé') ; tant que la table n'a pas répondu,
   t() renvoie la clé (jamais un texte inventé). Un échec de chargement est
   signalé au bandeau (WSBug.note), comme les produits — pas de repli.

   - Langues : fr, nl par défaut ; une boutique peut restreindre via `languages`.
   - Langue d'ouverture : choix explicite du client > profil > langue de la
     BOUTIQUE (default_lang) > navigateur > repli app (fr).
   - Persistance : localStorage('ws.lang') + profil client ('ws.customer').
   ===================================================================== */

(function () {
  // Tableau MUTÉ EN PLACE (jamais réassigné) : la même référence est exportée
  // et lue par le sélecteur, donc une restriction par boutique s'y reflète.
  const SUPPORTED = ['fr', 'nl'];
  const APP_DEFAULT = 'fr';                       // repli ultime

  const SHOP_CONFIG = { availableLanguages: SUPPORTED, defaultLanguage: null };

  /* Dictionnaires remplis au boot depuis /i18n. Vides au départ. */
  const UI = { fr: {}, nl: {} };
  /* Contenu (noms produits/catégories) : servi par l'API catalogue (marque),
     pas ici. On garde des tables vides pour l'API tProduct/tCategory. */
  const PRODUCT_TR = {};
  const CATEGORY_TR = {};

  /* ---------- persistance ------------------------------------------ */
  const LS_LANG = 'ws.lang';
  const LS_CUSTOMER = 'ws.customer';
  function readLS(k)  { try { return localStorage.getItem(k); } catch { return null; } }
  function writeLS(k, v) { try { localStorage.setItem(k, v); } catch {} }
  function readJSON(k)  { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : null; } catch { return null; } }
  function writeJSON(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} }

  function hasExplicitChoice() {
    const stored = readLS(LS_LANG);
    if (stored && SUPPORTED.includes(stored)) return true;
    const c = readJSON(LS_CUSTOMER);
    return !!(c && SUPPORTED.includes(c.preferredLang));
  }

  function detectLang() {
    const stored = readLS(LS_LANG);
    if (stored && SUPPORTED.includes(stored)) return stored;
    const c = readJSON(LS_CUSTOMER);
    if (c && SUPPORTED.includes(c.preferredLang)) return c.preferredLang;
    // langue de la boutique (connue après applyShop) avant le navigateur
    if (SHOP_CONFIG.defaultLanguage && SUPPORTED.includes(SHOP_CONFIG.defaultLanguage))
      return SHOP_CONFIG.defaultLanguage;
    const nav = (navigator.language || APP_DEFAULT).slice(0, 2).toLowerCase();
    if (SUPPORTED.includes(nav)) return nav;
    return APP_DEFAULT;
  }

  /* ---------- état + notifications --------------------------------- */
  const listeners = new Set();
  let currentLang = detectLang();
  let _loaded = false;

  function notify() { listeners.forEach((fn) => { try { fn(currentLang); } catch {} }); }
  function getLang() { return currentLang; }
  function isLoaded() { return _loaded; }

  function setLang(lang) {
    if (!SUPPORTED.includes(lang) || lang === currentLang) return;
    currentLang = lang;
    writeLS(LS_LANG, lang);
    const c = readJSON(LS_CUSTOMER);
    if (c) { c.preferredLang = lang; writeJSON(LS_CUSTOMER, c); }
    document.documentElement.setAttribute('lang', lang);
    notify();
  }

  function onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); }

  function format(str, params) {
    if (!params) return str;
    return str.replace(/\{(\w+)\}/g, (_, k) => (k in params ? params[k] : `{${k}}`));
  }
  function t(key, params) {
    const dict = UI[currentLang] || UI.fr;
    const raw = (dict && dict[key]) || (UI.fr && UI.fr[key]) || key;
    return format(raw, params);
  }

  function merge(strings) {
    if (!strings) return;
    Object.entries(strings).forEach(([lang, dict]) => {
      if (!UI[lang]) UI[lang] = {};
      Object.assign(UI[lang], dict || {});
    });
  }

  function tProduct(product, field = 'name') {
    if (!product) return '';
    const tr = PRODUCT_TR[currentLang] && PRODUCT_TR[currentLang][product.id];
    if (tr && tr[field]) return tr[field];
    return product[field] || '';
  }
  function tCategory(catId, fallback) {
    const tr = CATEGORY_TR[currentLang] && CATEGORY_TR[currentLang][catId];
    return tr || fallback || catId;
  }

  /* ---------- chargement depuis l'API ------------------------------ */
  let endpoint = null;                            // posé par api-config.js
  function setEndpoint(url) { endpoint = url; }
  function load(url) {
    if (url) endpoint = url;
    if (!endpoint) return Promise.resolve(false);
    return fetch(endpoint, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status))))
      .then((data) => {
        const strings = data && data.strings;
        if (!strings || typeof strings !== 'object') throw new Error('réponse i18n vide');
        merge(strings);
        _loaded = true;
        notify();                                  // re-rend les composants montés
        return true;
      })
      .catch((err) => {
        if (window.WSBug && window.WSBug.note)
          window.WSBug.note('i18n', 'Traductions non chargées : ' + (err.message || err));
        return false;
      });
  }

  /* Applique la config langue d'une boutique (default_lang / languages). */
  function applyShop(shop) {
    if (!shop) return;
    if (shop.languages) {
      const langs = String(shop.languages).split(',').map((s) => s.trim()).filter(Boolean);
      if (langs.length) SUPPORTED.splice(0, SUPPORTED.length, ...langs);  // mute en place
    }
    const def = shop.default_lang || shop.defaultLanguage || null;
    SHOP_CONFIG.defaultLanguage = def;
    // La boutique impose sa langue SI le visiteur n'a pas choisi lui-même.
    if (def && SUPPORTED.includes(def) && !hasExplicitChoice() && def !== currentLang) {
      currentLang = def;
      document.documentElement.setAttribute('lang', def);
      notify();
    }
  }

  /* ---------- profil client ---------------------------------------- */
  function getCustomer() { return readJSON(LS_CUSTOMER); }
  function setCustomer(profile) {
    writeJSON(LS_CUSTOMER, profile);
    if (profile && profile.preferredLang && SUPPORTED.includes(profile.preferredLang))
      setLang(profile.preferredLang);
  }
  function signOut() { try { localStorage.removeItem(LS_CUSTOMER); } catch {} }

  /* ---------- export ----------------------------------------------- */
  document.documentElement.setAttribute('lang', currentLang);

  window.WSI18n = {
    SUPPORTED, SHOP_CONFIG,
    getLang, setLang, onChange, isLoaded,
    t, merge, tProduct, tCategory,
    setEndpoint, load, applyShop,
    getCustomer, setCustomer, signOut,
  };
})();
