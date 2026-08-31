<?php
/* ============================================================================
 * Remontée des commandes vers l'ERP — POST {base}/client-orders.
 *
 * Cap acté : les tables ws_ sont appelées à disparaître. La commande doit donc
 * NAÎTRE côté ERP (client_order + client_order_product) ; ws_orders n'est plus
 * que la copie opérationnelle transitoire (créneau, livraison, paiement —
 * autant de notions que le modèle ERP ne porte pas encore).
 *
 * QUAND pousser : quand la commande est DÉFINITIVE, jamais avant.
 *   - comptoir / sur-compte : dès l'enregistrement (POST /orders) ;
 *   - Stripe : à l'ENCAISSEMENT (webhook payment_status='paid') — pousser à la
 *     création enverrait chaque panier abandonné en production (id_order_status
 *     n'a ni « en attente de paiement » ni « annulée » : 1 new, 2 completed,
 *     3 picked_up, 4 unpicked — confirmé par l'équipe ERP le 31/08).
 *
 * CE QUE l'ERP attend (contrat mesuré sur le dump client_order_product) :
 *   - les PRIX viennent de l'appelant (unit_gross_price, total_gross_value_
 *     after_discount) → cette remontée ne part QUE du serveur, jamais du
 *     navigateur : le jeton est un secret serveur et l'ERP fait confiance aux
 *     montants ;
 *   - comment est NOT NULL → toujours une chaîne, jamais null ;
 *   - la TVA est recalculée par l'ERP depuis le produit — rien à envoyer.
 *
 * CHOIX DE REPRÉSENTATION (validés) :
 *   - remises (promo croisée, remise webshop, bon) : réparties PRO-RATA sur
 *     les lignes — la somme des total_gross_value_after_discount est LE
 *     montant marchandises réellement payé, au centime (arrondi corrigé) ;
 *   - frais de livraison : pas de champ ERP → signalés dans comment, EN SUS
 *     des lignes ;
 *   - composants de menu : pas de notion parent/enfant côté ERP → la ligne
 *     mère porte prix + suppléments, la composition part dans comment (des
 *     lignes fantômes fausseraient les compteurs de pièces) ;
 *   - bon de réduction : PAS de voucher_candidate_code — le webshop enregistre
 *     déjà la redemption (canal WS) ; le renvoyer risquerait le double
 *     comptage. Le code est cité dans comment ;
 *   - po_hash / id_transaction : jamais envoyés (sens à clarifier côté ERP).
 *
 * SÛRETÉ : meilleur effort, TOUJOURS. Un ERP en panne n'annule jamais une
 * commande enregistrée ni ne casse l'accusé au webhook Stripe : l'échec est
 * posé sur ws_orders.erp_push_error et le cron de reprise
 * (cron/erp-orders-push.php) réessaie. L'API ERP n'a pas de clé
 * d'idempotence : erp_order_id NULL est LA garde anti-doublon, tenue sous
 * verrou GET_LOCK (webhook et cron peuvent viser la même commande à la même
 * seconde).
 *
 * ACTIVATION : ws_param.orders_push = 'erp' (même patron que catalog_source).
 * Tant que le paramètre n'est pas posé, tout ceci est inerte.
 * ========================================================================== */

/* Interrupteur — même patron que erp_catalog_enabled() : l'ERP est configuré
   ET le paramètre est explicitement posé. Rien ne part « par accident ». */
function erp_orders_enabled() {
  if (!function_exists('ws_param') || !function_exists('erp_enabled')) return false;
  if (!erp_enabled()) return false;
  try { return strtolower((string) (ws_param('orders_push', '') ?: '')) === 'erp'; }
  catch (Throwable $e) { return false; }
}

/* Famille d'un moyen de paiement. Source de vérité : payment_family()
   (index.php) quand elle est chargée ; la copie ci-dessous n'existe que pour
   les contextes CLI (cron, tests) où index.php n'est pas inclus — GARDER LES
   DEUX LISTES ALIGNÉES. */
function erp_order_famille($m) {
  if (function_exists('payment_family')) return payment_family($m);
  $m = strtolower(trim((string) $m));
  if (in_array($m, ['stripe', 'card', 'carte', 'bancontact', 'visa', 'mastercard', 'maestro'], true)) return 'stripe';
  if (in_array($m, ['shop', 'boutique', 'especes', 'cash', 'cod'], true)) return 'shop';
  if (in_array($m, ['deferred', 'account', 'compte', 'facturation'], true)) return 'deferred';
  return $m;
}

