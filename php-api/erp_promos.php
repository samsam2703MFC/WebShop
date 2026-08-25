<?php
/* ============================================================================
 * PROMOTIONS « x achetés → y offert(s) » servies par l'ERP.
 *
 *   GET {base}/admin/promotions/buy-x-get-y
 *
 * Pourquoi un fichier à part : ces routes sont sous /admin/ et exigent un
 * jeton ADMIN. Le jeton d'intégration du webshop est un jeton CONSULTANT
 * (perms: consultant.system_config_manage) — vérifié : 200 sur products et
 * recipes, 403 sur /admin/*. Il faut donc une seconde identité, posée dans
 * ws_param.erp_admin_phone / erp_admin_password. Absente → cette source est
 * inerte et la règle locale (ws_pricing_rules) continue de s'appliquer.
 *
 * RÈGLE D'OR : la MÊME fonction sert l'affichage (/pricing/promos/cross-portion)
 * et la FACTURATION (POST /orders). Deux résolutions séparées finiraient par
 * diverger, et le client verrait une économie qu'on ne lui débiterait pas.
 *
 * CE QU'ON REFUSE, et pourquoi :
 *  - une récompense qui n'est pas « 100 % de remise » : notre modèle ne sait
 *    dire que « offert ». Une promo à -50 % serait affichée comme un cadeau ;
 *  - une promo hors de sa fenêtre de validité, ou hors de cette boutique ;
 *  - une réponse illisible : on rend null et la règle locale reprend la main,
 *    au lieu d'annoncer une promo dont on ne sait rien.
 * ========================================================================== */

/* Jeton ADMIN (identité distincte du jeton d'intégration). Mis en cache
 * disque avec 60 s de marge, comme erp_token(). */
function erp_admin_token($force = false) {
  static $mem = null;
  if (!$force && $mem !== null) return $mem;
  $cfg = function_exists('erp_cfg') ? erp_cfg() : ['base' => ''];
  if ($cfg['base'] === '' || !function_exists('ws_param')) return $mem = '';
  $tel = (string) (ws_param('erp_admin_phone', '') ?: '');
  $mdp = (string) (ws_param('erp_admin_password', '') ?: '');
  if ($tel === '' || $mdp === '') return $mem = '';

  $f = sys_get_temp_dir() . '/ws_erp_adm_' . sha1($cfg['base'] . '|' . $tel) . '.json';
  if (!$force && is_file($f)) {
    $c = json_decode((string) @file_get_contents($f), true);
    if (is_array($c) && ($c['exp'] ?? 0) > time() && ($c['tok'] ?? '') !== '') return $mem = (string) $c['tok'];
  }
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => (int) ($cfg['timeout'] ?? 6) ?: 6, 'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode(['phone' => $tel, 'password' => $mdp]),
  ]]);
  $raw = @file_get_contents($cfg['base'] . '/admin/auth/login', false, $ctx);
  $d = ($raw !== false) ? json_decode($raw, true) : null;
  $tok = is_array($d) ? (string) ($d['access_token'] ?? '') : '';
  if ($tok === '') { if (function_exists('erp_notes')) erp_notes('ERP : connexion admin refusée'); return $mem = ''; }
  @file_put_contents($f, json_encode(['tok' => $tok, 'exp' => time() + max(120, (int) ($d['expires_in'] ?? 1800)) - 60]));
  @chmod($f, 0600);
  return $mem = $tok;
}

