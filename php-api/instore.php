<?php
/* =====================================================================
   PANIER CAISSE (« scan & go ») — composer son panier dans le webshop,
   le faire scanner en boutique, payer seul au terminal.
   ---------------------------------------------------------------------
   Le client, dans la boutique, compose son panier sur son téléphone (mode
   Retrait, jour même) puis touche « Payer à la caisse ». Le serveur fige le
   panier aux prix de la boutique et rend un CODE COURT (« PC-7K3Q9X ») que le
   téléphone affiche en QR. À la caisse :

     1. la caisse scanne le QR (ou tape le code)   GET  /pos/instore/{code}
        → elle reçoit les lignes, le total et le code client fidélité ;
     2. elle prend le panier                        POST /pos/instore/{code}/claim
        → plus personne d'autre ne peut l'encaisser ; la caisse passe
          directement en AUTO-PAIEMENT (terminal de paiement / monnayeur
          automatique, le client règle seul, personne ne retape les articles) ;
     3. une fois payé                                POST /pos/instore/{code}/paid
        → le ticket fiscal de la caisse est enregistré, le téléphone du
          client affiche « Payé » et vide son panier.

   Le webshop N'ENCAISSE RIEN et n'édite aucun ticket : c'est la caisse
   (certifiée) qui fait foi sur le montant, la TVA et le ticket. Le total
   affiché au client est une estimation aux prix de la boutique — la caisse
   peut appliquer ses propres promotions du moment.

   Fidélité : le panier porte le client_code du client connecté. La caisse
   l'attache au ticket comme si elle avait scanné la carte de fidélité ; les
   points et tampons arrivent par le chemin habituel (ticket ERP →
   pwa_purchases → app et webshop).

   Authentification caisse : X-Device-Token (ws_shop_device_token, un jeton
   par boutique, migration 0052) — ou le jeton admin pour les essais. Le jeton
   d'appareil limite la caisse à SA boutique.
   ===================================================================== */

/** Le module est-il en service ? (table migrée + interrupteur ws_param) */
function instore_enabled() {
  if (ws_param('instore_basket_enabled', '1') === '0') return false;
  return tbl_exists('ws_instore_basket') && tbl_exists('ws_instore_basket_line');
}

