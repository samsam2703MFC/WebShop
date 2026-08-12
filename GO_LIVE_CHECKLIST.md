# Checklist détaillée — chaque sous-test, son attendu, son minutage

À cocher au fur et à mesure. Chaque ligne est **un geste et une vérification**.
Si une ligne échoue : noter le message affiché (ils sont tous explicites
maintenant) et passer à la suivante — on corrige par lot.

**Environnement**
- Webshop `http://185.180.206.46/webshop/`
- BO franchisé `http://185.180.206.46/webshop/backoffice_franchisee/?shop=2`
- Console marque `http://185.180.206.46/webshop/backoffice_franchisor/`
- Base `atelierby_db` — **toujours préfixer** (une base `scheme_cmp` existe aussi)

---

# 0 · Préparation — 20 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 0.1 | Ouvrir le BO franchisé avec `?shop=2&token=<jeton>` | le jeton disparaît de l'URL et reste mémorisé | 3 |
| ☐ | 0.2 | Ouvrir la console marque | KPIs affichés, aucun bandeau rouge | 2 |
| ☐ | 0.3 | Relever la configuration des paiements (SQL ci-dessous) | noter les méthodes par profil | 5 |
| ☐ | 0.4 | Se connecter au webshop avec un compte ayant un mot de passe — sinon faire **3.1** d'abord | session ouverte | 5 |
| ☐ | 0.5 | Ouvrir un second navigateur en navigation privée | prêt pour les tests à deux clients | 5 |

```sql
SELECT shop_id, profile_type, method, active
  FROM atelierby_db.ws_shop_payment_options WHERE shop_id = 2
 ORDER BY profile_type, method;
-- vide = défaut : stripe + shop (et stripe + deferred en société)
```

---

# 1 · Séance ARGENT — 95 min

## 1.A · Paiement par carte (`stripe`) — 20 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.A1 | **Invité**, Click & Collect, 2 produits → Payer | redirection vers Stripe | 4 |
| ☐ | 1.A2 | Payer sur Stripe | retour sur la confirmation, montant identique | 3 |
| ☐ | 1.A3 | Vérifier le libellé dans la confirmation | « Carte / Bancontact (en ligne) » — **pas** « Bancontact » seul | 1 |
| ☐ | 1.A4 | SQL : `payment_method`, `payment_status` | `stripe`, statut payé | 2 |
| ☐ | 1.A5 | **Connecté**, même parcours | + `customer_id` renseigné | 5 |
| ☐ | 1.A6 | **Société**, même parcours | + identité société sur la facture | 5 |

## 1.B · Paiement en boutique (`shop`) — 10 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.B1 | Choisir « Paiement en boutique » → Payer | **aucune** redirection Stripe | 3 |
| ☐ | 1.B2 | Confirmation | libellé « Paiement en boutique » | 2 |
| ☐ | 1.B3 | SQL | `payment_method = shop`, `payment_status = pending` | 2 |
| ☐ | 1.B4 | BO → Commandes du jour | la commande apparaît | 3 |

## 1.C · Bon de réduction + paiement — 10 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.C1 | Appliquer `BIENVENUE10` | remise affichée | 2 |
| ☐ | 1.C2 | Passer à Stripe | montant Stripe = total **après** remise | 4 |
| ☐ | 1.C3 | SQL : `voucher_code`, `voucher_discount`, `total` | cohérents entre eux | 4 |

## 1.D · Abandon et échec Stripe — 20 min ⚠️ le plus important

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.D1 | Aller jusqu'à Stripe puis **fermer l'onglet** | aucune commande confirmée | 4 |
| ☐ | 1.D2 | SQL `ws_orders` | pas de commande payée fantôme | 3 |
| ☐ | 1.D3 | SQL `ws_stock_reservation` | **stock libéré** ou expirant, pas bloqué | 3 |
| ☐ | 1.D4 | Recommencer, utiliser une carte de test **refusée** | message d'erreur explicite | 5 |
| ☐ | 1.D5 | Vérifier le panier | **conservé**, pas vidé | 5 |

## 1.E · Aucun moyen configuré — 5 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.E1 | Désactiver temporairement les moyens de la boutique 2 (SQL) | — | 2 |
| ☐ | 1.E2 | Aller au paiement | message d'erreur explicite, commande **impossible** | 2 |
| ☐ | 1.E3 | **Réactiver** les moyens | ne pas oublier | 1 |

