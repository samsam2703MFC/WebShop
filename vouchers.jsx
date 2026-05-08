// vouchers.jsx — Voucher management
// Dense table (status, code, type, scope, usage bar, validity) + create/edit drawer
// Realistic L'Atelier-flavored campaigns

const VOUCHER_PRODUCTS = [
  { id: 'tarte-citron',      name: 'Tarte au citron meringuée' },
  { id: 'tarte-praline',     name: 'Tarte praliné noisette' },
  { id: 'salade-bressane',   name: 'Salade bressane' },
  { id: 'parfait-vanille',   name: 'Parfait vanille bourbon' },
  { id: 'plat-saumon',       name: 'Saumon, riz, légumes verts' },
  { id: 'plat-volaille',     name: 'Volaille fermière' },
  { id: 'cookie-chocolat',   name: 'Cookie double chocolat' },
  { id: 'pain-cereales',     name: 'Pain aux céréales' },
];

const VOUCHER_CATEGORIES = [
  { id: 'patisseries',  name: 'Pâtisseries' },
  { id: 'salades',      name: 'Salades' },
  { id: 'plats',        name: 'Plats du jour' },
  { id: 'douceurs',     name: 'Douceurs' },
  { id: 'boulangerie',  name: 'Boulangerie' },
];

const VOUCHER_COLLECTIONS = [
  { id: 'menu-midi',    name: 'Menu de midi' },
  { id: 'brunch',       name: 'Le Brunch' },
  { id: 'goute',        name: 'Le Goûter' },
  { id: 'apero',        name: 'Apéro Box' },
];

const VOUCHER_SHOPS = ['chatelain', 'sablon', 'carre', 'zuid', 'grognon', 'brugge'];

// Sample vouchers — realistic L'Atelier campaigns
const SEED_VOUCHERS = [
  {
    id: 'v1', code: 'RENTREE2026', type: 'percent', value: 15,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 25, usageLimit: 500, used: 187,
    validFrom: '2026-04-15', validTo: '2026-05-31',
    status: 'active',
  },
  {
    id: 'v2', code: 'CHATELAIN-VIP', type: 'percent', value: 20,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['chatelain'], channels: 'webshop',
    minOrder: 0, usageLimit: 100, used: 42,
    validFrom: '2026-05-01', validTo: '2026-06-30',
    status: 'active',
  },
  {
    id: 'v3', code: 'BRUNCH-SABLON', type: 'fixed', value: 5,
    scope: 'collections', products: [], categories: [], collections: ['brunch'],
    shops: ['sablon', 'chatelain'], channels: 'webshop',
    minOrder: 35, usageLimit: 200, used: 89,
    validFrom: '2026-04-01', validTo: '2026-12-31',
    status: 'active',
  },
  {
    id: 'v4', code: 'OFFICE-LAUNCH', type: 'percent', value: 10,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'office',
    minOrder: 100, usageLimit: 50, used: 12,
    validFrom: '2026-05-01', validTo: '2026-07-31',
    status: 'active',
  },
  {
    id: 'v5', code: 'TARTES-SAMEDI', type: 'percent', value: 25,
    scope: 'categories', products: [], categories: ['patisseries'], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 0, usageLimit: 1000, used: 736,
    validFrom: '2026-03-01', validTo: '2026-05-15',
    status: 'expiring',
  },
  {
    id: 'v6', code: 'GOUTER10', type: 'fixed', value: 3,
    scope: 'collections', products: [], categories: [], collections: ['goute'],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 12, usageLimit: 300, used: 298,
    validFrom: '2026-04-01', validTo: '2026-06-30',
    status: 'expiring',
  },
  {
    id: 'v7', code: 'BIENVENUE', type: 'fixed', value: 8,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 30, usageLimit: 0, used: 1240,
    validFrom: '2026-01-01', validTo: '2026-12-31',
    status: 'active',
  },
  {
    id: 'v8', code: 'APERO-VENDREDI', type: 'percent', value: 12,
    scope: 'collections', products: [], categories: [], collections: ['apero'],
    shops: ['chatelain', 'sablon', 'carre'], channels: 'webshop',
    minOrder: 25, usageLimit: 400, used: 156,
    validFrom: '2026-05-01', validTo: '2026-09-30',
    status: 'active',
  },
  {
    id: 'v9', code: 'NOEL2025', type: 'percent', value: 30,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 50, usageLimit: 200, used: 200,
    validFrom: '2025-12-01', validTo: '2025-12-31',
    status: 'expired',
  },
  {
    id: 'v10', code: 'BRUGGE-OPEN', type: 'fixed', value: 10,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['brugge'], channels: 'webshop',
    minOrder: 25, usageLimit: 150, used: 0,
    validFrom: '2026-06-01', validTo: '2026-07-31',
    status: 'scheduled',
  },
  {
    id: 'v11', code: 'PARFAIT-DUO', type: 'percent', value: 20,
    scope: 'products', products: ['parfait-vanille'], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 0, usageLimit: 100, used: 23,
    validFrom: '2026-05-01', validTo: '2026-06-30',
    status: 'active',
  },
  {
    id: 'v12', code: 'TEAM-NAMUR', type: 'percent', value: 15,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['grognon'], channels: 'office',
    minOrder: 75, usageLimit: 80, used: 8,
    validFrom: '2026-05-01', validTo: '2026-08-31',
    status: 'active',
  },
];

