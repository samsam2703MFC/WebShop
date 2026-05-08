// tournee-data.jsx — sample data for the admin tournée list
// Shop palette mirrors --shop-* CSS vars in admin.css

const SHOPS = {
  chatelain: { id: 'chatelain', name: 'Maison Châtelain',  short: 'MC',  city: 'Bruxelles', color: 'var(--shop-chatelain)' },
  sablon:    { id: 'sablon',    name: 'Atelier Sablon',     short: 'AS',  city: 'Bruxelles', color: 'var(--shop-sablon)' },
  carre:     { id: 'carre',     name: 'Le Carré',           short: 'LC',  city: 'Liège',     color: 'var(--shop-carre)' },
  zuid:      { id: 'zuid',      name: 'Zuid Bakery',        short: 'ZB',  city: 'Antwerpen', color: 'var(--shop-zuid)' },
  grognon:   { id: 'grognon',   name: 'Le Grognon',         short: 'LG',  city: 'Namur',     color: 'var(--shop-grognon)' },
  brugge:    { id: 'brugge',    name: 'Brugge Studio',      short: 'BS',  city: 'Brugge',    color: 'var(--shop-brugge)' },
};

const TOURNEES = [
  { id: 'TR-2814', name: 'Quartier Européen — Matin',     window: '07:30 → 11:00', date: 'Mer. 6 mai',
    driver: 'Émile Vandekeere',  driverInitials: 'EV', stops: 12, done: 12, kg: 38, status: 'done',     shop: 'chatelain' },
  { id: 'TR-2815', name: 'Sablon → Louise — Express',     window: '08:00 → 10:30', date: 'Mer. 6 mai',
    driver: 'Lina Boussaïd',     driverInitials: 'LB', stops: 9,  done: 7,  kg: 22, status: 'rolling',  shop: 'sablon' },
  { id: 'TR-2816', name: 'Centre-ville — Bureaux',        window: '09:00 → 12:00', date: 'Mer. 6 mai',
    driver: 'Pierre Lemmens',    driverInitials: 'PL', stops: 14, done: 5,  kg: 41, status: 'rolling',  shop: 'carre' },
  { id: 'TR-2817', name: 'Zuid Office — Tour A & B',       window: '10:00 → 13:00', date: 'Mer. 6 mai',
    driver: 'Jonas De Vos',      driverInitials: 'JD', stops: 8,  done: 0,  kg: 17, status: 'confirmed',shop: 'zuid' },
  { id: 'TR-2818', name: 'Citadelle — Mid-day',           window: '11:00 → 14:00', date: 'Mer. 6 mai',
    driver: 'Camille Henrard',   driverInitials: 'CH', stops: 6,  done: 0,  kg: 14, status: 'confirmed',shop: 'grognon' },
  { id: 'TR-2819', name: 'Bailli + Châtelain — Office',   window: '11:30 → 14:30', date: 'Mer. 6 mai',
    driver: 'Inès Marchal',      driverInitials: 'IM', stops: 11, done: 0,  kg: 28, status: 'planned',  shop: 'chatelain' },
  { id: 'TR-2820', name: 'Markt → Stations — Brugge',     window: '13:00 → 16:00', date: 'Mer. 6 mai',
    driver: 'Driver — à assigner', driverInitials: '?', stops: 7,  done: 0,  kg: 19, status: 'late',     shop: 'brugge' },
  { id: 'TR-2821', name: 'Grand-Place — Apéro Run',       window: '15:30 → 18:30', date: 'Mer. 6 mai',
    driver: 'Mathéo Ruelle',     driverInitials: 'MR', stops: 5,  done: 0,  kg: 11, status: 'planned',  shop: 'sablon' },
];

const SPLIT = [
  { shop: 'chatelain', tournees: 2, stops: 23 },
  { shop: 'sablon',    tournees: 2, stops: 14 },
  { shop: 'carre',     tournees: 1, stops: 14 },
  { shop: 'zuid',      tournees: 1, stops: 8 },
  { shop: 'grognon',   tournees: 1, stops: 6 },
  { shop: 'brugge',    tournees: 1, stops: 7 },
];

Object.assign(window, { SHOPS, TOURNEES, SPLIT });