## 1.F · Réservation de stock — 15 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.F1 | Connecté, ajouter **Brookie 1150002** en collecte (1 pièce) | ajouté au panier | 2 |
| ☐ | 1.F2 | SQL `ws_stock_reservation` | 1 ligne, `expires_at` à +15 min | 3 |
| ☐ | 1.F3 | SQL `ws_product_stock` | `qty_reserved = 1`, dispo `0` | 2 |
| ☐ | 1.F4 | **Second navigateur**, autre compte, même produit | affiché **indisponible** | 4 |
| ☐ | 1.F5 | Retirer la ligne du panier | `released_at` daté, `qty_reserved = 0` | 4 |

## 1.G · Bon nominatif `B2-KGZ46X` — 15 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 1.G1 | Connecté avec un client du **bureau 4**, cougnou + 1 produit | — | 3 |
| ☐ | 1.G2 | Étape Paiement | le bon apparaît dans « Vos bons disponibles » | 2 |
| ☐ | 1.G3 | Appliquer | remise = **le prix du cougnou**, pas plus | 3 |
| ☐ | 1.G4 | Commander | commande créée au bon montant | 2 |
| ☐ | 1.G5 | Refaire une commande avec le même bon | **refusé** et **disparu** de la liste | 3 |
| ☐ | 1.G6 | Client **hors bureau 4** | le bon n'apparaît **jamais** | 2 |

---

# 2 · Séance BUREAU — 55 min

## 2.A · Rattachement — 10 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 2.A1 | Webshop **Corbais**, connecté → Compte → **Mon bureau** | bouton « Me rattacher à une entreprise… » visible | 2 |
| ☐ | 2.A2 | Cliquer | liste : SA IBA, XLG FACILITY, COMMUNE MONT ST GUIBERT | 2 |
| ☐ | 2.A3 | *Voie A* : sélectionner **SA IBA** → Confirmer | rattachement immédiat | 2 |
| ☐ | 2.A4 | *Voie B* : « Mon bureau n'est pas dans la liste » → nom + contact → Envoyer | « Demande envoyée ✓ » | 2 |
| ☐ | 2.A5 | *Voie B* : BO → Demandes de rattachement → **Lier** | journal `client #… → bureau #…` | 2 |

## 2.B · Livraison au bureau — 25 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 2.B1 | Basculer en **Livraison au bureau** | mode accessible (il ne l'était pas avant 2.A) | 3 |
| ☐ | 2.B2 | Parcourir le catalogue | les produits `office_delivery = 0` sont **absents** | 4 |
| ☐ | 2.B3 | **Recharger la page** | ils restent absents (filtre serveur) | 2 |
| ☐ | 2.B4 | Choisir un site de livraison | frais affichés, **pas** d'erreur « frais indisponibles » | 3 |
| ☐ | 2.B5 | Choisir un créneau | respecte cut-off et délai | 3 |
| ☐ | 2.B6 | Commander | commande créée | 3 |
| ☐ | 2.B7 | BO → Commandes du jour | visible avec bureau et tournée | 3 |
| ☐ | 2.B8 | BO → Suivi / dispatch | affectée à une tournée | 4 |

## 2.C · Paiement différé — 20 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 2.C1 | SQL : un bureau avec `deferred_billing_enabled = 1` | identifié | 3 |
| ☐ | 2.C2 | Client de ce bureau → livraison → Paiement | « Sur compte (facturation) » proposé | 5 |
| ☐ | 2.C3 | Vérifier les autres méthodes | **seules** celles autorisées apparaissent | 4 |
| ☐ | 2.C4 | Commander | enregistrée **sans** paiement immédiat | 4 |
| ☐ | 2.C5 | Client d'un bureau **sans** différé | « Sur compte » **absent** | 4 |

---

# 3 · Séance COMPTES — 60 min *(dépend des 2 écrans)*

## 3.A · Compte client — 20 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 3.A1 | Se connecter avec `maxime.decnop@outlook.com` (compte PWA sans mot de passe) | panneau **« Définir votre mot de passe »** | 5 |
| ☐ | 3.A2 | Définir un mot de passe | session ouverte | 2 |
| ☐ | 3.A3 | Se déconnecter, se reconnecter **par email** | OK | 2 |
| ☐ | 3.A4 | Se reconnecter **par téléphone** | OK, ou message clair si numéro partagé | 3 |
| ☐ | 3.A5 | Créer un compte neuf : CP invalide `0123` puis `12` | **refusé** dans les deux cas | 3 |
| ☐ | 3.A6 | Créer le compte avec un CP valide | créé, `webshop_user = 1` en base | 5 |

