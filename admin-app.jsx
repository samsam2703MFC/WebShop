// admin-app.jsx — Admin shell with sidebar routing
// Tournées (existing) · Vouchers (new) · Liens directs (new)

// (useState/useEffect/useMemo/useRef destructured earlier in bundle)

// ─────────────────────────────────────────────────────────────
// Sidebar — routes between sections
// ─────────────────────────────────────────────────────────────
const NAV_ICONS = {
  home: <><path d="M4 21V10l8-6 8 6v11"/><path d="M9 21v-7h6v7"/></>,
  bag: <><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 016 0"/></>,
  truck: <><path d="M3 16V7h11v9M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></>,
  campaign: <><path d="M4 12V8a2 2 0 012-2h6l8-3v18l-8-3H6a2 2 0 01-2-2v-2"/><path d="M9 14v5"/></>,
  product: <><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></>,
  clipboard: <><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 11h6M9 15h4"/></>,
  shop: <><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></>,
  user: <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></>,
  cog: <><circle cx="12" cy="12" r="3"/><path d="M19 12h2M3 12h2M12 3v2M12 19v2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></>,
  ticket: <><path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 100 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 100-4V8z"/><path d="M14 6v2M14 11v2M14 16v2"/></>,
  link: <><path d="M10 13a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1.5 1.5"/><path d="M14 11a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1.5-1.5"/></>,
  bell: <><path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 004 0"/></>,
  chev: <path d="M9 6l6 6-6 6"/>,
};

function NavIcon({ k, size = 14 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size}>{NAV_ICONS[k]}</svg>
  );
}

function AdminSidebar({ route, setRoute }) {
  const item = (key, k, label, count) => (
    <a
      key={key}
      className={`admin__nav-item${route === key ? ' admin__nav-item--active' : ''}`}
      onClick={() => setRoute(key)}
    >
      <NavIcon k={k}/> {label}
      {count != null && <span className="admin__nav-count">{count}</span>}
    </a>
  );

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
      {item('dash', 'home', 'Tableau de bord')}
      {item('orders', 'bag', 'Commandes', 142)}
      {item('tournees', 'truck', 'Tournées', 8)}
      {item('campaigns', 'campaign', 'Campagnes')}

      <div className="admin__nav-group">Marketing</div>
      {item('vouchers', 'ticket', 'Vouchers', 12)}
      {item('links', 'link', 'Liens directs')}

      <div className="admin__nav-group">Catalogue</div>
      {item('products', 'product', 'Produits')}
      {item('bundles', 'clipboard', 'Bundles & Menus')}

      <div className="admin__nav-group">Réseau</div>
      {item('shops', 'shop', 'Boutiques')}
      {item('clients', 'user', 'Clients Office')}
      {item('settings', 'cog', 'Paramètres')}
    </aside>
  );
}

function AdminTopbar({ crumbs }) {
  return (
    <div className="admin__topbar">
      <div className="admin__breadcrumb">
        {crumbs.map((c, i) => (
          <React.Fragment key={i}>
            {i > 0 && <NavIcon k="chev" size={11}/>}
            {i === crumbs.length - 1 ? <strong>{c}</strong> : <span>{c}</span>}
          </React.Fragment>
        ))}
      </div>
      <div className="admin__topbar-spacer"/>
      <span className="admin__topbar-pill"><NavIcon k="bell" size={12}/> 2 incidents</span>
      <span className="admin__topbar-pill"><NavIcon k="shop" size={12}/> Toutes boutiques</span>
      <div className="admin__topbar-user">SR</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Tournées page — preserves existing variant gallery in a canvas
// ─────────────────────────────────────────────────────────────
function TourneesPage() {
  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Pilotage', 'Tournées']}/>
      <div style={{ flex: 1, minHeight: 0, position: 'relative' }}>
        <DesignCanvas style={{ height: '100%', width: '100%' }}>
          <DCSection id="variants" title="Trois pistes pour rendre la boutique d'attache toujours visible sur la tuile.">
            <DCArtboard id="A" label="A — Bandeau rail" width={1480} height={1020}>
              <VariantAFrame/>
            </DCArtboard>
            <DCArtboard id="B" label="B — Pastille en ligne" width={1480} height={1020}>
              <VariantBFrame/>
            </DCArtboard>
            <DCArtboard id="C" label="C — Tampon livraison" width={1480} height={1020}>
              <VariantCFrame/>
            </DCArtboard>
          </DCSection>
        </DesignCanvas>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// App router
// ─────────────────────────────────────────────────────────────
function AdminApp() {
  // Persist route to URL hash so reload keeps the user on the same page.
  const [route, setRouteState] = useState(() => {
    const h = (location.hash || '#tournees').slice(1);
    return ['tournees', 'vouchers', 'links'].includes(h) ? h : 'tournees';
  });
  const setRoute = (r) => {
    setRouteState(r);
    if (location.hash !== '#' + r) history.replaceState(null, '', '#' + r);
  };
  useEffect(() => {
    const onHash = () => {
      const h = (location.hash || '#tournees').slice(1);
      if (['tournees', 'vouchers', 'links'].includes(h)) setRouteState(h);
    };
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  let page;
  if (route === 'tournees') page = <TourneesPage/>;
  else if (route === 'vouchers') page = <VouchersPage/>;
  else if (route === 'links') page = <LinksPage/>;
  else page = <PlaceholderPage route={route} setRoute={setRoute}/>;

  return (
    <div className="admin">
      <AdminSidebar route={route} setRoute={setRoute}/>
      {page}
    </div>
  );
}

function PlaceholderPage({ route, setRoute }) {
  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Pilotage', route]}/>
      <div className="admin-empty">
        <div className="admin-empty__inner">
          <div className="admin-empty__title">Section en cours de design</div>
          <div className="admin-empty__sub">Cette section n'est pas encore prototypée. Sélectionnez Tournées, Vouchers ou Liens directs.</div>
          <div className="admin-empty__actions">
            <button className="btn" onClick={() => setRoute('tournees')}>← Tournées</button>
            <button className="btn btn--primary" onClick={() => setRoute('vouchers')}>Vouchers</button>
            <button className="btn" onClick={() => setRoute('links')}>Liens directs</button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { AdminApp, AdminSidebar, AdminTopbar, NavIcon });
