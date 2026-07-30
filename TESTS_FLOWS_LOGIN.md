# Tests des flows nécessitant une connexion — liste pour le débogueur

Tout ce qui suit n'a **pas** pu être validé pendant la campagne de debug parce
qu'il exige un compte connecté (client, bureau, chauffeur ou tablette). Les
points sans connexion ont été validés en direct sur la base de production (voir
la section « Déjà validé » en fin de document).

**Environnement**
- Webshop : `http://185.180.206.46/webshop/`
- BO franchisé : `http://185.180.206.46/webshop/backoffice_franchisee/?shop=2`
- Console marque : `http://185.180.206.46/webshop/backoffice_franchisor/`
- Base : `atelierby_db` (⚠️ une base `scheme_cmp` existe aussi : **toujours
  préfixer** `atelierby_db.` dans les requêtes)
- Les back-offices exigent le **jeton admin** en paramètre la première fois :
  `?shop=2&token=<jeton>` (il est ensuite mémorisé par le navigateur).

**Règle du projet à respecter dans tout correctif**
> Soit la fonctionnalité marche avec les vraies données, soit elle renvoie une
> erreur visible. Aucun seed, aucun mock, aucun repli qui fabrique de la donnée.

---

## 1. Création de compte client + connexion

**Préconditions** : aucune.

**Étapes**
1. Webshop → icône compte → créer un compte (email jamais utilisé, mot de passe,
   code postal — il est exigé « partout »).
