# Panier caisse (« scan & go ») — contrat pour la caisse

Le client compose son panier dans le webshop **en boutique**, touche
**« Je suis en boutique · payer à la caisse »** dans le panier, et montre le QR
au comptoir. La caisse scanne, **le panier tombe dans la caisse**, et la caisse
passe **directement en auto-paiement** : le montant part au terminal (ou au
monnayeur automatique), le client règle seul, personne ne retape les articles.

Le webshop **n'encaisse rien** et n'édite aucun ticket : la caisse certifiée fait
foi sur le montant, la TVA et le ticket fiscal. Elle renvoie au webshop le
montant encaissé et le numéro du ticket.

Code serveur : `php-api/instore.php` · tables : migration `0134_instore_basket.sql`
· écran client : `InstoreBasketModal` (webshop-full-bundle.jsx) · caisse de test :
`public/caisse-test.html`.

---

## 1. Le parcours

```
Téléphone (webshop)                       Serveur                        Caisse
───────────────────                       ───────                        ──────
Panier (Retrait, aujourd'hui)
« payer à la caisse »  ── POST /instore/baskets ──▶  fige les lignes aux prix
                                                    de la boutique, code PC-XXXXXX
QR « PC-7K3Q9X » affiché ◀── code + jeton ─────────
                                                                     scan du QR
                                                 ◀── GET  /pos/instore/PC-7K3Q9X
                                                 ── lignes, total, client ──▶
                                                 ◀── POST /pos/instore/…/claim
statut « à la caisse »  ◀── (suivi toutes les 3 s)      status = at_till    AUTO-PAIEMENT
                                                                     (terminal)
                                                 ◀── POST /pos/instore/…/paid
« Payé, merci ! »       ◀──────────────────────────     status = paid
panier du téléphone vidé                                ticket enregistré
```

Statuts : `open` → `at_till` → `paid`. Sorties : `cancelled` (le client annule ou
ferme l'écran avant le scan), `expired` (pas scanné à temps — 120 min par défaut,
`ws_param.instore_basket_ttl_min`).

## 2. Authentification de la caisse

En-tête **`X-Device-Token`** : le jeton d'appareil de la boutique
(`ws_shop_device_token`, migration 0052 — le même que la tablette Kitchen, généré
par le franchisé dans son back-office). Il limite la caisse à **sa** boutique : un
panier préparé pour une autre boutique est refusé (409).

Pour les essais seulement : `X-Admin-Token` + `?shopId=` (ou `shopId` dans le corps).

## 3. Endpoints caisse

Le code peut être envoyé tel que lu (`PC-7K3Q9X`) ou tapé sans tiret ni casse
(`pc7k3q9x`) : le serveur le normalise. Le QR contient **uniquement le code**.

### `GET /pos/instore/{code}` — lire le panier

```json
{
  "code": "PC-7K3Q9X", "status": "open", "shopId": 2,
  "subtotal": 16.00, "promo": 0, "total": 16.00, "items": 3,
  "expiresAt": "2026-10-23 17:47:09", "claimedAt": null, "claimedBy": null,
  "paidAt": null, "paidAmount": null, "ticketNo": null,
  "client": { "code": "11861", "name": "Marie Dupont" },
  "pointsEstimate": 2, "loyaltyLinked": true,
  "lines": [
    { "productId": 1, "externalId": null, "name": "Baguette tradition", "portion": null,
      "qty": 2, "unitPrice": 5.00, "lineTotal": 10.00, "children": [] },
    { "productId": 14, "externalId": null, "name": "Menu midi", "portion": null,
      "qty": 1, "unitPrice": 9.50, "lineTotal": 9.50,
      "children": [ { "productId": 31, "name": "Quiche lorraine", "qty": 1, "unitPrice": 0, "lineTotal": 0 } ] }
  ]
}
```

- `productId` = `ws_products.id` ; `externalId` = `ws_products.external_id` quand la
  colonne existe (code ERP / PLU).
