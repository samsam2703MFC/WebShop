<?php
/* =====================================================================
   FIDÉLITÉ DANS LE WEBSHOP — port de l'app fidélité (dépôt latelier-by-pwa,
   public/api/loyalty.php + repo.php, docs/LOYALTY.md).
   ---------------------------------------------------------------------
   Le webshop (sa version PWA installée comme sa version desktop) montre
   désormais la fidélité du client lui-même : carte d'identification (QR du
   client_code), points et jauge vers la prochaine récompense, coefficients du
   jour, cartes produit à tampons, catalogue de récompenses et récompenses
   obtenues. MÊMES tables que l'app (base partagée), MÊMES règles :

     pwa_client_loyalty          solde de points + niveau (écrit par l'ERP/caisse)
     pwa_loyalty_tiers           les niveaux
     pwa_loyalty_config          ratio de gain + jauge points → bon (025/027)
     pwa_loyalty_multiplier      Happy Hour, week-end ×2… (points seulement)
     pwa_loyalty_card / _stamp / _reward   cartes à tampons (025)
     pwa_loyalty_reward_catalog  catalogue de récompenses au choix (028)
     pwa_loyalty_voucher         récompenses obtenues avec des points (027/028)

   Les fonctions sont un portage LIGNE À LIGNE de celles de l'app (préfixe
   loy_ au lieu de loyalty_/repo_loyalty_) : un même client voit exactement
   les mêmes chiffres dans l'app et dans le webshop. La synchronisation du
   carnet est idempotente (clé unique source/source_ref) : l'app et le webshop
   peuvent la jouer l'un après l'autre sans doublon.

   Aucune de ces tables n'est créée ici : elles appartiennent au schéma de
   l'app (migrations du dépôt latelier-by-pwa). Sans elles, loy_payload()
   répond { enabled:false } et le webshop garde son écran « bientôt ».
   ===================================================================== */

/** Une table de l'app existe-t-elle ? (mémorisé par requête) */
function loy_tbl($t) {
  static $c = [];
  if (!array_key_exists($t, $c)) {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 1"); $c[$t] = true; } catch (Throwable $e) { $c[$t] = false; }
  }
  return $c[$t];
}

/** Libellé produit normalisé (minuscules, sans accents, mots séparés d'une espace). */
function loy_norm($s) {
  $s = mb_strtolower((string) $s, 'UTF-8');
  $s = strtr($s, [
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
    'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
    'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
    'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
    'ý' => 'y', 'ÿ' => 'y', '’' => ' ', "'" => ' ',
  ]);
  $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
  return trim($s);
}

/** Une règle pwa_loyalty_multiplier s'applique-t-elle à l'instant $ts (dates, heures, jours) ? */
function loy_rule_active(array $r, $ts) {
  $hour = (int) date('G', $ts);
  $dow  = (int) date('N', $ts);   // 1=lun … 7=dim
  if (!empty($r['date_from']) && $ts < (int) strtotime((string) $r['date_from'])) return false;
  if (!empty($r['date_to'])   && $ts > (int) strtotime((string) $r['date_to']))   return false;
  if ($r['hour_from'] !== null && $r['hour_from'] !== '' && $hour < (int) $r['hour_from']) return false;
  if ($r['hour_to']   !== null && $r['hour_to']   !== '' && $hour > (int) $r['hour_to'])   return false;
  if (!empty($r['days'])) {
    $days = array_map('intval', array_filter(explode(',', (string) $r['days']), 'strlen'));
    if ($days && !in_array($dow, $days, true)) return false;
  }
  return true;
}

/** « ×2 » / « +1 » — même écriture que le panneau « Points bonus actifs » de l'app. */
function loy_rule_label(array $r) {
  $s = rtrim(rtrim(number_format((float) $r['value'], 2, '.', ''), '0'), '.');
  return ($r['kind'] === 'add' ? '+' : '×') . ($s === '' ? '0' : $s);
}

