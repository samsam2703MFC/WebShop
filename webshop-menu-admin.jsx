/* =====================================================================
   Menu Builder — back office (admin.html).
   Déclencheur (b) : catégorie (menu_default) + produit (menu_override) ;
   contenu : formules -> étapes -> choix. Tout est écrit via /admin/* (token),
   le serveur recalcule et re-vérifie l'appartenance à chaque écriture.
   Design : réutilise admin.css ; layout fonctionnel dans webshop-menu-admin.css.
   ===================================================================== */
const { useState, useEffect, useCallback, useMemo, useRef } = React;

// API sur la même origine, sous le même chemin que la page (/webshop/api).
const API = location.origin + location.pathname.replace(/[^/]*$/, '') + 'api';
const TK_KEY = 'ws.admin.token';
let TOKEN = (() => { try { return localStorage.getItem(TK_KEY) || ''; } catch (_) { return ''; } })();

async function adminFetch(path, { method = 'GET', body } = {}) {
  const resp = await fetch(API + path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-Admin-Token': TOKEN },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (resp.status === 401 || resp.status === 503) { const e = new Error('unauthorized'); e.code = 401; throw e; }
  const j = await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Error(j.error || ('HTTP ' + resp.status));
  return j;
}

const eur = (n) => (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2) + ' €';

// ── Porte d'entrée : saisie du token admin ──────────────────────────
function Gate({ onOk }) {
  const [val, setVal] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  async function submit() {
    setBusy(true); setErr('');
    TOKEN = val.trim();
    try {
      await adminFetch('/admin/categories');       // ping protégé
      try { localStorage.setItem(TK_KEY, TOKEN); } catch (_) {}
      onOk();
    } catch (e) { setErr(e.code === 401 ? 'Token refusé.' : 'Erreur : ' + e.message); }
    finally { setBusy(false); }
  }
  return (
    <div className="mb-gate">
      <h2>Back office — Menu Builder</h2>
      <p className="mb-muted">Entrez le token administrateur pour continuer.</p>
      <label className="mb-field"><span>Token admin</span>
        <input className="mb-input" type="password" value={val} autoFocus
          onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()}/>
      </label>
      {err && <p className="mb-warn">{err}</p>}
      <button className="btn btn--primary" disabled={busy || !val.trim()} onClick={submit}>
        {busy ? 'Vérification…' : 'Entrer'}
      </button>
    </div>
  );
}

// ── Tri-état override produit ───────────────────────────────────────
function OverrideSeg({ value, onChange }) {
  const opt = [['inherit', 'Hériter'], ['on', 'Forcer oui'], ['off', 'Forcer non']];
  const cur = value === 'on' ? 'on' : value === 'off' ? 'off' : 'inherit';
  return (
    <div className="mb-seg" role="group" aria-label="Menu du produit">
      {opt.map(([k, lbl]) => (
        <button key={k} className={cur === k ? 'is-on' : ''}
          onClick={() => onChange(k === 'inherit' ? null : k)}>{lbl}</button>
      ))}
    </div>
  );
}

// ── Éditeur d'un choix ──────────────────────────────────────────────
function ChoiceRow({ choice, slotId, idx, count, onSaved, onMove }) {
  const [c, setC] = useState(choice);
  const [dirty, setDirty] = useState(false);
  const set = (k, v) => { setC((x) => ({ ...x, [k]: v })); setDirty(true); };
  async function save(patch = {}) {
    const r = await adminFetch('/admin/bundle-choices', { method: 'POST', body: {
      slotId, id: c.id, label: c.label, img: c.img, delta: c.delta, sortOrder: c.sort_order,
      active: c.active ? 1 : 0, ...patch } });
    setDirty(false); onSaved(r.id || c.id);
  }
  return (
    <div className={'mb-card mb-card--choice' + (c.active ? '' : ' mb-muted')}>
      <div className="mb-card__head">
        <div className="mb-row__grow">
          <input className="mb-input" style={{ maxWidth: 220 }} value={c.label || ''} placeholder="Libellé du choix"
            onChange={(e) => set('label', e.target.value)}/>
        </div>
        <div className="mb-tools">
          <button className="mb-iconbtn" disabled={idx === 0} title="Monter" onClick={() => onMove(-1)}>↑</button>
          <button className="mb-iconbtn" disabled={idx === count - 1} title="Descendre" onClick={() => onMove(1)}>↓</button>
          <button className="mb-iconbtn" title={c.active ? 'Désactiver' : 'Activer'}
            onClick={() => { set('active', !c.active); save({ active: c.active ? 0 : 1 }); }}>{c.active ? '⏻' : '✓'}</button>
        </div>
      </div>
      <div className="mb-inline" style={{ marginTop: 8 }}>
        <label className="mb-field"><span>Δ prix (€)</span>
          <input className="mb-input" type="number" step="0.10" value={c.delta ?? 0}
            onChange={(e) => set('delta', parseFloat(e.target.value) || 0)}/></label>
        <label className="mb-field"><span>Image (chemin média)</span>
          <input className="mb-input" value={c.img || ''} placeholder="/webshop/assets/…"
            onChange={(e) => set('img', e.target.value)}/></label>
        <div style={{ flex: '0 0 auto' }}>
          <button className="btn" disabled={!dirty} onClick={() => save()}>Enregistrer</button>
        </div>
      </div>
    </div>
  );
}