- `children` = composants d'un menu ou d'un bundle (lignes filles, souvent à 0 € ;
  un supplément de choix porte son prix).
- `client.code` = **code client fidélité** : la caisse l'attache au ticket comme si
  elle avait scanné la carte de fidélité. Points et tampons arrivent ensuite par le
  chemin habituel (ticket ERP → `pwa_purchases`). `client` vaut `null` si le client
  n'était pas connecté.
- `total` est une **estimation** aux prix de la boutique (promo X+Y comprise) : la
  caisse applique ses propres règles et fait foi.

### `POST /pos/instore/{code}/claim` — prendre le panier

Corps facultatif : `{ "till": "caisse 1" }`. Passe le panier en `at_till` : plus
aucune autre caisse ne peut le prendre. Idempotent pour la caisse qui l'a déjà pris.
**C'est à ce moment que la caisse passe en auto-paiement.** Le téléphone du client
affiche « Votre panier est à la caisse : réglez au terminal ».

### `POST /pos/instore/{code}/paid` — paiement accepté

```json
{ "till": "caisse 1", "amount": 15.80, "method": "card", "ticketNo": "FDM-000123", "posRef": "TX-88412" }
```

`amount` = montant réellement encaissé (absent → total du panier). `method`,
`ticketNo` (n° de ticket fiscal) et `posRef` (référence de transaction) sont
enregistrés tels quels. Accepté depuis `open` ou `at_till` ; un rejeu sur un panier
déjà payé rend la même réponse (idempotent).

### `POST /pos/instore/{code}/release` — paiement refusé / abandon

Le panier redevient `open` et scannable (le client peut réessayer ou payer autrement).

## 4. Refus

| HTTP | Cas | Message (à afficher tel quel) |
|------|-----|-------------------------------|
| 401 | jeton d'appareil absent/inconnu/révoqué | Caisse non reconnue… |
| 404 | code inconnu | Code inconnu. Vérifiez le code affiché… |
| 409 | panier d'une autre boutique | Ce panier a été préparé pour … — le client doit le refaire ici. |
| 409 | déjà pris par une autre caisse / déjà payé | … |
| 410 | annulé par le client / expiré | … |
| 501 | module coupé (`ws_param.instore_basket_enabled = '0'`) ou tables absentes | Le panier caisse n'est pas activé. |

## 5. Côté téléphone (pour mémoire)

| Méthode | Route | Rôle |
|---------|-------|------|
| `POST` | `/instore/baskets` `{shopId, basket[]}` | crée le panier ; rend `code`, `token`, lignes, total. Client connecté (Bearer) → fidélité liée. |
| `GET` | `/instore/baskets/{code}?token=` | suivi du statut (le téléphone interroge toutes les 3 s) |
| `DELETE` | `/instore/baskets/{code}?token=` | annulation (refusée si la caisse l'a déjà pris) |

Le bouton n'apparaît que si : mode **Retrait**, date **aujourd'hui**, prix affichés
(pas un bureau « prix masqués »), et `GET /config → instoreBasketEnabled = true`.

## 6. Essayer sans caisse intégrée

`/caisse-test.html` (non indexée) : saisir le jeton d'appareil de la boutique, scanner
le QR (douchette USB = clavier, ou caméra sur Chrome), puis simuler la réponse du
terminal (accepté → `paid` avec un ticket `TEST-…`, refusé → `release`). Rien n'est
encaissé ; le téléphone du client réagit comme en vrai.

## 7. Ce qu'il reste à faire chez l'éditeur de caisse

1. Lire un QR / un code `PC-…` dans le champ de saisie article (la plupart des
   douchettes l'envoient comme une frappe clavier suivie d'Entrée).
2. `GET` puis `claim`, insérer les lignes (`productId`/`externalId` → article caisse,
   `qty`, `unitPrice`), attacher `client.code` au ticket.
3. Lancer le paiement terminal sans intervention (auto-paiement).
4. `paid` (ou `release` si refus).
