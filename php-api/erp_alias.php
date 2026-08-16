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
  $c = cfg();
  $e = is_array($c['erp'] ?? null) ? $c['erp'] : [];
  return [
    'base'    => rtrim((string) ($e['base'] ?? ''), '/'),
    'token'   => (string) ($e['token'] ?? ''),
    'timeout' => (int) ($e['timeout'] ?? 6) ?: 6,
    'ttl'     => (int) ($e['ttl'] ?? 300),
  ];
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

  $headers = "Accept: application/json\r\n";
  if ($cfg['token'] !== '') $headers .= 'Authorization: Bearer ' . $cfg['token'] . "\r\n";
  $ctx = stream_context_create(['http' => [
    'method' => 'GET', 'header' => $headers,
    'timeout' => $cfg['timeout'], 'ignore_errors' => true,
  ]]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) { erp_notes('ERP injoignable : ' . $path); return null; }

  $code = 0;
  foreach (($http_response_header ?? []) as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  }
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

  $ID   = ['id', 'product_category_id', 'id_product_category', 'category_id',
           'product_category_group_id', 'id_product_category_group', 'group_id',
           'product_id', 'id_product'];
  $LANG = ['lang', 'language', 'locale', 'lang_code', 'language_code'];
  $LBL  = ['name', 'label', 'alias', 'value', 'title', 'translation'];

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