/* GET sur une route /admin/ — jeton admin, un rejeu sur 401. */
function erp_admin_get($path, $ttl = 120) {
  $cfg = function_exists('erp_cfg') ? erp_cfg() : ['base' => ''];
  if ($cfg['base'] === '') return null;
  $tok = erp_admin_token();
  if ($tok === '') return null;
  $url = $cfg['base'] . '/' . ltrim($path, '/');
  $file = sys_get_temp_dir() . '/ws_erpadm_' . sha1($url) . '.json';
  if ($ttl > 0 && is_file($file) && (time() - filemtime($file)) < $ttl) {
    $c = json_decode((string) @file_get_contents($file), true);
    if (is_array($c)) return $c;
  }
  $tirer = static function ($t) use ($url, $cfg) {
    $ctx = stream_context_create(['http' => [
      'method' => 'GET', 'timeout' => (int) ($cfg['timeout'] ?? 6) ?: 6, 'ignore_errors' => true,
      'header' => "Accept: application/json\r\nAuthorization: Bearer $t\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
    return [$raw, $code];
  };
  [$raw, $code] = $tirer($tok);
  if ($code === 401) [$raw, $code] = $tirer(erp_admin_token(true));
  if ($raw === false || $code >= 400) {
    if (function_exists('erp_notes')) erp_notes('ERP admin HTTP ' . $code . ' sur ' . $path);
    return null;
  }
  $d = json_decode($raw, true);
  if (!is_array($d)) return null;
  if ($ttl > 0) @file_put_contents($file, json_encode($d));
  return $d;
}

/* La promo « x achetés → y offert(s) » applicable À CETTE BOUTIQUE, ou null.
 * Forme rendue (celle que le front et la facturation consomment déjà) :
 *   ['buy','free','threshold','label','productIds','categoryIds','excluded','source'] */
function erp_cross_portion_rule($shopId) {
  static $cache = [];
  $shopId = (int) $shopId;
  if (array_key_exists($shopId, $cache)) return $cache[$shopId];
  $d = erp_admin_get('admin/promotions/buy-x-get-y', 120);
  $lst = is_array($d) ? (array_is_list($d) ? $d : ($d['items'] ?? $d['data'] ?? null)) : null;
  if (!is_array($lst)) return $cache[$shopId] = null;

  $now = time();
  $retenues = [];
  foreach ($lst as $p) {
    if (!is_array($p)) continue;
    if (strtoupper((string) ($p['status'] ?? '')) !== 'ACTIVE') continue;
    // Fenêtre de validité (null = toujours).
    foreach ([['valid_from', 1], ['valid_to', -1]] as [$k, $sens]) {
      if (!empty($p[$k])) {
        $t = strtotime((string) $p[$k]);
        if ($t && (($sens > 0 && $now < $t) || ($sens < 0 && $now > $t))) continue 2;
      }
    }
    // Portée boutique.
    $scope = strtoupper((string) ($p['shop_scope_type'] ?? 'ALL_SHOPS'));
    if ($scope !== 'ALL_SHOPS') {
      $ids = array_map('intval', (array) ($p['shop_ids'] ?? []));
      if (!in_array($shopId, $ids, true)) continue;
    }
    $retenues[] = $p;
  }
  if (!$retenues) return $cache[$shopId] = null;
  // Priorité la plus haute d'abord (l'ERP la porte explicitement).
  usort($retenues, fn ($a, $b) => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));
  $p = $retenues[0];

  // Détail : la liste ne porte que des compteurs, le périmètre est sur la fiche.
  $det = erp_admin_get('admin/promotions/buy-x-get-y/' . (int) $p['id'], 120);
  if (is_array($det) && isset($det['data']) && is_array($det['data'])) $det = $det['data'];
  if (!is_array($det) || !isset($det['trigger'], $det['reward'])) return $cache[$shopId] = null;

  $rw = $det['reward'];
  // « Offert » et rien d'autre : notre modèle ne sait pas dire « -50 % ».
  if (strtoupper((string) ($rw['type'] ?? '')) !== 'PERCENTAGE_DISCOUNT'
      || abs(((float) ($rw['value'] ?? 0)) - 100.0) > 0.001) {
    if (function_exists('erp_notes'))
      erp_notes('ERP promo « ' . ($p['name'] ?? '?') . ' » ignorée : récompense non « offert » ('
                . ($rw['type'] ?? '?') . ' ' . ($rw['value'] ?? '?') . ')');
    return $cache[$shopId] = null;
  }
  $buy  = (int) ($det['trigger']['quantity'] ?? 0);
  $free = (int) ($rw['quantity'] ?? 0);
  if ($buy < 1 || $free < 1) return $cache[$shopId] = null;

  return $cache[$shopId] = [
    'buy' => $buy, 'free' => $free, 'threshold' => $buy,
    'label' => (string) ($p['name'] ?? ($buy . ' achetés → ' . $free . ' offert(s)')),
    // Périmètre : c'est LUI qui décide quelles lignes du panier comptent.
    'productIds'  => array_map('intval', (array) ($det['trigger']['product_ids'] ?? [])),
    'categoryIds' => array_map('intval', (array) ($det['trigger']['category_ids'] ?? [])),
    'excluded'    => array_map('intval', (array) ($det['trigger']['excluded_product_ids'] ?? [])),
    'source' => 'erp',
  ];
}

/* La source des promos est-elle l'ERP ? ws_param.promos_source = 'erp'. */
function erp_promos_enabled() {
  if (!function_exists('ws_param')) return false;
  try { return strtolower((string) (ws_param('promos_source', '') ?: '')) === 'erp'; }
  catch (Throwable $e) { return false; }
}

/* RÉSOLVEUR UNIQUE — appelé par l'affichage ET par la facturation.
 * ERP si activé et lisible, sinon ws_pricing_rules. Rend null si aucune. */
function cross_portion_rule($shopId) {
  if (erp_promos_enabled()) {
    $r = erp_cross_portion_rule($shopId);
    if ($r) return $r;
    // ERP activé mais muet : on ne retombe PAS en silence sur une autre règle
    // — deux promos différentes selon l'humeur du réseau serait pire que rien.
    if (function_exists('erp_notes')) erp_notes('ERP promos activé mais aucune règle exploitable');
    return null;
  }
  try {
    $r = row("SELECT x AS buy, y AS free, threshold, label FROM ws_pricing_rules
               WHERE rule_type='cross_portion' AND active=1 AND (shop_id=? OR shop_id IS NULL)
               ORDER BY shop_id IS NULL LIMIT 1", [(int) $shopId]);
  } catch (Throwable $e) { return null; }
  if (!$r) return null;
  return ['buy' => (int) $r['buy'], 'free' => (int) $r['free'], 'threshold' => (int) $r['threshold'],
          'label' => (string) $r['label'], 'productIds' => [], 'categoryIds' => [], 'excluded' => [],
          'source' => 'local'];
}

/* Ce produit est-il ÉLIGIBLE à la promo ? Sans périmètre ERP (règle locale),
 * on garde le drapeau ws_products.cross_portion — c'est la règle historique. */
function cross_portion_eligible(array $regle, $productId, $catId, $subCatId, $flagLocal) {
  if (($regle['source'] ?? '') !== 'erp') return (bool) $flagLocal;
  $pid = (int) $productId;
  if (in_array($pid, $regle['excluded'], true)) return false;
  if ($regle['productIds'] && in_array($pid, $regle['productIds'], true)) return true;
  if ($regle['categoryIds']) {
    foreach ([(int) $catId, (int) $subCatId] as $c) if ($c && in_array($c, $regle['categoryIds'], true)) return true;
  }
  return false;
}