## 3.B · Handoff PWA — 5 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 3.B1 | Depuis la PWA, basculer vers le webshop | session reprise **sans** reconnexion | 3 |
| ☐ | 3.B2 | Réutiliser le même lien | **refusé** (jeton à usage unique) | 2 |

## 3.C · Comptes tablette PIN — 35 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 3.C1 | Console marque → **Profils tablette** → « Créer les profils standard » | 5 profils créés | 4 |
| ☐ | 3.C2 | Ouvrir le profil **Vendeur** | 4 sections cochées | 2 |
| ☐ | 3.C3 | BO franchisé (jeton admin) → **Comptes tablette** → « Julie D. », profil Vendeur, PIN `1234` | compte créé | 5 |
| ☐ | 3.C4 | Navigation privée → `?shop=2` | **pavé PIN** (aucun bandeau rouge) | 2 |
| ☐ | 3.C5 | Saisir `1234` | rechargement, **4 sections** au menu | 3 |
| ☐ | 3.C6 | Ouvrir chaque section | **des données**, pas d'écran vide | 4 |
| ☐ | 3.C7 | Forcer `?shop=3` dans l'URL | reste sur **Corbais** | 3 |
| ☐ | 3.C8 | Console marque : retirer une section du profil Vendeur | — | 2 |
| ☐ | 3.C9 | Recharger la tablette | la section a **disparu** | 2 |
| ☐ | 3.C10 | BO admin : **désactiver** Julie D. → recharger la tablette | accès **fermé** | 3 |
| ☐ | 3.C11 | Réactiver, puis saisir 9 mauvais PIN | **blocage** après 8 essais / 5 min | 3 |
| ☐ | 3.C12 | Bouton **Quitter** | retour au pavé, données de la session effacées | 2 |

---

# 4 · Séance ANNEXES — 25 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 4.1 | Compte → onglet **Fidélité** | présent, QR affiché (sinon `ws_param` le désactive) | 4 |
| ☐ | 4.2 | Onglet **Mes achats** | 12 derniers mois, dont les commandes de la séance 1 | 3 |
| ☐ | 4.3 | Demander une facture | générée, ou erreur explicite si colonne non migrée | 3 |
| ☐ | 4.4 | Compte → ajouter une société avec un **vrai n° de TVA belge** | nom et adresse **remplis par VIES** | 5 |
| ☐ | 4.5 | Numéro invalide | « n'a pas été reconnu » (≠ « indisponible ») | 3 |
| ☐ | 4.6 | Ajouter une société **sans** TVA (ASBL) | accepté, case TVA vide | 2 |
| ☐ | 4.7 | Boîte mail : e-mail de confirmation de la séance 1 | reçu — sinon SMTP à configurer | 5 |

---

# 5 · Mise en ligne — 60 min

| ☐ | # | Geste | Attendu | min |
|---|---|---|---|---|
| ☐ | 5.1 | **Dump complet daté** de `atelierby_db`, copié hors serveur | fichier vérifié | 15 |
| ☐ | 5.2 | `SELECT version FROM ws_schema_migrations ORDER BY version DESC LIMIT 10` | `0050` présent | 5 |
| ☐ | 5.3 | Lister commandes / comptes / bons de test | inventaire décidé | 10 |
| ☐ | 5.4 | Purger — **rien** qui soit référencé par une facture | base propre | 10 |
| ☐ | 5.5 | Générer un nouveau `admin_token` dans `api/config.php` | — | 5 |
| ☐ | 5.6 | Rouvrir chaque back-office avec `?token=<nouveau>` | accès OK ; l'ancien jeton ne marche plus | 5 |
| ☐ | 5.7 | Contrôle final : VIES, SMTP, Stripe | les trois répondent | 10 |

---

## Récapitulatif

| Séance | min | Cumul |
|---|---|---|
| 0 Préparation | 20 | 0 h 20 |
| 1 Argent | 95 | 1 h 55 |
| 2 Bureau | 55 | 2 h 50 |
| 3 Comptes | 60 | 3 h 50 |
| 4 Annexes | 25 | 4 h 15 |
| 5 Mise en ligne | 60 | 5 h 15 |

**5 h 15** de gestes, hors saisie des données (prix et allergènes, ~7 h 25) et
hors correction des défauts qui sortiront (+50 % à prévoir sur les séances 1 à 4).
