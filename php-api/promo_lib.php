<?php
/* promo_lib.php — logique « objectif d'achat cumulé → produit cadeau ».
 *
 * SANS EFFET DE BORD : uniquement des définitions de fonctions, la connexion PDO
 * est TOUJOURS passée en argument (jamais de db() global ici) → testable en
 * isolation avec un PDO SQLite en mémoire (voir tests/promo_cumulative_test.php).
 *
 * SQL volontairement portable (pas de fonction MySQL-only) pour tourner à
 * l'identique sur MySQL (prod) et SQLite (tests).
 *
 * Rappel d'architecture : le cumul se calcule sur ws_orders (commandes webshop) ;
 * les données de base (catalogue, boutiques, clients) restent maîtresses côté ERP
 * et ne sont que RÉFÉRENCÉES par id.
 */

/* Clé d'identité du « client » pour le cumul :
 *   - connecté  → 'client:{id}'  (agrégation sur ws_orders.customer_id)
 *   - invité    → 'guest:{email}' (agrégation sur ws_orders.guest_email, customer_id NULL)
 * Retourne null si aucune identité exploitable. */
function promo_customer_ref(?int $clientId, ?string $guestEmail): ?string {
  if ($clientId !== null && $clientId > 0) return 'client:' . $clientId;
  $email = strtolower(trim((string) $guestEmail));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) return 'guest:' . $email;
  return null;
}

/* Somme des commandes PAYÉES du client sur la période de la campagne.
 *   - payment_status = 'paid'      → exclut nativement 'refunded'/'failed'/'pending'
 *   - status <> 'cancelled'        → exclut les annulées
 *   - created_at ∈ [starts_at, ends_at] (bornes incluses)
 *   - id_shop de la campagne : NULL = réseau (toutes boutiques) ; sinon la boutique
 *   - portée du cumul : 'total' (défaut). 'per_day' n'est pas encore branché.
 * Retourne ['amount' => float, 'orders' => [['id'=>int,'amount'=>float], ...]].
 * Un remboursement/annulation postérieur fait mécaniquement baisser le cumul au
 * recalcul (la commande n'est plus 'paid'). */
function promo_accumulate(PDO $pdo, array $camp, ?int $clientId, ?string $guestEmail): array {
  $sql = "SELECT id, total FROM ws_orders
           WHERE payment_status = 'paid'
             AND status <> 'cancelled'
             AND created_at >= ? AND created_at <= ?";
  $params = [$camp['starts_at'], $camp['ends_at']];

  if ($camp['id_shop'] !== null && $camp['id_shop'] !== '') {
    $sql .= " AND shop_id = ?";
    $params[] = (int) $camp['id_shop'];
  }

  if ($clientId !== null && $clientId > 0) {
    $sql .= " AND customer_id = ?";
    $params[] = $clientId;
  } else {
    $sql .= " AND customer_id IS NULL AND LOWER(guest_email) = ?";
    $params[] = strtolower(trim((string) $guestEmail));
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $amount = 0.0; $orders = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $a = (float) $o['total'];
    $amount += $a;
    $orders[] = ['id' => (int) $o['id'], 'amount' => round($a, 2)];
  }
  return ['amount' => round($amount, 2), 'orders' => $orders];
}

/* Statut de la progression, sans effet de bord.
 *   inactive     → campagne désactivée
 *   not_started  → avant starts_at
 *   ended        → après ends_at
 *   unlocked     → dans la période ET cumul >= objectif
 *   in_progress  → dans la période, sous l'objectif
 * $nowStr : 'Y-m-d H:i:s' en Europe/Brussels (cf. promo_now). */
function promo_status(array $camp, float $amount, string $nowStr): string {
  if (empty($camp['is_active'])) return 'inactive';
  if ($nowStr < (string) $camp['starts_at']) return 'not_started';
  if ($nowStr > (string) $camp['ends_at'])   return 'ended';
  return ($amount + 1e-9 >= (float) $camp['threshold_amount']) ? 'unlocked' : 'in_progress';
}

/* Vrai si le voucher peut être attribué maintenant (période active + seuil atteint). */
function promo_is_unlockable(array $camp, float $amount, string $nowStr): bool {
  return promo_status($camp, $amount, $nowStr) === 'unlocked';
}

/* Horodatage courant en Europe/Brussels ('Y-m-d H:i:s'). Isolé pour être
 * injectable dans les tests (bornes TZ). */
function promo_now(?DateTimeImmutable $clock = null): string {
  $dt = $clock ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
  return $dt->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i:s');
}

/* Reste à dépenser avant déblocage (>= 0). */
function promo_remaining(array $camp, float $amount): float {
  return round(max(0.0, (float) $camp['threshold_amount'] - $amount), 2);
}

/* Normalise une date/heure d'entrée en 'Y-m-d H:i:s' (accepte 'Y-m-d',
 * 'Y-m-d H:i', 'Y-m-dTH:i'…). $defaultTime complète une date seule. */
function promo_norm_dt(string $s, string $defaultTime): string {
  $s = str_replace('T', ' ', trim($s));
  if ($s === '') return '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))            return $s . ' ' . $defaultTime;
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) return $s . ':00';
  return $s;
}

