<?php
/* voucher_promos.php — PROMOTIONS PRODUIT DÉCLENCHÉES PAR UN CODE.
 *
 * Le module marketing pose, à l'étape « Prix » d'une campagne, une promotion
 * produit par produit (−15 %, prix barré, 2 achetés → 1 offert). Un bon de la
 * campagne peut les DÉCLENCHER : au webshop, elles ne valent qu'avec ce code.
 * Le lien vit ici, dans ws_voucher_product_promos (migration 0136), rattaché à
 * la campagne de bons (voucher_campaign) qui porte le code.
 *
 * SANS EFFET DE BORD : uniquement des fonctions pures (aucune base, aucun
 * global), testées par tests/voucher_promos_test.php.
 *
 * RÈGLE D'OR : voucher_promos_apply() est LA fonction qui chiffre la remise,
 * appelée par l'aperçu du panier (POST /vouchers/redeem) ET par la facturation
 * (POST /orders), sur des prix résolus serveur. Deux calculs séparés finiraient
 * par diverger : le client verrait une remise qu'on ne lui accorderait pas.
 *
 * Ce que chaque mécanique remise, pièce par pièce :
 *   PERCENT       : pourcentage du prix de la pièce — toute portion (un
 *                   pourcentage vaut pour un quart comme pour l'entier) ;
 *   CROSSED_PRICE : la pièce ENTIÈRE passe au prix imposé (écart = remise ;
 *                   rien si la boutique vend déjà moins cher) ;
 *   BUY_X_GET_Y   : par lot de x + y pièces ENTIÈRES, les y moins chères sont
 *                   offertes (même lecture que le module marketing : le lot se
 *                   vend au prix des x achetées).
 * Les formules et bundles (ligne « pack ») gardent leur propre prix : aucune
 * promotion produit ne s'y ajoute.
 *
 * Plafond (max_qty, « quantité maximum par ticket » de l'étape Prix) : nombre
 * de pièces au plus qui profitent de la promotion dans une commande — les
 * moins chères d'abord, comme le périmètre produit des bons (0042).
 *
 * Non cumulable (cumulative = 0, le défaut de l'étape Prix) : une ligne qui
 * entre dans l'offre croisée du webshop (« 4 + 1 »), quand celle-ci joue, n'en
 * profite pas une seconde fois. Avec un autre bon, la question ne se pose pas :
 * une commande ne porte qu'un code.
 */

/* Les mécaniques que le webshop sait appliquer. « Prix de formule » et
   « Livraison offerte » de l'étape Prix n'en font pas partie : refusées à
   l'écriture plutôt qu'enregistrées et ignorées. */
function voucher_promo_mechanics(): array {
  return ['PERCENT', 'CROSSED_PRICE', 'BUY_X_GET_Y'];
}

/* Valide la liste envoyée par le module marketing. Pure.
 * Entrée : [{productId, mechanic, percent?, price?, buy?, get?, maxQty?}, …]
 * Retourne ['errors' => [messages], 'clean' => [lignes prêtes à écrire]]. */
function voucher_promos_validate($in, bool $cumulative = false): array {
  $e = []; $c = [];
  if (!is_array($in) || !array_is_list($in)) return ['errors' => ['promos : liste attendue'], 'clean' => []];
  if (!$in) $e[] = 'promos : au moins une promotion';
  if (count($in) > 50) $e[] = 'promos : 50 au plus';
  $vus = [];
  foreach ($in as $i => $p) {
    $n = 'promos[' . $i . ']';
    if (!is_array($p)) { $e[] = "$n : objet attendu"; continue; }
    $pid = (int) ($p['productId'] ?? 0);
    $mec = strtoupper(trim((string) ($p['mechanic'] ?? '')));
    if ($pid <= 0) $e[] = "$n : productId requis";
    if (!in_array($mec, voucher_promo_mechanics(), true)) {
      $e[] = "$n : mécanique « $mec » non appliquée au webshop (attendu " . implode(', ', voucher_promo_mechanics()) . ')';
      continue;
    }
    if ($pid > 0 && isset($vus[$pid])) $e[] = "$n : produit $pid déjà promu par ce bon";
    $vus[$pid] = true;
    $ligne = ['product_id' => $pid, 'mechanic' => $mec, 'percent' => null, 'price' => null,
              'buy_qty' => null, 'get_qty' => null, 'max_qty' => null,
              'cumulative' => $cumulative ? 1 : 0, 'sort_order' => $i];
    if ($mec === 'PERCENT') {
      $v = (float) ($p['percent'] ?? 0);
      if ($v <= 0 || $v > 100) $e[] = "$n : pourcentage entre 0 et 100";
      $ligne['percent'] = round($v, 2);
    } elseif ($mec === 'CROSSED_PRICE') {
      $v = (float) ($p['price'] ?? 0);
      if ($v <= 0) $e[] = "$n : prix barré supérieur à 0";
      $ligne['price'] = round($v, 2);
    } else {
      $x = (int) ($p['buy'] ?? 0); $y = (int) ($p['get'] ?? 0);
      if ($x < 1 || $y < 1) $e[] = "$n : offre liée, au moins 1 achetée et 1 offerte";
      $ligne['buy_qty'] = $x; $ligne['get_qty'] = $y;
    }
    $mx = $p['maxQty'] ?? null;
    if ($mx !== null && $mx !== '') {
      if ((int) $mx < 1) $e[] = "$n : plafond par commande au moins 1";
      $ligne['max_qty'] = max(1, (int) $mx);
    }
    $c[] = $ligne;
  }
  return ['errors' => $e, 'clean' => $c];
}

