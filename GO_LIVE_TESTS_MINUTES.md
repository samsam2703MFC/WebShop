# Plan de test chiffré — go-live

Tous les tests, avec le temps prévu pour chacun. Les durées supposent que la
**préparation** (§0) est faite et que le testeur connaît les écrans ; elles
incluent la vérification SQL mais **pas** la correction d'un éventuel défaut.

**État** : 🟠 corrigé, non testé · 🔴 jamais exercé · ⛔ bloqué

---

## 0. Préparation — 20 min

| # | Quoi | min |
|---|---|---|
| 0.1 | Jeton admin sous la main, back-offices ouverts une fois avec `?token=` | 5 |
| 0.2 | Un compte client de test **avec mot de passe** (ou faire T7 en premier) | 5 |
| 0.3 | Relever la configuration des paiements (`ws_shop_payment_options`) | 5 |
| 0.4 | Deux navigateurs prêts (dont un en privé) — nécessaires pour T5 et T6 | 5 |
| | **Total** | **20** |

---

## 1. Séance « argent » — 95 min ⚠️ priorité

Les flux qui encaissent. À faire d'un bloc : ils partagent le même panier de test.

| # | Test | État | min | Note |
|---|---|---|---|---|
| T12.1 | `stripe` — invité, collecte | 🔴 | 10 | redirection, retour, `payment_status` |
| T12.1b | `stripe` — connecté, collecte | 🔴 | 5 | + rattachement au client |
| T12.1c | `stripe` — société, collecte | 🔴 | 5 | + identité société sur la facture |
| T12.2 | `shop` — paiement en boutique | 🔴 | 10 | commande sans paiement, `pending` |
| T12.5 | Paiement avec bon de réduction | 🔴 | 10 | montant Stripe = total **après** remise |
| T12.4 | **Abandon** de paiement Stripe | 🔴 | 10 | commande non confirmée, **stock libéré** |
| T12.4b | **Échec** de paiement Stripe | 🔴 | 10 | message d'erreur, panier conservé |
| T12.6 | Aucun moyen configuré | 🔴 | 5 | écran d'erreur explicite |
| T5 | Réservation de stock 15 min | 🟠 | 15 | Brookie 1150002 (1 pièce), 2 navigateurs |
| T3 | Bon nominatif `B2-KGZ46X` | 🔴 | 15 | montant exact + 2ᵉ usage refusé |
| | **Total** | | **95** | |

---

## 2. Séance « bureau » — 55 min

Enchaînement obligatoire : chaque test crée la précondition du suivant.

| # | Test | État | min | Dépend de |
|---|---|---|---|---|
| T1 | Rattachement à un bureau | 🟠 | 10 | — |
| T2 | Livraison au bureau de bout en bout | 🔴 | 25 | T1 |
| T4 | Paiement différé | 🔴 | 10 | T1 + `deferred_billing_enabled` |
| T12.3 | `deferred` proposé **uniquement** si autorisé | 🔴 | 10 | T4 |
| | **Total** | | **55** | |

---

## 3. Séance « comptes » — 60 min

| # | Test | État | min | Dépend de |
|---|---|---|---|---|
| T7 | Création de compte + connexion (PWA → webshop) | 🔴 | 15 | — |
| T10 | Handoff PWA → webshop (SSO) | 🔴 | 5 | T7 |
| T6 | Comptes tablette PIN — 7 étapes | 🟠 | 30 | **A1 + A2** |
| T6b | Retrait d'une section côté marque → effet immédiat | 🟠 | 10 | T6 |
| | **Total** | | **60** | |

---

## 4. Séance « annexes » — 25 min

| # | Test | État | min | Note |
|---|---|---|---|---|
| T8 | Fidélité + « Mes achats » | 🔴 | 10 | onglet, QR, 12 mois, facture |
| T9 | Société de facturation + VIES | 🔴 | 10 | vrai n° de TVA belge |
| P2 | Vérifier SMTP (e-mail de commande reçu ?) | ⚪ | 5 | se constate pendant T12.1 |
| | **Total** | | **25** | |

---

## 5. Bloqué — non chiffrable

| # | Test | État | Pourquoi |
|---|---|---|---|
| T11 | Dispatch tablette chauffeur | ⛔ | l'application chauffeur n'existe pas (chantier A3) |

---

## Récapitulatif

| Séance | Contenu | min | h |
|---|---|---|---|
| 0 | Préparation | 20 | 0 h 20 |
| 1 | Argent (paiements, stock, bon) | 95 | 1 h 35 |
| 2 | Bureau (rattachement → livraison → différé) | 55 | 0 h 55 |
| 3 | Comptes (client, PWA, tablette) | 60 | 1 h 00 |
| 4 | Annexes (fidélité, VIES, e-mail) | 25 | 0 h 25 |
| | **Total testeur** | **255** | **4 h 15** |

Prévoir **une demi-journée** pour un premier passage complet. Compter **+50 %**
si des défauts apparaissent : chaque anomalie demande un aller-retour
(constat → correctif → déploiement ≈ 3 min → nouveau test).

---

## Prérequis de développement

Ces deux écrans **bloquent la séance 3** (T6). Ils sont à ma charge, pas à celle
du testeur.

| # | Chantier | Estimation |
|---|---|---|
| A1 | Écran **Profils tablette** (console marque) | ~1 h — l'API est prête |
| A2 | Écran **Comptes tablette** (BO franchisé) | ~1 h — l'API est prête |
| A3 | **Vue chauffeur** + endpoints (débloque T11) | ~1 journée — tout est à faire |

**Chemin le plus court vers un premier passage complet** : A1 + A2 (~2 h), puis
les séances 0 → 4 (4 h 15). Les séances 1, 2 et 4 sont faisables **dès
maintenant**, sans attendre A1/A2.
