# Go-live — tableau unique

Tous les points des trois documents (`TESTS_FLOWS_LOGIN.md`,
`PRE_GOLIVE_CHANTIERS.md`, `PRE_GOLIVE_DONNEES.md`) réunis en une seule liste.

**État** : 🟠 corrigé, non testé · 🔴 jamais exercé · ⛔ bloqué · 🟡 donnée à
compléter · ⚪ décision à prendre · ✅ validé

| # | Type | Sujet | État | Bloquant | Dépend de | Action |
|---|---|---|---|---|---|---|
| T1 | Test | Rattachement à un bureau (4 verrous corrigés) | 🟠 | oui | — | webshop → Mon bureau → rattacher/demander, puis BO → Lier |
| T2 | Test | Livraison au bureau de bout en bout | 🔴 | oui | T1 | commander en livraison, vérifier bureau + tournée dans le BO |
| T3 | Test | Bon nominatif `B2-KGZ46X` : montant + 2ᵉ usage refusé | 🔴 | oui | T1 | commander avec le bon, puis retenter |
| T4 | Test | Paiement différé (facturation mensuelle) | 🔴 | oui | T1 + bureau `deferred_billing_enabled` | vérifier que seul « Paiement différé » est proposé |
| T5 | Test | Réservation de stock 15 min (fonctionnalité créée) | 🟠 | oui | — | ajouter Brookie 1150002 (1 pièce) au panier, vérifier en base |
| T6 | Test | Comptes tablette PIN (3 verrous corrigés) | 🟠 | oui | A1 + A2 | 7 étapes : profil, compte, PIN, cloisonnement, révocation, brute-force |
| T7 | Test | Création de compte + connexion (PWA → webshop) | 🔴 | oui | — | « Définir votre mot de passe » sur le compte 11861, puis login email + téléphone |
| T8 | Test | Fidélité + « Mes achats » | 🔴 | non | — | onglet Fidélité, QR, liste 12 mois, demande de facture |
| T9 | Test | Société de facturation + VIES | 🔴 | non | P2 | saisir un vrai n° de TVA belge |
| T10 | Test | Handoff PWA → webshop (SSO) | 🔴 | non | — | ouvrir le webshop depuis la PWA via `?handoff=` |
| T11 | Test | Dispatch tablette chauffeur | ⛔ | non | A3 | pas testable : l'application chauffeur n'existe pas |
| T12 | Test | **Matrice complète des paiements** (13 scénarios) | 🟠 | **oui** | — | 3 méthodes × 3 profils × 2 modes + abandon, échec, bon, aucun moyen |
| T12.1 | Test | `stripe` — invité, connecté, société, collecte et livraison | 🔴 | oui | — | redirection, retour, `payment_status` |
| T12.2 | Test | `shop` (paiement en boutique) | 🔴 | oui | — | commande sans paiement, `pending` |
| T12.3 | Test | `deferred` (sur compte) | 🔴 | oui | T1 | proposé **seulement** si `deferred_billing_enabled = 1` |
| T12.4 | Test | Abandon et échec de paiement Stripe | 🔴 | **oui** | T5 | commande non confirmée, **stock libéré** |
| T12.5 | Test | Paiement avec bon de réduction | 🔴 | oui | T3 | montant envoyé à Stripe = total après remise |
| T12.6 | Test | Aucun moyen configuré | 🔴 | non | — | écran d'erreur explicite, commande impossible |
| A4 | Dév | **Confirmation du paiement Stripe** — rien ne marque une commande « payée » | ⛔ | **oui** (si carte ouverte) | — | aucun webhook ni vérification au retour : `payment_status` reste `pending` à vie |
| P8 | Process | `checkout_success` pointe vers GitHub Pages par défaut | ⚪ | oui (si carte) | A4 | vérifier `api/config.php` sur le serveur : après paiement, le client doit revenir sur la boutique |
| A1 | Dév | Écran **Profils tablette** (console marque) | ⛔ | oui (T6) | — | API prête : `bo-roles`, `bo-role`, `bo-roles-init` |
| A2 | Dév | Écran **Comptes tablette** (BO franchisé) | ⛔ | oui (T6) | — | API prête : `bo-users`, `bo-user`, `bo-roles` |
| A3 | Dév | **Vue chauffeur** + endpoints de validation d'arrêt | ⛔ | non | — | tout est à faire : arrêts, QR/PIN/signature, positions |
| P1 | Process | 🔐 **Rotation du jeton admin** | ⚪ | **oui** | — | a transité par un log GitHub Actions ; donne tous les droits |
| P2 | Process | Accès réseau sortants : VIES, SMTP, Stripe | ⚪ | oui | — | invisible en base, se teste en usage |
| P3 | Process | Vérifier les migrations appliquées (≥ 0050) | ⚪ | oui | — | `SELECT … FROM ws_schema_migrations` |
| P4 | Process | Purger les données de test (commandes, comptes, bons) | ⚪ | oui | P5 | ne rien supprimer qui soit référencé par une facture |
| P5 | Process | Sauvegarde complète datée, hors serveur | ⚪ | **oui** | — | seul filet en cas d'erreur le jour J |
| P6 | Process | Service workers résiduels chez les clients | ⚪ | non | — | prévoir un message « rechargez la page » les premiers jours |
| D1 | Donnée | **188 couples produit × boutique obligatoires sans prix ERP** | 🟡 | **oui** | — | sans prix : produit masqué et commande refusée |
| D2 | Donnée | 56 à 108 lignes de prix à **0** par boutique | 🟡 | oui | — | 0 = « non fixé », pas gratuit |
| D3 | Donnée | Prix de référence marque encore à **1,00 €** | 🟡 | non | — | aucun impact client ; la marque pilote à l'aveugle |
| D4 | Donnée | Allergènes : ~100 produits sans recette évaluée | 🟡 | oui (réglementaire) | — | priorité pâtisserie, boulangerie, traiteur, quiches |
| D5 | Donnée | Contours CP : 782/1145 à la granularité **communale** | 🟡 | non | — | secteurs Statbel + script fourni, sans redéploiement |
| D6 | Donnée | Paramètres de coût absents (`cost_*`) | 🟡 | non | — | coût et marge affichés « non calculables » |
| V1 | Décision | Boutique 2 : 68 produits retirés de l'assortiment | ⚪ | non | — | réduction volontaire du franchisé ? |
| V2 | Décision | `1150002` et `6700231` s'appellent tous deux « Brookie » | ⚪ | non | — | deux formats : vérifier que les deux ont un prix |
| V3 | Décision | 6 clients sur 10 708 ont un `office_id` | ⚪ | non | — | normal en démarrage B2B ? |
| V4 | Décision | 1 prospect non rattaché (`id_main_shop = 0`) | ⚪ | non | — | à traiter dans l'écran Prospect |
| V5 | Décision | Emplacement de l'écran Comptes tablette dans le BO | ⚪ | oui (A2) | — | sous Réglages, ou entrée de menu séparée ? |
| V6 | Décision | Maintien de stock réservé aux clients CONNECTÉS | ✅ | — | — | **tranché : on garde tel quel.** Un panier invité ne tient pas de stock ; l'arbitrage a lieu au paiement. Le maintien devient un avantage de la création de compte |

