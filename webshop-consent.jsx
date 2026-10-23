/* =====================================================================
   webshop-consent.jsx — window.WSConsent : le consentement aux cookies
   (Consent Mode v2 de Google) et le suivi e-commerce.

   Trois règles :
   • Avant tout choix, tout est refusé (index.html le déclare AVANT le
     conteneur Google Tag Manager). La balise Google Ads du conteneur ne dépose
     donc rien tant que la personne n'a pas dit oui à la publicité.
   • Google Analytics 4 n'est chargé qu'après un oui à la mesure d'audience, et
     seulement si un identifiant de mesure est configuré
     (ws_param.ga4_measurement_id, servi par GET /config). Sans identifiant, le
     webshop marche exactement comme avant.
   • Le suivi ne casse jamais l'achat : chaque envoi est dans un try.

   Les événements e-commerce (view_item, add_to_cart, begin_checkout, purchase)
   partent dans dataLayer pour le conteneur (l'agence y lit transaction_id,
   value, currency, comme avant) et, avec l'objet `ecommerce` au vocabulaire
   GA4, vers la propriété GA4 (send_to : elle seule, pas Google Ads).
   ===================================================================== */
(function () {
  const LS = 'ws.consent';
  const VERSION = 1;
  const listeners = new Set();     // choix enregistré
  const openers = new Set();       // demande de réouverture du bandeau
  let ga4 = null;
  let ga4Charge = false;

  function gtag() {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(arguments);
  }

  /** Le choix gardé dans ce navigateur, ou null s'il n'y en a pas (ou d'une ancienne version du bandeau). */
  function read() {
    try {
      const c = JSON.parse(localStorage.getItem(LS) || 'null');
      return c && c.v === VERSION ? c : null;
    } catch (_) { return null; }
  }

  /** Les quatre signaux du Consent Mode v2 pour un choix. */
  function signaux(c) {
    const mesure = c && c.mesure ? 'granted' : 'denied';
    const pub = c && c.pub ? 'granted' : 'denied';
    return { analytics_storage: mesure, ad_storage: pub, ad_user_data: pub, ad_personalization: pub };
  }

  function chargerGa4() {
    const c = read();
    if (ga4Charge || !ga4 || !c || !c.mesure) return;
    ga4Charge = true;
    try {
      const s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga4);
      document.head.appendChild(s);
      gtag('js', new Date());
      gtag('config', ga4);
    } catch (_) { /* le suivi ne casse jamais la page */ }
  }

  /** Enregistre un choix ({ mesure, pub }) et le transmet aux balises. */
  function save(choix) {
    const c = { v: VERSION, mesure: !!(choix && choix.mesure), pub: !!(choix && choix.pub), le: new Date().toISOString() };
    try { localStorage.setItem(LS, JSON.stringify(c)); } catch (_) { /* navigation privée : le choix vaut pour la visite */ }
    try {
      gtag('consent', 'update', signaux(c));
      window.dataLayer.push({ event: 'consent_update', consent_mesure: c.mesure, consent_pub: c.pub });
    } catch (_) {}
    chargerGa4();
    listeners.forEach((fn) => { try { fn(c); } catch (_) {} });
    return c;
  }

  /** La configuration du serveur (GET /config) : l'identifiant GA4, s'il est posé. */
  function setConfig(cfg) {
    const id = cfg && typeof cfg.ga4MeasurementId === 'string' ? cfg.ga4MeasurementId.trim() : '';
    ga4 = /^G-[A-Z0-9]{4,20}$/.test(id) ? id : null;
    chargerGa4();
  }

  /**
   * Un fait métier : `data` à plat pour le conteneur (comme avant), `ecommerce`
   * au vocabulaire GA4 ({ currency, value, items, … }). L'objet ecommerce
   * précédent est vidé d'abord, comme Google le demande, pour qu'un article
   * d'un événement ne se retrouve pas dans le suivant.
   */
  function track(event, data, ecommerce) {
    try {
      window.dataLayer = window.dataLayer || [];
      if (ecommerce) window.dataLayer.push({ ecommerce: null });
      window.dataLayer.push(Object.assign({ event: event }, data || {}, ecommerce ? { ecommerce: ecommerce } : {}));
      if (ga4Charge && ecommerce) gtag('event', event, Object.assign({ send_to: ga4 }, ecommerce));
    } catch (_) { /* le suivi ne casse jamais l'achat */ }
  }

  /** Une ligne de panier → un article GA4. */
  function article(l) {
    const it = { item_id: String(l.productId != null ? l.productId : (l.id != null ? l.id : '')), item_name: String(l.name || ''),
      price: Number(l.price) || 0, quantity: Number(l.qty) || 1 };
    if (l.cat) it.item_category = String(l.cat);
    if (l.portion) it.item_variant = String(l.portion);
    return it;
  }

  function open() { openers.forEach((fn) => { try { fn(); } catch (_) {} }); }
  function onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); }
  function onOpen(fn) { openers.add(fn); return () => openers.delete(fn); }

  window.WSConsent = { read, save, setConfig, track, article, open, onChange, onOpen };

  /* ── Le bandeau ───────────────────────────────────────────────────────
     Affiché tant qu'aucun choix n'est enregistré, et rouvert depuis « Cookies »
     (menu des langues) ou par ?cookies=1. Trois boutons de même poids :
     accepter, refuser, choisir — refuser ne demande pas plus d'effort. */
  function WSConsentBanner() {
    const { t } = window.useT ? window.useT() : { t: (k) => (window.WSI18n ? window.WSI18n.t(k) : k) };
    const [visible, setVisible] = React.useState(() => {
      if (read()) {
        try { return new URLSearchParams(location.search).get('cookies') === '1'; } catch (_) { return false; }
      }
      return true;
    });
    const [details, setDetails] = React.useState(false);
    const actuel = read();
    const [mesure, setMesure] = React.useState(actuel ? actuel.mesure : false);
    const [pub, setPub] = React.useState(actuel ? actuel.pub : false);

    React.useEffect(() => onOpen(() => {
      const c = read();
      setMesure(c ? c.mesure : false);
      setPub(c ? c.pub : false);
      setDetails(true);
      setVisible(true);
    }), []);

    if (!visible) return null;
    const fermer = (choix) => { save(choix); setVisible(false); setDetails(false); };

    return (
      <div className="ws-consent" role="dialog" aria-live="polite" aria-label={t('consent.title')}>
        <div className="ws-consent__text">
          <strong className="ws-consent__title">{t('consent.title')}</strong>
          <p>{t('consent.body')}</p>
        </div>
        {details && (
          <div className="ws-consent__choices">
            <label className="ws-consent__choice">
              <input type="checkbox" checked={mesure} onChange={(e) => setMesure(e.target.checked)} />
              <span><strong>{t('consent.analytics')}</strong><small>{t('consent.analytics.help')}</small></span>
            </label>
            <label className="ws-consent__choice">
              <input type="checkbox" checked={pub} onChange={(e) => setPub(e.target.checked)} />
              <span><strong>{t('consent.ads')}</strong><small>{t('consent.ads.help')}</small></span>
            </label>
          </div>
        )}
        <div className="ws-consent__actions">
          <button type="button" className="ws-consent__btn" onClick={() => fermer({ mesure: false, pub: false })}>{t('consent.refuse')}</button>
          {details
            ? <button type="button" className="ws-consent__btn" onClick={() => fermer({ mesure, pub })}>{t('consent.save')}</button>
            : <button type="button" className="ws-consent__btn" onClick={() => setDetails(true)}>{t('consent.choose')}</button>}
          <button type="button" className="ws-consent__btn ws-consent__btn--main" onClick={() => fermer({ mesure: true, pub: true })}>{t('consent.accept')}</button>
        </div>
      </div>
    );
  }

  window.WSConsentBanner = WSConsentBanner;
})();
