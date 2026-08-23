<?php
/* ============================================================================
 * Libellés traduits venus de l'API ERP (Franchise Buddy).
 *
 * Consigne : passer par l'API plutôt que de lire les tables ERP en SQL.
 *   GET {base}/product-categories/aliases       → noms de catégories
 *   GET {base}/product-category-groups/aliases  → noms de groupes de catégories
 * Un seul appel rend tout le dictionnaire ; on le garde en cache `ttl` secondes
 * (une page catalogue ne doit pas déclencher un appel par catégorie).
 *
 * LECTURE SEULE. Les PATCH d'alias existent côté ERP mais n'ont rien à faire
 * ici : le webshop affiche, il ne modifie pas le catalogue.
 *
 * RÈGLES DE SÛRETÉ (« aucune donnée inventée, aucun repli ») :
 *  - API non configurée (base vide) → fonction désactivée, libellés source
 *    servis tels quels : le comportement d'aujourd'hui, à l'octet près.
 *  - Appel en échec, JSON illisible ou forme inconnue → on garde le libellé
 *    SOURCE et on journalise. On ne fabrique jamais un nom, on n'affiche
 *    jamais une chaîne vide à la place d'un vrai libellé.
 *  - La langue demandée n'a pas d'alias → libellé source. Un catalogue à
 *    moitié traduit vaut mieux qu'un catalogue à trous.
 * ========================================================================== */

function erp_cfg() {
  static $c = null;
  if ($c !== null) return $c;
  $e = is_array(cfg()['erp'] ?? null) ? cfg()['erp'] : [];

  /* L'adresse et le jeton peuvent aussi vivre dans ws_param — c'est le
     paramétrage le plus PRATIQUE ici : il se règle depuis la base (phpMyAdmin
     ou l'écran Paramètres), sans éditer config.php ni redéployer. La base
     l'emporte sur le fichier ; à défaut, on garde config.php.
       ws_param.erp_api_base   = https://…/api/v1
       ws_param.erp_api_token  = <jeton Bearer, si l'API en exige un>
     Vide des deux côtés = fonction inerte (libellés source servis). */
  $base = (string) ($e['base'] ?? '');
  $tok  = (string) ($e['token'] ?? '');
  if (function_exists('ws_param')) {
    try {
      $b2 = (string) (ws_param('erp_api_base', '') ?: '');
      $t2 = (string) (ws_param('erp_api_token', '') ?: '');
      if ($b2 !== '') $base = $b2;
      if ($t2 !== '') $tok  = $t2;
    } catch (Throwable $ex) { /* table absente : on garde config.php */ }
  }
  /* Reconnexion AUTOMATIQUE (transitoire, en attendant le jeton d'intégration
     permanent demandé à Franchise Buddy) : le jeton consultant expire en
     30 minutes — un jeton statique posé dans erp_api_token meurt donc vite
     (constaté en production : 401 sur les alias, traductions à zéro). Si
     ws_param.erp_auth_phone / erp_auth_password sont posés, le serveur se
     reconnecte tout seul (erp_token) et le jeton statique devient un simple
     repli. À VIDER dès que le jeton permanent existe. */
  $aph = ''; $apw = '';
  if (function_exists('ws_param')) {
    try {
      $aph = (string) (ws_param('erp_auth_phone', '') ?: '');
      $apw = (string) (ws_param('erp_auth_password', '') ?: '');
    } catch (Throwable $ex) { /* table absente */ }
  }
  return $c = [
    'base'    => rtrim($base, '/'),
    'token'   => $tok,
    'auth_phone' => $aph,
    'auth_pass'  => $apw,
    'timeout' => (int) ($e['timeout'] ?? 6) ?: 6,
    'ttl'     => (int) ($e['ttl'] ?? 300),
  ];
}

/* POST de connexion consultant → ['t' => jeton, 'ttl' => secondes] ou null.
 * Volontairement muet sur le MOTIF détaillé (le mot de passe ne doit jamais
 * fuiter dans un journal) : l'échec se voit dans erp_notes et /erp/probe. */
