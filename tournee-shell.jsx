// tournee-shell.jsx — repeating admin chrome (sidebar + topbar + page head + toolbar)

const Icon = ({ d, size = 14, className = '' }) => (
  <svg viewBox="0 0 24 24" width={size} height={size} className={className}>
    {d}
  </svg>
);

const I = {
  chev:    <path d="M9 6l6 6-6 6" />,
  search:  <><circle cx="11" cy="11" r="6"/><path d="M16 16l4 4"/></>,
  cal:     <><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></>,
  filter:  <path d="M4 6h16M7 12h10M10 18h4" />,
  list:    <path d="M4 7h16M4 12h16M4 17h16" />,
  grid:    <><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></>,
  map:     <><path d="M9 4l-5 2v14l5-2 6 2 5-2V4l-5 2-6-2z"/><path d="M9 4v14M15 6v14"/></>,
  plus:    <><path d="M12 5v14M5 12h14"/></>,
  pin:     <><path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></>,
  truck:   <><path d="M3 16V7h11v9M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></>,
  more:    <><circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/></>,
  bell:    <><path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 004 0"/></>,
  home:    <><path d="M4 21V10l8-6 8 6v11"/><path d="M9 21v-7h6v7"/></>,
  shop:    <><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></>,
  user:    <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></>,
  product: <><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></>,
  bag:     <><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 016 0"/></>,
  campaign:<><path d="M4 12V8a2 2 0 012-2h6l8-3v18l-8-3H6a2 2 0 01-2-2v-2"/><path d="M9 14v5"/></>,
  cog:     <><circle cx="12" cy="12" r="3"/><path d="M19 12h2M3 12h2M12 3v2M12 19v2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></>,
  clipboard: <><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 11h6M9 15h4"/></>,
};

function Sidebar() {
  return (
    <aside className="admin__side">
      <div className="admin__brand">
        <div className="admin__brand-mark">L</div>
        <div>
          <div className="admin__brand-name">L'Atelier By</div>
          <span className="admin__brand-sub">Console franchise</span>
        </div>
      </div>

      <div className="admin__nav-group">Pilotage</div>
      <a className="admin__nav-item"><Icon d={I.home}/> Tableau de bord</a>
      <a className="admin__nav-item"><Icon d={I.bag}/> Commandes <span className="admin__nav-count">142</span></a>
      <a className="admin__nav-item admin__nav-item--active"><Icon d={I.truck}/> Tournées <span className="admin__nav-count">8</span></a>
      <a className="admin__nav-item"><Icon d={I.campaign}/> Campagnes</a>

      <div className="admin__nav-group">Catalogue</div>
      <a className="admin__nav-item"><Icon d={I.product}/> Produits</a>
      <a className="admin__nav-item"><Icon d={I.clipboard}/> Bundles &amp; Menus</a>

      <div className="admin__nav-group">Réseau</div>
      <a className="admin__nav-item"><Icon d={I.shop}/> Boutiques</a>
      <a className="admin__nav-item"><Icon d={I.user}/> Clients Office</a>
      <a className="admin__nav-item"><Icon d={I.cog}/> Paramètres</a>
    </aside>
  );
}

function Topbar() {
  return (
    <div className="admin__topbar">
      <div className="admin__breadcrumb">
        <span>Pilotage</span>
        <Icon d={I.chev} size={11}/>
        <strong>Tournées</strong>
      </div>
      <div className="admin__topbar-spacer"/>
      <span className="admin__topbar-pill"><Icon d={I.bell} size={12}/> 2 incidents</span>
      <span className="admin__topbar-pill"><Icon d={I.shop} size={12}/> Toutes boutiques</span>
      <div className="admin__topbar-user">SR</div>
    </div>
  );
}

function PageHead() {
  return (
    <div className="page-head">
      <div>
        <h1 className="page-head__title">Tournées du jour</h1>
        <p className="page-head__sub">8 tournées planifiées · 6 boutiques franchisées · 72 arrêts</p>
      </div>
      <div className="page-head__actions">
        <button className="btn"><Icon d={I.map}/> Voir la carte</button>
        <button className="btn btn--primary"><Icon d={I.plus}/> Nouvelle tournée</button>
      </div>
    </div>
  );
}

function SplitStrip() {
  return (
    <div className="split-strip">
      {SPLIT.map((s) => {
        const shop = SHOPS[s.shop];
        return (
          <div key={s.shop} className="split-strip__cell" style={{ '--shop-color': shop.color }}>
            <div className="split-strip__shop">{shop.name}</div>
            <div className="split-strip__count">{s.tournees}</div>
            <div className="split-strip__sub">{s.stops} arrêts · {shop.city}</div>
          </div>
        );
      })}
    </div>
  );
}

function Toolbar({ shopFilter, setShopFilter }) {
  const shops = ['all', ...Object.keys(SHOPS)];
  return (
    <div className="toolbar">
      <span className="toolbar__date"><Icon d={I.cal}/> Mer. 6 mai 2026</span>
      <div className="toolbar__group">
        {shops.map((k) => {
          const s = k === 'all' ? null : SHOPS[k];
          const active = shopFilter === k;
          return (
            <button
              key={k}
              className={`toolbar__chip toolbar__chip--shop${active ? ' toolbar__chip--active' : ''}`}
              onClick={() => setShopFilter(k)}
            >
              <span className="toolbar__chip-dot" style={{ background: s ? s.color : 'var(--color-text-muted)' }}/>
              {k === 'all' ? 'Toutes' : s.name}
            </button>
          );
        })}
      </div>
      <div className="toolbar__search">
        <Icon d={I.search}/>
        <input placeholder="Chauffeur, ID tournée, adresse…"/>
      </div>
    </div>
  );
}

Object.assign(window, { Sidebar, Topbar, PageHead, SplitStrip, Toolbar, Icon, I });