// Persist vouchers to localStorage so admin <-> storefront stay in sync (and survive reload)
const VOUCHERS_KEY = 'latelier-admin:vouchers';

function loadVouchers() {
  try {
    const raw = localStorage.getItem(VOUCHERS_KEY);
    if (!raw) return SEED_VOUCHERS;
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed) && parsed.length) return parsed;
  } catch {}
  return SEED_VOUCHERS;
}

function saveVouchers(list) {
  try { localStorage.setItem(VOUCHERS_KEY, JSON.stringify(list)); } catch {}
}

// Validity status calc — reads validity dates and usageLimit/used.
function deriveStatus(v, now = new Date()) {
  const from = new Date(v.validFrom);
  const to = new Date(v.validTo);
  if (v.usageLimit > 0 && v.used >= v.usageLimit) return 'exhausted';
  if (now < from) return 'scheduled';
  if (now > to) return 'expired';
  // Expiring soon: < 14 days remaining
  const days = Math.ceil((to - now) / (1000 * 60 * 60 * 24));
  if (days <= 14) return 'expiring';
  return 'active';
}

const STATUS_LABELS = {
  active:    { lbl: 'Actif',     cls: 'vstatus--active' },
  scheduled: { lbl: 'Planifié',  cls: 'vstatus--scheduled' },
  expiring:  { lbl: 'Expire',    cls: 'vstatus--expiring' },
  expired:   { lbl: 'Expiré',    cls: 'vstatus--expired' },
  exhausted: { lbl: 'Épuisé',    cls: 'vstatus--exhausted' },
};

const CHANNEL_LABELS = {
  webshop: 'Webshop',
  office: 'Office Shop',
  both: 'Webshop + Office',
};

function formatScope(v) {
  if (v.scope === 'cart')        return 'Panier entier';
  if (v.scope === 'products')    return `${v.products.length} produit${v.products.length > 1 ? 's' : ''}`;
  if (v.scope === 'categories')  return `${v.categories.length} catégorie${v.categories.length > 1 ? 's' : ''}`;
  if (v.scope === 'collections') return `${v.collections.length} collection${v.collections.length > 1 ? 's' : ''}`;
  if (v.scope === 'shops')       return `${v.shops.length} boutique${v.shops.length > 1 ? 's' : ''}`;
  return '—';
}

function formatValue(v) {
  return v.type === 'percent' ? `−${v.value}%` : `−${v.value}€`;
}

function formatDate(s) {
  const d = new Date(s);
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit' });
}

