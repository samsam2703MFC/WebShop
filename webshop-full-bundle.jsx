
// ===== webshop.jsx (full-bleed) =====
// webshop.jsx — L'Atelier By customer-facing webshop
// Three variants of how the active shop is anchored in the chrome.

const { useState, useMemo, useEffect } = React;

// =========================================================================
// DATA
// =========================================================================
const W_SHOPS = {
  chatelain: { id: 'chatelain', name: 'Maison Châtelain',  city: 'Bruxelles', accent: '#8D1D2C', address: 'Rue du Bailli 42, 1050 Ixelles' },
  sablon:    { id: 'sablon',    name: 'Atelier Sablon',     city: 'Bruxelles', accent: '#1F4F6B', address: 'Place du Grand Sablon 18, 1000 Bruxelles' },
  carre:     { id: 'carre',     name: 'Le Carré',           city: 'Liège',     accent: '#6B3D0A', address: 'Rue Pont d\u2019Avroy 11, 4000 Liège' },
  zuid:      { id: 'zuid',      name: 'Zuid Bakery',        city: 'Antwerpen', accent: '#2D5A3D', address: 'Volkstraat 37, 2000 Antwerpen' },
  grognon:   { id: 'grognon',   name: 'Le Grognon',         city: 'Namur',     accent: '#C17A2A', address: 'Rue des Brasseurs 108, 5000 Namur' },
  brugge:    { id: 'brugge',    name: 'Brugge Studio',      city: 'Brugge',    accent: '#5C4A8A', address: 'Steenstraat 74, 8000 Brugge' },
};
// Expose so webshop-shops-api.jsx can resolve from in-memory fixture
// when no remote endpoint is configured.
window.W_SHOPS = W_SHOPS;

const W_CATEGORIES = [
  { id: 'tarts',     label: 'Tartes',        img: 'img/cat-tarts.png',
    subs: [
      { id: 'tarts-fruit',     label: 'Fruits',           img: 'img/sweet-tart-small.png' },
      { id: 'tarts-chocolate', label: 'Chocolat',         img: 'img/cake-slice.png' },
      { id: 'tarts-savoury',   label: 'Salées',           img: 'img/savoury-tart.png' },
      { id: 'tarts-classics',  label: 'Classiques',       img: 'img/sweet-tart-big.png' },
      { id: 'tarts-individual',label: 'Individuelles',    img: 'img/cupcake.png' },
      { id: 'tarts-seasonal',  label: 'Saison',           img: 'img/pumpkin.png' },
    ] },
  { id: 'plats',     label: 'Plats',         img: 'img/cat-cakes.png',
    subs: [
      { id: 'plats-traiteur',  label: 'Traiteur',         img: 'img/sandwiches-platter.png' },
      { id: 'plats-soup',      label: 'Soupes',           img: 'img/tomato.png' },
      { id: 'plats-veggie',    label: 'Végétarien',       img: 'img/carrot.png' },
      { id: 'plats-saison',    label: 'De saison',        img: 'img/pumpkin.png' },
      { id: 'plats-sharing',   label: 'À partager',       img: 'img/cheese-fine-cuts.png' },
    ] },
  { id: 'sandwiches',label: 'Sandwiches',    img: 'img/cat-sandw.png',
    subs: [
      { id: 'sand-classic',    label: 'Classiques',       img: 'img/sandwiches.png' },
      { id: 'sand-veggie',     label: 'Végétariens',      img: 'img/salads.png' },
      { id: 'sand-deluxe',     label: 'Deluxe',           img: 'img/sandwiches-platter.png' },
      { id: 'sand-club',       label: 'Clubs',            img: 'img/cheese-fine-cuts.png' },
      { id: 'sand-petit',      label: 'Petits formats',   img: 'img/roll.png' },
    ] },
  { id: 'breads',    label: 'Pains',         img: 'img/cat-breads.png',
    subs: [
      { id: 'breads-trad',     label: 'Tradition',        img: 'img/bread-1.png' },
      { id: 'breads-special',  label: 'Spéciaux',         img: 'img/bread-2.png' },
      { id: 'breads-gluten',   label: 'Sans gluten',      img: 'img/bread-3.png' },
      { id: 'breads-petit',    label: 'Petits pains',     img: 'img/rolls.png' },
      { id: 'breads-jour',     label: 'Du jour',          img: 'img/bread-4.png' },
      { id: 'breads-cereales', label: 'Céréales',         img: 'img/bread-5.png' },
    ] },
  { id: 'viennoiseries', label: 'Viennoiseries', img: 'img/cat-vienn.png',
    subs: [
      { id: 'vien-croissant',  label: 'Croissants',       img: 'img/croissant.png' },
      { id: 'vien-chocolat',   label: 'Chocolatines',     img: 'img/croissant.png' },
      { id: 'vien-brioche',    label: 'Brioches',         img: 'img/cake.png' },
      { id: 'vien-feuillete',  label: 'Feuilletés',       img: 'img/croissant.png' },
      { id: 'vien-saison',     label: 'Saison',           img: 'img/cupcake.png' },
    ] },
  { id: 'sweet',     label: 'Sucré',         img: 'img/cat-sweet.png',
    subs: [
      { id: 'sweet-cookies',   label: 'Biscuits',         img: 'img/cookies.png' },
      { id: 'sweet-cake',      label: 'Gâteaux',          img: 'img/cake.png' },
      { id: 'sweet-cupcake',   label: 'Cupcakes',         img: 'img/cupcake.png' },
      { id: 'sweet-chocolat',  label: 'Chocolats',        img: 'img/cake-slice.png' },
      { id: 'sweet-saison',    label: 'Saison',           img: 'img/pumpkin.png' },
    ] },
];

const W_ASSORTMENTS = [
  { id: 'paques', label: 'Pâques',       img: 'img/season-paques.png',       tagline: 'Sélection chocolatée — disponible jusqu\u2019au 7 avril' },
  { id: 'ete',    label: 'Été',          img: 'img/season-fete-meres.png',   tagline: 'Pâtisseries fraîches & glaces — saison estivale' },
];

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

const W_PRODUCTS = [
  { id: 1,  cat: 'tarts',   name: 'Tarte aux fraises',                   price: 24.0, allergens: ['gluten','milk','egg'],            portions: true,  badge: '4+1', img: 'img/p-tarte-fraises.png',
    crossPortion: true,
    offer: { type: 'buy_x_get_y_free', x: 4, y: 1, unit: 'portion' } },
  { id: 2,  cat: 'tarts',   name: 'Tarte aux fruits frais',              price: 28.0, allergens: ['gluten','milk','egg'],            portions: true,  badge: '4+1', img: 'img/p-tarte-fruits.png',
    crossPortion: true,
    offer: { type: 'buy_x_get_y_free', x: 4, y: 1, unit: 'portion' } },
  { id: 3,  cat: 'plats',   name: 'Chou farci, crème & champignons',     price: 14.5, allergens: ['milk','egg'],                     portions: false, badge: 'Du jour', img: 'img/p-chou-farci.png',
    no_delivery: true },
  { id: 4,  cat: 'salades', name: 'Bowl chèvre, figues & légumes rôtis', price: 13.5, allergens: ['milk'],                           portions: false, badge: null,      img: 'img/p-bowl-veggie.png',
    delivery_stock: 3 },
  { id: 5,  cat: 'salades', name: 'Salade fêta, fruits rouges & olives', price: 12.5, allergens: ['milk','almond'],                  portions: false, badge: null,      img: 'img/p-salade-feta.png' },
  { id: 6,  cat: 'salades', name: 'Salade de bœuf, bleu & pignons',      price: 15.5, allergens: ['milk'],                           portions: false, badge: null,      img: 'img/p-salade-boeuf.png' },
  { id: 36, cat: 'salades', name: 'Salade chef saumon fumé & pommes grenailles',
    description: 'Saumon fumé, poulet effiloché, pommes grenailles rôties, olives noires, oignon rouge, roquette et cresson.',
    price: 14.50, allergens: ['fish','egg'],                               portions: false, badge: 'Nouveau', img: 'img/p-salade-chef-saumon.png' },
  { id: 7,  cat: 'sweet',   name: 'Yaourt, granola & fruits rouges',     price: 5.80, allergens: ['gluten','milk'],                  portions: false, badge: null,      img: 'img/p-parfait.png' },
  { id: 8,  cat: 'breads',  name: 'Pain de campagne au levain',          price: 4.80, allergens: ['gluten'],                         portions: false, badge: null,      img: null },
  { id: 9,  cat: 'breads',  name: 'Baguette tradition',                  price: 2.40, allergens: ['gluten'],                         portions: false, badge: null,      img: null },
  { id: 10, cat: 'breads',  name: 'Pain aux céréales',                   price: 5.20, allergens: ['gluten','sesame'],                portions: false, badge: null,      img: null },
  { id: 11, cat: 'vienn',   name: 'Croissant au beurre AOP',             price: 1.90, allergens: ['gluten','milk','egg'],            portions: false, badge: 'Du jour', img: null },
  { id: 12, cat: 'vienn',   name: 'Pain au chocolat',                    price: 2.20, allergens: ['gluten','milk','egg'],            portions: false, badge: null,      img: null },
  { id: 13, cat: 'vienn',   name: 'Brioche feuilletée',                  price: 3.40, allergens: ['gluten','milk','egg'],            portions: false, badge: null,      img: null },
  { id: 14, cat: 'sweet',   name: 'Cookies trio',                        price: 6.80, allergens: ['gluten','milk','egg'],            portions: false, badge: '2e -50%', img: null,
    offer: { type: 'second_at_pct', pct: 50, unit: 'piece' } },
  { id: 15, cat: 'sweet',   name: 'Madeleines (×6)',                     price: 7.20, allergens: ['gluten','milk','egg'],            portions: false, badge: null,      img: null },
  { id: 16, cat: 'sweet',   name: 'Cannelés (×6)',                       price: 9.00, allergens: ['gluten','milk','egg'],            portions: false, badge: null,      img: null },
  { id: 17, cat: 'sweet',   name: 'Macarons (×8)',                       price: 14.5, allergens: ['gluten','milk','egg','almond'],   portions: false, badge: null,      img: null },

  // -------- Configurable products (test fixtures) --------
  // Sandwich Club — options (bread, sauce) + multiple bundle plans
  { id: 20, cat: 'sandwiches', subCat: 'sand-classic',
    name: 'Sandwich Club',                       price: 9.50,
    allergens: ['gluten','milk','egg'],          portions: false, badge: null,
    img: 'img/p-sandwich-club.png',
    options: [
      { id: 'bread', label: 'Choix de pain', required: true, kind: 'single',
        choices: [
          { id: 'white', label: 'Pain blanc',  delta: 0 },
          { id: 'brown', label: 'Pain complet', delta: 0 },
        ]},
      { id: 'sauce', label: 'Sauce', required: true, kind: 'single',
        choices: [
          { id: 'oil',  label: 'Huile d\u2019olive', delta: 0 },
          { id: 'mayo', label: 'Mayonnaise',         delta: 1.0 },
          { id: 'andalouse', label: 'Andalouse',     delta: 1.0 },
        ]},
    ],
    has_menu_options: true,
    available_bundles: [
      { id: 'b-menu', name: 'Menu', description: '1 Sandwich Club + 1 boisson au choix',
        included: [{ label: 'Sandwich Club' }],
        slots: [
          { id: 'drink', label: 'Boisson', required: true,
            choices: [
              { id: 'd1', label: 'Eau plate 33cl',   img: 'img/cold-drink.png',    delta: 0 },
              { id: 'd2', label: 'Limonade maison',  img: 'img/lemonade-soda.png', delta: 0.5 },
              { id: 'd3', label: 'Café',             img: 'img/hot-drink.png',     delta: 0 },
            ],
          },
        ],
        price_modifier: 3.5,
        advantages: ['Économisez 1,00 €', 'Idéal pour le déjeuner'],
      },
      { id: 'b-full', name: 'Full Menu', description: '1 Sandwich Club + 1 boisson + 1 dessert',
        included: [{ label: 'Sandwich Club' }],
        slots: [
          { id: 'drink', label: 'Boisson', required: true,
            choices: [
              { id: 'd1', label: 'Eau plate 33cl',   img: 'img/cold-drink.png',    delta: 0 },
              { id: 'd2', label: 'Limonade maison',  img: 'img/lemonade-soda.png', delta: 0.5 },
              { id: 'd3', label: 'Café',             img: 'img/hot-drink.png',     delta: 0 },
            ],
          },
          { id: 'dessert', label: 'Dessert', required: true,
            choices: [
              { id: 's1', label: 'Cookie',     img: 'img/cookies.png' },
              { id: 's2', label: 'Cupcake',    img: 'img/cupcake.png' },
              { id: 's3', label: 'Madeleine',  img: 'img/cake-slice.png' },
            ],
          },
        ],
        price_modifier: 5.5,
        advantages: ['Économisez 2,50 €', 'Boisson + dessert inclus', 'Le plus complet'],
        recommended: true,
      },
    ],
  },
  // -------- Simple sandwiches (no options) --------
  { id: 30, cat: 'sandwiches', subCat: 'sand-classic',
    name: 'Sandwich mousse de jambon & cornichons',
    description: 'Mousse de jambon maison fouettée, cornichons croquants, salade fraîche, sur pain de campagne.',
    price: 7.50, allergens: ['gluten','milk','egg'], portions: false, badge: '2e -30%',
    img: 'img/p-sand-mousse-jambon.png',
    offer: { type: 'second_at_pct', pct: 30, unit: 'piece' } },
  { id: 31, cat: 'sandwiches', subCat: 'sand-deluxe',
    name: 'Sandwich jambon de Parme & burrata',
    description: 'Jambon de Parme 18 mois, burrata crémeuse, pignons torréfiés, jeunes pousses, confit de figues.',
    price: 11.50, allergens: ['gluten','milk','nuts'], portions: false, badge: 'Signature',
    img: 'img/p-sand-jambon-burrata.png' },
  { id: 32, cat: 'sandwiches', subCat: 'sand-classic',
    name: 'Sandwich œufs brouillés & bacon',
    description: 'Œufs brouillés moelleux, bacon croustillant, ciboulette fraîche, sur pain brioché.',
    price: 8.20, allergens: ['gluten','milk','egg'], portions: false, badge: null,
    img: 'img/p-sand-oeufs-bacon.png' },
  { id: 33, cat: 'sandwiches', subCat: 'sand-veggie',
    name: 'Sandwich féta & roquette',
    description: 'Féta marinée aux herbes, roquette, tomates confites, huile d\u2019olive vierge extra.',
    price: 8.90, allergens: ['gluten','milk'], portions: false, badge: null,
    img: 'img/p-sand-feta-roquette.png' },
  { id: 34, cat: 'sandwiches', subCat: 'sand-deluxe',
    name: 'Sandwich crabe & roquette',
    description: 'Chair de crabe, mayonnaise citronnée, roquette, tomates fraîches, cornichons.',
    price: 12.50, allergens: ['gluten','milk','egg','crustacean','fish'], portions: false, badge: null,
    img: 'img/p-sand-crabe.png' },
  { id: 35, cat: 'sandwiches', subCat: 'sand-classic',
    name: 'Sandwich rillettes de thon',
    description: 'Rillettes de thon préparées maison, salade verte croquante, citron.',
    price: 7.80, allergens: ['gluten','milk','egg','fish'], portions: false, badge: null,
    img: 'img/p-sand-thon.png' },

  // Quiche du jour — sauce option + salad upsell
  { id: 21, cat: 'plats', subCat: 'plats-traiteur',
    name: 'Quiche du jour',                      price: 7.80,
    allergens: ['gluten','milk','egg'],          portions: true, badge: 'Du jour',
    crossPortion: true,
    img: 'img/savoury-tart.png',
    options: [
      { id: 'sauce', label: 'Accompagnement', required: false, kind: 'single',
        choices: [
          { id: 'none',   label: 'Sans',          delta: 0 },
          { id: 'pesto',  label: 'Pesto maison',  delta: 0.5 },
          { id: 'tomato', label: 'Coulis tomate', delta: 0.5 },
        ]},
    ],
    upsells: [
      { id: 'salad', label: 'Petite salade', img: 'img/salads.png',         delta: 4.5 },
      { id: 'soup',  label: 'Soupe du jour', img: 'img/tomato.png',         delta: 4.0 },
      { id: 'drink', label: 'Boisson',       img: 'img/cold-drink.png',     delta: 2.5 },
    ],
  },
];

