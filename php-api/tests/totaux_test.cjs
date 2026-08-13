/* L'aperçu du webshop doit donner EXACTEMENT le total que le serveur facture.
 *
 * Ce test ne relit pas les deux calculs : il les FAIT TOURNER l'un contre
 * l'autre sur des centaines de paniers tirés au sort. Le calcul de référence
 * ci-dessous est la transcription littérale du bloc PHP de POST /orders
 * (php-api/index.php, sections « 1. Lignes + sous-total » à « $total = … ») ;
 * les fonctions testées sont extraites du bundle tel qu'il est livré.
 *
 * Un écart d'un centime ici, c'est un client qui lit sur sa confirmation un
 * montant que personne n'a débité.
 *
 * Usage :  node php-api/tests/totaux_test.cjs
 */
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../../webshop-full-bundle.jsx'), 'utf8');

/* Extraction des trois fonctions à tester — du JS pur, sans JSX. */
function extraire(nom) {
  const i = src.indexOf('function ' + nom + '(');
  if (i < 0) throw new Error('fonction introuvable : ' + nom);
  // Le corps commence après la parenthèse fermante des paramètres : une liste
  // déstructurée ({ basket, shop, … }) porte elle-même des accolades, et les
  // compter depuis la première tronquerait la fonction en silence.
  let par = 0, k = src.indexOf('(', i);
  for (; k < src.length; k++) {
    if (src[k] === '(') par++;
    else if (src[k] === ')' && --par === 0) break;
  }
  let prof = 0, debut = src.indexOf('{', k);
  for (let j = debut; j < src.length; j++) {
    if (src[j] === '{') prof++;
    else if (src[j] === '}' && --prof === 0) return src.slice(i, j + 1);
  }
  throw new Error('fin de fonction introuvable : ' + nom);
}
const code = ['wsArrondi', 'wsRemiseBoutique', 'wsTotaux', 'computeCrossPortionOffer']
  .map(extraire).join('\n');
const sandbox = {};
new Function('exports', code + '\nexports.wsTotaux=wsTotaux;exports.computeCrossPortionOffer=computeCrossPortionOffer;')(sandbox);

/* ── Le calcul du SERVEUR, transcrit ─────────────────────────────────────── */
function serveur({ lignes, regle, remiseType, remiseVal, bon, frais }) {
  const sousTotal = lignes.reduce((t, l) => t + l.price * l.qty, 0);

  let promo = 0;                                   // 2. promo croisée X+Y
  if (regle && regle.x > 0) {
    const units = [];
    for (const l of lignes) if (l.crossPortion) for (let k = 0; k < l.qty; k++) units.push(l.price);
    if (units.length >= regle.threshold) {
      units.sort((a, b) => a - b);
      const freeCount = Math.floor(units.length / regle.x) * regle.y;
      for (let k = 0; k < freeCount && k < units.length; k++) promo += units[k];
    }
  }

  let remise = 0;                                  // 2bis. remise boutique
  if (remiseVal > 0) {
    const base = sousTotal - promo;
    remise = remiseType === 'fixed' ? Math.min(base, remiseVal) : Math.round(base * remiseVal) / 100;
  }
  remise = Math.round(remise * 100) / 100;

  const total = Math.max(0, Math.round((sousTotal - promo - remise - bon + frais) * 100) / 100);
  return { sousTotal, promo, remise, total };
}

/* ── Tirage ──────────────────────────────────────────────────────────────── */
let graine = 20260813;
const rnd = () => (graine = (graine * 1103515245 + 12345) % 2147483648) / 2147483648;
const prix = () => Math.round((1 + rnd() * 40) * 100) / 100;

let ko = 0, n = 0;
for (let essai = 0; essai < 400; essai++) {
  const lignes = [];
  const nb = 1 + Math.floor(rnd() * 6);
  for (let i = 0; i < nb; i++)
    lignes.push({ name: 'P' + i, price: prix(), qty: 1 + Math.floor(rnd() * 4),
                  crossPortion: rnd() < 0.6, portion: 'quart', basePrice: prix() });
  const regle = rnd() < 0.75
    ? { x: 2 + Math.floor(rnd() * 4), y: 1 + Math.floor(rnd() * 2), threshold: Math.floor(rnd() * 5) }
    : null;
  const r = rnd();
  const remiseType = r < 0.4 ? 'percent' : (r < 0.6 ? 'fixed' : null);
  const remiseVal  = remiseType ? (remiseType === 'percent' ? 1 + Math.floor(rnd() * 15)
                                                            : Math.round(rnd() * 20 * 100) / 100) : 0;
  const bon   = rnd() < 0.3 ? Math.round(rnd() * 10 * 100) / 100 : 0;
  const frais = rnd() < 0.4 ? Math.round(rnd() * 8 * 100) / 100 : 0;

  const shop = { webshop_discount_type: remiseType, webshop_discount_value: remiseVal };
  const croise = sandbox.computeCrossPortionOffer(lignes, regle);
  const front  = sandbox.wsTotaux({ basket: lignes, shop, crossSavings: croise ? croise.savings : 0,
                                    voucherDiscount: bon, deliveryFee: frais });
  const back   = serveur({ lignes, regle, remiseType, remiseVal, bon, frais });

  n++;
  const ecart = Math.abs(front.total - back.total);
  if (ecart > 0.005) {
    ko++;
    if (ko <= 5) console.log(`KO  essai ${essai} : aperçu ${front.total.toFixed(2)} € ≠ facturé ${back.total.toFixed(2)} €`,
      `(offre ${front.croise.toFixed(2)}/${back.promo.toFixed(2)} · remise ${front.remise.toFixed(2)}/${back.remise.toFixed(2)})`);
  }
}

console.log(`\n${n - ko} panier(s) sur ${n} : aperçu = montant facturé.`);
if (ko) { console.log(`${ko} écart(s) — le client verrait un montant qu'il ne paiera pas.`); process.exit(1); }
