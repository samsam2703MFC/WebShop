// =========================================================================
// ALLERGEN ICONS + MODAL
// Brand allergen icon system, lifted from the design system's allergens.html.
// 14 EU major allergens, line-art at 24×24 viewBox, 1.4px stroke.
//
// Usage:
//   <AllergenIcon name="gluten" size={14} />
//   <AllergensRow list={['gluten','milk','egg']} />   ← replaces old dots
//   <AllergensModal open={...} onClose={...} />        ← navbar opens this
// =========================================================================

(() => {
  const { useEffect, useRef } = React;

  // --- Map every allergen key our product DB might use to a canonical id + label.
  //     Synonyms (almond → nuts, peanut → peanuts, …) are absorbed here so the
  //     product data doesn't have to change.
  const ALLERGENS = [
    { id: 'gluten',      label: 'Gluten',          aliases: ['gluten','wheat','ble','blé'] },
    { id: 'crustaceans', label: 'Crustacés',       aliases: ['crustacean','crustaceans','crustaces','crustacés','shrimp','crab'] },
    { id: 'eggs',        label: 'Œufs',            aliases: ['egg','eggs','oeuf','oeufs'] },
    { id: 'fish',        label: 'Poisson',         aliases: ['fish','poisson'] },
    { id: 'peanuts',     label: 'Arachides',       aliases: ['peanut','peanuts','arachide','arachides'] },
    { id: 'soy',         label: 'Soja',            aliases: ['soy','soja','soya'] },
    { id: 'milk',        label: 'Lait',            aliases: ['milk','lait','dairy','lactose'] },
    { id: 'nuts',        label: 'Fruits à coque',  aliases: ['nut','nuts','almond','almonds','amande','amandes','hazelnut','walnut','noix'] },
    { id: 'celery',      label: 'Céleri',          aliases: ['celery','celeri','céleri'] },
    { id: 'mustard',     label: 'Moutarde',        aliases: ['mustard','moutarde'] },
    { id: 'sesame',      label: 'Sésame',          aliases: ['sesame','sésame'] },
    { id: 'sulphites',   label: 'Sulfites',        aliases: ['sulphite','sulphites','sulfite','sulfites','so2'] },
    { id: 'lupin',       label: 'Lupin',           aliases: ['lupin','lupine'] },
    { id: 'molluscs',    label: 'Mollusques',      aliases: ['mollusc','molluscs','mollusque','mollusques'] },
  ];

  // alias → canonical id lookup
  const ALIAS_MAP = {};
  for (const a of ALLERGENS) for (const k of a.aliases) ALIAS_MAP[k.toLowerCase()] = a.id;

  function resolve(name) {
    if (!name) return null;
    return ALIAS_MAP[String(name).toLowerCase()] || null;
  }
  function labelOf(name) {
    const id = resolve(name);
    if (!id) return name;
    return ALLERGENS.find((a) => a.id === id)?.label || name;
  }

  // --- Inline SVG paths copied verbatim from the design system spec (allergens.html). ---
  // Each entry is the inner geometry; we wrap with a 24x24 viewBox at render time.
  const PATHS = {
    gluten: (
      <g>
        <path d="M12 3v18"/>
        <path d="M12 7c-1.6 0-3 -1 -3.6 -2.4 1.6 0 3 1 3.6 2.4z"/>
        <path d="M12 7c1.6 0 3 -1 3.6 -2.4 -1.6 0 -3 1 -3.6 2.4z"/>
        <path d="M12 11c-1.8 0-3.4 -1.2 -4 -3 1.8 0 3.4 1.2 4 3z"/>
        <path d="M12 11c1.8 0 3.4 -1.2 4 -3 -1.8 0 -3.4 1.2 -4 3z"/>
        <path d="M12 15c-2 0 -3.7 -1.3 -4.4 -3.3 2 0 3.7 1.3 4.4 3.3z"/>
        <path d="M12 15c2 0 3.7 -1.3 4.4 -3.3 -2 0 -3.7 1.3 -4.4 3.3z"/>
        <path d="M12 19c-2.2 0-4 -1.4 -4.8 -3.6 2.2 0 4 1.4 4.8 3.6z"/>
        <path d="M12 19c2.2 0 4 -1.4 4.8 -3.6 -2.2 0 -4 1.4 -4.8 3.6z"/>
      </g>
    ),
    crustaceans: (
      <g>
        <path d="M5 14c0 -4 3.5 -7 8 -7 2.8 0 5 1.4 5.5 2.6 .3 .8 -.4 1.4 -1.2 1.4H14"/>
        <path d="M19 8.5l2 -1.5"/>
        <path d="M21 10l1.2 .3"/>
        <path d="M7 14.5c1 1.5 3 2.5 5.5 2.5 3 0 5.5 -1.4 6.5 -3"/>
        <path d="M9 17l-1 2.2"/>
        <path d="M12 17.5l-.4 2.5"/>
        <path d="M15 17l1 2.2"/>
        <circle cx="6.4" cy="13.4" r=".7" fill="currentColor" stroke="none"/>
        <path d="M5.5 12.5c-1 -.6 -2 -1.6 -2.5 -2.8"/>
        <path d="M5 13c-1.4 0 -2.7 .6 -3.5 1.6"/>
      </g>
    ),
    eggs: (
      <g>
        <path d="M12 3.5c-3.6 0 -6.5 4.5 -6.5 9 0 4 2.9 7 6.5 7s6.5 -3 6.5 -7c0 -4.5 -2.9 -9 -6.5 -9z"/>
        <path d="M9 12.5l1.5 -1.5 1 1.5 1.4 -1.5 1.6 1.5"/>
      </g>
    ),
    fish: (
      <g>
        <path d="M3 12c2 -4 6 -6 10 -6 4 0 7 2 8 4l-2 2 2 2c-1 2 -4 4 -8 4 -4 0 -8 -2 -10 -6z"/>
        <path d="M3 12l-1 -3 3 1.5"/>
        <path d="M3 12l-1 3 3 -1.5"/>
        <circle cx="16" cy="11" r=".8" fill="currentColor" stroke="none"/>
        <path d="M11 12c1.5 1 3 1 4.5 0"/>
      </g>
    ),
    peanuts: (
      <g>
        <path d="M12 3c-2.5 0 -4.5 2 -4.5 4.5 0 1.4 .7 2.5 1.5 3.3 -1 .9 -1.7 2.2 -1.7 3.7 0 3 2.4 5.5 4.7 5.5s4.7 -2.5 4.7 -5.5c0 -1.5 -.7 -2.8 -1.7 -3.7 .8 -.8 1.5 -1.9 1.5 -3.3 0 -2.5 -2 -4.5 -4.5 -4.5z"/>
        <path d="M9.5 9c1.5 1 3.5 1 5 0"/>
        <path d="M9 8c.4 -.4 .9 -.7 1.4 -.9"/>
        <path d="M14 16c.5 .3 .9 .8 1 1.3"/>
      </g>
    ),
    soy: (
      <g>
        <path d="M4 16c0 -5 4.5 -10 10 -10 2 0 4 .6 5.5 1.5 -1 5.5 -5.5 11.5 -11 11.5 -2.5 0 -4.5 -1 -4.5 -3z"/>
        <circle cx="9.5" cy="14"   r="1.6"/>
        <circle cx="13"  cy="11.5" r="1.6"/>
        <circle cx="16"  cy="9"    r="1.6"/>
        <path d="M5 17c-.4 .8 -1.2 1.5 -2 1.8"/>
      </g>
    ),
    milk: (
      <g>
        <path d="M9 3h6"/>
        <path d="M9 3v3l-1.5 3c-.3 .6 -.5 1.3 -.5 2v8.5c0 1.4 1.1 2.5 2.5 2.5h5c1.4 0 2.5 -1.1 2.5 -2.5V11c0 -.7 -.2 -1.4 -.5 -2L15 6V3"/>
        <path d="M7 13h10"/>
        <path d="M9.5 16.5h2"/>
      </g>
    ),
    nuts: (
      <g>
        <path d="M12 7c-3.5 0 -6 2.8 -6 6.5 0 3.5 2.5 6.5 6 6.5s6 -3 6 -6.5c0 -3.7 -2.5 -6.5 -6 -6.5z"/>
        <path d="M9 13c1 -1 2 -1.5 3 -1.5s2 .5 3 1.5"/>
        <path d="M9 16c1 1 2 1.5 3 1.5s2 -.5 3 -1.5"/>
        <path d="M12 11.5v6"/>
        <path d="M12 7c0 -2.2 1.5 -4 3.5 -4.5 .5 2 -.5 4 -2.5 4.8"/>
        <path d="M12 7c-.3 -1.5 -1.5 -2.7 -3 -3"/>
      </g>
    ),
    celery: (
      <g>
        <path d="M9 21c-1 -3 -1.5 -7 -1 -11"/>
        <path d="M12 21c-.3 -3.5 -.3 -7.5 .5 -11.5"/>
        <path d="M15 21c.5 -3 1.2 -7 1 -11"/>
        <path d="M8 10h8"/>
        <path d="M8 10c-2 -1 -3 -3 -2.5 -5 1.8 .5 3 2 3 4"/>
        <path d="M12.5 9.5c0 -2.5 1 -4.5 3 -5.5 1 2 .5 4.5 -1.5 6"/>
        <path d="M16 10c2 -1.5 2.8 -3.5 2 -5.5 -1.8 .5 -3 2 -3.2 4"/>
      </g>
    ),
    mustard: (
      <g>
        <path d="M10 3h4v3h-4z"/>
        <path d="M9.5 6h5l1.2 2.5c.2 .4 .3 .9 .3 1.4V20a1 1 0 0 1 -1 1h-7a1 1 0 0 1 -1 -1V9.9c0 -.5 .1 -1 .3 -1.4z"/>
        <path d="M12 4.5v -2"/>
        <path d="M12 13v3"/>
      </g>
    ),
    sesame: (
      <g>
        <ellipse cx="8" cy="9" rx="2.6" ry="1.6" transform="rotate(-25 8 9)"/>
        <ellipse cx="16" cy="10" rx="2.6" ry="1.6" transform="rotate(20 16 10)"/>
        <ellipse cx="12" cy="16" rx="2.8" ry="1.7" transform="rotate(-5 12 16)"/>
        <path d="M7 8.6l1 .6"/>
        <path d="M15.4 9.7l1 .4"/>
        <path d="M11.2 15.6l1.2 .6"/>
      </g>
    ),
    sulphites: (
      <g>
        <path d="M7 3h10c0 4.5 -2.2 9 -5 9s-5 -4.5 -5 -9z"/>
        <path d="M12 12v7"/>
        <path d="M8.5 20h7"/>
        <path d="M8.5 7h7"/>
      </g>
    ),
    lupin: (
      <g>
        <path d="M12 21v-7"/>
        <path d="M9 14c1 -1 2 -1.5 3 -1.5s2 .5 3 1.5"/>
        <ellipse cx="10.5" cy="11.5" rx="1.5" ry="1"/>
        <ellipse cx="13.5" cy="11.5" rx="1.5" ry="1"/>
        <ellipse cx="10.8" cy="9"   rx="1.4" ry="1"/>
        <ellipse cx="13.2" cy="9"   rx="1.4" ry="1"/>
        <ellipse cx="11"   cy="6.5" rx="1.3" ry=".9"/>
        <ellipse cx="13"   cy="6.5" rx="1.3" ry=".9"/>
        <ellipse cx="12"   cy="4.2" rx="1.2" ry=".8"/>
        <path d="M9 17l-3 -1"/>
        <path d="M15 17l3 -1"/>
      </g>
    ),
    molluscs: (
      <g>
        <path d="M21 14c0 4.4 -4 7 -9 7s-9 -2.6 -9 -7c0 -4 3.6 -7 8 -7 3.6 0 6.5 2.2 6.5 5.2 0 2.4 -1.8 4 -4 4 -1.7 0 -3 -1.1 -3 -2.6 0 -1.2 1 -2 2.2 -2 .9 0 1.5 .5 1.5 1.2"/>
        <path d="M3 14c4 1.5 12 1.5 18 0"/>
      </g>
    ),
  };

  // ---------------------------------------------------------------------
  // <AllergenIcon name="gluten" size={14}/>
  // ---------------------------------------------------------------------
  function AllergenIcon({ name, size = 14, strokeWidth, title }) {
    const id = resolve(name);
    if (!id || !PATHS[id]) return null;
    const sw = strokeWidth || (size <= 14 ? 1.6 : size <= 20 ? 1.5 : 1.4);
    return (
      <svg
        className="al-icon"
        viewBox="0 0 24 24"
        width={size}
        height={size}
        fill="none"
        stroke="currentColor"
        strokeWidth={sw}
        strokeLinecap="round"
        strokeLinejoin="round"
        role="img"
        aria-label={title || labelOf(name)}
      >
        <title>{title || labelOf(name)}</title>
        {PATHS[id]}
      </svg>
    );
  }

  // ---------------------------------------------------------------------
  // <AllergensRow list={['gluten','milk']}/>  — drop-in replacement for dots
  // ---------------------------------------------------------------------
  function AllergensRow({ list, size = 14, max = 5 }) {
    if (!list || !list.length) return null;
    const ids = [];
    const seen = new Set();
    for (const raw of list) {
      const id = resolve(raw);
      if (id && !seen.has(id)) { seen.add(id); ids.push(id); }
    }
    if (!ids.length) return null;
    const shown = ids.slice(0, max);
    const extra = ids.length - shown.length;
    const labels = ids.map((i) => ALLERGENS.find((a) => a.id === i).label).join(', ');
    return (
      <span className="al-row" aria-label={`Allergènes: ${labels}`}>
        {shown.map((id) => (
          <AllergenIcon key={id} name={id} size={size}/>
        ))}
        {extra > 0 && <span className="al-row__more">+{extra}</span>}
      </span>
    );
  }

  // ---------------------------------------------------------------------
  // Navbar trigger button — opens the modal.
  // <AllergenNavButton onClick={...}/>
  // ---------------------------------------------------------------------
  function AllergenNavButton({ onClick }) {
    return (
      <button
        type="button"
        className="ws-nav__icon ws-nav__allergens-btn"
        aria-label="Liste des allergènes"
        title="Allergènes"
        onClick={onClick}
      >
        {/* Wheat-ear glyph from the design system — the canonical allergen pictogram */}
        <AllergenIcon name="gluten" size={16} strokeWidth={1.6} title="Allergènes"/>
      </button>
    );
  }

  // ---------------------------------------------------------------------
  // <AllergensModal open onClose />
  // ---------------------------------------------------------------------
  function AllergensModal({ open, onClose }) {
    const dialogRef = useRef(null);

    useEffect(() => {
      if (!open) return undefined;
      function onKey(e) { if (e.key === 'Escape') onClose?.(); }
      document.addEventListener('keydown', onKey);
      const prev = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => {
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = prev;
      };
    }, [open, onClose]);

    if (!open) return null;

    return (
      <div
        className="al-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="al-modal-title"
        onClick={(e) => { if (e.target === e.currentTarget) onClose?.(); }}
      >
        <div className="al-modal__panel" ref={dialogRef}>
          <header className="al-modal__head">
            <div>
              <p className="al-modal__eyebrow">Information consommateur</p>
              <h2 className="al-modal__title" id="al-modal-title">
                Les <em>14 allergènes</em> majeurs
              </h2>
              <p className="al-modal__lede">
                Les pictogrammes utilisés sur nos fiches produit. Conforme au règlement européen n° 1169/2011.
              </p>
            </div>
            <button type="button" className="al-modal__close" aria-label="Fermer" onClick={onClose}>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round">
                <path d="M6 6l12 12M18 6l-12 12"/>
              </svg>
            </button>
          </header>

          <div className="al-modal__grid">
            {ALLERGENS.map((a, i) => (
              <div key={a.id} className="al-card">
                <span className="al-card__num">{String(i + 1).padStart(2, '0')}</span>
                <div className="al-card__icon">
                  <AllergenIcon name={a.id} size={36} strokeWidth={1.4}/>
                </div>
                <span className="al-card__name">{a.label}</span>
              </div>
            ))}
          </div>

          <footer className="al-modal__foot">
            <span>Règlement UE n° 1169/2011 — substances ou produits provoquant des allergies ou intolérances.</span>
          </footer>
        </div>
      </div>
    );
  }

  // expose
  Object.assign(window, {
    AllergenIcon,
    AllergensRow,
    AllergenNavButton,
    AllergensModal,
    ALLERGENS_LIST: ALLERGENS,
    resolveAllergen: resolve,
    labelAllergen: labelOf,
  });
})();