if (typeof window !== 'undefined') {
  window._CATALOG_SEED = { products: W_PRODUCTS, assortments: W_ASSORTMENTS, categories: W_CATEGORIES };
}


// =========================================================================
// CLIENTS / OFFICES / DELIVERY TOURS
// A client may be linked to one office; an office may be linked to one tour.
// Delivery is enabled only if both links exist (office validated + tour set).
// =========================================================================
// TODO[BACKEND]: tours must come from a Tours API (e.g. `GET /tours?shopId=`).
// This in-memory fixture exists only so the demo storefront keeps running
// before the endpoint is wired. Frontend code MUST go through window.WSTours
// (to be added) — never read W_TOURS directly outside the demo seam below.
const W_TOURS = {
  'tour-bxl-mid': { id: 'tour-bxl-mid', name: 'Bruxelles Midi',  shopId: 'chatelain', window: '11:30–13:30', days: 'lun-ven' },
  'tour-bxl-am':  { id: 'tour-bxl-am',  name: 'Bruxelles Matin', shopId: 'sablon',    window: '08:30–10:30', days: 'lun-ven' },
  'tour-lg':      { id: 'tour-lg',      name: 'Liège Centre',    shopId: 'carre',     window: '11:00–13:00', days: 'mar-ven' },
};
// TODO[BACKEND]: offices come from WSOffices (webshop-offices-api.jsx).
// This seed only feeds window._AUTH_STORE so the API stub has data to return
// when no remote endpoint is configured. Remove once /offices is live.
const W_OFFICES_SEED = {
  'off-acme':     { id: 'off-acme',     name: 'ACME Avocats',     contact: 'Marie Dubois',  phone: '+32 472 11 22 33', email: 'marie@acme.be',     address: 'Rue de la Loi 120, 1040 Bxl',  tourId: 'tour-bxl-mid', status: 'validated' },
  'off-pendingA': { id: 'off-pendingA', name: 'Borderline & Co.', contact: 'Lou Mercier',   phone: '+32 470 12 34 56', email: 'lou@borderline.be', address: 'Place Stéphanie 4, 1050 Bxl',  tourId: null,           status: 'pending' },
};
// TODO[BACKEND]: users / auth must move behind a real Auth API
// (`POST /auth/login`, `GET /me`, `PATCH /me`). This seed is demo-only and
// stores a plaintext password — NEVER ship to production.
const W_USERS_SEED = {
  'marie@acme.be':     { id: 'u1', email: 'marie@acme.be',     password: 'demo', firstName: 'Marie', lastName: 'Dubois',  officeId: 'off-acme',     preferredShopId: 'chatelain', fidelityApp: { active: false, linkedAt: null } },
  'lou@borderline.be': { id: 'u2', email: 'lou@borderline.be', password: 'demo', firstName: 'Lou',   lastName: 'Mercier', officeId: 'off-pendingA', preferredShopId: 'sablon',    fidelityApp: { active: true,  linkedAt: '2026-01-12T09:30:00Z' } },
  'jules@indep.be':    { id: 'u3', email: 'jules@indep.be',    password: 'demo', firstName: 'Jules', lastName: 'Vermeer', officeId: null,           preferredShopId: null,         fidelityApp: { active: false, linkedAt: null } },
};
const _AUTH_STORE = { users: { ...W_USERS_SEED }, offices: { ...W_OFFICES_SEED } };
if (typeof window !== 'undefined') window._AUTH_STORE = _AUTH_STORE;

function authLogin(email, password) {
  const u = _AUTH_STORE.users[email.trim().toLowerCase()];
  if (!u || u.password !== password) return { ok: false, error: 'Identifiants incorrects.' };
  return { ok: true, user: u };
}
function authRegister({ email, password, firstName, lastName }) {
  const k = email.trim().toLowerCase();
  if (_AUTH_STORE.users[k]) return { ok: false, error: 'Un compte existe déjà avec cet email.' };
  const u = { id: 'u' + Date.now(), email: k, password, firstName, lastName, officeId: null };
  _AUTH_STORE.users[k] = u;
  return { ok: true, user: u };
}
function getOffice(id) { return id ? _AUTH_STORE.offices[id] : null; }
function getTour(id)   { return id ? W_TOURS[id] : null; }
function submitOfficeRequest({ user, companyName, contactName, phone, email }) {
  const id = 'off-req-' + Date.now();
  const office = {
    id, name: companyName, contact: contactName, phone,
    email: (email || user?.email || '').trim().toLowerCase(),
    address: null, tourId: null, status: 'pending',
  };
  _AUTH_STORE.offices[id] = office;
  if (user) { user.officeId = id; _AUTH_STORE.users[user.email] = { ...user }; }
  return office;
}

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
const W_MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
const W_DAYS = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
const W_DAYS_SHORT3 = ['Lun.','Mar.','Mer.','Jeu.','Ven.','Sam.','Dim.'];
function wsFormatPill(d) {
  return `${W_DAYS_SHORT3[(d.getDay()+6)%7]} ${d.getDate()} ${W_MONTHS[d.getMonth()].slice(0,3)}.`;
}
function DatePill({ mode, value, onChange, deliveryCutoffPassed }) {
  const [open, setOpen] = React.useState(false);
  const [view, setView] = React.useState(() => new Date(value.getFullYear(), value.getMonth(), 1));
  const wrapRef = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const off = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    const esc = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', off, true);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('pointerdown', off, true); document.removeEventListener('keydown', esc); };
  }, [open]);
  const today = new Date(); today.setHours(0,0,0,0);
  const firstDow = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7; // Mon-first
  const daysInMonth = new Date(view.getFullYear(), view.getMonth()+1, 0).getDate();
  const cells = [];
  for (let i = 0; i < firstDow; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(new Date(view.getFullYear(), view.getMonth(), d));
  while (cells.length % 7) cells.push(null);
  const sameDay = (a,b) => a && b && a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
  const shift = (n) => setView(new Date(view.getFullYear(), view.getMonth()+n, 1));
  return (
    <div ref={wrapRef} className="ws-datepill">
      <button className="ws-nav__date" onClick={() => setOpen((o)=>!o)} aria-expanded={open}>
        <Pict d={ICONS.cal} s={12}/>
        <span>Date de {mode === 'delivery' ? 'livraison' : 'retrait'}</span>
        <strong>· {wsFormatPill(value)}</strong>
        <Pict d={ICONS.chev} s={10}/>
      </button>
      {open && (
        <div className={`ws-datepop ws-datepop--${mode}`} onClick={(e)=>e.stopPropagation()}>
          <div className="ws-datepop__head">
            <button className="ws-datepop__nav" onClick={()=>shift(-1)} aria-label="Mois précédent">‹</button>
            <span className="ws-datepop__title">{W_MONTHS[view.getMonth()]} {view.getFullYear()}</span>
            <button className="ws-datepop__nav" onClick={()=>shift(1)} aria-label="Mois suivant">›</button>
          </div>
          <div className="ws-datepop__dow">
            {W_DAYS.map((d) => <span key={d}>{d}</span>)}
          </div>
          <div className="ws-datepop__grid">
            {cells.map((d, i) => {
              if (!d) return <span key={i} className="ws-datepop__cell ws-datepop__cell--empty"/>;
              const isPast = d < today;
              const isToday = sameDay(d, today);
              const isSel = sameDay(d, value);
              const isCutoffBlocked = mode === 'delivery' && isToday && deliveryCutoffPassed;
              const cls = ['ws-datepop__cell'];
              if (isPast || isCutoffBlocked) cls.push('is-past');
              if (isToday) cls.push('is-today');
              if (isSel) cls.push('is-sel');
              return (
                <button key={i} className={cls.join(' ')} disabled={isPast || isCutoffBlocked}
                  title={isCutoffBlocked ? 'Livraison fermée après 10h00' : undefined}
                  onClick={() => { onChange(d); setOpen(false); }}>
                  {d.getDate()}
                </button>
              );
            })}
          </div>
          <div className="ws-datepop__foot">
            <span>{mode === 'delivery' ? 'Livraison au bureau' : 'Collecte en magasin'}</span>
            <button className="ws-datepop__today"
              disabled={mode === 'delivery' && deliveryCutoffPassed}
              title={mode === 'delivery' && deliveryCutoffPassed ? 'Livraison fermée après 10h00' : undefined}
              onClick={() => { onChange(today); setOpen(false); }}>Aujourd'hui</button>
          </div>
        </div>
      )}
    </div>
  );
}

// Mode pill — Ruby (collect) / Abricot (delivery)
function ModePills({ mode, onChange, deliveryCutoffPassed }) {
  return (
    <div className="ws-modes" role="tablist" aria-label="Mode boutique">
      <span className="ws-modes__indicator" data-mode={deliveryCutoffPassed && mode === 'delivery' ? 'collect' : mode} aria-hidden="true"/>
      <button className={`ws-mode ws-mode--collect${mode === 'collect' ? ' is-active' : ''}`} onClick={() => onChange('collect')} role="tab" aria-selected={mode === 'collect'} aria-label="Click & Collect">
        <Pict d={ICONS.bag} s={14}/>
        <span className="ws-mode__lbl-full">Click &amp; Collect</span>
      </button>
      <button className={`ws-mode ws-mode--delivery${mode === 'delivery' ? ' is-active' : ''}${deliveryCutoffPassed ? ' is-disabled' : ''}`}
        onClick={() => onChange('delivery')} role="tab" aria-selected={mode === 'delivery'} aria-label="Livraison au bureau"
        disabled={deliveryCutoffPassed}
        title={deliveryCutoffPassed ? 'Livraison non disponible pour aujourd\'hui après 10h00' : undefined}>
        <Pict d={ICONS.truck} s={14}/>
        <span className="ws-mode__lbl-full">Livraison au bureau</span>
        {deliveryCutoffPassed && <span className="ws-mode__cutoff"> · Fermé</span>}
      </button>
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
    const freebieValue = isPortion
      ? ctx.basePrice * 0.27   // a free quarter
      : unit;

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
        <button type="button" className="pdm-offer__add" onClick={onAddOne}>
          +1
        </button>
      )}
    </div>
  );
}

