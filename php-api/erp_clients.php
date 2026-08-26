<?php
/* ============================================================================
 * CLIENTS DE L'ERP — lus par la LISTE, faute de mieux.
 *
 * CE QUI MARCHE, ET CE QUI NE MARCHE PAS (mesuré le 25/08, jetons consultant
 * ET admin, sur plusieurs boutiques) :
 *   GET  /shops/{id}/clients          → 200. shop 2 : 8154, shop 3 : 1318,
 *                                       shop 4 : 485. Le filtre par boutique
 *                                       fonctionne, `?limit=` est IGNORÉ
 *                                       (14,5 Mo à chaque appel).
 *   GET  /shops/{id}/clients/{cid}    → 404 CLIENT_NOT_ASSIGNED_TO_SHOP sur
 *                                       12 clients tirés au hasard DANS cette
 *                                       liste, sous 5 boutiques, avec les deux
 *                                       jetons. L'endpoint refuse TOUT LE MONDE.
 *   PATCH /shops/{id}/clients/{cid}   → même 404 : aucune mise à jour possible.
 *   POST /shops/{id}/clients          → existe (422 INVALID_PHONE_NUMBER à vide).
 *
 * D'OÙ CE MODULE : on lit un client dans la LISTE, pas par sa fiche. La liste
 * est donc rapatriée périodiquement et réduite à un INDEX (les champs dont le
 * webshop se sert), gardé sur disque. Rapatrier 14,5 Mo à chaque visiteur
 * serait absurde ; le faire une fois par quart d'heure est tenable.
 *
 * CE N'EST PAS UN CACHE DE CONFORT, C'EST UN CONTOURNEMENT : le jour où la
 * fiche unitaire répondra, ce module se réduit à trois appels directs. Le
 * commentaire est là pour qu'on s'en souvienne.
 *
 * RÈGLE : jamais de donnée inventée. Index inconstructible (API muette, JSON
 * illisible) → null, et l'appelant garde la table `client` locale.
 * ========================================================================== */

function erp_clients_enabled() {
  if (!function_exists('ws_param')) return false;
  try { return strtolower((string) (ws_param('clients_source', '') ?: '')) === 'erp'; }
  catch (Throwable $e) { return false; }
}

/* Champs conservés : ceux que le webshop lit réellement. Le reste (hachage de
 * mot de passe — toujours vide côté ERP —, IBAN, Peppol, compteurs internes)
 * n'a rien à faire dans un index qu'on écrit sur disque. */
function erp_client_reduire(array $c) {
  $g = fn ($k) => array_key_exists($k, $c) ? $c[$k] : null;
  return [
    'id'        => (int) $g('id'),
    'email'     => (string) ($g('email_norm') ?: strtolower(trim((string) $g('email')))),
    'emailAff'  => (string) $g('email'),
    'nom'       => (string) $g('name'),
    'prenom'    => (string) $g('surname'),
    'tel'       => (string) ($g('phone_e164') ?: $g('phone')),
    'societe'   => (string) $g('company_name'),
    'tva'       => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $g('tax_number'))),
    'b2b'       => !empty($g('is_b2b')),
    'shopId'    => (int) $g('id_main_shop'),
    'cp'        => (string) $g('zip'),
    'ville'     => (string) ($g('city') ?: $g('locality')),
    'actif'     => $g('active') === null ? true : !empty($g('active')),
    'bloque'    => !empty($g('blocked')),
    'remise'    => $g('personal_discount_percent') !== null ? (float) $g('personal_discount_percent') : null,
    'societeId' => $g('company_client_id') !== null ? (int) $g('company_client_id') : null,
    'officeId'  => $g('office_id') !== null ? (int) $g('office_id') : null,
  ];
}

/* L'index d'une boutique : [id => client réduit], ou null.
 * TTL réglable (ws_param.clients_index_ttl, 900 s par défaut). */
