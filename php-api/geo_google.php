<?php
/* ── GÉO GOOGLE, DEPUIS LE SERVEUR SEULEMENT ─────────────────────────────────
 * Trois usages, une clé (ws_param google_api_key, repli google_geocode_key —
 * la clé ne vit qu'en base, le dépôt est public et le navigateur ne la voit
 * jamais) :
 *   geo_suggest : liste d'adresses pendant la frappe (Places Autocomplete) ;
 *   geo_place   : la fiche d'un lieu choisi (Place Details) → rue, numéro,
 *                 code postal, localité, position, identifiant de lieu ;
 *   geo_route   : l'itinéraire dépôt → arrêts → dépôt (Directions) → km et
 *                 minutes par tronçon, dans l'ordre des arrêts.
 * Aucun repli : sans clé, l'appelant répond 501 ; Google muet ou en refus →
 * 502 avec le statut rendu. Rien n'est inventé, rien n'est mis en cache
 * côté serveur pour l'itinéraire (les arrêts changent). */

function geo_google_key(): string {
  $k = function_exists('ws_param') ? (string) ws_param('google_api_key', '') : '';
  if ($k === '' && function_exists('ws_param')) $k = (string) ws_param('google_geocode_key', '');
  return $k;
}
function geo_google_base(): string { return defined('GEO_GOOGLE_BASE_TEST') ? GEO_GOOGLE_BASE_TEST : 'https://maps.googleapis.com/maps/api'; }

/* GET JSON → [code HTTP, tableau|null]. 0 = injoignable. */
function geo_http_json(string $url, int $timeout = 8): array {
  $raw = false; $code = 0;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  }
  $d = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, is_array($d) ? $d : null];
}

function geo_google_statut(?array $d, int $code): string {
  if (!$d) return 'Google injoignable (HTTP ' . $code . ')';
  $st = (string) ($d['status'] ?? 'refus');
  return 'Google : ' . $st . (!empty($d['error_message']) ? ' — ' . $d['error_message'] : '');
}

function geo_suggest(string $q, string $key, string $pays = 'be|fr|nl|lu|de'): array {
  $comp = implode('|', array_map(static fn ($c) => 'country:' . $c, array_filter(explode('|', $pays))));
  // Sans `types=address` : Google propose AUSSI les entreprises et lieux
  // (« IBA Louvain-la-Neuve ») — un site se cherche par son nom autant que
  // par sa rue. La fiche du lieu rend ensuite l'adresse structurée.
  $url = geo_google_base() . '/place/autocomplete/json?input=' . rawurlencode($q) . '&language=fr&components=' . rawurlencode($comp) . '&key=' . rawurlencode($key);
  [$code, $d] = geo_http_json($url);
  if (!$d) return ['ok' => false, 'error' => geo_google_statut($d, $code)];
  $st = (string) ($d['status'] ?? '');
  if ($st === 'ZERO_RESULTS') return ['ok' => true, 'suggestions' => []];
  if ($st !== 'OK') return ['ok' => false, 'error' => geo_google_statut($d, $code)];
  $out = [];
  foreach ($d['predictions'] ?? [] as $p) {
    if (!is_array($p) || empty($p['place_id'])) continue;
    $out[] = ['id' => (string) $p['place_id'], 'label' => (string) ($p['description'] ?? ''),
              'main' => (string) ($p['structured_formatting']['main_text'] ?? ''), 'secondary' => (string) ($p['structured_formatting']['secondary_text'] ?? '')];
    if (count($out) >= 6) break;
  }
  return ['ok' => true, 'suggestions' => $out];
}

function geo_place(string $placeId, string $key): array {
  $url = geo_google_base() . '/place/details/json?place_id=' . rawurlencode($placeId) . '&fields=' . rawurlencode('address_component,geometry,formatted_address,place_id,name') . '&language=fr&key=' . rawurlencode($key);
  [$code, $d] = geo_http_json($url);
  if (!$d || (string) ($d['status'] ?? '') !== 'OK') return ['ok' => false, 'error' => geo_google_statut($d, $code)];
  $r = $d['result'] ?? [];
  $c = ['street' => '', 'number' => '', 'zip' => '', 'city' => '', 'country' => ''];
  foreach ($r['address_components'] ?? [] as $ac) {
    if (!is_array($ac)) continue;
    $t = $ac['types'] ?? []; $ln = (string) ($ac['long_name'] ?? ''); $sn = (string) ($ac['short_name'] ?? '');
    if (in_array('street_number', $t, true)) $c['number'] = $ln;
    elseif (in_array('route', $t, true)) $c['street'] = $ln;
    elseif (in_array('postal_code', $t, true)) $c['zip'] = $ln;
    elseif (in_array('locality', $t, true)) $c['city'] = $ln;
    elseif ($c['city'] === '' && (in_array('postal_town', $t, true) || in_array('sublocality', $t, true) || in_array('administrative_area_level_3', $t, true))) $c['city'] = $ln;
    elseif (in_array('country', $t, true)) $c['country'] = $sn;
  }
  $lat = $r['geometry']['location']['lat'] ?? null; $lng = $r['geometry']['location']['lng'] ?? null;
  return ['ok' => true] + $c + ['lat' => is_numeric($lat) ? (float) $lat : null, 'lng' => is_numeric($lng) ? (float) $lng : null,
          'placeId' => (string) ($r['place_id'] ?? $placeId), 'formatted' => (string) ($r['formatted_address'] ?? ''),
          'name' => (string) ($r['name'] ?? '')];
}

