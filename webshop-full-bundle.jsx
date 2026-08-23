
// ===== webshop.jsx (full-bleed) =====
// webshop.jsx — L'Atelier By customer-facing webshop
// Three variants of how the active shop is anchored in the chrome.

const { useState, useMemo, useEffect } = React;

// iOS Safari n'applique les états :active au toucher que si un écouteur
// touch existe : sans lui, tout le retour « pressé » ajouté en CSS resterait
// invisible sur iPhone. No-op passif, coût nul.
document.addEventListener('touchstart', function () {}, { passive: true });

// Appareil au doigt : le dépliage est instantané (CSS), les recentrages ne
// doivent donc plus attendre la fin d'une animation qui n'existe plus.
const IS_TOUCH = window.matchMedia && window.matchMedia('(hover: none)').matches;

// Version servie : visible en console et dans l'encadré ?tapdebug=1 — pour
// distinguer « le correctif ne marche pas » de « le téléphone sert encore
// l'ancien bundle » (service worker). Et rafraîchissement agressif du SW :
// vérification de mise à jour au retour d'onglet et toutes les 60 s.
const WS_BUILD = (typeof __WS_BUILD__ !== 'undefined') ? __WS_BUILD__ : 'dev';
window.__WS_BUILD = WS_BUILD;
try { console.info('[ws] build ' + WS_BUILD); } catch (e) {}
if ('serviceWorker' in navigator) {
  const ping = () => navigator.serviceWorker.getRegistration().then((r) => r && r.update()).catch(() => {});
  document.addEventListener('visibilitychange', () => { if (!document.hidden) ping(); });
  setInterval(ping, 60000);
}

/* ── TAP FIABLE, GÉNÉRALISÉ ────────────────────────────────────────────────
   L'action part au relâchement (pointerup/touchend) comme en natif, même
   quand le navigateur retient le click (tap qui stoppe l'inertie du scroll,
   geste requalifié). L'état vit SUR LE NŒUD DOM : chaque élément a le sien —
   un tap sur B ne peut jamais être mangé par la dédup d'un tap sur A (le
   verrou partagé de l'ancienne version mangeait le 2e tap < 400 ms).
   opts.open : marque un dépliage/une ouverture — arme la fenêtre anti-clic-
   fantôme (le click synthétisé du MÊME tap atterrit sur le contenu qui vient
   d'apparaître : il arrive en « orphelin », sans appui vu sur sa cible, et
   la fenêtre le neutralise ; un clic clavier, orphelin aussi, passe hors
   fenêtre). opts.stop : n'alimente pas les gestionnaires des ancêtres (les
   boutons imbriqués dans une carte elle-même tappable). */
let __wsOpenedAt = 0;
let __wsPressTarget = null;
let __wsPressAt = 0;
function wsShield() { __wsOpenedAt = performance.now(); }
/* BOUCLIER GLOBAL. Quand une action change la mise en page SOUS le doigt
   (dépliage, fermeture de la fiche, panier → checkout, commande passée), le
   click synthétisé du même tap atterrit sur ce qui vient d'apparaître à cet
   endroit — constaté : « Commander » posait le clic sur « Se connecter » du
   checkout, d'où une demande de connexion à chaque validation. Signature
   infaillible d'un fantôme : sa cible n'a reçu AUCUN appui (pointerdown) de
   ce geste. Un vrai tap suivant a toujours son propre appui : il passe. */
document.addEventListener('pointerdown', (e) => {
  __wsPressTarget = e.target; __wsPressAt = performance.now();
}, { capture: true, passive: true });
document.addEventListener('click', (e) => {
  if (performance.now() - __wsOpenedAt > 400) return;
  const t = __wsPressTarget;
  if (t && __wsPressAt <= performance.now() && t.isConnected
      && (t === e.target || t.contains(e.target) || e.target.contains(t))) return;
  e.stopPropagation(); e.preventDefault();
}, { capture: true });
function wsTap(fn, opts) {
  /* ÉCOUTEURS NATIFS, PAS SYNTHÉTIQUES. Diagnostic au doigt sur la fiche
     produit : les événements natifs d'un tap ATTEIGNENT l'élément et
     remontent jusqu'à la racine (sondes posées à chaque étage), mais la
     délégation synthétique de React n'invoquait AUCUN gestionnaire de la
     carte tapée — alors qu'un click dispatché par script passait. Plutôt que
     de dépendre de cette dispatche défaillante, chaque élément reçoit ses
     écouteurs natifs via ref. La closure du DERNIER rendu est rejouée
     (el.__wsTapConf.fn), les écouteurs ne sont posés qu'une fois par nœud,
     et meurent avec lui. */
  const conf = { fn, doOpen: !!(opts && (opts.open || opts.shield)), doStop: !!(opts && opts.stop) };
  return {
    ref: (el) => {
      if (!el) return;
      el.__wsTapConf = conf;
      if (el.__wsTapBound) return;
      el.__wsTapBound = true;
      const st = (e) => { if (el.__wsTapConf.doStop) e.stopPropagation(); };
      const fire = () => {
        const p = el.__wsTap || (el.__wsTap = {});
        p.handled = performance.now();
        if (el.__wsTapConf.doOpen) wsShield();
        el.__wsTapConf.fn();
      };
      el.addEventListener('pointerdown', (e) => {
        st(e);
        el.__wsTap = { x: e.clientX, y: e.clientY, t: performance.now(), id: e.pointerId, handled: 0 };
      });
      el.addEventListener('pointerup', (e) => {
        st(e);
        const p = el.__wsTap;
        if (!p || p.id !== e.pointerId || !p.t) return;
        const moved = Math.abs(e.clientX - p.x) > 14 || Math.abs(e.clientY - p.y) > 14;
        if (!moved && performance.now() - p.t < 600 && !(p.handled && performance.now() - p.handled < 350)) fire();
      });
      el.addEventListener('touchstart', (e) => {
        st(e);
        const t = e.touches[0]; if (!t) return;
        const p = el.__wsTap || (el.__wsTap = {});
        p.tx = t.clientX; p.ty = t.clientY; p.tt = performance.now();
      }, { passive: true });
      el.addEventListener('touchend', (e) => {
        st(e);
        const p = el.__wsTap; const t = e.changedTouches && e.changedTouches[0];
        if (!p || !t || !p.tt) return;
        if (p.handled && performance.now() - p.handled < 350) return;
        const moved = Math.abs(t.clientX - p.tx) > 12 || Math.abs(t.clientY - p.ty) > 12;
        if (!moved && performance.now() - p.tt < 600) fire();
      });
      el.addEventListener('click', (e) => {
        st(e);
        const p = el.__wsTap || {};
        const now = performance.now();
        if (p.handled && now - p.handled < 400) return;
        const sawPress = (p.t && now - p.t < 600) || (p.tt && now - p.tt < 600);
        if (!sawPress && now - __wsOpenedAt < 250) return;
        fire();
      });
    },
  };
}


// Diagnostic terrain : ?tapdebug=1 affiche les derniers événements tactiles
// (type + cible) dans un coin de l'écran — pour voir ce que LE téléphone fait
// réellement d'un tap qui « ne marche pas », sans outillage branché.
if (/[?&]tapdebug=1/.test(location.search)) {
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;left:4px;bottom:4px;z-index:99999;background:rgba(0,0,0,.78);color:#7CFC90;font:10px/1.4 monospace;padding:6px 8px;border-radius:6px;max-width:72vw;pointer-events:none;white-space:pre;';
  document.body.appendChild(box);
  const lines = ['build ' + ((typeof __WS_BUILD__ !== 'undefined') ? __WS_BUILD__ : 'dev')];
  const log = (e) => {
    const cls = ((e.target.getAttribute && e.target.getAttribute('class')) || e.target.tagName || '?').split(' ')[0];
    lines.push((e.type + '            ').slice(0, 13) + cls.slice(0, 26));
    if (lines.length > 9) lines.shift();
    box.textContent = lines.join('\n');
  };
  for (const t of ['pointerdown', 'pointerup', 'pointercancel', 'touchstart', 'touchend', 'touchcancel', 'click'])
    document.addEventListener(t, log, { capture: true, passive: true });
}

// =========================================================================
// DATA
// =========================================================================
// Go-live : AUCUNE boutique en dur. Les boutiques viennent exclusivement de
// l'API /shops (table `shops`) ; si elle est injoignable, l'UI affiche une
// erreur — jamais de données de démo (« Maison Châtelain » et consorts).
// L'objet global window.W_SHOPS a été supprimé : tant qu'il existait, un
// repli pouvait y puiser une liste de boutiques fictive.

const W_CATEGORIES = [];

const W_ASSORTMENTS = [];

// --- Line-art product placeholders (design-system illustrations) ---
const PLACEHOLDER_BY_SUBCAT = {
  'sand-classic': 'img/placeholders/sandwiches.png',
  'sand-deluxe':  'img/placeholders/sandwiches.png',
  'sand-veggie':  'img/placeholders/sandwiches.png',
  'plats-traiteur': 'img/placeholders/savoury-tart.png',
};
const PLACEHOLDER_BY_CAT = {
  breads:     'img/placeholders/bread.png',
  vienn:      'img/placeholders/croissant.png',
  tarts:      'img/placeholders/sweet-tart.png',
  sweet:      'img/placeholders/cookies.png',
  plats:      'img/placeholders/savoury-tart.png',
  salades:    'img/placeholders/salads.png',
  sandwiches: 'img/placeholders/sandwiches.png',
  drinks:     'img/placeholders/cold-drink.png',
  boissons:   'img/placeholders/hot-drink.png',
};
function getPlaceholder(p) {
  return (p && p.subCat && PLACEHOLDER_BY_SUBCAT[p.subCat])
      || (p && PLACEHOLDER_BY_CAT[p.cat])
      || 'img/placeholders/cake.png';
}

// Go-live : plus aucun produit de démo (Tarte aux fraises & co purgés).
const W_PRODUCTS = [];

const W_PRODUCT_PRICES = {};
const W_SHOP_PRODUCTS = {};

// Go-live : window._CATALOG_SEED n’est plus exposé — le catalogue vient
// exclusivement de l'API /catalog ; en cas d'échec, l'UI reste vide/erreur.


// =========================================================================
// CLIENTS / OFFICES / DELIVERY TOURS
// A client may be linked to one office; an office may be linked to one tour.
// Delivery is enabled only if both links exist (office validated + tour set).
// =========================================================================
// Go-live : AUCUN store local. Utilisateurs (WSAuth), bureaux (WSOffices) et
// tournées (WSTours) viennent du serveur ; sans lui, l'écran affiche une erreur.
const SRV_REQUIRED = (what) => ({ ok: false, error: 'Service ' + what + ' indisponible — please debug.' });

// =========================================================================
// SHARED PRIMITIVES
// =========================================================================

const Pict = ({ d, s = 16 }) => (
  <svg viewBox="0 0 24 24" width={s} height={s} fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
);

const ICONS = {
  chev:    <path d="M9 6l6 6-6 6"/>,
  cal:     <><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></>,
  search:  <><circle cx="11" cy="11" r="6"/><path d="M16 16l4 4"/></>,
  user:    <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></>,
  bag:     <><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 016 0"/></>,
  pin:     <><path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></>,
  shop:    <><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></>,
  truck:   <><path d="M3 16V7h11v9M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></>,
  back:    <path d="M15 6l-6 6 6 6"/>,
  plus:    <><path d="M12 5v14M5 12h14"/></>,
  info:    <><circle cx="12" cy="12" r="9"/><path d="M12 8v.01M12 11v5"/></>,
  options: <><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></>,
  check:   <path d="M5 12l4 4 10-10"/>,
  close:   <path d="M6 6l12 12M18 6L6 18"/>,
  switch:  <><path d="M4 9h13l-3-3M20 15H7l3 3"/></>,
};

// =========================================================================
// DATE PILL — date picker with popover calendar
// =========================================================================
/* Noms de jours et de mois : rendus par Intl DANS LA LANGUE COURANTE — jamais
   des tableaux français en dur (une boutique néerlandophone affichait « Dim. »
   à côté de libellés en NL). Ils n'ont pas leur place dans la table de
   traduction : le navigateur les connaît pour toutes les langues, et une copie
   en base se désynchroniserait. Le reste des libellés vient bien de ws_i18n. */
function wsLocale() {
  const l = (window.WSI18n && window.WSI18n.getLang && window.WSI18n.getLang()) || 'fr';
  return l === 'nl' ? 'nl-BE' : l === 'en' ? 'en-GB' : l === 'de' ? 'de-DE' : 'fr-BE';
}
function wsCap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
// Mois en toutes lettres (en-tête du calendrier) : « août » / « augustus ».
function wsMonthName(monthIndex, year) {
  return wsCap(new Date(year != null ? year : 2000, monthIndex, 1)
    .toLocaleDateString(wsLocale(), { month: 'long' }));
}
// Ligne d'en-tête du calendrier : Lun…Dim / Ma…Zo, semaine commençant le lundi.
function wsWeekdayNames() {
  const base = new Date(2024, 0, 1);              // 1er janvier 2024 = un lundi
  return Array.from({ length: 7 }, (_, i) =>
    wsCap(new Date(2024, 0, 1 + i).toLocaleDateString(wsLocale(), { weekday: 'short' })
      .replace(/\.$/, '')));
}
function wsFormatPill(d) {
  // Pastille du bandeau : jour + numéro, SANS le mois. « Dim. 16 » suffit à
  // lever l'ambiguïté (on ne franchit presque jamais une fin de mois) et
  // libère la largeur qui manquait au nom de la boutique. Le mois reste
  // affiché dans le calendrier ouvert.
  const day = wsCap(d.toLocaleDateString(wsLocale(), { weekday: 'short' }));
  return `${day} ${d.getDate()}`;
}
// i18n : hook réactif (webshop-i18n-react → window.useT). Re-rend le composant
// au changement de langue ; repli direct sur WSI18n.t si le hook n'est pas là.
function wsUseT() {
  return window.useT
    ? window.useT()
    : { t: (k, p) => (window.WSI18n ? window.WSI18n.t(k, p) : k), tCategory: (id, fb) => fb,
        lang: (window.WSI18n && window.WSI18n.getLang) ? window.WSI18n.getLang() : 'fr',
        setLang: (l) => { if (window.WSI18n) window.WSI18n.setLang(l); } };
}
/* Rendu RICHE d'un libellé traduit : une phrase = UNE clé, même quand une
   partie est mise en valeur. Le texte en base porte des marqueurs —
   **fort** → <strong>, __accent__ → <em> — au lieu d'être coupé en morceaux
   par le JSX. Découper « Bon retour <em>parmi nous</em>. » en deux clés
   rendait la phrase intraduisible : l'ordre des mots change d'une langue à
   l'autre, et le traducteur doit pouvoir DÉPLACER l'emphase. */
