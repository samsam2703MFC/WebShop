// links.jsx — Direct link generator
// Produces pre-parameterized storefront URLs (shop, date, mode, product, category, voucher)
// Renders branded QR (ruby modules + brand corners), copy URL, download PNG/SVG,
// live storefront preview, and a saved-link history.

const LINK_PRODUCTS = [
  { id: 'tarte-citron',      name: 'Tarte au citron meringuée', cat: 'patisseries', price: 6.5 },
  { id: 'tarte-praline',     name: 'Tarte praliné noisette',     cat: 'patisseries', price: 7.0 },
  { id: 'salade-bressane',   name: 'Salade bressane',            cat: 'salades',     price: 12.5 },
  { id: 'parfait-vanille',   name: 'Parfait vanille bourbon',    cat: 'douceurs',    price: 5.0 },
  { id: 'plat-saumon',       name: 'Saumon, riz, légumes verts', cat: 'plats',       price: 14.5 },
  { id: 'plat-volaille',     name: 'Volaille fermière',          cat: 'plats',       price: 13.0 },
  { id: 'cookie-chocolat',   name: 'Cookie double chocolat',     cat: 'douceurs',    price: 3.5 },
  { id: 'pain-cereales',     name: 'Pain aux céréales',          cat: 'boulangerie', price: 4.2 },
];

const LINK_CATS = [
  { id: 'patisseries', name: 'Pâtisseries' },
  { id: 'salades',     name: 'Salades' },
  { id: 'plats',       name: 'Plats du jour' },
  { id: 'douceurs',    name: 'Douceurs' },
  { id: 'boulangerie', name: 'Boulangerie' },
];

const LINK_COLLECTIONS = [
  { id: 'menu-midi', name: 'Menu de midi' },
  { id: 'brunch',    name: 'Le Brunch' },
  { id: 'goute',     name: 'Le Goûter' },
  { id: 'apero',     name: 'Apéro Box' },
];

const LINK_SHOPS = ['chatelain', 'sablon', 'carre', 'zuid', 'grognon', 'brugge'];

// History persisted to localStorage
const LINKS_KEY = 'latelier-admin:saved-links';

function loadLinks() {
  try {
    const raw = localStorage.getItem(LINKS_KEY);
    if (!raw) return SEED_LINKS;
    const p = JSON.parse(raw);
    return Array.isArray(p) ? p : SEED_LINKS;
  } catch { return SEED_LINKS; }
}
function saveLinks(list) {
  try { localStorage.setItem(LINKS_KEY, JSON.stringify(list)); } catch {}
}

const SEED_LINKS = [
  {
    id: 'l1', name: 'Newsletter rentrée — Tartes',
    config: { shop: 'chatelain', mode: 'collect', date: '2026-09-01', product: 'tarte-citron', voucher: 'RENTREE2026' },
    createdAt: '2026-04-28', clicks: 1240,
  },
  {
    id: 'l2', name: 'Affiche QR Sablon — Brunch dimanche',
    config: { shop: 'sablon', mode: 'collect', date: '', collection: 'brunch', voucher: 'BRUNCH-SABLON' },
    createdAt: '2026-04-22', clicks: 312,
  },
  {
    id: 'l3', name: 'Bureau Européen — Onboarding livraison',
    config: { shop: 'chatelain', mode: 'delivery', date: '', voucher: 'OFFICE-LAUNCH' },
    createdAt: '2026-05-01', clicks: 87,
  },
];

// ─────────────────────────────────────────────────────────────
// Build the storefront URL from the config
// ─────────────────────────────────────────────────────────────
function buildLinkUrl(cfg) {
  const base = location.origin + location.pathname.replace(/[^/]+$/, '') + 'webshop-full.html';
  const params = new URLSearchParams();
  if (cfg.shop)       params.set('shop', cfg.shop);
  if (cfg.mode)       params.set('mode', cfg.mode);
  if (cfg.date)       params.set('date', cfg.date);
  if (cfg.product)    params.set('product', cfg.product);
  if (cfg.openModal)  params.set('open', '1');
  if (cfg.category)   params.set('cat', cfg.category);
  if (cfg.collection) params.set('col', cfg.collection);
  if (cfg.voucher)    params.set('voucher', cfg.voucher);
  const qs = params.toString();
  return qs ? `${base}?${qs}` : base;
}