// ─────────────────────────────────────────────────────────────
// Vouchers page
// ─────────────────────────────────────────────────────────────
function VouchersPage() {
  const [vouchers, setVouchers] = useState(loadVouchers);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [editing, setEditing] = useState(null);   // voucher being edited or {} for new

  useEffect(() => { saveVouchers(vouchers); }, [vouchers]);

  // Recompute status from dates so seeded data stays accurate over time
  const enriched = useMemo(
    () => vouchers.map((v) => ({ ...v, status: deriveStatus(v) })),
    [vouchers],
  );

  const filtered = useMemo(() => {
    return enriched.filter((v) => {
      if (filter !== 'all' && v.status !== filter) return false;
      if (search && !v.code.toLowerCase().includes(search.toLowerCase())) return false;
      return true;
    });
  }, [enriched, filter, search]);

  const counts = useMemo(() => {
    const c = { all: enriched.length, active: 0, scheduled: 0, expiring: 0, expired: 0, exhausted: 0 };
    enriched.forEach((v) => { c[v.status] = (c[v.status] || 0) + 1; });
    return c;
  }, [enriched]);

  const onSave = (next) => {
    setVouchers((list) => {
      const exists = list.findIndex((x) => x.id === next.id);
      if (exists >= 0) {
        const copy = list.slice(); copy[exists] = next; return copy;
      }
      return [next, ...list];
    });
    setEditing(null);
  };

  const onDelete = (id) => {
    setVouchers((list) => list.filter((x) => x.id !== id));
    setEditing(null);
  };

  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Marketing', 'Vouchers']}/>

      <div className="page-head">
        <div>
          <h1 className="page-head__title">Codes promo & vouchers</h1>
          <p className="page-head__sub">{counts.active} actifs · {counts.scheduled} planifiés · {counts.expiring} expirent bientôt</p>
        </div>
        <div className="page-head__actions">
          <button className="btn"><NavIcon k="clipboard" size={12}/> Exporter CSV</button>
          <button className="btn btn--primary" onClick={() => setEditing({})}>
            <svg viewBox="0 0 24 24" width={12} height={12}><path d="M12 5v14M5 12h14" stroke="currentColor" fill="none" strokeWidth="2"/></svg>
            Nouveau voucher
          </button>
        </div>
      </div>

      <div className="vstats">
        <VStat label="Vouchers actifs" value={counts.active} accent="active"/>
        <VStat label="Utilisations ce mois" value={enriched.reduce((s, v) => s + (v.status !== 'expired' ? v.used : 0), 0).toLocaleString('fr-FR')}/>
        <VStat label="Économie offerte" value="€4 287" sub="cumulé 30 j."/>
        <VStat label="Taux d'utilisation" value="58%" sub="vouchers actifs"/>
      </div>

      <div className="toolbar">
        <div className="toolbar__group">
          {[
            ['all', 'Tous', counts.all],
            ['active', 'Actifs', counts.active],
            ['scheduled', 'Planifiés', counts.scheduled],
            ['expiring', 'Expirent', counts.expiring],
            ['expired', 'Expirés', counts.expired],
            ['exhausted', 'Épuisés', counts.exhausted],
          ].map(([k, lbl, n]) => (
            <button
              key={k}
              className={`toolbar__chip${filter === k ? ' toolbar__chip--active' : ''}`}
              onClick={() => setFilter(k)}
            >
              {lbl} <span style={{ opacity: 0.6, marginLeft: 4 }}>{n}</span>
            </button>
          ))}
        </div>
        <div className="toolbar__search" style={{ marginLeft: 'auto' }}>
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6" stroke="currentColor" fill="none" strokeWidth="1.7"/><path d="M16 16l4 4" stroke="currentColor" fill="none" strokeWidth="1.7"/></svg>
          <input
            placeholder="Code, nom de campagne…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      <div className="voucher-table-wrap">
        <table className="voucher-table">
          <thead>
            <tr>
              <th style={{ width: 110 }}>Statut</th>
              <th>Code</th>
              <th style={{ width: 90 }}>Réduction</th>
              <th>Périmètre</th>
              <th style={{ width: 110 }}>Canal</th>
              <th>Boutiques</th>
              <th style={{ width: 180 }}>Utilisation</th>
              <th style={{ width: 130 }}>Validité</th>
              <th style={{ width: 40 }}></th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((v) => (
              <VoucherRow key={v.id} voucher={v} onClick={() => setEditing(v)}/>
            ))}
            {filtered.length === 0 && (
              <tr><td colSpan={9} className="voucher-table__empty">Aucun voucher ne correspond aux filtres.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {editing && (
        <VoucherDrawer
          voucher={editing}
          onClose={() => setEditing(null)}
          onSave={onSave}
          onDelete={onDelete}
        />
      )}
    </div>
  );
}