/* Répartition d'une remise GLOBALE sur des lignes (montants bruts), pro-rata,
 * en centimes justes : somme des parts = remise (bornée au total), chaque part
 * dans [0, brut de la ligne]. La dernière ligne absorbe l'arrondi ; si un
 * plafonnement l'empêche, le reliquat est posé sur la première ligne qui a
 * encore de la marge. Fonction PURE (testée par tests/erp_orders_test.php). */
function erp_order_allouer_remises(array $bruts, $remise) {
  $bruts = array_values(array_map(static fn ($b) => round((float) $b, 2), $bruts));
  $n = count($bruts);
  if (!$n) return [];
  $g = round(array_sum($bruts), 2);
  $remise = max(0.0, min(round((float) $remise, 2), $g));
  $rep = []; $resteR = $remise; $resteG = $g;
  foreach ($bruts as $i => $b) {
    $d = ($i === $n - 1 || $resteG <= 0)
       ? min($b, $resteR)
       : min($b, round($resteR * $b / $resteG, 2));
    $d = max(0.0, round($d, 2));
    $rep[] = $d;
    $resteR = round($resteR - $d, 2);
    $resteG = round($resteG - $b, 2);
  }
  for ($i = 0; $resteR > 0 && $i < $n; $i++) {
    $marge = round($bruts[$i] - $rep[$i], 2);
    if ($marge <= 0) continue;
    $pose = min($marge, $resteR);
    $rep[$i] = round($rep[$i] + $pose, 2);
    $resteR = round($resteR - $pose, 2);
  }
  return $rep;
}

/* pick_up_datetime (NOT NULL côté ERP) : jour de retrait/livraison + heure de
 * DÉBUT du créneau, extraite du libellé (« 10:00 – 10:30 », « 10h00 »).
 * Libellé absent ou sans heure → minuit assumé : ça se LIT comme « heure non
 * précisée », et le créneau humain reste dans comment. Fonction PURE. */
function erp_order_retrait_datetime($date, $slotLabel) {
  $d = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date))
     ? substr($date, 0, 10) : date('Y-m-d');
  $h = '00:00:00';
  if (is_string($slotLabel) && preg_match('/(\d{1,2})\s*[:hH]\s*(\d{2})/', $slotLabel, $m)) {
    $h = sprintf('%02d:%02d:00', min(23, (int) $m[1]), min(59, (int) $m[2]));
  }
  return $d . ' ' . $h;
}

function erp_order_eur($v) { return number_format((float) $v, 2, ',', ' '); }

/* Corps du POST /client-orders, depuis une ligne ws_orders + ses
 * ws_order_lines. Fonction PURE (aucune lecture base) — c'est elle que le test
 * couvre. Les lignes ENFANTS (parent_line_id, composants de menu) sont
 * repliées dans le prix unitaire de leur mère ; une ligne mère sans product_id
 * (introuvable côté ERP) est EXCLUE des products et signalée dans comment —
 * l'y laisser ferait rejeter TOUTE la commande (404 PRODUCT_NOT_FOUND). */