// Portion glyph shapes (1/4, 1/2, entier) — shared by card hint + modal options
const PORTION_SHAPES = [
  { v: 'quart',  d: <path d="M12 12L12 3 A9 9 0 0 1 21 12 Z" fill="currentColor"/>,        name: '1/4',     factor: 0.27 },
  { v: 'demi',   d: <path d="M12 3 A9 9 0 0 1 12 21 Z" fill="currentColor"/>,              name: '1/2',     factor: 0.52 },
  { v: 'entier', d: <circle cx="12" cy="12" r="9" fill="currentColor"/>,                   name: 'Entière', factor: 1 },
];

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
function PortionOptions({ value, onChange, basePrice }) {
  return (
    <div className="pdm-optrow">
      <div className="pdm-optrow__head">
        <span className="pdm-opt__label">Portion</span>
        <span className="pdm-opt__req">Requis</span>
      </div>
      <div className="pdm-seg pdm-seg--portions" role="radiogroup" aria-label="Portion" style={{ '--pdm-seg-n': PORTION_SHAPES.length }}>
        {PORTION_SHAPES.map((o) => {
          const on = value === o.v;
          const price = (basePrice || 0) * o.factor;
          return (
            <button key={o.v}
              type="button"
              role="radio"
              aria-checked={on}
              className={'pdm-seg__btn pdm-seg__btn--portion' + (on ? ' is-on' : '')}
              onClick={() => onChange(o.v)}>
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
      { id: null, name: 'À la carte', description: 'Le produit seul, sans formule.', price_modifier: 0, slots: [], advantages: [], included: [] },
      ...product.available_bundles,
    ];
  }, [product]);

  // Reset state when the product changes — and auto-open required option groups + auto-pick recommended bundle.
  React.useEffect(() => {
    setSel(initSelections);
    setUpsellIds({});
    setQty(1);
    setPortion('entier');
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
    // Default-pick recommended bundle if any; otherwise null (À la carte).
    const rec = product?.available_bundles?.find((b) => b.recommended);
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
  const deliveryBlocked = mode === 'delivery' && !!product?.no_delivery;
  // qty_available from ws_product_stock API; falls back to delivery_stock on product seed
  const qtyAvailable = stock ? stock.qty_available : (typeof product?.delivery_stock === 'number' ? product.delivery_stock : null);
  const deliveryStockLeft = mode === 'delivery' && qtyAvailable !== null ? Math.max(0, qtyAvailable) : null;

  let unit = product?.price || 0;
  // Apply portion factor for portionable products (1/4 ≈ 0.27, 1/2 ≈ 0.52)
  if (product?.portions) {
    const factor = portion === 'quart' ? 0.27 : portion === 'demi' ? 0.52 : 1;
    unit = unit * factor;
  }
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
      const cid = bundleSlots[slot.id];
      if (cid) {
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
      if (slot.required && !bundleSlots[slot.id]) { valid = false; break; }
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
      const el = optRefs.current[oid];
      const sc = scrollRef.current;
      if (!el || !sc) return;
      const elRect = el.getBoundingClientRect();
      const scRect = sc.getBoundingClientRect();
      const target = sc.scrollTop + (elRect.top - scRect.top) - 8; // tiny breathing room at top
      sc.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
    }, 180);
  }
  function setSlot(slotId, choiceId) { setBundleSlots((s) => ({ ...s, [slotId]: choiceId })); }
  function pickBundle(bid) {
    setBundleId((cur) => cur === bid ? cur : bid);
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
      const cardRect = card.getBoundingClientRect();
      const scRect = sc.getBoundingClientRect();
      const cardCenter = (cardRect.top - scRect.top) + (card.offsetHeight / 2);
      const target = sc.scrollTop + cardCenter - (sc.clientHeight / 2);
      sc.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
    }, 340);
  }
  function toggleUpsell(id)        { setUpsellIds((s) => ({ ...s, [id]: !s[id] })); }
  function toggleOpt(oid)          {
    setOpenOpts((s) => {
      const next = { ...s, [oid]: !s[oid] };
      // After the accordion finishes opening, glide its body to vertical center
      // of the modal scroll area so the just-revealed options feel balanced.
      if (next[oid]) {
        setTimeout(() => {
          const el = optRefs.current[oid];
          const sc = scrollRef.current;
          if (!el || !sc) return;
          const elRect = el.getBoundingClientRect();
          const scRect = sc.getBoundingClientRect();
          const elCenter = (elRect.top - scRect.top) + (el.offsetHeight / 2);
          const target = sc.scrollTop + elCenter - (sc.clientHeight / 2);
          sc.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
        }, 320);
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
      optionLabels.push('Formule · ' + activeBundle.name);
      for (const slot of (activeBundle.slots || [])) {
        const cid = bundleSlots[slot.id];
        if (cid) {
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
      name: product.name + (portion === 'demi' ? ' — 1/2' : portion === 'quart' ? ' — 1/4' : ''),
      qty,
      price: qty > 0 ? total / qty : (unit + bundleDelta + upsellDelta),
      options: optionLabels.map((label) => ({ label })),
      portion: product.portions ? portion : null,
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
  const pdmPanelRef = useSwipeDownToClose(onClose);
  const scrollRef = React.useRef(null);
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
        <button className="pdm-close" aria-label="Fermer" onClick={onClose}><Pict d={ICONS.close} s={13}/></button>

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
              <p className="pdm-eyebrow">{product.cat === 'sandwiches' ? 'Sandwich' : product.cat === 'plats' ? 'Plat du jour' : 'Notre sélection'}</p>
              <h2 className="pdm-title">{product.name}</h2>
              <p className="pdm-desc">{product.description || 'Préparé chaque matin par nos artisans, avec des ingrédients sélectionnés au plus près de leur saison.'}</p>
              {product.allergens?.length > 0 && (
                <div className="pdm-allergens"><Allergens list={product.allergens}/></div>
              )}
              {product.portions && (
                <PortionOptions
                  value={portion}
                  onChange={setPortion}
                  basePrice={product.price}
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
                          ? <span className="pdm-opt__req">Requis</span>
                          : <span className="pdm-opt__req pdm-opt__req--soft">Optionnel</span>}
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
                              onClick={() => setOpt(o.id, c.id)}>
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
                      <button className="pdm-opt__head" onClick={() => toggleOpt(id)} aria-expanded={isOpen}>
                        <span className="pdm-opt__head-l">
                          <span className="pdm-opt__label">Pour accompagner</span>
                          {!isOpen && count > 0 && <span className="pdm-opt__sub">{count} ajout{count>1?'s':''}</span>}
                        </span>
                        <span className="pdm-opt__head-r">
                          <span className="pdm-opt__req pdm-opt__req--soft">Optionnel</span>
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
                                  onClick={() => toggleUpsell(u.id)}>
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
                  <span className="pdm-section-title">Formule</span>
                  {bundleList.length > 1 && (
                    <button type="button" className="pdm-section-arrow" aria-label="Suivant"
                      onClick={() => {
                        const cur = bundleList.findIndex((b) => b.id === bundleId);
                        const next = cur + 1 >= bundleList.length ? 0 : cur + 1;
                        const nextBundle = bundleList[next];
                        if (nextBundle) pickBundle(nextBundle.id);
                      }}>
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
                           onClick={() => pickBundle(b.id)}>
                        {b.recommended && <span className="pdm-bcard__badge">Best option</span>}
                        <div className="pdm-bcard__top">
                          <span className="pdm-bcard__name">{b.name}</span>
                          <span className={'pdm-bcard__price' + (b.price_modifier > 0 ? '' : ' pdm-bcard__price--free')}>
                            {b.price_modifier > 0 ? '+' + b.price_modifier.toFixed(2) + ' €' : 'Inclus'}
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
                          <div className="pdm-bcard__expand-inner" onClick={(e) => e.stopPropagation()}>
                            {b.slots?.map((slot) => (
                              <div key={slot.id} className="pdm-opt is-open" style={{ background: 'transparent', boxShadow: 'none' }}>
                                <div style={{ padding: '4px 0 0' }}>
                                  <div className="pdm-opt__head-l" style={{ marginBottom: 6 }}>
                                    <span className="pdm-opt__label" style={{ fontSize: 12.5 }}>{slot.label}
                                      {slot.required && <span className="pdm-opt__req" style={{ marginLeft: 8 }}>Requis</span>}
                                    </span>
                                  </div>
                                  <div className="pdm-chips">
                                    {slot.choices.map((c) => {
                                      const cOn = bundleSlots[slot.id] === c.id;
                                      const klass = c.img ? 'pdm-imgchip' : 'pdm-chip';
                                      return (
                                        <button key={c.id}
                                          className={klass + (cOn ? ' is-on' : '')}
                                          onClick={() => setSlot(slot.id, c.id)}>
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
            {!deliveryBlocked && deliveryStockLeft !== null && (
              <div className="pdm-delivery-notice">
                Livraison · {deliveryStockLeft > 0 ? `${deliveryStockLeft} unité${deliveryStockLeft > 1 ? 's' : ''} disponible${deliveryStockLeft > 1 ? 's' : ''}` : 'Stock épuisé'}
              </div>
            )}
            <div className="pdm-qty">
              <button className="pdm-qty__btn" onClick={() => setQty((q) => Math.max(1, q - 1))} disabled={qty <= 1} aria-label="Diminuer">−</button>
              <span className="pdm-qty__val">{qty}</span>
              <button className="pdm-qty__btn" onClick={() => setQty((q) => Math.min(q + 1, deliveryStockLeft ?? 99))} aria-label="Augmenter" disabled={deliveryStockLeft !== null && qty >= deliveryStockLeft}>+</button>
            </div>
            <button className="pdm-cta" disabled={!valid || deliveryBlocked || (deliveryStockLeft !== null && deliveryStockLeft === 0)} onClick={handleConfirm}>
              <span>{deliveryBlocked ? 'Non disponible en livraison' : (deliveryStockLeft === 0 ? 'Stock épuisé' : (valid ? 'Ajouter au panier' : 'Choisissez vos options'))}</span>
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
const ProductCard = React.memo(function ProductCard({ p, onAdd, onOpen, mode, basketQty, stock }) {
  const price = p.price;
  const hasOptions = !!(p.options || p.bundle || p.upsells);
  const isDelivery = mode === 'delivery';
  const deliveryBlocked = isDelivery && !!p.no_delivery;
  // qty_available from ws_product_stock API; falls back to delivery_stock on product seed
  const qtyAvailable = stock ? stock.qty_available : (typeof p.delivery_stock === 'number' ? p.delivery_stock : null);
  const deliveryStockLeft = isDelivery && qtyAvailable !== null
    ? Math.max(0, qtyAvailable - (basketQty || 0))
    : null;
  const stockExhausted = deliveryStockLeft !== null && deliveryStockLeft === 0;
  const addDisabled = deliveryBlocked || stockExhausted;

  function handleCardClick() { if (!deliveryBlocked) onOpen(p); }
  function handleAddClick(e) {
    e.stopPropagation();
    if (!addDisabled) onOpen(p);
  }
  return (
    <article className={`ws-card${addDisabled ? ' ws-card--unavail' : ''}`} onClick={handleCardClick} role="button" tabIndex={0}>
      <div className="ws-card__photo">
        {deliveryBlocked
          ? <span className="ws-card__badge ws-card__badge--nodeliv">Retrait seulement</span>
          : p.badge && <span className="ws-card__badge">{p.badge}</span>}
        <img
          className={p.img ? 'ws-card__photo-img' : 'ws-card__photo-img ws-card__photo-img--lineart'}
          src={p.img || getPlaceholder(p)}
          alt=""
        />
      </div>
      {/* Meta strip BELOW the (1:1) photo — allergens, info, add */}
      <div className="ws-card__metaStrip" onClick={(e) => e.stopPropagation()}>
        <Allergens list={p.allergens}/>
        <div className="ws-card__icons">
          <button className="ws-iconcircle" aria-label="Infos" onClick={(e) => { e.stopPropagation(); onOpen(p); }}><Pict d={ICONS.info} s={11}/></button>
          <button className="ws-add" onClick={handleAddClick} aria-label="Ajouter au panier"
            disabled={addDisabled} title={deliveryBlocked ? 'Non disponible en livraison' : stockExhausted ? 'Stock épuisé pour la livraison' : undefined}>
            {stockExhausted ? '✕' : '+'}
          </button>
        </div>
      </div>
      <div className="ws-card__body" onClick={(e) => e.stopPropagation()}>
        {p.portions && (
          <div className="ws-card__portions" aria-label="Portions disponibles">
            <PortionGlyph size={12}/>
            <span>Portions disponibles</span>
          </div>
        )}
        <div className="ws-card__name">{p.name}</div>
        <div className="ws-card__meta">
          <span className="ws-card__price">€{price.toFixed(2)}{hasOptions && <span className="ws-card__from"> · à partir de</span>}</span>
          {isDelivery && deliveryStockLeft !== null && !stockExhausted && (
            <span className="ws-card__stock">{deliveryStockLeft} dispo</span>
          )}
          {stockExhausted && <span className="ws-card__stock ws-card__stock--out">Épuisé livraison</span>}
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
const _CROSS_PORTION_FALLBACK = { x: 4, y: 1, threshold: 4, label: '4 quarts achetés, 1 offert' };

function computeCrossPortionOffer(basket, rule) {
  const r = rule || _CROSS_PORTION_FALLBACK;
  if (!Array.isArray(basket) || basket.length === 0) return null;
  const items = [];
  for (const l of basket) {
    if (!l.crossPortion) continue;
    if (!l.portion) continue;
    const unitsPerItem = portionUnitsFor(l.portion);
    if (unitsPerItem <= 0) continue;
    const quarterValue = (l.basePrice || 0) * 0.27;
    const total = unitsPerItem * (l.qty || 0);
    for (let i = 0; i < total; i++) {
      items.push({ price: quarterValue, name: l.name });
    }
  }
  const eligibleCount = items.length;
  if (eligibleCount === 0) return null;
  const groupSize = r.x + r.y;
  items.sort((a, b) => a.price - b.price);
  const cycles = Math.floor(eligibleCount / groupSize);
  const freeCount = cycles * r.y;
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

function CrossPortionStrip({ calc }) {
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
    lede = `${freeCount} quart${freeCount > 1 ? 's' : ''} offert${freeCount > 1 ? 's' : ''}${names ? ' · ' + names : ''}`;
  } else {
    lede = `Plus que ${calc.toNext} portion${calc.toNext > 1 ? 's' : ''} pour profiter de l'offre.`;
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
          <div className="ws-cross__title">Offre cumulable · {threshold} quarts achetés, 1 offert</div>
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
        <div className="ws-cross__hint">
          Le quart le moins cher est offert automatiquement · cumul tartes, quiches & gâteaux (entier = 4 portions, demi = 2, quart = 1).
        </div>
      )}
      {unlocked && remainder > 0 && (
        <div className="ws-cross__nudge">
          +{toNextCycle} portion{toNextCycle > 1 ? 's' : ''} pour un quart de plus offert.
        </div>
      )}
    </div>
  );
}

function Basket({ shop, mode, basket, onClose, onCheckout, onRemove }) {
  // TODO[BACKEND]: replace with `await WSPricing.quote({ shopId, mode, basket })`
  // and render the returned subtotal / discounts / total. The synchronous
  // computation below is a fallback so the demo basket still totals correctly
  // before the API is wired. The 5% pickup promo is a hardcoded business rule
  // and MUST disappear once /quote returns it as a discount line.
  const [crossPortionRule, setCrossPortionRule] = React.useState(null);
  React.useEffect(() => {
    if (window.WSPricing && typeof window.WSPricing.getCrossPortionRule === 'function') {
      window.WSPricing.getCrossPortionRule()
        .then((r) => { if (r) setCrossPortionRule(r); })
        .catch(() => {});
    }
  }, []);
  const subtotal = basket.reduce((t, l) => t + l.price * l.qty, 0);
  const crossOffer = computeCrossPortionOffer(basket, crossPortionRule);
  const crossSavings = crossOffer?.savings || 0;
  const promo = mode === 'collect' ? subtotal * 0.05 : 0;
  const total = Math.max(0, subtotal - promo - crossSavings);
  return (
    <aside className="ws-basket">
      <div className="ws-basket__head">
        <button className="ws-basket__back"><Pict d={ICONS.back} s={11}/> Retour</button>
        <span className="ws-basket__title">Récapitulatif de commande</span>
      </div>

      <div className={`ws-basket__mode ws-basket__mode--${mode}`}>
        <span className="ws-basket__mode-dot"/>
        {mode === 'collect' ? 'Collecte en magasin' : 'Livraison au bureau'}
      </div>

      <div className="ws-basket__items">
        {basket.length === 0 && (
          <div className="ws-basket__empty">Votre panier est vide.</div>
        )}
        {basket.map((l) => (
          <div key={l.line} className="ws-line">
            <div className="ws-line__qty">×{l.qty}</div>
            <div className="ws-line__body">
              <div className="ws-line__name">{l.name}</div>
              {l.options.map((o, i) => (<div key={i} className="ws-line__opt">{o.label}</div>))}
              {l.offerLabel && (
                <div className="ws-line__offer">Offre {l.offerLabel}{l.offerDiscount ? ` · −€${Number(l.offerDiscount).toFixed(2)}` : ''}</div>
              )}
            </div>
            <div className="ws-line__price">€{(l.price * l.qty).toFixed(2)}</div>
            {typeof onRemove === 'function' && (
              <button
                type="button"
                className="ws-line__remove"
                onClick={() => onRemove(l.line)}
                aria-label={`Retirer ${l.name} du panier`}
                title="Retirer du panier"
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
      </div>

      {crossOffer && <CrossPortionStrip calc={crossOffer}/>}

      <div className="ws-basket__sums">
        {basket.length > 0 && (
          <div className="ws-basket__row">
            <span>Sous-total</span>
            <span>€{subtotal.toFixed(2)}</span>
          </div>
        )}

        {crossSavings > 0 && (
          <div className="ws-basket__row ws-basket__row--promo">
            <span>Offre cumulable · {crossOffer?.freeCount || 0} quart{(crossOffer?.freeCount || 0) > 1 ? 's' : ''} offert{(crossOffer?.freeCount || 0) > 1 ? 's' : ''}</span>
            <span>−€{crossSavings.toFixed(2)}</span>
          </div>
        )}

        {promo > 0 && (
          <div className="ws-basket__row ws-basket__row--promo">
            <span>Réduction Webshop · 5%</span>
            <span>−€{promo.toFixed(2)}</span>
          </div>
        )}

        <div className="ws-basket__total">
          <span>Total TTC</span>
          <span className="ws-basket__total-amount">€{total.toFixed(2)}</span>
        </div>
      </div>

      <button className="ws-cta" style={{ background: 'var(--color-primary)' }} onClick={onCheckout} disabled={!basket.length}>
        Passer au paiement
        <Pict d={<path d="M5 12h14M13 5l7 7-7 7"/>} s={13}/>
      </button>

      <div className="ws-basket__foot">
        <Pict d={ICONS.pin} s={11}/> {shop.address}
      </div>
    </aside>
  );
}

// =========================================================================
// CATEGORY ROW + SUBCATEGORIES + SEASONAL ASSORTMENTS
// =========================================================================
function CategoryRow({ active, sub, onSelect, onSelectSub, accent, tint, categories, assortments }) {
  const ALL = { id: 'all', label: 'Tout', img: 'img/cat-all.png' };
  const cats = categories || W_CATEGORIES;
  const assorts = assortments || W_ASSORTMENTS;
  const activeCat = cats.find((c) => c.id === active);
  const subs = activeCat?.subs || [];
  const visibleCount = 5;
  const [showAllSubs, setShowAllSubs] = React.useState(false);
  const visibleSubs = showAllSubs ? subs : subs.slice(0, visibleCount);
  const hiddenCount = subs.length - visibleCount;

  React.useEffect(() => { setShowAllSubs(false); }, [active]);

  return (
    <div className="ws-cats-wrap">
      <div className="ws-cats">
        {[ALL, ...cats].map((c) => {
          const isOn = active === c.id;
          return (
            <button key={c.id} className={`ws-cat${isOn ? ' is-active' : ''}`} onClick={() => onSelect(c.id)} style={isOn ? { '--cat-accent': accent, '--cat-tint': tint } : {}}>
              <span className="ws-cat__tile"><img src={c.img} alt=""/></span>
              <span className="ws-cat__lbl">{c.label}</span>
            </button>
          );
        })}

        {/* Seasonal assortments — same badge style, but distinct shape (notched corner) */}
        <div className="ws-cats__sep" aria-hidden="true"/>
        {assorts.map((a) => {
          const isOn = active === `season:${a.id}`;
          return (
            <button key={a.id} className={`ws-cat ws-cat--season${isOn ? ' is-active' : ''}`} onClick={() => onSelect(`season:${a.id}`)} style={isOn ? { '--cat-accent': accent, '--cat-tint': tint } : {}}>
              <span className="ws-cat__tile">
                <img src={a.img} alt=""/>
              </span>
              <span className="ws-cat__lbl">{a.label}</span>
            </button>
          );
        })}
      </div>

      {/* Subcategory strip — appears only when a main category is active */}
      {activeCat && subs.length > 0 && (
        <>
          <div className="ws-subcats__rule" aria-hidden="true"/>
          <div className="ws-subcats">
            {visibleSubs.map((s) => {
              const isOn = sub === s.id;
              return (
                <button key={s.id} className={`ws-subcat${isOn ? ' is-active' : ''}`} onClick={() => onSelectSub(isOn ? null : s.id)} style={isOn ? { '--cat-accent': accent, '--cat-tint': tint } : {}}>
                  <span className="ws-subcat__tile"><img src={s.img} alt=""/></span>
                  <span className="ws-subcat__lbl">{s.label}</span>
                </button>
              );
            })}
            {hiddenCount > 0 && !showAllSubs && (
              <button className="ws-subcat ws-subcat--more" onClick={() => setShowAllSubs(true)}>
                <span className="ws-subcat__tile"><span className="ws-subcat__more-num">+{hiddenCount}</span></span>
                <span className="ws-subcat__lbl">Voir plus</span>
              </button>
            )}
          </div>
        </>
      )}
    </div>
  );
}

// =========================================================================
// NAVBAR — three variants share the same internals but wrap differently
// =========================================================================

// Variant A — Subtle: small shop chip after brand
function NavbarA({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, user, onAccount, onAllergens, deliveryCutoffPassed }) {
  return (
    <header className="ws-nav ws-nav--A">
      <div className="ws-nav__left">
        <button className="ws-nav__shopchip" onClick={onSwitchShop}>
          <span className="ws-nav__shopchip-dot"/>
          <span>{shop.name}</span>
          <Pict d={ICONS.chev} s={10}/>
        </button>
        <DatePill mode={mode} value={date} onChange={onDate} deliveryCutoffPassed={deliveryCutoffPassed}/>
        <ModePills mode={mode} onChange={onMode} deliveryCutoffPassed={deliveryCutoffPassed}/>
      </div>
      <div className="ws-nav__right">
        {window.LangChip && <window.LangChip />}
        {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
        <button className="ws-nav__icon" aria-label="Compte" onClick={onAccount}>
          {user
            ? <span className="ws-nav__avatar" aria-hidden="true">{user.firstName?.[0] || user.email?.[0]?.toUpperCase() || '·'}</span>
            : <Pict d={ICONS.user} s={15}/>}
        </button>
        <button className="ws-nav__icon ws-nav__cart" aria-label="Panier">
          <Pict d={ICONS.bag} s={15}/>
          {cartCount > 0 && <span className="ws-nav__cart-badge">{cartCount}</span>}
        </button>
      </div>
    </header>
  );
}

// Variant B — Medium: full colored brand bar above navbar
function NavbarB({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, onAllergens, deliveryCutoffPassed }) {
  return (
    <>
      <div className="ws-shopbar" style={{ background: 'var(--color-primary)' }}>
        <div className="ws-shopbar__inner">
          <span className="ws-shopbar__pin"><Pict d={ICONS.pin} s={12}/></span>
          <span className="ws-shopbar__name">Vous commandez chez · <strong>{shop.name}</strong></span>
          <span className="ws-shopbar__city">{shop.city} · {shop.address}</span>
          <button className="ws-shopbar__switch" onClick={onSwitchShop}>
            Changer de boutique <Pict d={ICONS.switch} s={12}/>
          </button>
        </div>
      </div>
      <header className="ws-nav ws-nav--B">
        <div className="ws-nav__left">
          <span className="ws-nav__brand">L'Atelier By</span>
          <DatePill mode={mode} value={date} onChange={onDate} deliveryCutoffPassed={deliveryCutoffPassed}/>
          <ModePills mode={mode} onChange={onMode} deliveryCutoffPassed={deliveryCutoffPassed}/>
        </div>
        <div className="ws-nav__right">
          {window.LangChip && <window.LangChip />}
          {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
          <button className="ws-nav__icon" aria-label="Compte"><Pict d={ICONS.user} s={15}/></button>
          <button className="ws-nav__icon ws-nav__cart" aria-label="Panier">
            <Pict d={ICONS.bag} s={15}/>
            {cartCount > 0 && <span className="ws-nav__cart-badge">{cartCount}</span>}
          </button>
        </div>
      </header>
    </>
  );
}

// Variant C — Strong: full per-shop accent. Brand wordmark, navbar background, CTA, focus rings all picked up.
function NavbarC({ shop, mode, onMode, onSwitchShop, cartCount, date, onDate, onAllergens, deliveryCutoffPassed }) {
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
        <DatePill mode={mode} value={date} onChange={onDate} deliveryCutoffPassed={deliveryCutoffPassed}/>
        <ModePills mode={mode} onChange={onMode} deliveryCutoffPassed={deliveryCutoffPassed}/>
      </div>
      <div className="ws-nav__right">
        {window.LangChip && <window.LangChip />}
        {window.AllergenNavButton && <window.AllergenNavButton onClick={onAllergens}/>}
        <button className="ws-nav__icon" aria-label="Compte"><Pict d={ICONS.user} s={15}/></button>
        <button className="ws-nav__icon ws-nav__cart" aria-label="Panier">
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
function useSwipeDownToClose(onClose) {
  const ref = React.useRef(null);
  const state = React.useRef({ y0: 0, dy: 0, dragging: false, atTop: true });
  React.useEffect(() => {
    const el = ref.current; if (!el) return;
    function onStart(e) {
      const t = e.touches ? e.touches[0] : e;
      state.current.atTop = el.scrollTop <= 0;
      // Allow dragging when starting at scroll-top OR on the handle itself
      const onHandle = e.target.closest && e.target.closest('.ws-modal__handle');
      if (!state.current.atTop && !onHandle) return;
      state.current.y0 = t.clientY; state.current.dy = 0; state.current.dragging = true;
      el.style.transition = 'none';
    }
    function onMove(e) {
      if (!state.current.dragging) return;
      const t = e.touches ? e.touches[0] : e;
      const dy = Math.max(0, t.clientY - state.current.y0);
      state.current.dy = dy;
      el.style.transform = `translateY(${dy}px)`;
      if (dy > 4 && e.cancelable) e.preventDefault();
    }
    function onEnd() {
      if (!state.current.dragging) return;
      state.current.dragging = false;
      el.style.transition = 'transform 220ms cubic-bezier(.2,.8,.2,1)';
      if (state.current.dy > 110) {
        el.style.transform = 'translateY(100%)';
        setTimeout(() => onClose && onClose(), 200);
      } else {
        el.style.transform = '';
      }
    }
    el.addEventListener('touchstart', onStart, { passive: true });
    el.addEventListener('touchmove', onMove, { passive: false });
    el.addEventListener('touchend', onEnd);
    el.addEventListener('touchcancel', onEnd);
    return () => {
      el.removeEventListener('touchstart', onStart);
      el.removeEventListener('touchmove', onMove);
      el.removeEventListener('touchend', onEnd);
      el.removeEventListener('touchcancel', onEnd);
    };
  }, [onClose]);
  return ref;
}

function ModalShell({ onClose, children, narrow }) {
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
        <button className="ws-modal__close" onClick={onClose} aria-label="Fermer"><Pict d={ICONS.close} s={14}/></button>
        {children}
        <div className="ws-modal__rail" aria-hidden={!(showArrows.up || showArrows.down)}>
          <button type="button" className={`ws-modal__rail-btn${showArrows.up ? '' : ' is-disabled'}`} onClick={() => nudge(-1)} aria-label="Remonter">
            <svg viewBox="0 0 16 16" width="12" height="12"><path d="M3 10l5-5 5 5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/></svg>
          </button>
          <button type="button" className={`ws-modal__rail-btn${showArrows.down ? '' : ' is-disabled'}`} onClick={() => nudge(1)} aria-label="Descendre">
            <svg viewBox="0 0 16 16" width="12" height="12"><path d="M3 6l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/></svg>
          </button>
        </div>
      </div>
    </div>
  );
}

function LoginModal({ open, onClose, onLogin, onRegister }) {
  const [tab, setTab] = useState('login');
  const [form, setForm] = useState({ email: '', password: '', firstName: '', lastName: '' });
  const [err, setErr] = useState('');
  const [loading, setLoading] = useState(false);
  if (!open) return null;
  function set(k, v) { setForm((f) => ({ ...f, [k]: v })); setErr(''); }
  async function submit(e) {
    e.preventDefault();
    setLoading(true); setErr('');
    try {
      if (tab === 'login') {
        const r = window.WSAuth
          ? await window.WSAuth.login({ email: form.email, password: form.password })
          : authLogin(form.email, form.password);
        if (!r.ok) { setErr(r.error || 'Identifiants incorrects.'); return; }
        onLogin(r.user); onClose();
      } else {
        if (!form.firstName || !form.lastName) { setErr('Prénom et nom requis.'); return; }
        if (!form.email || !form.password) { setErr('Email et mot de passe requis.'); return; }
        const r = window.WSAuth
          ? await window.WSAuth.register(form)
          : authRegister(form);
        if (!r.ok) { setErr(r.error || "Erreur lors de l'inscription."); return; }
        onRegister(r.user); onClose();
      }
    } catch (_) {
      setErr('Erreur réseau. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  }
  return (
    <ModalShell onClose={onClose} narrow>
      <p className="ws-modal__eyebrow">Mon compte</p>
      <h2 className="ws-modal__title">{tab === 'login' ? <>Bon retour <em>parmi nous</em>.</> : <>Créez <em>votre</em> compte.</>}</h2>
      <p className="ws-modal__lede">{tab === 'login' ? 'Connectez-vous pour retrouver vos commandes et votre bureau.' : 'Quelques secondes pour commander, suivre et faire livrer.'}</p>
      <div className="ws-tabs">
        <button className={`ws-tab${tab === 'login' ? ' is-active' : ''}`} onClick={() => { setTab('login'); setErr(''); }}>Connexion</button>
        <button className={`ws-tab${tab === 'register' ? ' is-active' : ''}`} onClick={() => { setTab('register'); setErr(''); }}>Créer un compte</button>
      </div>
      <form className="ws-form" onSubmit={submit}>
        {tab === 'register' && (
          <div className="ws-form__row2">
            <label className="ws-field"><span>Prénom</span><input value={form.firstName} onChange={(e) => set('firstName', e.target.value)} autoComplete="given-name"/></label>
            <label className="ws-field"><span>Nom</span><input value={form.lastName} onChange={(e) => set('lastName', e.target.value)} autoComplete="family-name"/></label>
          </div>
        )}
        <label className="ws-field"><span>Email</span><input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} autoComplete="email"/></label>
        <label className="ws-field"><span>Mot de passe</span><input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} autoComplete={tab === 'login' ? 'current-password' : 'new-password'}/></label>
        {err && <p className="ws-form__err">{err}</p>}
        {tab === 'login' && <p className="ws-form__hint">Démo : <strong>marie@acme.be</strong> · <strong>lou@borderline.be</strong> · <strong>jules@indep.be</strong> — mdp <strong>demo</strong></p>}
        <button type="submit" className="ws-cta ws-cta--block" disabled={loading}>{loading ? 'Chargement…' : (tab === 'login' ? 'Se connecter' : 'Créer mon compte')}</button>
      </form>
    </ModalShell>
  );
}

function AccountModal({ open, user, onClose, onLogout, onRequestOffice, onUpdateUser, shops, currentShopId, onChangePreferredShop, office, tour }) {
  const [form, setForm] = useState({
    firstName: user?.firstName || '',
    lastName: user?.lastName || '',
    company: user?.company || '',
    email: user?.email || '',
    phone: user?.phone || '',
    postalCode: user?.postalCode || '',
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
  const [vies, setVies] = useState({ status: 'idle', message: '' }); // idle | loading | ok | invalid | unavailable
  const [fidOpen, setFidOpen] = useState(false);
  // Office unplug/reconnect flow: 'idle' | 'confirm' | 'ask' | 'pick' | 'add'
  const [officeStep, setOfficeStep] = useState('idle');
  const [approvedOffices, setApprovedOffices] = useState([]);
  const [approvedOfficeTours, setApprovedOfficeTours] = useState({});
  const [pickedOfficeId, setPickedOfficeId] = useState('');
  const [newOffice, setNewOffice] = useState({
    name: '', vat: '', address: '', postalCode: '', city: '',
    contact: '', email: '', phone: '', preferredShopId: '',
  });
  const [officeErr, setOfficeErr] = useState('');
  const [officeBusy, setOfficeBusy] = useState(false);

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
    setVies({ status: 'idle', message: '' });
  }, [user]);

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
  function persistPartial(patch) {
    const updated = { ...user, ...patch };
    setForm((f) => ({ ...f, ...patch }));
    if (typeof onUpdateUser === 'function') onUpdateUser(updated);
    if (window.WSI18n && window.WSI18n.setCustomer) {
      const existing = window.WSI18n.getCustomer() || {};
      window.WSI18n.setCustomer({ ...existing, ...updated });
    }
  }

  // Fidelity toggle: OFF→ON opens the QR modal; ON→OFF unlinks immediately.
  function toggleFidelity(next) {
    if (next) {
      setFidOpen(true);              // QR modal handles confirmation
    } else {
      persistPartial({ fidelityApp: { active: false, linkedAt: null } });
    }
  }
  function onFidelityConfirmed({ linkedAt }) {
    setFidOpen(false);
    persistPartial({ fidelityApp: { active: true, linkedAt } });
  }

  // ── Office: unplug / reconnect / add new ───────────────────────────
  async function loadApprovedOffices() {
    if (!window.WSOffices) return;
    setOfficeBusy(true);
    try {
      const list = await window.WSOffices.listApproved();
      const filtered = (list || []).filter((o) => o && o.id !== user.officeId);
      setApprovedOffices(filtered);
      if (window.WSTours && filtered.length) {
        const tourIds = [...new Set(filtered.map((o) => o.tourId).filter(Boolean))];
        const tourEntries = await Promise.all(
          tourIds.map((id) => window.WSTours.get(id).then((t) => [id, t]).catch(() => [id, null]))
        );
        setApprovedOfficeTours(Object.fromEntries(tourEntries));
      }
    } finally { setOfficeBusy(false); }
  }
  function startUnplug() { setOfficeStep('confirm'); setOfficeErr(''); }
  function confirmUnplug() {
    persistPartial({ officeId: null });
    setOfficeStep('ask');
  }
  function chooseLinkAnother() {
    setPickedOfficeId('');
    setOfficeStep('pick');
    loadApprovedOffices();
  }
  function chooseDone() {
    setOfficeStep('idle');
  }
  function confirmPick() {
    if (!pickedOfficeId) { setOfficeErr('Sélectionnez un bureau.'); return; }
    persistPartial({ officeId: pickedOfficeId });
    setOfficeStep('idle');
  }
  function setNewOfficeField(k, v) { setNewOffice((f) => ({ ...f, [k]: v })); setOfficeErr(''); }
  async function submitNewOffice() {
    const required = ['name', 'address', 'postalCode', 'city', 'contact', 'email', 'phone', 'preferredShopId'];
    for (const k of required) {
      if (!String(newOffice[k] || '').trim()) {
        setOfficeErr('Tous les champs sont obligatoires (sauf TVA).');
        return;
      }
    }
    if (!window.WSOffices) { setOfficeErr('Service indisponible.'); return; }
    setOfficeBusy(true); setOfficeErr('');
    try {
      const office = await window.WSOffices.requestNew({
        ...newOffice,
        requestedBy: user.email,
      });
      persistPartial({ officeId: office.id });
      setNewOffice({ name: '', vat: '', address: '', postalCode: '', city: '', contact: '', email: '', phone: '', preferredShopId: '' });
      setOfficeStep('idle');
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
    if (!window.WSVies) { setVies({ status: 'unavailable', message: 'Service VIES indisponible.' }); return; }
    setVies({ status: 'loading', message: '' });
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
  function saveProfile(e) {
    e.preventDefault();
    const updated = { ...user, ...form };
    if (typeof onUpdateUser === 'function') onUpdateUser(updated);
    if (window.WSI18n && window.WSI18n.setCustomer) {
      const existing = window.WSI18n.getCustomer() || {};
      window.WSI18n.setCustomer({ ...existing, ...updated });
    }
    setSavedFlash(true);
    setTimeout(() => setSavedFlash(false), 1800);
  }
  return (
    <ModalShell onClose={onClose} narrow>
      <p className="ws-modal__eyebrow">Mon compte</p>
      <h2 className="ws-modal__title">Bonjour <em>{form.firstName || user.firstName}</em>.</h2>
      <p className="ws-modal__lede">{user.email}</p>

      {form.fidelityApp?.active && (
        <aside className="ws-fidinfo" role="note" aria-label="Information application fidélité">
          <div className="ws-fidinfo__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
              <rect x="6" y="2.5" width="12" height="19" rx="2.5"/>
              <path d="M10 18.5h4"/>
              <path d="M9.5 6h5"/>
            </svg>
          </div>
          <div className="ws-fidinfo__body">
            <div className="ws-fidinfo__title">Tout est dans votre application L'Atelier</div>
            <p className="ws-fidinfo__lede">
              Vos commandes sont gérées <strong>directement dans l'application fidélité</strong>. Vous y retrouverez&nbsp;:
            </p>
            <ul className="ws-fidinfo__list">
              <li>Vos <strong>commandes précédentes</strong> et leur <strong>statut</strong> en temps réel</li>
              <li>L'ouverture et le suivi de vos <strong>tickets</strong></li>
              <li>Vos <strong>demandes de facture</strong> et la <strong>conversion ticket → facture</strong></li>
            </ul>
            <p className="ws-fidinfo__foot">
              Pour limiter les e-mails, nous privilégions désormais les notifications de l'application.
            </p>
          </div>
        </aside>
      )}

      <form className="ws-acc__section" onSubmit={saveProfile}>
        <div className="ws-acc__section-h">Mes informations</div>
        <div className="ws-acc__form">
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">Prénom</span>
            <input type="text" className="ws-acc__input" value={form.firstName}
              onChange={(e) => setField('firstName', e.target.value)} placeholder="Prénom" />
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">Nom</span>
            <input type="text" className="ws-acc__input" value={form.lastName}
              onChange={(e) => setField('lastName', e.target.value)} placeholder="Nom" />
          </label>
          <label className="ws-acc__field ws-acc__field--full">
            <span className="ws-acc__field-label">Entreprise</span>
            <input type="text" className="ws-acc__input" value={form.company}
              onChange={(e) => setField('company', e.target.value)} placeholder="Nom de l'entreprise" />
          </label>
          <label className="ws-acc__field ws-acc__field--full">
            <span className="ws-acc__field-label">E-mail</span>
            <input type="email" className="ws-acc__input" value={form.email}
              onChange={(e) => setField('email', e.target.value)} placeholder="vous@exemple.com" required />
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">Téléphone</span>
            <input type="tel" className="ws-acc__input" value={form.phone}
              onChange={(e) => setField('phone', e.target.value)} placeholder="+32 ..." />
          </label>
          <label className="ws-acc__field">
            <span className="ws-acc__field-label">Code postal</span>
            <input type="text" className="ws-acc__input" value={form.postalCode}
              onChange={(e) => setField('postalCode', e.target.value)} placeholder="1000"
              inputMode="numeric" maxLength="10" />
          </label>
        </div>

        <label className="ws-acc__toggle">
          <input type="checkbox" checked={form.isBusiness}
            onChange={(e) => setField('isBusiness', e.target.checked)} />
          <span className="ws-acc__toggle-track" aria-hidden="true"><span className="ws-acc__toggle-thumb"/></span>
          <span className="ws-acc__toggle-label">Je fais des achats pour une entreprise / avec facturation</span>
        </label>

        {form.isBusiness && (
          <details className="ws-acc__invoice" open>
            <summary className="ws-acc__invoice-sum">Facturation entreprise</summary>
            <div className="ws-acc__invoice-body">
              <div className="ws-acc__form ws-acc__form--vat">
                <label className="ws-acc__field ws-acc__field--country">
                  <span className="ws-acc__field-label">Pays</span>
                  <select className="ws-acc__input" value={form.invoice.country}
                    onChange={(e) => setInvoiceField('country', e.target.value)}>
                    {['BE','NL','FR','DE','LU','IT','ES','AT','PT','IE','FI','SE','DK','PL','CZ'].map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                </label>
                <label className="ws-acc__field ws-acc__field--vat">
                  <span className="ws-acc__field-label">Numéro de TVA</span>
                  <div className="ws-acc__vatrow">
                    <input type="text" className="ws-acc__input" value={form.invoice.vat}
                      onChange={(e) => setInvoiceField('vat', e.target.value)}
                      placeholder="0123456789" autoComplete="off" />
                    <button type="button" className="ws-acc__vat-btn"
                      disabled={vies.status === 'loading' || !form.invoice.vat}
                      onClick={checkVat}>
                      {vies.status === 'loading' ? 'Vérification…' : 'Vérifier (VIES)'}
                    </button>
                  </div>
                </label>
              </div>
              {vies.status === 'ok' && <p className="ws-acc__vat-msg ws-acc__vat-msg--ok">✓ {vies.message}</p>}
              {vies.status === 'invalid' && <p className="ws-acc__vat-msg ws-acc__vat-msg--err">⚠ {vies.message}</p>}
              {vies.status === 'unavailable' && (
                <p className="ws-acc__vat-msg ws-acc__vat-msg--err">
                  ⚠ {vies.message} <button type="button" className="ws-acc__retry" onClick={checkVat}>Réessayer</button>
                </p>
              )}
              <div className="ws-acc__form">
                <label className="ws-acc__field ws-acc__field--full">
                  <span className="ws-acc__field-label">Raison sociale</span>
                  <input type="text" className="ws-acc__input" value={form.invoice.name}
                    onChange={(e) => setInvoiceField('name', e.target.value)} placeholder="Nom légal" />
                </label>
                <label className="ws-acc__field ws-acc__field--full">
                  <span className="ws-acc__field-label">Adresse</span>
                  <input type="text" className="ws-acc__input" value={form.invoice.address}
                    onChange={(e) => setInvoiceField('address', e.target.value)} placeholder="Rue et numéro" />
                </label>
                <label className="ws-acc__field">
                  <span className="ws-acc__field-label">Code postal</span>
                  <input type="text" className="ws-acc__input" value={form.invoice.postalCode}
                    onChange={(e) => setInvoiceField('postalCode', e.target.value)} placeholder="1000" />
                </label>
                <label className="ws-acc__field">
                  <span className="ws-acc__field-label">Ville</span>
                  <input type="text" className="ws-acc__input" value={form.invoice.city}
                    onChange={(e) => setInvoiceField('city', e.target.value)} placeholder="Bruxelles" />
                </label>
              </div>
              <p className="ws-acc__hint">Les champs manquants peuvent être complétés manuellement.</p>
            </div>
          </details>
        )}

        <div className="ws-acc__form-foot">
          <button type="submit" className="ws-cta">Enregistrer</button>
          {savedFlash && <span className="ws-acc__saved">✓ Enregistré</span>}
        </div>
      </form>

      <div className="ws-acc__section">
        <div className="ws-acc__section-h">Préférences</div>

        {/* Preferred shop ----------------------------------------------- */}
        <div className="ws-acc__row">
          <div className="ws-acc__row-body">
            <div className="ws-acc__row-title">Boutique préférée</div>
            <div className="ws-acc__row-sub">
              Détermine votre boutique par défaut à la connexion. Conditionne aussi votre éligibilité à la livraison au bureau et les créneaux disponibles.
            </div>
            <div className="ws-acc__select-row">
              <select
                className="ws-acc__input"
                value={form.preferredShopId || ''}
                onChange={(e) => changePreferredShop(e.target.value || null)}
                aria-label="Boutique préférée"
              >
                <option value="">— Aucune (choisir à chaque visite) —</option>
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
            <div className="ws-acc__row-title">Application fidélité</div>
            <div className="ws-acc__row-sub">
              Liez votre compte web à l'application mobile L'Atelier pour synchroniser vos points et vos avantages.
            </div>
            {form.fidelityApp?.active ? (
              <span className="ws-acc__row-status">Liée · {form.fidelityApp.linkedAt ? new Date(form.fidelityApp.linkedAt).toLocaleDateString('fr-BE') : 'récemment'}</span>
            ) : (
              <span className="ws-acc__row-status ws-acc__row-status--off">Non liée</span>
            )}
          </div>
          <label className="ws-acc__toggle" aria-label="Activer l'application fidélité">
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
        onConfirmed={onFidelityConfirmed}
        onClose={() => setFidOpen(false)}
      />

      <div className="ws-acc__section">
        <div className="ws-acc__section-h">Mon bureau</div>

        {officeStep === 'idle' && status === 'active' && (
          <div className="ws-acc__card ws-acc__card--ok">
            <div className="ws-acc__card-row"><span className="ws-acc__k">Bureau</span><span className="ws-acc__v">{office.name}</span></div>
            {office.address && <div className="ws-acc__card-row"><span className="ws-acc__k">Adresse</span><span className="ws-acc__v">{office.address}</span></div>}
            {tour && tour.shopId && (shops || []).find((s) => s.id === tour.shopId) && (
              <div className="ws-acc__card-row"><span className="ws-acc__k">Boutique</span><span className="ws-acc__v">{(shops || []).find((s) => s.id === tour.shopId).name}</span></div>
            )}
            <div className="ws-acc__card-row"><span className="ws-acc__k">Tournée</span><span className="ws-acc__v">{tour.name} · {tour.window}</span></div>
            <div className="ws-acc__btnrow">
              <span className="ws-acc__badge ws-acc__badge--ok">Livraison active</span>
              <button type="button" className="ws-acc__unplug" onClick={startUnplug}>Délier ce bureau</button>
            </div>
          </div>
        )}

        {officeStep === 'idle' && status === 'pending' && (
          <div className="ws-acc__card ws-acc__card--pending">
            <div className="ws-acc__card-row"><span className="ws-acc__k">Bureau</span><span className="ws-acc__v">{office.name}</span></div>
            <div className="ws-acc__card-row"><span className="ws-acc__k">Contact</span><span className="ws-acc__v">{office.contact}</span></div>
            <div className="ws-acc__badge ws-acc__badge--pending">En attente de validation</div>
            <p className="ws-acc__note">Votre bureau sera relié à une tournée par notre équipe. En attendant, commandez en Click &amp; Collect.</p>
            <button type="button" className="ws-acc__unplug" onClick={startUnplug}>Délier ce bureau</button>
          </div>
        )}

        {officeStep === 'idle' && status === 'unlinked' && (
          <div className="ws-acc__card ws-acc__card--empty">
            <p className="ws-acc__note">Aucun bureau associé. Liez-vous à un bureau approuvé ou demandez l'ajout du vôtre.</p>
            <button className="ws-cta ws-cta--block" onClick={chooseLinkAnother}>Lier un bureau</button>
          </div>
        )}

        {officeStep === 'confirm' && (
          <div className="ws-acc__card ws-acc__card--warn">
            <p className="ws-acc__note"><strong>Délier votre compte de ce bureau ?</strong> La livraison au bureau sera désactivée jusqu'à ce que vous liiez un nouveau bureau. Votre compte reste actif.</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('idle')}>Annuler</button>
              <button type="button" className="ws-cta" onClick={confirmUnplug}>Confirmer</button>
            </div>
          </div>
        )}

        {officeStep === 'ask' && (
          <div className="ws-acc__card">
            <p className="ws-acc__note"><strong>Bureau délié.</strong> Souhaitez-vous lier un autre bureau maintenant ?</p>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={chooseDone}>Non</button>
              <button type="button" className="ws-cta" onClick={chooseLinkAnother}>Oui</button>
            </div>
          </div>
        )}

        {officeStep === 'pick' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>Choisir un bureau approuvé</div>
            {officeBusy && <p className="ws-acc__hint">Chargement…</p>}
            {!officeBusy && approvedOffices.length === 0 && (
              <p className="ws-acc__hint">Aucun bureau approuvé disponible.</p>
            )}
            {!officeBusy && approvedOffices.length > 0 && (
              <select className="ws-acc__input" value={pickedOfficeId} onChange={(e) => { setPickedOfficeId(e.target.value); setOfficeErr(''); }}>
                <option value="">— Sélectionnez un bureau —</option>
                {approvedOffices.map((o) => {
                  const t = approvedOfficeTours[o.tourId] || null;
                  const shopForTour = t && (shops || []).find((s) => s.id === t.shopId);
                  return <option key={o.id} value={o.id}>{o.name}{shopForTour ? ` · ${shopForTour.name}` : ''}</option>;
                })}
              </select>
            )}
            {officeErr && <p className="ws-form__err">{officeErr}</p>}
            <button type="button" className="ws-acc__addlink" onClick={() => { setOfficeErr(''); setOfficeStep('add'); }}>+ Ajouter votre bureau</button>
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('idle')}>Annuler</button>
              <button type="button" className="ws-cta" onClick={confirmPick} disabled={!pickedOfficeId}>Confirmer</button>
            </div>
          </div>
        )}

        {officeStep === 'add' && (
          <div className="ws-acc__card">
            <div className="ws-acc__row-title" style={{ marginBottom: 6 }}>Demander l'ajout d'un bureau</div>
            <p className="ws-acc__hint">Votre demande sera enregistrée comme <em>en attente d'approbation</em>. La livraison sera activée après validation.</p>
            <div className="ws-acc__grid">
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">Nom de l'entreprise *</span>
                <input className="ws-acc__input" value={newOffice.name} onChange={(e) => setNewOfficeField('name', e.target.value)} placeholder="ACME SA"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">Numéro de TVA</span>
                <input className="ws-acc__input" value={newOffice.vat} onChange={(e) => setNewOfficeField('vat', e.target.value)} placeholder="BE0123456789"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">Boutique préférée *</span>
                <select className="ws-acc__input" value={newOffice.preferredShopId} onChange={(e) => setNewOfficeField('preferredShopId', e.target.value)}>
                  <option value="">—</option>
                  {(shops || []).map((s) => <option key={s.id} value={s.id}>{s.name} · {s.city}</option>)}
                </select>
              </label>
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">Adresse *</span>
                <input className="ws-acc__input" value={newOffice.address} onChange={(e) => setNewOfficeField('address', e.target.value)} placeholder="Rue et numéro"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">Code postal *</span>
                <input className="ws-acc__input" value={newOffice.postalCode} onChange={(e) => setNewOfficeField('postalCode', e.target.value)} placeholder="1000"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">Ville *</span>
                <input className="ws-acc__input" value={newOffice.city} onChange={(e) => setNewOfficeField('city', e.target.value)} placeholder="Bruxelles"/>
              </label>
              <label className="ws-acc__field ws-acc__field--full">
                <span className="ws-acc__field-label">Personne de contact *</span>
                <input className="ws-acc__input" value={newOffice.contact} onChange={(e) => setNewOfficeField('contact', e.target.value)} placeholder="Prénom Nom"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">E-mail *</span>
                <input type="email" className="ws-acc__input" value={newOffice.email} onChange={(e) => setNewOfficeField('email', e.target.value)} placeholder="contact@acme.be"/>
              </label>
              <label className="ws-acc__field">
                <span className="ws-acc__field-label">Téléphone *</span>
                <input className="ws-acc__input" value={newOffice.phone} onChange={(e) => setNewOfficeField('phone', e.target.value)} placeholder="+32 …"/>
              </label>
            </div>
            {officeErr && <p className="ws-form__err">{officeErr}</p>}
            <div className="ws-acc__row-foot">
              <button type="button" className="ws-fid__cancel" onClick={() => setOfficeStep('pick')}>Retour</button>
              <button type="button" className="ws-cta" onClick={submitNewOffice} disabled={officeBusy}>{officeBusy ? 'Envoi…' : 'Envoyer la demande'}</button>
            </div>
          </div>
        )}
      </div>

      {window.LangMenu && (
        <div className="ws-acc__section">
          <div className="ws-acc__section-h">Langue</div>
          <window.LangMenu />
        </div>
      )}

      <div className="ws-acc__foot">
        <button className="ws-acc__logout" onClick={() => { onLogout(); onClose(); }}>Se déconnecter</button>
      </div>
    </ModalShell>
  );
}

// =========================================================================
// FIDELITY APP LINK — QR modal
// Shown when the user toggles the fidelity-app setting from OFF to ON.
// =========================================================================
function FidelityQR({ payload }) {
  // 21x21 deterministic pseudo-QR with three corner finders. Looks the part
  // for the demo; production would use a real QR library (qrcode.react, etc).
  const grid = React.useMemo(() => {
    const N = 21;
    const cells = Array.from({ length: N * N }, () => false);
    let h = 0;
    for (let i = 0; i < payload.length; i++) h = (h * 31 + payload.charCodeAt(i)) >>> 0;
    let r = h || 1;
    for (let i = 0; i < N * N; i++) {
      r = (r * 1664525 + 1013904223) >>> 0;
      cells[i] = (r & 1) === 1;
    }
    function corner(cx, cy) {
      for (let y = 0; y < 7; y++) for (let x = 0; x < 7; x++) {
        const onEdge = x === 0 || x === 6 || y === 0 || y === 6;
        const inner = x >= 2 && x <= 4 && y >= 2 && y <= 4;
        cells[(cy + y) * N + (cx + x)] = onEdge || inner;
      }
    }
    corner(0, 0); corner(N - 7, 0); corner(0, N - 7);
    return { N, cells };
  }, [payload]);
  const N = grid.N, cell = 8, size = N * cell;
  const rects = [];
  for (let y = 0; y < N; y++) for (let x = 0; x < N; x++) {
    if (grid.cells[y * N + x]) rects.push(<rect key={`${x}-${y}`} x={x * cell} y={y * cell} width={cell} height={cell} fill="#1a1a1a"/>);
  }
  return (
    <svg className="ws-fid__qr" viewBox={`0 0 ${size} ${size}`} width="220" height="220" role="img" aria-label="QR code de liaison">
      <rect width={size} height={size} fill="#fff"/>
      {rects}
    </svg>
  );
}

function FidelityLinkPanel({ open, user, onConfirmed, onClose }) {
  const [step, setStep] = useState('qr');
  const [pulse, setPulse] = useState(false);
  useEffect(() => { if (open) { setStep('qr'); setPulse(false); } }, [open]);
  const payload = React.useMemo(() => {
    if (!open || !user) return '';
    const nonce = Math.random().toString(36).slice(2, 10);
    return `latelier://fidelity/link?u=${encodeURIComponent(user.id || user.email || 'demo')}&n=${nonce}`;
  }, [open, user?.id, user?.email]);
  if (!open) return null;
  function confirm() {
    setStep('success'); setPulse(true);
    setTimeout(() => {
      if (typeof onConfirmed === 'function') onConfirmed({ linkedAt: new Date().toISOString() });
    }, 700);
  }
  return (
    <div className="ws-fidpanel" role="region" aria-label="Liaison application fidélité">
      <div className="ws-fidpanel__head">
        <span className="ws-fidpanel__eyebrow">Application fidélité</span>
        <button type="button" className="ws-fidpanel__close" aria-label="Fermer" onClick={onClose}>×</button>
      </div>
      {step === 'qr' && (
        <div className="ws-fid ws-fid--inline">
          <div className="ws-fid__qrwrap ws-fid__qrwrap--sm">
            <FidelityQR payload={payload}/>
          </div>
          <ol className="ws-fid__steps ws-fid__steps--sm">
            <li><span className="ws-fid__step-n">1</span> Ouvrez l'app <strong>L'Atelier</strong>.</li>
            <li><span className="ws-fid__step-n">2</span> Profil → <strong>Lier un compte web</strong>.</li>
            <li><span className="ws-fid__step-n">3</span> Scannez ce code.</li>
          </ol>
          <div className="ws-fid__foot ws-fid__foot--sm">
            <button type="button" className="ws-fid__cancel" onClick={onClose}>Annuler</button>
            <button type="button" className="ws-cta ws-fid__confirm" onClick={confirm}>J'ai scanné</button>
          </div>
        </div>
      )}
      {step === 'success' && (
        <div className={`ws-fid ws-fid--ok ws-fid--inline${pulse ? ' is-pulse' : ''}`}>
          <div className="ws-fid__ok">
            <svg viewBox="0 0 64 64" width="48" height="48" aria-hidden="true">
              <circle cx="32" cy="32" r="30" fill="none" stroke="currentColor" strokeWidth="3"/>
              <path d="M18 33l10 10 18-22" fill="none" stroke="currentColor" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </div>
          <h3 className="ws-fid__ok-title">Application liée</h3>
          <p className="ws-fid__ok-sub">Votre compte mobile est connecté.</p>
        </div>
      )}
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
// Payment methods are loaded async from WSPricing.listPaymentMethods().
// The FALLBACK array is used only during the first render before the
// async call resolves, or when no endpoint is configured.
const W_PAYMENTS_FALLBACK = [
  { id: 'bancontact', label: 'Bancontact',   sub: 'Paiement instantané' },
  { id: 'visa',       label: 'Carte bancaire', sub: 'Visa · Mastercard · Amex' },
  { id: 'apple',      label: 'Apple Pay',    sub: 'Touch ID / Face ID' },
];

function usePaymentMethods(shopId, mode) {
  const [methods, setMethods] = React.useState(W_PAYMENTS_FALLBACK);
  React.useEffect(() => {
    let alive = true;
    if (window.WSPricing && typeof window.WSPricing.listPaymentMethods === 'function') {
      window.WSPricing.listPaymentMethods({ shopId, mode })
        .then((m) => { if (alive && m && m.length) setMethods(m); })
        .catch(() => {});
    }
    return () => { alive = false; };
  }, [shopId, mode]);
  return methods;
}

function CheckoutWizard({ open, onClose, shop, mode, basket, user, onLogin, onPlaced,
                          voucherInput, setVoucherInput, voucherApplied, setVoucherApplied,
                          office, tour }) {
  const [step, setStep] = useState(1);
  const [forceAuth, setForceAuth] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payErr, setPayErr] = useState(null);

  // Guest contact (collect only)
  const [contact, setContact] = useState({ firstName: '', lastName: '', email: '', phone: '' });

  // Slot
  const [slot, setSlot] = useState(null);

  // Office invoice toggle
  const [invoice, setInvoice] = useState(false);
  const [vat, setVat] = useState('');

  // Payment
  const [payment, setPayment] = useState('bancontact');

  // Reset when reopened
  useEffect(() => { if (open) { setStep(1); setSlot(null); setInvoice(false); setVat(''); setPayment('bancontact'); setForceAuth(false); setPaying(false); setPayErr(null); } }, [open]);

  if (!open) return null;

  // TODO[BACKEND]: same as above — checkout totals must come from WSPricing.quote().
  const subtotal = basket.reduce((t, l) => t + l.price * l.qty, 0);
  const promo = mode === 'collect' ? subtotal * 0.05 : 0;
  const voucherDiscount = voucherApplied && voucherApplied.ok ? voucherApplied.discount : 0;
  const total = Math.max(0, subtotal - promo - voucherDiscount);

  const isOffice = mode === 'delivery' && user && office;
  const isGuest = !user;

  // Step 1 validity
  function step1Valid() {
    if (isOffice) return true;             // all read-only, valid
    if (user)    return true;              // collect logged-in: prefilled
    return contact.firstName && contact.lastName && contact.email && contact.phone;
  }
  function step2Valid() { return Boolean(slot); }

  async function handlePay() {
    setPaying(true); setPayErr(null);
    try {
      const payload = {
        shopId: shop && shop.id,
        mode,
        slot: { slotId: slot, label: slot },
        basket: basket.map((l) => ({ productId: l.productId, qty: l.qty, portion: l.portion || null, options: l.options || [], bundleId: l.bundleId || null, bundleSlots: l.bundleSlots || {} })),
        voucher: voucherApplied && voucherApplied.ok ? voucherApplied.voucher.code : null,
        customer: user ? { id: user.id, email: user.email, firstName: user.firstName, lastName: user.lastName, phone: user.phone || null, officeId: user.officeId || null } : { ...contact },
        payment: { method: payment },
        delivery: mode === 'delivery' && office ? { officeId: office.id, tourId: office.tourId, address: office.address } : null,
        total,
        invoice: invoice ? { requested: true, vat } : null,
      };
      const result = window.WSOrders
        ? await window.WSOrders.place(payload)
        : { ok: true, orderId: 'ord-demo', total, slot, payment };
      onPlaced({ ...result, slot, payment, total });
    } catch (ex) {
      setPayErr(ex.message || 'Erreur lors du paiement. Veuillez réessayer.');
    } finally {
      setPaying(false);
    }
  }

  function next() {
    if (step === 1) {
      if (!step1Valid()) return;
      // Guest collect → force login/register before reaching step 2
      if (isGuest && !forceAuth) { setForceAuth(true); return; }
      setStep(2);
    } else if (step === 2) {
      if (!step2Valid()) return;
      setStep(3);
    } else {
      handlePay();
    }
  }

  return (
    <aside className="ws-checkout" role="dialog" aria-label="Checkout">
      <header className="ws-checkout__head">
        <button className="ws-checkout__back" onClick={onClose}>
          <Pict d={<path d="M15 6l-6 6 6 6"/>} s={12}/> Retour au panier
        </button>
        <span className="ws-checkout__title">Finalisez votre commande</span>
      </header>

      <ol className="ws-stepper">
        {[
          { n: 1, label: 'Coordonnées' },
          { n: 2, label: 'Créneau' },
          { n: 3, label: 'Paiement' },
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
          />
        )}
        {step === 2 && (
          <CheckoutStep2 mode={mode} shop={shop} office={office} tour={tour} slot={slot} setSlot={setSlot}/>
        )}
        {step === 3 && (
          <CheckoutStep3
            mode={mode} basket={basket} subtotal={subtotal} promo={promo} total={total}
            payment={payment} setPayment={setPayment}
            isOffice={isOffice} invoice={invoice} setInvoice={setInvoice} vat={vat} setVat={setVat}
            shopId={shop && shop.id} mode={mode}
            voucherInput={voucherInput} setVoucherInput={setVoucherInput}
            voucherApplied={voucherApplied} setVoucherApplied={setVoucherApplied}
            voucherDiscount={voucherDiscount}
          />
        )}
      </div>

      <footer className="ws-checkout__foot">
        {payErr && <div className="ws-checkout__pay-err" role="alert">{payErr}</div>}
        <div className="ws-checkout__foot-total">
          <span className="ws-checkout__foot-k">Total TTC</span>
          <span className="ws-checkout__foot-v">€{total.toFixed(2)}</span>
        </div>
        <div className="ws-checkout__foot-actions">
          {step > 1 && <button className="ws-btn-ghost" onClick={() => setStep((s) => s - 1)} disabled={paying}>Précédent</button>}
          <button
            className="ws-cta ws-cta--block"
            disabled={paying || (step === 1 && !step1Valid()) || (step === 2 && !step2Valid())}
            onClick={next}
          >
            {paying ? 'Traitement…' : step === 3 ? `Payer · €${total.toFixed(2)}` : (step === 1 && isGuest && !forceAuth ? 'Continuer · se connecter' : 'Continuer')}
            {!paying && step < 3 && <Pict d={<path d="M5 12h14M13 5l7 7-7 7"/>} s={13}/>}
          </button>
        </div>
      </footer>
    </aside>
  );
}

function CheckoutStep1({ mode, shop, user, office, tour, contact, setContact, forceAuth, onLoginNow }) {
  // Office Shop: all read-only
  if (mode === 'delivery' && user && office) {
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">Coordonnées de livraison</h3>
        <p className="ws-co-step__lede">Vos informations sont rattachées à votre bureau et déjà validées par L'Atelier By.</p>
        <div className="ws-co-readbox">
          <ReadRow k="Entreprise" v={office.name}/>
          <ReadRow k="Contact"    v={user.firstName + ' ' + user.lastName}/>
          <ReadRow k="Email"      v={user.email}/>
          <ReadRow k="Téléphone"  v={office.phone}/>
          <ReadRow k="Adresse"    v={office.address || '—'}/>
          <ReadRow k="Tournée"    v={tour ? tour.name + ' · ' + tour.window : '—'}/>
        </div>
        <p className="ws-co-step__locknote"><Pict d={<><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></>} s={12}/> Modifier ces informations depuis Mon compte.</p>
      </div>
    );
  }

  // Logged-in collect: prefilled, read-only (per spec)
  if (user) {
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">Vos coordonnées</h3>
        <p className="ws-co-step__lede">Pré-remplies depuis votre compte. Modifiables depuis Mon compte.</p>
        <div className="ws-co-readbox">
          <ReadRow k="Nom"       v={user.firstName + ' ' + user.lastName}/>
          <ReadRow k="Email"     v={user.email}/>
          <ReadRow k="Téléphone" v={office?.phone || '—'}/>
          <ReadRow k="Boutique"  v={shop.name + ' · ' + shop.address}/>
        </div>
      </div>
    );
  }

  // Guest collect — fields, then forced login at the gate
  if (forceAuth) {
    return (
      <div className="ws-co-step">
        <h3 className="ws-co-step__title">Connectez-vous pour continuer</h3>
        <p className="ws-co-step__lede">Pour finaliser votre commande, créez un compte ou connectez-vous. Vos coordonnées seront pré-remplies.</p>
        <div className="ws-co-authwall">
          <button className="ws-cta ws-cta--block" onClick={onLoginNow}>Se connecter</button>
          <button className="ws-btn-ghost" onClick={onLoginNow}>Créer un compte</button>
        </div>
        <p className="ws-co-step__hint">Démo : marie@acme.be · jules@indep.be — mdp <strong>demo</strong></p>
      </div>
    );
  }
  return (
    <div className="ws-co-step">
      <h3 className="ws-co-step__title">Vos coordonnées</h3>
      <p className="ws-co-step__lede">Indiquez comment nous joindre, puis connectez-vous pour continuer.</p>
      <div className="ws-form">
        <div className="ws-form__row2">
          <label className="ws-field"><span>Prénom</span><input value={contact.firstName} onChange={(e) => setContact((c) => ({ ...c, firstName: e.target.value }))} autoComplete="given-name"/></label>
          <label className="ws-field"><span>Nom</span><input value={contact.lastName} onChange={(e) => setContact((c) => ({ ...c, lastName: e.target.value }))} autoComplete="family-name"/></label>
        </div>
        <label className="ws-field"><span>Email</span><input type="email" value={contact.email} onChange={(e) => setContact((c) => ({ ...c, email: e.target.value }))} autoComplete="email"/></label>
        <label className="ws-field"><span>Téléphone</span><input value={contact.phone} onChange={(e) => setContact((c) => ({ ...c, phone: e.target.value }))} autoComplete="tel"/></label>
      </div>
    </div>
  );
}

function CheckoutStep2({ mode, shop, office, tour, slot, setSlot }) {
  const [slots, setSlots] = React.useState([]);
  const [dateLabel, setDateLabel] = React.useState('');
  React.useEffect(() => {
    let alive = true;
    (async () => {
      const today = new Date(); today.setHours(0,0,0,0);
      setDateLabel(today.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' }));
      if (!window.WSCalendar) { setSlots([]); return; }
      const list = await window.WSCalendar.listSlots({
        shopId: shop?.id, mode, date: window.WSCalendar.isoOf(today),
      });
      if (alive) setSlots((list || []).map((s) => s.label || s));
    })();
    return () => { alive = false; };
  }, [mode, shop?.id]);
  return (
    <div className="ws-co-step">
      <h3 className="ws-co-step__title">{mode === 'delivery' ? 'Créneau de livraison' : 'Créneau de collecte'}</h3>
      <p className="ws-co-step__lede">
        {mode === 'delivery'
          ? <>Tournée <strong>{tour?.name || '—'}</strong> · Livraison à <strong>{office?.name}</strong>, {office?.address || ''}.</>
          : <>À retirer chez <strong>{shop.name}</strong>, {shop.address}.</>
        }
      </p>
      <div className="ws-co-day">
        <Pict d={ICONS.cal} s={13}/> <span>{dateLabel}</span>
      </div>
      <div className="ws-slots">
        {slots.map((s) => (
          <button key={s} className={`ws-slot${slot === s ? ' is-active' : ''}`} onClick={() => setSlot(s)}>
            {s}
          </button>
        ))}
      </div>
      {mode === 'delivery' && <p className="ws-co-step__hint">Livraison incluse pour les bureaux desservis par votre tournée.</p>}
    </div>
  );
}

function CheckoutStep3({ basket, subtotal, promo, total, payment, setPayment, isOffice, invoice, setInvoice, vat, setVat,
                         shopId, mode, voucherInput, setVoucherInput, voucherApplied, setVoucherApplied, voucherDiscount }) {
  const [voucherErr, setVoucherErr] = useState(null);
  const [voucherLoading, setVoucherLoading] = useState(false);
  const paymentMethods = usePaymentMethods(shopId, mode);

  // Re-validate whenever subtotal changes (e.g. minOrder boundary)
  useEffect(() => {
    if (voucherApplied && voucherApplied.ok) {
      const code = voucherApplied.voucher.code;
      const validate = window.WSVouchers
        ? () => window.WSVouchers.redeem({ code, shopId, subtotal, basket })
        : () => Promise.resolve(validateVoucher(code, { subtotal, shopId }));
      validate().then((r) => {
        if (!r.ok) { setVoucherApplied(null); setVoucherErr(r.message); }
        else setVoucherApplied(r);
      }).catch(() => {});
    }
  }, [subtotal, shopId]);

  async function applyVoucher() {
    setVoucherErr(null);
    const code = (voucherInput || '').trim();
    if (!code) return;
    setVoucherLoading(true);
    try {
      const r = window.WSVouchers
        ? await window.WSVouchers.redeem({ code, shopId, subtotal, basket })
        : validateVoucher(code, { subtotal, shopId });
      if (r.ok) { setVoucherApplied(r); setVoucherErr(null); }
      else { setVoucherApplied(null); setVoucherErr(r.message || 'Code invalide'); }
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
      <h3 className="ws-co-step__title">Paiement</h3>
      <p className="ws-co-step__lede">Choisissez votre méthode de paiement et confirmez.</p>

      <div className="ws-co-voucher">
        <div className="ws-co-voucher__head">
          <span className="ws-co-voucher__lbl">Code promo</span>
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
            <button type="button" className="ws-co-voucher__remove" onClick={removeVoucher}>Retirer</button>
          </div>
        ) : (
          <div className="ws-co-voucher__row">
            <input
              type="text"
              className="ws-co-voucher__input"
              placeholder="Saisir un code (ex. BIENVENUE10)"
              value={voucherInput}
              onChange={(e) => { setVoucherInput(e.target.value.toUpperCase()); setVoucherErr(null); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyVoucher(); } }}
              autoComplete="off"
              spellCheck={false}
            />
            <button type="button" className="ws-co-voucher__apply" onClick={applyVoucher} disabled={!voucherInput.trim() || voucherLoading}>{voucherLoading ? '…' : 'Appliquer'}</button>
          </div>
        )}
        {voucherErr && <div className="ws-co-voucher__err">{voucherErr}</div>}
      </div>

      <div className="ws-co-summary">
        <div className="ws-co-summary__h">Récapitulatif</div>
        <ul className="ws-co-summary__list">
          {basket.map((l) => (
            <li key={l.line}>
              <span className="ws-co-summary__qty">×{l.qty}</span>
              <span className="ws-co-summary__name">{l.name}</span>
              <span className="ws-co-summary__amt">€{(l.price * l.qty).toFixed(2)}</span>
            </li>
          ))}
        </ul>
        <div className="ws-co-summary__row"><span>Sous-total</span><span>€{subtotal.toFixed(2)}</span></div>
        {promo > 0 && <div className="ws-co-summary__row ws-co-summary__row--promo"><span>Réduction Webshop · 5%</span><span>−€{promo.toFixed(2)}</span></div>}
        {voucherDiscount > 0 && voucherApplied && (
          <div className="ws-co-summary__row ws-co-summary__row--promo">
            <span>Code <strong>{voucherApplied.voucher.code}</strong></span>
            <span>−€{voucherDiscount.toFixed(2)}</span>
          </div>
        )}
        <div className="ws-co-summary__row ws-co-summary__row--total"><span>Total TTC</span><span>€{total.toFixed(2)}</span></div>
      </div>

      <div className="ws-pay">
        {paymentMethods.map((p) => (
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

      {isOffice && (
        <div className="ws-co-invoice">
          <label className="ws-co-invoice__check">
            <input type="checkbox" checked={invoice} onChange={(e) => setInvoice(e.target.checked)}/>
            <span>Demander une facture nominative</span>
          </label>
          {invoice && (
            <label className="ws-field"><span>N° de TVA</span><input value={vat} onChange={(e) => setVat(e.target.value)} placeholder="BE0123.456.789"/></label>
          )}
        </div>
      )}

      <p className="ws-co-step__hint">Paiement sécurisé · L'Atelier By ne stocke aucune donnée bancaire.</p>
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
function ShopSwitcher({ open, currentId, onPick, onClose, shops }) {
  const swPanelRef = useSwipeDownToClose(onClose);
  if (!open) return null;
  const list = shops || [];
  return (
    <div className="ws-modal ws-modal--switcher" onClick={onClose}>
      <div ref={swPanelRef} className="ws-modal__panel ws-modal__panel--switcher" onClick={(e) => e.stopPropagation()}>
        <span className="ws-modal__handle" aria-hidden="true"/>
        <button className="ws-modal__close" onClick={onClose}><Pict d={ICONS.close} s={14}/></button>
        <p className="ws-modal__eyebrow">Choisissez votre boutique</p>
        <h2 className="ws-modal__title">Trouvez <em>votre</em> Atelier.</h2>
        <p className="ws-modal__lede">Chaque boutique a ses produits, ses horaires et ses créneaux. Sélectionnez celle où vous souhaitez retirer votre commande.</p>
        <div className="ws-modal__grid">
          {list.map((s) => (
            <button key={s.id} className={`ws-shopcard${s.id === currentId ? ' is-current' : ''}`} onClick={() => { onPick(s.id); onClose(); }} style={{ '--accent': s.accent }}>
              <span className="ws-shopcard__bar"/>
              <div className="ws-shopcard__head">
                <span className="ws-shopcard__city">{s.city}</span>
                {s.id === currentId && <span className="ws-shopcard__active"><Pict d={ICONS.check} s={11}/> Actuelle</span>}
              </div>
              <div className="ws-shopcard__name">{s.name}</div>
              <div className="ws-shopcard__addr">{s.address}</div>
              <div className="ws-shopcard__svcs">
                <span>Click & Collect</span>
                <span>·</span>
                <span>Livraison bureau</span>
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
  // Deep-link: read URL params once at mount so admin direct links
  // (?shop=&mode=&voucher=&category=) preload the storefront state.
  const _deep = typeof parseDeepLink === 'function' ? parseDeepLink() : {};
  const [shopId, setShopId] = useState(_deep.shopId || 'chatelain');
  React.useEffect(() => {
    if (window.WSBrand && typeof window.WSBrand.apply === 'function') {
      window.WSBrand.apply(shopId);
    }
  }, [shopId]);
  const [mode, setMode] = useState(_deep.mode === 'delivery' ? 'collect' : (_deep.mode || 'collect')); // gate delivery via existing flow
  const [cat, setCat] = useState(_deep.cat || 'all');
  const [subCat, setSubCat] = useState(null);
  const [basket, setBasket] = useState([]);
  const [cartDrawerOpen, setCartDrawerOpen] = useState(false);
  // Voucher state — may be pre-filled by deep link
  const [voucherInput, setVoucherInput] = useState(_deep.voucher || '');
  const [voucherApplied, setVoucherApplied] = useState(null);
  const [deepLinkBanner, setDeepLinkBanner] = useState(
    _deep.shopId || _deep.voucher || _deep.cat ? _deep : null
  );
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
  // Delivery cutoff time loaded from WSCalendar per shop — defaults to 11:00 until API responds.
  const [deliveryCutoffMinutes, setDeliveryCutoffMinutes] = React.useState(11 * 60);
  React.useEffect(() => {
    if (!window.WSCalendar) return;
    window.WSCalendar.getCutoff({ shopId, mode: 'delivery' })
      .then((r) => { if (r && typeof r.hour === 'number') setDeliveryCutoffMinutes(r.hour * 60 + (r.minutes || 0)); })
      .catch(() => {});
  }, [shopId]);
  const deliveryCutoffPassed = isToday(date) && nowHour >= deliveryCutoffMinutes;
  const [user, setUser] = useState(null);
  const [authOpen, setAuthOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [allergensOpen, setAllergensOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [orderToast, setOrderToast] = useState(null);

  // Shops directory — sourced from API stub (or remote endpoint when wired).
  const [shops, setShops] = useState(() => (window.WSShops ? window.WSShops.getCacheSync() : Object.values(W_SHOPS || {})));
  React.useEffect(() => {
    let alive = true;
    if (window.WSShops) {
      window.WSShops.list().then((s) => { if (alive) setShops(s); }).catch(() => {});
    }
    return () => { alive = false; };
  }, []);

  // Categories — loaded from API, seed used as instant fallback.
  const [categories, setCategories] = React.useState((window._CATALOG_SEED && window._CATALOG_SEED.categories) || []);
  React.useEffect(() => {
    let alive = true;
    if (window.WSCatalog && typeof window.WSCatalog.listCategories === 'function') {
      window.WSCatalog.listCategories({ shopId })
        .then((c) => { if (alive && c && c.length) setCategories(c); })
        .catch(() => {});
    }
    return () => { alive = false; };
  }, [shopId]);

  // Assortments — loaded from API, seed used as instant fallback.
  const [assortments, setAssortments] = React.useState((window._CATALOG_SEED && window._CATALOG_SEED.assortments) || []);
  React.useEffect(() => {
    let alive = true;
    if (window.WSCatalog) {
      window.WSCatalog.listAssortments({ shopId })
        .then((a) => { if (alive) setAssortments(a || []); })
        .catch(() => {});
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
      const office = window.WSOffices
        ? await window.WSOffices.get(user.officeId).catch(() => null)
        : getOffice(user.officeId);
      if (!alive || !office) { setUserOffice(null); setUserTour(null); return; }
      setUserOffice(office);
      const tour = window.WSTours
        ? await window.WSTours.get(office.tourId).catch(() => null)
        : getTour(office.tourId);
      if (alive) setUserTour(tour || null);
    }
    load();
    return () => { alive = false; };
  }, [user?.officeId]);

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
    setTimeout(() => setOrderToast(null), 4500);
  }

  const shop = shops.find((s) => s.id === shopId) || shops[0] || null;
  const isAssortment = typeof cat === 'string' && cat.startsWith('season:');
  const assortmentId = isAssortment ? cat.slice('season:'.length) : null;
  const assortment = assortmentId ? assortments.find((a) => a.id === assortmentId) : null;
  const seedProducts = (window._CATALOG_SEED && window._CATALOG_SEED.products) || [];
  const [allProducts, setAllProducts] = React.useState(seedProducts);
  React.useEffect(() => {
    let alive = true;
    (async () => {
      if (!window.WSCatalog) return;
      const list = await window.WSCatalog.listProducts({ shopId });
      if (alive && list && list.length) setAllProducts(list);
    })();
    return () => { alive = false; };
  }, [shopId]);
  const products = useMemo(() => {
    const src = allProducts;
    if (cat === 'all') return src;
    if (isAssortment) {
      return src.slice(0, 8);
    }
    return src.filter((p) => p.cat === cat);
  }, [cat, isAssortment, allProducts]);

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
  const userCanDeliver = !!(userOffice && userOffice.status === 'validated' && userTour);

  function handleAdd(p, portion) {
    setBasket((b) => [...b, { line: Date.now(), productId: p.id, name: p.name + (portion === 'demi' ? ' — 1/2' : portion === 'quart' ? ' — 1/4' : ''), qty: 1, price: p.price, options: [], portion: portion || null, cat: p.cat, crossPortion: !!p.crossPortion }]);
  }

  // Configurable-product detail
  function handleRemove(lineId) {
    setBasket((b) => b.filter((l) => l.line !== lineId));
  }

  const [detailProduct, setDetailProduct] = useState(null);
  function handleAddConfigured(line) {
    setBasket((b) => [...b, { line: Date.now(), ...line }]);
  }

  const Nav = variant === 'A' ? NavbarA : variant === 'B' ? NavbarB : NavbarC;

  // Auto-switch back to collect if delivery cutoff passes while already in delivery mode.
  React.useEffect(() => {
    if (mode === 'delivery' && deliveryCutoffPassed) {
      setMode('collect');
    }
  }, [deliveryCutoffPassed, mode]);

  // Design system rule: changing mode OR date clears the basket instantly.
  function handleMode(next) {
    if (next === mode) return;
    // Gating: Livraison only if logged in & office linked & tour set
    if (next === 'delivery' && !userCanDeliver) {
      if (!user) { setAuthOpen(true); return; }
      setAccountOpen(true);
      return;
    }
    // Gating: no same-day delivery after 10:00
    if (next === 'delivery' && deliveryCutoffPassed) return;
    setMode(next);
    setBasket([]);
  }
  function handleDate(next) {
    const a = date instanceof Date ? date.toDateString() : String(date);
    const b = next instanceof Date ? next.toDateString() : String(next);
    if (a === b) return;
    setDate(next);
    setBasket([]);
    // If switching to today in delivery mode and cutoff has passed, revert to collect
    if (mode === 'delivery' && isToday(next) && nowHour >= deliveryCutoffMinutes) {
      setMode('collect');
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
      setShopId(u.preferredShopId);
    }
    // If freshly logged-in user already has delivery enabled & was on collect, leave mode alone (don't surprise the user).
  }
  function handleLogout() {
    setUser(null);
    if (mode === 'delivery') { setMode('collect'); setBasket([]); }
  }

  return (
    <div className={`ws ws--${variant}`} data-mode={mode}>
      <Nav shop={shop} mode={mode} onMode={handleMode} onSwitchShop={() => setSwitcherOpen(true)} cartCount={cartCount} date={date} onDate={handleDate} user={user} onAccount={handleAccount} onAllergens={() => setAllergensOpen(true)} deliveryCutoffPassed={deliveryCutoffPassed}/>

      <div className="ws-body">
        <main className="ws-main" ref={mainScrollRef} onScroll={updateScrollState}>
          {variant === 'C' && (
            <div className="ws-hero" style={{ '--shop-accent': shop.accent }}>
              <div className="ws-hero__copy">
                <span className="ws-hero__eyebrow">Campagne · Printemps 2026</span>
                <h1 className="ws-hero__slogan">On prend.<br/>On divise.<br/><em style={{ color: 'var(--color-primary)' }}>On goûte.</em></h1>
                <p className="ws-hero__lede">4 parts achetées · 1 offerte. Disponible en boutique pour la collecte aujourd'hui.</p>
              </div>
              <div className="ws-hero__chip" style={{ background: 'var(--color-primary)' }}>
                <span>{shop.name}</span>
                <span className="ws-hero__chip-sub">{shop.city}</span>
              </div>
            </div>
          )}

          {/* page head removed */}

          <CategoryRow active={cat} sub={subCat} onSelect={(c) => { setCat(c); setSubCat(null); }} onSelectSub={setSubCat} accent={mode === 'delivery' ? '#c17a2a' : 'var(--color-primary)'} tint={mode === 'delivery' ? 'invert(45%) sepia(60%) saturate(600%) hue-rotate(5deg)' : 'invert(15%) sepia(85%) saturate(2400%) hue-rotate(335deg)'} categories={categories} assortments={assortments}/>

          <div className="ws-grid">
            {products.map((p) => {
              const bqty = basket.filter((l) => l.productId === p.id).reduce((t, l) => t + l.qty, 0);
              const stock = productStock[p.id] || null;
              return <ProductCard key={p.id} p={p} onAdd={handleAdd} onOpen={setDetailProduct} mode={mode} basketQty={bqty} stock={stock}/>;
            })}
          </div>
        </main>

        <button
          type="button"
          className="ws-scrollcue ws-scrollcue--up"
          aria-label="Scroll up"
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
          aria-label="Scroll down"
          onClick={() => {
            const main = document.querySelector('.ws-main');
            if (!main) return;
            const atBottom = main.scrollTop + main.clientHeight >= main.scrollHeight - 24;
            main.scrollBy({ top: atBottom ? -main.scrollHeight : main.clientHeight * 0.85, behavior: 'smooth' });
          }}
        >
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>

        <Basket shop={shop} mode={mode} basket={basket} onCheckout={handleCheckout} onRemove={handleRemove}/>
      </div>

      {/* Mobile bottom tab bar — 2 buttons, 50/50 split */}
      <nav className="ws-tabbar" aria-label="Navigation">
        <button className="ws-tabbar__btn ws-tabbar__btn--cart" onClick={() => setCartDrawerOpen(true)} aria-label="Panier">
          <span className="ws-tabbar__cart-wrap">
            <Pict d={ICONS.bag} s={20}/>
            {cartCount > 0 && <span className="ws-tabbar__badge">{cartCount}</span>}
          </span>
          <span className="ws-tabbar__label">Panier</span>
        </button>
        <button className="ws-tabbar__btn ws-tabbar__btn--account" onClick={handleAccount} aria-label={user ? 'Profil' : 'Connexion'}>
          <Pict d={ICONS.user} s={20}/>
          <span className="ws-tabbar__label">{user ? 'Profil' : 'Connexion'}</span>
        </button>
      </nav>

      {/* Mobile cart drop-up drawer */}
      {cartDrawerOpen && (
        <div className="ws-drawer is-open" onClick={(e) => { if (e.target === e.currentTarget) setCartDrawerOpen(false); }}>
          <div className="ws-drawer__panel">
            <button className="ws-drawer__close" onClick={() => setCartDrawerOpen(false)} aria-label="Fermer">×</button>
            <div className="ws-drawer__handle" aria-hidden="true"/>
            <Basket shop={shop} mode={mode} basket={basket} onCheckout={() => { setCartDrawerOpen(false); handleCheckout(); }} onRemove={handleRemove}/>
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
      <LoginModal open={authOpen} onClose={() => setAuthOpen(false)} onLogin={handleLogin} onRegister={handleLogin}/>
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
      <ProductDetail open={!!detailProduct} product={detailProduct} mode={mode} onClose={() => setDetailProduct(null)} onAdd={handleAddConfigured} stock={detailProduct ? (productStock[detailProduct.id] || null) : null}/>
      {window.AllergensModal && <window.AllergensModal open={allergensOpen} onClose={() => setAllergensOpen(false)}/>}
      <CheckoutWizard open={checkoutOpen} onClose={() => setCheckoutOpen(false)} shop={shop} mode={mode} basket={basket} user={user} onLogin={() => setAuthOpen(true)} onPlaced={handlePlaced}
        voucherInput={voucherInput} setVoucherInput={setVoucherInput}
        voucherApplied={voucherApplied} setVoucherApplied={setVoucherApplied}
        office={userOffice} tour={userTour}
      />
      {deepLinkBanner && (
        <div className="ws-deeplink" role="status">
          <div className="ws-deeplink__copy">
            <strong>Lien direct reçu</strong>
            <span>
              {deepLinkBanner.shopId && shops.find((s) => s.id === deepLinkBanner.shopId) && (
                <>Boutique · <em>{shops.find((s) => s.id === deepLinkBanner.shopId).name}</em></>
              )}
              {deepLinkBanner.cat && deepLinkBanner.cat !== 'all' && (
                <> · Catégorie <em>{deepLinkBanner.cat}</em></>
              )}
              {deepLinkBanner.voucher && (
                <> · Code <em>{deepLinkBanner.voucher}</em> prêt à appliquer</>
              )}
            </span>
          </div>
          <button className="ws-deeplink__x" onClick={() => setDeepLinkBanner(null)} aria-label="Fermer">×</button>
        </div>
      )}
      {prefNudge && (
        <div className="ws-pref-nudge" role="dialog" aria-label="Mettre à jour la boutique préférée">
          <div className="ws-pref-nudge__body">
            <div className="ws-pref-nudge__title">Faire de <em>{prefNudge.shopName}</em> votre boutique préférée ?</div>
            <div className="ws-pref-nudge__sub">Cela définira <em>{prefNudge.shopName}</em> par défaut à votre prochaine connexion.</div>
          </div>
          <div className="ws-pref-nudge__btns">
            <button type="button" className="ws-pref-nudge__no" onClick={() => setPrefNudge(null)}>Plus tard</button>
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
            }}>Définir comme préférée</button>
          </div>
        </div>
      )}
      {orderToast && (
        <div className="ws-toast" role="status">
          <span className="ws-toast__check"><Pict d={<path d="M5 12l4 4 10-10"/>} s={14}/></span>
          <div>
            <div className="ws-toast__title">Commande confirmée</div>
            <div className="ws-toast__sub">Créneau {orderToast.slot} · {orderToast.payment === 'visa' ? 'Carte' : orderToast.payment === 'apple' ? 'Apple Pay' : 'Bancontact'} · €{orderToast.total.toFixed(2)}</div>
          </div>
        </div>
      )}
    </div>
  );
}

// =========================================================================
ReactDOM.createRoot(document.getElementById('root')).render(<ShopFrame variant="A"/>);