function VStat({ label, value, sub, accent }) {
  return (
    <div className={`vstat${accent ? ' vstat--' + accent : ''}`}>
      <div className="vstat__label">{label}</div>
      <div className="vstat__value">{value}</div>
      {sub && <div className="vstat__sub">{sub}</div>}
    </div>
  );
}

function VoucherRow({ voucher, onClick }) {
  const v = voucher;
  const s = STATUS_LABELS[v.status] || STATUS_LABELS.active;
  const pct = v.usageLimit > 0 ? Math.min(100, Math.round((v.used / v.usageLimit) * 100)) : null;
  const shops = v.shops.length === VOUCHER_SHOPS.length
    ? 'Toutes'
    : v.shops.slice(0, 3).map((id) => SHOPS[id].short).join(' · ') + (v.shops.length > 3 ? ` +${v.shops.length - 3}` : '');

  return (
    <tr className="voucher-row" onClick={onClick}>
      <td>
        <span className={`vstatus ${s.cls}`}>
          <span className="vstatus__dot"/> {s.lbl}
        </span>
      </td>
      <td>
        <div className="vcode">{v.code}</div>
        <div className="vcode__sub">
          {v.minOrder > 0 ? `Min. ${v.minOrder}€` : 'Sans minimum'}
        </div>
      </td>
      <td className="vvalue">{formatValue(v)}</td>
      <td>
        <div className="vscope">{formatScope(v)}</div>
      </td>
      <td>
        <span className={`vchannel vchannel--${v.channels}`}>{CHANNEL_LABELS[v.channels]}</span>
      </td>
      <td>
        <div className="vshops">
          <div className="vshops__dots">
            {v.shops.slice(0, 6).map((id) => (
              <span key={id} className="vshops__dot" style={{ background: SHOPS[id].color }} title={SHOPS[id].name}/>
            ))}
          </div>
          <span className="vshops__txt">{shops}</span>
        </div>
      </td>
      <td>
        {pct !== null ? (
          <>
            <div className="vusage__row">
              <span>{v.used.toLocaleString('fr-FR')} / {v.usageLimit.toLocaleString('fr-FR')}</span>
              <span className="vusage__pct">{pct}%</span>
            </div>
            <div className="vusage__bar"><div className="vusage__fill" style={{ width: pct + '%' }}/></div>
          </>
        ) : (
          <div className="vusage__row vusage__row--unlimited">
            <span>{v.used.toLocaleString('fr-FR')}</span>
            <span className="vusage__pct">illimité</span>
          </div>
        )}
      </td>
      <td>
        <div className="vvalidity">{formatDate(v.validFrom)}</div>
        <div className="vvalidity__sub">→ {formatDate(v.validTo)}</div>
      </td>
      <td><button className="vrow__more" onClick={(e) => { e.stopPropagation(); onClick(); }}>›</button></td>
    </tr>
  );
}