function erp_order_payload(array $o, array $lignes) {
  $enfants = [];
  foreach ($lignes as $l) {
    if (!empty($l['parent_line_id'])) $enfants[(int) $l['parent_line_id']][] = $l;
  }

  $meres = []; $compos = []; $notesL = []; $exclues = [];
  foreach ($lignes as $l) {
    if (!empty($l['parent_line_id'])) continue;
    $kids = $enfants[(int) ($l['id'] ?? 0)] ?? [];
    $suppl = 0.0; $noms = [];
    foreach ($kids as $k) {
      $delta = (float) $k['unit_price'];
      $suppl += $delta;
      $noms[] = (string) $k['product_name'] . ($delta > 0 ? ' (+' . erp_order_eur($delta) . ' €)' : '');
    }
    if ($noms) $compos[] = '« ' . $l['product_name'] . ' » : ' . implode(', ', $noms);
    if (!empty($l['note'])) $notesL[] = '« ' . $l['product_name'] . ' » : ' . $l['note'];
    $qte = max(1, (int) $l['qty']);
    if (empty($l['product_id'])) { $exclues[] = (string) $l['product_name'] . ' ×' . $qte; continue; }
    $unite = round((float) $l['unit_price'] + $suppl, 2);
    $meres[] = [
      'id_product'  => (int) $l['product_id'],
      'portion_id'  => !empty($l['portion_id']) ? (int) $l['portion_id'] : null,
      'name'        => (string) $l['product_name'],
      'qty'         => $qte,
      'unit'        => $unite,
      'brut'        => round($unite * $qte, 2),
    ];
  }

  $remise = round((float) ($o['promo_amount'] ?? 0)
                + (float) ($o['webshop_discount'] ?? 0)
                + (float) ($o['voucher_discount'] ?? 0), 2);
  $rep = erp_order_allouer_remises(array_column($meres, 'brut'), $remise);

  $products = [];
  foreach ($meres as $i => $m) {
    $p = [
      'id_product'       => $m['id_product'],
      'name'             => $m['name'],
      'quantity'         => $m['qty'],
      'weight'           => 0,
      'unit_gross_price' => $m['unit'],
      'item_discount_value'              => $rep[$i] ?? 0.0,
      'total_gross_value_after_discount' => round($m['brut'] - ($rep[$i] ?? 0.0), 2),
    ];
    if ($m['portion_id'] !== null) $p['id_product_portion'] = $m['portion_id'];
    $products[] = $p;
  }

  $fam = erp_order_famille($o['payment_method'] ?? '');
  $parts = ['Webshop ' . (string) ($o['order_ref'] ?? '')];
  if (($o['mode'] ?? '') === 'delivery') {
    $lieu = trim((string) ($o['office_delivery_site_name'] ?? ''));
    $parts[] = 'Livraison bureau' . ($lieu !== '' ? ' : ' . $lieu : '')
             . (!empty($o['slot_label']) ? ' — ' . $o['slot_label'] : '');
    $frais = (float) ($o['delivery_fee_amount'] ?? 0);
    if ($frais > 0) $parts[] = 'Frais de livraison ' . erp_order_eur($frais) . ' € EN SUS (non repris dans les lignes)';
  } elseif (!empty($o['slot_label'])) {
    $parts[] = 'Retrait ' . $o['slot_label'];
  }
  $parts[] = $fam === 'stripe' ? 'Payé en ligne (Stripe)'
           : ($fam === 'deferred' ? 'Sur compte (facturation différée)' : 'À encaisser en boutique');
  if (!empty($o['voucher_code'])) {
    $parts[] = 'Bon ' . $o['voucher_code'] . ' (−' . erp_order_eur($o['voucher_discount'] ?? 0)
             . ' €) déjà déduit des lignes — redemption déjà comptée par le webshop';
  }
  if (!empty($o['note']))  $parts[] = 'Note client : ' . $o['note'];
  foreach ($compos as $c)  $parts[] = 'Compo ' . $c;
  foreach ($notesL as $nl) $parts[] = 'Note ' . $nl;
  foreach ($exclues as $x) $parts[] = 'NON REPRIS (produit inconnu de l\'ERP) : ' . $x;

  $charge = [
    'id_shop'          => (int) ($o['shop_id'] ?? 0),
    'comment'          => implode("\n", $parts),   // NOT NULL côté ERP — jamais null
    'pick_up_datetime' => erp_order_retrait_datetime($o['delivery_date'] ?? null, $o['slot_label'] ?? null),
    'id_order_status'  => 1,                       // « new » — le seul statut que le webshop pose
    'products'         => $products,
  ];
  if (!empty($o['customer_id'])) $charge['id_client'] = (int) $o['customer_id'];
  return $charge;
}

/* POST JSON sur l'API ERP — même idiome que erp_get() (erp_alias.php) : jeton
 * courant, et UNE reprise après reconnexion sur 401 (le jeton consultant
 * expire en 30 min). Rend ['code','json','raw'] ; ne jette jamais. */