// ── Éditeur d'une étape (slot) ──────────────────────────────────────
function SlotCard({ slot, bundleId, idx, count, onChanged, onMove }) {
  const [s, setS] = useState(slot);
  const [dirty, setDirty] = useState(false);
  const set = (k, v) => { setS((x) => ({ ...x, [k]: v })); setDirty(true); };
  async function save(patch = {}) {
    const r = await adminFetch('/admin/bundle-slots', { method: 'POST', body: {
      bundleId, id: s.id, label: s.label, required: s.required ? 1 : 0,
      minSelect: s.min_select, maxSelect: s.max_select, sortOrder: s.sort_order,
      active: s.active ? 1 : 0, ...patch } });
    setDirty(false); onChanged();
  }
  async function addChoice() {
    await adminFetch('/admin/bundle-choices', { method: 'POST', body: { slotId: s.id, label: 'Nouveau choix', delta: 0, sortOrder: (s.choices || []).length } });
    onChanged();
  }
  async function moveChoice(i, dir) {
    const arr = [...(s.choices || [])]; const j = i + dir; if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    await adminFetch('/admin/bundle-reorder', { method: 'POST', body: { entity: 'choice', order: arr.map((c, k) => ({ id: c.id, sortOrder: k })) } });
    onChanged();
  }
  const noChoice = (s.choices || []).filter((c) => c.active).length === 0;
  return (
    <div className={'mb-card mb-card--slot' + (s.active ? '' : ' mb-muted')}>
      <div className="mb-card__head">
        <div className="mb-row__grow">
          <input className="mb-input" style={{ maxWidth: 240 }} value={s.label || ''} placeholder="Nom de l'étape"
            onChange={(e) => set('label', e.target.value)}/>
        </div>
        <div className="mb-tools">
          <button className="mb-iconbtn" disabled={idx === 0} title="Monter" onClick={() => onMove(-1)}>↑</button>
          <button className="mb-iconbtn" disabled={idx === count - 1} title="Descendre" onClick={() => onMove(1)}>↓</button>
          <button className="mb-iconbtn" title={s.active ? 'Désactiver' : 'Activer'}
            onClick={() => { set('active', !s.active); save({ active: s.active ? 0 : 1 }); }}>{s.active ? '⏻' : '✓'}</button>
        </div>
      </div>
      <div className="mb-inline" style={{ marginTop: 8 }}>
        <label className="mb-field"><span>Obligatoire</span>
          <select className="mb-select" value={s.required ? '1' : '0'} onChange={(e) => set('required', e.target.value === '1')}>
            <option value="1">Oui</option><option value="0">Non</option></select></label>
        <label className="mb-field"><span>Min. choix</span>
          <input className="mb-input" type="number" min="0" value={s.min_select ?? 0}
            onChange={(e) => set('min_select', parseInt(e.target.value, 10) || 0)}/></label>
        <label className="mb-field"><span>Max. choix</span>
          <input className="mb-input" type="number" min="1" value={s.max_select ?? 1}
            onChange={(e) => set('max_select', parseInt(e.target.value, 10) || 1)}/></label>
        <div style={{ flex: '0 0 auto' }}>
          <button className="btn" disabled={!dirty} onClick={() => save()}>Enregistrer</button>
        </div>
      </div>
      {noChoice && <p className="mb-warn">Étape sans choix actif — rien à afficher côté client.</p>}
      {(s.choices || []).map((c, i) => (
        <ChoiceRow key={c.id} choice={c} slotId={s.id} idx={i} count={s.choices.length}
          onSaved={onChanged} onMove={(dir) => moveChoice(i, dir)}/>
      ))}
      <button className="btn btn--ghost" style={{ marginLeft: 28, marginTop: 6 }} onClick={addChoice}>+ Ajouter un choix</button>
    </div>
  );
}