/* $origin ['lat','lng'] ; $stops [['lat','lng'], …] dans l'ordre ; $back : retour au dépôt. */
function geo_route(array $origin, array $stops, bool $back, string $key): array {
  if (!$stops) return ['ok' => false, 'error' => 'aucun arrêt'];
  $fmt = static fn ($p) => sprintf('%.6f,%.6f', (float) $p['lat'], (float) $p['lng']);
  $dest = $back ? $origin : $stops[count($stops) - 1];
  $way  = $back ? $stops : array_slice($stops, 0, -1);
  $url = geo_google_base() . '/directions/json?origin=' . rawurlencode($fmt($origin)) . '&destination=' . rawurlencode($fmt($dest))
       . ($way ? '&waypoints=' . rawurlencode(implode('|', array_map($fmt, $way))) : '') . '&mode=driving&language=fr&units=metric&key=' . rawurlencode($key);
  [$code, $d] = geo_http_json($url, 12);
  if (!$d || (string) ($d['status'] ?? '') !== 'OK') return ['ok' => false, 'error' => geo_google_statut($d, $code)];
  $legs = []; $m = 0; $s = 0;
  foreach ($d['routes'][0]['legs'] ?? [] as $lg) {
    $dm = (int) ($lg['distance']['value'] ?? 0); $ds = (int) ($lg['duration']['value'] ?? 0);
    $m += $dm; $s += $ds; $legs[] = ['km' => round($dm / 1000, 1), 'min' => (int) round($ds / 60)];
  }
  return ['ok' => true, 'km' => round($m / 1000, 1), 'minutes' => (int) round($s / 60), 'legs' => $legs];
}

/* ── ROUTES API : ORDRE D'ARRÊTS OPTIMAL (optimizeWaypointOrder) ───────────
 * L'API « tournée » de Google Maps Platform pour un véhicule : elle rend
 * l'ordre le plus court et les tronçons. Même clé que Places/Directions, mais
 * « Routes API » doit être ACTIVÉE sur le projet Google Cloud de la clé —
 * sinon 403 PERMISSION_DENIED, rendu tel quel à l'appelant.
 * Retour au dépôt : tous les arrêts sont intermédiaires, destination = dépôt.
 * Sans retour : le DERNIER arrêt reste la destination (fixe), les autres sont
 * réordonnés — Google exige une destination connue. */