function erp_login() {
  $cfg = erp_cfg();
  if ($cfg['base'] === '' || $cfg['auth_phone'] === '' || $cfg['auth_pass'] === '') return null;
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => $cfg['timeout'], 'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode(['phone' => $cfg['auth_phone'], 'password' => $cfg['auth_pass']]),
  ]]);
  $raw = @file_get_contents($cfg['base'] . '/consultant/auth/login', false, $ctx);
  $d = ($raw !== false) ? json_decode($raw, true) : null;
  $tok = is_array($d) ? (string) ($d['access_token'] ?? '') : '';
  if ($tok === '') { erp_notes('ERP : reconnexion refusée (identifiants ?)'); return null; }
  return ['t' => $tok, 'ttl' => max(120, (int) ($d['expires_in'] ?? 1800))];
}

/* Jeton Bearer COURANT : reconnexion automatique (mise en cache disque, marge
 * de 60 s avant l'expiration), sinon le jeton statique de ws_param/config. */
function erp_token($forceLogin = false) {
  $cfg = erp_cfg();
  if ($cfg['auth_phone'] !== '' && $cfg['auth_pass'] !== '') {
    $f = sys_get_temp_dir() . '/ws_erp_tok_' . sha1($cfg['base'] . '|' . $cfg['auth_phone']) . '.json';
    if (!$forceLogin) {
      $c = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
      if (is_array($c) && ($c['exp'] ?? 0) > time() && ($c['tok'] ?? '') !== '') return (string) $c['tok'];
    }
    $l = erp_login();
    if ($l !== null) {
      @file_put_contents($f, json_encode(['tok' => $l['t'], 'exp' => time() + $l['ttl'] - 60]));
      @chmod($f, 0600);
      return $l['t'];
    }
    // reconnexion en échec → on tente le jeton statique, faute de mieux
  }
  return $cfg['token'];
}

function erp_enabled() { return erp_cfg()['base'] !== ''; }

/* Journal des échecs ERP du cycle courant : /catalog/* les ressert pour que le
 * bandeau du webshop reste crédible (une panne se voit, elle ne se devine pas). */
function erp_notes($add = null) {
  static $n = [];
  if ($add !== null) { $n[] = $add; return $n; }
  return $n;
}

