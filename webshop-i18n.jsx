/* =====================================================================
   WEBSHOP i18n — Multilingual support
   ---------------------------------------------------------------------
   - Supported languages: fr, nl, en, de
   - Source language: fr (canonical strings live in fr dictionary)
   - Other languages: bracketed placeholders ([NL] / [EN] / [DE] …) you
     can replace with real translations.
   - Persistence: localStorage('ws.lang') + mock customer.preferredLang
     round-trip via a fake profile in localStorage('ws.customer').
   - Auto-detect: navigator.language on first visit if it matches one of
     fr/nl/en/de, else falls back to shop default (fr).
   - Cart is untouched on language change. Orders are tagged with the
     current language in their metadata.
   ===================================================================== */

(function () {
  const SUPPORTED = ['fr', 'nl', 'en', 'de'];
  const SHOP_DEFAULT = 'fr';

  /* ---------- shop config ------------------------------------------- */
  const SHOP_CONFIG = {
    availableLanguages: SUPPORTED,
    defaultLanguage: SHOP_DEFAULT,
  };

  /* ---------- UI string dictionary ---------------------------------- */
  /* Every visible UI string lives here. Components call t('key') to read.
     Add new keys to the fr block first; other languages should mirror. */
  const UI = {
    fr: {
      // top nav
      'nav.shopChip': 'Boutique',
      'nav.changeShop': 'Changer de boutique',
      'nav.date': 'Pour le',
      'nav.mode.collect': 'À emporter',
      'nav.mode.delivery': 'Livraison',
      'nav.cart': 'Panier',
      'nav.menu': 'Menu',
      'nav.signin': 'Connexion',
      'nav.profile': 'Profil',
      'nav.language': 'Langue',

      // categories shell
      'cats.all': 'Tout voir',

      // category navigation — single line, two levels. The first slot is
      // always occupied: `all` at category level, `back` at subcategory
      // level. `back` is a template — the category name is injected as a
      // variable, never concatenated by the caller.
      'nav.category.all': 'Tout',
      'nav.category.back': '← {category}',
      'nav.category.cats': 'Catégories',
      'nav.category.subsOf': 'Sous-catégories de {category}',

      // product card
      'card.add': 'Ajouter',
      'card.from': 'dès',
      'card.info': 'Infos',
      'card.allergens': 'Allergènes',
      'card.unavailable': 'Indisponible',

      // basket
      'basket.title': 'Mon panier',
      'basket.empty.title': 'Votre panier est vide',
      'basket.empty.sub': 'Parcourez nos catégories pour ajouter des produits.',
      'basket.subtotal': 'Sous-total',
      'basket.delivery': 'Livraison',
      'basket.total': 'Total',
      'basket.tax': 'TVA incluse',
      'basket.checkout': 'Commander',
      'basket.continue': 'Poursuivre',
      'basket.remove': 'Retirer',
      'basket.qty': 'Quantité',
      'basket.note': 'Note pour la cuisine',
      'basket.notePlaceholder': 'Allergies, préférences…',

      // detail modal
      'pdm.bundles': 'Formules',
      'pdm.options': 'Options',
      'pdm.required': 'Requis',
      'pdm.optional': 'Facultatif',
      'pdm.pickOne': 'Choisissez 1',
      'pdm.pickUpTo': 'Jusqu’à {n}',
      'pdm.add': 'Ajouter au panier',
      'pdm.adding': 'Ajout…',
      'pdm.added': 'Ajouté',
      'pdm.close': 'Fermer',
      'pdm.qty': 'Quantité',

      // checkout
      'checkout.title': 'Finaliser ma commande',
      'checkout.contact': 'Coordonnées',
      'checkout.firstName': 'Prénom',
      'checkout.lastName': 'Nom',
      'checkout.email': 'E-mail',
      'checkout.phone': 'Téléphone',
      'checkout.pickup': 'Retrait',
      'checkout.delivery': 'Livraison',
      'checkout.address': 'Adresse',
      'checkout.zip': 'Code postal',
      'checkout.city': 'Ville',
      'checkout.payment': 'Paiement',
      'checkout.payOnSite': 'Sur place',
      'checkout.payOnline': 'En ligne',
      'checkout.confirm': 'Confirmer la commande',
      'checkout.terms': 'En commandant, vous acceptez nos conditions.',

      // confirmation / order
      'order.thanks': 'Merci pour votre commande !',
      'order.number': 'Numéro de commande',
      'order.lang': 'Langue',
      'order.email.subject': 'Confirmation de commande',
      'order.email.body': 'Bonjour, votre commande est confirmée.',

      // profile
      'profile.title': 'Mon profil',
      'profile.greeting': 'Bonjour, {name}',
      'profile.preferredLang': 'Langue préférée',
      'profile.preferredLang.help': 'Sera utilisée à votre prochaine connexion.',
      'profile.signout': 'Se déconnecter',
      'profile.save': 'Enregistrer',
      'profile.saved': 'Préférence enregistrée',
      'profile.guest': 'Vous n’êtes pas connecté',

      // language selector
      'lang.selector.label': 'Langue',
      'lang.fr': 'Français',
      'lang.nl': 'Nederlands',
      'lang.en': 'English',
      'lang.de': 'Deutsch',

      // misc
      'common.cancel': 'Annuler',
      'common.save': 'Enregistrer',
      'common.close': 'Fermer',
      'common.back': 'Retour',
      'common.loading': 'Chargement…',
      'common.error': 'Une erreur est survenue',
    },
  };

  /* Build placeholder dictionaries for nl/en/de — every key from fr,
     wrapped with a [LANG] tag the user can grep for and replace. */
  ['nl', 'en', 'de'].forEach((lang) => {
    const tag = `[${lang.toUpperCase()}] `;
    const out = {};
    for (const key of Object.keys(UI.fr)) {
      out[key] = tag + UI.fr[key];
    }
    UI[lang] = out;
  });

  /* Real translations for keys that sit permanently in the storefront chrome
     (a [TAG] placeholder there would read as broken, not as "to translate").
     Applied AFTER the placeholder generation so they win. `back` stays a
     template per language — translators may move the variable. */
  const UI_REAL = {
    nl: {
      'nav.category.all': 'Alles',
      'nav.category.back': '← {category}',
      'nav.category.cats': 'Categorieën',
      'nav.category.subsOf': 'Subcategorieën van {category}',
    },
    en: {
      'nav.category.all': 'All',
      'nav.category.back': '← {category}',
      'nav.category.cats': 'Categories',
      'nav.category.subsOf': 'Subcategories of {category}',
    },
    de: {
      'nav.category.all': 'Alle',
      'nav.category.back': '← {category}',
      'nav.category.cats': 'Kategorien',
      'nav.category.subsOf': 'Unterkategorien von {category}',
    },
  };
  Object.entries(UI_REAL).forEach(([lang, dict]) => Object.assign(UI[lang], dict));

  /* ---------- product / category translations ---------------------- */
  /* Keyed by id — separate from the products array so the data can stay
     monolingual in the source. Add entries here per id for full coverage. */
  const PRODUCT_TR = {
    fr: {
      // canonical — empty means "use the source field"
    },
    // Placeholders for a few ids so you can see the round-trip working.
    nl: {
      1: { name: '[NL] Tarte aux fraises' },
      2: { name: '[NL] Tarte aux fruits frais' },
      3: { name: '[NL] Tarte au citron' },
    },
    en: {
      1: { name: '[EN] Tarte aux fraises' },
      2: { name: '[EN] Tarte aux fruits frais' },
      3: { name: '[EN] Tarte au citron' },
    },
    de: {
      1: { name: '[DE] Tarte aux fraises' },
      2: { name: '[DE] Tarte aux fruits frais' },
      3: { name: '[DE] Tarte au citron' },
    },
  };

  const CATEGORY_TR = {
    fr: {},
    nl: { tarts: '[NL] Tartes', sandwiches: '[NL] Sandwichs', plats: '[NL] Plats', viennoiseries: '[NL] Viennoiseries', boissons: '[NL] Boissons' },
    en: { tarts: '[EN] Tarts', sandwiches: '[EN] Sandwiches', plats: '[EN] Mains', viennoiseries: '[EN] Pastries', boissons: '[EN] Drinks' },
    de: { tarts: '[DE] Torten', sandwiches: '[DE] Sandwiches', plats: '[DE] Hauptgerichte', viennoiseries: '[DE] Gebäck', boissons: '[DE] Getränke' },
  };

  /* ---------- persistence helpers ---------------------------------- */
  const LS_LANG = 'ws.lang';
  const LS_CUSTOMER = 'ws.customer';

  function readLS(key) {
    try { return localStorage.getItem(key); } catch { return null; }
  }
  function writeLS(key, val) {
    try { localStorage.setItem(key, val); } catch {}
  }
  function readJSON(key) {
    try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : null; } catch { return null; }
  }
  function writeJSON(key, val) {
    try { localStorage.setItem(key, JSON.stringify(val)); } catch {}
  }

  function detectLang() {
    // 1. explicit user choice
    const stored = readLS(LS_LANG);
    if (stored && SUPPORTED.includes(stored)) return stored;
    // 2. customer profile preference
    const customer = readJSON(LS_CUSTOMER);
    if (customer && SUPPORTED.includes(customer.preferredLang)) return customer.preferredLang;
    // 3. browser language
    const nav = (navigator.language || 'fr').slice(0, 2).toLowerCase();
    if (SUPPORTED.includes(nav)) return nav;
    // 4. shop default
    return SHOP_CONFIG.defaultLanguage || SHOP_DEFAULT;
  }

  /* ---------- public API ------------------------------------------- */
  const listeners = new Set();
  let currentLang = detectLang();

  function getLang() { return currentLang; }

  function setLang(lang) {
    if (!SUPPORTED.includes(lang)) return;
    if (lang === currentLang) return;
    currentLang = lang;
    writeLS(LS_LANG, lang);
    // Round-trip into customer profile if one exists
    const customer = readJSON(LS_CUSTOMER);
    if (customer) {
      customer.preferredLang = lang;
      writeJSON(LS_CUSTOMER, customer);
    }
    document.documentElement.setAttribute('lang', lang);
    listeners.forEach((fn) => { try { fn(lang); } catch {} });
  }

  function onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); }

  function format(str, params) {
    if (!params) return str;
    return str.replace(/\{(\w+)\}/g, (_, k) => (k in params ? params[k] : `{${k}}`));
  }

  function t(key, params) {
    const dict = UI[currentLang] || UI.fr;
    const fallback = UI.fr;
    const raw = (dict && dict[key]) || (fallback && fallback[key]) || key;
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
    const id = product.id;
    const tr = PRODUCT_TR[currentLang] && PRODUCT_TR[currentLang][id];
    if (tr && tr[field]) return tr[field];
    return product[field] || '';
  }

  function tCategory(catId, fallback) {
    const tr = CATEGORY_TR[currentLang] && CATEGORY_TR[currentLang][catId];
    return tr || fallback || catId;
  }

  /* ---------- mock customer profile -------------------------------- */
  function getCustomer() {
    return readJSON(LS_CUSTOMER);
  }
  function setCustomer(profile) {
    writeJSON(LS_CUSTOMER, profile);
    if (profile && profile.preferredLang && SUPPORTED.includes(profile.preferredLang)) {
      setLang(profile.preferredLang);
    }
  }
  function signOut() {
    try { localStorage.removeItem(LS_CUSTOMER); } catch {}
  }

  /* ---------- export ----------------------------------------------- */
  document.documentElement.setAttribute('lang', currentLang);

  window.WSI18n = {
    SUPPORTED,
    SHOP_CONFIG,
    getLang,
    setLang,
    onChange,
    t,
    merge,
    tProduct,
    tCategory,
    getCustomer,
    setCustomer,
    signOut,
  };
})();