function erp_clients_index($shopId, $force = false) {
  static $mem = [];
  $shopId = (int) $shopId;
  if (!$force && isset($mem[$shopId])) return $mem[$shopId];
  if (!erp_clients_enabled() || !function_exists('erp_cfg')) return $mem[$shopId] = null;
  $cfg = erp_cfg();
  if ($cfg['base'] === '' || $shopId <= 0) return $mem[$shopId] = null;

  $ttl = 900;
  try { $ttl = max(60, (int) (ws_param('clients_index_ttl', 900) ?: 900)); } catch (Throwable $e) {}
  $f = sys_get_temp_dir() . '/ws_erp_clients_' . sha1($cfg['base'] . '|' . $shopId) . '.json';
  if (!$force && is_file($f) && (time() - filemtime($f)) < $ttl) {
    $c = json_decode((string) @file_get_contents($f), true);
    if (is_array($c)) return $mem[$shopId] = $c;
  }

  $tok = function_exists('erp_token') ? erp_token() : '';
  $ctx = stream_context_create(['http' => [
    'method' => 'GET', 'timeout' => 60, 'ignore_errors' => true,   // 14,5 Mo : large
    'header' => "Accept: application/json\r\n" . ($tok !== '' ? "Authorization: Bearer $tok\r\n" : ''),
  ]]);
  $raw = @file_get_contents($cfg['base'] . '/shops/' . $shopId . '/clients', false, $ctx);
  $code = 0;
  foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  if ($raw === false || $code >= 400) {
    if (function_exists('erp_notes')) erp_notes('ERP clients HTTP ' . $code . ' (boutique ' . $shopId . ')');
    // Index périmé plutôt que rien : il vaut mieux un annuaire d'hier qu'un
    // écran vide — mais on le DIT dans les incidents.
    if (is_file($f)) { $c = json_decode((string) @file_get_contents($f), true); if (is_array($c)) return $mem[$shopId] = $c; }
    return $mem[$shopId] = null;
  }
  $d = json_decode($raw, true);
  $lst = is_array($d) ? (array_is_list($d) ? $d : ($d['items'] ?? $d['data'] ?? null)) : null;
  if (!is_array($lst)) { if (function_exists('erp_notes')) erp_notes('ERP clients : forme inattendue'); return $mem[$shopId] = null; }

  $idx = [];
  foreach ($lst as $c) if (is_array($c) && !empty($c['id'])) {
    $r = erp_client_reduire($c);
    $idx[(string) $r['id']] = $r;
  }
  if (!$idx) return $mem[$shopId] = null;
  @file_put_contents($f, json_encode($idx));
  @chmod($f, 0600);
  return $mem[$shopId] = $idx;
}

/* Téléphone NORMALISÉ — la clé de rapprochement réelle entre le webshop et
 * l'ERP. Mesuré sur la production : 8123 clients sur 8154 ont un téléphone,
 * 575 seulement ont un e-mail. Chercher par e-mail ne retrouverait donc que
 * 7 % des fiches ; le téléphone en retrouve 99,6 %. (`public_id` porte la même
 * notion côté ERP, mais il est vide sur les 8154 — on normalise nous-mêmes.)
 *
 * Les mêmes chiffres s'écrivent de six façons — 0495727667, +32495727667,
 * 0032 495 72 76 67, 495727667… On compare donc les 9 derniers chiffres
 * (numéro national belge sans le zéro de tête), ce qui rapproche toutes ces
 * formes sans confondre deux abonnés différents. */
function erp_tel_cle($tel) {
  $d = preg_replace('/[^0-9]/', '', (string) $tel);
  if ($d === '') return '';
  if (str_starts_with($d, '0032')) $d = substr($d, 4);        // 0032… → national
  elseif (str_starts_with($d, '32') && strlen($d) > 9) $d = substr($d, 2);
  $d = ltrim($d, '0');                                        // 0495… → 495…
  return strlen($d) >= 8 ? substr($d, -9) : $d;
}

/* Recherches — l'équivalent de la fiche unitaire, prise dans l'index. */
function erp_client_par_tel($shopId, $tel) {
  $i = erp_clients_index($shopId);
  if (!$i) return null;
  $k = erp_tel_cle($tel);
  if ($k === '') return null;
  foreach ($i as $c) if ($c['tel'] !== '' && erp_tel_cle($c['tel']) === $k) return $c;
  return null;
}

function erp_client_par_id($shopId, $id) {
  $i = erp_clients_index($shopId);
  return $i[(string) (int) $id] ?? null;
}
function erp_client_par_email($shopId, $email) {
  $i = erp_clients_index($shopId);
  if (!$i) return null;
  $e = strtolower(trim((string) $email));
  if ($e === '') return null;
  foreach ($i as $c) if ($c['email'] === $e) return $c;
  return null;
}
function erp_client_par_tva($shopId, $tva) {
  $i = erp_clients_index($shopId);
  if (!$i) return null;
  $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $tva));
  if ($t === '') return null;
  foreach ($i as $c) if ($c['tva'] !== '' && $c['tva'] === $t) return $c;
  return null;
}

/* Diagnostic : ce que l'index contient, sans exposer les clients eux-mêmes. */
function erp_clients_etat($shopId) {
  $cfg = function_exists('erp_cfg') ? erp_cfg() : ['base' => ''];
  $f = sys_get_temp_dir() . '/ws_erp_clients_' . sha1($cfg['base'] . '|' . (int) $shopId) . '.json';
  $i = erp_clients_index($shopId);
  return [
    'source'    => erp_clients_enabled() ? 'erp' : 'local',
    'clients'   => is_array($i) ? count($i) : 0,
    'age_s'     => is_file($f) ? time() - filemtime($f) : null,
    'ttl_s'     => (int) (function_exists('ws_param') ? (ws_param('clients_index_ttl', 900) ?: 900) : 900),
    'avec_tel'  => is_array($i) ? count(array_filter($i, fn ($c) => $c['tel'] !== '')) : 0,
    'avec_email' => is_array($i) ? count(array_filter($i, fn ($c) => $c['email'] !== '')) : 0,
    'avec_tva'  => is_array($i) ? count(array_filter($i, fn ($c) => $c['tva'] !== '')) : 0,
    'b2b'       => is_array($i) ? count(array_filter($i, fn ($c) => $c['b2b'])) : 0,
  ];
}