/* GET JSON sur l'API ERP, avec cache disque. Rend null en cas d'échec. */
function erp_get($path) {
  $cfg = erp_cfg();
  if ($cfg['base'] === '') return null;
  $url = $cfg['base'] . '/' . ltrim($path, '/');

  $file = sys_get_temp_dir() . '/ws_erp_' . sha1($url) . '.json';
  if ($cfg['ttl'] > 0 && is_file($file) && (time() - filemtime($file)) < $cfg['ttl']) {
    $cached = json_decode((string) @file_get_contents($file), true);
    if (is_array($cached)) return $cached;
  }

  $tirer = static function ($tok) use ($url, $cfg) {
    $headers = "Accept: application/json\r\n";
    if ($tok !== '') $headers .= 'Authorization: Bearer ' . $tok . "\r\n";
    $ctx = stream_context_create(['http' => [
      'method' => 'GET', 'header' => $headers,
      'timeout' => $cfg['timeout'], 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
    }
    return [$raw, $code];
  };
  [$raw, $code] = $tirer(erp_token());
  if ($code === 401 && $cfg['auth_phone'] !== '' && $cfg['auth_pass'] !== '') {
    // Jeton du cache périmé côté ERP avant notre marge : reconnexion forcée
    // puis UN nouvel essai — jamais de boucle.
    [$raw, $code] = $tirer(erp_token(true));
  }
  if ($raw === false) { erp_notes('ERP injoignable : ' . $path); return null; }
  if ($code >= 400) { erp_notes('ERP HTTP ' . $code . ' sur ' . $path); return null; }

  $data = json_decode($raw, true);
  if (!is_array($data)) { erp_notes('ERP : JSON illisible sur ' . $path); return null; }

  if ($cfg['ttl'] > 0) @file_put_contents($file, json_encode($data));
  return $data;
}

/* Extrait [id => [langue => libellé]] d'une réponse d'alias.
 *
 * La forme exacte de l'API n'étant pas figée, on RECONNAÎT les formes usuelles
 * plutôt que d'en supposer une seule — et si aucune ne colle, on rend un
 * tableau vide (donc : libellés source conservés), jamais un résultat approché.
 *
 * Formes reconnues, par entrée de liste :
 *   a) { id: 12, lang: "nl",  name: "Taarten" }            (une ligne par langue)
 *   b) { id: 12, aliases: { nl: "Taarten", fr: "Tartes" } }
 *   c) { id: 12, name_nl: "Taarten", name_fr: "Tartes" }
 * La liste peut être à la racine, ou sous data / items / results / aliases. */
function erp_alias_map($path) {
  $data = erp_get($path);
  if ($data === null) return [];

  $list = null;
  if (array_is_list($data)) $list = $data;
  else foreach (['data', 'items', 'results', 'aliases'] as $k) {
    if (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) { $list = $data[$k]; break; }
  }
  if ($list === null) { erp_notes('ERP : forme inattendue sur ' . $path); return []; }

  /* `fk_id` en tête : c'est la clé RÉELLE de tfbuddy, vérifiée jeton en main sur
     products/aliases (762 lignes) et product-categories/aliases (81). Sans elle
     aucune ligne n'était reconnue : le catalogue restait en français sans que
     rien ne le signale. */
  $ID   = ['fk_id', 'id', 'product_category_id', 'id_product_category', 'category_id',
           'product_category_group_id', 'id_product_category_group', 'group_id',
           'product_id', 'id_product'];
  $LANG = ['lang', 'language', 'locale', 'lang_code', 'language_code'];
  /* `alias_value` AVANT `effective_value` : le second retombe sur `base_value`
     (le libellé SOURCE, en français) quand aucun alias n'existe. Le lire
     d'abord ferait passer du français pour une traduction néerlandaise. */
  $LBL  = ['alias_value', 'effective_value',
           'name', 'label', 'alias', 'value', 'title', 'translation'];

  $out = [];
  foreach ($list as $row) {
    if (!is_array($row)) continue;

    $id = null;
    foreach ($ID as $k) { if (isset($row[$k]) && $row[$k] !== '') { $id = (string) $row[$k]; break; } }
    if ($id === null) continue;

    // (a) une ligne par langue
    $lang = null;
    foreach ($LANG as $k) { if (!empty($row[$k]) && is_string($row[$k])) { $lang = strtolower(substr($row[$k], 0, 2)); break; } }
    if ($lang !== null) {
      foreach ($LBL as $k) {
        if (isset($row[$k]) && is_string($row[$k]) && $row[$k] !== '') { $out[$id][$lang] = $row[$k]; break; }
      }
      continue;
    }

    // (b) dictionnaire de langues imbriqué
    foreach (['aliases', 'translations', 'names', 'labels'] as $k) {
      if (isset($row[$k]) && is_array($row[$k])) {
        foreach ($row[$k] as $lg => $val) {
          if (is_string($lg) && is_string($val) && $val !== '') $out[$id][strtolower(substr($lg, 0, 2))] = $val;
        }
      }
    }

    // (c) colonnes suffixées name_nl / label_fr …
    foreach ($row as $k => $val) {
      if (is_string($val) && $val !== '' && preg_match('/^(?:name|label|alias)_([a-z]{2})$/i', (string) $k, $m)) {
        $out[$id][strtolower($m[1])] = $val;
      }
    }
  }
  return $out;
}

/* Noms de PRODUITS dans la langue demandée : [id_produit => nom].
 * Source : GET {base}/products/aliases — un seul appel pour tout le catalogue
 * (jamais un appel par produit). Même règle que pour les catégories : sans
 * alias dans cette langue, l'appelant garde le nom SOURCE. */
function erp_product_labels($lang) {
  static $cache = [];
  $lang = strtolower(substr((string) $lang, 0, 2));
  if ($lang === '') return [];
  if (isset($cache[$lang])) return $cache[$lang];
  if (!erp_enabled()) return $cache[$lang] = [];

  $out = [];
  foreach (erp_alias_map('products/aliases') as $id => $byLang) {
    if (isset($byLang[$lang]) && $byLang[$lang] !== '') $out[$id] = $byLang[$lang];
  }
  return $cache[$lang] = $out;
}

/* Libellés de catégories dans la langue demandée : [id => libellé].
 * Rend [] si l'API est absente ou muette — l'appelant garde ses libellés source. */
function erp_category_labels($lang) {
  static $cache = [];
  $lang = strtolower(substr((string) $lang, 0, 2));
  if ($lang === '' ) return [];
  if (isset($cache[$lang])) return $cache[$lang];
  if (!erp_enabled()) return $cache[$lang] = [];

  $out = [];
  foreach (['product-categories/aliases', 'product-category-groups/aliases'] as $path) {
    foreach (erp_alias_map($path) as $id => $byLang) {
      if (isset($byLang[$lang]) && $byLang[$lang] !== '') $out[$id] = $byLang[$lang];
    }
  }
  return $cache[$lang] = $out;
}
