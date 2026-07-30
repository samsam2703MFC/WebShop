# À faire, dans l'ordre — avec le minutage

Une seule liste chronologique. Colonne **Qui** : 👤 toi · 🤖 moi (développement).
Le cumul ne compte que **ton** temps.

---

## Aujourd'hui — ce qui ne dépend de rien

| # | Quoi | Qui | min | Cumul |
|---|---|---|---|---|
| 1 | **Décider** : écran Comptes tablette sous Réglages ou entrée de menu séparée ? | 👤 | 1 | 0 h 01 |
| 2 | Préparation : jeton admin, compte client avec mot de passe, relevé de `ws_shop_payment_options`, 2 navigateurs (dont un privé) | 👤 | 20 | 0 h 21 |
| 3 | **Séance ARGENT** — `stripe` (invité / connecté / société), `shop`, bon de réduction, abandon Stripe, échec Stripe, aucun moyen configuré, réservation de stock, bon nominatif | 👤 | 95 | 1 h 56 |
| 4 | **Séance BUREAU** — rattachement → livraison bureau → paiement différé → `deferred` proposé seulement si autorisé | 👤 | 55 | 2 h 51 |
| 5 | **Séance ANNEXES** — fidélité, Mes achats, VIES, e-mail de commande reçu | 👤 | 25 | 3 h 16 |
| — | *(en parallèle)* Écrans **Profils tablette** + **Comptes tablette** | 🤖 | ~120 | — |

> Si des défauts sortent des séances 3 à 5, compter **+50 %** : chaque anomalie
> coûte un aller-retour constat → correctif → déploiement (~3 min) → nouveau test.

---

## Ensuite — dès que les deux écrans sont livrés

| # | Quoi | Qui | min | Cumul |
|---|---|---|---|---|
| 6 | **Séance COMPTES** — compte client + connexion, handoff PWA, comptes tablette PIN (7 étapes), retrait d'une section → effet immédiat | 👤 | 60 | 4 h 16 |

---

## Données — le vrai gros morceau

Ces durées dépendent de **ta** vitesse de saisie dans l'ERP, pas du code.

| # | Quoi | Qui | min | Cumul |
|---|---|---|---|---|
| 7 | **Prix ERP des 188 obligatoires sans prix** ⚠️ bloquant commercial | 👤 | ~190 | 7 h 26 |
| 8 | Traiter les prix à **0** (56 à 108 par boutique) — 0 = « non fixé », pas gratuit | 👤 | ~120 | 9 h 26 |
| 9 | **Allergènes** des catégories à risque (pâtisserie, boulangerie, traiteur, quiches) ⚠️ réglementaire | 👤 | ~120 | 11 h 26 |
| 10 | Décisions rapides : 68 produits retirés (volontaire ?), doublon « Brookie », prospect non rattaché | 👤 | 15 | 11 h 41 |
| 11 | *(optionnel)* Prix de référence marque à 1,00 € — aucun impact client | 👤 | ~60 | — |
| 12 | *(optionnel)* Paramètres de coût `cost_*` — débloque rentabilité et marge | 👤 | 15 | — |
| 13 | *(optionnel)* Contours exacts des codes postaux (secteurs Statbel) | 👤 | 30 | — |

---

## Juste avant l'ouverture — dans cet ordre

| # | Quoi | Qui | min | Cumul |
|---|---|---|---|---|
| 14 | **Sauvegarde complète datée**, conservée hors serveur | 👤 | 15 | 11 h 56 |
| 15 | Vérifier les migrations appliquées (≥ 0050) | 👤 | 5 | 12 h 01 |
| 16 | **Purger les données de test** — commandes d'essai, comptes `bb@`/`cs@`, bons de test. Rien qui soit référencé par une facture | 👤 | 20 | 12 h 21 |
| 17 | 🔐 **Rotation du jeton admin** — puis rouvrir chaque back-office avec `?token=<nouveau>` | 👤 | 10 | 12 h 31 |
| 18 | Vérifier une dernière fois : VIES joignable, SMTP, Stripe | 👤 | 10 | 12 h 41 |

---

## Après l'ouverture

| # | Quoi | Qui | min |
|---|---|---|---|
| 19 | **Vue chauffeur** + endpoints de validation d'arrêt (débloque le dispatch tablette) | 🤖 | ~1 j |
| 20 | Test du dispatch chauffeur | 👤 | 30 |
| 21 | Surveiller les premiers jours : anomalie signalée → faire recharger la page (service workers résiduels) | 👤 | — |

---

## Récapitulatif

| Bloc | Ton temps |
|---|---|
| Tests (étapes 2 → 6) | **4 h 15** |
| Données obligatoires (7 → 10) | **~7 h 25** |
| Mise en ligne (14 → 18) | **1 h 00** |
| **Total avant ouverture** | **~12 h 40** |
| Optionnel (11 → 13) | +1 h 45 |

**Deux jours de travail**, dont plus de la moitié en **saisie de données** — pas
en test. Si les prix ERP peuvent être saisis par quelqu'un d'autre en parallèle,
tu passes à **une journée**.

**Chemin le plus court vers une ouverture** : étapes 1 → 5 aujourd'hui (3 h 16),
la saisie des prix en parallèle par une autre personne, puis 6 et 14 → 18.