/* Montant lisible : 16.9 → « 16,90 € ». */
function voucher_promo_euros(float $v): string {
  return number_format($v, 2, ',', ' ') . ' €';
}

/* Libellé d'une promotion, tel que le client le lit au panier. Construit ici
   depuis la mécanique et le NOM WEBSHOP du produit — jamais reçu de
   l'appelant : c'est ce nom-là que le client voit dans sa commande. */
function voucher_promo_label(array $p, string $produit): string {
  $produit = trim($produit) !== '' ? trim($produit) : ('produit #' . (int) ($p['product_id'] ?? 0));
  switch ($p['mechanic'] ?? '') {
    case 'PERCENT':
      return $produit . ' : −' . rtrim(rtrim(number_format((float) $p['percent'], 2, ',', ''), '0'), ',') . ' %';
    case 'CROSSED_PRICE':
      return $produit . ' à ' . voucher_promo_euros((float) $p['price']);
    case 'BUY_X_GET_Y':
      $x = (int) $p['buy_qty']; $y = (int) $p['get_qty'];
      return $produit . ' : ' . $x . ' acheté' . ($x > 1 ? 's' : '') . ' → ' . $y . ' offert' . ($y > 1 ? 's' : '');
  }
  return $produit;
}

/* Pièces du panier qu'une promotion peut toucher : prix unitaires, une entrée
   par pièce, les moins chères d'abord. */
function voucher_promo_pieces(array $p, array $lines): array {
  $entieres = ($p['mechanic'] ?? '') !== 'PERCENT';
  $u = [];
  foreach ($lines as $l) {
    if ((int) ($l['productId'] ?? 0) !== (int) $p['product_id']) continue;
    if (!empty($l['pack'])) continue;
    if (empty($p['cumulative']) && !empty($l['crossLocked'])) continue;
    $po = mb_strtolower(trim((string) ($l['portion'] ?? '')));
    if ($entieres && $po !== '' && $po !== 'entier') continue;
    $unit = (float) ($l['unit'] ?? 0);
    if ($unit <= 0) continue;
    for ($k = 0; $k < max(0, (int) ($l['qty'] ?? 0)); $k++) $u[] = $unit;
  }
  sort($u);
  return $u;
}

/* LA FONCTION QUI CHIFFRE — aperçu ET facturation.
 * $promos : lignes de ws_voucher_product_promos (+ 'label').
 * $lines  : [{productId, qty, unit, portion, pack, crossLocked}] aux prix serveur.
 * Retourne :
 *   discount : remise totale (2 décimales) ;
 *   details  : [{productId, mechanic, name, label, amount, pieces}] des promotions qui ont joué ;
 *   unmet    : [{productId, mechanic, name, label, need, have}] de celles qui n'ont pas joué —
 *              need = pièces à mettre au panier pour qu'elle joue. */
function voucher_promos_apply(array $promos, array $lines): array {
  $total = 0.0; $details = []; $unmet = [];
  foreach ($promos as $p) {
    $u = voucher_promo_pieces($p, $lines);
    $cap = isset($p['max_qty']) && $p['max_qty'] !== null ? (int) $p['max_qty'] : null;
    $prises = $cap !== null ? array_slice($u, 0, max(0, $cap)) : $u;
    $montant = 0.0; $pieces = 0; $need = 1;
    switch ($p['mechanic']) {
      case 'PERCENT':
        $montant = round(array_sum($prises) * (float) $p['percent']) / 100;
        $pieces = count($prises);
        break;
      case 'CROSSED_PRICE':
        foreach ($prises as $px) {
          $ecart = $px - (float) $p['price'];
          if ($ecart > 0) { $montant += $ecart; $pieces++; }
        }
        break;
      case 'BUY_X_GET_Y':
        $x = (int) $p['buy_qty']; $y = (int) $p['get_qty'];
        $need = $x + $y;
        if ($x >= 1 && $y >= 1) {
          $offertes = intdiv(count($prises), $need) * $y;
          for ($k = 0; $k < $offertes; $k++) $montant += $prises[$k];
          $pieces = $offertes;
        }
        break;
    }
    $montant = round($montant, 2);
    $label = (string) ($p['label'] ?? voucher_promo_label($p, (string) ($p['product_name'] ?? '')));
    $nom = trim((string) ($p['product_name'] ?? '')) ?: ('produit #' . (int) $p['product_id']);
    if ($montant > 0) {
      $total += $montant;
      $details[] = ['productId' => (int) $p['product_id'], 'mechanic' => $p['mechanic'], 'name' => $nom,
                    'label' => $label, 'amount' => $montant, 'pieces' => $pieces];
    } else {
      $unmet[] = ['productId' => (int) $p['product_id'], 'mechanic' => $p['mechanic'], 'name' => $nom,
                  'label' => $label, 'need' => $need, 'have' => count($u)];
    }
  }
  return ['discount' => round($total, 2), 'details' => $details, 'unmet' => $unmet];
}