// ── Éditeur d'une formule (bundle) ──────────────────────────────────
function BundleCard({ bundle, productId, idx, count, onChanged, onMove }) {
  const [b, setB] = useState(bundle);
  const [dirty, setDirty] = useState(false);
  const set = (k, v) => { setB((x) => ({ ...x, [k]: v })); setDirty(true); };
  async function save(patch = {}) {
    await adminFetch('/admin/bundles', { method: 'POST', body: {
      productId, id: b.id, name: b.name, description: b.description,
      priceModifier: b.price_modifier, sortOrder: b.sort_order, active: b.active ? 1 : 0, ...patch } });
    setDirty(false); onChanged();
  }
  async function addSlot() {
    await adminFetch('/admin/bundle-slots', { method: 'POST', body: { bundleId: b.id, label: 'Nouvelle étape', required: 1, minSelect: 1, maxSelect: 1, sortOrder: (b.slots || []).length } });
    onChanged();
  }
  async function moveSlot(i, dir) {
    const arr = [...(b.slots || [])]; const j = i + dir; if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    await adminFetch('/admin/bundle-reorder', { method: 'POST', body: { entity: 'slot', order: arr.map((s, k) => ({ id: s.id, sortOrder: k })) } });
    onChanged();
  }
  return (
    <div className={'mb-card' + (b.active ? '' : ' mb-muted')}>
      <div className="mb-card__head">
        <div className="mb-row__grow">
          <input className="mb-input" style={{ maxWidth: 260, fontWeight: 600 }} value={b.name || ''} placeholder="Nom de la formule"
            onChange={(e) => set('name', e.target.value)}/>
        </div>
        <div className="mb-tools">
          <button className="mb-iconbtn" disabled={idx === 0} title="Monter" onClick={() => onMove(-1)}>↑</button>
          <button className="mb-iconbtn" disabled={idx === count - 1} title="Descendre" onClick={() => onMove(1)}>↓</button>
          <button className="mb-iconbtn" title={b.active ? 'Désactiver' : 'Activer'}
            onClick={() => { set('active', !b.active); save({ active: b.active ? 0 : 1 }); }}>{b.active ? '⏻' : '✓'}</button>
        </div>
      </div>
      <div className="mb-inline" style={{ marginTop: 8 }}>
        <label className="mb-field"><span>Modif. prix formule (€)</span>
          <input className="mb-input" type="number" step="0.10" value={b.price_modifier ?? 0}
            onChange={(e) => set('price_modifier', parseFloat(e.target.value) || 0)}/></label>
        <label className="mb-field" style={{ flex: 3 }}><span>Description</span>
          <input className="mb-input" value={b.description || ''} onChange={(e) => set('description', e.target.value)}/></label>
        <div style={{ flex: '0 0 auto' }}>
          <button className="btn" disabled={!dirty} onClick={() => save()}>Enregistrer</button>
        </div>
      </div>
      {(b.slots || []).map((s, i) => (
        <SlotCard key={s.id} slot={s} bundleId={b.id} idx={i} count={b.slots.length}
          onChanged={onChanged} onMove={(dir) => moveSlot(i, dir)}/>
      ))}
      <button className="btn btn--ghost" style={{ marginLeft: 14, marginTop: 6 }} onClick={addSlot}>+ Ajouter une étape</button>
    </div>
  );
}