function geo_routes_base(): string { return defined('GEO_ROUTES_BASE_TEST') ? GEO_ROUTES_BASE_TEST : 'https://routes.googleapis.com'; }
function geo_http_post_json(string $url, array $body, array $headers, int $timeout = 15): array {
  $raw = false; $code = 0; $json = json_encode($body, JSON_UNESCAPED_UNICODE);
  $hdr = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $hdr]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true, 'header' => implode("\r\n", $hdr) . "\r\n", 'content' => $json]]);
    $raw = @file_get_contents($url, false, $ctx);
    foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
  }
  $d = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, is_array($d) ? $d : null];
}
function geo_route_optimise(array $origin, array $stops, bool $back, string $key): array {
  $n = count($stops);
  if ($n < 2 + ($back ? 0 : 1)) return ['ok' => false, 'error' => 'Au moins ' . ($back ? 2 : 3) . ' arrêts positionnés sont nécessaires pour proposer un ordre.'];
  $pt = static fn ($p) => ['location' => ['latLng' => ['latitude' => (float) $p['lat'], 'longitude' => (float) $p['lng']]]];
  $inter = $back ? $stops : array_slice($stops, 0, -1);
  $dest  = $back ? $origin : $stops[$n - 1];
  $body = ['origin' => $pt($origin), 'destination' => $pt($dest), 'intermediates' => array_map($pt, $inter),
           'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'optimizeWaypointOrder' => true, 'languageCode' => 'fr', 'units' => 'METRIC'];
  [$code, $d] = geo_http_post_json(geo_routes_base() . '/directions/v2:computeRoutes', $body,
    ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: routes.optimizedIntermediateWaypointIndex,routes.distanceMeters,routes.duration,routes.legs.distanceMeters,routes.legs.duration']);
  if (!$d) return ['ok' => false, 'error' => 'Routes API injoignable (HTTP ' . $code . ')'];
  if ($code !== 200 || empty($d['routes'][0])) {
    $msg = (string) ($d['error']['message'] ?? ($d['error']['status'] ?? 'refus'));
    if ($code === 403 || stripos($msg, 'PERMISSION') !== false || stripos($msg, 'not enabled') !== false || stripos($msg, 'has not been used') !== false)
      $msg = 'Routes API non activée sur la clé Google — activer « Routes API » dans le projet Google Cloud (' . $msg . ')';
    return ['ok' => false, 'error' => 'Google Routes : ' . $msg];
  }
  $r = $d['routes'][0];
  $idx = array_values(array_map('intval', (array) ($r['optimizedIntermediateWaypointIndex'] ?? [])));
  if (count($idx) !== count($inter)) $idx = range(0, count($inter) - 1);   // sans réordonnancement rendu : ordre inchangé
  $order = [];
  foreach ($idx as $i) $order[] = $i;                       // indices dans $stops (les intermédiaires sont les premiers)
  if (!$back) $order[] = $n - 1;
  $legs = []; $m = 0; $sec = 0;
  foreach ($r['legs'] ?? [] as $lg) { $dm = (int) ($lg['distanceMeters'] ?? 0); $ds = (int) preg_replace('/\D/', '', (string) ($lg['duration'] ?? '0s')); $m += $dm; $sec += $ds; $legs[] = ['km' => round($dm / 1000, 1), 'min' => (int) round($ds / 60)]; }
  return ['ok' => true, 'order' => $order, 'km' => round($m / 1000, 1), 'minutes' => (int) round($sec / 60), 'legs' => $legs];
}

/* ── ROUTES API : TRACÉ RÉEL + TRAFIC (TRAFFIC_ON_POLYLINE) ────────────────
 * Le tracé de la tournée (polyline) et, portion par portion, le niveau de
 * trafic Google : NORMAL / SLOW / TRAFFIC_JAM — les couleurs de Google Maps.
 * Durée avec trafic (duration) et sans (staticDuration) par tronçon. Même
 * activation « Routes API » que l'ordre optimal. */
function geo_polyline_decode(string $enc): array {
  $pts = []; $i = 0; $n = strlen($enc); $lat = 0; $lng = 0;
  while ($i < $n) {
    foreach (['lat', 'lng'] as $k) {
      $res = 0; $sh = 0;
      do { $b = ord($enc[$i++]) - 63; $res |= ($b & 0x1f) << $sh; $sh += 5; } while ($b >= 0x20 && $i < $n);
      $d = ($res & 1) ? ~($res >> 1) : ($res >> 1);
      if ($k === 'lat') $lat += $d; else $lng += $d;
    }
    $pts[] = [round($lat / 1e5, 5), round($lng / 1e5, 5)];
  }
  return $pts;
}
function geo_route_trace(array $origin, array $stops, bool $back, string $key): array {
  if (!$stops) return ['ok' => false, 'error' => 'aucun arrêt'];
  $pt = static fn ($p) => ['location' => ['latLng' => ['latitude' => (float) $p['lat'], 'longitude' => (float) $p['lng']]]];
  $dest = $back ? $origin : $stops[count($stops) - 1];
  $inter = $back ? $stops : array_slice($stops, 0, -1);
  $body = ['origin' => $pt($origin), 'destination' => $pt($dest), 'intermediates' => array_map($pt, $inter),
           'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_AWARE_OPTIMAL',
           'departureTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 90), 'extraComputations' => ['TRAFFIC_ON_POLYLINE'],
           'polylineQuality' => 'HIGH_QUALITY', 'languageCode' => 'fr', 'units' => 'METRIC'];
  [$code, $d] = geo_http_post_json(geo_routes_base() . '/directions/v2:computeRoutes', $body,
    ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: routes.distanceMeters,routes.duration,routes.staticDuration,routes.polyline.encodedPolyline,routes.travelAdvisory.speedReadingIntervals,routes.legs.distanceMeters,routes.legs.duration,routes.legs.staticDuration']);
  if (!$d) return ['ok' => false, 'error' => 'Routes API injoignable (HTTP ' . $code . ')'];
  if ($code !== 200 || empty($d['routes'][0])) {
    $msg = (string) ($d['error']['message'] ?? ($d['error']['status'] ?? 'refus'));
    if ($code === 403 || stripos($msg, 'PERMISSION') !== false || stripos($msg, 'not enabled') !== false || stripos($msg, 'has not been used') !== false)
      $msg = 'Routes API non activée sur la clé Google — activer « Routes API » dans le projet Google Cloud (' . $msg . ')';
    return ['ok' => false, 'error' => 'Google Routes : ' . $msg];
  }
  $r = $d['routes'][0]; $sec = static fn ($v) => (int) preg_replace('/\D/', '', (string) ($v ?? '0s'));
  $pts = geo_polyline_decode((string) ($r['polyline']['encodedPolyline'] ?? ''));
  $iv = [];
  foreach ($r['travelAdvisory']['speedReadingIntervals'] ?? [] as $x)
    $iv[] = ['a' => (int) ($x['startPolylinePointIndex'] ?? 0), 'b' => (int) ($x['endPolylinePointIndex'] ?? 0), 'speed' => (string) ($x['speed'] ?? 'NORMAL')];
  $legs = [];
  foreach ($r['legs'] ?? [] as $lg) $legs[] = ['km' => round(((int) ($lg['distanceMeters'] ?? 0)) / 1000, 1), 'min' => (int) round($sec($lg['duration'] ?? null) / 60), 'minSans' => (int) round($sec($lg['staticDuration'] ?? null) / 60)];
  $nJam = 0; $nSlow = 0; foreach ($iv as $x) { if ($x['speed'] === 'TRAFFIC_JAM') $nJam++; elseif ($x['speed'] === 'SLOW') $nSlow++; }
  return ['ok' => true, 'points' => $pts, 'intervals' => $iv, 'legs' => $legs,
          'km' => round(((int) ($r['distanceMeters'] ?? 0)) / 1000, 1), 'minutes' => (int) round($sec($r['duration'] ?? null) / 60), 'minutesSans' => (int) round($sec($r['staticDuration'] ?? null) / 60),
          'trafic' => ['jam' => $nJam, 'slow' => $nSlow, 'normal' => count($iv) - $nJam - $nSlow], 'at' => gmdate('c')];
}

/* Colonnes d'adresse / géo d'un site, posées depuis une ligne reçue (clés lat,
   lng, place_id, formatted, street, street_number, postal_code, city).
   Seules les colonnes présentes sont écrites ; sans lat/lng rien ne bouge. */
function site_geo_poser(int $siteId, array $r): void {
  if ($siteId <= 0 || !function_exists('col_exists')) return;
  $sets = []; $vals = [];
  $lat = $r['lat'] ?? ($r['latitude'] ?? null); $lng = $r['lng'] ?? ($r['longitude'] ?? null);
  if (is_numeric($lat) && is_numeric($lng) && col_exists('ws_office_delivery_sites', 'latitude')) {
    $sets[] = 'latitude=?'; $vals[] = (float) $lat; $sets[] = 'longitude=?'; $vals[] = (float) $lng;
    if (col_exists('ws_office_delivery_sites', 'google_place_id')) { $sets[] = 'google_place_id=?'; $vals[] = (string) (($r['place_id'] ?? $r['placeId'] ?? '') ?: null); }
    if (col_exists('ws_office_delivery_sites', 'google_formatted_address')) { $sets[] = 'google_formatted_address=?'; $vals[] = (string) (($r['formatted'] ?? '') ?: null); }
    if (col_exists('ws_office_delivery_sites', 'geocode_status')) { $sets[] = "geocode_status='success'"; }
    if (col_exists('ws_office_delivery_sites', 'geocoded_at')) { $sets[] = 'geocoded_at=NOW()'; }
  }
  foreach (['street' => 'street', 'street_number' => 'street_number', 'postal_code' => 'postal_code', 'city' => 'city'] as $k => $col) {
    if (array_key_exists($k, $r) && col_exists('ws_office_delivery_sites', $col)) { $sets[] = "$col=?"; $vals[] = ((string) $r[$k] !== '') ? (string) $r[$k] : null; }
  }
  if (!$sets) return;
  $vals[] = $siteId;
  q("UPDATE ws_office_delivery_sites SET " . implode(',', $sets) . " WHERE id=?", $vals);
}
