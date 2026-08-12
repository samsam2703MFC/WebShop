// tournee-data.jsx — structures de données de la liste des tournées (admin)
// La palette boutique reflète les variables CSS --shop-* de admin.css

// Go-live : aucune donnée de démonstration — la source réelle est l'API.
// Les boutiques doivent être injectées par le backend ; tant qu'aucune source
// n'est branchée, la structure reste vide et l'UI affiche un état vide.
const SHOPS = {};

// Descripteur neutre renvoyé quand un identifiant boutique n'est pas connu de
// SHOPS. Garde-fou de rendu uniquement — n'invente aucune donnée métier.
function getShop(id) {
  return SHOPS[id] || { id: id || '', name: id || '—', short: '—', city: '', color: 'var(--color-text-muted)' };
}

// Go-live : aucune tournée de démonstration — la source réelle est l'API.
const TOURNEES = [];

// Go-live : aucune répartition par boutique de démonstration — la source réelle est l'API.
const SPLIT = [];

Object.assign(window, { SHOPS, TOURNEES, SPLIT, getShop });