// ── Aperçu (rendu client compact) ───────────────────────────────────
function Preview({ product, bundles }) {
  const active = (bundles || []).filter((b) => b.active);
  const base = Number(product.price) || 0;
  return (
    <div className="mb-prev">
      <div className="mb-prev__price">Base : {eur(base)}</div>
      {active.length === 0 && <p className="mb-muted">Aucune formule active — produit simple.</p>}
      {active.map((b) => {
        // Prix « au plus bas » : base + modif formule + min choix les moins chers.
        let low = base + (Number(b.price_modifier) || 0);
        (b.slots || []).filter((s) => s.active).forEach((s) => {
          const ch = (s.choices || []).filter((c) => c.active).map((c) => Number(c.delta) || 0).sort((a, z) => a - z);
          for (let i = 0; i < (s.min_select || 0) && i < ch.length; i++) low += ch[i];
        });
        return (
          <div key={b.id} style={{ marginTop: 12 }}>
            <div className="mb-prev__slot-h">{b.name} — dès {eur(low)}</div>
            {(b.slots || []).filter((s) => s.active).map((s) => (
              <div key={s.id} className="mb-prev__slot">
                <div className="mb-prev__slot-h">
                  {s.label} <span className="mb-muted">
                    ({s.required ? 'obligatoire' : 'facultatif'} · {s.min_select === s.max_select ? `choisir ${s.max_select}` : `${s.min_select}–${s.max_select}`})
                  </span>
                </div>
                {(s.choices || []).filter((c) => c.active).map((c) => (
                  <div key={c.id} className="mb-prev__choice">
                    <span>{c.label}</span><span>{c.delta ? (c.delta > 0 ? '+' : '') + eur(c.delta) : '—'}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        );
      })}
    </div>
  );
}

// ── Application ─────────────────────────────────────────────────────
function App() {
  const [ready, setReady] = useState(!!TOKEN);
  const [tab, setTab] = useState('products');       // 'products' | 'categories'
  const [cats, setCats] = useState([]);
  const [q, setQ] = useState('');
  const [catFilter, setCatFilter] = useState(0);
  const [products, setProducts] = useState([]);
  const [sel, setSel] = useState(null);             // produit sélectionné
  const [bundles, setBundles] = useState([]);
  const [err, setErr] = useState('');

  const loadCats = useCallback(async () => { try { setCats(await adminFetch('/admin/categories')); } catch (e) { fail(e); } }, []);
  const loadProducts = useCallback(async () => {
    try {
      const qs = new URLSearchParams();
      if (q) qs.set('q', q); if (catFilter) qs.set('categoryId', String(catFilter));
      setProducts(await adminFetch('/admin/products?' + qs.toString()));
    } catch (e) { fail(e); }
  }, [q, catFilter]);
  const loadBundles = useCallback(async (pid) => { try { setBundles(await adminFetch('/admin/bundles?productId=' + pid)); } catch (e) { fail(e); } }, []);

  function fail(e) { if (e.code === 401) { setReady(false); } else setErr(e.message); }

  useEffect(() => { if (ready) { loadCats(); loadProducts(); } }, [ready]);
  useEffect(() => { if (ready) loadProducts(); }, [q, catFilter]);
  useEffect(() => { if (sel) loadBundles(sel.id); }, [sel]);

  const refresh = useCallback(() => { if (sel) loadBundles(sel.id); loadProducts(); }, [sel]);

  async function toggleCatDefault(c) {
    await adminFetch('/admin/category-menu', { method: 'POST', body: { categoryId: c.id, menuDefault: c.menu_default ? 0 : 1 } });
    loadCats(); loadProducts(); if (sel) loadBundles(sel.id);
  }
  async function setOverride(ov) {
    await adminFetch('/admin/product-menu', { method: 'POST', body: { productId: sel.id, menuOverride: ov } });
    const ps = await adminFetch('/admin/products?' + (catFilter ? 'categoryId=' + catFilter : ''));
    setProducts(ps); const upd = ps.find((p) => p.id === sel.id); if (upd) setSel(upd);
  }
  async function addBundle() {
    await adminFetch('/admin/bundles', { method: 'POST', body: { productId: sel.id, name: 'Nouvelle formule', priceModifier: 0, sortOrder: bundles.length } });
    loadBundles(sel.id);
  }
  async function moveBundle(i, dir) {
    const arr = [...bundles]; const j = i + dir; if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    await adminFetch('/admin/bundle-reorder', { method: 'POST', body: { entity: 'bundle', order: arr.map((b, k) => ({ id: b.id, sortOrder: k })) } });
    loadBundles(sel.id);
  }

  if (!ready) return <Gate onOk={() => setReady(true)}/>;

  const effOn = sel && Number(sel.has_menu_options) === 1;
  const origin = sel ? (sel.menu_override === 'on' ? 'forcé' : sel.menu_override === 'off' ? 'forcé' : (Number(sel.category_menu_default) ? 'hérité de la catégorie' : 'défaut')) : '';

  return (
    <div className="mb-wrap admin">
      <aside className="mb-side">
        <div className="mb-seg" style={{ width: '100%' }}>
          <button style={{ flex: 1 }} className={tab === 'products' ? 'is-on' : ''} onClick={() => setTab('products')}>Produits</button>
          <button style={{ flex: 1 }} className={tab === 'categories' ? 'is-on' : ''} onClick={() => setTab('categories')}>Catégories</button>
        </div>

        {tab === 'categories' && (
          <>
            <div className="mb-h">Déclencheur par catégorie</div>
            {cats.map((c) => (
              <div key={c.id} className="mb-row">
                <div className="mb-row__grow">
                  <div className="mb-row__title">{c.label}</div>
                  <div className="mb-row__sub">{c.product_count} produits</div>
                </div>
                <button className={'mb-badge ' + (Number(c.menu_default) ? 'mb-badge--on' : 'mb-badge--off')}
                  onClick={() => toggleCatDefault(c)} title="Basculer menu par défaut">
                  {Number(c.menu_default) ? 'Menu par défaut' : 'Off'}
                </button>
              </div>
            ))}
          </>
        )}

        {tab === 'products' && (
          <>
            <div className="mb-h">Rechercher un produit</div>
            <input className="mb-input" placeholder="Nom du produit…" value={q} onChange={(e) => setQ(e.target.value)}/>
            <select className="mb-select" style={{ marginTop: 8 }} value={catFilter} onChange={(e) => setCatFilter(parseInt(e.target.value, 10) || 0)}>
              <option value={0}>Toutes catégories</option>
              {cats.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
            </select>
            <div className="mb-h">Produits ({products.length})</div>
            {products.map((p) => (
              <button key={p.id} className={'mb-row mb-row--btn' + (sel && sel.id === p.id ? ' is-active' : '')} onClick={() => setSel(p)}>
                <div className="mb-row__grow">
                  <div className="mb-row__title">{p.name}</div>
                  <div className="mb-row__sub">{p.category || '—'} · {p.bundle_count} formule(s)</div>
                </div>
                <span className={'mb-badge ' + (Number(p.has_menu_options) ? 'mb-badge--on' : 'mb-badge--off')}>
                  {Number(p.has_menu_options) ? 'Menu' : '—'}
                </span>
              </button>
            ))}
          </>
        )}
      </aside>

      <main className="mb-main">
        {err && <p className="mb-warn">{err}</p>}
        {!sel && <p className="mb-muted">Sélectionnez un produit pour éditer son menu, ou activez le menu par défaut d'une catégorie.</p>}
        {sel && (
          <>
            <div className="page-head">
              <div>
                <div className="page-head__title">{sel.name}</div>
                <div className="page-head__sub">{sel.category || '—'} · {eur(Number(sel.price) || 0)}</div>
              </div>
            </div>

            <div className="mb-card">
              <div className="mb-inline" style={{ alignItems: 'center' }}>
                <div style={{ flex: '0 0 auto' }}><span className="mb-row__sub">Menu du produit</span><br/>
                  <OverrideSeg value={sel.menu_override} onChange={setOverride}/>
                </div>
                <div>
                  <span className={'mb-badge ' + (effOn ? 'mb-badge--on' : 'mb-badge--off')}>
                    Menu effectif : {effOn ? 'Oui' : 'Non'}
                  </span>
                  <span className="mb-row__sub"> ({origin})</span>
                  {effOn && bundles.filter((b) => b.active).length === 0 &&
                    <p className="mb-warn">Menu armé mais aucune formule active — rien ne s'affiche côté client.</p>}
                </div>
              </div>
            </div>

            <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="mb-h">Formules</div>
                {bundles.map((b, i) => (
                  <BundleCard key={b.id} bundle={b} productId={sel.id} idx={i} count={bundles.length}
                    onChanged={refresh} onMove={(dir) => moveBundle(i, dir)}/>
                ))}
                <button className="btn btn--primary" onClick={addBundle}>+ Ajouter une formule</button>
              </div>
              <div style={{ flex: '0 0 320px' }}>
                <div className="mb-h">Aperçu client</div>
                <Preview product={sel} bundles={bundles}/>
              </div>
            </div>
          </>
        )}
      </main>
    </div>
  );
}

const mount = document.getElementById('admin-root');
if (mount) ReactDOM.createRoot(mount).render(<App/>);