// ─────────────────────────────────────────────────────────────
// Drawer — create / edit voucher
// ─────────────────────────────────────────────────────────────
function VoucherDrawer({ voucher, onClose, onSave, onDelete }) {
  const isNew = !voucher.id;
  const [draft, setDraft] = useState(() => ({
    id: voucher.id || ('v' + Math.random().toString(36).slice(2, 8)),
    code: voucher.code || '',
    type: voucher.type || 'percent',
    value: voucher.value ?? 10,
    scope: voucher.scope || 'cart',
    products: voucher.products || [],
    categories: voucher.categories || [],
    collections: voucher.collections || [],
    shops: voucher.shops || VOUCHER_SHOPS.slice(),
    channels: voucher.channels || 'both',
    minOrder: voucher.minOrder ?? 0,
    usageLimit: voucher.usageLimit ?? 100,
    used: voucher.used ?? 0,
    validFrom: voucher.validFrom || new Date().toISOString().slice(0, 10),
    validTo: voucher.validTo || new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
  }));

  const set = (k, v) => setDraft((d) => ({ ...d, [k]: v }));
  const toggle = (k, val) => setDraft((d) => ({
    ...d,
    [k]: d[k].includes(val) ? d[k].filter((x) => x !== val) : [...d[k], val],
  }));

  const valid = draft.code.trim().length >= 3 && draft.value > 0 && draft.validFrom <= draft.validTo;

  return (
    <>
      <div className="drawer-scrim" onClick={onClose}/>
      <aside className="drawer">
        <div className="drawer__head">
          <div>
            <div className="drawer__eyebrow">{isNew ? 'Nouveau voucher' : 'Modifier'}</div>
            <h2 className="drawer__title">
              {draft.code ? <span className="vcode" style={{ fontSize: 18 }}>{draft.code}</span> : 'Nouveau voucher'}
            </h2>
          </div>
          <button className="drawer__close" onClick={onClose}>×</button>
        </div>

        <div className="drawer__body">

          <Section title="Code & réduction">
            <Field label="Code">
              <input
                className="input input--mono"
                value={draft.code}
                onChange={(e) => set('code', e.target.value.toUpperCase().replace(/\s+/g, '-'))}
                placeholder="RENTREE2026"
              />
              <button className="ghost-btn" type="button" onClick={() => set('code', genCode())}>Générer</button>
            </Field>

            <Field label="Type de réduction">
              <Radio
                options={[['percent', 'Pourcentage'], ['fixed', 'Montant fixe (€)']]}
                value={draft.type}
                onChange={(v) => set('type', v)}
              />
            </Field>

            <Field label={draft.type === 'percent' ? 'Valeur (%)' : 'Valeur (€)'}>
              <input
                className="input input--num"
                type="number"
                value={draft.value}
                min={0}
                onChange={(e) => set('value', Number(e.target.value))}
              />
              <span className="input-suffix">{draft.type === 'percent' ? '%' : '€'}</span>
            </Field>
          </Section>

          <Section title="Périmètre — sur quoi le code s'applique">
            <Field label="Type">
              <select className="input" value={draft.scope} onChange={(e) => set('scope', e.target.value)}>
                <option value="cart">Panier entier</option>
                <option value="products">Produits spécifiques</option>
                <option value="categories">Catégories</option>
                <option value="collections">Collections</option>
                <option value="shops">Boutiques uniquement</option>
              </select>
            </Field>

            {draft.scope === 'products' && (
              <ChipMultiSelect
                label="Produits"
                options={VOUCHER_PRODUCTS}
                selected={draft.products}
                toggle={(id) => toggle('products', id)}
              />
            )}
            {draft.scope === 'categories' && (
              <ChipMultiSelect
                label="Catégories"
                options={VOUCHER_CATEGORIES}
                selected={draft.categories}
                toggle={(id) => toggle('categories', id)}
              />
            )}
            {draft.scope === 'collections' && (
              <ChipMultiSelect
                label="Collections"
                options={VOUCHER_COLLECTIONS}
                selected={draft.collections}
                toggle={(id) => toggle('collections', id)}
              />
            )}
          </Section>

          <Section title="Boutiques">
            <div className="shop-multi">
              {VOUCHER_SHOPS.map((id) => {
                const shop = SHOPS[id];
                const active = draft.shops.includes(id);
                return (
                  <button
                    key={id}
                    type="button"
                    className={`shop-multi__chip${active ? ' shop-multi__chip--active' : ''}`}
                    onClick={() => toggle('shops', id)}
                  >
                    <span className="shop-multi__avatar" style={{ background: shop.color }}>{shop.short}</span>
                    <div>
                      <div className="shop-multi__name">{shop.name}</div>
                      <div className="shop-multi__city">{shop.city}</div>
                    </div>
                    <span className={`shop-multi__check${active ? ' shop-multi__check--on' : ''}`}>{active ? '✓' : ''}</span>
                  </button>
                );
              })}
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
              <button className="ghost-btn" type="button" onClick={() => set('shops', VOUCHER_SHOPS.slice())}>Tout sélectionner</button>
              <button className="ghost-btn" type="button" onClick={() => set('shops', [])}>Tout désélectionner</button>
            </div>
          </Section>

          <Section title="Canal">
            <Radio
              options={[['webshop', 'Webshop'], ['office', 'Office Shop'], ['both', 'Les deux']]}
              value={draft.channels}
              onChange={(v) => set('channels', v)}
            />
          </Section>

          <Section title="Conditions & limites">
            <Field label="Montant minimum (€)">
              <input
                className="input input--num"
                type="number"
                value={draft.minOrder}
                min={0}
                onChange={(e) => set('minOrder', Number(e.target.value))}
              />
              <span className="input-hint">0 = sans minimum</span>
            </Field>

            <Field label="Limite d'utilisation">
              <input
                className="input input--num"
                type="number"
                value={draft.usageLimit}
                min={0}
                onChange={(e) => set('usageLimit', Number(e.target.value))}
              />
              <span className="input-hint">0 = illimité</span>
            </Field>
          </Section>

          <Section title="Période de validité">
            <div style={{ display: 'flex', gap: 12 }}>
              <Field label="Du">
                <input
                  className="input"
                  type="date"
                  value={draft.validFrom}
                  onChange={(e) => set('validFrom', e.target.value)}
                />
              </Field>
              <Field label="Au">
                <input
                  className="input"
                  type="date"
                  value={draft.validTo}
                  onChange={(e) => set('validTo', e.target.value)}
                />
              </Field>
            </div>
          </Section>

          <div className="drawer__preview">
            <div className="drawer__preview-label">Aperçu</div>
            <div className="vpreview">
              <div className="vpreview__code">{draft.code || 'CODE'}</div>
              <div className="vpreview__value">{formatValue(draft)}</div>
              <div className="vpreview__sub">
                {formatScope(draft)} · {CHANNEL_LABELS[draft.channels]} · {draft.shops.length} boutique{draft.shops.length > 1 ? 's' : ''}
              </div>
              <div className="vpreview__sub">
                {draft.minOrder > 0 ? `Min. ${draft.minOrder}€ · ` : ''}
                {draft.usageLimit > 0 ? `${draft.usageLimit} utilisations max` : 'Illimité'} ·
                {' '}{formatDate(draft.validFrom)} → {formatDate(draft.validTo)}
              </div>
            </div>
          </div>

        </div>

        <div className="drawer__foot">
          {!isNew && (
            <button className="btn btn--danger" onClick={() => {
              if (confirm(`Supprimer le voucher ${draft.code} ?`)) onDelete(draft.id);
            }}>Supprimer</button>
          )}
          <div style={{ flex: 1 }}/>
          <button className="btn" onClick={onClose}>Annuler</button>
          <button className="btn btn--primary" disabled={!valid} onClick={() => onSave(draft)}>
            {isNew ? 'Créer le voucher' : 'Enregistrer'}
          </button>
        </div>
      </aside>
    </>
  );
}