/** Code court unique, lisible et dictable (sans 0/O/1/I) : « PC-7K3Q9X ». */
function instore_new_code() {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for ($i = 0; $i < 12; $i++) {
    $s = '';
    for ($j = 0; $j < 6; $j++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    if (!row('SELECT 1 x FROM ws_instore_basket WHERE code = ? LIMIT 1', ['PC-' . $s])) return 'PC-' . $s;
  }
  throw new RuntimeException('code panier caisse : aucun code libre');
}

/** Normalise un code saisi à la caisse (« pc 7k3q9x », « PC7K3Q9X »). */
function instore_norm_code($raw) {
  $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
  if (strpos($c, 'PC') === 0) $c = substr($c, 2);
  return $c === '' ? '' : 'PC-' . $c;
}

/** Entretien, à chaque création : les paniers ouverts échus passent en
 *  « expired » ; les paniers jamais payés de plus de 30 jours sont supprimés
 *  avec leurs lignes (les payés restent : ils portent le n° de ticket). */
function instore_housekeeping() {
  try {
    q("UPDATE ws_instore_basket SET status = 'expired' WHERE status = 'open' AND expires_at < NOW()");
    q("DELETE l FROM ws_instore_basket_line l JOIN ws_instore_basket b ON b.id = l.basket_id
        WHERE b.status IN ('expired','cancelled') AND b.created_at < NOW() - INTERVAL 30 DAY");
    q("DELETE FROM ws_instore_basket WHERE status IN ('expired','cancelled') AND created_at < NOW() - INTERVAL 30 DAY");
  } catch (Throwable $e) { /* best effort */ }
}

/**
 * Lignes au prix de la boutique — MÊMES sources que la commande (POST /orders) :
 * prix_produits() pour le produit, erp_portion_options() pour la portion,
 * bundle_compose() pour la composition d'un menu, erp_bundle_offre_pour() pour
 * un bundle ERP. Aucun prix n'est reçu du téléphone ; un prix absent refuse le
 * panier (409) plutôt que d'en deviner un.
 * Rend [lignes, sous-total] ; chaque ligne porte ses `children` (composants).
 */
function instore_price_lines($shop, array $basket) {
  $today = date('Y-m-d');
  $ids = array_map(static fn($it) => (int) ($it['productId'] ?? 0), $basket);
  $prices = prix_produits($ids, (int) $shop);
  $portPx = erp_portion_options($shop, $ids);
  $hasExt = col_exists('ws_products', 'external_id');
  $lines = []; $subtotal = 0.0;
  foreach ($basket as $it) {
    $p = row('SELECT p.id, p.name, p.cross_portion, p.cat_id, p.sub_cat_id' . ($hasExt ? ', p.external_id' : ', NULL AS external_id') . '
                FROM ws_products p WHERE p.id = ? AND p.active = 1', [(int) ($it['productId'] ?? 0)]);
    if (!$p) continue;
    $nom = trim((string) $p['name']);
    if (!product_available_on($p['id'], $today, $shop)) {
      json_out(['error' => '« ' . $nom . ' » n\'est pas vendu aujourd\'hui dans cette boutique — retirez-le du panier.'], 409);
    }
    if (!isset($prices[(int) $p['id']])) {
      json_out(['error' => 'Prix indisponible pour « ' . $nom . ' » dans cette boutique — retirez-le du panier.'], 409);
    }
    $unit = (float) $prices[(int) $p['id']];
    $portion = mb_strtolower(trim((string) ($it['portion'] ?? '')));
    if ($portion !== '' && $portion !== 'entier') {
      $unitP = null;
      foreach (($portPx[(int) $p['id']] ?? []) as $c) {
        if ($c['v'] === $portion && $c['price'] !== null) { $unitP = (float) $c['price']; break; }
      }
      if ($unitP === null) json_out(['error' => 'Prix de portion indisponible pour « ' . $nom . ' » — choisissez la pièce entière.'], 409);
      $unit = $unitP;
    } else {
      $portion = '';
    }
    $qty = max(1, min(99, (int) ($it['qty'] ?? 1)));
    $comp = bundle_compose($p['id'], $it['bundleId'] ?? null, is_array($it['bundleSlots'] ?? null) ? $it['bundleSlots'] : []);
    $children = [];
    foreach ($comp['choices'] as $c) {
      $children[] = ['productId' => isset($c['product_id']) ? (int) $c['product_id'] : null, 'name' => (string) $c['label'],
                     'portion' => null, 'qty' => 1, 'unit' => round((float) $c['delta'], 2)];
    }
    $nomL = $nom;
    if (!empty($it['bundleOfferId']) && function_exists('erp_bundle_offre_pour')) {
      $idsB = [(int) $p['id']];
      foreach ((array) erp_bundles_brut($shop) as $bb) if ($bb['id'] === (int) $it['bundleOfferId'])
        foreach ($bb['items'] as $bi) $idsB[] = $bi['pid'];
      $idsB = array_values(array_unique($idsB));
      $noms = [];
      foreach (rows('SELECT id, name FROM ws_products WHERE id IN (' . implode(',', array_fill(0, count($idsB), '?')) . ')', $idsB) as $nb) $noms[(int) $nb['id']] = (string) $nb['name'];
      $off = erp_bundle_offre_pour($shop, (int) $p['id'], (int) $it['bundleOfferId'], prix_produits($idsB, (int) $shop), erp_portion_options($shop, $idsB), $noms);
      if (!$off) json_out(['error' => 'Le bundle choisi pour « ' . $nom . ' » n\'est plus proposé — retirez-le du panier.'], 409);
      $unit = (float) $off['price']; $nomL = $off['name'] !== '' ? $off['name'] : $nom; $portion = '';
      $comp['modifier'] = 0; $children = [];
      foreach ($off['items'] as $li) {
        if ((int) $li['productId'] === (int) $p['id'] && !$li['portion'] && (int) $li['qty'] === 1) continue;   // la mère
        $children[] = ['productId' => (int) $li['productId'], 'name' => $li['name'] . ($li['portionLabel'] ? ', ' . $li['portionLabel'] : ''),
                       'portion' => $li['portion'] ?: null, 'qty' => (int) $li['qty'], 'unit' => 0.0];
      }
    }
    $unitMere = round($unit + (float) $comp['modifier'], 2);
    $suppl = 0.0; foreach ($children as $ch) $suppl += $ch['unit'] * $ch['qty'];
    $subtotal += ($unitMere + $suppl) * $qty;
    $lines[] = ['productId' => (int) $p['id'], 'externalId' => $p['external_id'] !== null ? (string) $p['external_id'] : null,
                'name' => $nomL, 'portion' => $portion ?: null, 'qty' => $qty, 'unit' => $unitMere, 'children' => $children,
                'cross' => (int) $p['cross_portion'], 'catId' => (int) ($p['cat_id'] ?? 0), 'subCatId' => (int) ($p['sub_cat_id'] ?? 0)];
  }
  return [$lines, round($subtotal, 2)];
}

/** Promo croisée X+Y (même résolveur que le panier et la commande). */
function instore_cross_promo($shop, array $lines) {
  $rule = function_exists('cross_portion_rule') ? cross_portion_rule($shop) : null;
  if (!$rule || (int) $rule['buy'] <= 0) return 0.0;
  $units = [];
  foreach ($lines as $l) {
    if (!cross_portion_eligible($rule, $l['productId'], $l['catId'], $l['subCatId'], $l['cross'])) continue;
    for ($k = 0; $k < $l['qty']; $k++) $units[] = $l['unit'];
  }
  if (count($units) < (int) $rule['threshold']) return 0.0;
  sort($units);
  $promo = 0.0;
  $free = intdiv(count($units), (int) $rule['buy']) * (int) $rule['free'];
  for ($k = 0; $k < $free && $k < count($units); $k++) $promo += $units[$k];
  return round($promo, 2);
}

/** Lignes enregistrées d'un panier, composants rangés sous leur ligne mère. */
function instore_lines($basketId) {
  $all = rows('SELECT id, parent_line_id, product_id, external_id, name, `portion`, qty, unit_price
                 FROM ws_instore_basket_line WHERE basket_id = ? ORDER BY id', [$basketId]);
  $out = []; $idx = [];
  foreach ($all as $l) {
    $x = ['productId' => $l['product_id'] !== null ? (int) $l['product_id'] : null,
          'externalId' => $l['external_id'], 'name' => $l['name'], 'portion' => $l['portion'],
          'qty' => (int) $l['qty'], 'unitPrice' => (float) $l['unit_price'],
          'lineTotal' => round((float) $l['unit_price'] * (int) $l['qty'], 2)];
    if ($l['parent_line_id'] === null) { $x['children'] = []; $idx[(int) $l['id']] = count($out); $out[] = $x; }
    elseif (isset($idx[(int) $l['parent_line_id']])) $out[$idx[(int) $l['parent_line_id']]]['children'][] = $x;
  }
  return $out;
}

/** Vue « téléphone » : ce que le client voit de son panier (jamais le jeton). */
function instore_public(array $b) {
  return [
    'code'           => $b['code'],
    'status'         => $b['status'],
    'shopId'         => (int) $b['shop_id'],
    'subtotal'       => (float) $b['subtotal'],
    'promo'          => (float) $b['promo_amount'],
    'total'          => (float) $b['total'],
    'items'          => (int) $b['items_count'],
    'expiresAt'      => $b['expires_at'],
    'claimedAt'      => $b['claimed_at'],
    'paidAt'         => $b['paid_at'],
    'paidAmount'     => $b['paid_amount'] !== null ? (float) $b['paid_amount'] : null,
    'ticketNo'       => $b['fiscal_ticket_no'],
    'pointsEstimate' => ($b['client_id'] && loy_tbl('pwa_client_loyalty')) ? loy_points_estimate((float) ($b['paid_amount'] ?? $b['total']), (int) $b['shop_id']) : 0,
    'loyaltyLinked'  => (bool) $b['client_id'],
  ];
}

/** Vue « caisse » : lignes, total, client fidélité. */
function instore_for_pos(array $b) {
  $client = null;
  if ($b['client_id']) {
    $c = row('SELECT * FROM client WHERE id = ? LIMIT 1', [(int) $b['client_id']]);
    if ($c) $client = ['code' => $c['client_code'] ?? null, 'name' => trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''))];
  }
  return instore_public($b) + ['client' => $client, 'lines' => instore_lines((int) $b['id']), 'claimedBy' => $b['claimed_by']];
}

/** Boutique de la caisse appelante : jeton d'appareil, ou jeton admin + shopId (essais). */
function instore_pos_shop() {
  $sid = device_token_shop();
  if ($sid) return $sid;
  if (is_admin_request()) {
    $q = qp('shopId') ?? (body()['shopId'] ?? null);
    return is_numeric($q) ? (int) $q : 0;   // 0 = admin sans boutique : toutes
  }
  json_out(['error' => 'Caisse non reconnue : jeton d\'appareil (X-Device-Token) requis.'], 401);
}

/** Panier par code pour la caisse, avec les refus parlants. */
function instore_pos_load($code, $shopId) {
  $b = row('SELECT * FROM ws_instore_basket WHERE code = ? LIMIT 1', [instore_norm_code($code)]);
  if (!$b) json_out(['error' => 'Code inconnu. Vérifiez le code affiché sur le téléphone du client.'], 404);
  if ($shopId && (int) $b['shop_id'] !== (int) $shopId) {
    $n = row('SELECT name FROM shops WHERE id = ?', [(int) $b['shop_id']]);
    json_out(['error' => 'Ce panier a été préparé pour ' . ($n['name'] ?? 'une autre boutique') . ' — le client doit le refaire ici.',
              'status' => $b['status']], 409);
  }
  if ($b['status'] === 'open' && strtotime((string) $b['expires_at']) < time()) {
    q("UPDATE ws_instore_basket SET status = 'expired' WHERE id = ? AND status = 'open'", [(int) $b['id']]);
    $b['status'] = 'expired';
  }
  return $b;
}

function instore_dispatch($m, $p) {
  if (strpos($p, '/instore/') !== 0 && strpos($p, '/pos/instore/') !== 0) return;
  if (!instore_enabled()) json_out(['error' => 'Le panier caisse n\'est pas activé.'], 501);

  /* ── Téléphone : créer le panier caisse ───────────────────────────────── */
  if ($m === 'POST' && $p === '/instore/baskets') {
    rate_limit('instore-create', 30, 600);
    instore_housekeeping();
    $b = body();
    $shop = (int) ($b['shopId'] ?? 0);
    $basket = is_array($b['basket'] ?? null) ? array_slice($b['basket'], 0, 80) : [];
    if (!$shop || !$basket) json_out(['error' => 'shopId et basket requis'], 400);
    if (!row('SELECT id FROM shops WHERE id = ? AND active = 1', [$shop])) json_out(['error' => 'Boutique inconnue.'], 404);
    [$lines, $subtotal] = instore_price_lines($shop, $basket);
    if (!$lines) json_out(['error' => 'Aucun produit valide dans le panier.'], 400);
    $promo = instore_cross_promo($shop, $lines);
    $total = round(max(0, $subtotal - $promo), 2);
    $items = 0; foreach ($lines as $l) $items += $l['qty'];
    $ttl = max(10, min(720, (int) ws_param('instore_basket_ttl_min', '120')));
    $uid = auth_uid() ?: null;
    $token = bin2hex(random_bytes(16));
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $code = instore_new_code();
      q('INSERT INTO ws_instore_basket (code, owner_token, shop_id, client_id, status, subtotal, promo_amount, total, items_count, expires_at)
         VALUES (?, ?, ?, ?, \'open\', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$code, $token, $shop, $uid, $subtotal, $promo, $total, $items, $ttl]);
      $bid = (int) $pdo->lastInsertId();
      $ins = $pdo->prepare('INSERT INTO ws_instore_basket_line (basket_id, parent_line_id, product_id, external_id, name, `portion`, qty, unit_price)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
      foreach ($lines as $l) {
        $ins->execute([$bid, null, $l['productId'], $l['externalId'], mb_substr($l['name'], 0, 255), $l['portion'], $l['qty'], $l['unit']]);
        $mere = (int) $pdo->lastInsertId();
        foreach ($l['children'] as $ch) {
          $ins->execute([$bid, $mere, $ch['productId'], null, mb_substr($ch['name'], 0, 255), $ch['portion'], $ch['qty'] * $l['qty'], $ch['unit']]);
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    $row = row('SELECT * FROM ws_instore_basket WHERE id = ?', [$bid]);
    json_out(['ok' => true, 'token' => $token] + instore_public($row) + ['lines' => instore_lines($bid)], 201);
  }

  /* ── Téléphone : suivre / annuler son panier (jeton du créateur) ──────── */
  if (preg_match('#^/instore/baskets/([A-Za-z0-9-]{4,16})$#', $p, $mm) && in_array($m, ['GET', 'DELETE'], true)) {
    $tok = (string) (qp('token') ?? '');
    $b = row('SELECT * FROM ws_instore_basket WHERE code = ? LIMIT 1', [instore_norm_code($mm[1])]);
    if (!$b || $tok === '' || !hash_equals((string) $b['owner_token'], $tok)) json_out(['error' => 'Panier introuvable.'], 404);
    if ($b['status'] === 'open' && strtotime((string) $b['expires_at']) < time()) {
      q("UPDATE ws_instore_basket SET status = 'expired' WHERE id = ? AND status = 'open'", [(int) $b['id']]);
      $b['status'] = 'expired';
    }
    if ($m === 'DELETE') {
      // Annulable tant que la caisse ne l'a pas pris ; ensuite, c'est au comptoir.
      if ($b['status'] === 'at_till') json_out(['error' => 'La caisse a déjà pris ce panier : voyez le comptoir.'], 409);
      if ($b['status'] === 'open') q("UPDATE ws_instore_basket SET status = 'cancelled' WHERE id = ? AND status = 'open'", [(int) $b['id']]);
      $b = row('SELECT * FROM ws_instore_basket WHERE id = ?', [(int) $b['id']]);
    }
    json_out(instore_public($b));
  }

  /* ── Caisse ───────────────────────────────────────────────────────────── */
  if (preg_match('#^/pos/instore/([A-Za-z0-9-]{4,20})(/(claim|release|paid))?$#', $p, $mm)) {
    $shopId = instore_pos_shop();
    $act = $mm[3] ?? '';
    $b = instore_pos_load($mm[1], $shopId);
    $till = mb_substr(trim((string) (body()['till'] ?? '')), 0, 40);
    $who = ($shopId ? 'shop:' . $shopId : 'admin') . ($till !== '' ? ' · ' . $till : '');

    if ($m === 'GET' && $act === '') json_out(instore_for_pos($b));

    if ($m === 'POST' && $act === 'claim') {
      // Idempotent pour la caisse qui l'a déjà pris ; refus pour toute autre.
      if ($b['status'] === 'at_till' && (string) $b['claimed_by'] === $who) json_out(instore_for_pos($b));
      if ($b['status'] !== 'open') {
        $msg = ['at_till' => 'Ce panier est déjà en cours d\'encaissement sur une autre caisse.',
                'paid' => 'Ce panier est déjà payé.', 'cancelled' => 'Le client a annulé ce panier.',
                'expired' => 'Ce panier a expiré : le client doit le recréer.'][$b['status']] ?? 'Panier indisponible.';
        json_out(['error' => $msg, 'status' => $b['status']], $b['status'] === 'expired' || $b['status'] === 'cancelled' ? 410 : 409);
      }
      $st = q("UPDATE ws_instore_basket SET status = 'at_till', claimed_at = NOW(), claimed_by = ? WHERE id = ? AND status = 'open'", [$who, (int) $b['id']]);
      if ($st->rowCount() !== 1) json_out(['error' => 'Ce panier vient d\'être pris par une autre caisse.'], 409);
      json_out(instore_for_pos(row('SELECT * FROM ws_instore_basket WHERE id = ?', [(int) $b['id']])));
    }

    if ($m === 'POST' && $act === 'release') {
      // Le client renonce au comptoir : le panier redevient scannable.
      if ($b['status'] === 'at_till') q("UPDATE ws_instore_basket SET status = 'open', claimed_at = NULL, claimed_by = NULL WHERE id = ? AND status = 'at_till'", [(int) $b['id']]);
      json_out(instore_for_pos(row('SELECT * FROM ws_instore_basket WHERE id = ?', [(int) $b['id']])));
    }

    if ($m === 'POST' && $act === 'paid') {
      $in = body();
      if ($b['status'] === 'paid') json_out(instore_for_pos($b));   // rejeu de la caisse : même réponse
      if ($b['status'] !== 'at_till' && $b['status'] !== 'open') json_out(['error' => 'Panier indisponible (' . $b['status'] . ').', 'status' => $b['status']], 409);
      // Montant RÉEL encaissé par la caisse (elle fait foi : promotions de
      // caisse, arrondis). Absent → le total du panier.
      $amount = isset($in['amount']) && is_numeric($in['amount']) ? round((float) $in['amount'], 2) : (float) $b['total'];
      $method = mb_substr(preg_replace('/[^a-z0-9_ -]/i', '', (string) ($in['method'] ?? '')), 0, 32) ?: null;
      $ticket = isset($in['ticketNo']) && $in['ticketNo'] !== '' ? mb_substr((string) $in['ticketNo'], 0, 40) : null;
      $posRef = isset($in['posRef']) && $in['posRef'] !== '' ? mb_substr((string) $in['posRef'], 0, 64) : null;
      q("UPDATE ws_instore_basket SET status = 'paid', paid_at = NOW(), paid_amount = ?, payment_method = ?, fiscal_ticket_no = ?, pos_ref = ?,
                claimed_at = COALESCE(claimed_at, NOW()), claimed_by = COALESCE(claimed_by, ?)
          WHERE id = ? AND status IN ('open','at_till')", [$amount, $method, $ticket, $posRef, $who, (int) $b['id']]);
      json_out(instore_for_pos(row('SELECT * FROM ws_instore_basket WHERE id = ?', [(int) $b['id']])));
    }
    json_out(['error' => 'Not found', 'path' => $p], 404);
  }
}