---

## Bloquants durs — l'ouverture au public en dépend

| # | Sujet | Pourquoi |
|---|---|---|
| D1 | 188 obligatoires sans prix | la marque les impose, aucun prix ne les rend vendables |
| P1 | Rotation du jeton admin | jeton exposé, tous les droits sur les deux back-offices |
| P5 | Sauvegarde | aucun retour arrière possible sans elle |
| T1–T5 | Flows qui touchent à l'argent | rattachement, livraison, bon, paiement, stock |
| T12 | Matrice des paiements | c'est l'encaissement : un moyen mal proposé ou mal libellé se voit en litige client |
| T12.4 | Abandon / échec Stripe | cas le plus rarement testé : un abandon qui laisse du stock réservé bloque la vente pour tout le monde |
| A4 | Paiement Stripe jamais confirmé | le stock est décrémenté à la commande ; sans confirmation, une carte abandonnée et une carte payée sont indiscernables |

---

## Ordre conseillé

| Rang | Quoi | Pourquoi maintenant |
|---|---|---|
| 1 | A1 + A2 | deux petits écrans qui débloquent T6, le plus gros bloc de code jamais exercé |
| 2 | T1 → T5 | les flows qui touchent à l'argent |
| 2 bis | T12 (matrice paiements) | c'est l'encaissement : à faire avec T1–T5, pas après |
| 3 | D1 puis D4 | seul blocage commercial dur, puis l'enjeu réglementaire |
| 4 | T6 → T10 | le reste des parcours |
| 5 | P5, P1, P4 | juste avant l'ouverture, dans cet ordre |
| 6 | A3 | peut suivre l'ouverture si la livraison bureau démarre progressivement |

---

## Déjà validé — ne pas refaire

| Sujet | Résultat |
|---|---|
| Checkout invité complet | ✅ commande créée, e-mail, confirmation |
| Code promo `BIENVENUE10` | ✅ aperçu = montant facturé |
| Créneaux Click & Collect | ✅ générés depuis `ws_shop_availability` |
| Portions ERP | ✅ prix réels, plus de facteurs inventés |
| Règle « obligatoire ⇒ vendable » | ✅ trigger vérifié |
| Assortiment (activation / retrait) | ✅ écriture réelle |
| Hygiène des noms produits | ✅ normalisés + garde permanente |
| Prix : source unique ERP | ✅ produit sans prix masqué et refusé |
| Prix ERP à 0 | ✅ « non fixé », plus de facturation à 0 € |
| Allergènes | ✅ 3 états, plus de faux « sans allergène » |
| Stock du jour (BO → base → API → blocage) | ✅ chaîne complète |
| Compteurs de bons + limite par client | ✅ vérifiés |
| Zones de chalandise | ✅ polygones réels |
| Écrans marque (KPIs, Bons, Catalogue, Audit, Menu, Gouvernance) | ✅ |
| Formules vides | ✅ plus proposées |