function Section({ title, children }) {
  return (
    <div className="dsection">
      <div className="dsection__title">{title}</div>
      <div className="dsection__body">{children}</div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label className="field">
      <span className="field__lbl">{label}</span>
      <span className="field__row">{children}</span>
    </label>
  );
}

function Radio({ options, value, onChange }) {
  return (
    <div className="radio-group">
      {options.map(([v, l]) => (
        <button
          key={v}
          type="button"
          className={`radio${value === v ? ' radio--active' : ''}`}
          onClick={() => onChange(v)}
        >
          <span className="radio__dot"/>
          {l}
        </button>
      ))}
    </div>
  );
}

function ChipMultiSelect({ label, options, selected, toggle }) {
  return (
    <Field label={label}>
      <div className="chip-multi">
        {options.map((o) => (
          <button
            key={o.id}
            type="button"
            className={`chip-multi__chip${selected.includes(o.id) ? ' chip-multi__chip--active' : ''}`}
            onClick={() => toggle(o.id)}
          >
            {o.name}
          </button>
        ))}
      </div>
    </Field>
  );
}

function genCode() {
  const wraps = ['MENU', 'ATELIER', 'GOUTER', 'BRUNCH', 'OFFICE'];
  const yr = new Date().getFullYear();
  const w = wraps[Math.floor(Math.random() * wraps.length)];
  const n = Math.floor(Math.random() * 90) + 10;
  return `${w}${n}-${yr}`;
}

Object.assign(window, { VouchersPage, loadVouchers, saveVouchers, deriveStatus, VOUCHER_PRODUCTS, VOUCHER_CATEGORIES, VOUCHER_COLLECTIONS, VOUCHER_SHOPS });