2. Se déconnecter, se reconnecter avec l'email **puis** avec le téléphone
   (l'identifiant accepte les deux).
3. Demander une réinitialisation de mot de passe, puis définir le nouveau.

**Attendu** : compte créé, connexion OK par les deux identifiants, email de
réinitialisation envoyé, nouveau mot de passe fonctionnel.

**Vérification**
```sql
SELECT id, email, phone, zip, office_id, preferred_shop_id, created_at
  FROM atelierby_db.client ORDER BY id DESC LIMIT 5;
```

**Points de vigilance** : le CP doit être validé au format belge (4 chiffres, pas
de 0 initial) ; l'email de reset part en « best-effort » (ne doit jamais faire
échouer l'inscription).

---

## 2. Livraison au bureau (le flow le plus long) ⚠️ priorité

**Préconditions** : un client rattaché à un bureau (`client.office_id` non nul).
Live : seuls **6 clients sur 10 708** ont un `office_id` — les ids **5, 195,
4477, 6676** appartiennent au bureau 4 (SA IBA).

**Étapes**
1. Se connecter avec le client **5**.
2. Webshop → basculer en mode **Livraison au bureau**.
3. Vérifier que le catalogue **exclut** les produits non éligibles
   (`office_delivery = 0`) — c'est un filtre serveur, il doit s'appliquer même
   en rechargeant.
4. Choisir un site de livraison, puis un créneau.
5. Passer commande.
6. BO franchisé → **Commandes du jour** : la commande apparaît avec son bureau
   et sa tournée.
7. BO franchisé → **Suivi / dispatch** : la commande est affectée à une tournée.

**Attendu** : commande créée avec `office_client_id`, `tour_id` et
`office_delivery_site_id` renseignés ; visible dans les deux écrans.

**Vérification**
```sql
SELECT id, order_ref, mode, customer_id, office_client_id, tour_id,
       office_delivery_site_id, delivery_date, slot_label, total, status
  FROM atelierby_db.ws_orders
 WHERE mode = 'delivery' ORDER BY id DESC LIMIT 5;
```

**Points de vigilance**
- Les créneaux de livraison sont **générés** depuis `ws_shop_availability`
  (08:30–13:30, pas de 120 min, cut-off 11:00, lead 20 h) quand `ws_slots` est
  vide : vérifier que les créneaux proposés respectent bien cut-off et délai.
- `ws_tour_tracking` est **vide** en base : le suivi temps réel n'a jamais reçu
  de position. À confirmer : est-ce normal (aucune tournée encore roulée) ou
  l'écriture des positions n'est-elle pas branchée ?

---

## 3. Bon nominatif / bureau de bout en bout ⚠️ priorité

**Préconditions** : bon `B2-KGZ46X` (boutique Corbais, cible **bureau 4 SA
IBA**, « Cougnou - Sucre offert », −100 % sur 1 pièce, limite **1 par client**).

**Étapes**
1. Se connecter avec le client **5** (bureau 4).
2. Mettre un **Cougnou - Sucre** au panier + un autre produit.
3. Étape Paiement : le bon doit apparaître dans « Vos bons disponibles » avec un
   bouton **Appliquer** (affichage marketing) — vérifier le libellé
   « Cougnou - Sucre offert ».
4. Appliquer, vérifier que la remise = **le prix du cougnou** (et pas plus).
5. Passer commande.
6. Refaire une commande avec le même bon → il doit être **refusé** (limite 1 par
   client atteinte) et **ne plus apparaître** dans les bons disponibles.
7. Se connecter avec un client **hors bureau 4** → le bon ne doit **jamais**
   apparaître ni fonctionner.

**Attendu** : remise exacte, compteur incrémenté, deuxième usage refusé,
étanchéité du ciblage.

**Vérification**
```sql
SELECT vco.code, vco.usage_count, vc.usage_limit_per_customer,
       COUNT(vr.id) AS redemptions, GROUP_CONCAT(vr.id_customer) AS clients
  FROM atelierby_db.voucher_code vco
  JOIN atelierby_db.voucher_campaign vc ON vc.id = vco.id_voucher_campaign
  LEFT JOIN atelierby_db.voucher_redemption vr ON vr.id_voucher_code = vco.id
 WHERE vco.code = 'B2-KGZ46X' GROUP BY vco.code, vco.usage_count, vc.usage_limit_per_customer;
```

**Déjà validé sans connexion** : le ciblage et la limite par client ont été
vérifiés via `/vouchers/available` (le bon disparaît dès la première redemption
simulée). Reste à confirmer le **montant réellement facturé** en commande.

---

## 4. Paiement différé (facturation mensuelle)

**Préconditions** : un bureau avec `ws_offices.deferred_billing_enabled = 1`.

**Étapes** : se connecter avec un client de ce bureau → commander en livraison
bureau → l'étape Paiement doit proposer **« Paiement différé »** et **uniquement
les moyens autorisés** pour ce profil.

**Attendu** : la liste des moyens vient du serveur (`/payment-methods`), la
commande est enregistrée sans paiement immédiat.

**Point de vigilance** : les moyens de paiement codés en dur ont été supprimés.
Si la liste est **vide**, l'écran affiche désormais une **erreur explicite** —
c'est le comportement voulu, cela signifie que la boutique n'a aucun moyen
configuré. Vérifier alors `ws_shop_payment_options`.

---

## 5. Réservation de stock (maintien 15 min)

**Préconditions** : client connecté + un produit avec du stock du jour
(`ws_product_stock`).

**Étapes** : ajouter le produit au panier → vérifier la réservation → attendre
l'expiration ou vider le panier → vérifier la libération.

**Attendu** : `qty_reserved` monte à l'ajout, redescend à la libération/expiration.

**Vérification**
```sql
SELECT p.name, st.mode, st.qty_total, st.qty_reserved, st.qty_sold
  FROM atelierby_db.ws_product_stock st
  JOIN atelierby_db.ws_products p ON p.id = st.product_id
 WHERE st.shop_id = 2 AND st.date = CURDATE();
```

**Point de vigilance** : la réservation **lève désormais une erreur** si le
serveur refuse (avant, l'échec était silencieux et le stock n'était pas tenu →
risque de survente).

---

## 6. Fidélité + « Mes achats »

**Préconditions** : client connecté avec au moins une commande passée.

**Étapes** : compte → onglet **Fidélité** (visible seulement si le flag serveur
l'autorise) → afficher le QR ; onglet **Mes achats** → liste des 12 derniers
mois, demander une facture.

**Point de vigilance** : le flag `fidelityTabEnabled` n'est plus forcé à `true`
côté client. Si l'onglet n'apparaît pas, c'est que le serveur ne l'active pas
(`ws_param`) — ce n'est pas un bug d'affichage.

---

## 7. Société de facturation + VIES

**Préconditions** : client connecté.

**Étapes** : compte → ajouter une société → saisir un **vrai** numéro de TVA
belge → vérifier que nom et adresse sont **remplis par le service**, puis
l'ajout sans TVA.

**Attendu** : identité renseignée par VIES, ou message
« Vérification TVA indisponible ».

**Point de vigilance** : le **mock VIES a été supprimé** (il fabriquait
l'identité de 5 fausses sociétés et rejetait les vrais numéros). Si la
vérification échoue systématiquement, c'est que le proxy VIES n'est pas
configuré côté serveur — le message doit le dire clairement.

---

## 8. Demande de rattachement bureau → validation franchisé

**Étapes**
1. Webshop, client connecté sans bureau → demander le rattachement à un bureau.
2. BO franchisé → **Demandes B2B / Demandes de rattachement** → valider.
3. Le client doit alors accéder au mode Livraison bureau.

**Vérification**
```sql
SELECT * FROM atelierby_db.ws_office_join_requests ORDER BY id DESC LIMIT 5;
SELECT id, email, office_id FROM atelierby_db.client WHERE office_id IS NOT NULL;
```

---

## 9. Dispatch tablette chauffeur

**Préconditions** : une tournée publiée avec des arrêts.

**Étapes** : BO franchisé → **Tournées** → publier vers la tablette → ouvrir la
vue chauffeur → valider un arrêt (QR / PIN / signature selon le site) → vérifier
la remontée dans **Suivi**.

**Point de vigilance** : `ws_tour_tracking` est vide — c'est le test qui dira si
l'écriture des positions/validations fonctionne.

---

## 10. Comptes tablette par PIN (fonctionnalité neuve, jamais testée en usage)

**Préconditions** : un compte créé dans la console marque → **Utilisateurs &
rôles** → **Comptes tablette boutique**.

**Étapes**
1. Créer « Julie D. », boutique Corbais, rôle **Vendeur**, PIN **1234**.
2. Ouvrir le BO franchisé en navigation privée (donc **sans** jeton admin) :
   `?shop=2` → le pavé PIN doit s'afficher.
3. Saisir 1234 → seules les **4 sections** du rôle doivent être au menu.
4. Tenter d'atteindre une section interdite → refus explicite.
5. Désactiver le compte depuis la console marque, puis recharger la tablette →
   l'accès doit se **fermer** (la session est revalidée côté serveur).
6. Tester 9 mauvais PIN d'affilée → blocage temporaire (8 essais / 5 min).
7. Bouton **Quitter** → retour au pavé PIN.

**Attendu** : cloisonnement effectif (menu, navigation et serveur), révocation
immédiate, anti-brute-force actif.

---

## 11. Handoff PWA → webshop (SSO par jeton)

**Étapes** : depuis la PWA, ouvrir le webshop via le lien de bascule
(`?handoff=<jeton>`) → la session doit être reprise sans nouvelle connexion, et
la boutique préférée du client appliquée.

---

# Déjà validé sans connexion (ne pas refaire)

| Sujet | Résultat |
|---|---|
| Checkout invité complet | commande créée (order 8/9), email de contact, confirmation |
| Code promo `BIENVENUE10` | aperçu = montant facturé |
| Créneaux Click & Collect | générés depuis `ws_shop_availability` (jamais inventés) |
| Portions ERP | prix par portion, plus de facteurs inventés |
| Règle « obligatoire ⇒ vendable » | trigger vérifié (produit 2110001 reste actif) |
| Assortiment (activation/retrait) | écriture réelle `ws_product_shops` |
| Hygiène des noms produits | tabulations/espaces normalisés + garde permanente |
| Prix : source unique ERP | réplica supprimé ; produit sans prix masqué et refusé |
| Prix ERP à 0 | traité comme « non fixé » : plus de facturation à 0 € |
| Allergènes | 3 états distincts, plus de faux « sans allergène » |
| Stock du jour (BO → base → API → blocage) | chaîne complète |
| Compteurs de bons + limite par client | vérifiés via `/vouchers/available` |
| Zones de chalandise | polygones réels affichés |
| Écrans marque | KPIs, Bons, Catalogue, Audit, Menu Builder, Gouvernance |
| Formules vides | plus proposées au client |