// ─────────────────────────────────────────────────────────────
// LinksPage
// ─────────────────────────────────────────────────────────────
function LinksPage() {
  const [cfg, setCfg] = useState({
    shop: 'chatelain',
    mode: 'collect',
    date: '',
    product: '',
    openModal: false,
    category: '',
    collection: '',
    voucher: '',
  });
  const [name, setName] = useState('Lien sans nom');
  const [history, setHistory] = useState(loadLinks);
  const [savedToast, setSavedToast] = useState(false);

  useEffect(() => { saveLinks(history); }, [history]);

  const url = buildLinkUrl(cfg);

  const set = (k, v) => setCfg((c) => ({ ...c, [k]: v }));

  const onSave = () => {
    const link = {
      id: 'l' + Math.random().toString(36).slice(2, 8),
      name: name.trim() || 'Lien sans nom',
      config: { ...cfg },
      createdAt: new Date().toISOString().slice(0, 10),
      clicks: 0,
    };
    setHistory((h) => [link, ...h]);
    setSavedToast(true);
    setTimeout(() => setSavedToast(false), 2400);
  };

  const onLoad = (link) => {
    setCfg({
      shop: '', mode: '', date: '', product: '', openModal: false,
      category: '', collection: '', voucher: '',
      ...link.config,
    });
    setName(link.name);
  };

  // Voucher dropdown — load active vouchers from storage
  const allVouchers = useMemo(() => loadVouchers(), []);
  const validVouchers = useMemo(
    () => allVouchers.filter((v) => deriveStatus(v) === 'active' || deriveStatus(v) === 'expiring' || deriveStatus(v) === 'scheduled'),
    [allVouchers],
  );

  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Marketing', 'Liens directs']}/>

      <div className="page-head">
        <div>
          <h1 className="page-head__title">Générateur de liens directs</h1>
          <p className="page-head__sub">Pré-paramétrez l'expérience webshop : boutique, date, produit, voucher. Téléchargez le QR pour vos campagnes print.</p>
        </div>
      </div>

      <div className="link-grid">
        {/* LEFT — config form */}
        <div className="link-form">
          <div className="link-form__head">
            <input
              className="input link-form__name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nom de la campagne"
            />
          </div>

          <Section title="Boutique de destination">
            <div className="shop-multi shop-multi--single">
              <button
                type="button"
                className={`shop-multi__chip${!cfg.shop ? ' shop-multi__chip--active' : ''}`}
                onClick={() => set('shop', '')}
              >
                <span className="shop-multi__avatar shop-multi__avatar--neutral">∅</span>
                <div>
                  <div className="shop-multi__name">Aucune (le client choisit)</div>
                  <div className="shop-multi__city">Pas de pré-sélection</div>
                </div>
              </button>
              {LINK_SHOPS.map((id) => {
                const shop = SHOPS[id];
                const active = cfg.shop === id;
                return (
                  <button
                    key={id}
                    type="button"
                    className={`shop-multi__chip${active ? ' shop-multi__chip--active' : ''}`}
                    onClick={() => set('shop', id)}
                  >
                    <span className="shop-multi__avatar" style={{ background: shop.color }}>{shop.short}</span>
                    <div>
                      <div className="shop-multi__name">{shop.name}</div>
                      <div className="shop-multi__city">{shop.city}</div>
                    </div>
                  </button>
                );
              })}
            </div>
          </Section>

          <Section title="Mode de service">
            <Radio
              options={[
                ['', 'Aucun (par défaut Collecte)'],
                ['collect', 'Click & Collect'],
                ['delivery', 'Livraison au bureau'],
              ]}
              value={cfg.mode}
              onChange={(v) => set('mode', v)}
            />
          </Section>

          <Section title="Date pré-sélectionnée">
            <Field label="Date">
              <input
                className="input"
                type="date"
                value={cfg.date}
                onChange={(e) => set('date', e.target.value)}
              />
              {cfg.date && <button className="ghost-btn" type="button" onClick={() => set('date', '')}>Effacer</button>}
            </Field>
            <div className="link-form__hint">Vide = la date est laissée libre côté client.</div>
          </Section>

          <Section title="Mise en avant produit">
            <Field label="Produit">
              <select className="input" value={cfg.product} onChange={(e) => set('product', e.target.value)}>
                <option value="">— Aucun —</option>
                {LINK_PRODUCTS.map((p) => (
                  <option key={p.id} value={p.id}>{p.name} · {p.price.toFixed(2)}€</option>
                ))}
              </select>
            </Field>
            {cfg.product && (
              <div className="link-toggle">
                <input
                  type="checkbox"
                  id="openmodal"
                  checked={cfg.openModal}
                  onChange={(e) => set('openModal', e.target.checked)}
                />
                <label htmlFor="openmodal">
                  <strong>Ouvrir la fiche produit</strong>
                  <span>Si décoché : le produit est mis en évidence dans la grille (halo ruby).</span>
                </label>
              </div>
            )}
          </Section>

          <Section title="Catégorie ou collection (filtre)">
            <Field label="Catégorie">
              <select className="input" value={cfg.category} onChange={(e) => { set('category', e.target.value); if (e.target.value) set('collection', ''); }}>
                <option value="">— Aucune —</option>
                {LINK_CATS.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Collection">
              <select className="input" value={cfg.collection} onChange={(e) => { set('collection', e.target.value); if (e.target.value) set('category', ''); }}>
                <option value="">— Aucune —</option>
                {LINK_COLLECTIONS.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
          </Section>

          <Section title="Voucher pré-appliqué">
            <Field label="Code">
              <select className="input input--mono" value={cfg.voucher} onChange={(e) => set('voucher', e.target.value)}>
                <option value="">— Aucun —</option>
                {validVouchers.map((v) => (
                  <option key={v.id} value={v.code}>{v.code} · {formatValue(v)}</option>
                ))}
              </select>
            </Field>
            {cfg.voucher && (
              <div className="link-form__hint">Le code sera appliqué automatiquement au passage en caisse, avec validation en temps réel côté client.</div>
            )}
          </Section>

          <div className="link-form__foot">
            <button className="btn" onClick={() => setCfg({ shop: 'chatelain', mode: 'collect', date: '', product: '', openModal: false, category: '', collection: '', voucher: '' })}>Réinitialiser</button>
            <button className="btn btn--primary" onClick={onSave}>Enregistrer ce lien</button>
          </div>
        </div>

        {/* RIGHT — output */}
        <div className="link-output">
          <UrlPanel url={url}/>
          <QrPanel url={url} brandLabel={name}/>
          <PreviewPanel cfg={cfg}/>
        </div>
      </div>

      {/* History */}
      <div className="link-history">
        <div className="link-history__head">
          <h3>Liens enregistrés</h3>
          <span>{history.length} liens</span>
        </div>
        <div className="link-history__list">
          {history.map((link) => (
            <LinkHistoryRow key={link.id} link={link} onLoad={() => onLoad(link)} onDelete={() => setHistory((h) => h.filter((x) => x.id !== link.id))}/>
          ))}
          {history.length === 0 && (
            <div className="link-history__empty">Aucun lien enregistré pour l'instant.</div>
          )}
        </div>
      </div>

      {savedToast && (
        <div className="toast">Lien enregistré — disponible dans l'historique.</div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// URL panel — display, copy, length warning
// ─────────────────────────────────────────────────────────────
function UrlPanel({ url }) {
  const [copied, setCopied] = useState(false);
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch {}
  };
  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">URL générée</span>
        <span className="opanel__meta">{url.length} caractères</span>
      </div>
      <div className="url-box">
        <div className="url-box__txt">{url}</div>
        <button className={`url-box__copy${copied ? ' url-box__copy--ok' : ''}`} onClick={onCopy}>
          {copied ? '✓ Copié' : 'Copier'}
        </button>
      </div>
      <div className="opanel__actions">
        <a className="ghost-btn" href={url} target="_blank" rel="noreferrer">Ouvrir dans un nouvel onglet ↗</a>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// QR panel — branded SVG, download PNG/SVG
// ─────────────────────────────────────────────────────────────
function QrPanel({ url, brandLabel }) {
  const [branded, setBranded] = useState(true);
  const containerRef = useRef(null);
  const [svgMarkup, setSvgMarkup] = useState('');

  useEffect(() => {
    if (!window.QR) return;
    try {
      const m = window.QR.render(url, {
        size: 220,
        branded,
        brandLabel: brandLabel ? brandLabel.toUpperCase() : '',
        color: '#8D1D2C',
        bg: '#ffffff',
      });
      setSvgMarkup(m);
    } catch (e) {
      setSvgMarkup('');
      console.error(e);
    }
  }, [url, branded, brandLabel]);

  const downloadSvg = () => {
    const blob = new Blob([svgMarkup], { type: 'image/svg+xml' });
    triggerDownload(blob, sanitize(brandLabel) + '.svg');
  };
  const downloadPng = async () => {
    if (!window.QR) return;
    try {
      const blob = await window.QR.renderPng(url, {
        size: 360, branded,
        brandLabel: brandLabel ? brandLabel.toUpperCase() : '',
        color: '#8D1D2C', bg: '#ffffff',
      });
      triggerDownload(blob, sanitize(brandLabel) + '.png');
    } catch (e) { console.error(e); }
  };

  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">QR code</span>
        <div className="qr-toggle">
          <button className={`qr-toggle__btn${branded ? ' qr-toggle__btn--active' : ''}`} onClick={() => setBranded(true)}>Brandé</button>
          <button className={`qr-toggle__btn${!branded ? ' qr-toggle__btn--active' : ''}`} onClick={() => setBranded(false)}>Standard</button>
        </div>
      </div>
      <div className="qr-card">
        <div className="qr-card__qr" ref={containerRef} dangerouslySetInnerHTML={{ __html: svgMarkup }}/>
        <div className="qr-card__meta">
          <div className="qr-card__brand">L'Atelier By</div>
          <div className="qr-card__url">{url.replace(/^https?:\/\//, '').slice(0, 32)}…</div>
        </div>
      </div>
      <div className="opanel__actions">
        <button className="btn" onClick={downloadPng}>Télécharger PNG</button>
        <button className="btn" onClick={downloadSvg}>Télécharger SVG</button>
      </div>
    </div>
  );
}

function triggerDownload(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function sanitize(s) {
  return (s || 'lien-latelier').replace(/[^\w-]+/g, '-').toLowerCase().slice(0, 40);
}

// ─────────────────────────────────────────────────────────────
// Preview — miniature storefront state
// ─────────────────────────────────────────────────────────────
function PreviewPanel({ cfg }) {
  const shop = cfg.shop ? SHOPS[cfg.shop] : null;
  const product = LINK_PRODUCTS.find((p) => p.id === cfg.product);

  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">Aperçu de l'arrivée</span>
        <span className="opanel__meta">État pré-chargé</span>
      </div>
      <div className="ppreview">
        <div className="ppreview__nav">
          <span className="ppreview__shop">
            {shop ? (
              <>
                <span className="ppreview__dot" style={{ background: shop.color }}/>
                {shop.name}
              </>
            ) : (
              <>
                <span className="ppreview__dot ppreview__dot--neutral"/>
                Choisissez votre boutique
              </>
            )}
          </span>
          <span className={`ppreview__mode ppreview__mode--${cfg.mode || 'collect'}`}>
            {cfg.mode === 'delivery' ? 'Livraison' : 'Collecte'}
          </span>
          <span className="ppreview__date">
            {cfg.date ? formatDate(cfg.date) : 'Date à choisir'}
          </span>
        </div>
        <div className="ppreview__filter">
          {cfg.category && <span className="ppreview__chip">Cat. {LINK_CATS.find((c) => c.id === cfg.category)?.name}</span>}
          {cfg.collection && <span className="ppreview__chip">Col. {LINK_COLLECTIONS.find((c) => c.id === cfg.collection)?.name}</span>}
          {!cfg.category && !cfg.collection && <span className="ppreview__chip ppreview__chip--ghost">Tous les produits</span>}
        </div>
        <div className="ppreview__grid">
          {LINK_PRODUCTS.slice(0, 6).map((p) => {
            const highlighted = p.id === cfg.product && !cfg.openModal;
            const opened = p.id === cfg.product && cfg.openModal;
            const dim = (cfg.category && p.cat !== cfg.category);
            return (
              <div
                key={p.id}
                className={`ppreview__card${highlighted ? ' ppreview__card--hl' : ''}${dim ? ' ppreview__card--dim' : ''}`}
              >
                <div className="ppreview__card-img"/>
                <div className="ppreview__card-name">{p.name.length > 18 ? p.name.slice(0, 18) + '…' : p.name}</div>
                <div className="ppreview__card-price">{p.price.toFixed(2)}€</div>
                {opened && <div className="ppreview__modal">Fiche ouverte</div>}
              </div>
            );
          })}
        </div>
        {cfg.voucher && (
          <div className="ppreview__voucher">
            <span className="ppreview__voucher-tag">VOUCHER</span>
            <span className="vcode">{cfg.voucher}</span>
            <span className="ppreview__voucher-state">✓ Pré-appliqué au panier</span>
          </div>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// History row
// ─────────────────────────────────────────────────────────────
function LinkHistoryRow({ link, onLoad, onDelete }) {
  const cfg = link.config;
  const url = buildLinkUrl(cfg);
  const shop = cfg.shop ? SHOPS[cfg.shop] : null;
  const [copied, setCopied] = useState(false);

  const onCopy = async (e) => {
    e.stopPropagation();
    try { await navigator.clipboard.writeText(url); setCopied(true); setTimeout(() => setCopied(false), 1500); } catch {}
  };

  return (
    <div className="lhrow" onClick={onLoad}>
      <div className="lhrow__main">
        <div className="lhrow__name">{link.name}</div>
        <div className="lhrow__meta">
          {shop && <span className="lhrow__chip"><span className="lhrow__dot" style={{ background: shop.color }}/>{shop.name}</span>}
          {cfg.mode && <span className="lhrow__chip">{cfg.mode === 'delivery' ? 'Livraison' : 'Collecte'}</span>}
          {cfg.product && <span className="lhrow__chip">Produit</span>}
          {cfg.voucher && <span className="lhrow__chip lhrow__chip--ruby">{cfg.voucher}</span>}
          {cfg.date && <span className="lhrow__chip">{formatDate(cfg.date)}</span>}
        </div>
      </div>
      <div className="lhrow__stats">
        <div className="lhrow__clicks">{link.clicks.toLocaleString('fr-FR')}</div>
        <div className="lhrow__clicks-lbl">clics</div>
      </div>
      <div className="lhrow__actions" onClick={(e) => e.stopPropagation()}>
        <button className="btn" onClick={onCopy}>{copied ? '✓' : 'Copier URL'}</button>
        <button className="btn" onClick={onLoad}>Charger</button>
        <button className="btn btn--ghost" onClick={() => { if (confirm(`Supprimer "${link.name}" ?`)) onDelete(); }}>×</button>
      </div>
    </div>
  );
}

Object.assign(window, { LinksPage, buildLinkUrl });