function tRich(t, key, params) {
  const raw = t(key, params);
  const out = [];
  const re = /\*\*([^*]+)\*\*|__([^_]+)__/g;
  let last = 0, m, i = 0;
  while ((m = re.exec(raw)) !== null) {
    if (m.index > last) out.push(raw.slice(last, m.index));
    out.push(m[1] != null
      ? <strong key={i++}>{m[1]}</strong>
      : <em key={i++}>{m[2]}</em>);
    last = m.index + m[0].length;
  }
  if (last < raw.length) out.push(raw.slice(last));
  return out;
}
function DatePill({ mode, value, onChange, shopId,
                    collectCutoffPassed, collectCutoffLabel,
                    deliveryCutoffPassed, deliveryCutoffLabel,
                    minLeadDays }) {
  const { t } = wsUseT();
  const [open, setOpen] = React.useState(false);
  const [view, setView] = React.useState(() => new Date(value.getFullYear(), value.getMonth(), 1));
  // dayMap: { 'YYYY-MM-DD': { available, reason } } — populated per visible month
  const [dayMap, setDayMap] = React.useState({});
  const [loadingDays, setLoadingDays] = React.useState(false);
  const wrapRef = React.useRef(null);

  // Close on outside click / Escape
  React.useEffect(() => {
    if (!open) return;
    const off = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    const esc = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', off, true);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('pointerdown', off, true); document.removeEventListener('keydown', esc); };
  }, [open]);

  // Fetch available days for the visible month whenever view/shop/mode changes
  React.useEffect(() => {
    const api = window.WSAvailability || window.WSCalendar;
    if (!api || typeof api.listAvailableDays !== 'function') return;
    let alive = true;
    const from = `${view.getFullYear()}-${String(view.getMonth()+1).padStart(2,'0')}-01`;
    const lastDay = new Date(view.getFullYear(), view.getMonth()+1, 0).getDate();
    const to = `${view.getFullYear()}-${String(view.getMonth()+1).padStart(2,'0')}-${lastDay}`;
    setLoadingDays(true);
    api.listAvailableDays({ shopId, mode, from, to })
      .then((rows) => {
        if (!alive) return;
        const map = {};
        for (const row of (rows || [])) map[row.iso] = row;
        setDayMap((prev) => ({ ...prev, ...map }));
      })
      .catch(() => {})
      .finally(() => { if (alive) setLoadingDays(false); });
    return () => { alive = false; };
  }, [view.getFullYear(), view.getMonth(), shopId, mode]);

  function pad(n) { return String(n).padStart(2, '0'); }
  function isoOf(d) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }

  const today = new Date(); today.setHours(0, 0, 0, 0);
  const firstDow = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7;
  const daysInMonth = new Date(view.getFullYear(), view.getMonth()+1, 0).getDate();
  const cells = [];
  for (let i = 0; i < firstDow; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(new Date(view.getFullYear(), view.getMonth(), d));
  while (cells.length % 7) cells.push(null);

  const sameDay = (a, b) => a && b &&
    a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
  const shift = (n) => setView(new Date(view.getFullYear(), view.getMonth()+n, 1));

  // Cutoff for the active mode
  const cutoffPassed = mode === 'delivery' ? deliveryCutoffPassed : collectCutoffPassed;
  const cutoffLabel  = mode === 'delivery' ? deliveryCutoffLabel  : collectCutoffLabel;
  const cutoffTitle  = cutoffLabel ? `Fermé après ${cutoffLabel}` : 'Fermé (heure de commande dépassée)';

  // Today is blocked for the active mode if its cutoff passed
  const todayBlocked = cutoffPassed;

  function isCellDisabled(d) {
    const isPast = d < today;
    if (isPast) return { disabled: true, reason: 'past' };
    const isItToday = sameDay(d, today);
    if (isItToday && todayBlocked) return { disabled: true, reason: 'cutoff' };
    // Lead time: days too close to today for products in basket
    const daysDiff = Math.floor((d - today) / 86400000);
    if ((minLeadDays || 0) > 0 && daysDiff < minLeadDays)
      return { disabled: true, reason: 'lead_time' };
    // API-driven: shop closed, holiday, etc.
    const info = dayMap[isoOf(d)];
    if (info && !info.available) return { disabled: true, reason: info.reason || 'closed' };
    return { disabled: false, reason: null };
  }

  function cellTitle(d, reason) {
    if (reason === 'cutoff')     return cutoffTitle;
    if (reason === 'lead_time')  return `Commande requise ${minLeadDays} jour${minLeadDays > 1 ? 's' : ''} avant`;
    if (reason === 'closed')     return 'Boutique fermée';
    if (reason === 'holiday')    return 'Jour férié';
    if (reason === 'exception')  return dayMap[isoOf(d)]?.reason || 'Fermeture exceptionnelle';
    return undefined;
  }

  const todayOk = !todayBlocked && !isCellDisabled(today).disabled;

  return (
    <div ref={wrapRef} className="ws-datepill">
      <button className="ws-nav__date" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <Pict d={ICONS.cal} s={12}/>
        <span>{t(mode === 'delivery' ? 'nav.datepill.delivery' : 'nav.datepill.pickup')}</span>
        <strong>· {wsFormatPill(value)}</strong>
        <Pict d={ICONS.chev} s={10}/>
      </button>
      {open && (
        <div className={`ws-datepop ws-datepop--${mode}`} onClick={(e) => e.stopPropagation()}>
          <div className="ws-datepop__head">
            <button className="ws-datepop__nav" onClick={() => shift(-1)} aria-label={t('cal.prevMonth')}>‹</button>
            <span className="ws-datepop__title">
              {wsMonthName(view.getMonth(), view.getFullYear())} {view.getFullYear()}
              {loadingDays && <span style={{fontSize:9,opacity:.5,marginLeft:4}}>…</span>}
            </span>
            <button className="ws-datepop__nav" onClick={() => shift(1)} aria-label={t('cal.nextMonth')}>›</button>
          </div>
          <div className="ws-datepop__dow">
            {wsWeekdayNames().map((d, i) => <span key={i}>{d}</span>)}
          </div>
          <div className="ws-datepop__grid">
            {cells.map((d, i) => {
              if (!d) return <span key={i} className="ws-datepop__cell ws-datepop__cell--empty"/>;
              const { disabled, reason } = isCellDisabled(d);
              const isItToday = sameDay(d, today);
              const isSel = sameDay(d, value);
              const cls = ['ws-datepop__cell'];
              if (disabled) cls.push('is-past');
              if (isItToday) cls.push('is-today');
              if (isSel) cls.push('is-sel');
              if (!disabled && dayMap[isoOf(d)]?.available === false) cls.push('is-closed');
              return (
                <button key={i} className={cls.join(' ')} disabled={disabled}
                  title={cellTitle(d, reason)}
                  onClick={() => { onChange(d); setOpen(false); }}>
                  {d.getDate()}
                </button>
              );
            })}
          </div>
          <div className="ws-datepop__foot">
            <span>{mode === 'delivery' ? 'Livraison au bureau' : 'Collecte en magasin'}</span>
            <button className="ws-datepop__today"
              disabled={!todayOk}
              title={!todayOk && todayBlocked ? cutoffTitle : undefined}
              onClick={() => { onChange(today); setOpen(false); }}>
              Aujourd'hui
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/* CHOIX D'UN BUREAU — recherche, jamais liste déroulante.
   Une déroulante oblige à parcourir l'inventaire pour retrouver un nom déjà
   connu, et se prête mal au pouce. La saisie NE CRÉE RIEN : seules les sociétés
   déjà validées par l'Atelier apparaissent ; l'absente passe par la demande
   d'ajout, seul chemin qui fasse valider un bureau.
   UN SEUL composant pour les DEUX écrans qui choisissent un bureau. Ils avaient
   chacun leur liste déroulante, et corriger l'une laissait l'autre en arrière —
   c'est exactement ce qui vient de se produire. */
/* RECHERCHE D'UN BUREAU — le serveur ne répond qu'à une question posée.
   Aucune liste n'est affichée tant que rien n'est saisi : le carnet d'adresses
   B2B d'une boutique n'a pas à être feuilleté par n'importe quel visiteur
   connecté. On retrouve son employeur parce qu'on le connaît ; on ne découvre
   pas ceux des autres.
   Seuls les bureaux RÉELLEMENT LIVRABLES remontent — leur site porte une
   tournée active. Les autres ne sont pas montrés « avec leur motif » : un
   « bureau sans société rattachée » décrit un état interne du back-office, que
   le client ne peut ni comprendre ni corriger. C'est au franchisé de les
   traiter, et le workflow check-endpoints les lui compte et les lui nomme. */
function OfficeSearchPicker({ chercher, value, onPick, label }) {
  const { t } = wsUseT();
  const [q, setQ] = React.useState('');
  const [hits, setHits] = React.useState([]);
  const [busy, setBusy] = React.useState(false);
  const [cherche, setCherche] = React.useState(false);

  React.useEffect(() => {
    const terme = q.trim();
    if (terme.length < 2) { setHits([]); setCherche(false); setBusy(false); return; }
    let vivant = true;
    setBusy(true);
    // Petite attente : une requête par frappe inonderait le serveur et ferait
    // clignoter la liste sous le doigt.
    const t = setTimeout(() => {
      Promise.resolve(chercher(terme))
        .then((r) => { if (vivant) { setHits(Array.isArray(r) ? r : []); setCherche(true); } })
        .catch(() => { if (vivant) { setHits([]); setCherche(true); } })
        .then(() => { if (vivant) setBusy(false); });
    }, 260);
    return () => { vivant = false; clearTimeout(t); };
  }, [q]);

  return (
    <div className="ws-acc__pick">
      <input type="search" className="ws-acc__input" value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder={t('office.searchPh')}
        aria-label={label || 'Rechercher un bureau'} autoComplete="off"/>
      {q.trim().length < 2 ? (
        <p className="ws-acc__hint" style={{ margin: '8px 2px 0' }}>
          Tapez le nom de votre société, ou son adresse. Seuls les bureaux desservis
          par une tournée de cette boutique apparaissent.
        </p>
      ) : busy ? (
        <p className="ws-acc__hint" style={{ margin: '8px 2px 0' }}>{t('common.searchPh')}</p>
      ) : hits.length === 0 && cherche ? (
        <p className="ws-acc__hint" style={{ margin: '8px 2px 0' }}>
          Aucun bureau desservi ne correspond à «&nbsp;{q.trim()}&nbsp;». S'il s'agit du vôtre,
          demandez son ajout ci-dessous : votre Atelier le rattachera à une tournée.
        </p>
      ) : (
        <ul className="ws-acc__pick-list" role="listbox" aria-label={label || 'Bureaux'}>
          {hits.map((o) => (
            <li key={o.id}>
              <button type="button" role="option"
                aria-selected={String(value) === String(o.id)}
                className={'ws-acc__pick-item ws-acc__pick-bureau' + (String(value) === String(o.id) ? ' is-picked' : '')}
                onClick={() => onPick(String(o.id))}>
                {/* Le nom est celui de la SOCIÉTÉ ; l'adresse et la tournée
                    servent à la reconnaître, pas à la choisir. */}
                <span className="ws-acc__pick-name">{o.name}</span>
                {o.address ? <span className="ws-acc__pick-site">{o.address}
                  {o.points > 1 ? ' · ' + o.points + ' points de livraison' : ''}</span> : null}
                {o.tourName ? <span className="ws-acc__pick-tour">Tournée {o.tourName}</span> : null}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

// Mode pill — Ruby (collect) / Abricot (delivery)
function ModePills({ mode, onChange, collectCutoffPassed, collectCutoffLabel, deliveryCutoffPassed, deliveryCutoffLabel }) {
  const { t } = wsUseT();
  const [hover, setHover] = React.useState(false);
  const delivTitle = deliveryCutoffPassed
    ? `Livraison non disponible après ${deliveryCutoffLabel}`
    : undefined;
  const collTitle = collectCutoffPassed
    ? `Collecte non disponible après ${collectCutoffLabel}`
    : undefined;
  const effMode = deliveryCutoffPassed && mode === 'delivery' ? 'collect' : mode;
  return (
    <div className="ws-modewrap">
    <div className="ws-modes" role="tablist" aria-label={t('nav.modeAria')}>
      <span className="ws-modes__indicator" data-mode={effMode} aria-hidden="true"/>
      <button className={`ws-mode ws-mode--collect${mode === 'collect' ? ' is-active' : ''}${collectCutoffPassed ? ' is-disabled' : ''}`}
        onClick={() => onChange('collect')} role="tab" aria-selected={mode === 'collect'} aria-label={t('nav.mode.collect')}
        title={collTitle}>
        <Pict d={ICONS.bag} s={14}/>
        <span className="ws-mode__lbl-full">{t('nav.mode.collect')}</span>
        {collectCutoffPassed && <span className="ws-mode__cutoff"> · {t('nav.mode.closed')}</span>}
      </button>
      {/* Pas d'attribut `disabled` : un bouton désactivé n'émet aucun clic, donc
          le motif du refus ne pouvait jamais s'afficher — on retombait sur le
          bouton qui « ne fait rien ». L'apparence reste celle d'une option
          indisponible (is-disabled) et aria-disabled l'annonce aux lecteurs
          d'écran, mais le clic sert enfin à dire POURQUOI. */}
      <button className={`ws-mode ws-mode--delivery${mode === 'delivery' ? ' is-active' : ''}${deliveryCutoffPassed ? ' is-disabled' : ''}`}
        onClick={() => onChange('delivery')} role="tab" aria-selected={mode === 'delivery'} aria-label={t('nav.mode.delivery')}
        aria-disabled={deliveryCutoffPassed || undefined}
        title={delivTitle}>
        <Pict d={ICONS.truck} s={14}/>
        <span className="ws-mode__lbl-full">{t('nav.mode.delivery')}</span>
        {deliveryCutoffPassed && <span className="ws-mode__cutoff"> · {t('nav.mode.closed')}</span>}
      </button>
      </div>
      {/* « i » apricot : pas encore de bureau ? → ouvre le formulaire zone (landing).
          Il est SORTI du rail : depuis que le sélecteur est un segment à deux
          colonnes, un troisième enfant tombait en seconde ligne et débordait
          sous le rail. Frère du rail, il reste à côté sans le déformer. */}
      <span style={{ position: 'relative', display: 'inline-flex', alignItems: 'center', flex: 'none', marginLeft: 4 }}
        onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)}>
        <button type="button" aria-label={t('office.notYet')}
          onClick={(e) => { e.stopPropagation(); e.preventDefault(); window.open('/landing/livraison-bureau.html', '_blank', 'noopener'); }}
          style={{ width: 18, height: 18, borderRadius: '50%', border: 'none', cursor: 'pointer', flex: 'none',
                   background: '#c17a2a', color: '#fff', font: '700 11px/1 system-ui',
                   display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                   animation: 'wsBureauPulse 2.2s infinite' }}>i</button>
        {hover && (
          <span role="tooltip" style={{ position: 'absolute', top: '160%', right: 0, width: 216, maxWidth: '78vw',
            boxSizing: 'border-box', whiteSpace: 'normal', wordBreak: 'break-word',
            background: '#e8a15c', color: '#241a16', borderRadius: 14, padding: '11px 13px',
            font: '600 11.5px/1.45 system-ui', boxShadow: '0 10px 28px rgba(36,26,22,.28)',
            border: '1px solid rgba(36,26,22,.10)', zIndex: 70, textAlign: 'left' }}>
            <span aria-hidden="true" style={{ position: 'absolute', top: -5, right: 15, width: 11, height: 11,
              background: '#e8a15c', borderTop: '1px solid rgba(36,26,22,.10)', borderLeft: '1px solid rgba(36,26,22,.10)',
              transform: 'rotate(45deg)', borderRadius: 3 }} />
            {t('office.notYetTip')}
          </span>
        )}
      </span>
    </div>
  );
}

// Allergen badges — uses AllergensRow from webshop-allergens.jsx (line-art icons).
function Allergens({ list }) {
  if (!list || !list.length) return null;
  if (window.AllergensRow) return <window.AllergensRow list={list} size={14} max={5}/>;
  return null;
}

// =========================================================================
// SPECIAL OFFER LOGIC — buy_x_get_y_free  &  second_at_pct
// =========================================================================
// Unified portion accounting: a quarter is the atomic portion unit.
//   1 quart   = 1 portion
//   1 demi    = 2 portions
//   1 entier  = 4 portions
// All bundle progression, free-item awards, and cross-product offers
// count in portion-units when offer.unit === 'portion'.
// TODO[BACKEND]: portion-unit conversion is a backend rule (varies by product
// type — 6-piece cake vs 4-piece tart). Surface via product schema (e.g.
// `product.portionUnits = { quart: n, demi: n, entier: n }`) returned by
// WSCatalog. The constant below is a global fallback only.
const PORTION_UNITS = { quart: 1, demi: 2, entier: 4 };
function portionUnitsFor(portion) { return PORTION_UNITS[portion] || 0; }

function computeOffer(offer, qty, unit, ctx = {}) {
  if (!offer || !qty || !unit) return null;
  const result = { discount: 0, freebies: 0, threshold: 0, status: 'dormant', cycles: 0, type: offer.type };

  if (offer.type === 'buy_x_get_y_free') {
    const groupSize = (offer.x || 0) + (offer.y || 0);
    if (groupSize <= 0) return null;

    // Resolve "effective" count for the offer:
    //  - unit:'portion' on a portionable line → count in portion-units (1/2/4).
    //    Free items are awarded as quarter-equivalents valued at basePrice*0.27.
    //  - otherwise → count in pieces, free items priced at the line unit total.
    const isPortion = (offer.unit === 'portion') && ctx?.portion && ctx?.basePrice;
    const effectiveQty = isPortion
      ? qty * portionUnitsFor(ctx.portion)
      : qty;
    // La pièce offerte vaut le prix RÉEL de la ligne — le ×0.27 (« un quart »)
    // était un facteur qui n'existe nulle part côté serveur ni en base.
    const freebieValue = unit;

    const cycles = Math.floor(effectiveQty / groupSize);
    const freebies = cycles * (offer.y || 0);
    const discount = freebies * freebieValue;
    result.threshold = groupSize;
    result.cycles = cycles;
    result.freebies = freebies;
    result.discount = discount;
    result.x = offer.x;
    result.y = offer.y;
    result.unit = offer.unit || 'piece';
    result.effectiveQty = effectiveQty;
    if (cycles >= 1) result.status = cycles >= 2 ? 'boosted' : 'active';
    else result.status = 'dormant';
    result.toNext = groupSize - (effectiveQty % groupSize);
    return result;
  }

  if (offer.type === 'second_at_pct') {
    const pct = offer.pct || 0;
    if (qty < 2) {
      result.threshold = 2;
      result.toNext = 2 - qty;
      result.pct = pct;
      result.unit = offer.unit || 'piece';
      return result;
    }
    const pairs = Math.floor(qty / 2);
    const discount = pairs * unit * (pct / 100);
    result.discount = discount;
    result.cycles = pairs;
    result.freebies = 0;
    result.threshold = 2;
    result.pct = pct;
    result.unit = offer.unit || 'piece';
    result.status = pairs >= 2 ? 'boosted' : 'active';
    result.toNext = 2 - (qty % 2);
    return result;
  }
  return null;
}

function OfferStrip({ offer, qty, unit, calc, onAddOne }) {
  if (!offer || !calc) return null;

  const isFree = offer.type === 'buy_x_get_y_free';
  const noun = (calc.unit === 'portion') ? 'portion' : 'pièce';
  const nounPl = noun + 's';

  let title;
  let lede;
  let progressNodes = null;

  if (isFree) {
    title = `${offer.x} achetées · ${offer.y} offerte${offer.y > 1 ? 's' : ''}`;
    if (calc.status === 'dormant') {
      lede = `Plus que ${calc.toNext} ${calc.toNext > 1 ? nounPl : noun} pour profiter de l'offre.`;
    } else if (calc.status === 'active') {
      lede = `${calc.freebies} ${calc.freebies > 1 ? nounPl : noun} offerte${calc.freebies > 1 ? 's' : ''} · économie de €${calc.discount.toFixed(2)}.`;
    } else {
      lede = `${calc.freebies} ${nounPl} offertes · économie de €${calc.discount.toFixed(2)}.`;
    }
    // Progress dots: groupSize segments per cycle, fill = effective count within current cycle
    const groupSize = calc.threshold;
    const effective = calc.effectiveQty ?? qty;
    const inCycle = effective % groupSize === 0 && effective >= groupSize ? groupSize : effective % groupSize;
    progressNodes = (
      <div className="pdm-offer__dots" aria-hidden="true">
        {Array.from({ length: groupSize }).map((_, i) => {
          let cls = 'pdm-offer__dot';
          if (i < offer.x) cls += ' is-buy';
          else cls += ' is-free';
          if (i < inCycle) cls += ' is-on';
          return <span key={i} className={cls}/>;
        })}
        {calc.cycles > 0 && (
          <span className="pdm-offer__cycle">×{calc.cycles}</span>
        )}
      </div>
    );
  } else {
    title = `Le 2e à −${offer.pct}%`;
    if (calc.status === 'dormant') {
      lede = `Ajoutez ${calc.toNext} ${calc.toNext > 1 ? nounPl : noun} pour activer l'offre.`;
    } else {
      lede = `Offre active · économie de €${calc.discount.toFixed(2)}.`;
    }
  }

  const showAdd = calc.status === 'dormant' && typeof onAddOne === 'function';

  return (
    <div className={`pdm-offer pdm-offer--${calc.status}`} role="status" aria-live="polite">
      <div className="pdm-offer__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20 7H4v5h16V7Z"/>
          <path d="M5 12v8h14v-8"/>
          <path d="M12 7v13"/>
          <path d="M12 7c-1.5-2.5-5-2.5-5 0 0 1 .5 2 2 2h3"/>
          <path d="M12 7c1.5-2.5 5-2.5 5 0 0 1-.5 2-2 2h-3"/>
        </svg>
      </div>
      <div className="pdm-offer__body">
        <div className="pdm-offer__row">
          <span className="pdm-offer__title">{title}</span>
          {calc.discount > 0 && (
            <span className="pdm-offer__save">−€{calc.discount.toFixed(2)}</span>
          )}
        </div>
        <div className="pdm-offer__lede">{lede}</div>
        {progressNodes}
      </div>
      {showAdd && (
        <button type="button" className="pdm-offer__add" {...wsTap(onAddOne)}>
          +1
        </button>
      )}
    </div>
  );
}

// Portion glyph shapes (1/8, 1/4, 1/2, entier) — shared by card hint + modal
// options. Glyphes d'affichage uniquement : les PRIX de portion viennent
// exclusivement de l'ERP (shop_product_portion_price via le catalogue).
const PORTION_SHAPES = [
  { v: 'huitieme', d: <path d="M12 12L12 3 A9 9 0 0 1 18.36 5.64 Z" fill="currentColor"/>, name: '1/8' },
  { v: 'quart',  d: <path d="M12 12L12 3 A9 9 0 0 1 21 12 Z" fill="currentColor"/>,        name: '1/4' },
  { v: 'demi',   d: <path d="M12 3 A9 9 0 0 1 12 21 Z" fill="currentColor"/>,              name: '1/2' },
  { v: 'entier', d: <circle cx="12" cy="12" r="9" fill="currentColor"/>,                   name: 'Entière' },
];

// Options de portion d'un produit : UNIQUEMENT les prix explicites de l'ERP
// (product.portionOptions = [{v,label,price}] servis par le catalogue —
// shop_product_portion_price). Go-live « vraies données ou bug » : le repli
// « prix de base × facteur (0.27/0.52/0.15) » est SUPPRIMÉ — il affichait et
// facturait des prix de portion que la boutique n'a jamais fixés. Sans prix
// ERP de portion, seule la pièce ENTIÈRE (prix réel) est proposée.
function portionOptionList(p) {
  if (Array.isArray(p?.portionOptions) && p.portionOptions.length) {
    return p.portionOptions.map((o) => {
      const sh = PORTION_SHAPES.find((s) => s.v === o.v) || PORTION_SHAPES[PORTION_SHAPES.length - 1];
      return { v: o.v, d: sh.d, name: o.label || sh.name, price: Number(o.price) || 0 };
    });
  }
  const entier = PORTION_SHAPES.find((s) => s.v === 'entier');
  return [{ v: 'entier', d: entier.d, name: entier.name, price: p?.price || 0 }];
}

// Libellé des portions d'une carte produit — types proposés avec le PRIX de
// chaque portion : ex. « Entière €24.00 · 1/2 €14.90 · 1/4 €8.90 ».
function portionPriceHint(p) {
  return portionOptionList(p).map((o) => `${o.name} €${o.price.toFixed(2)}`).join(' · ');
}

// Single "portions available" glyph used on the product card — a quartered
// disc that hints at the slicing without committing to a specific portion.
function PortionGlyph({ size = 14 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeWidth="1.4"/>
      <line x1="12" y1="3"  x2="12" y2="21" stroke="currentColor" strokeWidth="1.4"/>
      <line x1="3"  y1="12" x2="21" y2="12" stroke="currentColor" strokeWidth="1.4"/>
    </svg>
  );
}

// Portion option list inside the product modal — same toggle/button UX as
// other option groups (pdm-optrow + pdm-seg). Each button shows icon +
// portion name + computed price.
function PortionOptions({ value, onChange, product }) {
  const { t } = wsUseT();
  // Options du produit : prix EXPLICITES ERP quand fournis, sinon facteurs.
  const shapes = portionOptionList(product);
  return (
    <div className="pdm-optrow">
      <div className="pdm-optrow__head">
        <span className="pdm-opt__label">{t('pd.portion')}</span>
        <span className="pdm-opt__req">{t('pd.required')}</span>
      </div>
      <div className="pdm-seg pdm-seg--portions" role="radiogroup" aria-label={t('pd.portion')} style={{ '--pdm-seg-n': shapes.length }}>
        {shapes.map((o) => {
          const on = value === o.v;
          const price = o.price;
          return (
            <button key={o.v}
              type="button"
              role="radio"
              aria-checked={on}
              className={'pdm-seg__btn pdm-seg__btn--portion' + (on ? ' is-on' : '')}
              {...wsTap(() => onChange(o.v))}>
              <span className="pdm-seg__pico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="14" height="14">{o.d}</svg>
              </span>
              <span className="pdm-seg__lbl">{o.name}</span>
              <span className="pdm-seg__delta">€{price.toFixed(2)}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

// =========================================================================
// PRODUCT DETAIL MODAL — options, upsells, bundles
// =========================================================================
function ProductDetail({ open, product, mode, onClose, onAdd, stock }) {
  const { t, lang } = wsUseT();
  // ── Hooks (must run unconditionally; never gate behind early-return) ──
  const initSelections = React.useMemo(() => {
    const out = {};
    if (product?.options) {
      for (const o of product.options) if (o.required) out[o.id] = o.choices[0]?.id;
    }
    return out;
  }, [product]);
  const [sel, setSel]                 = React.useState(initSelections);
  const [bundleId, setBundleId]       = React.useState(null);
  const [bundleSlots, setBundleSlots] = React.useState({});
  const [upsellIds, setUpsellIds]     = React.useState({});
  const [qty, setQty]                 = React.useState(1);
  const [portion, setPortion]         = React.useState('entier');
  const [openOpts, setOpenOpts]       = React.useState({}); // accordion state per option id
  const [carIdx, setCarIdx]           = React.useState(0);
  const [pulse, setPulse]             = React.useState(0);  // re-trigger price pop animation
  const [activeOpt, setActiveOpt]     = React.useState(null); // currently focused option id
  const carRef = React.useRef(null);
  const optRefs = React.useRef({}); // option-id -> DOM node

  // Compute the bundle list ONCE per product (pre-prepended "À la carte").
  const bundleList = React.useMemo(() => {
    if (!product?.has_menu_options || !product.available_bundles) return [];
    return [
      { id: null, name: t('pd.alaCarte'), description: t('pd.alaCarteDesc'), price_modifier: 0, slots: [], advantages: [], included: [] },
      ...product.available_bundles,
    ];
    // `lang` en dépendance : « À la carte » et sa description viennent de la
    // table de traduction. Sans elle, changer de langue modale ouverte laissait
    // la première formule dans la langue précédente.
  }, [product, lang]);

  // Reset state when the product changes — and auto-open required option groups + auto-pick recommended bundle.
  React.useEffect(() => {
    setSel(initSelections);
    setUpsellIds({});
    setQty(1);
    // Portion par défaut = la PREMIÈRE option proposée (l'entière quand les
    // options ERP sont fournies, sinon le comportement historique).
    setPortion((portionOptionList(product).find((o) => o.v === 'entier') || portionOptionList(product)[0])?.v || 'entier');
    setBundleSlots({});
    setCarIdx(0);
    if (product?.options) {
      const initOpen = {};
      for (const o of product.options) initOpen[o.id] = !!o.required;
      setOpenOpts(initOpen);
      // Initial active option = first one (helps highlight on open)
      const firstUnanswered = product.options.find((o) => o.required && !initSelections[o.id]) || product.options[0];
      setActiveOpt(firstUnanswered ? firstUnanswered.id : null);
    } else {
      setOpenOpts({});
      setActiveOpt(null);
    }
    // Formule présélectionnée : la recommandée, SINON l'unique formule s'il
    // n'y en a qu'une — ses étapes s'ouvrent d'emblée. Sur mobile, le tap
    // « pour rien » qui ne faisait qu'ouvrir la carte passait pour un raté
    // (constaté : « je dois cliquer plusieurs fois pour que ça s'ouvre »).
    const rec = product?.available_bundles?.find((b) => b.recommended)
      || (product?.available_bundles?.length === 1 ? product.available_bundles[0] : null);
    setBundleId(rec ? rec.id : null);
    if (rec) {
      const recIdx = bundleList.findIndex((b) => b.id === rec.id);
      if (recIdx >= 0) setCarIdx(recIdx);
    }
  }, [product, initSelections, bundleList]);

  // Lock body scroll while open.
  React.useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const esc = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', esc);
    return () => { document.body.style.overflow = prev; document.removeEventListener('keydown', esc); };
  }, [open, onClose]);

  // ── Derived (after hooks) ─────────────────────────────────────────────
  const accentVar = mode === 'delivery' ? '#c17a2a' : 'var(--color-primary)';
  // Bloqué en livraison bureau (« apricot ») si le produit n'est pas livré par la
  // boutique (no_delivery) OU s'il est désactivé pour ce canal côté marque
  // (office_delivery === false). Absent/undefined = disponible (rétro-compat).
  const deliveryBlocked = mode === 'delivery' && (!!product?.no_delivery || product?.office_delivery === false);
  // qty_available from ws_product_stock API; falls back to delivery_stock on product seed
  // Le stock du jour vaut pour LES DEUX modes : le serveur refuse une commande
  // au-delà du disponible en collecte comme en livraison. Le limiter ici au seul
  // mode livraison laissait le client remplir son panier avec un produit épuisé
  // et ne l'apprendre qu'à l'étape paiement, après avoir tout saisi.
  // Le repli `delivery_stock` reste propre à la livraison : en collecte, une
  // absence de ligne de stock veut dire « pas de plafond », pas « zéro ».
  const qtyAvailable = stock ? stock.qty_available
    : (mode === 'delivery' && typeof product?.delivery_stock === 'number' ? product.delivery_stock : null);
  const deliveryStockLeft = qtyAvailable !== null ? Math.max(0, qtyAvailable) : null;

  let unit = product?.price || 0;
  // Portion RÉSOLUE : la portion choisie si elle existe dans la liste, sinon
  // on retombe sur l'Entière (jamais sur une portion fantôme). Prix ET libellé
  // dérivent de CETTE option -> impossible d'afficher « 1/4 » à prix d'entière.
  const _plist = product?.portions ? portionOptionList(product) : [];
  const portOpt = product?.portions
    ? (_plist.find((o) => o.v === portion) || _plist.find((o) => o.v === 'entier') || _plist[0] || null)
    : null;
  if (portOpt) unit = portOpt.price;
  if (product?.options) {
    for (const o of product.options) {
      const choiceId = sel[o.id];
      if (!choiceId) continue;
      const choice = o.choices.find((c) => c.id === choiceId);
      if (choice?.delta) unit += choice.delta;
    }
  }
  const activeBundle = bundleId && product?.available_bundles
    ? product.available_bundles.find((b) => b.id === bundleId)
    : null;
  let bundleDelta = 0;
  if (activeBundle) {
    bundleDelta += activeBundle.price_modifier || 0;
    for (const slot of (activeBundle.slots || [])) {
      for (const cid of slotPicked(slot)) {
        const c = slot.choices.find((x) => x.id === cid);
        if (c?.delta) bundleDelta += c.delta;
      }
    }
  }
  const upsellDelta = Object.entries(upsellIds).reduce((t, [id, on]) => {
    if (!on || !product?.upsells) return t;
    const u = product.upsells.find((x) => x.id === id);
    return t + (u?.delta || 0);
  }, 0);
  const unitTotal = unit + bundleDelta + upsellDelta;
  const grossTotal = unitTotal * qty;
  const offerCalc = product?.offer ? computeOffer(product.offer, qty, unitTotal, {
    portion: product.portions ? portion : null,
    basePrice: product.price,
  }) : null;
  const offerDiscount = offerCalc?.discount || 0;
  const total = Math.max(0, grossTotal - offerDiscount);

  // Validity
  let valid = true;
  if (product?.options) {
    for (const o of product.options) if (o.required && !sel[o.id]) { valid = false; break; }
  }
  if (activeBundle) {
    for (const slot of (activeBundle.slots || [])) {
      if (slot.required && slotPicked(slot).length < Math.max(1, slot.min_select || 1)) { valid = false; break; }
    }
  }

  // Re-pulse price on any change
  React.useEffect(() => { setPulse((p) => p + 1); }, [unit, bundleDelta, upsellDelta, qty, bundleId]);

  // Sync carIdx to scroll position (snap-based feel)
  function onCarScroll(e) {
    const el = e.currentTarget;
    if (!el || !el.firstElementChild) return;
    const cardW = el.firstElementChild.getBoundingClientRect().width + 12; // card + gap
    const idx = Math.round(el.scrollLeft / cardW);
    if (idx !== carIdx && idx >= 0 && idx < bundleList.length) setCarIdx(idx);
  }

  function setOpt(oid, cid)        {
    setSel((s) => ({ ...s, [oid]: cid }));
    // Glide the just-selected option group to the top of the modal scroll area
    // so it stays visible as a "settled" choice while the customer can keep
    // scrolling freely to make further choices.
    setActiveOpt(oid);
    setTimeout(() => {
      const sc = scrollRef.current;
      glideIfHidden(optRefs.current[oid], sc, (elRect, scRect) => sc.scrollTop + (elRect.top - scRect.top) - 8);
    }, IS_TOUCH ? 30 : 180);
  }
  function setSlot(slotId, choiceId) { setBundleSlots((s) => ({ ...s, [slotId]: choiceId })); }
  // Rubrique multi (« 2 choix ») : le tap AJOUTE ou RETIRE, il ne remplace
  // pas — c'était le blocage constaté : le 2e choix écrasait le 1er et la
  // formule ne se complétait jamais. Plafond = max_select.
  function toggleSlotMulti(slotId, choiceId, maxSel) {
    setBundleSlots((s) => {
      const cur = Array.isArray(s[slotId]) ? s[slotId] : (s[slotId] ? [s[slotId]] : []);
      if (cur.includes(choiceId)) return { ...s, [slotId]: cur.filter((x) => x !== choiceId) };
      if (cur.length >= Math.max(1, maxSel || 1)) return s;
      return { ...s, [slotId]: [...cur, choiceId] };
    });
  }
  function slotIsMulti(slot) { return (slot.max_select || 1) > 1 || slot.kind === 'multi'; }
  function slotPicked(slot) {
    const v = bundleSlots[slot.id];
    return Array.isArray(v) ? v : (v ? [v] : []);
  }
  // Ne scrolle que si l'élément déborde de la zone visible. Le glissement
  // systématique déplaçait le contenu SOUS LE DOIGT juste après un tap : le
  // tap suivant tombait à côté — la cause des « miss-clicks » constatés sur
  // téléphone. Une cible déjà visible reste où elle est.
  function glideIfHidden(el, sc, computeTarget) {
    if (!el || !sc) return;
    const elRect = el.getBoundingClientRect();
    const scRect = sc.getBoundingClientRect();
    const fullyVisible = elRect.top >= scRect.top && elRect.bottom <= scRect.bottom;
    if (fullyVisible) return;
    sc.scrollTo({ top: Math.max(0, computeTarget(elRect, scRect)), behavior: IS_TOUCH ? 'auto' : 'smooth' });
  }
  function pickBundle(bid) {
    if (bid === bundleId) return;
    setBundleId(bid);
    setBundleSlots({});
    // Glide the picked bundle card so its expanded body sits centered in the
    // modal scroll area, after the slot pickers animate open.
    const idx = bundleList.findIndex((b) => b.id === bid);
    setCarIdx(idx >= 0 ? idx : 0);
    setTimeout(() => {
      const el = carRef.current; if (!el) return;
      if (idx < 0) return;
      const card = el.children[idx]; if (!card) return;
      const sc = scrollRef.current; if (!sc) return;
      glideIfHidden(card, sc, (cardRect, scRect) =>
        sc.scrollTop + (cardRect.top - scRect.top) + (card.offsetHeight / 2) - (sc.clientHeight / 2));
    }, IS_TOUCH ? 30 : 340);
  }
  function toggleUpsell(id)        { setUpsellIds((s) => ({ ...s, [id]: !s[id] })); }
  // Un accordéon qui vient de s'ouvrir (animation ~300 ms) ne doit pas se
  // REFERMER sur un re-tap réflexe « ça n'a pas marché » : anti-rebond.
  const lastToggle = React.useRef({});
  function toggleOpt(oid)          {
    const now = performance.now();
    if (lastToggle.current[oid] && now - lastToggle.current[oid] < 350) return;
    lastToggle.current[oid] = now;
    setOpenOpts((s) => {
      const next = { ...s, [oid]: !s[oid] };
      // After the accordion finishes opening, glide its body to vertical center
      // of the modal scroll area so the just-revealed options feel balanced.
      if (next[oid]) {
        setTimeout(() => {
          const sc = scrollRef.current;
          const el = optRefs.current[oid];
          glideIfHidden(el, sc, (elRect, scRect) =>
            sc.scrollTop + (elRect.top - scRect.top) + (el.offsetHeight / 2) - (sc.clientHeight / 2));
        }, IS_TOUCH ? 30 : 320);
      }
      return next;
    });
  }

  function handleConfirm() {
    if (!valid) return;
    const optionLabels = (product.options || [])
      .map((o) => { const c = o.choices.find((x) => x.id === sel[o.id]); return c ? c.label : null; })
      .filter(Boolean);
    if (activeBundle) {
      optionLabels.push(t('pd.bundle') + ' · ' + activeBundle.name);
      for (const slot of (activeBundle.slots || [])) {
        for (const cid of slotPicked(slot)) {
          const c = slot.choices.find((x) => x.id === cid);
          if (c) optionLabels.push(slot.label + ' · ' + c.label);
        }
      }
    }
    Object.entries(upsellIds).forEach(([id, on]) => {
      if (!on) return;
      const u = product.upsells.find((x) => x.id === id);
      if (u) optionLabels.push('+ ' + u.label);
    });
    onAdd({
      productId: product.id,
      // Libellé dérivé de la portion RÉSOLUE (portOpt) : cohérent avec le prix.
      // Pas de suffixe pour l'Entière.
      name: product.name + (portOpt && portOpt.v !== 'entier' ? ' — ' + portOpt.name : ''),
      qty,
      price: qty > 0 ? total / qty : (unit + bundleDelta + upsellDelta),
      options: optionLabels.map((label) => ({ label })),
      /* Composition du menu par IDENTIFIANTS, en plus des libelles d'affichage.
         Elle restait dans le composeur : le panier ne portait que des libelles,
         et la commande ne pouvait donc ecrire aucune ligne pour les choix du
         menu — la boutique voyait « Menu » sans savoir quoi preparer.
         C'est le serveur qui resout ces identifiants en produits ; le navigateur
         ne decide pas de ce qui est vendu. */
      bundleId: activeBundle ? activeBundle.id : null,
      bundleSlots: { ...bundleSlots },
      portion: portOpt ? portOpt.v : null,
      cat: product.cat,
      crossPortion: !!product.crossPortion,
      basePrice: product.price,
      offerDiscount: offerDiscount || 0,
      offerLabel: offerCalc && offerCalc.discount > 0
        ? (offerCalc.type === 'buy_x_get_y_free'
            ? `${product.offer.x}+${product.offer.y}`
            : `2e −${product.offer.pct}%`)
        : null,
    });
    onClose();
  }

  // ── Hooks must run unconditionally (called BEFORE any early return) ─
  const scrollRef = React.useRef(null);
  const pdmPanelRef = useSwipeDownToClose(onClose, scrollRef);
  const [swipeHint, setSwipeHint] = React.useState(false);
  // Show the in-place swipe hint when content overflows; hide once user
  // has interacted (scrolled) or reached the bottom.
  React.useEffect(() => {
    if (!open) return;
    const el = scrollRef.current;
    if (!el) return;
    let acted = false;
    const measure = () => {
      if (acted) return;
      const overflow = el.scrollHeight - el.clientHeight - el.scrollTop;
      setSwipeHint(overflow > 24);
    };
    const onScroll = () => {
      if (el.scrollTop > 6) { acted = true; setSwipeHint(false); el.removeEventListener('scroll', onScroll); }
      else measure();
    };
    measure();
    const t = setTimeout(measure, 60);
    el.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', measure);
    return () => { clearTimeout(t); el.removeEventListener('scroll', onScroll); window.removeEventListener('resize', measure); };
  }, [open, product, bundleId]);

  if (!open || !product) return null;

  // ── Render ────────────────────────────────────────────────────────────
  return (
    <div className="pdm-scrim" role="dialog" aria-modal="true" onClick={onClose} style={{ '--accent': accentVar }}>
      <div ref={pdmPanelRef} className="pdm" onClick={(e) => e.stopPropagation()}>
        <span className="ws-modal__handle pdm-handle" aria-hidden="true"/>
        <button className="pdm-close" aria-label={t('common.close2')} {...wsTap(onClose, { shield: true })}><Pict d={ICONS.close} s={13}/></button>

        {/* HERO */}
        <div className="pdm-hero">
          {product.badge && <span className="pdm-hero__badge">{product.badge}</span>}
          <img
            className={product.img ? 'pdm-hero__img' : 'pdm-hero__img pdm-hero__img--lineart'}
            src={product.img || getPlaceholder(product)}
            alt={product.name}
          />
        </div>

        {/* INFO */}
        <div className="pdm-info">
          <div className="pdm-scroll" ref={scrollRef}>
            <div className="pdm-head">
              <p className="pdm-eyebrow">{product.cat === 'sandwiches' ? t('pd.eyebrow.sandwich') : product.cat === 'plats' ? t('pd.eyebrow.dish') : t('pd.eyebrow.selection')}</p>
              <h2 className="pdm-title">{product.name}</h2>
              {product.description ? <p className="pdm-desc">{product.description}</p> : null}
              {/* Allergènes — 3 états distincts (sécurité alimentaire) :
                  liste = connus · [] = recette évaluée, aucun · null = NON
                  RENSEIGNÉ (on le dit, on ne laisse jamais croire « aucun »). */}
              {Array.isArray(product.allergens) && product.allergens.length > 0 && (
                <div className="pdm-allergens"><Allergens list={product.allergens}/></div>
              )}
              {Array.isArray(product.allergens) && product.allergens.length === 0 && (
                <p className="pdm-allergens pdm-allergens--none">{t('pd.noAllergen')}</p>
              )}
              {!Array.isArray(product.allergens) && (
                <p className="pdm-allergens pdm-allergens--unknown">{t('pd.allergenUnknown')}</p>
              )}
              {product.portions && (
                <PortionOptions
                  value={portion}
                  onChange={setPortion}
                  product={product}
                />
              )}
            </div>

            {/* SPECIAL OFFER STRIP */}
            {offerCalc && (
              <OfferStrip
                offer={product.offer}
                qty={qty}
                unit={unitTotal}
                calc={offerCalc}
                onAddOne={() => setQty((q) => q + 1)}
              />
            )}

            {/* OPTION ACCORDIONS */}
            {(product.options?.length > 0 || product.upsells?.length > 0) && (
              <div className="pdm-opts">
                {product.options?.map((o) => {
                  return (
                    <div key={o.id}
                         ref={(el) => { if (el) optRefs.current[o.id] = el; }}
                         className={'pdm-optrow' + (activeOpt === o.id ? ' is-active' : '')}>
                      <div className="pdm-optrow__head">
                        <span className="pdm-opt__label">{o.label}</span>
                        {o.required
                          ? <span className="pdm-opt__req">{t('pd.required')}</span>
                          : <span className="pdm-opt__req pdm-opt__req--soft">{t('pd.optional')}</span>}
                      </div>
                      <div className="pdm-seg" role="radiogroup" aria-label={o.label} style={{ '--pdm-seg-n': o.choices.length }}>
                        {o.choices.map((c) => {
                          const on = sel[o.id] === c.id;
                          return (
                            <button key={c.id}
                              type="button"
                              role="radio"
                              aria-checked={on}
                              className={'pdm-seg__btn' + (on ? ' is-on' : '')}
                              {...wsTap(() => setOpt(o.id, c.id))}>
                              <span className="pdm-seg__lbl">{c.label}</span>
                              {c.delta > 0 && <span className="pdm-seg__delta">+{c.delta.toFixed(2)} €</span>}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}

                {product.upsells?.length > 0 && (() => {
                  const id = '__upsells';
                  const isOpen = openOpts[id] !== false; // default open
                  const count = Object.values(upsellIds).filter(Boolean).length;
                  return (
                    <div key={id}
                         ref={(el) => { if (el) optRefs.current[id] = el; }}
                         className={'pdm-opt' + (isOpen ? ' is-open' : '') + (activeOpt === id ? ' is-active' : '')}>
                      <button className="pdm-opt__head" {...wsTap(() => toggleOpt(id), { open: true })} aria-expanded={isOpen}>
                        <span className="pdm-opt__head-l">
                          <span className="pdm-opt__label">{t('xsell.title')}</span>
                          {!isOpen && count > 0 && <span className="pdm-opt__sub">{count} ajout{count>1?'s':''}</span>}
                        </span>
                        <span className="pdm-opt__head-r">
                          <span className="pdm-opt__req pdm-opt__req--soft">{t('pd.optional')}</span>
                          <span className="pdm-opt__chev"><Pict d={ICONS.chev} s={12}/></span>
                        </span>
                      </button>
                      <div className="pdm-opt__body-wrap">
                        <div className="pdm-opt__body">
                          <div className="pdm-chips">
                            {product.upsells.map((u) => {
                              const on = !!upsellIds[u.id];
                              return (
                                <button key={u.id}
                                  className={'pdm-imgchip' + (on ? ' is-on' : '')}
                                  {...wsTap(() => toggleUpsell(u.id))}>
                                  {u.img && <span className="pdm-imgchip__tile"><img src={u.img} alt=""/></span>}
                                  <span>{u.label}</span>
                                  <span className="pdm-imgchip__delta">+{u.delta.toFixed(2)}</span>
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })()}
              </div>
            )}

            {/* BUNDLE CAROUSEL */}
            {bundleList.length > 0 && (
              <div className="pdm-bundles">
                <div className="pdm-section-head">
                  <span className="pdm-section-title">{t('pd.bundle')}</span>
                  {bundleList.length > 1 && (
                    <button type="button" className="pdm-section-arrow" aria-label={t('co.next')}
                      {...wsTap(() => {
                        const cur = bundleList.findIndex((b) => b.id === bundleId);
                        const next = cur + 1 >= bundleList.length ? 0 : cur + 1;
                        const nextBundle = bundleList[next];
                        if (nextBundle) pickBundle(nextBundle.id);
                      }, { open: true })}>
                      <Pict d={ICONS.chev} s={10}/>
                    </button>
                  )}
                </div>
                <div className="pdm-car" ref={carRef} onScroll={onCarScroll}>
                  {bundleList.map((b, i) => {
                    const picked = (b.id === bundleId);
                    return (
                      <div key={b.id || 'alc'}
                           className={'pdm-bcard' + (picked ? ' is-picked' : '')}
                           role="button"
                           {...wsTap(() => pickBundle(b.id), { open: true })}>
                        {b.recommended && <span className="pdm-bcard__badge">{t('pd.bestOption')}</span>}
                        <div className="pdm-bcard__top">
                          <span className="pdm-bcard__name">{b.name}</span>
                          <span className={'pdm-bcard__price' + (b.price_modifier > 0 ? '' : ' pdm-bcard__price--free')}>
                            {b.price_modifier > 0 ? '+' + b.price_modifier.toFixed(2) + ' €' : t('pd.included')}
                          </span>
                        </div>
                        <p className="pdm-bcard__desc">{b.description}</p>
                        {b.included?.length > 0 && (
                          <ul className="pdm-bcard__inc">
                            {b.included.map((it, k) => (
                              <li key={k}><Pict d={ICONS.check} s={10}/> <span>{it.label}</span></li>
                            ))}
                          </ul>
                        )}
                        {/* progressive disclosure: bundle slots open softly when picked */}
                        <div className={'pdm-bcard__expand' + (picked && b.id !== null && b.slots?.length > 0 ? ' is-open' : '')}>
                          <div className="pdm-bcard__expand-inner" onClick={(e) => e.stopPropagation()} onPointerUp={(e) => e.stopPropagation()} onPointerDown={(e) => e.stopPropagation()} onTouchStart={(e) => e.stopPropagation()} onTouchEnd={(e) => e.stopPropagation()}>
                            {b.slots?.map((slot) => (
                              <div key={slot.id} className="pdm-opt is-open" style={{ background: 'transparent', boxShadow: 'none' }}>
                                <div style={{ padding: '4px 0 0' }}>
                                  <div className="pdm-opt__head-l" style={{ marginBottom: 6 }}>
                                    <span className="pdm-opt__label" style={{ fontSize: 12.5 }}>{slot.label}
                                      {slotIsMulti(slot)
                                        ? <span className={'pdm-opt__req' + (slot.required ? '' : ' pdm-opt__req--soft')} style={{ marginLeft: 8 }}>
                                            {slotPicked(slot).length}/{Math.max(1, slot.max_select || 1)}
                                            {slot.required ? ' — choisissez ' + Math.max(1, slot.min_select || 1) : ''}
                                          </span>
                                        : slot.required && <span className="pdm-opt__req" style={{ marginLeft: 8 }}>{t('pd.required')}</span>}
                                    </span>
                                  </div>
                                  <div className="pdm-chips">
                                    {slot.choices.map((c) => {
                                      const multi = slotIsMulti(slot);
                                      const cOn = multi ? slotPicked(slot).includes(c.id) : bundleSlots[slot.id] === c.id;
                                      const klass = c.img ? 'pdm-imgchip' : 'pdm-chip';
                                      return (
                                        <button key={c.id}
                                          className={klass + (cOn ? ' is-on' : '')}
                                          {...wsTap(() => multi ? toggleSlotMulti(slot.id, c.id, slot.max_select) : setSlot(slot.id, c.id))}>
                                          {c.img && <span className="pdm-imgchip__tile"><img src={c.img} alt=""/></span>}
                                          <span>{c.label}</span>
                                          {c.delta > 0 && <span className={c.img ? 'pdm-imgchip__delta' : 'pdm-chip__delta'}>+{c.delta.toFixed(2)}</span>}
                                        </button>
                                      );
                                    })}
                                  </div>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                        <span className="pdm-bcard__radio" aria-hidden="true"/>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>

          {/* In-place swipe hint */}
          <div className={'pdm-swipe' + (swipeHint ? ' is-visible' : '')} aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 5v14"/>
              <path d="M6 13l6 6 6-6"/>
            </svg>
          </div>

          {/* STICKY FOOTER */}
          <div className="pdm-foot">
            {deliveryBlocked && (
              <div className="pdm-delivery-notice pdm-delivery-notice--blocked">
                Ce produit n'est pas disponible en livraison · Retrait en boutique uniquement
              </div>
            )}
            {/* Le NOMBRE d'unités restantes n'est plus affiché (retiré le 23/08,
                comme le compteur des vignettes avant lui) : c'est une donnée
                interne de stock, pas une information client. Seul l'état
                bloquant reste visible — « Épuisé » — et la quantité demeure
                plafonnée en silence par le sélecteur ci-dessous. */}
            {!deliveryBlocked && deliveryStockLeft === 0 && (
              <div className="pdm-delivery-notice">{t('pd.outOfStock')}</div>
            )}
            <div className="pdm-qty">
              <button className="pdm-qty__btn" {...wsTap(() => setQty((q) => Math.max(1, q - 1)))} disabled={qty <= 1} aria-label={t('qty.dec')}>−</button>
              <span className="pdm-qty__val">{qty}</span>
              <button className="pdm-qty__btn" {...wsTap(() => setQty((q) => Math.min(q + 1, deliveryStockLeft ?? 99)))} aria-label={t('qty.inc')} disabled={deliveryStockLeft !== null && qty >= deliveryStockLeft}>+</button>
            </div>
            <button className="pdm-cta" disabled={!valid || deliveryBlocked || (deliveryStockLeft !== null && deliveryStockLeft === 0)} {...wsTap(handleConfirm, { shield: true })}>
              <span>{deliveryBlocked ? t('pd.notForDelivery') : (deliveryStockLeft === 0 ? t('pd.outOfStock') : (valid ? t('pd.addToCart') : t('pd.chooseOptions')))}</span>
              <span className="pdm-cta__total" key={pulse}>
                {offerDiscount > 0 && (
                  <span className="pdm-cta__strike">€{grossTotal.toFixed(2)}</span>
                )}
                <span className="pdm-cta__total-anim">€{total.toFixed(2)}</span>
              </span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// PRODUCT CARD
// =========================================================================
const ProductCard = React.memo(function ProductCard({ p, onAdd, onOpen, mode, basketQty, stock, platsBadge }) {
  const { t } = wsUseT();
  const price = p.price;
  const hasOptions = !!(p.options || p.bundle || p.upsells);
  const isDelivery = mode === 'delivery';
  // Canal livraison bureau (« apricot ») : bloqué si non livré par la boutique
  // (no_delivery) ou désactivé marque pour ce canal (office_delivery === false).
  const deliveryBlocked = isDelivery && (!!p.no_delivery || p.office_delivery === false);
  // qty_available from ws_product_stock API; falls back to delivery_stock on product seed
  // Même règle que dans le détail : le stock s'applique aux deux modes, le
  // repli `delivery_stock` seulement à la livraison.
  const qtyAvailable = stock ? stock.qty_available
    : (isDelivery && typeof p.delivery_stock === 'number' ? p.delivery_stock : null);
  const deliveryStockLeft = qtyAvailable !== null
    ? Math.max(0, qtyAvailable - (basketQty || 0))
    : null;
  const stockExhausted = deliveryStockLeft !== null && deliveryStockLeft === 0;
  const addDisabled = deliveryBlocked || stockExhausted;

  function handleCardClick() { if (!deliveryBlocked) onOpen(p); }
  return (
    <article className={`ws-card${addDisabled ? ' ws-card--unavail' : ''}`} {...wsTap(handleCardClick, { open: true })} role="button" tabIndex={0}>
      <div className="ws-card__photo">
        {deliveryBlocked
          ? <span className="ws-card__badge ws-card__badge--nodeliv">{t('pd.pickupOnly')}</span>
          : platsBadge
            ? <span className="ws-badge--plats ws-card__badge">{t('pd.readyMeals')}</span>
            : p.badge && <span className="ws-card__badge">{p.badge}</span>}
        <img
          className={p.img ? 'ws-card__photo-img' : 'ws-card__photo-img ws-card__photo-img--lineart'}
          src={p.img || getPlaceholder(p)}
          alt=""
          // Filet de sécurité : si la photo casse quand même (fichier retiré du
          // serveur entre deux déploiements, URL externe morte), on bascule sur
          // l'illustration de repli au lieu de laisser une image cassée. Le
          // drapeau coupe la boucle si le repli échoue à son tour.
          onError={(e) => {
            const el = e.currentTarget;
            if (el.dataset.imgFallback === '1') return;
            el.dataset.imgFallback = '1';
            el.classList.add('ws-card__photo-img--lineart');
            el.src = getPlaceholder(p);
          }}
        />
      </div>
      {/* Meta strip BELOW the (1:1) photo — allergens, info, add */}
      <div className="ws-card__metaStrip">
        <Allergens list={p.allergens}/>
        <div className="ws-card__icons">
          <button className="ws-iconcircle" aria-label={t('pd.info')} {...wsTap(() => onOpen(p), { stop: true, open: true })}><Pict d={ICONS.info} s={11}/></button>
          <button className="ws-add" {...wsTap(() => { if (!addDisabled) onOpen(p); }, { stop: true, open: true })} aria-label={t('pd.addToCart')}
            disabled={addDisabled} title={deliveryBlocked ? 'Non disponible en livraison' : stockExhausted ? 'Stock épuisé pour la livraison' : undefined}>
            {stockExhausted ? '✕' : '+'}
          </button>
        </div>
      </div>
      <div className="ws-card__body">
        {p.portions && (
          <div className="ws-card__portions" aria-label={t('pd.portionsAvailable')}
               title={'Portions : ' + portionOptionList(p).map((o) => o.name).join(' · ')}>
            <PortionGlyph size={12}/>
            <span>{t('pd.portionsAvailable')}</span>
          </div>
        )}
        {p.lead_time > 0 && (
          <div className="ws-card__leadtime" title={`Commander ${p.lead_time} jour${p.lead_time > 1 ? 's' : ''} avant`}>
            {`J+${p.lead_time}`}
          </div>
        )}
        <div className="ws-card__name">{p.name}</div>
        <div className="ws-card__meta">
          <span className="ws-card__price">€{price.toFixed(2)}{hasOptions && <span className="ws-card__from"> · {t('card.fromPrice')}</span>}</span>
          {/* Compteur « X dispo » retiré des vignettes (demandé le 15/08) — il
              exposait le stock restant sur la grille. « Épuisé » reste : c'est
              une contrainte que le client doit voir avant d'ajouter. */}
          {stockExhausted && <span className="ws-card__stock ws-card__stock--out">{t('pd.soldOutDelivery')}</span>}
        </div>
      </div>
    </article>
  );
});

// =========================================================================
// BASKET PANEL (right side)
// =========================================================================
// =========================================================================
// CROSS-CATEGORY PORTION OFFER — basket-level
// =========================================================================
// Scans the basket for any line marked `crossPortion: true` and totals its
// portion-units (quart=1, demi=2, entier=4). Every X portion-units earns Y
// free quarter-equivalents, valued at the cheapest line's basePrice × 0.27.
// Fallback rule used only when WSPricing.getCrossPortionRule() is unavailable.
// x paid + y free per group; threshold = portions needed before first freebie.
// Go-live : plus de regle 4+1 de secours cote client (promotion hors course).

function computeCrossPortionOffer(basket, rule) {
  const r = rule;
  if (!r) return null; // pas de regle serveur -> pas d'offre affichee
  if (!Array.isArray(basket) || basket.length === 0) return null;
  /* MIROIR EXACT du calcul de POST /orders. C'est le serveur qui facture :
     tout ce qui s'en écarte promet au client une économie qu'il ne recevra pas.
     Ses trois règles, telles qu'elles sont écrites en PHP :
       • UNE entrée par PIÈCE (qty), pas par unité de portion — un demi compte
         pour un, comme un quart ;
       • valorisée au PRIX RÉEL de la ligne (prix ERP de la portion), et non à
         27 % du prix du produit entier — ce pourcentage n'existe nulle part
         dans la base ;
       • le nombre d'offerts est floor(nb / x) × y, sous réserve d'atteindre le
         seuil (threshold) ; ce sont les MOINS CHERS qui sont offerts. */
  const items = [];
  for (const l of basket) {
    if (!l.crossPortion) continue;
    for (let i = 0; i < (l.qty || 0); i++) items.push({ price: l.price || 0, name: l.name });
  }
  const eligibleCount = items.length;
  if (eligibleCount === 0) return null;
  if (r.threshold != null && eligibleCount < r.threshold) return null;
  const groupSize = r.x;
  items.sort((a, b) => a.price - b.price);
  const cycles = Math.floor(eligibleCount / groupSize);
  const freeCount = Math.min(cycles * r.y, eligibleCount);
  let savings = 0;
  const freeNames = [];
  for (let i = 0; i < freeCount; i++) {
    savings += items[i].price;
    freeNames.push(items[i].name);
  }
  const remainder = eligibleCount % groupSize;
  const toNext = groupSize - remainder;
  return {
    eligibleCount,
    groupSize,
    cycles,
    freeCount,
    savings,
    freeNames,
    toNext: cycles >= 1 && remainder === 0 ? 0 : toNext,
    status: cycles >= 1 ? (cycles >= 2 ? 'boosted' : 'active') : 'dormant',
    threshold: r.x,
  };
}

/* ── LES TOTAUX ────────────────────────────────────────────────────────────
   Une seule fonction, parce qu'il n'y a qu'une addition qui compte : celle du
   serveur (POST /orders). Le panier, le tunnel et la confirmation la lisaient
   chacun à leur façon — le client voyait donc trois montants pour une seule
   commande, dont aucun n'était forcément celui facturé.

   Formule du serveur, à la ligne près :
     total = max(0, sous-total − offre croisée − remise boutique − bon + frais)
   la remise boutique portant sur (sous-total − offre croisée). */
function wsArrondi(x) { return Math.round(x * 100) / 100; }

/* Remise de la boutique (shops.discount_type / discount_value, servies par
   /shops sous webshop_discount_*). Elle était APPLIQUÉE par le serveur et
   AFFICHÉE nulle part : le client lisait un total supérieur à ce qu'il payait,
   et l'écart, étant en sa faveur, ne remontait jamais. */
function wsRemiseBoutique(shop, base) {
  const type = shop && shop.webshop_discount_type;
  const val  = Number(shop && shop.webshop_discount_value) || 0;
  if (!type || val <= 0 || base <= 0) return 0;
  return wsArrondi(type === 'fixed' ? Math.min(base, val) : Math.round(base * val) / 100);
}

function wsTotaux({ basket, shop, crossSavings = 0, voucherDiscount = 0, deliveryFee = 0 }) {
  const sousTotal = (Array.isArray(basket) ? basket : []).reduce((t, l) => t + l.price * l.qty, 0);
  const croise    = crossSavings || 0;
  const remise    = wsRemiseBoutique(shop, sousTotal - croise);
  const total     = Math.max(0, wsArrondi(sousTotal - croise - remise - (voucherDiscount || 0) + (deliveryFee || 0)));
  return { sousTotal, croise, remise, bon: voucherDiscount || 0, frais: deliveryFee || 0, total,
           remisePct: (shop && shop.webshop_discount_type === 'percent')
             ? Number(shop.webshop_discount_value) || 0 : 0 };
}

function CrossPortionStrip({ calc }) {
  const { t } = wsUseT();
  if (!calc) return null;
  const { eligibleCount, groupSize, freeCount, savings, freeNames, status, threshold } = calc;
  const unlocked = status !== 'dormant';
  // Remainder progress = portions accumulated *toward the next* freebie cycle.
  const remainder = eligibleCount % groupSize;
  const inCycle = remainder === 0 && eligibleCount > 0 ? groupSize : remainder;
  const toNextCycle = groupSize - remainder;

  let lede;
  if (unlocked) {
    const names = freeNames.slice(0, 2).join(', ') + (freeNames.length > 2 ? '…' : '');
    lede = t(freeCount > 1 ? 'cross.freeMany' : 'cross.freeOne', { n: freeCount })
         + (names ? ' · ' + names : '');
  } else {
    lede = t(calc.toNext > 1 ? 'cross.toNextMany' : 'cross.toNextOne', { n: calc.toNext });
  }

  return (
    <div className={`ws-cross ws-cross--${status}${unlocked ? ' is-unlocked' : ''}`}>
      <div className="ws-cross__head">
        <div className="ws-cross__icon" aria-hidden="true">
          {unlocked ? (
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
              <path d="M5 12.5l4 4 10-10"/>
            </svg>
          ) : (
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
              <path d="M20 7H4v5h16V7Z"/>
              <path d="M5 12v8h14v-8"/>
              <path d="M12 7v13"/>
              <path d="M12 7c-1.5-2.5-5-2.5-5 0 0 1 .5 2 2 2h3"/>
              <path d="M12 7c1.5-2.5 5-2.5 5 0 0 1-.5 2-2 2h-3"/>
            </svg>
          )}
        </div>
        <div className="ws-cross__titles">
          <div className="ws-cross__title">{t('cross.title', { n: threshold })}</div>
          <div className="ws-cross__lede">{lede}</div>
        </div>
        {savings > 0 && <div className="ws-cross__save">−€{savings.toFixed(2)}</div>}
      </div>
      {!unlocked && (
        <div className="ws-cross__dots" aria-hidden="true">
          {Array.from({ length: groupSize }).map((_, i) => {
            let cls = 'ws-cross__dot';
            if (i < threshold) cls += ' is-buy';
            else cls += ' is-free';
            if (i < inCycle) cls += ' is-on';
            return <span key={i} className={cls}/>;
          })}
        </div>
      )}
      {!unlocked && (
        <div className="ws-cross__hint">{t('cross.hint')}</div>
      )}
      {unlocked && remainder > 0 && (
        <div className="ws-cross__nudge">
          {t(toNextCycle > 1 ? 'cross.nudgeMany' : 'cross.nudgeOne', { n: toNextCycle })}
        </div>
      )}
    </div>
  );
}

/* Suggestions « Panier Croisé ». Le serveur reçoit le panier et rend les
   produits à proposer, DÉJÀ filtrés sur l'assortiment de la boutique, la gamme
   saisonnière à la date de retrait et le stock du jour : le navigateur ne
   décide de rien. L'heure comparée est celle du CRÉNEAU DE RETRAIT, pas de la
   commande — on commande le soir pour le lendemain midi.
   Aucune suggestion → aucun bloc : pas de rubrique vide. */
function CrossSell({ shopId, mode, date, time, basket, placement, onAdd }) {
  const { t } = wsUseT();
  const [items, setItems] = React.useState([]);
  const ids = basket.map((l) => l.productId).filter(Boolean);
  const key = ids.slice().sort().join(',') + '|' + (date || '') + '|' + (time || '') + '|' + mode;
  React.useEffect(() => {
    let alive = true;
    const base = window.WSCatalog && window.WSCatalog.endpoint;
    if (!base || !shopId || ids.length === 0) { setItems([]); return; }
    fetch(base + '/cross-sell', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ shopId, mode, date: date || null, time: time || null, placement, productIds: ids }),
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((list) => { if (alive) setItems(Array.isArray(list) ? list : []); })
      // Une panne de suggestions ne doit RIEN casser du panier — mais elle se
      // trace, sinon « aucune suggestion » et « serveur muet » se ressemblent.
      .catch((e) => { if (alive) { setItems([]); bug('cross-sell', 'suggestions indisponibles', e); } });
    return () => { alive = false; };
  }, [key, shopId, placement]);

  // Mesure : une impression comptée par produit réellement affiché.
  React.useEffect(() => {
    const base = window.WSCatalog && window.WSCatalog.endpoint;
    if (!base || !items.length) return;
    for (const it of items) {
      fetch(base + '/cross-sell/stat', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: 'impression', ruleId: it.ruleId, productId: it.productId, shopId }),
      }).catch(() => {});
    }
  }, [items.map((i) => i.productId).join(','), shopId]);

  if (!items.length) return null;
  return (
    <div className="ws-xsell">
      <div className="ws-xsell__h">{t('xsell.title')}</div>
      {items.map((it) => (
        <div className="ws-xsell__i" key={it.productId}>
          {it.img ? <img className="ws-xsell__img" src={it.img} alt="" onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }}/>
                  : <span className="ws-xsell__img"/>}
          <span className="ws-xsell__n">{it.name}</span>
          <span className="ws-xsell__p">€{Number(it.price).toFixed(2)}</span>
          <button type="button" className="ws-xsell__add" aria-label={t('xsell.addItem', { name: it.name })} title={t('xsell.add')}
            onClick={() => {
              const base = window.WSCatalog && window.WSCatalog.endpoint;
              if (base) fetch(base + '/cross-sell/stat', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event: 'add', ruleId: it.ruleId, productId: it.productId, shopId }),
              }).catch(() => {});
              onAdd(it);
            }}>+</button>
        </div>
      ))}
    </div>
  );
}

function Basket({ shop, mode, basket, onClose, onCheckout, onRemove, onNote, notesEnabled, deliveryFeeResult,
                  date, slotTime, onCrossAdd }) {
  /* Les totaux affichés sont un APERÇU, calculé par wsTotaux() — miroir exact
     de l'addition du serveur, seule à faire foi. La commande, elle, est
     recalculée serveur depuis les prix ERP : c'est son total qui s'affiche à
     la confirmation. (L'ancienne « réduction Webshop · 5 % » codée en dur a
     disparu : la remise réelle vient de shops.discount_value.) */
  const { t } = wsUseT();
  const [crossPortionRule, setCrossPortionRule] = React.useState(null);
  React.useEffect(() => {
    if (window.WSPricing && typeof window.WSPricing.getCrossPortionRule === 'function') {
      window.WSPricing.getCrossPortionRule()
        .then((r) => { if (r) setCrossPortionRule(r); })
        .catch(() => {});
    }
  }, []);
  const crossOffer = computeCrossPortionOffer(basket, crossPortionRule);
  const crossSavings = crossOffer?.savings || 0;
  const deliveryFee = (mode === 'delivery' && deliveryFeeResult) ? (deliveryFeeResult.fee_amount || 0) : 0;
  // Le bon de réduction se saisit au tunnel : il n'entre pas dans ce total-ci,
  // exactement comme côté serveur où il s'applique après la remise boutique.
  const T = wsTotaux({ basket, shop, crossSavings, deliveryFee });
  const subtotal = T.sousTotal;
  const total = T.total;
  return (
    <aside className="ws-basket">
      <div className="ws-basket__head">
        <button className="ws-basket__back"><Pict d={ICONS.back} s={11}/> {t('cart.back')}</button>
        <span className="ws-basket__title">{t('cart.title')}</span>
      </div>

      <div className={`ws-basket__mode ws-basket__mode--${mode}`}>
        <span className="ws-basket__mode-dot"/>
        {t(mode === 'collect' ? 'cart.mode.collect' : 'cart.mode.delivery')}
      </div>

      <div className="ws-basket__items">
        {basket.length === 0 && (
          <div className="ws-basket__empty">{t('cart.empty')}</div>
        )}
        {basket.map((l) => (
          <div key={l.line} className="ws-line">
            <div className="ws-line__qty">×{l.qty}</div>
            <div className="ws-line__body">
              <div className="ws-line__name">{l.name}</div>
              {l.options.map((o, i) => (<div key={i} className="ws-line__opt">{o.label}</div>))}
              {l.offerLabel && (
                <div className="ws-line__offer">{t('cart.offer', { label: l.offerLabel })}{l.offerDiscount ? ` · −€${Number(l.offerDiscount).toFixed(2)}` : ''}</div>
              )}
              {notesEnabled && typeof onNote === 'function' && (
                <div className="ws-line__notewrap">
                  <svg className="ws-line__note-ic" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                  </svg>
                  <input
                    className="ws-line__note" type="text" defaultValue={l.note || ''} maxLength={255}
                    placeholder={t('cart.notePlaceholder')}
                    onBlur={(e) => onNote(l.line, e.target.value)}
                  />
                </div>
              )}
            </div>
            <div className="ws-line__price">€{(l.price * l.qty).toFixed(2)}</div>
            {typeof onRemove === 'function' && (
              <button
                type="button"
                className="ws-line__remove"
                onClick={() => onRemove(l.line)}
                aria-label={t('cart.removeItem', { name: l.name })}
                title={t('cart.remove')}
              >
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
                  <path d="M5 7h14"/>
                  <path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                  <path d="M7 7l1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12"/>
                  <path d="M10 11v6"/>
                  <path d="M14 11v6"/>
                </svg>
              </button>
            )}
          </div>
        ))}
        {typeof onCrossAdd === 'function' && (
          <CrossSell shopId={shop && shop.id} mode={mode} date={date} time={slotTime}
                     basket={basket} placement="cart" onAdd={onCrossAdd}/>
        )}
      </div>

      {crossOffer && <CrossPortionStrip calc={crossOffer}/>}

      <div className="ws-basket__sums">
        {basket.length > 0 && (
          <div className="ws-basket__row">
            <span>{t('cart.subtotal')}</span>
            <span>€{subtotal.toFixed(2)}</span>
          </div>
        )}

        {crossSavings > 0 && (
          <div className="ws-basket__row ws-basket__row--promo">
            <span>{t((crossOffer?.freeCount || 0) > 1 ? 'cross.freeMany' : 'cross.freeOne',
                     { n: crossOffer?.freeCount || 0 })}</span>
            <span>−€{crossSavings.toFixed(2)}</span>
          </div>
        )}

        {T.remise > 0 && (
          <div className="ws-basket__row ws-basket__row--promo">
            <span>{t('cart.shopDiscount')}{T.remisePct ? ` · ${T.remisePct} %` : ''}</span>
            <span>−€{T.remise.toFixed(2)}</span>
          </div>
        )}

        {deliveryFeeResult && mode === 'delivery' && (
          <div className={`ws-basket__row${deliveryFee === 0 ? ' ws-basket__row--free' : ''}`}>
            <span>{t('cart.deliveryFee')}{deliveryFee === 0 && deliveryFeeResult.free_delivery_minimum > 0
              ? ' · ' + t('cart.deliveryFreeFrom', { amount: '€' + deliveryFeeResult.free_delivery_minimum.toFixed(2) })
              : ''}</span>
            <span>{deliveryFee === 0 ? t('cart.deliveryFree') : `+€${deliveryFee.toFixed(2)}`}</span>
          </div>
        )}
        {deliveryFeeResult && mode === 'delivery' && deliveryFee > 0 && deliveryFeeResult.free_delivery_minimum > 0 && deliveryFeeResult.amount_remaining_for_free > 0 && (
          <div className="ws-basket__row ws-basket__row--fee-nudge">
            <span>{t('cart.deliveryRemaining', { amount: '€' + deliveryFeeResult.amount_remaining_for_free.toFixed(2) })}</span>
          </div>
        )}

        <div className="ws-basket__total">
          <span>{t('cart.total')}</span>
          <span className="ws-basket__total-amount">€{total.toFixed(2)}</span>
        </div>
      </div>

      <button className="ws-cta" style={{ background: 'var(--color-primary)' }} {...wsTap(onCheckout, { shield: true })} disabled={!basket.length}>
        {t('cart.checkout')}
        <Pict d={<path d="M5 12h14M13 5l7 7-7 7"/>} s={13}/>
      </button>

      <div className="ws-basket__foot">
        <Pict d={ICONS.pin} s={11}/> {shop.address}
      </div>
    </aside>
  );
}

// =========================================================================
// CATEGORY ROW — SINGLE LINE, TWO LEVELS
// -------------------------------------------------------------------------
// One line of choices, always in the same place; its CONTENT switches level.
// Never two stacked rows.
//   Level "cats":  [Tout] [catégories…] | [saisons…]
//   Level "subs":  [← Nom de la catégorie] [sous-catégories…]
// The first slot is therefore always occupied, by a different key per level.
// `Tout` and `← Catégorie` are NOT categories: no `category` row backs them —
// their icons come from ws_param (via /config), their labels from i18n.
// The back key carries the current category NAME on purpose: once the line
// switches to subcategories, that name would otherwise vanish from screen —
// the key both says where you are and lets you leave.
// A category with NO subcategory never switches level: there is nothing to
// switch to. A category with ONE does switch — its subcategory carries a
// label and an illustration of its own, and hiding it made the second level
// vanish for a whole shop. "A single choice is not a choice" was reasoning
// about a filter; a subcategory is also a signpost, and a shop whose entire
// catalogue sits under one of them showed no second level at all.
// =========================================================================
function CategoryRow({ active, sub, onSelect, onSelectSub, onBack, accent, tint, categories, assortments, navIcons }) {
  // i18n : hook global (webshop-i18n-react, chargé avant ce bundle) — un
  // repli direct sur WSI18n.t couvre le cas où le hook n'est pas monté.
  const { t, tCategory } = window.useT
    ? window.useT()
    : { t: (k, p) => (window.WSI18n ? window.WSI18n.t(k, p) : k), tCategory: (id, fb) => fb };
  const cats = categories || W_CATEGORIES;
  const assorts = assortments || W_ASSORTMENTS;
  const activeCat = cats.find((c) => String(c.id) === String(active)) || null;
  const subs = activeCat?.subs || [];
  /* Niveau sous-catégories dès qu'il y en a UNE. Le seuil était à deux, au
     motif qu'« un choix unique n'est pas un choix » : vrai d'un filtre, faux
     d'un repère. Relevé en production — Biscuiterie, Boulangerie et
     Pâtisserie n'ont qu'une sous-catégorie peuplée chacune, et une boutique
     dont tout le catalogue tient dans l'une d'elles n'avait aucun second
     niveau. Les sous-catégories vides, elles, sont déjà écartées en amont
     (navCats ne garde que celles qui contiennent un produit du créneau). */
  const subLevel = !!activeCat && subs.length >= 1;
  const catName = activeCat ? tCategory(activeCat.id, activeCat.label) : '';

  const visibleCount = 5;
  const [showAllSubs, setShowAllSubs] = React.useState(false);
  const visibleSubs = showAllSubs ? subs : subs.slice(0, visibleCount);
  const hiddenCount = subs.length - visibleCount;
  React.useEffect(() => { setShowAllSubs(false); }, [active]);

  // Accessibilité : au CHANGEMENT de niveau (pas au premier rendu), le focus
  // se pose sur la première touche de la nouvelle ligne — sinon il retombe en
  // haut de page — et la zone aria-live annonce le nouveau contenu (la ligne
  // se transforme sans qu'aucun élément n'apparaisse ailleurs : rien ne le
  // signalerait par défaut aux lecteurs d'écran).
  const stripRef = React.useRef(null);
  const prevLevel = React.useRef(subLevel);
  const [announce, setAnnounce] = React.useState('');
  React.useEffect(() => {
    if (prevLevel.current === subLevel) return;
    prevLevel.current = subLevel;
    setAnnounce(subLevel ? t('nav.category.subsOf', { category: catName }) : t('nav.category.cats'));
    const first = stripRef.current && stripRef.current.querySelector('button');
    if (first) first.focus();
  }, [subLevel, catName]);

  const activeStyle = { '--cat-accent': accent, '--cat-tint': tint };
  const icons = navIcons || {};

  return (
    <div className="ws-cats-wrap">
      <span className="ws-vh" aria-live="polite" role="status">{announce}</span>
      <div className="ws-cats" ref={stripRef}
           aria-label={subLevel ? t('nav.category.subsOf', { category: catName }) : t('nav.category.cats')}>
        {!subLevel && (
          <>
            {/* Première position — état « Tout » (icône ws_param, libellé i18n) */}
            <button key="all" className={`ws-cat${active === 'all' ? ' is-active' : ''}`}
                    {...wsTap(() => onSelect('all'))} style={active === 'all' ? activeStyle : {}}>
              <span className="ws-cat__tile">{icons.all ? <img src={icons.all} alt=""/> : null}</span>
              <span className="ws-cat__lbl">{t('nav.category.all')}</span>
            </button>
            {cats.map((c) => {
              const isOn = String(active) === String(c.id);
              return (
                <button key={c.id} className={`ws-cat${isOn ? ' is-active' : ''}`} {...wsTap(() => onSelect(c.id))} style={isOn ? activeStyle : {}}>
                  {/* Garde identique aux tuiles voisines (icons.all / icons.back) :
                      une src vide fait recharger la PAGE comme image, échoue au
                      décodage et log une erreur console par tuile et par rendu. */}
                  <span className="ws-cat__tile">{c.img ? <img src={c.img} alt=""/> : null}</span>
                  <span className="ws-cat__lbl">{tCategory(c.id, c.label)}</span>
                </button>
              );
            })}
            {/* Seasonal assortments — same badge style, but distinct shape (notched corner) */}
            {assorts.length > 0 && <div className="ws-cats__sep" aria-hidden="true"/>}
            {assorts.map((a) => {
              const isOn = active === `season:${a.id}`;
              return (
                <button key={a.id} className={`ws-cat ws-cat--season${isOn ? ' is-active' : ''}`} {...wsTap(() => onSelect(`season:${a.id}`))} style={isOn ? activeStyle : {}}>
                  <span className="ws-cat__tile">
                    {a.img ? <img src={a.img} alt=""/> : null}
                  </span>
                  <span className="ws-cat__lbl">{a.label}</span>
                </button>
              );
            })}
          </>
        )}

        {subLevel && (
          <>
            {/* Première position — état « retour », porteur du nom de la
                catégorie courante (dire où on est + permettre d'en sortir). */}
            <button key="back" className="ws-cat ws-cat--back" onClick={onBack}>
              <span className="ws-cat__tile">{icons.back ? <img src={icons.back} alt=""/> : null}</span>
              <span className="ws-cat__lbl">{t('nav.category.back', { category: catName })}</span>
            </button>
            {visibleSubs.map((s) => {
              const isOn = String(sub) === String(s.id);
              return (
                <button key={s.id} className={`ws-subcat${isOn ? ' is-active' : ''}`}
                        aria-pressed={isOn}
                        {...wsTap(() => onSelectSub(isOn ? null : s.id))} style={isOn ? activeStyle : {}}>
                  <span className="ws-subcat__tile">{s.img ? <img src={s.img} alt=""/> : null}</span>
                  <span className="ws-subcat__lbl">{s.label}</span>
                </button>
              );
            })}
            {hiddenCount > 0 && !showAllSubs && (
              <button className="ws-subcat ws-subcat--more" {...wsTap(() => setShowAllSubs(true))}>
                <span className="ws-subcat__tile"><span className="ws-subcat__more-num">+{hiddenCount}</span></span>
                <span className="ws-subcat__lbl">{t('common.seeMore')}</span>
              </button>
            )}
          </>
        )}
      </div>
    </div>
  );
}

// =========================================================================
// NAVBAR — three variants share the same internals but wrap differently
// =========================================================================

// Variant A — Subtle: small shop chip after brand
// ── Bandeau « objectif d'achat cumulé → produit cadeau » (haut de boutique) ──
// Lit WSPromo.active(shop) puis la progression du client. Barre de progression,
// message « plus que X € », et à l'objectif : produit cadeau + code (récupéré via
// claim, idempotent). Masqué si aucune campagne / pas d'identité (invité non
// connecté) — l'invité applique son code au checkout.
function GiftIcon({ size = 22, className }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
         strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      <path d="M20 12v9H4v-9"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/>
      <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
      <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
    </svg>
  );
}
function GiftProgressBanner({ shop, user }) {
  const { t } = wsUseT();
  const [prog, setProg] = React.useState(null);
  const [copied, setCopied] = React.useState(false);
  const shopId = shop && shop.id;
  const guestEmail = user ? null : null;   // page boutique : identité = client connecté (jeton) ou démo

  React.useEffect(() => {
    let alive = true;
    if (!window.WSPromo) return;
    Promise.resolve(window.WSPromo.active({ shopId }))
      .then((camps) => {
        const c = Array.isArray(camps) ? camps[0] : null;
        if (!c) { if (alive) setProg(null); return; }
        return Promise.resolve(window.WSPromo.progress(c.id, { guestEmail }))
          .then((p) => { if (alive) setProg(p && p.campaign ? p : null); });
      })
      .catch(() => { if (alive) setProg(null); });
    return () => { alive = false; };
  }, [shopId, user && user.id]);

  // Objectif atteint sans code encore émis → claim (idempotent) pour l'obtenir.
  React.useEffect(() => {
    if (prog && prog.status === 'unlocked' && !prog.voucherCode && window.WSPromo) {
      Promise.resolve(window.WSPromo.claim(prog.campaign.id, { guestEmail }))
        .then((p) => { if (p && p.campaign) setProg(p); }).catch(() => {});
    }
  }, [prog && prog.status, prog && prog.voucherCode]);

  if (!prog || !prog.campaign) return null;
  const c = prog.campaign;
  const cur = c.currency || 'EUR';
  const money = (n) => new Intl.NumberFormat('fr-BE', { style: 'currency', currency: cur, maximumFractionDigits: Number.isInteger(n) ? 0 : 2 }).format(n);
  const pct = Math.max(0, Math.min(100, Math.round((prog.accumulated / c.threshold) * 100)));
  const unlocked = prog.status === 'unlocked';
  const reward = c.reward || {};
  const copy = () => {
    if (!prog.voucherCode) return;
    try { navigator.clipboard.writeText(prog.voucherCode); setCopied(true); setTimeout(() => setCopied(false), 1800); } catch (_) {}
  };

  return (
    <section className={`ws-giftbar${unlocked ? ' is-unlocked' : ''}`} aria-label={t('gift.campaign')}>
      <div className="ws-giftbar__head">
        <GiftIcon size={22} className="ws-giftbar__ic"/>
        <div>
          <div className="ws-giftbar__ttl">{unlocked ? 'Cadeau débloqué' : 'Votre cadeau vous attend'}</div>
          <div className="ws-giftbar__cap">{c.name}{c.endsAt ? ` · jusqu'au ${String(c.endsAt).slice(8, 10)}/${String(c.endsAt).slice(5, 7)}` : ''}</div>
        </div>
      </div>
      <div className="ws-giftbar__bar"><div className="ws-giftbar__fill" style={{ width: pct + '%' }}/></div>
      <div className="ws-giftbar__amt">
        <b>{money(prog.accumulated)}</b><span className="ws-giftbar__goal">objectif {money(c.threshold)}</span>
      </div>
      {!unlocked && (
        <div className="ws-giftbar__remain">{tRich(t, 'gift.remaining', { amount: money(prog.remaining) })}</div>
      )}
      {unlocked && (
        <>
          <div className="ws-giftbar__reward">
            <span className="ws-giftbar__sw"/>
            <span>
              <span className="ws-giftbar__pn">{reward.name || 'Votre cadeau'}</span>
              <span className="ws-giftbar__pp">Cadeau · {money(0)}</span>
            </span>
          </div>
          {prog.voucherCode && (
            <div className="ws-giftbar__code">
              <span>
                <span className="ws-giftbar__lab">{t('gift.yourCode')}</span>
                <span className="ws-giftbar__val">{prog.voucherCode}</span>
              </span>
              <button type="button" className="ws-giftbar__copy" onClick={copy}>{copied ? 'Copié' : 'Copier'}</button>
            </div>
          )}
          {c.rewardDeliveryDate && (
            <div className="ws-giftbar__hint">À ajouter à votre commande dès le {String(c.rewardDeliveryDate).slice(8, 10)}/{String(c.rewardDeliveryDate).slice(5, 7)}.</div>
          )}
        </>
      )}
    </section>
  );
}

function NavbarA({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, user, onAccount, onAllergens,
                   collectCutoffPassed, collectCutoffLabel, deliveryCutoffPassed, deliveryCutoffLabel, minLeadDays }) {
  const { t } = wsUseT();
  return (
    <header className="ws-nav ws-nav--A">
      <div className="ws-nav__left">
        <button className="ws-nav__shopchip" onClick={onSwitchShop}>
          <span className="ws-nav__shopchip-dot"/>
          <span>{shop.name}</span>
          <Pict d={ICONS.chev} s={10}/>
        </button>
        <DatePill mode={mode} value={date} onChange={onDate} shopId={shop.id}
          collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
          deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}
          minLeadDays={minLeadDays}/>
        <ModePills mode={mode} onChange={onMode}
          collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
          deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}/>
      </div>
      <div className="ws-nav__right">
        {/* Desktop : sélecteur de langue. Mobile : masqué (voir .ws-nav__lang),
            remplacé par l'icône « i » -> landing « Livraison au bureau ». */}
        {window.LangChip && <window.LangChip className="ws-nav__lang" />}
        {/* Le « i » « pas encore de bureau ? » a quitté le bandeau permanent :
            il ne concerne QUE l'utilisateur sans bureau relié, et sera reposé
            dans le flux « Livraison au bureau », là où il a du sens. Le retirer
            vide le bloc de droite sur mobile → le nom de la boutique récupère
            toute la ligne (fin de la troncature « Atelier by Ber… »). */}
        {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
        <button className="ws-nav__icon" aria-label={t('nav.accountAria')} onClick={onAccount}>
          {user
            ? <span className="ws-nav__avatar" aria-hidden="true">{user.firstName?.[0] || user.email?.[0]?.toUpperCase() || '·'}</span>
            : <Pict d={ICONS.user} s={15}/>}
        </button>
        <button className="ws-nav__icon ws-nav__cart" aria-label={t('nav.cartAria')}>
          <Pict d={ICONS.bag} s={15}/>
          {cartCount > 0 && <span className="ws-nav__cart-badge">{cartCount}</span>}
        </button>
      </div>
    </header>
  );
}

// Variant B — Medium: full colored brand bar above navbar
function NavbarB({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, onAllergens,
                   collectCutoffPassed, collectCutoffLabel, deliveryCutoffPassed, deliveryCutoffLabel, minLeadDays }) {
  const { t } = wsUseT();
  return (
    <>
      <div className="ws-shopbar" style={{ background: 'var(--color-primary)' }}>
        <div className="ws-shopbar__inner">
          <span className="ws-shopbar__pin"><Pict d={ICONS.pin} s={12}/></span>
          <span className="ws-shopbar__name">{tRich(t, 'nav.orderingAt', { shop: shop.name })}</span>
          <span className="ws-shopbar__city">{shop.city} · {shop.address}</span>
          <button className="ws-shopbar__switch" onClick={onSwitchShop}>
            Changer de boutique <Pict d={ICONS.switch} s={12}/>
          </button>
        </div>
      </div>
      <header className="ws-nav ws-nav--B">
        <div className="ws-nav__left">
          <span className="ws-nav__brand">L'Atelier By</span>
          <DatePill mode={mode} value={date} onChange={onDate} shopId={shop.id}
            collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
            deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}
            minLeadDays={minLeadDays}/>
          <ModePills mode={mode} onChange={onMode}
            collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
            deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}/>
        </div>
        <div className="ws-nav__right">
          {window.LangChip && <window.LangChip />}
          {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
          <button className="ws-nav__icon" aria-label={t('nav.accountAria')}><Pict d={ICONS.user} s={15}/></button>
          <button className="ws-nav__icon ws-nav__cart" aria-label={t('nav.cartAria')}>
            <Pict d={ICONS.bag} s={15}/>
            {cartCount > 0 && <span className="ws-nav__cart-badge">{cartCount}</span>}
          </button>
        </div>
      </header>
    </>
  );
}

// Variant C — Strong: full per-shop accent. Brand wordmark, navbar background, CTA, focus rings all picked up.
function NavbarC({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, onAllergens,
                   collectCutoffPassed, collectCutoffLabel, deliveryCutoffPassed, deliveryCutoffLabel, minLeadDays }) {
  const { t } = wsUseT();
  return (
    <header className="ws-nav ws-nav--C" style={{ '--shop-accent': shop.accent }}>
      <div className="ws-nav__left">
        <div className="ws-nav__brandwrap">
          <span className="ws-nav__brand" style={{ color: shop.accent }}>L'Atelier By</span>
          <button className="ws-nav__shopplate" onClick={onSwitchShop} style={{ color: shop.accent, borderColor: shop.accent }}>
            <Pict d={ICONS.pin} s={11}/>
            <span>{shop.name}</span>
            <span className="ws-nav__shopplate-city">· {shop.city}</span>
            <Pict d={ICONS.chev} s={10}/>
          </button>
        </div>
        <DatePill mode={mode} value={date} onChange={onDate} shopId={shop.id}
          collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
          deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}
          minLeadDays={minLeadDays}/>
        <ModePills mode={mode} onChange={onMode}
          collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
          deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}/>
      </div>
      <div className="ws-nav__right">
        {window.LangChip && <window.LangChip />}
        {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
        <button className="ws-nav__icon" aria-label={t('nav.accountAria')}><Pict d={ICONS.user} s={15}/></button>
        <button className="ws-nav__icon ws-nav__cart" aria-label={t('nav.cartAria')}>
          <Pict d={ICONS.bag} s={15}/>
          {cartCount > 0 && <span className="ws-nav__cart-badge">{cartCount}</span>}
        </button>
      </div>
    </header>
  );
}

// =========================================================================
// AUTH MODALS — login / register, account, office request
// =========================================================================
function useSwipeDownToClose(onClose, scrollEl) {
  /* Ref-CALLBACK, pas effet : les panneaux qui utilisent ce hook rendent null
     tant qu'ils sont fermés. L'ancien useEffect (deps [onClose]) tournait au
     premier montage — panneau absent, rien à écouter — et ne se rejouait à
     l'ouverture QUE si l'identité d'onClose changeait : le geste de fermeture
     n'était branché qu'au gré des re-rendus du parent. Le ref-callback, lui,
     s'exécute précisément quand le nœud apparaît/disparaît. */
  const onCloseRef = React.useRef(onClose);
  onCloseRef.current = onClose;
  const cleanupRef = React.useRef(null);
  return React.useCallback((el) => {
    if (cleanupRef.current) { cleanupRef.current(); cleanupRef.current = null; }
    if (!el) return;
    const state = { y0: 0, dy: 0, dragging: false, atTop: true };
    function onStart(e) {
      const t = e.touches ? e.touches[0] : e;
      // Le panneau porteur du geste n'est pas forcément le scroller (fiche
      // produit : .pdm est overflow:hidden, c'est .pdm-scroll qui défile).
      // Lire scrollTop sur le panneau armait le drag À CHAQUE toucher :
      // remonter dans la fiche tirait la bottom-sheet au lieu de défiler, et
      // au-delà de 110 px la fermait — configuration perdue.
      const sc = (scrollEl && scrollEl.current) || el;
      state.atTop = sc.scrollTop <= 0;
      // Allow dragging when starting at scroll-top OR on the handle itself
      const onHandle = e.target.closest && e.target.closest('.ws-modal__handle');
      if (!state.atTop && !onHandle) return;
      state.y0 = t.clientY; state.dy = 0; state.dragging = true;
      el.style.transition = 'none';
    }
    function onMove(e) {
      if (!state.dragging) return;
      const t = e.touches ? e.touches[0] : e;
      const dy = Math.max(0, t.clientY - state.y0);
      state.dy = dy;
      el.style.transform = `translateY(${dy}px)`;
      if (dy > 4 && e.cancelable) e.preventDefault();
    }
    function onEnd() {
      if (!state.dragging) return;
      state.dragging = false;
      el.style.transition = 'transform 220ms cubic-bezier(.2,.8,.2,1)';
      if (state.dy > 110) {
        el.style.transform = 'translateY(100%)';
        setTimeout(() => onCloseRef.current && onCloseRef.current(), 200);
      } else {
        el.style.transform = '';
      }
    }
    el.addEventListener('touchstart', onStart, { passive: true });
    el.addEventListener('touchmove', onMove, { passive: false });
    el.addEventListener('touchend', onEnd);
    el.addEventListener('touchcancel', onEnd);
    cleanupRef.current = () => {
      el.removeEventListener('touchstart', onStart);
      el.removeEventListener('touchmove', onMove);
      el.removeEventListener('touchend', onEnd);
      el.removeEventListener('touchcancel', onEnd);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

function ModalShell({ onClose, children, narrow }) {
  const { t } = wsUseT();
  const panelRef = useSwipeDownToClose(onClose);
  const [showArrows, setShowArrows] = React.useState({ up: false, down: false });
  React.useEffect(() => {
    const el = panelRef.current; if (!el) return;
    const update = () => {
      const can = el.scrollHeight > el.clientHeight + 4;
      setShowArrows({
        up: can && el.scrollTop > 8,
        down: can && (el.scrollTop + el.clientHeight) < (el.scrollHeight - 8),
      });
    };
    update();
    el.addEventListener('scroll', update, { passive: true });
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => { el.removeEventListener('scroll', update); ro.disconnect(); };
  }, []);
  function nudge(dir) {
    const el = panelRef.current; if (!el) return;
    el.scrollBy({ top: dir * Math.max(180, el.clientHeight * 0.6), behavior: 'smooth' });
  }
  return (
    <div className="ws-modal" onClick={onClose}>
      <div ref={panelRef} className={`ws-modal__panel${narrow ? ' ws-modal__panel--narrow' : ''}`} onClick={(e) => e.stopPropagation()}>
        <span className="ws-modal__handle" aria-hidden="true"/>
        <button className="ws-modal__close" onClick={onClose} aria-label={t('common.close2')}><Pict d={ICONS.close} s={14}/></button>
        {children}
        <div className="ws-modal__rail" aria-hidden={!(showArrows.up || showArrows.down)}>
          <button type="button" className={`ws-modal__rail-btn${showArrows.up ? '' : ' is-disabled'}`} onClick={() => nudge(-1)} aria-label={t('scroll.up')}>
            <svg viewBox="0 0 16 16" width="12" height="12"><path d="M3 10l5-5 5 5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/></svg>
          </button>
          <button type="button" className={`ws-modal__rail-btn${showArrows.down ? '' : ' is-disabled'}`} onClick={() => nudge(1)} aria-label={t('scroll.down')}>
            <svg viewBox="0 0 16 16" width="12" height="12"><path d="M3 6l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/></svg>
          </button>
        </div>
      </div>
    </div>
  );
}

// Préfixes internationaux proposés dans les formulaires (défaut +32 Belgique).
const PHONE_PREFIXES = [
  ['+32', '+32'], ['+33', '+33'], ['+31', '+31'],
  ['+352', '+352'], ['+49', '+49'],
];

// ── Collecte du code postal client (obligatoire partout) ────────────────
// Dès que 4 chiffres sont saisis, la LOCALITÉ correspondante s'affiche
// (référentiel /geo/postcodes) — liste déroulante quand un même code couvre
// plusieurs localités (ex. 1300 → Limal · Wavre). La localité confirmée est
// envoyée au serveur avec le code postal.
const CP_RE = /^[1-9][0-9]{3}$/;             // format belge : 4 chiffres
const _cpLocCache = {};
async function cpLocalities(cp) {
  cp = String(cp || '').trim();
  if (!CP_RE.test(cp)) return [];
  if (_cpLocCache[cp]) return _cpLocCache[cp];
  const base = (window.WSAuth && window.WSAuth.endpoint)
    ? String(window.WSAuth.endpoint).replace(/\/auth\/?$/, '') : '';
  if (!base) return [];
  try {
    const r = await fetch(`${base}/geo/postcodes?q=${encodeURIComponent(cp)}`);
    const j = await r.json();
    const list = [...new Set((Array.isArray(j) ? j : [])
      .filter((e) => String(e.cp) === cp).map((e) => String(e.commune)))];
    _cpLocCache[cp] = list;
    return list;
  } catch (_) { return []; }
}
// Champ CP + localité auto. `variant` adapte le markup au formulaire hôte
// ('modal' → ws-field du LoginModal, 'acc' → ws-acc__field du compte).
function CpField({ cp, locality, onCp, onLocality, onOpts, variant }) {
  const { t } = wsUseT();
  const [opts, setOpts] = useState([]);
  useEffect(() => {
    let alive = true;
    const v = String(cp || '').trim();
    if (!CP_RE.test(v)) {
      setOpts([]); if (onOpts) onOpts([]);
      if (locality) onLocality('');
      return;
    }
    cpLocalities(v).then((list) => {
      if (!alive) return;
      setOpts(list); if (onOpts) onOpts(list);
      if (list.length === 1) onLocality(list[0]);                    // localité unique → auto
      else if (list.length > 1 && !list.includes(locality)) onLocality(''); // ambigu → choix requis
    });
    return () => { alive = false; };
  }, [cp]);
  const acc = variant === 'acc';
  const fieldCls = acc ? 'ws-acc__field' : 'ws-field';
  const inputCls = acc ? 'ws-acc__input' : undefined;
  const lbl = (t) => acc ? <span className="ws-acc__field-label">{t}</span> : <span>{t}</span>;
  const locHint = { margin: '2px 0 0', fontSize: 12.5, opacity: .75 };
  return (
    <>
      <label className={fieldCls}>{lbl('Code postal')}
        <input className={inputCls} value={cp} onChange={(e) => onCp(e.target.value)}
          autoComplete="postal-code" inputMode="numeric" maxLength={4} placeholder="1000" required/>
        {opts.length === 1 && locality && <p style={locHint}>📍 {locality}</p>}
      </label>
      {opts.length > 1 && (
        <label className={fieldCls}>{lbl('Localité')}
          <select className={inputCls} value={locality} onChange={(e) => onLocality(e.target.value)} required>
            <option value="">{t('cp.pickLocality')}</option>
            {opts.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </label>
      )}
    </>
  );
}

function LoginModal({ open, onClose, onLogin, onRegister, shopId }) {
  const { t } = wsUseT();
  const [tab, setTab] = useState('login');
  const [form, setForm] = useState({ identifier: '', email: '', phone: '', phonePrefix: '+32', password: '', firstName: '', lastName: '', postalCode: '', locality: '', authMethod: 'email' });
  const [err, setErr] = useState('');
  const [loading, setLoading] = useState(false);
  const [pwStep, setPwStep] = useState(false);   // panneau « compte existant -> mot de passe »
  const [newPw, setNewPw] = useState('');
  const [cpOpts, setCpOpts] = useState([]);      // localités du CP saisi (validation « localité choisie »)
  if (!open) return null;
  function set(k, v) { setForm((f) => ({ ...f, [k]: v })); setErr(''); }
  async function submit(e) {
    e.preventDefault();
    setLoading(true); setErr('');
    try {
      if (tab === 'login') {
        if (!form.identifier || !form.password) { setErr('Email/téléphone et mot de passe requis.'); return; }
        const r = window.WSAuth
          ? await window.WSAuth.login({ identifier: form.identifier, password: form.password, phonePrefix: form.phonePrefix, authMethod: form.authMethod })
          : SRV_REQUIRED('de connexion');
        if (!r.ok) {
          // Compte existant sans mot de passe -> panneau "définir votre mot de passe"
          // (on pré-remplit avec ce qui vient d'être tapé au login).
          if (r.needsPassword) { setForm((f) => ({ ...f, email: f.email || (f.identifier.includes('@') ? f.identifier : ''), phone: f.phone || (f.identifier.includes('@') ? '' : f.identifier) })); setNewPw(form.password || ''); setPwStep(true); return; }
          setErr(r.error || 'Identifiants incorrects.'); return;
        }
        onLogin(r.user); onClose();
      } else {
        if (!form.firstName || !form.lastName) { setErr('Prénom et nom requis.'); return; }
        // L'inscription se fait par email (le téléphone est optionnel) — le
        // serveur n'a jamais accepté l'inscription par téléphone seul, le
        // formulaire ne doit pas la promettre.
        if (!form.email) { setErr('Email requis.'); return; }
        if ((form.password || '').length < 8) { setErr('Mot de passe requis (8 caractères minimum).'); return; }
        // Code postal OBLIGATOIRE (collecte réseau) + localité confirmée quand
        // le code couvre plusieurs localités.
        if (!CP_RE.test(String(form.postalCode).trim())) { setErr('Code postal requis (4 chiffres).'); return; }
        if (cpOpts.length > 1 && !form.locality) { setErr('Choisissez votre localité.'); return; }
        const r = window.WSAuth
          ? await window.WSAuth.register({ ...form, shopId })
          : SRV_REQUIRED("d'inscription");
        if (!r.ok) {
          if (r.exists) { setPwStep(true); return; }   // compte déjà présent -> set-password
          setErr(r.error || "Erreur lors de l'inscription."); return;
        }
        onRegister(r.user); onClose();
      }
    } catch (_) {
      setErr('Erreur réseau. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  }
  async function submitSetPassword() {
    setErr('');
    if (!newPw || newPw.length < 6) { setErr('Mot de passe : 6 caractères minimum.'); return; }
    setLoading(true);
    try {
      const r = await window.WSAuth.setPassword({ email: form.email, phone: form.phone, phonePrefix: form.phonePrefix, identifier: form.identifier, password: newPw });
      if (!r.ok) { setErr(r.error || 'Échec de la mise à jour.'); return; }
      onRegister(r.user); onClose();
    } catch (_) {
      setErr('Erreur réseau. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  }
  return (
    <ModalShell onClose={onClose} narrow>
      <p className="ws-modal__eyebrow">{t('auth.myAccount')}</p>
      {pwStep ? (
        <>
          <h2 className="ws-modal__title">{tRich(t, 'auth.accountExists')}</h2>
          <p className="ws-modal__lede">{t('auth.setPassword')}</p>
          <div className="ws-form">
            <label className="ws-field"><span>{t('auth.password')}</span>
              <input type="password" value={newPw} onChange={(e) => { setNewPw(e.target.value); setErr(''); }} autoComplete="new-password" placeholder={t('auth.min6')}/></label>
            {err && <p className="ws-form__err">{err}</p>}
            <button type="button" className="ws-cta ws-cta--block" disabled={loading} onClick={submitSetPassword}>{loading ? 'Mise à jour…' : 'Mettre à jour & se connecter'}</button>
            <button type="button" className="ws-linkbtn" onClick={() => { setPwStep(false); setTab('login'); setErr(''); }}>{t('auth.havePassword')}</button>
          </div>
        </>
      ) : (
      <>
      <h2 className="ws-modal__title">{tRich(t, tab === 'login' ? 'auth.welcomeBack' : 'auth.createTitle')}</h2>
      <p className="ws-modal__lede">{tab === 'login' ? 'Connectez-vous pour retrouver vos commandes et votre bureau.' : 'Quelques secondes pour commander, suivre et faire livrer.'}</p>
      <div className="ws-tabs">
        <button className={`ws-tab${tab === 'login' ? ' is-active' : ''}`} onClick={() => { setTab('login'); setErr(''); }}>{t('auth.login')}</button>
        <button className={`ws-tab${tab === 'register' ? ' is-active' : ''}`} onClick={() => { setTab('register'); setErr(''); }}>{t('auth.createAccount')}</button>
      </div>
      <form className="ws-form" onSubmit={submit}>
        {tab === 'register' && (
          <>
            <div className="ws-form__row2">
              <label className="ws-field"><span>{t('form.firstName')}</span><input value={form.firstName} onChange={(e) => set('firstName', e.target.value)} autoComplete="given-name"/></label>
              <label className="ws-field"><span>{t('form.lastName')}</span><input value={form.lastName} onChange={(e) => set('lastName', e.target.value)} autoComplete="family-name"/></label>
            </div>
            <label className="ws-field"><span>{t('form.email')}</span><input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} autoComplete="email"/></label>
            <label className="ws-field"><span>{t('auth.password')}</span><input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" minLength={8} placeholder={t('auth.min8')}/></label>
            <label className="ws-field"><span>{t('form.phone')} <em style={{ fontStyle: 'normal', fontWeight: 400, opacity: .6 }}>{t('form.optional')}</em></span>
              <span className="ws-phone">
                <select className="ws-phone__pfx" value={form.phonePrefix} onChange={(e) => set('phonePrefix', e.target.value)} aria-label={t('form.phonePrefix')}>
                  {PHONE_PREFIXES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <input type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} autoComplete="tel" inputMode="tel" placeholder="470 00 00 02"/>
              </span>
            </label>
            <CpField variant="modal" cp={form.postalCode} locality={form.locality}
              onCp={(v) => set('postalCode', v)} onLocality={(v) => set('locality', v)} onOpts={setCpOpts}/>
          </>
        )}
        {tab === 'login' && (
          <>
            {/* Toggle : se connecter avec email OU téléphone */}
            <div className="ws-toggle" role="tablist" aria-label={t('auth.signinWith')}>
              <button type="button" role="tab" aria-selected={form.authMethod === 'email'} className={`ws-toggle__opt${form.authMethod === 'email' ? ' is-active' : ''}`} onClick={() => set('authMethod', 'email')}>{t('form.email')}</button>
              <button type="button" role="tab" aria-selected={form.authMethod === 'phone'} className={`ws-toggle__opt${form.authMethod === 'phone' ? ' is-active' : ''}`} onClick={() => set('authMethod', 'phone')}>{t('form.phone')}</button>
            </div>
            <label className="ws-field"><span>{form.authMethod === 'phone' ? 'Téléphone' : 'Email'}</span>
              {form.authMethod === 'phone' ? (
                <span className="ws-phone">
                  <select className="ws-phone__pfx" value={form.phonePrefix} onChange={(e) => set('phonePrefix', e.target.value)} aria-label={t('form.phonePrefix')}>
                    {PHONE_PREFIXES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </select>
                  <input type="tel" value={form.identifier} onChange={(e) => set('identifier', e.target.value)} autoComplete="username" inputMode="tel" placeholder="470 00 00 02"/>
                </span>
              ) : (
                <input type="email" value={form.identifier} onChange={(e) => set('identifier', e.target.value)} autoComplete="username"/>
              )}
            </label>
            <label className="ws-field"><span>{t('auth.password')}</span><input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} autoComplete="current-password"/></label>
          </>
        )}
        {err && <p className="ws-form__err">{err}</p>}
        <button type="submit" className="ws-cta ws-cta--block" disabled={loading}>{loading ? 'Chargement…' : (tab === 'login' ? 'Se connecter' : 'Créer mon compte')}</button>
      </form>
      </>
      )}
    </ModalShell>
  );
}

// =========================================================================
// MON COMPTE — onglets. Le toggle DÉRIVE ses positions de cette liste (jamais
// en dur) : un onglet s'ajoute sans refonte. La visibilité de « Fidélité » est
// pilotée par ws_param.fidelity_tab_enabled (GET /config) — masquable en prod
// et révélable sans redéploiement. L'onglet actif survit au rafraîchissement.
// =========================================================================
const ACCOUNT_TABS = [
  { key: 'profil',   label: 'Profil' },
  { key: 'achats',   label: 'Mes achats' },
  { key: 'fidelite', label: 'Fidélité', configFlag: 'fidelityTabEnabled' },
];
const ACCOUNT_TAB_LS = 'ws.accountTab';

// Onglet Fidélité : coquille volontairement explicite — pas d'écran nu ni de
// compteur à zéro qui laisserait croire que le client a perdu ses points.
function FideliteShell() {
  const { t } = wsUseT();
  return (
    <div className="ws-acc__section">
      <div className="ws-acc__section-h">{t('acc.loyalty')}</div>
      <div className="ws-acc__card ws-acc__card--empty">
        <p className="ws-acc__note"><strong>{t('acc.loyaltySoon')}</strong> {t('acc.loyaltySoonHint')}</p>
      </div>
    </div>
  );
}

// =========================================================================
// MON COMPTE — onglet « Mes achats » : UNE liste qui fusionne tickets et
// factures. Une même ligne = ticket, ticket avec facture demandée, ou facture
// (état enrichi du même achat — jamais une seconde entrée). Le webshop n'émet
// AUCUNE facture : il écrit to_invoice + le destinataire, et affiche ce que
// l'ERP du franchisé a poussé (numéro, PDF). Le contrôle de demande n'apparaît
// que si la colonne to_invoice existe en base (capacité serveur).
// =========================================================================
const PURCHASE_FILTERS = [
  { key: 'all',       label: 'Tous' },
  { key: 'none',      label: 'Sans facture' },
  { key: 'requested', label: 'Facture demandée' },
  { key: 'invoiced',  label: 'Facturé' },
];
function AccountPurchases({ user }) {
  const { t } = wsUseT();
  const [filter, setFilter]   = useState('all');
  const [page, setPage]       = useState(1);
  const [meta, setMeta]       = useState({ total: 0, canRequestInvoice: false, invoiceNotice: '' });
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(false);
  const [busyRef, setBusyRef] = useState('');
  const [notice, setNotice]   = useState('');
  const [err, setErr]         = useState('');
  const PER = 10;
  useEffect(() => {
    let alive = true;
    setLoading(true);
    Promise.resolve(window.WSAuth && window.WSAuth.listPurchases
      ? window.WSAuth.listPurchases({ filter, page, perPage: PER })
      : { items: [], total: 0 })
      .then((d) => {
        if (!alive || !d) return;
        setMeta({ total: d.total || 0, canRequestInvoice: !!d.canRequestInvoice, invoiceNotice: d.invoiceNotice || '' });
        setItems((prev) => (page === 1 ? (d.items || []) : prev.concat(d.items || [])));
      })
      .catch(() => {})
      .then(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [filter, page]);
  function switchFilter(f) { setFilter(f); setPage(1); setNotice(''); setErr(''); }
  async function toggleInvoice(it, want, beOverride) {
    setBusyRef(it.ref); setErr('');
    // Destinataire : société liée par défaut, sinon l'utilisateur lui-même
    // (un particulier peut demander une facture à son nom). Jamais de saisie
    // libre — le serveur re-vérifie l'appartenance.
    const be = want ? (beOverride || it.billingEntityId || user.companyClientId || user.id) : null;
    const r = await window.WSAuth.requestInvoice({ ref: it.ref, want, billingEntityId: be });
    setBusyRef('');
    if (r.ok) {
      setItems((list) => list.map((x) => (x.ref === it.ref
        ? { ...x, state: want ? 'requested' : 'open', toInvoice: want ? 1 : 0, billingEntityId: want ? be : null }
        : x)));
      // Dit AU MOMENT de la demande — la facture n'apparaît qu'au batch mensuel.
      if (want && r.notice) setNotice(r.notice);
    } else {
      setErr(r.error || 'Échec de la demande.');
    }
  }
  const fmtDate = (s) => {
    try {
      const d = new Date(String(s).replace(' ', 'T'));
      return d.toLocaleDateString('fr-BE') + ' · ' + d.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' });
    } catch (_) { return String(s || ''); }
  };
  const fmtEur = (n) => ((n === null || n === undefined) ? '—' : Number(n).toFixed(2).replace('.', ',') + ' €');
  const peppolBadge = (st) => {
    const s = String(st || '').toLowerCase();
    if (s === 'transmise' || s === 'sent' || s === 'ok') return <span className="ws-acc__badge ws-acc__badge--ok">{t('acc.peppolSent')}</span>;
    if (s === 'echec' || s === 'failed' || s === 'error') return <span className="ws-acc__badge ws-acc__badge--pending">{t('acc.peppolFailed')}</span>;
    return <span className="ws-acc__badge ws-acc__badge--pending">{t('acc.pending')}</span>;
  };
  const badge = (st) => (st === 'invoiced'
    ? <span className="ws-acc__badge">{t('acc.invoiced')}</span>
    : st === 'requested'
      ? <span className="ws-acc__badge ws-acc__badge--pending">{t('acc.invoiceRequested')}</span>
      : null);
  const hasMore = items.length < (meta.total || 0);
  return (
    <div className="ws-acc__section">
      <div className="ws-acc__section-h">{t('acc.purchases')}</div>
      {/* Filtre discret — remplace un onglet « Mes factures » : le client qui
          veut ses factures pour son comptable filtre sur « Facturé ». */}
      <div className="ws-toggle" role="tablist" aria-label={t('acc.filterPurchases')}>
        {PURCHASE_FILTERS.map((f) => (
          <button key={f.key} type="button" role="tab" aria-selected={filter === f.key}
            className={'ws-toggle__opt' + (filter === f.key ? ' is-active' : '')}
            onClick={() => switchFilter(f.key)}>{f.label}</button>
        ))}
      </div>
      {notice && <p className="ws-acc__hint">🧾 {notice}</p>}
      {err && <p className="ws-acc__vat-msg ws-acc__vat-msg--err">⚠ {err}</p>}
      {loading && items.length === 0 && <p className="ws-acc__hint">{t('common.loading2')}</p>}
      {!loading && items.length === 0 && (
        <div className="ws-acc__card ws-acc__card--empty">
          <p className="ws-acc__note">Aucun achat sur les 12 derniers mois{filter !== 'all' ? ' pour ce filtre' : ''}.</p>
        </div>
      )}
      {items.map((it) => (
        <div key={it.source + '-' + it.ref} className="ws-acc__card">
          <div className="ws-acc__card-row"><span className="ws-acc__k">{it.ref}</span><span className="ws-acc__v">{badge(it.state)}</span></div>
          <div className="ws-acc__card-row"><span className="ws-acc__k">{it.shop || '—'}</span><span className="ws-acc__v">{fmtDate(it.at)}</span></div>
          <div className="ws-acc__card-row">
            <span className="ws-acc__k">{Number(it.items) || 0} article{Number(it.items) > 1 ? 's' : ''}</span>
            <span className="ws-acc__v">{fmtEur(it.invoiceTotal != null ? it.invoiceTotal : it.total)}</span>
          </div>
          {/* Ticket de caisse FISCAL rattaché à la commande (édité à la
              validation). N'apparaît que s'il est renseigné — jamais inventé. */}
          {it.fiscalTicketNo && (
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('acc.fiscalTicket')}</span>
              <span className="ws-acc__v">{it.fiscalTicketNo}{it.fiscalTicketUrl ? <> · <a href={it.fiscalTicketUrl} target="_blank" rel="noopener">PDF</a></> : null}</span></div>
          )}
          {it.state === 'invoiced' && (
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('acc.invoice')}</span>
              <span className="ws-acc__v">{it.invoiceNo}{it.hasInvoicePdf ? ' · PDF sur demande' : ''}</span></div>
          )}
          {/* Statut Peppol — transmission gérée par l'ERP ; le webshop affiche
              ce qu'il pousse. « — » tant que rien n'est renseigné. */}
          {it.state === 'invoiced' && it.peppolStatus && (
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('acc.peppol')}</span>
              <span className="ws-acc__v">{peppolBadge(it.peppolStatus)}{it.peppolAt ? ' · ' + fmtDate(it.peppolAt) : ''}</span></div>
          )}
          {meta.canRequestInvoice && it.source === 'ticket' && it.state !== 'invoiced' && (
            <label className="ws-acc__toggle" aria-label={t('acc.wantInvoice')}>
              <input type="checkbox" checked={it.state === 'requested'}
                disabled={it.state === 'closed' || busyRef === it.ref}
                onChange={(e) => toggleInvoice(it, e.target.checked)} />
              <span className="ws-acc__toggle-track" aria-hidden="true"><span className="ws-acc__toggle-thumb"/></span>
              <span className="ws-acc__toggle-label">
                {it.state === 'closed' ? 'Délai de demande dépassé' : 'Voulez-vous une facture ?'}
              </span>
            </label>
          )}
          {meta.canRequestInvoice && it.source === 'ticket' && it.state === 'requested' && user.companyClientId && (
            <div className="ws-acc__select-row">
              <select className="ws-acc__input" aria-label={t('acc.billingCompany')}
                value={String(it.billingEntityId || user.companyClientId)}
                disabled={busyRef === it.ref}
                onChange={(e) => toggleInvoice(it, true, Number(e.target.value))}>
                <option value={String(user.companyClientId)}>{user.company || 'Ma société'}</option>
                <option value={String(user.id)}>{t('acc.inMyName')}</option>
              </select>
            </div>
          )}
        </div>
      ))}
      {hasMore && (
        <button type="button" className="ws-cta ws-cta--block" disabled={loading} onClick={() => setPage((p) => p + 1)}>
          {t('common.seeMore')}
        </button>
      )}
    </div>
  );
}

// ── Rattrapage du code postal (comptes existants) ───────────────────────
// À CHAQUE connexion d'un client dont le code postal manque en base
// (user.needsPostcode), cette modal de saisie rapide s'affiche : un seul champ
// CP, localité affichée immédiatement pour confirmation, bouton Valider qui
// enregistre (PATCH /auth/me) et ferme. Jamais affichée si le CP est déjà
// connu ; ne réapparaît plus une fois enregistré. « Plus tard » referme pour
// cette session — elle reviendra à la prochaine connexion.
function PostcodeCatchupModal({ user, onUpdateUser }) {
  const { t } = wsUseT();
  const [cp, setCp] = useState('');
  const [locality, setLocality] = useState('');
  const [cpOpts, setCpOpts] = useState([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [snoozed, setSnoozed] = useState(false);
  const uid = user ? user.id : null;
  useEffect(() => { setSnoozed(false); setCp(''); setLocality(''); setErr(''); }, [uid]); // nouvelle connexion → réaffichage
  const needs = !!user && (user.needsPostcode === true || !user.postalCode);
  if (!needs || snoozed) return null;
  async function submit(e) {
    if (e) e.preventDefault();
    setErr('');
    if (!CP_RE.test(String(cp).trim())) { setErr('Code postal requis (4 chiffres).'); return; }
    if (cpOpts.length > 1 && !locality) { setErr('Choisissez votre localité.'); return; }
    if (!window.WSAuth || typeof window.WSAuth.updateMe !== 'function') { setSnoozed(true); return; }
    setBusy(true);
    try {
      const r = await window.WSAuth.updateMe({ postalCode: String(cp).trim(), locality });
      if (r && r.ok && r.user) { onUpdateUser(r.user); }              // needsPostcode:false → la modal disparaît
      else setErr((r && r.error) || 'Enregistrement impossible. Réessayez.');
    } catch (_) {
      setErr('Erreur réseau. Veuillez réessayer.');
    } finally {
      setBusy(false);
    }
  }
  return (
    <ModalShell onClose={() => setSnoozed(true)} narrow>
      <p className="ws-modal__eyebrow">{t('acc.yourProfile')}</p>
      <h2 className="ws-modal__title">{tRich(t, 'cp.title')}</h2>
      <p className="ws-modal__lede">{t('cp.lede')}</p>
      <form className="ws-form" onSubmit={submit}>
        <CpField variant="modal" cp={cp} locality={locality}
          onCp={(v) => { setCp(v); setErr(''); }} onLocality={setLocality} onOpts={setCpOpts}/>
        {err && <p className="ws-form__err">{err}</p>}
        <button type="submit" className="ws-cta ws-cta--block" disabled={busy}>{busy ? 'Enregistrement…' : 'Valider'}</button>
        <button type="button" className="ws-linkbtn" onClick={() => setSnoozed(true)}>{t('common.later')}</button>
      </form>
    </ModalShell>
  );
}

// Pulse apricot du « i » « pas encore de bureau ? » (à côté de « Livraison au bureau »).
if (typeof document !== 'undefined' && !document.getElementById('ws-bureau-style')) {
  const _st = document.createElement('style');
  _st.id = 'ws-bureau-style';
  _st.textContent = '@keyframes wsBureauPulse{0%{box-shadow:0 0 0 0 rgba(193,122,42,.55)}70%{box-shadow:0 0 0 7px rgba(193,122,42,0)}100%{box-shadow:0 0 0 0 rgba(193,122,42,0)}}';
  document.head.appendChild(_st);
}

function AccountModal({ open, user, onClose, onLogout, onRequestOffice, onUpdateUser, shops, currentShopId, onChangePreferredShop, office, tour }) {
  const { t } = wsUseT();
  const [form, setForm] = useState({
    firstName: user?.firstName || '',
    lastName: user?.lastName || '',
    company: user?.company || '',
    email: user?.email || '',
    phone: user?.phone || '',
    postalCode: user?.postalCode || '',
    locality: user?.locality || '',
    isBusiness: !!user?.isBusiness,
    preferredShopId: user?.preferredShopId || null,
    fidelityApp: user?.fidelityApp || { active: false, linkedAt: null },
    invoice: {
      country: user?.invoice?.country || 'BE',
      vat: user?.invoice?.vat || '',
      name: user?.invoice?.name || '',
      address: user?.invoice?.address || '',
      postalCode: user?.invoice?.postalCode || '',
      city: user?.invoice?.city || '',
    },
  });
  const [savedFlash, setSavedFlash] = useState(false);
  const [profileErr, setProfileErr] = React.useState('');
  // Statut VIES : si la société est déjà liée en base (verified_at côté client),
  // on affiche le badge « vérifié » d'office — comme la carte VIES de la PWA.
  const [vies, setVies] = useState(() => (user?.invoice?.viesVerified
    ? { status: 'ok', message: 'TVA vérifiée (VIES)' }
    : { status: 'idle', message: '' })); // idle | loading | ok | invalid | unavailable
  const [fidOpen, setFidOpen] = useState(false);
  // Comptes entreprise (livraison bureau) rattachés à l'e-mail du client
  // (ws_office_emails → ws_offices) — affichés en lecture seule, comme la PWA.
  const [linkedCompanies, setLinkedCompanies] = useState([]);
  // Office unplug/reconnect flow: 'idle' | 'confirm' | 'ask' | 'pick' | 'add'
  const [officeStep, setOfficeStep] = useState('idle');
  const [approvedOffices, setApprovedOffices] = useState([]);
  const [approvedOfficeTours, setApprovedOfficeTours] = useState({});
  const [pickedOfficeId, setPickedOfficeId] = useState('');
  // Recherche du bureau à lier. Une liste déroulante oblige à parcourir
  // l'inventaire pour retrouver un nom qu'on connaît déjà ; on tape ce qu'on
  // cherche. La saisie NE CRÉE RIEN : seule une société déjà validée par
  // l'Atelier peut être liée. Absente de la liste → demande d'ajout ci-dessous.
  const [officeQuery, setOfficeQuery] = useState('');
  const [newOffice, setNewOffice] = useState({
    name: '', vat: '', address: '', postalCode: '', city: '',
    contact: '', email: '', phone: '', preferredShopId: '',
  });
  const [officeErr, setOfficeErr] = useState('');
  const [officeBusy, setOfficeBusy] = useState(false);
  // Bureau « site de livraison » (parité PWA) : sélecteur lié au shop.
  const [siteStep, setSiteStep] = useState('idle');   // 'idle' | 'pick'
  const [siteList, setSiteList] = useState([]);
  const [sitePicked, setSitePicked] = useState('');
  const [siteBusy, setSiteBusy] = useState(false);
  const [siteErr, setSiteErr] = useState('');
  // Onglets (l'actif survit au rafraîchissement) + config serveur (flags).
  // Vide au départ : aucun flag n'est supposé activé tant que le serveur ne
  // l'a pas dit (l'ancien défaut fidelityTabEnabled:true affichait l'onglet
  // même pour une boutique qui l'a désactivé).
  const [cfg, setCfg] = useState({});
  const [tab, setTab] = useState(() => {
    try { return localStorage.getItem(ACCOUNT_TAB_LS) || 'profil'; } catch (_) { return 'profil'; }
  });
  // Sociétés de facturation : flux d'ajout (VIES ou sans TVA) — jamais
  // d'édition en place d'une société existante.
  const [companyStep, setCompanyStep] = useState('idle'); // 'idle' | 'vies' | 'novat'
  const [addVat, setAddVat] = useState({ country: 'BE', vat: '' });
  const [addNo, setAddNo]   = useState({ name: '', address: '', postalCode: '', city: '' });
  const [companyBusy, setCompanyBusy] = useState(false);
  const [companyErr, setCompanyErr]   = useState('');
  // Sécurité : changement de mot de passe.
  const [pw1, setPw1] = useState('');
  const [pw2, setPw2] = useState('');
  const [pwBusy, setPwBusy] = useState(false);
  const [pwMsg, setPwMsg]   = useState(null);

  // Config serveur (liste blanche ws_param) à l'ouverture de la modale.
  useEffect(() => {
    let alive = true;
    if (!open || !window.WSAuth || typeof window.WSAuth.config !== 'function') return;
    Promise.resolve(window.WSAuth.config())
      .then((c) => { if (alive && c) setCfg((prev) => ({ ...prev, ...c })); })
      .catch(() => {});
    return () => { alive = false; };
  }, [open]);
  const visibleTabs = ACCOUNT_TABS.filter((t) => !t.configFlag || cfg[t.configFlag] !== false);
  const activeTab = visibleTabs.some((t) => t.key === tab) ? tab : 'profil';
  function selectTab(k) {
    setTab(k);
    try { localStorage.setItem(ACCOUNT_TAB_LS, k); } catch (_) {}
  }

  async function addByVies() {
    setCompanyBusy(true); setCompanyErr('');
    const r = await window.WSAuth.billingVerify({ vat: addVat.vat, country: addVat.country });
    setCompanyBusy(false);
    if (r.ok && r.user) {
      if (typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
      setCompanyStep('idle'); setAddVat({ country: 'BE', vat: '' });
    } else {
      setCompanyErr((r.error && (r.error.message || r.error)) || 'Vérification indisponible, réessayez plus tard.');
    }
  }
  async function addNoVat() {
    setCompanyBusy(true); setCompanyErr('');
    const r = await window.WSAuth.addCompanyNoVat(addNo);
    setCompanyBusy(false);
    if (r.ok && r.user) {
      if (typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
      setCompanyStep('idle'); setAddNo({ name: '', address: '', postalCode: '', city: '' });
    } else {
      setCompanyErr(r.error || "Échec de l'ajout.");
    }
  }
  async function removeCompany() {
    // Archivage : on délie seulement — la fiche société reste en base (les
    // factures émises la référencent et doivent rester lisibles).
    setCompanyBusy(true);
    const r = await window.WSAuth.unlinkCompany();
    setCompanyBusy(false);
    if (r.ok && r.user && typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
  }
  async function savePassword() {
    setPwBusy(true); setPwMsg(null);
    const r = await window.WSAuth.changePassword({ password: pw1 });
    setPwBusy(false);
    if (r.ok) { setPwMsg({ ok: true, text: 'Mot de passe mis à jour.' }); setPw1(''); setPw2(''); }
    else setPwMsg({ ok: false, text: r.error || 'Échec.' });
  }

  // re-sync the form whenever a different user is loaded into the modal
  useEffect(() => {
    if (!user) return;
    setForm({
      firstName: user.firstName || '',
      lastName: user.lastName || '',
      company: user.company || '',
      email: user.email || '',
      phone: user.phone || '',
      postalCode: user.postalCode || '',
      isBusiness: !!user.isBusiness,
      preferredShopId: user.preferredShopId || null,
      fidelityApp: user.fidelityApp || { active: false, linkedAt: null },
      invoice: {
        country: user.invoice?.country || 'BE',
        vat: user.invoice?.vat || '',
        name: user.invoice?.name || '',
        address: user.invoice?.address || '',
        postalCode: user.invoice?.postalCode || '',
        city: user.invoice?.city || '',
      },
    });
    setVies(user.invoice?.viesVerified
      ? { status: 'ok', message: 'TVA vérifiée (VIES)' }
      : { status: 'idle', message: '' });
  }, [user]);

  // Comptes entreprise liés à l'e-mail (lecture seule).
  useEffect(() => {
    let alive = true;
    if (!open || !user?.email || !window.WSCompanies) { setLinkedCompanies([]); return; }
    Promise.resolve(window.WSCompanies.list(user.email))
      .then((cs) => { if (alive) setLinkedCompanies(Array.isArray(cs) ? cs : []); })
      .catch(() => { if (alive) setLinkedCompanies([]); });
    return () => { alive = false; };
  }, [open, user?.email]);

  if (!open || !user) return null;
  const status = !office ? 'unlinked' : (office.status === 'validated' && tour) ? 'active' : 'pending';

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })); }
  function setInvoiceField(k, v) {
    setForm((f) => ({ ...f, invoice: { ...f.invoice, [k]: v } }));
    if (k === 'vat' || k === 'country') setVies({ status: 'idle', message: '' });
  }

  // Persist a partial update through onUpdateUser + WSI18n customer store.
  // Used for the in-row controls (fidelity toggle, preferred shop) that
  // shouldn't wait for the global "Enregistrer" button.
  // Persistance serveur ATTENDUE : renvoie {ok, error}. L'ancien
  // fire-and-forget appliquait l'état localement même si le serveur refusait
  // (409) — le client croyait son bureau/sa boutique rattachés. On applique
  // d'abord l'optimiste (réactivité), mais on rend le verdict serveur pour que
  // l'appelant affiche l'erreur et, au besoin, revienne en arrière.
  async function persistPartial(patch) {
    const updated = { ...user, ...patch };
    setForm((f) => ({ ...f, ...patch }));
    if (typeof onUpdateUser === 'function') onUpdateUser(updated);
    if (window.WSI18n && window.WSI18n.setCustomer) {
      const existing = window.WSI18n.getCustomer() || {};
      window.WSI18n.setCustomer({ ...existing, ...updated });
    }
    if (window.WSAuth && typeof window.WSAuth.updateMe === 'function' && window.WSAuth.endpoint) {
      try {
        const r = await window.WSAuth.updateMe(patch);
        if (r && r.ok && r.user && typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
        return r || { ok: false, error: 'Réponse serveur vide.' };
      } catch (e) { return { ok: false, error: 'Serveur injoignable — non enregistré.' }; }
    }
    return { ok: false, error: 'Service de compte indisponible — non enregistré.' };
  }

  // Toggle app fidélité. L'état réel vit en base (fidelity_active, écrit par le
  // PWA lors de la liaison) ; la boutique le reflète seulement.
  //  • OFF→ON : ouvre la modale QR vers le PWA (pas d'activation locale — c'est
  //    dans le PWA que ça se lie ; on ne coche donc pas le toggle).
  //  • ON→OFF : délie côté boutique (état local jusqu'au prochain reload).
  function toggleFidelity(next) {
    if (next) {
      setFidOpen(true);              // modale QR → installer/ouvrir le PWA
    } else {
      persistPartial({ fidelityApp: { active: false, linkedAt: null } });
    }
  }

  // ── Office: unplug / reconnect / add new ───────────────────────────
  // Boutique de référence des bureaux : la préférée du profil, sinon celle que
  // le client consulte. Sans ce repli, un client dont preferred_shop_id est vide
  // (compte créé côté PWA, par exemple) restait bloqué sur « choisissez d'abord
  // votre boutique préférée » et ne pouvait pas se rattacher.
  const officeShopId = form.preferredShopId || currentShopId;
  async function loadApprovedOffices() {
    if (!window.WSOffices) { setOfficeErr('Service bureaux indisponible — please debug.'); return; }
    const shopId = officeShopId; // boutique préférée, sinon celle consultée
    setOfficeBusy(true);
    try {
      const list = await window.WSOffices.listApproved(shopId);
      const filtered = (list || []).filter((o) => o && o.id !== user.officeId);
      setApprovedOffices(filtered);
      if (window.WSTours && filtered.length) {
        const tourIds = [...new Set(filtered.map((o) => o.tourId).filter(Boolean))];
        const tourEntries = await Promise.all(
          tourIds.map((id) => window.WSTours.get(id).then((t) => [id, t]).catch(() => [id, null]))
        );
        setApprovedOfficeTours(Object.fromEntries(tourEntries));
      }
    } catch (e) {
      // L'échec est VISIBLE : sans lui, la liste restait vide sans explication
      // et l'utilisateur croyait qu'aucun bureau n'existait.
      setApprovedOffices([]);
      setOfficeErr(e && e.message ? e.message : 'Bureaux indisponibles — please debug.');
    } finally { setOfficeBusy(false); }
  }
  // ── Bureau « site de livraison » (parité PWA) : lier / changer / délier ──
  // Liste = ws_office_delivery_sites du shop (même source que la PWA) ; la
  // liaison est persistée côté serveur dans pwa_client_office (partagé PWA⇄WS).
  /* Ouvre l'écran, sans rien précharger : la liste des bureaux ne se feuillette
     pas, elle se cherche. Charger l'inventaire à l'ouverture, c'était l'exposer
     à quiconque ouvre son profil. */
  function openSitePicker() {
    setSiteErr(''); setSitePicked(''); setSiteList([]); setSiteBusy(false); setSiteStep('pick');
  }
  async function saveSite() {
    if (!sitePicked) { setSiteErr('Sélectionnez un bureau.'); return; }
    setSiteBusy(true); setSiteErr('');
    const r = await window.WSAuth.setOfficeSite(sitePicked);
    setSiteBusy(false);
    if (r.ok && r.user) {
      if (typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
      setSiteStep('idle');
    } else {
      setSiteErr(r.error || 'Échec de la liaison.');
    }
  }
  /* Déliement — opération UNIQUE. Les deux boutons « Délier ce bureau » de cet
     écran appelaient deux choses différentes : celui-ci supprimait la liaison
     PWA, l'autre vidait client.office_id. Or le serveur résout le bureau par
     une chaîne de replis : effacer une source laissait l'autre en fournir un
     AUTRE aussitôt, et le client croyait à une résurrection. POST /auth/office
     avec un site vide coupe désormais les deux d'un coup.
     L'échec était muet — pas de branche else : on cliquait, l'écran avançait,
     et le bureau restait rattaché en base sans un mot. */
  async function doUnlink() {
    setSiteBusy(true); setOfficeErr('');
    const r = await window.WSAuth.setOfficeSite(null);
    setSiteBusy(false);
    if (!r || !r.ok) {
      setOfficeErr((r && r.error) || 'Déliement impossible — le bureau est toujours rattaché.');
      return false;
    }
    if (r.user && typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
    return true;
  }
  async function unlinkSite() { await doUnlink(); }

  function startUnplug() { setOfficeStep('confirm'); setOfficeErr(''); }
  async function confirmUnplug() {
    if (await doUnlink()) setOfficeStep('ask');
  }
  function chooseLinkAnother() {
    setPickedOfficeId(''); setOfficeErr('');
    setOfficeStep('pick');
    if (officeShopId) loadApprovedOffices();
  }
  function chooseDone() {
    setOfficeStep('idle');
  }
  async function confirmPick() {
    if (!pickedOfficeId) { setOfficeErr('Sélectionnez un bureau.'); return; }
    const r = await persistPartial({ officeId: pickedOfficeId });
    if (r && r.ok === false) { setOfficeErr(r.error || 'Rattachement refusé par le serveur.'); return; }
    setOfficeErr(''); setOfficeStep('idle');
  }
  function setNewOfficeField(k, v) { setNewOffice((f) => ({ ...f, [k]: v })); setOfficeErr(''); }
  // "My office isn't listed" → ask the franchise to contact it (no office is
  // created/linked; the franchise handles it). Only name + one contact needed.
  async function submitContactRequest() {
    const nameOk = String(newOffice.name || '').trim();
    const contactOk = ['phone', 'email', 'address'].some((k) => String(newOffice[k] || '').trim());
    if (!nameOk) { setOfficeErr('Indiquez le nom du bureau ou de la société.'); return; }
    if (!contactOk) { setOfficeErr('Indiquez au moins un moyen de contact (téléphone, e-mail ou adresse).'); return; }
    if (!window.WSOffices) { setOfficeErr('Service indisponible.'); return; }
    setOfficeBusy(true); setOfficeErr('');
    try {
      // La demande va à la boutique où le client se trouve : sa boutique
      // préférée si elle est renseignée, sinon celle qu'il consulte. Sans ce
      // repli, un client sans boutique préférée voyait sa demande atterrir dans
      // le back-office d'une AUTRE boutique (celle de sa fiche ERP).
      const r = await window.WSOffices.contactFranchise({
        officeName: newOffice.name, phone: newOffice.phone, email: newOffice.email, address: newOffice.address,
        shopId: officeShopId, requestedBy: user.email,
      });
      if (r && r.ok === false) { setOfficeErr(r.error || 'Échec de l\'envoi.'); return; }
      setNewOffice({ name: '', vat: '', address: '', postalCode: '', city: '', contact: '', email: '', phone: '', preferredShopId: '' });
      setOfficeStep('sent');
    } catch (e) {
      setOfficeErr('Échec de l\'envoi. Réessayez.');
    } finally {
      setOfficeBusy(false);
    }
  }

  // Preferred-shop change. Saves to profile AND notifies the shell so the
  // active shop can switch (with the cart-preserving rules in ShopFrame).
  function changePreferredShop(nextId) {
    persistPartial({ preferredShopId: nextId || null });
    if (nextId && typeof onChangePreferredShop === 'function') {
      onChangePreferredShop(nextId, { fromProfile: true });
    }
  }
  async function checkVat() {
    setVies({ status: 'loading', message: '' });
    // Comme la PWA : la vérification VIES est faite ET persistée côté serveur
    // (raison sociale + adresse + verified_at sur la fiche client partagée).
    // Repli sur la simple vérification WSVies (remplissage local) hors ligne.
    if (window.WSAuth && typeof window.WSAuth.billingVerify === 'function' && window.WSAuth.endpoint) {
      const rv = await window.WSAuth.billingVerify({ vat: form.invoice.vat, country: form.invoice.country });
      if (rv.ok && rv.user) {
        if (typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...rv.user }); // resync du formulaire via l'effet [user]
        setVies({ status: 'ok', message: 'TVA validée par VIES' });
        return;
      }
      if (rv.error) { setVies({ status: rv.error.code || 'invalid', message: rv.error.message || 'Échec de validation' }); return; }
    }
    if (!window.WSVies) { setVies({ status: 'unavailable', message: 'Service VIES indisponible.' }); return; }
    const r = await window.WSVies.check({ vat: form.invoice.vat, country: form.invoice.country });
    if (r.valid && r.data) {
      setForm((f) => ({
        ...f,
        invoice: {
          ...f.invoice,
          vat: r.data.vat,
          country: r.data.country || f.invoice.country,
          name: r.data.name || f.invoice.name,
          address: r.data.address || f.invoice.address,
          postalCode: r.data.postalCode || f.invoice.postalCode,
          city: r.data.city || f.invoice.city,
        },
      }));
      setVies({ status: 'ok', message: 'TVA validée par VIES' });
    } else {
      setVies({ status: r.error?.code || 'invalid', message: r.error?.message || 'Échec de validation' });
    }
  }
  async function saveProfile(e) {
    e.preventDefault();
    const updated = { ...user, ...form };
    if (typeof onUpdateUser === 'function') onUpdateUser(updated);
    if (window.WSI18n && window.WSI18n.setCustomer) {
      const existing = window.WSI18n.getCustomer() || {};
      window.WSI18n.setCustomer({ ...existing, ...updated });
    }
    // Persist to the backend (customer profile) when the API is wired.
    // « Enregistré » ne s'affiche QUE si le serveur a accepté — l'ancien code
    // le montrait toujours, même sur un rejet (400/500 avalé) : perte
    // silencieuse. On applique aussi l'état renvoyé par le serveur, pas
    // l'optimiste local.
    if (window.WSAuth && typeof window.WSAuth.updateMe === 'function' && window.WSAuth.endpoint) {
      try {
        const r = await window.WSAuth.updateMe({
          firstName: form.firstName, lastName: form.lastName,
          phone: form.phone, postalCode: form.postalCode, locality: form.locality,
        });
        if (r && r.ok) {
          if (r.user && typeof onUpdateUser === 'function') onUpdateUser({ ...user, ...r.user });
          setProfileErr(''); setSavedFlash(true); setTimeout(() => setSavedFlash(false), 1800);
        } else {
          setProfileErr((r && r.error) || 'Enregistrement refusé par le serveur.');
        }
      } catch (e) {
        setProfileErr('Serveur injoignable — modifications NON enregistrées.');
      }
    } else {
      setProfileErr('Service de compte indisponible — modifications NON enregistrées.');
    }
  }
  return (
    <ModalShell onClose={onClose} narrow>
      <p className="ws-modal__eyebrow">{t('auth.myAccount')}</p>
      <h2 className="ws-modal__title">{tRich(t, 'acc.hello', { name: form.firstName || user.firstName })}</h2>
      <p className="ws-modal__lede">{user.email}</p>

      {/* Onglets — positions dérivées d'ACCOUNT_TABS, actif persisté. */}
      <div className="ws-toggle" role="tablist" aria-label={t('acc.sectionsAria')}>
        {visibleTabs.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={activeTab === t.key}
            className={'ws-toggle__opt' + (activeTab === t.key ? ' is-active' : '')}
            onClick={() => selectTab(t.key)}>{t.label}</button>
        ))}
      </div>

      {activeTab === 'achats' && <AccountPurchases user={user} />}
      {activeTab === 'fidelite' && <FideliteShell />}

      {activeTab === 'profil' && <>
      {form.fidelityApp?.active && (
        <aside className="ws-fidinfo" role="note" aria-label={t('fid.infoAria')}>
          <div className="ws-fidinfo__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
              <rect x="6" y="2.5" width="12" height="19" rx="2.5"/>
              <path d="M10 18.5h4"/>
              <path d="M9.5 6h5"/>
            </svg>
          </div>
          <div className="ws-fidinfo__body">
            <div className="ws-fidinfo__title">{t('fid.allInApp')}</div>
            <p className="ws-fidinfo__lede">
              {tRich(t, 'fid.managedIn')}
            </p>
            <ul className="ws-fidinfo__list">
              <li>{tRich(t, 'fid.bullet1')}</li>
              <li>{tRich(t, 'fid.bullet2')}</li>
              <li>{tRich(t, 'fid.bullet3')}</li>
            </ul>
            <p className="ws-fidinfo__foot">
              Pour limiter les e-mails, nous privilégions désormais les notifications de l'application.
            </p>
          </div>
        </aside>
      )}

      <form className="ws-acc__section" onSubmit={saveProfile}>
        <div className="ws-acc__section-h">{t('acc.contact')}</div>
        <div className="ws-acc__form">
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">{t('form.firstName')}</span>
            <input type="text" className="ws-acc__input" value={form.firstName}
              onChange={(e) => setField('firstName', e.target.value)} placeholder={t('form.firstName')} />
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">{t('form.lastName')}</span>
            <input type="text" className="ws-acc__input" value={form.lastName}
              onChange={(e) => setField('lastName', e.target.value)} placeholder={t('form.lastName')} />
          </label>
          <label className="ws-acc__field ws-acc__field--full">
            <span className="ws-acc__field-label">{t('form.emailLong')}</span>
            {/* LECTURE SEULE, honnêtement : le champ était éditable mais la
                sauvegarde ne l'envoyait jamais — « ✓ Enregistré » mentait, et
                l'email sert d'identifiant (connexion, rattachement bureau). */}
            <input type="email" className="ws-acc__input" value={form.email} readOnly disabled
              title={t('acc.emailIsLogin')} />
            <span style={{ font: '400 10.5px var(--font-ui, sans-serif)', color: 'var(--color-text-muted, #8a7c6e)' }}>{t('acc.emailIsLoginShort')}</span>
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">{t('form.phone')}</span>
            <input type="tel" className="ws-acc__input" value={form.phone}
              onChange={(e) => setField('phone', e.target.value)} placeholder="+32 ..." />
          </label>
          <CpField variant="acc" cp={form.postalCode} locality={form.locality}
            onCp={(v) => setField('postalCode', v)} onLocality={(v) => setField('locality', v)}/>
        </div>

        <div className="ws-acc__form-foot">
          <button type="submit" className="ws-cta">{t('common.save2')}</button>
          {savedFlash && <span className="ws-acc__saved">✓ {t('common.saved')}</span>}
          {profileErr && <span className="ws-acc__saved" style={{ color: 'var(--color-primary, #8d1d2c)' }}>{profileErr}</span>}
        </div>
      </form>

      {/* ── Sociétés de facturation ─────────────────────────────────────
          Données IMPORTÉES (VIES ou saisie encadrée à l'AJOUT) — jamais de
          champs de saisie pour une société existante : présentation en
          lecture seule, règle appliquée aussi côté serveur (PATCH /auth/me
          ignore ces clés). Retrait = archivage, la fiche reste en base. */}
      <div className="ws-acc__section">
        <div className="ws-acc__section-h">{t('acc.companies')}</div>
        {(user.companyClientId || user.company) && (
          <div className="ws-acc__card ws-acc__card--ok">
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('co2.legalName')}</span><span className="ws-acc__v">{user.invoice?.name || user.company}</span></div>
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('co2.vat')}</span><span className="ws-acc__v">{user.invoice?.vat || 'Non assujettie'}</span></div>
            {(user.invoice?.address || user.invoice?.city) && (
              <div className="ws-acc__card-row"><span className="ws-acc__k">{t('co2.address')}</span>
                <span className="ws-acc__v">{[user.invoice?.address, [user.invoice?.postalCode, user.invoice?.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')}</span></div>
            )}
            {user.invoice?.viesVerified && <div className="ws-acc__badge">{t('co2.viesOk')}</div>}
            <p className="ws-acc__hint">{t('co2.defaultHint')}</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => { setCompanyStep('vies'); setCompanyErr(''); }} disabled={companyBusy}>{t('co2.viesRecheck')}</button>
              <button type="button" className="ws-acc__unplug" onClick={removeCompany} disabled={companyBusy}>{t('common.remove')}</button>
            </div>
          </div>
        )}
        {!(user.companyClientId || user.company) && companyStep === 'idle' && (
          <div className="ws-acc__card ws-acc__card--empty">
            <p className="ws-acc__note">{t('co2.none')}</p>
            <button className="ws-cta ws-cta--block" onClick={() => { setCompanyStep('vies'); setCompanyErr(''); }}>{t('co2.add')}</button>
          </div>
        )}
        {companyStep === 'vies' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>{t('co2.addByVat')}</div>
            <div className="ws-acc__form ws-acc__form--vat">
              <label className="ws-acc__field ws-acc__field--country">
                <span className="ws-acc__field-label">{t('co2.country')}</span>
                <select className="ws-acc__input" value={addVat.country}
                  onChange={(e) => setAddVat((v) => ({ ...v, country: e.target.value }))}>
                  {['BE','NL','FR','DE','LU','IT','ES','AT','PT','IE','FI','SE','DK','PL','CZ'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </label>
              <label className="ws-acc__field ws-acc__field--vat">
                <span className="ws-acc__field-label">{t('co2.vatNumber')}</span>
                <input type="text" className="ws-acc__input" value={addVat.vat}
                  onChange={(e) => setAddVat((v) => ({ ...v, vat: e.target.value }))}
                  placeholder="0123456789" autoComplete="off" />
              </label>
            </div>
            <p className="ws-acc__hint">{t('co2.viesImport')}</p>
            {companyErr && <p className="ws-acc__vat-msg ws-acc__vat-msg--err">⚠ {companyErr}</p>}
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => { setCompanyStep('idle'); setCompanyErr(''); }}>{t('common.cancel2')}</button>
              <button type="button" className="ws-fid__cancel" onClick={() => { setCompanyStep('novat'); setCompanyErr(''); }}>{t('co2.noVat')}</button>
              <button type="button" className="ws-cta" disabled={companyBusy || !addVat.vat} onClick={addByVies}>
                {companyBusy ? 'Vérification…' : 'Vérifier et importer'}
              </button>
            </div>
          </div>
        )}
        {companyStep === 'novat' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>{t('co2.addNoVat')}</div>
            <div className="ws-acc__form">
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">{t('co2.legalName')}</span>
                <input type="text" className="ws-acc__input" value={addNo.name}
                  onChange={(e) => setAddNo((v) => ({ ...v, name: e.target.value }))} />
              </label>
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">{t('co2.address')}</span>
                <input type="text" className="ws-acc__input" value={addNo.address}
                  onChange={(e) => setAddNo((v) => ({ ...v, address: e.target.value }))} />
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">{t('co2.zip')}</span>
                <input type="text" className="ws-acc__input" value={addNo.postalCode}
                  onChange={(e) => setAddNo((v) => ({ ...v, postalCode: e.target.value }))} />
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">{t('co2.city')}</span>
                <input type="text" className="ws-acc__input" value={addNo.city}
                  onChange={(e) => setAddNo((v) => ({ ...v, city: e.target.value }))} />
              </label>
            </div>
            <p className="ws-acc__hint">{t('co2.noVatHint')}</p>
            {companyErr && <p className="ws-acc__vat-msg ws-acc__vat-msg--err">⚠ {companyErr}</p>}
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => { setCompanyStep('idle'); setCompanyErr(''); }}>{t('common.cancel2')}</button>
              <button type="button" className="ws-cta" disabled={companyBusy || !addNo.name} onClick={addNoVat}>{t('common.add')}</button>
            </div>
          </div>
        )}
      </div>

      {/* ── Sécurité ── */}
      <div className="ws-acc__section">
        <div className="ws-acc__section-h">{t('acc.security')}</div>
        <div className="ws-acc__form">
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">{t('acc.newPassword')}</span>
            <input type="password" className="ws-acc__input" value={pw1}
              onChange={(e) => setPw1(e.target.value)} autoComplete="new-password" />
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">{t('acc.confirmPassword')}</span>
            <input type="password" className="ws-acc__input" value={pw2}
              onChange={(e) => setPw2(e.target.value)} autoComplete="new-password" />
          </label>
        </div>
        {pwMsg && (
          <p className={'ws-acc__vat-msg ' + (pwMsg.ok ? 'ws-acc__vat-msg--ok' : 'ws-acc__vat-msg--err')}>
            {pwMsg.ok ? '✓ ' : '⚠ '}{pwMsg.text}
          </p>
        )}
        <div className="ws-acc__form-foot">
          <button type="button" className="ws-cta" disabled={pwBusy || pw1.length < 6 || pw1 !== pw2} onClick={savePassword}>
            Changer le mot de passe
          </button>
        </div>
      </div>

      <div className="ws-acc__section">
        <div className="ws-acc__section-h">{t('acc.prefs')}</div>

        {/* Preferred shop ----------------------------------------------- */}
        <div className="ws-acc__row">
          <div className="ws-acc__row-body">
            <div className="ws-acc__row-title">{t('acc.prefShop')}</div>
            <div className="ws-acc__row-sub">
              Détermine votre boutique par défaut à la connexion. Conditionne aussi votre éligibilité à la livraison au bureau et les créneaux disponibles.
            </div>
            <div className="ws-acc__select-row">
              <select
                className="ws-acc__input"
                value={form.preferredShopId || ''}
                onChange={(e) => changePreferredShop(e.target.value || null)}
                aria-label={t('acc.prefShop')}
              >
                <option value="">{t('acc.noPrefShop')}</option>
                {(shops || []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name} · {s.city}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {/* Fidelity mobile app ------------------------------------------ */}
        <div className="ws-acc__row">
          <div className="ws-acc__row-body">
            <div className="ws-acc__row-title">{t('acc.loyaltyApp')}</div>
            <div className="ws-acc__row-sub">
              Liez votre compte web à l'application mobile L'Atelier pour synchroniser vos points et vos avantages.
            </div>
            {form.fidelityApp?.active ? (
              <span className="ws-acc__row-status">Liée · {form.fidelityApp.linkedAt ? new Date(form.fidelityApp.linkedAt).toLocaleDateString('fr-BE') : 'récemment'}</span>
            ) : (
              <span className="ws-acc__row-status ws-acc__row-status--off">{t('acc.notLinked')}</span>
            )}
          </div>
          <label className="ws-acc__toggle" aria-label={t('acc.enableLoyalty')}>
            <input
              type="checkbox"
              checked={!!form.fidelityApp?.active}
              onChange={(e) => toggleFidelity(e.target.checked)}
            />
            <span className="ws-acc__toggle-track" aria-hidden="true"><span className="ws-acc__toggle-thumb"/></span>
          </label>
        </div>
      </div>

      <FidelityLinkPanel
        open={fidOpen}
        user={user}
        onClose={() => setFidOpen(false)}
      />

      <div className="ws-acc__section">
        <div className="ws-acc__section-h">{t('acc.myOffice')}</div>

        {officeStep === 'idle' && status === 'active' && (
          <div className="ws-acc__card ws-acc__card--ok">
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.office')}</span><span className="ws-acc__v">{office.name}</span></div>
            {office.address && <div className="ws-acc__card-row"><span className="ws-acc__k">{t('co2.address')}</span><span className="ws-acc__v">{office.address}</span></div>}
            {tour && tour.shopId && (shops || []).find((s) => s.id === tour.shopId) && (
              <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.shop')}</span><span className="ws-acc__v">{(shops || []).find((s) => s.id === tour.shopId).name}</span></div>
            )}
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.tour')}</span><span className="ws-acc__v">{tour.name} · {tour.window}</span></div>
            <div className="ws-acc__btnrow">
              <span className="ws-acc__badge ws-acc__badge--ok">{t('off.deliveryActive')}</span>
              <button type="button" className="ws-acc__unplug" onClick={startUnplug}>{t('off.unlink')}</button>
            </div>
          </div>
        )}

        {officeStep === 'idle' && status === 'pending' && (
          <div className="ws-acc__card ws-acc__card--pending">
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.office')}</span><span className="ws-acc__v">{office.name}</span></div>
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.contact')}</span><span className="ws-acc__v">{office.contact}</span></div>
            <div className="ws-acc__badge ws-acc__badge--pending">{t('off.pendingValidation')}</div>
            <p className="ws-acc__note">{t('off.pendingNote')}</p>
            <button type="button" className="ws-acc__unplug" onClick={startUnplug}>{t('off.unlink')}</button>
          </div>
        )}

        {/* Bureau lié côté PWA = un SITE de livraison (ws_office_delivery_sites),
            parfois sans entreprise ws_offices associée : on l'affiche quand même,
            avec les mêmes données que la carte bureau de la PWA. */}
        {officeStep === 'idle' && siteStep === 'idle' && status === 'unlinked' && user.officeSite && (
          <div className="ws-acc__card ws-acc__card--ok">
            <div className="ws-acc__card-row"><span className="ws-acc__k">{t('off.office')}</span><span className="ws-acc__v">{user.officeSite.name}</span></div>
            {user.officeSite.address && <div className="ws-acc__card-row"><span className="ws-acc__k">{t('co2.address')}</span><span className="ws-acc__v">{user.officeSite.address}</span></div>}
            <div className={'ws-acc__badge' + (Number(user.officeSite.active) ? '' : ' ws-acc__badge--pending')}>
              {Number(user.officeSite.active) ? 'Validé' : 'En attente de validation'}
            </div>
            <p className="ws-acc__note">{t('off.defaultAtCheckout')}</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={openSitePicker} disabled={siteBusy}>{t('off.change')}</button>
              <button type="button" className="ws-acc__unplug" onClick={unlinkSite} disabled={siteBusy}>{t('off.unlink')}</button>
            </div>
          </div>
        )}

        {officeStep === 'idle' && siteStep === 'idle' && status === 'unlinked' && !user.officeSite && (
          <div className="ws-acc__card ws-acc__card--empty">
            <p className="ws-acc__note">{t('off.noneLinked')}</p>
            <button className="ws-cta ws-cta--block" onClick={openSitePicker}>{t('off.link')}</button>
            {/* Le rattachement à une ENTREPRISE (ws_offices) n'était atteignable
                qu'après avoir délié un bureau existant : un client sans bureau ne
                pouvait ni se rattacher, ni demander l'ajout du sien. */}
            <button type="button" className="ws-acc__addlink" onClick={chooseLinkAnother}>{t('off.joinCompany')}</button>
            <button type="button" className="ws-acc__unplug" onClick={() => window.open('/landing/livraison-bureau.html', '_blank', 'noopener')}>Ma zone est-elle desservie&nbsp;?</button>
          </div>
        )}


        {/* Sélecteur de bureau (sites de livraison du shop — même liste que la PWA) */}
        {siteStep === 'pick' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>
              Bureaux de {((shops || []).find((s) => s.id === ((user.officeSite && user.officeSite.shopId) || form.preferredShopId || currentShopId)) || {}).name || 'votre boutique'}
            </div>
            {/* Plus de chargement de liste à l'ouverture : le serveur ne répond
                qu'à une recherche. L'affichage ne dépend donc plus d'un
                inventaire préchargé — il dépend de ce que le client tape. */}
            <OfficeSearchPicker
              chercher={(q) => (window.WSAuth && window.WSAuth.listOfficeSites)
                ? window.WSAuth.listOfficeSites({
                    shopId: (user.officeSite && user.officeSite.shopId) || form.preferredShopId || currentShopId,
                    q }) : []}
              value={sitePicked}
              onPick={(id) => { setSitePicked(id); setSiteErr(''); }}
              label="Choisir un bureau de livraison"/>
            {siteErr && <p className="ws-acc__vat-msg ws-acc__vat-msg--err">⚠ {siteErr}</p>}
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setSiteStep('idle')}>{t('common.cancel2')}</button>
              <button type="button" className="ws-cta" onClick={saveSite} disabled={siteBusy || !sitePicked}>{t('off.linkThis')}</button>
            </div>
          </div>
        )}

        {officeStep === 'confirm' && (
          <div className="ws-acc__card ws-acc__card--warn">
            <p className="ws-acc__note"><strong>{t('off.unlinkQ')}</strong> La livraison au bureau sera désactivée jusqu'à ce que vous liiez un nouveau bureau. Votre compte reste actif.</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('idle')}>{t('common.cancel2')}</button>
              <button type="button" className="ws-cta" onClick={confirmUnplug}>{t('common.confirm')}</button>
            </div>
          </div>
        )}

        {officeStep === 'ask' && (
          <div className="ws-acc__card">
            <p className="ws-acc__note"><strong>{t('off.unlinked')}</strong> {t('off.linkAnotherQ')}</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={chooseDone}>{t('common.no')}</button>
              <button type="button" className="ws-cta" onClick={chooseLinkAnother}>{t('common.yes')}</button>
            </div>
          </div>
        )}

        {officeStep === 'pick' && !officeShopId && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>{t('off.pick')}</div>
            <p className="ws-acc__hint">{tRich(t, 'off.pickShopFirst')}</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('idle')}>{t('common.close2')}</button>
            </div>
          </div>
        )}

        {officeStep === 'pick' && officeShopId && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>
              Bureaux de {((shops || []).find((s) => s.id === officeShopId) || {}).name || 'votre boutique'}
            </div>
            {officeBusy && <p className="ws-acc__hint">{t('common.loading2')}</p>}
            {!officeBusy && approvedOffices.length === 0 && (
              <p className="ws-acc__hint">{t('off.noneValidated')}</p>
            )}
            {!officeBusy && approvedOffices.length > 0 && (
              <OfficeSearchPicker items={approvedOffices} value={pickedOfficeId}
                onPick={(id) => { setPickedOfficeId(id); setOfficeErr(''); }}
                label="Bureaux validés"/>
            )}
            {officeErr && <p className="ws-form__err">{officeErr}</p>}
            <button type="button" className="ws-acc__addlink" onClick={() => { setOfficeErr(''); setOfficeStep('add'); }}>{t('off.notInList')}</button>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('idle')}>{t('common.cancel2')}</button>
              <button type="button" className="ws-cta" onClick={confirmPick} disabled={!pickedOfficeId}>{t('common.confirm')}</button>
            </div>
          </div>
        )}

        {officeStep === 'add' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>{t('off.notInListQ')}</div>
            <p className="ws-acc__hint">{tRich(t, 'off.reqHint')}</p>
            <div className="ws-acc__grid">
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">{t('off.reqName')}</span>
                <input className="ws-acc__input" value={newOffice.name} onChange={(e) => setNewOfficeField('name', e.target.value)} placeholder={t('off.phName')}/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">{t('form.phone')}</span>
                <input className="ws-acc__input" value={newOffice.phone} onChange={(e) => setNewOfficeField('phone', e.target.value)} placeholder="+32 …"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">{t('form.emailLong')}</span>
                <input type="email" className="ws-acc__input" value={newOffice.email} onChange={(e) => setNewOfficeField('email', e.target.value)} placeholder={t('off.phEmail')}/>
              </label>
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">{t('co2.address')}</span>
                <input className="ws-acc__input" value={newOffice.address} onChange={(e) => setNewOfficeField('address', e.target.value)} placeholder={t('off.reqAddress')}/>
              </label>
            </div>
            <p className="ws-acc__hint">{t('off.reqContactHint')}</p>
            {officeErr && <p className="ws-form__err">{officeErr}</p>}
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => { setOfficeErr(''); setOfficeStep('pick'); }}>{t('common.back2')}</button>
              <button type="button" className="ws-cta" onClick={submitContactRequest} disabled={officeBusy}>{officeBusy ? 'Envoi…' : 'Envoyer à votre Atelier'}</button>
            </div>
          </div>
        )}

        {officeStep === 'sent' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>{t('off.reqSent')}</div>
            <p className="ws-acc__hint">{t('off.reqThanks')}</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-cta" onClick={() => setOfficeStep('idle')}>{t('common.close2')}</button>
            </div>
          </div>
        )}
      </div>

      {linkedCompanies.length > 0 && (
        <div className="ws-acc__section">
          <div className="ws-acc__section-h">{t('acc.b2bAccounts')}</div>
          {linkedCompanies.map((c) => (
            <div key={c.id} className="ws-acc__card ws-acc__card--ok">
              <div className="ws-acc__card-row"><span className="ws-acc__k">{t('acc.company')}</span><span className="ws-acc__v">{c.name}</span></div>
              {c.vat ? <div className="ws-acc__card-row"><span className="ws-acc__k">TVA</span><span className="ws-acc__v">{c.vat}</span></div> : null}
              <div className="ws-acc__card-row"><span className="ws-acc__k">{t('acc.billing')}</span><span className="ws-acc__v">{c.deferredBilling ? 'Sur compte (différée)' : 'Paiement direct'}</span></div>
            </div>
          ))}
        </div>
      )}

      {window.LangMenu && (
        <div className="ws-acc__section">
          <div className="ws-acc__section-h">{t('acc.language')}</div>
          <window.LangMenu />
        </div>
      )}
      </>}

      <div className="ws-acc__foot">
        <button className="ws-acc__logout" onClick={() => { onLogout(); onClose(); }}>{t('acc.signout')}</button>
      </div>
    </ModalShell>
  );
}

// =========================================================================
// FIDELITY APP LINK — QR modal
// Shown when the user toggles the fidelity-app setting from OFF to ON.
// =========================================================================
function FidelityQR({ payload }) {
  const { t } = wsUseT();
  // Vrai QR scannable (window.QR, fourni par qr.jsx). Rendu « classique » —
  // modules pleins, sombres, marge complète : le style brandé (points ronds
  // bordeaux, finders arrondis) n'est pas lu par tous les scanners.
  const svg = React.useMemo(() => {
    try {
      if (payload && window.QR && typeof window.QR.render === 'function') {
        return window.QR.render(payload, { size: 240, margin: 4, branded: false, color: '#111111' });
      }
    } catch (_) {}
    return null;
  }, [payload]);
  if (svg) {
    return (
      <div className="ws-fid__qr" role="img" aria-label={t('fid.qrAlt')}
        dangerouslySetInnerHTML={{ __html: svg }} />
    );
  }
  // Encodeur indisponible : lien direct plutôt qu'un faux QR illisible.
  return (
    <p className="ws-fid__hint">
      QR indisponible — ouvrez directement{' '}
      <a href={payload} target="_blank" rel="noopener noreferrer">{payload}</a>
    </p>
  );
}

function FidelityLinkPanel({ open, user, onClose }) {
  const { t } = wsUseT();
  // Modale QR-only : elle montre l'adresse du PWA (user.fidelityApp.installUrl,
  // servi par le backend depuis ws_param.pwa_url). L'activation réelle de l'app
  // fidélité se fait DANS le PWA (il écrit fidelity_active en base) ; la boutique
  // ne fait que refléter cet état — pas de fausse liaison locale ici.
  const payload = React.useMemo(() => {
    if (!open || !user) return '';
    return (user.fidelityApp && user.fidelityApp.installUrl) || '';
  }, [open, user?.fidelityApp?.installUrl]);
  if (!open) return null;
  return (
    <div className="ws-fidpanel" role="dialog" aria-modal="true" aria-label={t('fid.title')}>
      <div className="ws-fidpanel__head">
        <span className="ws-fidpanel__eyebrow">{t('acc.loyaltyApp')}</span>
        <button type="button" className="ws-fidpanel__close" aria-label={t('common.close2')} onClick={onClose}>×</button>
      </div>
      <div className="ws-fid ws-fid--inline">
        <div className="ws-fid__qrwrap ws-fid__qrwrap--sm">
          {payload
            ? <FidelityQR payload={payload}/>
            : <p className="ws-fid__hint">{t('fid.noLink')}</p>}
        </div>
        <ol className="ws-fid__steps ws-fid__steps--sm">
          <li><span className="ws-fid__step-n">1</span> {t('fid.scan')}</li>
          <li><span className="ws-fid__step-n">2</span> {tRich(t, 'fid.step2')}</li>
          <li><span className="ws-fid__step-n">3</span> {t('fid.signinSync')}</li>
        </ol>
        {payload && (
          <a className="ws-fid__link" href={payload} target="_blank" rel="noopener noreferrer">{t('fid.open')}</a>
        )}
        <div className="ws-fid__foot ws-fid__foot--sm">
          <button type="button" className="ws-cta ws-fid__confirm" onClick={onClose}>{t('common.close2')}</button>
        </div>
      </div>
    </div>
  );
}

// OfficeRequestModal removed — superseded by AccountModal's inline office add flow

// =========================================================================
// CHECKOUT — slide-over 3-step wizard (Coordonnées · Créneau · Paiement)
// Two flows: Click & Collect (logged-in) / Office Shop (delivery, logged-in).
// Guest collect → forced login/register before continuing.
// =========================================================================
// Slots now come from WSCalendar.listSlots(). The deprecated stub was removed.
//
// Moyens de paiement : SOURCE UNIQUE = le serveur (/payment-methods), qui
// applique les règles réelles (boutique × profil guest/registered/company, et
// paiement différé selon ws_offices.deferred_billing_enabled). Les listes
// codées en dur (bancontact/visa/apple, « paiement différé ») ont été
// SUPPRIMÉES : elles proposaient au client des moyens de paiement qui ne sont
// pas forcément configurés pour sa boutique. Sans réponse du serveur la liste
// reste VIDE et l'étape Paiement affiche une erreur — jamais d'option inventée.
function usePaymentMethods(shopId, mode, deliveryFeeResult, profile, companyId) {
  const [methods, setMethods] = React.useState([]);
  React.useEffect(() => {
    let alive = true;
    if (!(window.WSPayments && window.WSPayments.endpoint)) { setMethods([]); return () => { alive = false; }; }
    // `mode` était reçu par le hook et listé dans ses dépendances, mais jamais
    // transmis : la liste ne dépendait donc pas du mode, et « paiement en
    // boutique » apparaissait sur une livraison.
    window.WSPayments.list({ shopId, profile: profile || 'guest', companyId, mode })
      .then((m) => {
        if (!alive) return;
        setMethods(Array.isArray(m) ? m.map((x) => ({ id: x.method, label: x.label || x.method, family: x.family || x.method, sub: '' })) : []);
      })
      .catch(() => { if (alive) setMethods([]); });
    return () => { alive = false; };
  }, [shopId, mode, deliveryFeeResult && deliveryFeeResult.payment_type, profile, companyId]);
  return methods;
}

function CheckoutWizard({ open, onClose, shop, mode, basket, user, onLogin, onPlaced,
                          voucherInput, setVoucherInput, voucherApplied, setVoucherApplied,
                          office, tour, date,
                          deliveryFeeResult, deliveryFeeErr, officeSites, selectedSiteId, setSelectedSiteId }) {
  const { t } = wsUseT();
  const [step, setStep] = useState(1);
  const [forceAuth, setForceAuth] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payErr, setPayErr] = useState(null);
  // Clé d'idempotence : STABLE tant que le tunnel reste ouvert, renouvelée à
  // chaque réouverture. Deux clics — ou un renvoi après une erreur réseau —
  // portent donc la même clé, et le serveur renvoie la commande déjà créée au
  // lieu d'en enregistrer une seconde.
  const newPayKey = () => 'ws-' + Date.now().toString(36) + '-' +
    Math.random().toString(36).slice(2, 10);
  const [payKey, setPayKey] = useState(newPayKey);

  // Guest contact (collect only)
  const [contact, setContact] = useState({ firstName: '', lastName: '', email: '', phone: '' });

  // Slot
  const [slot, setSlot] = useState(null);

  // Office invoice toggle
  const [invoice, setInvoice] = useState(false);
  const [vat, setVat] = useState('');

  // Payment — reset to 'deferred' for deferred sites, 'bancontact' otherwise
  // Aucun moyen par défaut inventé : « bancontact » n'existe pas côté serveur
  // (les méthodes réelles sont stripe / shop / deferred). La sélection est
  // posée par l'effet dès que /payment-methods a répondu.
  const defaultPayment = (deliveryFeeResult && deliveryFeeResult.payment_type === 'deferred') ? 'deferred' : '';
  const [payment, setPayment] = useState(defaultPayment);

  // B2B « commander pour une entreprise » + remarque + PO (facturation pro).
  const [orderNote, setOrderNote] = useState('');
  const [poNumber, setPoNumber] = useState('');
  const [companies, setCompanies] = useState([]);
  const [companyId, setCompanyId] = useState('');
  const [onAccount, setOnAccount] = useState(false);
  useEffect(() => {
    let alive = true;
    const email = user && user.email;
    if (open && email && window.WSCompanies) {
      window.WSCompanies.list(email).then((cs) => { if (alive) setCompanies(cs || []); }).catch(() => {});
    } else setCompanies([]);
    return () => { alive = false; };
  }, [open, user && user.email]);
  // « Demander une facture nominative » coché → on sélectionne automatiquement la
  // société liée à l'utilisateur (companyClientId), sinon sa première société.
  // On n'écrase jamais un choix déjà fait.
  useEffect(() => {
    if (invoice && !companyId && companies.length) {
      const own = companies.find((c) => String(c.id) === String(user && user.companyClientId)) || companies[0];
      if (own) setCompanyId(String(own.id));
    }
  }, [invoice, companies]);
  // …et on PRÉ-REMPLIT les données B2B de la fiche utilisateur : le N° de TVA de la
  // société sélectionnée, sinon celui enregistré sur le compte (user.invoice.vat).
  // On n'écrase pas une saisie manuelle en cours.
  useEffect(() => {
    if (!invoice || vat) return;
    const c = companies.find((x) => String(x.id) === String(companyId));
    const preVat = (c && c.vat) || (user && user.invoice && user.invoice.vat) || '';
    if (preVat) setVat(preVat);
  }, [invoice, companyId, companies]);

  // Reset when reopened
  useEffect(() => {
    if (open) {
      setStep(1); setSlot(null); setInvoice(false); setVat(''); setForceAuth(false); setPaying(false); setPayErr(null);
      setPayKey(newPayKey());
      setOrderNote(''); setPoNumber(''); setCompanyId(''); setOnAccount(false);
      setPayment((deliveryFeeResult && deliveryFeeResult.payment_type === 'deferred') ? 'deferred' : '');
    }
  }, [open]);

  // Moyens de paiement : chargés ICI, AVANT tout return conditionnel — un hook
  // placé après « if (!open) return null » change le nombre de hooks entre deux
  // rendus, ce que React refuse (écran blanc à l'ouverture du tunnel).
  // Le profil est recalculé en ligne : companyId/user suffisent, et les valeurs
  // dérivées plus bas ne sont pas encore disponibles à ce point du composant.
  const paymentMethods = usePaymentMethods(
    shop && shop.id, mode, deliveryFeeResult,
    companyId ? 'company' : (user ? 'registered' : 'guest'),
    companyId || null);

  // Code cadeau « achat cumulé » appliqué (ajoute une ligne 0 € côté serveur).
  // DOIT rester AVANT le `return null` ci-dessous, et DANS ce composant : place
  // plus bas, ce hook n'était appelé que le tunnel ouvert — React comptait un
  // hook de plus a l'ouverture (erreur #310) et le paiement devenait
  // inaccessible.
  const [giftCode, setGiftCode] = useState(null);
  /* L'offre croisée : le panier la déduisait, le tunnel l'ignorait — le total
     REMONTAIT donc au passage au paiement. Même règle, même source (la règle
     serveur), aux deux endroits. Hook AVANT le `return null`, comme celui
     ci-dessus : un hook conditionnel casse le tunnel (React #310). */
  const [xRule, setXRule] = useState(null);
  React.useEffect(() => {
    if (window.WSPricing && typeof window.WSPricing.getCrossPortionRule === 'function') {
      window.WSPricing.getCrossPortionRule().then((r) => { if (r) setXRule(r); }).catch(() => {});
    }
  }, []);

  if (!open) return null;

  const voucherDiscount = voucherApplied && voucherApplied.ok ? voucherApplied.discount : 0;
  const deliveryFee = (mode === 'delivery' && deliveryFeeResult) ? (deliveryFeeResult.fee_amount || 0) : 0;
  const crossSavings = computeCrossPortionOffer(basket, xRule)?.savings || 0;
  // Même addition que le panier, et que le serveur (wsTotaux).
  const T = wsTotaux({ basket, shop, crossSavings, voucherDiscount, deliveryFee });
  const subtotal = T.sousTotal;
  const total = T.total;

  const isOffice = mode === 'delivery' && user && office;
  const isGuest = !user;
  // Client relié à un B2B ? (a au moins une société liée, ou un id société ERP).
  const isB2B = companies.length > 0 || !!(user && user.companyClientId);
  // Profil de paiement : société (companyId) > enregistré (user) > visiteur (guest).
  const checkoutProfile = companyId ? 'company' : (user ? 'registered' : 'guest');
  // Step 1 validity
  function step1Valid() {
    if (isOffice) return true;             // all read-only, valid
    if (user)    return true;              // collect logged-in: prefilled
    // E-mail FORMELLEMENT valide (il sert à la confirmation de commande) et
    // téléphone contenant au moins des chiffres : l'ancien test «vérité»
    // laissait passer une adresse malformée ou un téléphone sans chiffre,
    // refusés ou inexploitables ensuite.
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(contact.email || '').trim());
    const phoneOk = /\d{5,}/.test(String(contact.phone || '').replace(/\D/g, ''));
    return Boolean(contact.firstName && contact.lastName && emailOk && phoneOk);
  }
  function step2Valid() { return Boolean(slot); }
  // Étape 3 : un moyen de paiement doit être proposé ET choisi. Sans ce test, le
  // bouton « Payer » restait actif alors que l'écran affichait « moyens
  // indisponibles », et la commande partait avec un moyen vide.
  function step3Valid() { return paymentMethods.length > 0 && Boolean(payment); }


  async function handlePay() {
    // Livraison bureau sans frais résolus : on REFUSE au lieu de facturer 0 €
    // et de forcer un paiement immédiat à un bureau en facturation différée.
    if (mode === 'delivery' && deliveryFeeErr) { setPayErr(deliveryFeeErr); return; }
    setPaying(true); setPayErr(null);
    try {
      // Jour choisi au format YYYY-MM-DD LOCAL (isoOf évite le décalage UTC de
      // toISOString à minuit) : sans lui, ws_orders.delivery_date restait NULL
      // et une commande J+1 apparaissait sous « aujourd'hui » côté back-office.
      const isoFn = (window.WSAvailability || window.WSCalendar)?.isoOf
        || ((x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`);
      const deliveryDate = date instanceof Date ? isoFn(date) : (date || null);
      const payload = {
        shopId: shop && shop.id,
        requestKey: payKey,
        mode,
        deliveryDate,
        slot: typeof slot === 'object' && slot
          ? { slotId: slot.id, label: slot.label, date: deliveryDate }
          : { slotId: slot, label: slot, date: deliveryDate },
        basket: basket.map((l) => ({ productId: l.productId, qty: l.qty, portion: l.portion || null, note: l.note || null, options: l.options || [], bundleId: l.bundleId || null, bundleSlots: l.bundleSlots || {} })),
        voucher: voucherApplied && voucherApplied.ok ? voucherApplied.voucher.code : null,
        giftCode: giftCode || null,
        note: orderNote || null,
        companyId: companyId || null,
        onAccount: !!onAccount,
        customer: user ? { id: user.id, email: user.email, firstName: user.firstName, lastName: user.lastName, phone: user.phone || null, officeId: user.officeId || null } : { ...contact },
        payment: { method: payment },
        delivery: mode === 'delivery' && office ? {
          office_client_id:            office.id,
          office_delivery_site_id:     selectedSiteId || null,
          office_delivery_site_name:   deliveryFeeResult && deliveryFeeResult.site ? deliveryFeeResult.site.name : (office.address || null),
          address:                     deliveryFeeResult && deliveryFeeResult.site ? deliveryFeeResult.site.address : office.address,
          tournee_id:                  deliveryFeeResult && deliveryFeeResult.site ? deliveryFeeResult.site.tournee_id  : (office.tourId || null),
          tournee_stop_id:             deliveryFeeResult && deliveryFeeResult.site ? deliveryFeeResult.site.tournee_stop_id : null,
          payment_type:                deliveryFeeResult ? deliveryFeeResult.payment_type : 'immediate',
          delivery_fee_applied:        deliveryFee > 0,
          delivery_fee_amount:         deliveryFee,
          free_delivery_minimum:       deliveryFeeResult ? (deliveryFeeResult.free_delivery_minimum || 0) : 0,
          delivery_mode:               'office_delivery',
        } : null,
        total,
        invoice: invoice ? { requested: true, vat, po: poNumber || null, note: orderNote || null } : null,
      };
      // Une commande n'est « passée » que si le serveur l'a ENREGISTRÉE. L'ancien
      // repli renvoyait un faux succès (orderId 'ord-demo') quand le module de
      // commande était absent : le client voyait une confirmation pour une
      // commande qui n'existait nulle part. Sans module → erreur, jamais de
      // confirmation inventée.
      if (!window.WSOrders) throw new Error('Service de commande indisponible — commande non enregistrée.');
      const result = await window.WSOrders.place(payload);
      // Compatibilité : si un jour POST /orders rend lui-même l'URL de paiement.
      if (result && result.checkoutUrl) {
        window.location.href = result.checkoutUrl;
        return;
      }
      /* PAIEMENT EN LIGNE (famille « stripe », servie par /payment-methods).
         La commande vient d'être ENREGISTRÉE ; l'encaissement se fait sur la
         page Stripe hébergée, créée par POST /payments/checkout — et seul le
         webhook Stripe marque « payé ». Sans cet appel, une commande « carte »
         était confirmée à l'écran sans qu'aucun paiement n'ait jamais lieu :
         POST /orders ne rend pas d'URL de paiement, et rien n'appelait la
         route qui la fabrique. Hors ligne : différé/sur compte (la facture
         suit) et paiement en boutique (encaissé au comptoir). */
      const payFam = (paymentMethods.find((x) => x.id === payment) || {}).family || payment;
      const differe = Boolean(result && (result.paymentType === 'deferred' || result.onAccount));
      if (payFam === 'stripe' && !differe && window.WSOrders.pay) {
        let sess;
        try {
          sess = await window.WSOrders.pay(result.orderId);
        } catch (pe) {
          /* La commande EXISTE déjà — le dire, et dire que réessayer est sûr :
             la clé d'idempotence fait reprendre LA MÊME commande, jamais une
             seconde. Un « Erreur 503 » nu ferait recommander le panier. */
          throw new Error(t('co.payStartFail',
            { ref: result.orderRef || String(result.orderId), reason: pe.message || '' }));
        }
        /* Mémorisé pour l'accueil du retour (?paid=1 / ?canceled=1) : la
           redirection recharge la page, l'état React ne survit pas. */
        try {
          sessionStorage.setItem('ws.pendingPay', JSON.stringify({
            orderId: result.orderId,
            orderRef: result.orderRef || '',
            slot: (typeof slot === 'object' && slot) ? slot.label : slot,
            paymentLabel: (paymentMethods.find((x) => x.id === payment) || {}).label || payment,
            total: (typeof result.total === 'number') ? result.total : total,
          }));
        } catch (_) { /* stockage indisponible : le retour sera juste muet */ }
        window.location.href = sess.checkoutUrl;
        return;
      }
      // Le LIBELLÉ vient de la liste serveur : la confirmation annonçait
      // « Bancontact » pour toute méthode non reconnue — donc aussi pour un
      // paiement en boutique ou sur compte, que le client n'a pas fait.
      const payLabel = (paymentMethods.find((x) => x.id === payment) || {}).label || payment;
      /* Le total du SERVEUR, pas celui du navigateur. L'ancien ordre
         (`{ ...result, total }`) écrasait le montant facturé par l'aperçu
         local : le client lisait sur sa confirmation un montant que personne
         n'avait débité. L'aperçu ne sert qu'à défaut de réponse chiffrée. */
      const totalFacture = (result && typeof result.total === 'number') ? result.total : total;
      onPlaced({ ...result, slot, payment, paymentLabel: payLabel, total: totalFacture });
    } catch (ex) {
      setPayErr(ex.message || 'Erreur lors du paiement. Veuillez réessayer.');
    } finally {
      setPaying(false);
    }
  }

  function next() {
    if (step === 1) {
      if (!step1Valid()) return;
      setStep(2);
    } else if (step === 2) {
      if (!step2Valid()) return;
      setStep(3);
    } else {
      if (!step3Valid()) { setPayErr('Choisissez un moyen de paiement.'); return; }
      handlePay();
    }
  }

  return (
    <aside className="ws-checkout" role="dialog" aria-label={t('co.title')}>
      <header className="ws-checkout__head">
        <button className="ws-checkout__back" onClick={onClose}>
          <Pict d={<path d="M15 6l-6 6 6 6"/>} s={12}/> {t('co.backToCart')}
        </button>
        <span className="ws-checkout__title">{t('co.subtitle')}</span>
      </header>

      <ol className="ws-stepper">
        {[
          { n: 1, label: t('co.step1') },
          { n: 2, label: t('co.step2') },
          { n: 3, label: t('co.step3') },
        ].map((s) => (
          <li key={s.n} className={`ws-stepper__step${step === s.n ? ' is-current' : ''}${step > s.n ? ' is-done' : ''}`}>
            <span className="ws-stepper__num">{step > s.n ? <Pict d={<path d="M5 12l4 4 10-10"/>} s={11}/> : s.n}</span>
            <span className="ws-stepper__lbl">{s.label}</span>
          </li>
        ))}
      </ol>

      <div className="ws-checkout__body">
        {step === 1 && (
          <CheckoutStep1
            mode={mode} shop={shop} user={user} office={office} tour={tour}
            contact={contact} setContact={setContact}
            forceAuth={forceAuth} onLoginNow={() => onLogin()}
            officeSites={officeSites} selectedSiteId={selectedSiteId} setSelectedSiteId={setSelectedSiteId}
            deliveryFeeResult={deliveryFeeResult}
          />
        )}
        {step === 2 && (
          <CheckoutStep2 mode={mode} shop={shop} office={office} tour={tour} slot={slot} setSlot={setSlot} date={date}/>
        )}
        {step === 3 && (
          <>
          <CheckoutStep3
            mode={mode} basket={basket} subtotal={subtotal} totaux={T} total={total}
            deliveryFee={deliveryFee} deliveryFeeResult={deliveryFeeResult} deliveryFeeErr={deliveryFeeErr}
            payment={payment} setPayment={setPayment} paymentMethods={paymentMethods}
            profile={checkoutProfile} companyId={companyId || null}
            isOffice={isOffice} isB2B={isB2B} invoice={invoice} setInvoice={setInvoice} vat={vat} setVat={setVat}
            shopId={shop && shop.id}
            voucherInput={voucherInput} setVoucherInput={setVoucherInput}
            voucherApplied={voucherApplied} setVoucherApplied={setVoucherApplied}
            voucherDiscount={voucherDiscount}
            giftCode={giftCode} onGift={setGiftCode}
            giftEmail={user ? (user.email || null) : ((contact && contact.email) || null)}
            customerId={user ? user.id : null}
          />
          {invoice && (
          <div className="ws-b2b">
            <div className="ws-b2b__head">
              <span className="ws-b2b__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M3 21h18"/>
                  <path d="M5 21V7l7-4 7 4v14"/>
                  <path d="M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>
                </svg>
              </span>
              <div className="ws-b2b__titles">
                <span className="ws-b2b__title">{t('co.company.step')}</span>
                <span className="ws-b2b__sub">{t('co.company.hint')}</span>
              </div>
            </div>

            {companies.length > 0 && (
              <label className="ws-field ws-b2b__field">
                <span>{t('co.company.forCompany')}</span>
                <select value={companyId} onChange={(e) => { setCompanyId(e.target.value); setOnAccount(false); }}>
                  <option value="">{t('co.company.personal')}</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </label>
            )}

            {companyId && (() => {
              const c = companies.find((x) => String(x.id) === String(companyId));
              if (c && c.deferredBilling) {
                return (
                  <label className="ws-b2b__account">
                    <input type="checkbox" checked={onAccount} onChange={(e) => setOnAccount(e.target.checked)} />
                    <span className="ws-b2b__account-box" aria-hidden="true"/>
                    <span className="ws-b2b__account-copy">
                      <span className="ws-b2b__account-name">{t('co.company.onAccount')}</span>
                      <span className="ws-b2b__account-sub">{t('co.company.deferred')}</span>
                    </span>
                  </label>
                );
              }
              return (
                <div className="ws-b2b__callout" role="note">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <path d="M2 10h20"/>
                  </svg>
                  <span>{tRich(t, 'co.company.payByCard')}</span>
                </div>
              );
            })()}

            {/* Récap facturation : raison sociale + adresse (lecture seule),
                depuis la fiche utilisateur (user.invoice) / la société. */}
            {(() => {
              const c = companies.find((x) => String(x.id) === String(companyId));
              const persName = user ? [user.firstName, user.lastName].filter(Boolean).join(' ') : '';
              const bName = (user && (user.invoice?.name || user.company)) || (c && c.name) || persName || '';
              const bVat = (c && c.vat) || (user && user.invoice && user.invoice.vat) || vat || '';
              const uAddr = (user && user.invoice)
                ? [user.invoice.address, [user.invoice.postalCode, user.invoice.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
                : '';
              const cAddr = c
                ? [c.address, [c.postalCode, c.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
                : '';
              const bAddr = uAddr || cAddr; // fiche user d'abord, sinon adresse société (API /companies)
              if (!bName && !bVat && !bAddr) return null;
              return (
                <div className="ws-b2b__bill" role="note">
                  <span className="ws-b2b__bill-k">{t('co.company.billedTo')}</span>
                  {bName && <span className="ws-b2b__bill-name">{bName}</span>}
                  {bVat && <span className="ws-b2b__bill-addr">N° TVA · {bVat}</span>}
                  {bAddr && <span className="ws-b2b__bill-addr">{bAddr}</span>}
                </div>
              );
            })()}

            <label className="ws-field ws-b2b__field">
              <span>{t('co.company.note')}</span>
              <textarea value={orderNote} onChange={(e) => setOrderNote(e.target.value)} rows={2} maxLength={1000}
                        placeholder={t('co.company.notePh')} />
            </label>
            <label className="ws-field ws-b2b__field">
              <span>{t('co.company.po')}</span>
              <input type="text" value={poNumber} onChange={(e) => setPoNumber(e.target.value)}
                     placeholder={t('co.company.poPh')} />
            </label>
          </div>
          )}
          </>
        )}
      </div>

      <footer className="ws-checkout__foot">
        {payErr && <div className="ws-checkout__pay-err" role="alert">{payErr}</div>}
        <div className="ws-checkout__foot-total">
          <span className="ws-checkout__foot-k">{t('sum.total')}</span>
          <span className="ws-checkout__foot-v">€{total.toFixed(2)}</span>
        </div>
        <div className="ws-checkout__foot-actions">
          {step > 1 && <button className="ws-btn-ghost" {...wsTap(() => setStep((s) => s - 1), { shield: true })} disabled={paying}>{t('co.prev')}</button>}
          <button
            className="ws-cta ws-cta--block"
            disabled={paying || (step === 1 && !step1Valid()) || (step === 2 && !step2Valid()) || (step === 3 && !step3Valid())}
            {...wsTap(next, { shield: true })}
          >
            {paying ? t('co.processing') : step === 3 ? t('co.payAmount', { amount: '€' + total.toFixed(2) }) : t('co.continue')}
            {!paying && step < 3 && <Pict d={<path d="M5 12h14M13 5l7 7-7 7"/>} s={13}/>}
          </button>
        </div>
      </footer>
    </aside>
  );
}

function CheckoutStep1({ mode, shop, user, office, tour, contact, setContact, forceAuth, onLoginNow,
                         officeSites, selectedSiteId, setSelectedSiteId, deliveryFeeResult }) {
  const { t } = wsUseT();
  // Office Shop: site selector + read-only delivery info
  if (mode === 'delivery' && user && office) {
    const activeSite = (officeSites || []).find((s) => s.id === selectedSiteId) || null;
    const feeResult = deliveryFeeResult;
    // Un complément d'adresse réduit à un tiret n'est pas un complément : c'est
    // un placeholder saisi ou repris comme donnée. Il était concaténé tel quel
    // et l'adresse s'affichait « … Louvain-la-Neuve · — ».
    const complement = (v) => {
      const s = String(v == null ? '' : v).trim();
      return (s === '' || s === '—' || s === '-' || s === '–') ? '' : s;
    };
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">{t('co.delivery.address')}</h3>
        <p className="ws-co-step__lede">{t('co.delivery.pickSite')}</p>

        {officeSites && officeSites.length > 1 && (
          <div className="ws-co-site-select">
            {officeSites.map((site) => (
              <label key={site.id} className={`ws-co-site-opt${selectedSiteId === site.id ? ' is-active' : ''}`}>
                <input type="radio" name="delivery-site" value={site.id} checked={selectedSiteId === site.id}
                  onChange={() => setSelectedSiteId(site.id)}/>
                <span className="ws-co-site-opt__radio"/>
                <span className="ws-co-site-opt__body">
                  <span className="ws-co-site-opt__name">{site.name}</span>
                  <span className="ws-co-site-opt__addr">{site.address}{complement(site.floor_room) ? ' · ' + complement(site.floor_room) : ''}</span>
                </span>
              </label>
            ))}
          </div>
        )}

        <div className="ws-co-readbox">
          <ReadRow k={t('acc.company')} v={office.name}/>
          {/* Repli sur le titulaire du compte, comme la ligne Téléphone juste en
              dessous : un site sans contact nommé affichait une ligne VIDE. */}
          <ReadRow k={t('off.contact')}    v={(activeSite ? activeSite.contact_name : null)
                                     || ((user.firstName || '') + ' ' + (user.lastName || '')).trim() || '—'}/>
          <ReadRow k={t('form.emailLong')} v={user.email}/>
          <ReadRow k={t('form.phone')} v={(activeSite ? activeSite.contact_phone : office.phone) || user.phone || '—'}/>
          <ReadRow k="Adresse"    v={activeSite
                                     ? (activeSite.address + (complement(activeSite.floor_room) ? ' · ' + complement(activeSite.floor_room) : ''))
                                     : (office.address || '—')}/>
          <ReadRow k="Tournée"    v={tour ? tour.name + ' · ' + tour.window : '—'}/>
          {feeResult && (
            <ReadRow k="Livraison"
              v={feeResult.free_delivery
                ? 'Gratuite'
                : feeResult.fee_amount > 0
                  ? `€${feeResult.fee_amount.toFixed(2)}${feeResult.free_delivery_minimum > 0 ? ` (offerte dès €${feeResult.free_delivery_minimum.toFixed(2)})` : ''}`
                  : 'Gratuite'}/>
          )}
          {feeResult && feeResult.payment_type === 'deferred' && (
            <ReadRow k="Paiement" v="Différé · facturation mensuelle"/>
          )}
        </div>
        <p className="ws-co-step__locknote"><Pict d={<><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></>} s={12}/> {t('co.contact.editFromAccount')}</p>
      </div>
    );
  }

  // Logged-in collect: prefilled, read-only (per spec)
  if (user) {
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">{t('co.contact.title')}</h3>
        <p className="ws-co-step__lede">{t('co.contact.prefilled')}</p>
        <div className="ws-co-readbox">
          <ReadRow k={t('form.lastName')} v={user.firstName + ' ' + user.lastName}/>
          <ReadRow k={t('form.emailLong')} v={user.email}/>
          <ReadRow k={t('form.phone')} v={user.phone || office?.phone || '—'}/>
          <ReadRow k={t('off.shop')} v={shop.name + ' · ' + shop.address}/>
        </div>
      </div>
    );
  }

  // Guest collect — fields, then forced login at the gate
  if (forceAuth) {
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">{t('co.auth.title')}</h3>
        <p className="ws-co-step__lede">{t('co.auth.lede')}</p>
        <div className="ws-co-authwall">
          <button className="ws-cta ws-cta--block" onClick={onLoginNow}>{t('co.auth.signin')}</button>
          <button className="ws-btn-ghost" onClick={onLoginNow}>{t('auth.createAccount')}</button>
        </div>
      </div>
    );
  }
  // Guest collect — contact fields, optional login
  return (
    <div className="ws-co-step">
      <div className="ws-co-guest__banner">
        <span className="ws-co-guest__check" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </span>
        <div className="ws-co-guest__banner-copy">
          <strong>{t('co.guest.title')}</strong>
          <span>{t('co.guest.hint')}</span>
        </div>
      </div>
      <h3 className="ws-co-step__title">{t('co.contact.title')}</h3>
      <div className="ws-form">
        <div className="ws-form__row2">
          <label className="ws-field"><span>{t('form.firstName')}</span><input value={contact.firstName} onChange={(e) => setContact((c) => ({ ...c, firstName: e.target.value }))} autoComplete="given-name"/></label>
          <label className="ws-field"><span>{t('form.lastName')}</span><input value={contact.lastName} onChange={(e) => setContact((c) => ({ ...c, lastName: e.target.value }))} autoComplete="family-name"/></label>
        </div>
        <label className="ws-field"><span>{t('form.email')}</span><input type="email" value={contact.email} onChange={(e) => setContact((c) => ({ ...c, email: e.target.value }))} autoComplete="email"/></label>
        <label className="ws-field"><span>{t('form.phone')}</span><input value={contact.phone} onChange={(e) => setContact((c) => ({ ...c, phone: e.target.value }))} autoComplete="tel"/></label>
      </div>
      <p className="ws-co-guest__login">{t('co.guest.already')} <button type="button" className="ws-linkbtn" onClick={onLoginNow}>{t('co.auth.signin')}</button> pour pré-remplir vos infos.</p>
    </div>
  );
}

function SlotIcon({ name, size = 15 }) {
  const paths = {
    lunch:   <><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></>,
    evening: <path d="M20 14.5A8 8 0 1 1 10.5 4a6.2 6.2 0 0 0 9.5 10.5z"/>,
  };
  return (
    <svg className="ws-slotseg__ic" viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[name] || paths.lunch}
    </svg>
  );
}

// Single derived CTA — renders ONLY what the server returns in `cta`
// (label/theme/icon). No horaire, couleur, or libellé hardcoded here.
function SlotCTA({ cta, onClick }) {
  if (!cta) return null;
  return (
    <button type="button" className="ws-slotcta" data-slot-theme={cta.theme} onClick={onClick}>
      <span className="ws-slotcta__ic"><SlotIcon name={cta.icon} size={16}/></span>
      <span>{cta.label}</span>
    </button>
  );
}

// Sticky segmented control — shown only when the office has 2+ orderable slots.
function SlotSegmented({ slots, selected, onSelect }) {
  const { t } = wsUseT();
  if (!slots || slots.length < 2) return null;
  const active = slots.find((s) => s.slot_type === selected) || slots[0];
  return (
    <div className="ws-slotseg" role="tablist" aria-label={t('co.slot.title')}>
      <div className="ws-slotseg__tabs">
        {slots.map((s) => {
          const theme = (s.cta && s.cta.theme) || (s.slot_type === 'soir' ? 'evening' : 'lunch');
          const on = s.slot_type === selected;
          return (
            <button key={s.route_id} type="button" role="tab" aria-selected={on}
              className="ws-slotseg__btn" data-slot-theme={theme} data-active={on}
              onClick={() => onSelect(s.slot_type, s)}>
              <SlotIcon name={(s.cta && s.cta.icon) || (s.slot_type === 'soir' ? 'evening' : 'lunch')}/>
              <span>{(s.cta && s.cta.label) || (s.slot_type === 'soir' ? 'Soirée' : 'Midi')}</span>
            </button>
          );
        })}
      </div>
      {active && active.cutoff_label && (
        <div className="ws-slotseg__cutoff">{t('co.slot.orderBefore')} <strong>{active.cutoff_label}</strong></div>
      )}
    </div>
  );
}

// Confirmation modal — one cart = one route + one date. Lists removed items.
function SlotChangeModal({ items, targetLabel, onConfirm, onCancel }) {
  const { t } = wsUseT();
  return (
    <div className="ws-drawer is-open" onClick={(e) => { if (e.target === e.currentTarget) onCancel(); }}>
      <div className="ws-drawer__panel ws-slotchange" role="dialog" aria-label={t('co.slot.change')}>
        <h3 className="ws-co-step__title">{t('co.slot.changeQ')}</h3>
        <p className="ws-co-step__lede">Un panier ne peut contenir qu'un seul créneau. Passer sur <strong>{targetLabel}</strong> retirera {items.length} article{items.length > 1 ? 's' : ''} indisponible{items.length > 1 ? 's' : ''} sur ce créneau :</p>
        <ul className="ws-slotchange__list">
          {items.map((l) => <li key={l.line}><span>{l.name}</span><span>×{l.qty}</span></li>)}
        </ul>
        <div className="ws-slotchange__actions">
          <button type="button" className="ws-fid__cancel" onClick={onCancel}>{t('common.cancel2')}</button>
          <button type="button" className="ws-slotchange__confirm" onClick={onConfirm}>{t('co.slot.removeContinue')}</button>
        </div>
      </div>
    </div>
  );
}

function CheckoutStep2({ mode, shop, office, tour, slot, setSlot, date }) {
  const { t, lang } = wsUseT();
  const [slots, setSlots] = React.useState([]);
  const [dateLabel, setDateLabel] = React.useState('');
  React.useEffect(() => {
    let alive = true;
    (async () => {
      // Use the parent-selected date, not hardcoded today
      const d = date instanceof Date ? date : new Date();
      setDateLabel(wsCap(d.toLocaleDateString(wsLocale(), { weekday: 'long', day: 'numeric', month: 'long' })));
      // Repli LOCAL, jamais UTC : toISOString() renvoie la veille pour une Date
      // à minuit en UTC+2.
      const isoFn = (window.WSAvailability || window.WSCalendar)?.isoOf
        || ((x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`);
      const isoDate = isoFn(d);
      const api = window.WSAvailability || window.WSCalendar;
      if (!api) { setSlots([]); return; }
      const list = await api.listSlots({ shopId: shop?.id, mode, date: isoDate });
      if (alive) setSlots(list || []);
    })();
    return () => { alive = false; };
    // `lang` : la date longue (« Zondag 16 augustus ») est rendue par Intl
    // dans la langue courante — sans cette dépendance, elle resterait dans la
    // langue du premier affichage.
  }, [mode, shop?.id, date, lang]);

  const selectedId = typeof slot === 'object' && slot ? slot.id : slot;

  return (
    <div className="ws-co-step">
      <h3 className="ws-co-step__title">{t(mode === 'delivery' ? 'co.slot.titleDelivery' : 'co.slot.titleCollect')}</h3>
      <p className="ws-co-step__lede">
        {mode === 'delivery'
          ? <>{t('off.tour')} <strong>{tour?.name || '—'}</strong> · Livraison à <strong>{office?.name}</strong>, {office?.address || ''}.</>
          : <>{t('co.pickup.at')} <strong>{shop.name}</strong>, {shop.address}.</>
        }
      </p>
      <div className="ws-co-day">
        <Pict d={ICONS.cal} s={13}/> <span>{dateLabel}</span>
      </div>
      <div className="ws-slots">
        {slots.map((s) => {
          const id    = typeof s === 'object' ? s.id    : s;
          const label = typeof s === 'object' ? s.label : s;
          const full  = typeof s === 'object' && (s.available === false || s.current_orders >= s.capacity);
          {/* « X restants » retiré (23/08, même règle que les unités en stock) :
              la capacité résiduelle est une donnée interne. Seul l'état
              bloquant « Complet » s'affiche. */}
          return (
            <button key={id}
              className={`ws-slot${selectedId === id ? ' is-active' : ''}${full ? ' is-full' : ''}`}
              disabled={full}
              title={full ? 'Créneau complet' : undefined}
              onClick={() => !full && setSlot({ id, label })}>
              <span className="ws-slot__lbl">{label}</span>
              {full && <span className="ws-slot__cap ws-slot__cap--full">{t('slot.full')}</span>}
            </button>
          );
        })}
      </div>
      {mode === 'delivery' && <p className="ws-co-step__hint">{t('co.delivery.included')}</p>}
    </div>
  );
}

function CheckoutStep3({ basket, subtotal, totaux, total, payment, setPayment, isOffice, isB2B, invoice, setInvoice, vat, setVat,
                         shopId, mode, voucherInput, setVoucherInput, voucherApplied, setVoucherApplied, voucherDiscount,
                         giftCode, onGift, giftEmail,
                         deliveryFee, deliveryFeeResult, deliveryFeeErr, profile, companyId, customerId,
                         paymentMethods }) {
  const { t } = wsUseT();
  const [voucherErr, setVoucherErr] = useState(null);
  // Cadeau « achat cumulé » : code saisi + produit cadeau validé (ligne 0 €).
  const [giftInput, setGiftInput] = useState('');
  const [giftReward, setGiftReward] = useState(null);
  const [giftErr, setGiftErr] = useState(null);
  const [giftLoading, setGiftLoading] = useState(false);
  const GIFT_REASONS = {
    unknown_code: 'Code cadeau inconnu', already_redeemed: 'Ce cadeau a déjà été utilisé',
    not_unlocked: "Ce cadeau n'est pas encore débloqué", not_owner: 'Ce code ne vous appartient pas',
    before_delivery_date: 'Cadeau disponible plus tard', wrong_shop: 'Cadeau non valable dans cette boutique',
    identity_required: 'Connectez-vous ou saisissez votre e-mail', network: 'Erreur réseau',
  };
  async function applyGift() {
    const code = (giftInput || '').trim();
    if (!code || !window.WSPromo) return;
    setGiftErr(null); setGiftLoading(true);
    try {
      const r = await window.WSPromo.redeem({ code, shopId, guestEmail: giftEmail });
      if (r && r.valid) { setGiftReward(r.reward || {}); onGift && onGift(code); setGiftErr(null); }
      else { setGiftReward(null); onGift && onGift(null); setGiftErr(GIFT_REASONS[r && r.reason] || 'Code cadeau invalide'); }
    } catch (_) { setGiftErr('Erreur réseau lors de la validation du cadeau.'); }
    finally { setGiftLoading(false); }
  }
  function removeGift() { setGiftReward(null); setGiftInput(''); setGiftErr(null); onGift && onGift(null); }
  const [voucherLoading, setVoucherLoading] = useState(false);
  // Infobulle « facture nominative » : ouverte au TAP, fermée au tap extérieur.
  const [infoOpen, setInfoOpen] = useState(false);
  const infoRef = React.useRef(null);
  React.useEffect(() => {
    if (!infoOpen) return;
    const off = (e) => { if (infoRef.current && !infoRef.current.contains(e.target)) setInfoOpen(false); };
    document.addEventListener('pointerdown', off, true);
    return () => document.removeEventListener('pointerdown', off, true);
  }, [infoOpen]);
  // paymentMethods vient du wizard (prop) : plus de second appel à /payment-methods.
  // Si le moyen sélectionné n'est plus proposé (profil/boutique), prendre le premier dispo.
  useEffect(() => {
    if (paymentMethods.length && !paymentMethods.some((p) => p.id === payment)) setPayment(paymentMethods[0].id);
  }, [paymentMethods]);

  // Re-validate whenever subtotal changes (e.g. minOrder boundary)
  useEffect(() => {
    if (voucherApplied && voucherApplied.ok) {
      const code = voucherApplied.voucher.code;
      // Seul le serveur revalide un bon (compteurs, ciblage, périmètre, dates).
      if (!window.WSVouchers) { setVoucherApplied(null); setVoucherErr('Service codes promo indisponible.'); return; }
      window.WSVouchers.redeem({ code, shopId, subtotal, basket, customerId }).then((r) => {
        if (!r.ok) { setVoucherApplied(null); setVoucherErr(r.message); }
        else setVoucherApplied(r);
      }).catch(() => { setVoucherApplied(null); setVoucherErr('Service codes promo indisponible.'); });
    }
  }, [subtotal, shopId]);

  // Bons DISPONIBLES (marketing) : chargés pour ce client + boutique, appliqués
  // en un clic (le client ne retape pas le code).
  const [availVouchers, setAvailVouchers] = useState([]);
  useEffect(() => {
    let alive = true;
    if (window.WSVouchers && typeof window.WSVouchers.available === 'function') {
      window.WSVouchers.available({ shopId, customerId, subtotal })
        .then((list) => { if (alive) setAvailVouchers(Array.isArray(list) ? list : []); })
        .catch(() => {});
    }
    return () => { alive = false; };
  }, [shopId, customerId, subtotal]);

  async function applyVoucher(forcedCode) {
    setVoucherErr(null);
    // Un « code forcé » ne peut être qu'une chaîne (ou un nombre). Tout autre
    // type est un appel mal câblé — typiquement un événement de clic passé par
    // React — et doit retomber sur la saisie, pas être converti en
    // « [object Object] » puis envoyé au serveur comme un vrai code.
    const forced = (typeof forcedCode === 'string' || typeof forcedCode === 'number') ? String(forcedCode) : null;
    const code = (forced != null ? forced : (voucherInput || '')).trim();
    if (!code) return;
    setVoucherLoading(true);
    try {
      if (!window.WSVouchers) throw new Error('Service codes promo indisponible.');
      const r = await window.WSVouchers.redeem({ code, shopId, subtotal, basket, customerId });
      if (r.ok) { setVoucherApplied(r); setVoucherErr(null); setVoucherInput(code); }
      else {
        setVoucherErr(r.message || 'Code invalide');
        // Un échec sur un AUTRE code ne doit PAS retirer celui qui fonctionne :
        // essayer un bon refusé (périmètre produit, seuil non atteint…) faisait
        // perdre la remise déjà acquise, sans le dire. On ne retire que si le
        // code refusé est justement celui qui était appliqué.
        const active = voucherApplied && voucherApplied.ok && voucherApplied.voucher
                       ? String(voucherApplied.voucher.code) : null;
        if (active !== null && active === code) setVoucherApplied(null);
      }
    } catch (_) {
      setVoucherErr('Erreur réseau lors de la validation du code.');
    } finally {
      setVoucherLoading(false);
    }
  }
  function removeVoucher() {
    setVoucherApplied(null);
    setVoucherInput('');
    setVoucherErr(null);
  }

  return (
    <div className="ws-co-step">
      <h3 className="ws-co-step__title">{t('sum.payment')}</h3>
      <p className="ws-co-step__lede">{t('co.pay.subtitle')}</p>

      <div className="ws-co-voucher">
        <div className="ws-co-voucher__head">
          <span className="ws-co-voucher__lbl">{t('co.promo.title')}</span>
          {voucherApplied && voucherApplied.ok && (
            <span className="ws-co-voucher__badge">
              <Pict d={<path d="M5 12l4 4 10-10"/>} s={11}/> {voucherApplied.message}
            </span>
          )}
        </div>
        {voucherApplied && voucherApplied.ok ? (
          <div className="ws-co-voucher__row ws-co-voucher__row--ok">
            <code className="ws-co-voucher__code">{voucherApplied.voucher.code}</code>
            <span className="ws-co-voucher__amt">−€{voucherDiscount.toFixed(2)}</span>
            <button type="button" className="ws-co-voucher__remove" onClick={removeVoucher}>{t('common.remove')}</button>
          </div>
        ) : (
          <div className="ws-co-voucher__row">
            <input
              type="text"
              className="ws-co-voucher__input"
              placeholder={t('co.promo.ph')}
              value={voucherInput}
              onChange={(e) => { setVoucherInput(e.target.value.toUpperCase()); setVoucherErr(null); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyVoucher(); } }}
              autoComplete="off"
              spellCheck={false}
            />
            {/* onClick={applyVoucher} passait l'ÉVÉNEMENT de clic en premier
                argument, donc en « code forcé » : String(event) valait
                « [object Object] » et le code saisi n'était jamais lu. Aucun
                code tapé à la main ne pouvait aboutir — seuls les bons cliqués
                dans la liste fonctionnaient, eux qui passent leur code. */}
            <button type="button" className="ws-co-voucher__apply" onClick={() => applyVoucher()} disabled={!voucherInput.trim() || voucherLoading}>{voucherLoading ? '…' : t('co.apply')}</button>
          </div>
        )}
        {voucherErr && <div className="ws-co-voucher__err">{voucherErr}</div>}

        {/* Bons DISPONIBLES — marketing : le client applique en un clic sans retaper. */}
        {/* La liste reste VISIBLE une fois un code appliqué. Auparavant elle
            disparaissait entièrement : impossible de voir les autres bons ni
            d'en choisir un autre sans passer par « Retirer », ce qui donnait
            l'impression que le choix était définitif. Un seul code par commande
            (le serveur n'en accepte qu'un) — c'est désormais écrit, et cliquer
            un autre bon le remplace directement. */}
        {availVouchers.length > 0 && (
          <div className="ws-co-avail">
            <div className="ws-co-avail__head">
              <PortionGlyph size={13}/>
              <span>{t(availVouchers.length > 1 ? 'co.voucher.availableMany' : 'co.voucher.availableOne')}</span>
              {availVouchers.length > 1 && (
                <span className="ws-co-avail__rule"> · {t('co.voucher.onePerOrder')}</span>
              )}
            </div>
            <ul className="ws-co-avail__list">
              {availVouchers.map((v) => {
                const isOn = !!(voucherApplied && voucherApplied.ok && voucherApplied.voucher
                                && String(voucherApplied.voucher.code) === String(v.code));
                return (
                  <li key={v.code} className={'ws-co-avail__item' + (v.personal ? ' is-personal' : '')}>
                    <div className="ws-co-avail__info">
                      <span className="ws-co-avail__label">{v.label}{v.personal && <span className="ws-co-avail__perso"> · {t('co.voucher.personal')}</span>}</span>
                      <span className="ws-co-avail__code">{v.code}{v.hint ? ' · ' + v.hint : ''}</span>
                    </div>
                    <button type="button" className="ws-co-avail__apply"
                      disabled={isOn || !v.reachable || voucherLoading}
                      title={isOn ? 'Déjà appliqué' : (v.reachable ? 'Remplace le code en cours' : ('Applicable ' + v.hint))}
                      onClick={() => applyVoucher(v.code)}>
                      {isOn ? t('co.applied') : (v.reachable ? t('co.apply') : v.hint)}
                    </button>
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </div>

      <div className="ws-co-gift">
        <div className="ws-co-gift__head">
          <GiftIcon size={15} className="ws-co-gift__ic"/>
          <span className="ws-co-gift__lbl">{t('co.gift.title')}</span>
        </div>
        {giftReward ? (
          <div className="ws-co-gift__ok">
            <Pict d={<path d="M5 12l4 4 10-10"/>} s={12}/>
            <span className="ws-co-gift__okname">{giftReward.name || 'Cadeau'} ajouté à votre commande</span>
            <button type="button" className="ws-co-gift__remove" onClick={removeGift}>{t('common.remove')}</button>
          </div>
        ) : (
          <div className="ws-co-gift__row">
            <input type="text" className="ws-co-gift__input" placeholder={t('co.gift.ph')}
              value={giftInput}
              onChange={(e) => { setGiftInput(e.target.value.toUpperCase()); setGiftErr(null); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyGift(); } }}
              autoComplete="off" spellCheck={false}/>
            <button type="button" className="ws-co-gift__apply" onClick={applyGift} disabled={!giftInput.trim() || giftLoading}>{giftLoading ? '…' : t('co.apply')}</button>
          </div>
        )}
        {giftErr && <div className="ws-co-gift__err">{giftErr}</div>}
      </div>

      <div className="ws-co-summary">
        <div className="ws-co-summary__h">{t('co.summary.title')}</div>
        <ul className="ws-co-summary__list">
          {basket.map((l) => (
            <li key={l.line}>
              <span className="ws-co-summary__qty">×{l.qty}</span>
              <span className="ws-co-summary__name">{l.name}</span>
              <span className="ws-co-summary__amt">€{(l.price * l.qty).toFixed(2)}</span>
            </li>
          ))}
        </ul>
        <div className="ws-co-summary__row"><span>{t('sum.subtotal')}</span><span>€{subtotal.toFixed(2)}</span></div>
        {totaux && totaux.croise > 0 && (
          <div className="ws-co-summary__row ws-co-summary__row--promo">
            <span>{t('sum.crossOffer')}</span><span>−€{totaux.croise.toFixed(2)}</span></div>)}
        {totaux && totaux.remise > 0 && (
          <div className="ws-co-summary__row ws-co-summary__row--promo">
            <span>Remise boutique{totaux.remisePct ? ` · ${totaux.remisePct} %` : ''}</span>
            <span>−€{totaux.remise.toFixed(2)}</span></div>)}
        {voucherDiscount > 0 && voucherApplied && (
          <div className="ws-co-summary__row ws-co-summary__row--promo">
            <span>{t('sum.code')} <strong>{voucherApplied.voucher.code}</strong></span>
            <span>−€{voucherDiscount.toFixed(2)}</span>
          </div>
        )}
        {giftReward && (
          <div className="ws-co-summary__row ws-co-summary__row--gift">
            <span>{giftReward.name || 'Cadeau'} <span className="ws-co-summary__badge">CADEAU</span></span>
            <span>€0,00</span>
          </div>
        )}
        {deliveryFeeResult && mode === 'delivery' && (
          <div className={`ws-co-summary__row${deliveryFee === 0 ? ' ws-co-summary__row--free' : ''}`}>
            <span>Frais de livraison
              {deliveryFee === 0 && deliveryFeeResult.free_delivery_minimum > 0 && (
                <span className="ws-co-summary__row-note"> · offerts dès €{deliveryFeeResult.free_delivery_minimum.toFixed(2)}</span>
              )}
            </span>
            <span>{deliveryFee === 0 ? 'Offerts' : `€${deliveryFee.toFixed(2)}`}</span>
          </div>
        )}
        {mode === 'delivery' && deliveryFeeErr && (
          <p className="ws-co-error" role="alert">{deliveryFeeErr}</p>
        )}
        <div className="ws-co-summary__row ws-co-summary__row--total"><span>{t('sum.total')}</span><span>€{total.toFixed(2)}</span></div>
      </div>

      <div className="ws-pay">
        {paymentMethods.length === 0 ? (
          // Aucun moyen de paiement renvoyé par le serveur : on le DIT au lieu
          // d'afficher des options inventées (règle go-live « vraies données »).
          <p className="ws-co-error" role="alert">
            Moyens de paiement indisponibles pour cette boutique. La commande ne peut pas être
            finalisée — réessayez dans un instant ou contactez la boutique.
          </p>
        ) : paymentMethods.map((p) => (
          <label key={p.id} className={`ws-pay__opt${payment === p.id ? ' is-active' : ''}`}>
            <input type="radio" name="payment" value={p.id} checked={payment === p.id} onChange={() => setPayment(p.id)}/>
            <span className="ws-pay__radio"/>
            <span className="ws-pay__copy">
              <span className="ws-pay__name">{p.label}</span>
              <span className="ws-pay__sub">{p.sub}</span>
            </span>
            <span className="ws-pay__logo" data-prov={p.id}/>
          </label>
        ))}
      </div>

      {profile !== 'guest' && (
        <div className="ws-co-invoice" ref={infoRef}>
          <div className="ws-co-invoice__row">
            <label className="ws-co-invoice__check">
              <input type="checkbox" checked={invoice} onChange={(e) => setInvoice(e.target.checked)}/>
              <span>{t(isB2B ? 'co.askInvoice' : 'co.askInvoiceNamed')}</span>
            </label>
            {!isB2B && (
              <button type="button" className={`ws-co-invoice__i${infoOpen ? ' is-open' : ''}`}
                      aria-label={t('co.invoice.about')} aria-expanded={infoOpen}
                      onClick={() => setInfoOpen((v) => !v)}>i</button>
            )}
          </div>
          {!isB2B && infoOpen && (
            <div className="ws-co-invoice__tip" role="note">
              Ticket au format A4, présenté comme une facture — établi à votre nom (facture nominative).
            </div>
          )}
        </div>
      )}

      <p className="ws-co-step__hint">{t('co.pay.secure')}</p>
    </div>
  );
}

function ReadRow({ k, v }) {
  return (
    <div className="ws-co-readrow">
      <span className="ws-co-readrow__k">{k}</span>
      <span className="ws-co-readrow__v">{v}</span>
    </div>
  );
}

// =========================================================================
// SHOP SWITCHER MODAL
// =========================================================================
/* bug() — un échec d'API se DIT à l'écran, il ne se range pas dans la console.
   Le webshop n'avait que des console.error : dix appels échouaient en silence
   pour qui ne garde pas l'inspecteur ouvert, et une grille vide ressemblait
   exactement à une boutique sans produit. Le bandeau (webshop-bug-banner)
   l'annonce ; la console garde l'objet d'erreur complet, qui sert au débogage
   mais n'a rien à faire sous les yeux d'un client. */
function bug(source, quoi, e) {
  try { if (window.WSBug) window.WSBug.note(source, quoi + (e && e.message ? ' — ' + e.message : '')); } catch (_) {}
  console.error('[' + source + '] ' + quoi, e || '');
}

function ShopSwitcher({ open, currentId, onPick, onClose, shops }) {
  const { t } = wsUseT();
  const swPanelRef = useSwipeDownToClose(onClose);
  if (!open) return null;
  const list = shops || [];
  return (
    <div className="ws-modal ws-modal--switcher" onClick={onClose}>
      <div ref={swPanelRef} className="ws-modal__panel ws-modal__panel--switcher" onClick={(e) => e.stopPropagation()}>
        <span className="ws-modal__handle" aria-hidden="true"/>
        <button className="ws-modal__close" onClick={onClose}><Pict d={ICONS.close} s={14}/></button>
        <p className="ws-modal__eyebrow">{t('shoppick.title')}</p>
        <h2 className="ws-modal__title">{tRich(t, 'shoppick.findTitle')}</h2>
        <p className="ws-modal__lede">{t('shoppick.hint')}</p>
        <div className="ws-modal__grid">
          {list.map((s) => (
            <button key={s.id} className={`ws-shopcard${s.id === currentId ? ' is-current' : ''}`} onClick={() => { onPick(s.id); onClose(); }} style={{ '--accent': s.accent }}>
              <span className="ws-shopcard__bar"/>
              <div className="ws-shopcard__head">
                <span className="ws-shopcard__city">{s.city}</span>
                {s.id === currentId && <span className="ws-shopcard__active"><Pict d={ICONS.check} s={11}/> {t('shoppick.current')}</span>}
              </div>
              <div className="ws-shopcard__name">{s.name}</div>
              <div className="ws-shopcard__addr">{s.address}</div>
              <div className="ws-shopcard__svcs">
                <span>{t('nav.mode.collect')}</span>
                <span>·</span>
                <span>{t('shoppick.delivery')}</span>
              </div>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// SHOP FRAME — full storefront
// =========================================================================
function ShopFrame({ variant }) {
  const { t, lang } = wsUseT();   // i18n réactif : coquille + rechargement des catégories
  // Deep-link: read URL params once at mount so admin direct links
  // (?shop=&mode=&voucher=&category=) preload the storefront state.
  const _deep = typeof parseDeepLink === 'function' ? parseDeepLink() : {};
  // Active shop: deep-link → last remembered (WSShopRouter) → default.
  const [shopId, setShopId] = useState(
    // Go-live : plus de boutique par defaut en dur - deep-link ou memoire,
    // sinon la premiere boutique reelle renvoyee par /shops (effet ci-dessous).
    _deep.shopId || (window.WSShopRouter && window.WSShopRouter.current()) || null
  );
  React.useEffect(() => {
    // Persist the active shop so cart/checkout/login stay scoped to it.
    if (window.WSShopRouter) window.WSShopRouter.setActive(shopId);
    if (window.WSBrand && typeof window.WSBrand.apply === 'function') {
      window.WSBrand.apply(shopId);
    }
    /* LA BOUTIQUE ACTIVE S'ÉCRIT DANS L'URL. Elle n'y était pas : ?shop= était
       LU au chargement puis rangé dans localStorage, et jamais réaffiché. Trois
       conséquences, toutes constatées :
         · on ne pouvait pas savoir quelle boutique on regardait ;
         · un lien copié ne portait pas la boutique — le destinataire tombait
           sur la sienne, ou sur celle que le repli avait choisie ;
         · comparer le webshop et la console franchisé (qui, elle, affiche
           ?shop=2) demandait de deviner.
       replaceState, comme pour la catégorie juste en dessous : l'adresse suit
       l'état sans ajouter d'entrée à l'historique. */
    if (shopId == null) return;
    try {
      const q = new URLSearchParams(window.location.search);
      if (q.get('shop') !== String(shopId)) {
        q.set('shop', String(shopId));
        q.delete('shopId');   // alias historique : une seule clé dans l'adresse
        window.history.replaceState(window.history.state, '',
          window.location.pathname + '?' + q.toString() + window.location.hash);
      }
    } catch (_) { /* URL non manipulable : la boutique reste en mémoire */ }
  }, [shopId]);
  const [mode, setMode] = useState(_deep.mode === 'delivery' ? 'collect' : (_deep.mode || 'collect')); // gate delivery via existing flow
  // Niveau + catégorie + sous-catégorie vivent dans l'URL (?category=&sub=),
  // pas seulement en mémoire : une page filtrée est partageable et survit au
  // rafraîchissement. Un lien direct sur une sous-catégorie ouvre la ligne
  // directement au niveau sous-catégories.
  const [cat, setCat] = useState(_deep.cat || 'all');
  const [subCat, setSubCat] = useState(_deep.sub != null ? _deep.sub : null);
  const [basket, setBasket] = useState([]);
  const [cartDrawerOpen, setCartDrawerOpen] = useState(false);
  // Voucher state — may be pre-filled by deep link
  const [voucherInput, setVoucherInput] = useState(_deep.voucher || '');
  const [voucherApplied, setVoucherApplied] = useState(null);
  const [switcherOpen, setSwitcherOpen] = useState(false);
  const [date, setDate] = useState(() => { const t = new Date(); t.setHours(0,0,0,0); return t; });
  // Delivery cutoff: no same-day delivery after 10:00. Re-evaluated every minute.
  const [nowHour, setNowHour] = React.useState(() => new Date().getHours() * 60 + new Date().getMinutes());
  React.useEffect(() => {
    const id = setInterval(() => setNowHour(new Date().getHours() * 60 + new Date().getMinutes()), 60000);
    return () => clearInterval(id);
  }, []);
  const todayMidnight = React.useMemo(() => { const t = new Date(); t.setHours(0,0,0,0); return t; }, []);
  const isToday = (d) => d && d.toDateString() === todayMidnight.toDateString();
  // Cutoff times loaded from API per shop — defaults until API responds.
  /* Cut-offs : null tant que le serveur n'a pas répondu — JAMAIS une valeur
     inventée. Les anciens défauts 11h00/16h00 gouvernaient l'écran (blocage
     « Fermé · commandez pour demain », tooltips) avec des horaires que la
     boutique n'avait pas fixés, dès que l'appel échouait ou tardait. Tant que
     le cut-off est inconnu, on ne bloque pas et on n'affiche pas d'heure. */
  const [deliveryCutoffMinutes, setDeliveryCutoffMinutes] = React.useState(null);
  const [collectCutoffMinutes,  setCollectCutoffMinutes]  = React.useState(null);
  // Per-product notes are a toggleable feature (enabled/disabled via API/DB).
  // Default off until the shop settings confirm it's on.
  const [lineNotesEnabled, setLineNotesEnabled] = React.useState(false);
  // ── DELIVERY SLOTS (midi / soir) ────────────────────────────────────
  // The slot is an ATTRIBUTE of the order, resolved server-side from the
  // route that serves the office. Front only renders /slots + /next-slot.
  // Déclaré AVANT le slot-effect ci-dessous (qui lit `user` dans ses deps) — sinon TDZ.
  const [user, setUser] = useState(null);
  // SSO handoff PWA -> webshop : si l'URL porte ?handoff=<jeton>, on l'échange
  // contre une session webshop, puis on retire le jeton de l'URL (usage unique,
  // ne doit pas rester visible/partageable).
  //
  // AVANT le login token, on force un HARD REFRESH pour repartir d'un état 100%
  // propre : une session/onglet précédent laisse en mémoire (état React) et en
  // localStorage un contexte résiduel (jeton, boutique active, mode, office/site,
  // LISTE PRODUITS déjà chargée) qui « colle » et empêche les filtres livraison
  // de s'appliquer comme sur le webshop online. Le reload wipe tout l'état React
  // en mémoire (panier, mode, listes) et re-fetch le catalogue de la bonne
  // boutique ; on purge aussi la session cliente résiduelle. Garde one-shot via
  // sessionStorage pour ne jamais boucler (le ?handoff survit au reload).
  React.useEffect(() => {
    let alive = true;
    let token = null;
    try { token = new URLSearchParams(window.location.search).get('handoff'); } catch (_) {}
    if (!token || !window.WSAuth || typeof window.WSAuth.handoff !== 'function') return;

    const CLEAN_FLAG = 'ws_handoff_clean';
    // Déjà « nettoyé » ? Si sessionStorage est indisponible (navigation privée
    // stricte), on considère nettoyé → on saute le refresh plutôt que de boucler.
    let cleaned = true;
    try { cleaned = sessionStorage.getItem(CLEAN_FLAG) === '1'; } catch (_) { cleaned = true; }
    if (!cleaned) {
      // 1er passage : purge la session résiduelle + hard refresh — mais UNIQUEMENT
      // si le drapeau one-shot a bien pu être persisté (sinon on boucle à l'infini).
      let persisted = false;
      try { sessionStorage.setItem(CLEAN_FLAG, '1'); persisted = sessionStorage.getItem(CLEAN_FLAG) === '1'; } catch (_) {}
      if (persisted) {
        try { localStorage.removeItem('ws_auth_token'); } catch (_) {} // drop la session précédente
        window.location.reload();                                       // hard refresh (l'URL garde ?handoff)
        return;
      }
      // persistance impossible → on continue sans refresh (pas de boucle).
    }
    // État frais garanti → on procède au login token.
    try { sessionStorage.removeItem(CLEAN_FLAG); } catch (_) {}

    window.WSAuth.handoff(token).then((r) => {
      if (alive && r && r.ok && r.user) {
        setUser(r.user);
        // Transfert PWA : un client B2B (rattaché à un office) atterrit
        // DIRECTEMENT en mode Livraison bureau. L'office puis son site par défaut
        // sont ensuite auto-sélectionnés par les effets [user.officeId]/[userOffice],
        // et le catalogue est re-fetché avec mode=delivery (liste éligible, filtre
        // serveur). Un client sans office reste en Collecte.
        if (r.user.officeId) setMode('delivery');
      }
      try {
        const p = new URLSearchParams(window.location.search);
        p.delete('handoff');
        const qs = p.toString();
        window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
      } catch (_) {}
    }).catch(() => {});
    return () => { alive = false; };
  }, []);
  // Restauration de la session au chargement. Le jeton était bien écrit dans
  // localStorage à la connexion et WSAuth.me() existait pour le revalider, mais
  // RIEN ne l'appelait : après le moindre rechargement, le client redevenait
  // invité — aucun maintien de stock, et un checkout traité en visiteur alors
  // qu'il a un compte.
  React.useEffect(() => {
    let alive = true;
    // Un ?handoff en cours ouvre lui-même la session : ne pas courir contre lui.
    let hasHandoff = false;
    try { hasHandoff = !!new URLSearchParams(window.location.search).get('handoff'); } catch (_) {}
    if (hasHandoff || !window.WSAuth || typeof window.WSAuth.me !== 'function') return;
    Promise.resolve(window.WSAuth.me())
      .then((u) => { if (alive && u && u.id) setUser(u); })
      .catch((e) => bug('session', 'session non restaurée', e));
    return () => { alive = false; };
  }, []);
  const [officeSlots, setOfficeSlots] = React.useState([]);
  const [slotCta, setSlotCta] = React.useState(null);
  const [selectedSlot, setSelectedSlot] = React.useState(null);
  const [pendingSlot, setPendingSlot] = React.useState(null);
  React.useEffect(() => {
    let alive = true;
    const api = window.WSSlots || window.WSAvailability;
    if (mode !== 'delivery' || !api || !(api.listSlots || api.nextSlot)) { setOfficeSlots([]); setSlotCta(null); return; }
    const officeId = (user && user.officeId) || null;
    // Formatage LOCAL : toISOString() rend la veille pour une Date à minuit en
    // UTC+2, et les créneaux demandés étaient ceux du mauvais jour.
    const p2 = (n) => String(n).padStart(2, '0');
    const iso = date instanceof Date ? `${date.getFullYear()}-${p2(date.getMonth() + 1)}-${p2(date.getDate())}` : '';
    Promise.all([
      api.listSlots ? api.listSlots({ officeId, date: iso }) : Promise.resolve([]),
      api.nextSlot  ? api.nextSlot({ officeId, date: iso })  : Promise.resolve(null),
    ]).then(([slots, next]) => {
      if (!alive) return;
      const list = (slots || []).filter((s) => s && s.orderable !== false);
      setOfficeSlots(list);
      setSlotCta(next && next.cta ? next.cta : (list[0] && list[0].cta) || null);
      setSelectedSlot((cur) => cur || (next && next.slot_type) || (list[0] && list[0].slot_type) || null);
    }).catch(() => { if (alive) { setOfficeSlots([]); setSlotCta(null); } });
    return () => { alive = false; };
  }, [mode, user, date]);
  // One cart = one route. Changing slot drops items unavailable on the target.
  function requestSlotChange(slotType) {
    if (slotType === selectedSlot) return;
    const target = officeSlots.find((s) => s.slot_type === slotType);
    const dropped = basket.filter((l) => Array.isArray(l.available_slots) && !l.available_slots.includes(slotType));
    if (dropped.length > 0) { setPendingSlot({ slot_type: slotType, route: target, dropped }); return; }
    setSelectedSlot(slotType);
  }
  function confirmSlotChange() {
    if (!pendingSlot) return;
    // Les lignes écartées par le changement de créneau tenaient du stock : sans
    // libération, il restait gelé jusqu'à expiration alors que le produit
    // n'était plus au panier de personne.
    (pendingSlot.dropped || []).forEach((l) => stockRelease(l.productId, l.reservationId || null));
    setBasket((b) => b.filter((l) => !(Array.isArray(l.available_slots) && !l.available_slots.includes(pendingSlot.slot_type))));
    setSelectedSlot(pendingSlot.slot_type);
    setPendingSlot(null);
  }
  React.useEffect(() => {
    let alive = true;
    const cfgApi = [window.WSCatalog, window.WSBrand, window.WSAvailability, window.WSCalendar]
      .find((a) => a && (a.getSettings || a.getShopSettings));
    const getter = cfgApi && (cfgApi.getSettings || cfgApi.getShopSettings);
    if (!getter) return;
    Promise.resolve(getter.call(cfgApi, { shopId }))
      .then((s) => {
        if (!alive || !s) return;
        // Accept a few likely field names from the settings payload.
        const flag = s.line_notes_enabled ?? s.product_notes_enabled ?? s.allow_line_notes ?? (s.features && s.features.lineNotes);
        if (typeof flag === 'boolean') setLineNotesEnabled(flag);
      })
      .catch(() => {});
    return () => { alive = false; };
  }, [shopId]);
  React.useEffect(() => {
    const calApi = window.WSAvailability || window.WSCalendar;
    if (!calApi) return;
    // Delivery cutoff
    (calApi.getCutoff || (() => calApi.getShopSettings({ shopId }).then((s) => s.delivery_cutoff)))
      .call(calApi, { shopId, mode: 'delivery' })
      .then((r) => { if (r && typeof r.hour === 'number') setDeliveryCutoffMinutes(r.hour * 60 + (r.minutes || 0)); })
      .catch(() => {});
    // Collect cutoff
    (calApi.getCutoff || (() => calApi.getShopSettings({ shopId }).then((s) => s.collect_cutoff)))
      .call(calApi, { shopId, mode: 'collect' })
      .then((r) => { if (r && typeof r.hour === 'number') setCollectCutoffMinutes(r.hour * 60 + (r.minutes || 0)); })
      .catch(() => {});
  }, [shopId]);
  const deliveryCutoffPassed = isToday(date) && deliveryCutoffMinutes !== null && nowHour >= deliveryCutoffMinutes;
  const collectCutoffPassed  = isToday(date) && collectCutoffMinutes !== null && nowHour >= collectCutoffMinutes;
  // Human-readable cutoff labels for tooltips (e.g. "11h00", "16h00")
  function fmtCutoff(mins) {
    if (mins === null || mins === undefined) return '';
    const h = Math.floor(mins / 60), m = mins % 60;
    return `${h}h${m > 0 ? String(m).padStart(2,'0') : ''}`;
  }
  const deliveryCutoffLabel = fmtCutoff(deliveryCutoffMinutes);
  const collectCutoffLabel  = fmtCutoff(collectCutoffMinutes);
  // Minimum lead days required by any item currently in basket
  const minLeadDays = useMemo(
    () => basket.reduce((m, l) => Math.max(m, l.lead_time || 0), 0),
    [basket]
  );
  const [authOpen, setAuthOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [allergensOpen, setAllergensOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [orderToast, setOrderToast] = useState(null);

  // Shops directory — sourced from API stub (or remote endpoint when wired).
  const [shops, setShops] = useState(() => (window.WSShops ? window.WSShops.getCacheSync() : []));
  const [shopsFailed, setShopsFailed] = React.useState(false);
  React.useEffect(() => {
    let alive = true;
    if (window.WSShops) {
      window.WSShops.list()
        .then((s) => { if (!alive) return; setShops(s || []); setShopsFailed(!s || !s.length); })
        .catch(() => { if (alive) setShopsFailed(true); });
    } else { setShopsFailed(true); }
    return () => { alive = false; };
  }, []);

  // Deep-link : normalise la réf boutique (id numérique OU slug) vers l'id
  // canonique dès que l'annuaire des boutiques est chargé, pour que tous les
  // appels API utilisent le vrai shopId.
  React.useEffect(() => {
    if (!shops || !shops.length) return;
    const m = shops.find((s) => String(s.id) === String(shopId) || s.slug === shopId);
    if (m) { if (m.id !== shopId) setShopId(m.id); }
    else {
      /* AUCUN REPLI. On ne choisit PAS de boutique à la place du visiteur.
         Le code prenait shops[0] — et /shops trie PAR NOM, donc ce n'était même
         pas « la boutique par défaut » mais la première par ordre alphabétique.
         Relevé en production : un visiteur sans ?shop= atterrissait sur
         « Atelier by - Halle » (id 4) pendant que la console franchisé montrait
         « Atelier by Berlo - Corbais » (id 2). Deux catalogues différents, et
         rien à l'écran pour le dire — on a cherché une heure un produit
         manquant qui n'a jamais manqué.

         Une boutique servie à la place d'une autre est une donnée fausse, pas
         un moindre mal. On rend donc la main : shopId reste nul, et l'écran
         demande de choisir. */
      if (shopId != null) console.warn('[webshop] boutique « ' + shopId +
        ' » inconnue de /shops — aucun repli : le visiteur choisit.');
      setShopId(null);
    }
  }, [shops]);

  // Catégories — serveur uniquement (window._CATALOG_SEED n'existe plus).
  // L'échec est tracé : sans ça, la boutique s'affichait vide sans un mot.
  const [categories, setCategories] = React.useState([]);
  React.useEffect(() => {
    let alive = true;
    if (window.WSCatalog && typeof window.WSCatalog.listCategories === 'function') {
      // Date LOCALE, comme pour les produits : la barre de nav doit refléter la
      // même saison que la grille, sinon on garde des onglets qui ne mènent
      // nulle part.
      const dIso = date instanceof Date
        ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
        : (date || '');
      window.WSCatalog.listCategories({ shopId, date: dIso })
        .then((c) => { if (alive && Array.isArray(c)) setCategories(c); })
        .catch((e) => bug('catalogue', 'catégories indisponibles — la barre restera vide', e));
    }
    return () => { alive = false; };
    // `lang` dans les dépendances : les libellés de catégories sont résolus par
    // le SERVEUR selon la langue, donc changer de langue doit les redemander —
    // sinon la barre reste dans la langue du premier chargement.
  }, [shopId, date, lang]);

  // Assortiments (saisons) — serveur uniquement.
  const [assortments, setAssortments] = React.useState([]);
  React.useEffect(() => {
    let alive = true;
    if (window.WSCatalog) {
      window.WSCatalog.listAssortments({ shopId })
        .then((a) => { if (alive) setAssortments(a || []); })
        .catch((e) => bug('catalogue', 'assortiments indisponibles', e));
    }
    return () => { alive = false; };
  }, [shopId]);

  // Logged-in user's office + tour — loaded async whenever officeId changes.
  const [userOffice, setUserOffice] = React.useState(null);
  const [userTour, setUserTour] = React.useState(null);
  React.useEffect(() => {
    let alive = true;
    async function load() {
      if (!user || !user.officeId) { setUserOffice(null); setUserTour(null); return; }
      // Serveur uniquement : sans WSOffices/WSTours, pas de bureau — l'écran
      // « Mon bureau » affichera l'absence, jamais un bureau fabriqué.
      const office = window.WSOffices
        ? await window.WSOffices.get(user.officeId).catch((e) => { bug('bureau', 'fiche bureau indisponible', e); return null; })
        : null;
      if (!alive || !office) { setUserOffice(null); setUserTour(null); return; }
      setUserOffice(office);
      const tour = window.WSTours
        ? await window.WSTours.get(office.tourId).catch(() => null)
        : null;
      if (alive) setUserTour(tour || null);
    }
    load();
    return () => { alive = false; };
  }, [user?.officeId]);

  // Delivery sites for the logged-in office client, and the site selected in checkout.
  const [officeSites, setOfficeSites] = React.useState([]);
  const [selectedSiteId, setSelectedSiteId] = React.useState(null);
  React.useEffect(() => {
    let alive = true;
    async function loadSites() {
      if (!userOffice) { setOfficeSites([]); setSelectedSiteId(null); return; }
      const sites = window.WSDeliveryFees
        ? await window.WSDeliveryFees.listSites({ officeClientId: userOffice.id }).catch(() => [])
        : [];
      if (!alive) return;
      setOfficeSites(sites);
      // Pre-select the office's default site (or the first active site).
      const def = userOffice.defaultSiteId || null;
      const picked = (def && sites.find((s) => s.id === def)) ? def : (sites[0] ? sites[0].id : null);
      setSelectedSiteId(picked);
    }
    loadSites();
    return () => { alive = false; };
  }, [userOffice?.id]);

  // Active delivery site object (resolved from selection or first site).
  const selectedSite = React.useMemo(
    () => officeSites.find((s) => s.id === selectedSiteId) || null,
    [officeSites, selectedSiteId]
  );

  // Delivery fee — recomputed whenever basket subtotal or selected site changes.
  const subtotalForFee = basket.reduce((t, l) => t + l.price * l.qty, 0);
  const [deliveryFeeResult, setDeliveryFeeResult] = React.useState(null);
  // L'échec du calcul des frais était AVALÉ (.catch(() => {})). Conséquences :
  // frais de livraison facturés 0 €, ligne « Frais de livraison » masquée, et
  // payment_type retombant sur 'immediate' — donc un bureau en facturation
  // différée se voyait demander un paiement immédiat. On garde l'erreur et la
  // commande est refusée en mode livraison tant qu'elle n'est pas résolue.
  const [deliveryFeeErr, setDeliveryFeeErr] = React.useState('');
  React.useEffect(() => {
    let alive = true;
    if (mode !== 'delivery' || !userOffice) { setDeliveryFeeResult(null); setDeliveryFeeErr(''); return; }
    if (!window.WSDeliveryFees) { setDeliveryFeeResult(null); setDeliveryFeeErr('Frais de livraison indisponibles — please debug.'); return; }
    window.WSDeliveryFees.quote({
      siteId:          selectedSite ? selectedSite.id          : null,
      officeClientId:  userOffice.id,
      tourneeId:       selectedSite ? selectedSite.tournee_id  : (userOffice.tourId || null),
      shopId:          shop ? shop.id : shopId,
      subtotal:        subtotalForFee,
    }).then((r) => { if (!alive) return; setDeliveryFeeResult(r); setDeliveryFeeErr(r ? '' : 'Frais de livraison indisponibles — please debug.'); })
      .catch((e) => { if (!alive) return; setDeliveryFeeResult(null); bug('frais de livraison', 'calcul indisponible — aucun montant affiché', e);
                      setDeliveryFeeErr('Frais de livraison indisponibles — commande impossible. ' + (e && e.message ? e.message : '')); });
    return () => { alive = false; };
  }, [mode, userOffice?.id, selectedSite?.id, subtotalForFee, shopId]);

  // After a manual shop switch we may prompt the user to make it their preferred shop.
  const [prefNudge, setPrefNudge] = useState(null); // { shopId, shopName } | null

  const mainScrollRef = React.useRef(null);
  function updateScrollState() {
    const el = mainScrollRef.current;
    if (!el) return;
    const canScroll = el.scrollHeight - el.clientHeight - el.scrollTop > 24;
    const canScrollUp = el.scrollTop > 24;
    el.dataset.canScroll = canScroll ? '1' : '0';
    el.dataset.canScrollUp = canScrollUp ? '1' : '0';
  }
  React.useEffect(() => {
    updateScrollState();
    const id = setTimeout(updateScrollState, 200);
    window.addEventListener('resize', updateScrollState);
    return () => { clearTimeout(id); window.removeEventListener('resize', updateScrollState); };
  });

  function handleCheckout() {
    if (!basket.length) return;
    setCheckoutOpen(true);
  }
  function handlePlaced(payload) {
    setCheckoutOpen(false);
    setOrderToast({ ...payload, ts: Date.now() });
    setBasket([]);
    stockReleaseAll(); // reservations converted to qty_sold by the order endpoint
    setTimeout(() => setOrderToast(null), 4500);
  }

  // Deep-link peut passer un id numérique (en string) OU un slug → match souple.
  // `|| shops[0]` retiré : le meme repli, une seconde fois. Il rattrapait un
  // shopId nul en servant la premiere boutique par ordre alphabetique — donc
  // meme sans repli dans la resolution ci-dessus, l'ecran en aurait affiche une.
  const shop = shops.find((s) => String(s.id) === String(shopId) || s.slug === shopId) || null;
  // Langue de la boutique : default_lang / languages (servis par /shops) →
  // Halle ouvre en néerlandais, le sélecteur se limite aux langues offertes.
  // N'impose la langue que si le visiteur n'a pas choisi lui-même (voir applyShop).
  React.useEffect(() => {
    if (shop && window.WSI18n && window.WSI18n.applyShop) window.WSI18n.applyShop(shop);
  }, [shop && shop.id, shop && shop.default_lang, shop && shop.languages]);
  const isAssortment = typeof cat === 'string' && cat.startsWith('season:');
  const assortmentId = isAssortment ? cat.slice('season:'.length) : null;
  const assortment = assortmentId ? assortments.find((a) => a.id === assortmentId) : null;
  const [allProducts, setAllProducts] = React.useState([]);
  // Mode livraison : marque <body> pour recolorer (CSS, mobile) les boutons
  // d'action principaux du parcours en Abricot Pastel. Retiré hors livraison.
  React.useEffect(() => {
    try { document.body.classList.toggle('ws-mode-delivery', mode === 'delivery'); } catch (_) {}
    return () => { try { document.body.classList.remove('ws-mode-delivery'); } catch (_) {} };
  }, [mode]);
  React.useEffect(() => {
    let alive = true;
    (async () => {
      if (!window.WSCatalog) return;
      // On passe le `mode` : en livraison bureau, l'API renvoie DÉJÀ la liste
      // filtrée (produits éligibles) — filtre partagé, identique online et après
      // handoff PWA, sans dépendre de l'état client. Le filtre client résiduel
      // (slotFiltered) reste comme repli (seed/démo ou API sans le paramètre).
      // `date` : les gammes saisonnières sont évaluées à la date de retrait /
      // livraison. Formatage LOCAL — toISOString() décalerait d'un jour en
      // soirée (heure belge d'été), et on interrogerait la mauvaise saison.
      const dIso = date instanceof Date
        ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
        : (date || '');
      const list = await window.WSCatalog.listProducts({ shopId, mode, date: dIso });
      // Une liste VIDE est une réponse valable — c'est le catalogue de cette
      // date. L'ancien test `list.length` conservait la liste précédente, donc
      // des produits d'une autre date/saison restaient affichés. Un échec HTTP
      // lève déjà une exception : ici, vide veut bien dire vide.
      if (alive && Array.isArray(list)) setAllProducts(list);
    })();
    return () => { alive = false; };
    // `lang` : les NOMS de produits sont résolus par le serveur selon la
    // langue — changer de langue doit redemander le catalogue, sinon la grille
    // reste dans la langue du premier chargement.
  }, [shopId, mode, date, lang]);
  // Source commune GRILLE + LIGNE DE NAV : le catalogue restreint au créneau
  // et à la date en cours. La règle d'affichage de la nav (« n'afficher que ce
  // qui contient au moins un produit disponible ») est ainsi exactement celle
  // de la grille — même source, jamais deux vérités.
  const slotFiltered = useMemo(() => {
    let src = allProducts;
    if (mode === 'delivery') {
      // Canal livraison bureau (« apricot ») : n'exposer QUE les produits activés
      // pour ce canal côté marque (ws_products.office_delivery). C'est un vrai
      // filtre : les produits désactivés disparaissent de la grille ET de la nav
      // (catégories/sous-catégories vides masquées). Absent/undefined = disponible
      // (rétro-compat seed). Indépendant du click & collect.
      src = src.filter((p) => p.office_delivery !== false);
      if (selectedSlot) {
        src = src.filter((p) => !Array.isArray(p.available_slots) || p.available_slots.includes(selectedSlot));
      }
    }
    return src;
  }, [allProducts, mode, selectedSlot]);

  const products = useMemo(() => {
    let src = slotFiltered;
    // Evening → surface "Plats préparés" first.
    if (mode === 'delivery' && selectedSlot === 'soir') {
      src = [...src].sort((a, b) => (a.cat === 'plats' ? -1 : 0) - (b.cat === 'plats' ? -1 : 0));
    }
    if (cat === 'all') return src;
    if (isAssortment) {
      // Filtre par SAISON réelle (product.season = slug ws_season).
      return src.filter((p) => (p.season || '') === assortmentId);
    }
    // Filtre par CATÉGORIE : l'API renvoie cat_id (num) ; fallback p.cat (seed).
    let out = src.filter((p) => String(p.cat_id) === String(cat) || p.cat === cat);
    // Filtre par SOUS-CATÉGORIE si une puce est sélectionnée (subCat = id num).
    if (subCat != null) {
      out = out.filter((p) => String(p.sub_cat_id) === String(subCat) || String(p.subCat) === String(subCat));
    }
    return out;
  }, [cat, subCat, isAssortment, assortmentId, slotFiltered, mode, selectedSlot]);

  // Ligne de nav : catégories/sous-catégories qui contiennent au moins un
  // produit disponible pour le créneau + la date en cours. Une sous-catégorie
  // vide ne se grise pas : elle ne s'affiche pas. Idem pour une catégorie.
  const navCats = useMemo(() => {
    const inCat = (p, c) => String(p.cat_id) === String(c.id) || p.cat === c.id;
    const inSub = (p, s) => String(p.sub_cat_id) === String(s.id) || String(p.subCat) === String(s.id);
    return (categories || [])
      .map((c) => ({ ...c, subs: (c.subs || []).filter((s) => slotFiltered.some((p) => inSub(p, s))) }))
      .filter((c) => slotFiltered.some((p) => inCat(p, c)));
  }, [categories, slotFiltered]);

  // Puces de saison = saisons réellement présentes dans les produits du
  // créneau (comme les catégories, on n'affiche que ce qui est disponible).
  const seasonChips = useMemo(() => {
    const seen = {};
    (slotFiltered || []).forEach((p) => {
      if (p.season && !seen[p.season]) seen[p.season] = { id: p.season, label: p.season_name || p.season, img: p.season_img || 'img/cat-all.png' };
    });
    const arr = Object.values(seen);
    return arr.length ? arr : assortments;
  }, [slotFiltered, assortments]);

  // ── Nav catégories : icônes des touches « Tout » / retour (ws_param via
  //    /config — pas de fichier en dur dans le composant), et synchro URL. ──
  const [navIcons, setNavIcons] = React.useState({ all: null, back: null });
  React.useEffect(() => {
    let alive = true;
    if (window.WSAuth && typeof window.WSAuth.config === 'function') {
      window.WSAuth.config().then((c) => {
        if (alive && c) setNavIcons({ all: c.categoryNavAllIcon || null, back: c.categoryNavBackIcon || null });
      }).catch(() => {});
    }
    return () => { alive = false; };
  }, []);

  // L'URL est la vérité partageable : category= / sub= (fusionnés dans la
  // query existante — shop, mode, voucher… restent intacts). Les BASCULES de
  // niveau créent une étape d'historique (pushState) ; les changements de
  // filtre au sein d'un niveau la remplacent (replaceState). Le « précédent »
  // du navigateur remonte donc d'un cran, comme la touche de retour — il ne
  // quitte pas la boutique tant qu'il reste des étapes.
  const syncCatUrl = React.useCallback((nextCat, nextSub, push) => {
    try {
      const p = new URLSearchParams(window.location.search);
      if (nextCat && nextCat !== 'all') p.set('category', String(nextCat)); else p.delete('category');
      if (nextSub != null && nextSub !== '') p.set('sub', String(nextSub)); else p.delete('sub');
      const qs = p.toString();
      const url = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
      if (push) window.history.pushState({ wsCatNav: 1 }, '', url);
      else window.history.replaceState(window.history.state, '', url);
    } catch (_) {}
  }, []);
  // MÊME SEUIL QUE CategoryRow, obligatoirement : celui-ci décide de l'étape
  // d'historique, celui-là de l'affichage. Deux valeurs différentes et le
  // bouton Retour du navigateur ne correspond plus à ce qu'on voit.
  const catHasSubLevel = React.useCallback((cid) => {
    const c = (navCats || []).find((x) => String(x.id) === String(cid));
    return !!c && (c.subs || []).length >= 1;
  }, [navCats]);
  const selectCat = React.useCallback((cid) => {
    setCat(cid); setSubCat(null);
    // Entrer au niveau sous-catégories = étape d'historique ; une catégorie
    // sans aucune sous-catégorie peuplée filtre sans changer de niveau.
    syncCatUrl(cid, null, cid !== 'all' && catHasSubLevel(cid));
  }, [syncCatUrl, catHasSubLevel]);
  const backToCats = React.useCallback(() => {
    setCat('all'); setSubCat(null);
    syncCatUrl('all', null, true);       // sortie de niveau = étape d'historique
  }, [syncCatUrl]);
  const selectSub = React.useCallback((sid) => {
    setSubCat(sid);
    syncCatUrl(cat, sid, false);         // même niveau : pas d'étape
  }, [syncCatUrl, cat]);
  React.useEffect(() => {
    // Retour navigateur : mêmes étapes que la touche de retour — l'état suit
    // l'URL de l'étape sur laquelle on retombe.
    const onPop = () => {
      try {
        const p = new URLSearchParams(window.location.search);
        setCat(p.get('category') || 'all');
        setSubCat(p.get('sub'));
      } catch (_) {}
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);
  React.useEffect(() => {
    // Cohérence après un changement de créneau/date : si la catégorie ou la
    // sous-catégorie active n'a plus de produit disponible, on ne filtre pas
    // à l'aveugle — retour à un état visible. Gardé silencieux tant que les
    // données ne sont pas chargées (sinon un chargement lent « reset » l'URL).
    if (!slotFiltered.length || !(categories || []).length) return;
    if (cat === 'all' || isAssortment) return;
    const c = navCats.find((x) => String(x.id) === String(cat));
    if (!c) { setCat('all'); setSubCat(null); syncCatUrl('all', null, false); return; }
    if (subCat != null && !(c.subs || []).some((s) => String(s.id) === String(subCat))) {
      setSubCat(null); syncCatUrl(cat, null, false);
    }
  }, [navCats]); // volontairement déclenché par le seul recalcul de la nav

  // Stock map: productId -> { qty_total, qty_reserved, qty_sold, qty_available }
  // Reloaded whenever shop, date or mode changes.
  const [productStock, setProductStock] = React.useState({});
  React.useEffect(() => {
    let alive = true;
    if (window.WSCatalog && typeof window.WSCatalog.getStock === 'function') {
      window.WSCatalog.getStock({ shopId, date, mode })
        .then((map) => { if (alive) setProductStock(map || {}); })
        .catch(() => {});
    }
    return () => { alive = false; };
  }, [shopId, date, mode]);

  const cartCount = basket.reduce((t, l) => t + l.qty, 0);
  /* LIVRABLE : deux chemins, parce que la base en connaît deux.
     • l'entreprise ws_offices validée et rattachée à une tournée (chaîne ERP) ;
     • OU le bureau lié porte lui-même une tournée active (user.officeSite.tourId).
     Le second manquait. Or c'est celui que suit le rattachement depuis le
     profil : la liste ne propose QUE des bureaux desservis, mais la porte
     n'acceptait que la chaîne ERP — souvent vide. Le client liait un bureau
     desservi et se voyait refuser la livraison faute de « bureau lié ». */
  const siteTour = !!(user && user.officeSite && user.officeSite.tourId);
  const userCanDeliver = !!((userOffice && userOffice.status === 'validated' && userTour) || siteTour);

  // Maintien de stock (15 min) — clients connectés. Trois corrections :
  //  • l'échec n'est plus avalé : il est affiché (sinon le produit était au
  //    panier SANS être tenu, et le client l'apprenait au paiement) ;
  //  • la date était calculée en UTC (toISOString) : après 22 h en heure belge
  //    d'été, la réservation partait sur le JOUR PRÉCÉDENT, donc sur une autre
  //    ligne de stock que celle réellement vendue ;
  //  • retirer UNE ligne relâchait TOUT le panier (release sans productId).
  const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const [stockErr, setStockErr] = React.useState('');
  /* MOTIF DE CE QUE L'APP VIENT DE FAIRE, ou de ce qu'elle refuse. Toute
     action décidée à la place du client s'annonce ici : refus de la livraison,
     bascule automatique de mode, panier vidé, boutique changée. Une app qui
     agit sans le dire oblige à deviner — et sur un panier perdu, à recommencer. */
  const [notice, setNotice] = React.useState(null);
  /* RETOUR DE LA PAGE DE PAIEMENT Stripe (checkout_success / checkout_cancel →
     ?paid=1 / ?canceled=1). La commande a été enregistrée AVANT la redirection ;
     ici on accueille, on ne crée rien :
       · paid=1     → toast de confirmation, données mémorisées au départ ;
       · canceled=1 → avis persistant : la commande existe mais reste impayée.
     Le retour du navigateur ne PROUVE pas l'encaissement (seul le webhook fait
     foi, cf. config.php) : on annonce la commande, pas le paiement. Les
     paramètres sont ensuite retirés de l'adresse — un rechargement ne doit pas
     rejouer l'accueil. */
  React.useEffect(() => {
    let q;
    try { q = new URLSearchParams(window.location.search); } catch (_) { return; }
    const paid = q.get('paid') === '1';
    const canceled = q.get('canceled') === '1';
    if (!paid && !canceled) return;
    let pend = null;
    try {
      pend = JSON.parse(sessionStorage.getItem('ws.pendingPay') || 'null');
      sessionStorage.removeItem('ws.pendingPay');
    } catch (_) {}
    if (paid && pend && Number.isFinite(Number(pend.total))) {
      setOrderToast({ orderRef: pend.orderRef, slot: pend.slot,
                      paymentLabel: pend.paymentLabel, total: Number(pend.total), ts: Date.now() });
      setTimeout(() => setOrderToast(null), 6500);
    }
    if (canceled) {
      /* Le texte est CAPTURÉ ici — le rendu affiche notice.texte tel quel. Au
         premier chargement, /i18n n'a souvent pas encore répondu : t() aurait
         figé les clés brutes dans l'avis. On le pose quand le dictionnaire est
         là (ou tout de suite s'il l'est déjà). */
      const ref = (pend && pend.orderRef) || '';
      const poser = () => setNotice({ titre: t('co.payCanceled'),
                                      texte: t('co.payCanceledSub', { ref }) });
      if (!window.WSI18n || !window.WSI18n.onChange || window.WSI18n.isLoaded()) poser();
      else {
        const off = window.WSI18n.onChange(() => { poser(); off(); });
      }
    }
    try {
      q.delete('paid'); q.delete('canceled');
      const qs = q.toString();
      window.history.replaceState(window.history.state, '',
        window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    } catch (_) {}
  }, []);
  function refreshStock() {
    return window.WSCatalog.getStock({ shopId, date, mode }).then((m) => setProductStock(m || {}));
  }
  function stockReserve(productId, qty = 1, lineId = null) {
    // Visiteur non connecté : aucun maintien possible (pas d'identité à qui le
    // rattacher). On le TRACE — sans ça, l'absence de réservation était
    // indiscernable d'une panne, y compris en test.
    if (!user) { console.info('[stock] pas de maintien : client non connecté (panier invité)'); return; }
    if (!window.WSCatalog || !window.WSCatalog.reserve) {
      setStockErr('Service stock indisponible — please debug.'); return;
    }
    const iso = date instanceof Date ? isoLocal(date) : (date || '');
    window.WSCatalog.reserve({ productId, shopId, date: iso, mode, qty, customerId: user.id })
      .then((r) => {
        // Trace systématique : le serveur peut répondre « ok » SANS avoir tenu
        // quoi que ce soit (produit sans stock du jour → rien à tenir). Sans
        // cette trace, une table de réservations vide restait inexplicable.
        console.info('[stock] réponse réservation', r);
        if (r && r.ok !== false && !r.reservationId) {
          console.info('[stock] aucun maintien créé — raison :', (r && r.reason) || 'non précisée');
        }
        // On rattache le maintien à SA ligne de panier : sans cet identifiant,
        // le retrait d'une ligne libérait toutes les réservations du même
        // produit (deux lignes de brownies, on en retire une, les deux
        // maintiens sautaient et le stock repassait dispo à tort).
        if (r && r.reservationId && lineId != null) {
          setBasket((b) => b.map((l) => (l.line === lineId ? { ...l, reservationId: r.reservationId } : l)));
        }
        setStockErr(r && r.ok === false ? (r.error || 'Stock non tenu — please debug.') : '');
        return refreshStock();
      })
      .catch((e) => { bug('stock', 'réservation refusée', e);
                      setStockErr('Stock non tenu : ' + (e && e.message ? e.message : 'erreur serveur') + ' — la disponibilité sera revérifiée au paiement.');
                      refreshStock().catch(() => {}); });
  }
  // reservationId connu → on ne libère QUE ce maintien. Sinon (réservation non
  // encore revenue du serveur), repli sur le produit : mieux vaut libérer un peu
  // trop que geler du stock vendable.
  function stockRelease(productId, reservationId = null) {
    // Sortie silencieuse = enquête impossible : la libération n'était jamais
    // appelée (session perdue, module absent) et la table restait à
    // released_at NULL sans le moindre message, alors que TOUT le reste de la
    // chaîne était tracé.
    if (!user) { console.info('[stock] pas de libération : client non connecté — le maintien expirera seul'); return; }
    if (!window.WSCatalog || !window.WSCatalog.release) {
      bug('stock', 'pas de libération : module catalogue absent'); return;
    }
    window.WSCatalog.release(reservationId
        ? { customerId: user.id, reservationIds: [reservationId] }
        : { customerId: user.id, productId })
      .then((r) => {
        if (r && r.ok !== false && !r.released) {
          console.info('[stock] aucun maintien libéré — rien ne correspondait côté serveur');
        }
        return refreshStock();
      })
      .catch((e) => bug('stock', 'libération du stock', e));
  }
  function stockReleaseAll() {
    if (!user) { console.info('[stock] pas de libération globale : client non connecté'); return; }
    if (!window.WSCatalog || !window.WSCatalog.release) return;
    window.WSCatalog.release({ customerId: user.id }).catch(() => {});
  }

  function handleAdd(p, portion) {
    const lineId = Date.now();
    setBasket((b) => [...b, {
      line: lineId, productId: p.id,
      name: p.name + (portion === 'demi' ? ' — 1/2' : portion === 'quart' ? ' — 1/4' : ''),
      qty: 1, price: p.price, options: [],
      portion: portion || null, cat: p.cat, crossPortion: !!p.crossPortion,
      lead_time: p.lead_time || 0, no_delivery: !!p.no_delivery,
      available_slots: Array.isArray(p.available_slots) ? p.available_slots : null,
    }]);
    stockReserve(p.id, 1, lineId);
  }

  // Configurable-product detail
  function handleRemove(lineId) {
    const line = basket.find((l) => l.line === lineId);
    setBasket((b) => b.filter((l) => l.line !== lineId));
    if (line) stockRelease(line.productId, line.reservationId || null);
  }
  function handleNote(lineId, note) {
    setBasket((b) => b.map((l) => (l.line === lineId ? { ...l, note } : l)));
  }

  const [detailProduct, setDetailProduct] = useState(null);
  // Le catalogue (grille) ne porte que le drapeau has_menu_options, pas la
  // composition. Quand on ouvre un produit déclencheur (menu), on récupère à la
  // demande son arbre formule → étapes → choix (ws_bundles) et on l'attache en
  // available_bundles pour que le composer s'affiche. Sans ça, un menu ne
  // montrait jamais ses étapes côté webshop.
  const bundleCache = React.useRef({});
  /* BOUTON RETOUR DU TÉLÉPHONE = fermer la fiche, pas quitter le site.
     Ouvrir la fiche pousse une étape d'historique (même URL) ; « back » la
     retire et le popstate ferme la fiche. La croix fait la même chose en
     passant par history.back(), pour ne pas laisser traîner une étape qui
     ferait « reculer dans le vide » au retour suivant. */
  const detailOpenRef = React.useRef(false);
  React.useEffect(() => {
    const opening = !!detailProduct && !detailOpenRef.current;
    detailOpenRef.current = !!detailProduct;
    if (opening) { try { window.history.pushState({ wsDetail: 1 }, ''); } catch (_) {} }
  }, [detailProduct]);
  React.useEffect(() => {
    const onPopDetail = () => { setDetailProduct((cur) => (cur ? null : cur)); };
    window.addEventListener('popstate', onPopDetail);
    return () => window.removeEventListener('popstate', onPopDetail);
  }, []);
  const closeProductDetail = React.useCallback(() => {
    try {
      if (window.history.state && window.history.state.wsDetail) { window.history.back(); return; }
    } catch (_) {}
    setDetailProduct(null);
  }, []);
  const openProductDetail = React.useCallback((p) => {
    if (!p) return;
    if (p.has_menu_options && !p.available_bundles &&
        window.WSCatalog && typeof window.WSCatalog.listBundles === 'function') {
      const cached = bundleCache.current[p.id];
      if (cached) { setDetailProduct({ ...p, available_bundles: cached }); return; }
      setDetailProduct(p); // ouvre tout de suite ; le composer apparaît dès l'arrivée des étapes
      window.WSCatalog.listBundles({ productId: p.id })
        .then((bundles) => {
          const list = Array.isArray(bundles) ? bundles : [];
          bundleCache.current[p.id] = list;
          setDetailProduct((cur) => (cur && cur.id === p.id ? { ...cur, available_bundles: list } : cur));
        })
        .catch(() => {});
      return;
    }
    setDetailProduct(p);
  }, []);
  function handleAddConfigured(line) {
    const product = allProducts.find((p) => p.id === line.productId);
    // Même rattachement ligne ↔ réservation que dans handleAdd : sans lui, les
    // produits configurables (portions, options, formules) retombaient sur le
    // repli « par produit » et leur retrait libérait TOUTES les réservations du
    // même produit.
    const lineId = line.line != null ? line.line : Date.now();
    setBasket((b) => [...b, {
      ...line,
      line: lineId,
      lead_time: line.lead_time ?? product?.lead_time ?? 0,
      no_delivery: line.no_delivery ?? !!product?.no_delivery,
    }]);
    stockReserve(line.productId, line.qty || 1, lineId);
  }

  /* Heure de RETRAIT du créneau choisi — décision validée : c'est elle que les
     règles de ventes croisées comparent, jamais l'heure de la commande.
     Les créneaux sont libellés « 12:00 – 12:30 » ; on en prend le début.
     Tant qu'aucun créneau n'est choisi (cas du panier, avant le paiement),
     l'heure reste inconnue et la contrainte horaire ne s'applique pas — à
     l'étape de paiement, où le créneau est choisi, elle s'applique exactement. */
  const crossSlotTime = React.useMemo(() => {
    const hit = (officeSlots || []).find((s) => s.slot_type === selectedSlot || s.id === selectedSlot);
    const m = String((hit && hit.label) || '').match(/([01]\d|2[0-3]):[0-5]\d/);
    return m ? m[0] : null;
  }, [officeSlots, selectedSlot]);

  /* Ajout depuis une suggestion « Panier Croisé ». On passe par le MÊME chemin
     qu'un ajout ordinaire — réservation de stock comprise — pour qu'un produit
     suggéré ne soit pas un citoyen de seconde zone dans le panier. */
  function handleCrossAdd(it) {
    const p = allProducts.find((x) => String(x.id) === String(it.productId));
    handleAddConfigured({
      productId: it.productId,
      name: p ? p.name : it.name,
      qty: 1,
      price: Number(it.price) || (p ? p.price : 0),
      options: [],
      portion: null,
      cat: p ? p.cat : null,
      basePrice: p ? p.price : Number(it.price) || 0,
    });
  }

  const Nav = variant === 'A' ? NavbarA : variant === 'B' ? NavbarB : NavbarC;

  /* L'heure limite tombe PENDANT la navigation : le mode repasse en Click &
     Collect tout seul. Sans un mot, le client voyait ses prix et ses créneaux
     changer sous ses yeux sans avoir rien touché. */
  React.useEffect(() => {
    if (mode === 'delivery' && deliveryCutoffPassed) {
      setMode('collect');
      setNotice({
        titre: 'Passé en Click & Collect',
        texte: 'L’heure limite de la livraison au bureau (' + deliveryCutoffLabel
             + ') vient d’être atteinte. Pour être livré, choisissez une date ultérieure.',
      });
    }
  }, [deliveryCutoffPassed, mode]);

  // Design system rule: changing mode OR date clears the basket instantly.
  /* POURQUOI la livraison au bureau est refusée. Cinq situations distinctes
     menaient au même geste muet — ouverture du panneau compte, ou rien du tout
     dans le cas de l'heure limite. Le client voyait son écran changer sans
     comprendre, et n'avait aucun moyen de savoir quoi corriger.
     Chaque cas dit donc CE QUI MANQUE et CE QU'IL FAUT FAIRE. Aucun n'est
     inventé : ils découlent de userCanDeliver = bureau lié ET validé ET
     rattaché à une tournée. */
  function deliveryBlockReason() {
    if (!user) return {
      titre: 'Connectez-vous pour la livraison au bureau',
      texte: 'Ce mode est réservé aux comptes rattachés à un bureau livré. Connectez-vous ou créez un compte.',
    };
    if (!userOffice && !(user.officeSite && user.officeSite.id)) return {
      titre: 'Aucun bureau lié à votre compte',
      texte: 'Cherchez votre société dans « Livraison au bureau », ou demandez son ajout si elle n’y figure pas.',
    };
    if (!userOffice && user.officeSite && !user.officeSite.tourId) return {
      titre: 'Votre bureau n’est pas desservi par une tournée',
      texte: '« ' + (user.officeSite.name || 'Votre bureau') + ' » est bien lié à votre compte, mais aucune tournée ne le dessert encore. Votre Atelier doit l’y rattacher ; cela ne dépend pas de vous.',
    };
    if (userOffice.status !== 'validated') return {
      titre: 'Votre bureau attend la validation de l’Atelier',
      texte: 'La demande pour « ' + (userOffice.name || 'votre bureau') + ' » est enregistrée. Vous serez livrable dès qu’elle sera acceptée ; en attendant, commandez en Click & Collect.',
    };
    if (!userTour) return {
      titre: 'Votre bureau n’est pas encore sur une tournée',
      texte: '« ' + (userOffice.name || 'Votre bureau') + ' » est validé, mais votre Atelier doit encore le rattacher à une tournée de livraison. Cela ne dépend pas de vous.',
    };
    return null;
  }

  function handleMode(next) {
    if (next === mode) return;
    // Livraison : compte connecté, bureau lié, validé, et rattaché à une tournée.
    if (next === 'delivery' && !userCanDeliver) {
      const why = deliveryBlockReason();
      setNotice(why);
      // On ouvre l'écran où l'action se fait — mais seulement quand il y a une
      // action possible. Attendre une validation ou une tournée ne se règle pas
      // depuis le compte : y renvoyer ferait chercher un réglage inexistant.
      if (!user) setAuthOpen(true);
      else if (!userOffice) setAccountOpen(true);
      return;
    }
    // Livraison le jour même : refusée passé l'heure limite. Ce cas ne faisait
    // RIEN — le bouton semblait cassé.
    if (next === 'delivery' && deliveryCutoffPassed) {
      setNotice({
        titre: 'Livraison du jour clôturée',
        texte: 'Les commandes en livraison au bureau se prennent avant ' + deliveryCutoffLabel
             + '. Choisissez une date ultérieure, ou passez en Click & Collect.',
      });
      return;
    }
    stockReleaseAll();
    if (basket.length > 0) setNotice({
      titre: 'Panier vidé',
      texte: 'Les prix et les créneaux diffèrent entre Click & Collect et livraison au bureau : le panier repart à zéro au changement de mode.',
    });
    else setNotice(null);
    setMode(next);
    setBasket([]);
  }
  function handleDate(next) {
    const a = date instanceof Date ? date.toDateString() : String(date);
    const b = next instanceof Date ? next.toDateString() : String(next);
    if (a === b) return;
    const avait = basket.length > 0;
    stockReleaseAll();
    setDate(next);
    setBasket([]);
    // Aujourd'hui + livraison + heure limite dépassée : retour forcé en Collect.
    if (mode === 'delivery' && isToday(next) && deliveryCutoffMinutes !== null && nowHour >= deliveryCutoffMinutes) {
      setMode('collect');
      setNotice({
        titre: 'Passé en Click & Collect',
        texte: 'La livraison au bureau n’est plus possible aujourd’hui (commandes avant '
             + deliveryCutoffLabel + ').'
             + (avait ? ' Votre panier a été vidé : les prix et les créneaux dépendent du mode.' : ''),
      });
    } else if (avait) {
      // Règle du design : changer de date vide le panier. Encore faut-il le dire.
      setNotice({
        titre: 'Panier vidé',
        texte: 'Les disponibilités et les prix changent d’un jour à l’autre : le panier est remis à zéro à chaque changement de date.',
      });
    }
  }
  function handleAccount() {
    if (user) setAccountOpen(true);
    else setAuthOpen(true);
  }
  function handleLogin(u) {
    setUser(u);
    // Preferred shop: if the user has one, adopt it as the active shop on login
    // (rule: "On login, load the preferred shop automatically").
    // Don't reset cart — spec says "Do not reset cart unless the shop change requires it".
    if (u && u.preferredShopId && u.preferredShopId !== shopId) {
      const cible = (shops || []).find((x) => String(x.id) === String(u.preferredShopId));
      setShopId(u.preferredShopId);
      // Le catalogue et les prix changent avec la boutique : le dire, sinon le
      // client croit s'être trompé de page en se connectant.
      setNotice({
        titre: 'Boutique ' + (cible ? cible.name : 'préférée') + ' chargée',
        texte: 'C’est la boutique enregistrée dans votre compte. Vous pouvez en changer à tout moment en haut de l’écran.',
      });
    }
    // If freshly logged-in user already has delivery enabled & was on collect, leave mode alone (don't surprise the user).
  }
  function handleLogout() {
    stockReleaseAll(); // release before clearing user reference
    setUser(null);
    // La livraison au bureau exige un compte : à la déconnexion, le mode revient
    // à Click & Collect et le panier tombe. Perdre un panier sans un mot oblige
    // à tout refaire sans comprendre.
    if (mode === 'delivery') {
      const avait = basket.length > 0;
      setMode('collect');
      setBasket([]);
      setNotice({
        titre: 'Retour en Click & Collect',
        texte: 'La livraison au bureau demande un compte rattaché à un bureau.'
             + (avait ? ' Votre panier a été vidé.' : ''),
      });
    }
  }

  // Go-live : sans boutique resolue (API /shops en echec ou vide), on affiche
  // un etat explicite - jamais de boutique de demonstration.
  /* TROIS ÉTATS SANS BOUTIQUE, ET ILS NE SE CONFONDENT PAS. L'écran n'en
     connaissait que deux — « chargement » et « API injoignable » — parce que le
     troisième n'existait pas : un repli choisissait une boutique en silence.
     Sans repli, il faut le dire et laisser choisir, sinon le visiteur attend un
     chargement qui ne viendra jamais. */
  if (!shop) {
    const aChoisir = !shopsFailed && shops && shops.length > 0;
    return (
      <div className={`ws ws--${variant}`} style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '2rem', textAlign: 'center' }}>
        <div style={{ maxWidth: '30rem' }}>
          <h2 style={{ marginBottom: '.5rem' }}>
            {shopsFailed ? 'Boutiques indisponibles'
                         : (aChoisir ? 'Choisissez votre boutique' : 'Chargement de la boutique…')}
          </h2>
          <p style={{ opacity: .7 }}>
            {shopsFailed
              ? 'Impossible de charger la liste des boutiques (API injoignable). Veuillez réessayer plus tard.'
              : (aChoisir
                  ? 'Chaque Atelier a sa carte. Aucune n’est choisie à votre place.'
                  : 'Connexion en cours…')}
          </p>
          {aChoisir && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '.5rem', marginTop: '1.25rem' }}>
              {shops.map((s) => (
                <button key={s.id} className="ws-btn ws-btn--primary"
                        onClick={() => setShopId(s.id)}
                        style={{ width: '100%', justifyContent: 'space-between' }}>
                  <span>{s.name}</span>
                  {s.city ? <span style={{ opacity: .7, fontWeight: 400 }}>{s.city}</span> : null}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className={`ws ws--${variant}`} data-mode={mode}>
      <Nav shop={shop} mode={mode} onMode={handleMode} onSwitchShop={() => setSwitcherOpen(true)}
           cartCount={cartCount} date={date} onDate={handleDate} user={user} onAccount={handleAccount}
           onAllergens={() => setAllergensOpen(true)}
           collectCutoffPassed={collectCutoffPassed} collectCutoffLabel={collectCutoffLabel}
           deliveryCutoffPassed={deliveryCutoffPassed} deliveryCutoffLabel={deliveryCutoffLabel}
           minLeadDays={minLeadDays}/>

      <div className="ws-body">
        <main className="ws-main" ref={mainScrollRef} onScroll={updateScrollState}>
          {variant === 'C' && (
            <div className="ws-hero" style={{ '--shop-accent': shop.accent }}>
              <div className="ws-hero__copy">
                <span className="ws-hero__eyebrow">{t('hero.eyebrow')}</span>
                <h1 className="ws-hero__slogan">On prend.<br/>On divise.<br/><em style={{ color: 'var(--color-primary)' }}>On goûte.</em></h1>
                <p className="ws-hero__lede">{t('hero.lede')}</p>
              </div>
              <div className="ws-hero__chip" style={{ background: 'var(--color-primary)' }}>
                <span>{shop.name}</span>
                <span className="ws-hero__chip-sub">{shop.city}</span>
              </div>
            </div>
          )}

          {/* page head removed */}

          <GiftProgressBanner shop={shop} user={user} />

          {mode === 'delivery' && officeSlots.length >= 2 && (
            <SlotSegmented slots={officeSlots} selected={selectedSlot} onSelect={(t) => requestSlotChange(t)}/>
          )}
          {mode === 'delivery' && officeSlots.length === 1 && officeSlots[0].slot_type === 'midi' && (
            <p className="ws-slot-ask">{t('evening.q')} <button type="button" className="ws-linkbtn" onClick={() => window.WSSlots && window.WSSlots.requestEvening && window.WSSlots.requestEvening({ officeId: (user && user.officeId) || null })}>{t('evening.tellUs')}</button></p>
          )}

          <CategoryRow active={cat} sub={subCat} onSelect={selectCat} onSelectSub={selectSub} onBack={backToCats} navIcons={navIcons} accent={mode === 'delivery' ? '#c17a2a' : 'var(--color-primary)'} tint={mode === 'delivery' ? 'invert(45%) sepia(60%) saturate(600%) hue-rotate(5deg)' : 'invert(15%) sepia(85%) saturate(2400%) hue-rotate(335deg)'} categories={navCats} assortments={seasonChips}/>

          <div className="ws-grid">
            {products.map((p) => {
              const bqty = basket.filter((l) => l.productId === p.id).reduce((t, l) => t + l.qty, 0);
              const stock = productStock[p.id] || null;
              return <ProductCard key={p.id} p={p} onAdd={handleAdd} onOpen={openProductDetail} mode={mode} basketQty={bqty} stock={stock} platsBadge={mode === 'delivery' && selectedSlot === 'soir' && p.cat === 'plats'}/>;
            })}
          </div>
        </main>

        <button
          type="button"
          className="ws-scrollcue ws-scrollcue--up"
          aria-label={t('scroll.upAria')}
          onClick={() => {
            const main = document.querySelector('.ws-main');
            if (!main) return;
            main.scrollBy({ top: -main.clientHeight * 0.85, behavior: 'smooth' });
          }}
        >
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M6 15l6-6 6 6"/></svg>
        </button>
        <button
          type="button"
          className="ws-scrollcue ws-scrollcue--down"
          aria-label={t('scroll.downAria')}
          onClick={() => {
            const main = document.querySelector('.ws-main');
            if (!main) return;
            const atBottom = main.scrollTop + main.clientHeight >= main.scrollHeight - 24;
            main.scrollBy({ top: atBottom ? -main.scrollHeight : main.clientHeight * 0.85, behavior: 'smooth' });
          }}
        >
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>

        <Basket shop={shop} mode={mode} basket={basket} onCheckout={handleCheckout} onRemove={handleRemove} onNote={handleNote} notesEnabled={lineNotesEnabled} deliveryFeeResult={deliveryFeeResult} date={date} slotTime={crossSlotTime} onCrossAdd={handleCrossAdd}/>
      </div>

      {/* Mobile bottom tab bar — 2 buttons, 50/50 split */}
      <nav className="ws-tabbar" aria-label={t('nav.navAria')}>
        <button className="ws-tabbar__btn ws-tabbar__btn--cart" onClick={() => setCartDrawerOpen(true)} aria-label={t('nav.cart')}>
          <span className="ws-tabbar__cart-wrap">
            <Pict d={ICONS.bag} s={20}/>
            {cartCount > 0 && <span className="ws-tabbar__badge">{cartCount}</span>}
          </span>
          <span className="ws-tabbar__label">{t('nav.cart')}</span>
        </button>
        <button className="ws-tabbar__btn ws-tabbar__btn--account" onClick={handleAccount} aria-label={user ? t('nav.profile') : t('nav.signin')}>
          <Pict d={ICONS.user} s={20}/>
          <span className="ws-tabbar__label">{user ? t('nav.profile') : t('nav.signin')}</span>
        </button>
      </nav>

      {/* Slot change confirmation — one cart = one route + one date */}
      {pendingSlot && (
        <SlotChangeModal
          items={pendingSlot.dropped}
          targetLabel={(pendingSlot.route && pendingSlot.route.cta && pendingSlot.route.cta.label) || (pendingSlot.slot_type === 'soir' ? 'Soirée' : 'Midi')}
          onConfirm={confirmSlotChange}
          onCancel={() => setPendingSlot(null)}
        />
      )}

      {/* Mobile cart drop-up drawer */}
      {cartDrawerOpen && (
        <div className="ws-drawer is-open" onClick={(e) => { if (e.target === e.currentTarget) setCartDrawerOpen(false); }}>
          <div className="ws-drawer__panel">
            <button className="ws-drawer__close" onClick={() => setCartDrawerOpen(false)} aria-label={t('common.close2')}>×</button>
            <div className="ws-drawer__handle" aria-hidden="true"/>
            <Basket shop={shop} mode={mode} basket={basket} onCheckout={() => { setCartDrawerOpen(false); handleCheckout(); }} onRemove={handleRemove} onNote={handleNote} notesEnabled={lineNotesEnabled} date={date} slotTime={crossSlotTime} onCrossAdd={handleCrossAdd}/>
          </div>
        </div>
      )}

      <ShopSwitcher
        open={switcherOpen}
        currentId={shopId}
        shops={shops}
        onPick={(id) => {
          if (id === shopId) return;
          setShopId(id);
          // Spec: "If the customer switches shop manually, ask whether
          // they want to update the preferred shop."
          if (user && user.preferredShopId !== id) {
            const picked = shops.find((s) => s.id === id);
            setPrefNudge({ shopId: id, shopName: picked ? picked.name : id });
          }
        }}
        onClose={() => setSwitcherOpen(false)}
      />
      <LoginModal open={authOpen} onClose={() => setAuthOpen(false)} onLogin={handleLogin} onRegister={handleLogin} shopId={shopId}/>
      {/* Rattrapage CP : s'affiche seule après toute connexion (login, handoff
          PWA, session restaurée) tant que le code postal manque en base. */}
      <PostcodeCatchupModal user={user} onUpdateUser={(u) => setUser(u)}/>
      <AccountModal
        open={accountOpen}
        user={user}
        shops={shops}
        currentShopId={shopId}
        onChangePreferredShop={(id) => { if (id && id !== shopId) setShopId(id); }}
        onClose={() => setAccountOpen(false)}
        onLogout={handleLogout}
        onRequestOffice={() => setAccountOpen(true)}
        onUpdateUser={(u) => setUser(u)}
        office={userOffice}
        tour={userTour}
      />
      <ProductDetail open={!!detailProduct} product={detailProduct} mode={mode} onClose={closeProductDetail} onAdd={handleAddConfigured} stock={detailProduct ? (productStock[detailProduct.id] || null) : null}/>
      {window.AllergensModal && <window.AllergensModal open={allergensOpen} onClose={() => setAllergensOpen(false)}/>}
      <CheckoutWizard open={checkoutOpen} onClose={() => setCheckoutOpen(false)} shop={shop} mode={mode} basket={basket} user={user} onLogin={() => setAuthOpen(true)} onPlaced={handlePlaced}
        voucherInput={voucherInput} setVoucherInput={setVoucherInput}
        voucherApplied={voucherApplied} setVoucherApplied={setVoucherApplied}
        office={userOffice} tour={userTour} date={date}
        deliveryFeeResult={deliveryFeeResult} deliveryFeeErr={deliveryFeeErr}
        officeSites={officeSites} selectedSiteId={selectedSiteId} setSelectedSiteId={setSelectedSiteId}
      />
      {prefNudge && (
        <div className="ws-pref-nudge" role="dialog" aria-label={t('prefshop.title')}>
          <div className="ws-pref-nudge__body">
            <div className="ws-pref-nudge__title">{tRich(t, 'prefshop.q', { shop: prefNudge.shopName })}</div>
            <div className="ws-pref-nudge__sub">{tRich(t, 'prefshop.sub', { shop: prefNudge.shopName })}</div>
          </div>
          <div className="ws-pref-nudge__btns">
            <button type="button" className="ws-pref-nudge__no" onClick={() => setPrefNudge(null)}>{t('common.later')}</button>
            <button type="button" className="ws-pref-nudge__yes" onClick={() => {
              if (user) {
                const updated = { ...user, preferredShopId: prefNudge.shopId };
                setUser(updated);
                if (window.WSI18n && window.WSI18n.setCustomer) {
                  const existing = window.WSI18n.getCustomer() || {};
                  window.WSI18n.setCustomer({ ...existing, ...updated });
                }
              }
              setPrefNudge(null);
            }}>{t('prefshop.set')}</button>
          </div>
        </div>
      )}
      {notice && (
        /* Le motif reste à l'écran jusqu'à ce qu'on le ferme : il porte une
           consigne, et une bulle qui s'efface toute seule se lit rarement en
           entier sur un téléphone. */
        <div className="ws-toast ws-toast--notice" role="alert">
          <div className="ws-toast__body">
            <div className="ws-toast__title">{notice.titre}</div>
            <div className="ws-toast__sub">{notice.texte}</div>
          </div>
          {/* « Fermer » EN BAS : à droite du texte, il comprimait le titre sur
              deux lignes et tombait haut dans l'écran, loin du pouce. */}
          <button type="button" className="ws-toast__close" onClick={() => setNotice(null)}>{t('common.close2')}</button>
        </div>
      )}
      {stockErr && (
        <div className="ws-toast ws-toast--err" role="alert" style={{ background: '#7a1f1f' }}>
          <div>
            <div className="ws-toast__title">{t('stock.untracked')}</div>
            <div className="ws-toast__sub">{stockErr}</div>
          </div>
          <button type="button" onClick={() => setStockErr('')}
            style={{ marginLeft: 'auto', background: 'transparent', border: 0, color: 'inherit', cursor: 'pointer', font: '600 13px var(--font-ui)' }}>{t('common.close2')}</button>
        </div>
      )}
      {orderToast && (
        <div className="ws-toast" role="status">
          <span className="ws-toast__check"><Pict d={<path d="M5 12l4 4 10-10"/>} s={14}/></span>
          <div>
            <div className="ws-toast__title">{t('order.confirmed')}</div>
            <div className="ws-toast__sub">Créneau {typeof orderToast.slot === 'object' ? orderToast.slot?.label : orderToast.slot} · {orderToast.paymentLabel || orderToast.payment} · €{orderToast.total.toFixed(2)}</div>
          </div>
        </div>
      )}
    </div>
  );
}

// =========================================================================
ReactDOM.createRoot(document.getElementById('root')).render(<ShopFrame variant="A"/>);