function erp_post_json($chemin, array $corps) {
  $cfg = erp_cfg();
  if ($cfg['base'] === '') return ['code' => 0, 'json' => null, 'raw' => 'ERP non configuré'];
  $url = $cfg['base'] . '/' . ltrim((string) $chemin, '/');
  $tirer = static function ($tok) use ($url, $cfg, $corps) {
    $h = "Content-Type: application/json\r\nAccept: application/json\r\n";
    if ($tok !== '') $h .= 'Authorization: Bearer ' . $tok . "\r\n";
    // Écriture d'une commande : on s'accorde un délai plancher plus large que
    // les lectures de catalogue (6 s) — un timeout coupé APRÈS l'INSERT ERP
    // fabriquerait un doublon à la reprise.
    $ctx = stream_context_create(['http' => [
      'method' => 'POST', 'timeout' => max(8, (int) $cfg['timeout']), 'ignore_errors' => true,
      'header' => $h, 'content' => json_encode($corps, JSON_UNESCAPED_UNICODE),
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $ln) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ln, $m)) $code = (int) $m[1];
    return [$code, $raw];
  };
  [$code, $raw] = $tirer(function_exists('erp_token') ? erp_token() : (string) $cfg['token']);
  if ($code === 401 && function_exists('erp_token')) [$code, $raw] = $tirer(erp_token(true));
  $j = ($raw !== false && $raw !== null) ? json_decode((string) $raw, true) : null;
  return ['code' => $code, 'json' => is_array($j) ? $j : null, 'raw' => $raw === false ? '' : (string) $raw];
}

function erp_order_marquer_echec($orderId, $motif) {
  if (!col_exists('ws_orders', 'erp_push_error')) return;
  q("UPDATE ws_orders SET erp_push_error=? WHERE id=?", [mb_substr((string) $motif, 0, 400), (int) $orderId]);
}

/* Pousse UNE commande vers l'ERP. Idempotent (erp_order_id déjà posé = succès
 * immédiat), sous verrou GET_LOCK, et ne JETTE JAMAIS : true = la commande
 * existe côté ERP, false = pas poussée (inactif, déjà en cours ailleurs, ou
 * échec — alors posé sur erp_push_error pour la reprise cron). */
function erp_order_push($orderId) {
  $orderId = (int) $orderId;
  $verrou = 'ws_erp_push_' . $orderId;
  $tenu = false;
  try {
    if ($orderId <= 0 || !erp_orders_enabled()) return false;
    if (!col_exists('ws_orders', 'erp_order_id')) return false;   // migration 0104 pas passée
    $pris = row("SELECT GET_LOCK(?, 0) AS l", [$verrou]);
    if (!(int) ($pris['l'] ?? 0)) return false;   // un autre processus pousse déjà cette commande
    $tenu = true;

    $o = row("SELECT * FROM ws_orders WHERE id=?", [$orderId]);
    if (!$o) return false;
    if (!empty($o['erp_order_id'])) return true;                  // déjà née côté ERP
    if (($o['status'] ?? '') === 'cancelled') return false;       // jamais pousser une annulée
    if (empty($o['customer_id'])) {
      // Commande invitée HISTORIQUE (le parcours invité est supprimé) : l'ERP
      // accepterait un client anonyme, mais une commande sans id_client est
      // exactement ce que la bascule veut éviter — on marque, on ne pousse pas.
      erp_order_marquer_echec($orderId, 'commande sans client (invité historique) — pas de id_client');
      return false;
    }
    $lignes = rows("SELECT * FROM ws_order_lines WHERE order_id=? ORDER BY id", [$orderId]);
    $charge = erp_order_payload($o, $lignes);
    if (!count($charge['products'])) {
      erp_order_marquer_echec($orderId, 'aucune ligne exploitable (produits sans id ERP)');
      return false;
    }
    $r = erp_post_json('client-orders', $charge);
    $insere = (int) (($r['json']['inserted_id'] ?? 0));
    if ($r['code'] === 201 && $insere > 0) {
      q("UPDATE ws_orders SET erp_order_id=?, erp_push_error=NULL WHERE id=? AND erp_order_id IS NULL",
        [$insere, $orderId]);
      return true;
    }
    $motif = 'HTTP ' . $r['code'];
    $msg = (string) ($r['json']['message'] ?? ($r['json']['error'] ?? ''));
    if ($msg !== '') $motif .= ' — ' . $msg;
    erp_order_marquer_echec($orderId, $motif);
    if (function_exists('erp_notes')) erp_notes('remontée commande ' . ($o['order_ref'] ?? $orderId) . ' : ' . $motif);
    error_log('[ws] remontée ERP commande ' . $orderId . ' : ' . $motif);
    return false;
  } catch (Throwable $e) {
    error_log('[ws] remontée ERP commande ' . $orderId . ' : ' . $e->getMessage());
    try { erp_order_marquer_echec($orderId, mb_substr('exception : ' . $e->getMessage(), 0, 380)); } catch (Throwable $e2) {}
    return false;
  } finally {
    if ($tenu) { try { row("SELECT RELEASE_LOCK(?) AS r", [$verrou]); } catch (Throwable $e3) {} }
  }
}