/* Valide + normalise l'entrée d'une campagne (endpoint admin). Pur, testable.
 * Retourne ['errors' => [codes], 'clean' => [colonnes prêtes à insérer]]. */
function promo_campaign_validate(array $in): array {
  $e = []; $c = [];
  $c['name'] = trim((string) ($in['name'] ?? ''));
  if ($c['name'] === '') $e[] = 'name_required';

  $c['id_shop'] = (isset($in['idShop']) && $in['idShop'] !== '' && $in['idShop'] !== null)
                  ? (int) $in['idShop'] : null;

  $re = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/';
  $rawStart = trim((string) ($in['startsAt'] ?? ''));
  $rawEnd   = trim((string) ($in['endsAt'] ?? ''));
  if (!preg_match($re, $rawStart)) $e[] = 'starts_at_invalid';
  if (!preg_match($re, $rawEnd))   $e[] = 'ends_at_invalid';
  $c['starts_at'] = promo_norm_dt($rawStart, '00:00:00');
  $c['ends_at']   = promo_norm_dt($rawEnd, '23:59:59');
  if ($c['starts_at'] !== '' && $c['ends_at'] !== '' && $c['starts_at'] > $c['ends_at'])
    $e[] = 'period_inverted';

  $c['threshold_amount'] = round((float) ($in['thresholdAmount'] ?? 0), 2);
  if ($c['threshold_amount'] <= 0) $e[] = 'threshold_invalid';

  $c['currency'] = strtoupper(trim((string) ($in['currency'] ?? 'EUR'))) ?: 'EUR';

  $scope = strtolower(trim((string) ($in['conditionScope'] ?? 'total'))) ?: 'total';
  if (!in_array($scope, ['total', 'per_day'], true)) $e[] = 'condition_scope_invalid';
  $c['condition_scope'] = $scope;

  $c['reward_product_id'] = (int) ($in['rewardProductId'] ?? 0);
  if ($c['reward_product_id'] <= 0) $e[] = 'reward_product_required';

  $rdd = trim((string) ($in['rewardDeliveryDate'] ?? ''));
  if ($rdd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rdd)) $e[] = 'reward_delivery_date_invalid';
  $c['reward_delivery_date'] = $rdd !== '' ? $rdd : null;

  $c['voucher_code_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '',
                                (string) ($in['voucherCodePrefix'] ?? 'GIFT')) ?: 'GIFT');
  $c['per_customer_limit'] = max(1, (int) ($in['perCustomerLimit'] ?? 1));
  $c['is_active'] = array_key_exists('isActive', $in) ? (!empty($in['isActive']) ? 1 : 0) : 1;

  return ['errors' => $e, 'clean' => $c];
}

/* Force le fuseau Europe/Brussels sur la connexion (le temps de la requête promo),
 * pour que les TIMESTAMP ws_orders.created_at se comparent aux bornes de campagne
 * en heure locale Brussels. Best-effort : nom de zone si les tables tz sont chargées,
 * sinon l'offset courant, sinon on laisse le tz serveur. Contenu à la requête promo
 * (PHP = 1 connexion/requête) → n'affecte aucune autre route. No-op sur SQLite. */
function promo_apply_tz(PDO $pdo, string $tz = 'Europe/Brussels'): void {
  try { $pdo->exec("SET time_zone = '" . str_replace("'", '', $tz) . "'"); return; }
  catch (Throwable $e) { /* tables tz absentes → offset courant */ }
  try {
    $off = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('P');   // ex. +02:00
    $pdo->exec("SET time_zone = '$off'");
  } catch (Throwable $e2) { /* laisse le tz serveur */ }
}

/* Le cadeau (déjà débloqué) est-il consommable maintenant, par ce client, sur cette
 * boutique ? $g = ligne jointe progression+campagne
 * (unlocked_at, redeemed_at, customer_ref, reward_delivery_date, id_shop).
 * Retourne ['ok'=>bool, 'reason'=>string]. Pur, testable. */
function promo_gift_redeemable(array $g, string $customerRef, int $shopId, string $nowStr): array {
  if (empty($g['unlocked_at']))                          return ['ok' => false, 'reason' => 'not_unlocked'];
  if (!empty($g['redeemed_at']))                         return ['ok' => false, 'reason' => 'already_redeemed'];
  if ((string) $g['customer_ref'] !== $customerRef)      return ['ok' => false, 'reason' => 'not_owner'];
  if (!empty($g['reward_delivery_date'])
      && $nowStr < ((string) $g['reward_delivery_date'] . ' 00:00:00'))
                                                         return ['ok' => false, 'reason' => 'before_delivery_date'];
  if ($g['id_shop'] !== null && $g['id_shop'] !== '' && (int) $g['id_shop'] !== $shopId)
                                                         return ['ok' => false, 'reason' => 'wrong_shop'];
  return ['ok' => true, 'reason' => 'ok'];
}

/* Génère un code cadeau : {PREFIX}-{campaignId}-{8 hex}. $rand injectable pour test. */
function promo_generate_code(array $camp, ?string $rand = null): string {
  $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($camp['voucher_code_prefix'] ?? 'GIFT')) ?: 'GIFT');
  $suffix = $rand ?? strtoupper(bin2hex(random_bytes(4)));
  return $prefix . '-' . (int) $camp['id'] . '-' . $suffix;
}