/** « 15h–17h » ou « ». */
function loy_hours_hint(array $r) {
  if ($r['hour_from'] !== null && $r['hour_from'] !== '' && $r['hour_to'] !== null && $r['hour_to'] !== '') {
    return (int) $r['hour_from'] . 'h–' . (int) $r['hour_to'] . 'h';
  }
  return '';
}

/** Créneau lisible : « sam · dim », « lun–ven 15h–17h », « 7h–10h », « » (toujours). */
function loy_sched_hint(array $r) {
  $short = ['', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
  $parts = [];
  if (!empty($r['days'])) {
    $d = array_values(array_unique(array_map('intval', array_filter(explode(',', (string) $r['days']), 'strlen'))));
    $d = array_values(array_filter($d, static fn($x) => $x >= 1 && $x <= 7));
    sort($d);
    if ($d && count($d) < 7) {
      $contig = count($d) > 2 && ($d[count($d) - 1] - $d[0] === count($d) - 1);
      $parts[] = $contig
        ? $short[$d[0]] . '–' . $short[$d[count($d) - 1]]
        : implode(' · ', array_map(static fn($x) => $short[$x], $d));
    }
  }
  $h = loy_hours_hint($r);
  if ($h !== '') $parts[] = $h;
  return implode(' ', $parts);
}

/** Règles de coefficient actives (points), filtrées sur la boutique. [] sans table. */
function loy_multiplier_rows($shopId = null) {
  if (!loy_tbl('pwa_loyalty_multiplier')) return [];
  $out = [];
  foreach (rows('SELECT id, name, kind, value, date_from, date_to, hour_from, hour_to, days, shop_id, icon_svg, active, sort_order
                   FROM pwa_loyalty_multiplier WHERE active = 1 ORDER BY sort_order, id') as $r) {
    // Portée boutique : shop_id NULL = partout, sinon doit correspondre.
    if ($r['shop_id'] !== null && $shopId !== null && (int) $r['shop_id'] !== (int) $shopId) continue;
    $out[] = $r;
  }
  return $out;
}

/** Ratio de gain + jauge (les trois formes successives du schéma de l'app). */
function loy_config() {
  $r = null;
  foreach ([
    'SELECT euros_per_point, points_per_step, active, voucher_points, voucher_value, voucher_label, voucher_validity_days, auto_voucher FROM pwa_loyalty_config WHERE id = 1 LIMIT 1',
    'SELECT euros_per_point, points_per_step, active, voucher_points, voucher_value, voucher_label, voucher_validity_days FROM pwa_loyalty_config WHERE id = 1 LIMIT 1',
    'SELECT euros_per_point, points_per_step, active, voucher_points, voucher_value, voucher_label FROM pwa_loyalty_config WHERE id = 1 LIMIT 1',
    'SELECT euros_per_point, points_per_step, active FROM pwa_loyalty_config WHERE id = 1 LIMIT 1',
  ] as $sql) {
    try { $r = row($sql); break; } catch (Throwable $e) { $r = null; }
  }
  if (!$r) {
    return ['eurosPerPoint' => 10.0, 'pointsPerStep' => 1, 'active' => true, 'voucherPoints' => 100, 'voucherValue' => 5.0,
            'voucherLabel' => null, 'voucherValidityDays' => 90, 'autoVoucher' => false];
  }
  return [
    'eurosPerPoint'       => (float) $r['euros_per_point'],
    'pointsPerStep'       => (int) $r['points_per_step'],
    'active'              => (bool) $r['active'],
    'voucherPoints'       => isset($r['voucher_points']) ? (int) $r['voucher_points'] : 100,
    'voucherValue'        => isset($r['voucher_value']) ? (float) $r['voucher_value'] : 5.0,
    'voucherLabel'        => $r['voucher_label'] ?? null,
    'voucherValidityDays' => isset($r['voucher_validity_days']) ? (int) $r['voucher_validity_days'] : 90,
    // Avant la migration 028 (pas de catalogue), le bon automatique reste le comportement.
    'autoVoucher'         => isset($r['auto_voucher']) ? (bool) $r['auto_voucher'] : true,
  ];
}

/** Points estimés pour un montant : ratio de base puis coefficients actifs maintenant. */
function loy_points_estimate($amount, $shopId = null) {
  $c = loy_config();
  if (!$c['active'] || $c['eurosPerPoint'] <= 0) return 0;
  $pts = (float) (floor($amount / $c['eurosPerPoint']) * $c['pointsPerStep']);
  $now = time();
  foreach (loy_multiplier_rows($shopId) as $r) {
    if (!loy_rule_active($r, $now)) continue;
    $pts = $r['kind'] === 'add' ? $pts + (float) $r['value'] : $pts * (float) $r['value'];
  }
  return (int) floor($pts);
}

/** Cartes actives (paramétrage) pour la boutique, mots-clés normalisés. [] sans table. */
function loy_card_defs($shopId = null) {
  if (!loy_tbl('pwa_loyalty_card')) return [];
  $out = [];
  foreach (rows('SELECT * FROM pwa_loyalty_card WHERE active = 1 ORDER BY sort_order, id') as $r) {
    if ($r['shop_id'] !== null && $shopId !== null && (int) $r['shop_id'] !== (int) $shopId) continue;
    $r['keywords']  = array_values(array_filter(array_map('loy_norm', explode(',', (string) $r['match_keywords'])), 'strlen'));
    $r['cafe_cats'] = array_values(array_filter(array_map('trim', explode(',', (string) ($r['cafe_cat_codes'] ?? ''))), 'strlen'));
    $out[] = $r;
  }
  return $out;
}

/** La carte d'une ligne d'achat : catégorie café d'abord, sinon le mot-clé LE PLUS LONG. */
function loy_card_for(array $cards, $label, $cafeCat = null) {
  if ($cafeCat !== null && $cafeCat !== '') {
    foreach ($cards as $c) if (in_array($cafeCat, $c['cafe_cats'], true)) return $c;
  }
  $n = ' ' . loy_norm($label) . ' ';
  $best = null; $bestLen = 0;
  foreach ($cards as $c) {
    foreach ($c['keywords'] as $kw) {
      if (strlen($kw) > $bestLen && strpos($n, ' ' . $kw . ' ') !== false) { $best = $c; $bestLen = strlen($kw); }
    }
  }
  return $best;
}

/** Code court et unique (sans 0/O/1/I) : « PA-7K3Q », « BON-7K3Q »… */
function loy_short_code($table, $col, $prefix) {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for ($i = 0; $i < 12; $i++) {
    $s = '';
    for ($j = 0; $j < 4; $j++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    if (!row("SELECT 1 x FROM `$table` WHERE `$col` = ? LIMIT 1", [$prefix . '-' . $s])) return $prefix . '-' . $s;
  }
  return $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Synchronise le carnet du client depuis ses achats (idempotent) — un tampon
 * par unité achetée, sans coefficient — puis transforme chaque série complète
 * en récompense et périme les récompenses échues. Tout ou rien.
 */
function loy_cards_sync($clientId, array $cards) {
  if (!$cards || !loy_tbl('pwa_loyalty_stamp') || !loy_tbl('pwa_loyalty_reward')) return;
  $pdo = db();
  $items = [];
  if (loy_tbl('pwa_purchase_items') && loy_tbl('pwa_purchases')) {
    foreach (rows('SELECT pi.id, pi.qty, pi.name, COALESCE(p.occurred_at, p.created_at) AS at
                     FROM pwa_purchase_items pi JOIN pwa_purchases p ON p.id = pi.purchase_id
                    WHERE p.client_id = ?', [$clientId]) as $r) {
      $items[] = ['src' => 'purchase', 'ref' => 'pi:' . $r['id'], 'qty' => (int) $r['qty'], 'name' => (string) $r['name'], 'at' => $r['at'], 'cat' => null];
    }
  }
  // Commandes café PAYÉES passées dans l'app (catégorie produit connue).
  try {
    foreach (rows("SELECT coi.id, coi.qty, coi.name, cp.cat_code, co.created_at AS at
                     FROM pwa_cafe_order_items coi
                     JOIN pwa_cafe_orders co ON co.id = coi.cafe_order_id
                LEFT JOIN pwa_cafe_products cp ON cp.id = coi.product_ref AND coi.item_type = 'prod'
                    WHERE co.client_id = ? AND co.status = 'paid'", [$clientId]) as $r) {
      $items[] = ['src' => 'cafe', 'ref' => 'coi:' . $r['id'], 'qty' => (int) $r['qty'], 'name' => (string) $r['name'], 'at' => $r['at'], 'cat' => $r['cat_code'] ?? null];
    }
  } catch (Throwable $e) { /* tables café absentes : rien à rejouer de ce côté */ }

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare('INSERT IGNORE INTO pwa_loyalty_stamp (client_id, card_id, source, source_ref, occurred_at, item_label)
                          VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($items as $it) {
      $card = loy_card_for($cards, $it['name'], $it['cat']);
      if (!$card) continue;
      $ts = $it['at'] ? (int) strtotime((string) $it['at']) : 0;
      if ($ts <= 0) $ts = time();
      $at = date('Y-m-d H:i:s', $ts);
      $label = mb_substr($it['name'], 0, 160);
      $qty = max(1, min(50, $it['qty']));
      for ($n = 1; $n <= $qty; $n++) {   // un tampon par unité, quel que soit le jour
        $ins->execute([$clientId, (int) $card['id'], $it['src'], $it['ref'] . ':' . $n, $at, $label]);
      }
    }
    // Série complète → récompense. FOR UPDATE : si l'app synchronise le même
    // client au même instant, la seconde transaction attend la première et ne
    // consomme pas deux fois les mêmes tampons.
    $cnt  = $pdo->prepare('SELECT COUNT(*) FROM pwa_loyalty_stamp WHERE client_id = ? AND card_id = ? AND reward_id IS NULL');
    $old  = $pdo->prepare('SELECT id, occurred_at FROM pwa_loyalty_stamp WHERE client_id = ? AND card_id = ? AND reward_id IS NULL ORDER BY occurred_at, id LIMIT ? FOR UPDATE');
    $insR = $pdo->prepare('INSERT INTO pwa_loyalty_reward (client_id, card_id, reward_code, status, earned_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($cards as $card) {
      $need = max(1, (int) $card['stamps_required']);
      for ($guard = 0; $guard < 20; $guard++) {
        $cnt->execute([$clientId, (int) $card['id']]);
        if ((int) $cnt->fetchColumn() < $need) break;
        $old->bindValue(1, $clientId, PDO::PARAM_INT);
        $old->bindValue(2, (int) $card['id'], PDO::PARAM_INT);
        $old->bindValue(3, $need, PDO::PARAM_INT);
        $old->execute();
        $rs = $old->fetchAll();
        if (count($rs) < $need) break;
        $earnedAt = (string) $rs[count($rs) - 1]['occurred_at'];
        $days = (int) $card['reward_validity_days'];
        $expires = $days > 0 ? date('Y-m-d H:i:s', (int) strtotime($earnedAt) + $days * 86400) : null;
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', (string) $card['card_code']) ?: 'CF', 0, 2));
        $insR->execute([$clientId, (int) $card['id'], loy_short_code('pwa_loyalty_reward', 'reward_code', $prefix), 'ready', $earnedAt, $expires]);
        $rewardId = (int) $pdo->lastInsertId();
        $ids = array_map(static fn($x) => (int) $x['id'], $rs);
        $pdo->exec('UPDATE pwa_loyalty_stamp SET reward_id = ' . $rewardId . ' WHERE id IN (' . implode(',', $ids) . ')');
      }
    }
    $pdo->prepare("UPDATE pwa_loyalty_reward SET status = 'expired' WHERE client_id = ? AND status = 'ready' AND expires_at IS NOT NULL AND expires_at < NOW()")
        ->execute([$clientId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Catalogue actif pour la boutique, du moins cher au plus cher (028). */
function loy_reward_catalog($shopId = null) {
  if (!loy_tbl('pwa_loyalty_reward_catalog')) return [];
  $plans = loy_tbl('pwa_subscription_plans');
  $out = [];
  foreach (rows('SELECT c.*' . ($plans ? ', p.name AS plan_name, p.quota AS plan_quota, p.img AS plan_img' : ', NULL AS plan_name, NULL AS plan_quota, NULL AS plan_img') . '
                   FROM pwa_loyalty_reward_catalog c' . ($plans ? ' LEFT JOIN pwa_subscription_plans p ON p.id = c.plan_id' : '') . '
                  WHERE c.active = 1 ORDER BY c.points_cost, c.sort_order, c.id') as $r) {
    if ($r['shop_id'] !== null && $shopId !== null && (int) $r['shop_id'] !== (int) $shopId) continue;
    if ($r['kind'] === 'subscription' && empty($r['plan_name'])) continue;   // formule absente : on ne propose pas
    $out[] = $r;
  }
  return $out;
}

/**
 * Échange de points contre une récompense du catalogue — même transaction que
 * l'app : déduction conditionnelle (jamais de solde négatif) ; un abonnement
 * offert crée la souscription (prix zéro, durée du catalogue).
 */
function loy_claim_reward($clientId, $catalogId, $shopId = null) {
  if (!loy_tbl('pwa_loyalty_reward_catalog') || !loy_tbl('pwa_loyalty_voucher') || !loy_tbl('pwa_client_loyalty')) {
    return ['ok' => false, 'error' => 'Le catalogue de récompenses n’est pas encore disponible.', 'code' => 501];
  }
  $item = null;
  foreach (loy_reward_catalog($shopId) as $c) if ((int) $c['id'] === (int) $catalogId) { $item = $c; break; }
  if (!$item) return ['ok' => false, 'error' => 'Récompense introuvable.', 'code' => 404];
  $cost = max(1, (int) $item['points_cost']);
  $balance = (int) (row('SELECT points_balance FROM pwa_client_loyalty WHERE client_id = ? LIMIT 1', [$clientId])['points_balance'] ?? 0);
  if ($balance < $cost) {
    $d = $cost - $balance;
    return ['ok' => false, 'error' => 'Il vous manque ' . $d . ' point' . ($d > 1 ? 's' : '') . ' pour cette récompense.', 'code' => 400];
  }
  $kind = (string) $item['kind'];
  $prefix = $kind === 'voucher' ? 'BON' : ($kind === 'product' ? 'PRD' : 'ABO');
  $days = $kind === 'subscription' ? max(1, (int) $item['duration_days']) : (int) $item['validity_days'];
  $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $dec = $pdo->prepare('UPDATE pwa_client_loyalty SET points_balance = points_balance - ? WHERE client_id = ? AND points_balance >= ?');
    $dec->execute([$cost, $clientId, $cost]);
    if ($dec->rowCount() !== 1) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Solde de points insuffisant.', 'code' => 400]; }
    $pdo->prepare('INSERT INTO pwa_loyalty_voucher (client_id, voucher_code, kind, catalog_id, label, value, points_spent, status, issued_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
        ->execute([$clientId, loy_short_code('pwa_loyalty_voucher', 'voucher_code', $prefix), $kind, (int) $item['id'], (string) $item['name'],
                   (float) $item['value'], $cost, $kind === 'subscription' ? 'active' : 'available', $expires]);
    $rewardId = (int) $pdo->lastInsertId();
    if ($kind === 'subscription') {
      $until = $expires ? date('d/m/Y', strtotime($expires)) : '';
      $pdo->prepare('INSERT INTO pwa_subscriptions (sub_code, client_id, plan_id, name, used, total, unit_word, unit, img, period_label, renew_label, status)
                     VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)')
          ->execute(['loy_' . $rewardId, $clientId, (int) $item['plan_id'], (string) $item['plan_name'], (int) $item['plan_quota'],
                     (string) ($item['unit_word'] ?: 'unités'), (string) ($item['unit'] ?: 'unité'), (string) ($item['plan_img'] ?: ($item['img'] ?? '')),
                     'offert · ' . $days . ' jours', 'Offert avec vos points' . ($until ? ' · jusqu’au ' . $until : ''), 'active']);
      $pdo->prepare('UPDATE pwa_loyalty_voucher SET subscription_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $rewardId]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ws] échange fidélité refusé : ' . $e->getMessage());
    return ['ok' => false, 'error' => 'L’échange n’a pas pu être enregistré.', 'code' => 500];
  }
  return ['ok' => true, 'rewardId' => $rewardId];
}

/**
 * Déclencheur de points (bon automatique, 027) et échéances — identique à
 * l'app : rejoué à chaque lecture, inerte sans la table des bons.
 */
function loy_vouchers_issue($clientId, array $cfg) {
  if (!loy_tbl('pwa_loyalty_voucher')) return;
  try {
    foreach (rows("SELECT id, subscription_id FROM pwa_loyalty_voucher WHERE client_id = ? AND status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()", [$clientId]) as $r) {
      if (!empty($r['subscription_id'])) q("UPDATE pwa_subscriptions SET status = 'cancelled' WHERE id = ?", [(int) $r['subscription_id']]);
      q("UPDATE pwa_loyalty_voucher SET status = 'expired' WHERE id = ?", [(int) $r['id']]);
    }
  } catch (Throwable $e) { /* colonnes 028 absentes : rien à faire ici */ }
  $expire = "UPDATE pwa_loyalty_voucher SET status = 'expired' WHERE client_id = ? AND status = 'available' AND expires_at IS NOT NULL AND expires_at < NOW()";
  if (empty($cfg['active']) || empty($cfg['autoVoucher']) || !loy_tbl('pwa_client_loyalty')) { q($expire, [$clientId]); return; }
  $vp = max(1, (int) $cfg['voucherPoints']);
  $value = (float) $cfg['voucherValue'];
  $days = (int) ($cfg['voucherValidityDays'] ?? 90);
  if ($value <= 0) return;
  $pdo = db();
  for ($guard = 0; $guard < 20; $guard++) {
    $pdo->beginTransaction();
    try {
      $dec = $pdo->prepare('UPDATE pwa_client_loyalty SET points_balance = points_balance - ? WHERE client_id = ? AND points_balance >= ?');
      $dec->execute([$vp, $clientId, $vp]);
      if ($dec->rowCount() !== 1) { $pdo->rollBack(); break; }
      $exp = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
      $pdo->prepare('INSERT INTO pwa_loyalty_voucher (client_id, voucher_code, value, points_spent, status, issued_at, expires_at) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
          ->execute([$clientId, loy_short_code('pwa_loyalty_voucher', 'voucher_code', 'BON'), $value, $vp, 'available', $exp]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      break;
    }
  }
  q($expire, [$clientId]);
}

/** Les récompenses obtenues avec des points, disponibles d'abord. [] sans table. */
function loy_vouchers($clientId) {
  if (!loy_tbl('pwa_loyalty_voucher')) return [];
  $base = 'SELECT id, voucher_code, value, points_spent, status, issued_at, expires_at, used_at';
  try {
    $rs = rows($base . ", kind, label, subscription_id FROM pwa_loyalty_voucher WHERE client_id = ? ORDER BY (status IN ('available','active')) DESC, issued_at DESC, id DESC", [$clientId]);
  } catch (Throwable $e) {
    $rs = rows($base . " FROM pwa_loyalty_voucher WHERE client_id = ? ORDER BY (status = 'available') DESC, issued_at DESC, id DESC", [$clientId]);
  }
  return array_map(static fn($v) => [
    'id'          => (int) $v['id'],
    'code'        => (string) $v['voucher_code'],
    'kind'        => (string) ($v['kind'] ?? 'voucher'),
    'label'       => $v['label'] ?? null,
    'value'       => (float) $v['value'],
    'pointsSpent' => (int) $v['points_spent'],
    'status'      => (string) $v['status'],
    'issuedAt'    => $v['issued_at'],
    'expiresAt'   => $v['expires_at'],
    'usedAt'      => $v['used_at'],
  ], $rs);
}

/** Image de l'app (chemin relatif à la PWA) → URL absolue servie par la PWA. */
function loy_img($path) {
  $path = trim((string) $path);
  if ($path === '' || preg_match('#^(https?:)?//#i', $path) || strpos($path, 'data:') === 0) return $path;
  return rtrim(pwa_url(), '/') . '/' . ltrim($path, '/');
}

/**
 * Charge utile de l'écran Fidélité du webshop. Même forme que
 * bootstrap.loyaltyCards de l'app, plus `member` (la carte d'identification
 * à présenter en caisse) et `appUrl`. { enabled:false } sans tables.
 */
function loy_payload($clientId, $shopId = null) {
  $hasAny = loy_tbl('pwa_client_loyalty') || loy_tbl('pwa_loyalty_card') || loy_tbl('pwa_loyalty_config');
  if (!$hasAny) return ['enabled' => false];

  $c = row('SELECT * FROM client WHERE id = ? LIMIT 1', [$clientId]) ?: [];   // client_code absent d'une base non migrée
  $member = [
    'code' => isset($c['client_code']) && $c['client_code'] !== '' ? (string) $c['client_code'] : null,
    'name' => trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? '')),
    'tier' => null, 'perk' => null, 'nextTier' => null,
    // Niveau suivant (l'icône croissant de l'app) : visites restantes sur la période.
    'visits' => null, 'visitsTarget' => null, 'visitsRemaining' => null, 'period' => null,
  ];
  if (loy_tbl('pwa_client_loyalty') && loy_tbl('pwa_loyalty_tiers')) {
    try {
      $t = row('SELECT t.name AS tier, t.perk_label AS perk, nt.name AS next_tier,
                       cl.visits_current, cl.visits_target, cl.period_label
                  FROM pwa_client_loyalty cl
                  JOIN pwa_loyalty_tiers t ON t.id = cl.tier_id
             LEFT JOIN pwa_loyalty_tiers nt ON nt.id = cl.next_tier_id
                 WHERE cl.client_id = ? LIMIT 1', [$clientId]);
      if ($t) {
        $member['tier'] = $t['tier']; $member['perk'] = $t['perk']; $member['nextTier'] = $t['next_tier'];
        $member['visits'] = (int) $t['visits_current']; $member['visitsTarget'] = (int) $t['visits_target'];
        $member['visitsRemaining'] = max(0, (int) $t['visits_target'] - (int) $t['visits_current']);
        $member['period'] = $t['period_label'];
      }
    } catch (Throwable $e) { /* niveaux absents : la carte reste valable sans */ }
  }

  $cards = loy_card_defs($shopId);
  try { loy_cards_sync($clientId, $cards); }
  catch (Throwable $e) { error_log('[ws] synchro carnet fidélité : ' . $e->getMessage()); }

  $cfg = loy_config();
  try { loy_vouchers_issue($clientId, $cfg); } catch (Throwable $e) { error_log('[ws] bons fidélité : ' . $e->getMessage()); }
  $balance = loy_tbl('pwa_client_loyalty')
    ? max(0, (int) (row('SELECT points_balance FROM pwa_client_loyalty WHERE client_id = ? LIMIT 1', [$clientId])['points_balance'] ?? 0))
    : 0;
  $vp = max(1, (int) $cfg['voucherPoints']);
  $vouchers = loy_vouchers($clientId);
  $progress = loy_tbl('pwa_loyalty_voucher') ? min($balance, $vp) : ($balance % $vp);
  $available = count(array_filter($vouchers, static fn($v) => in_array($v['status'], ['available', 'active'], true)));
  $catalog = array_map(static fn($r) => [
    'id'           => (int) $r['id'],
    'code'         => (string) $r['reward_code'],
    'kind'         => (string) $r['kind'],
    'name'         => (string) $r['name'],
    'description'  => $r['description'],
    'img'          => loy_img($r['img'] ?? ''),
    'pointsCost'   => (int) $r['points_cost'],
    'value'        => (float) $r['value'],
    'planName'     => $r['plan_name'] ?? null,
    'durationDays' => (int) $r['duration_days'],
    'validityDays' => (int) $r['validity_days'],
    'affordable'   => $balance >= (int) $r['points_cost'],
  ], loy_reward_catalog($shopId));
  $affordable = count(array_filter($catalog, static fn($x) => $x['affordable']));
  $next = null;
  foreach ($catalog as $x) if (!$x['affordable']) { $next = ['id' => $x['id'], 'name' => $x['name'], 'pointsCost' => $x['pointsCost']]; break; }

  $now = time();
  $rules = array_map(static fn($r) => [
    'id'        => (int) $r['id'],
    'name'      => (string) $r['name'],
    'kind'      => (string) $r['kind'],
    'label'     => loy_rule_label($r),
    'hint'      => loy_sched_hint($r),
    'hours'     => loy_hours_hint($r),
    'activeNow' => loy_rule_active($r, $now),
  ], loy_multiplier_rows($shopId));
  $today = array_values(array_filter($rules, static fn($r) => $r['activeNow']));

  $out = [];
  if ($cards && loy_tbl('pwa_loyalty_stamp')) {
    foreach ($cards as $cd) {
      $need = max(1, (int) $cd['stamps_required']);
      $stamps = array_map(static fn($s) => ['date' => substr((string) $s['occurred_at'], 0, 10), 'label' => $s['item_label']],
        rows('SELECT occurred_at, item_label FROM pwa_loyalty_stamp WHERE client_id = ? AND card_id = ? AND reward_id IS NULL ORDER BY occurred_at, id',
             [$clientId, (int) $cd['id']]));
      $count = min(count($stamps), $need);
      $rw = loy_tbl('pwa_loyalty_reward')
        ? row("SELECT id, reward_code, earned_at, expires_at FROM pwa_loyalty_reward WHERE client_id = ? AND card_id = ? AND status = 'ready' ORDER BY earned_at, id LIMIT 1",
              [$clientId, (int) $cd['id']])
        : null;
      $out[] = [
        'code'         => (string) $cd['card_code'],
        'name'         => (string) $cd['name'],
        'img'          => loy_img($cd['img']),
        'colors'       => ['tint' => (string) $cd['color_tint'], 'fill' => (string) $cd['color_fill'], 'accent' => (string) $cd['color_accent']],
        'required'     => $need,
        'count'        => $count,
        'remaining'    => max(0, $need - $count),
        'pct'          => (int) round($count / $need * 100),
        'rewardLabel'  => (string) $cd['reward_label'],
        'validityDays' => (int) $cd['reward_validity_days'],
        'stamps'       => array_slice($stamps, 0, $need),
        'reward'       => $rw ? ['id' => (int) $rw['id'], 'code' => (string) $rw['reward_code'],
                                 'earnedAt' => $rw['earned_at'], 'expiresAt' => $rw['expires_at']] : null,
      ];
    }
  }

  return [
    'enabled' => true,
    'member'  => $member,
    'appUrl'  => pwa_url(),
    'points'  => [
      'balance'             => $balance,
      'progress'            => $progress,
      'voucherPoints'       => $vp,
      'voucherValue'        => (float) $cfg['voucherValue'],
      'voucherLabel'        => $cfg['voucherLabel'],
      'remaining'           => $vp - $progress,
      'pct'                 => (int) round($progress / $vp * 100),
      'eurosPerPoint'       => (float) $cfg['eurosPerPoint'],
      'voucherValidityDays' => (int) ($cfg['voucherValidityDays'] ?? 90),
      'vouchersAvailable'   => $available,
      'vouchersTotal'       => count($vouchers),
      'autoVoucher'         => (bool) ($cfg['autoVoucher'] ?? false),
      'affordableCount'     => $affordable,
      'nextReward'          => $next,
    ],
    'today'    => $today,
    'rules'    => $rules,
    'cards'    => $out,
    'vouchers' => $vouchers,
    'catalog'  => $catalog,
  ];
}
