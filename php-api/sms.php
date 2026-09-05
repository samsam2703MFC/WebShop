<?php
/* ── ENVOI DE SMS ET CODE À USAGE UNIQUE — SMSAPI ────────────────────────────
 *
 * POURQUOI. 8 178 fiches clients, 0 mot de passe, 99 % avec un téléphone et 7 %
 * avec un e-mail. Le seul canal qui atteigne la base est le SMS. Sans lui,
 * `/auth/set-password` laisse n'importe qui réclamer le compte de n'importe qui
 * en tapant son numéro — le fichier porte l'historique d'achats et la fidélité.
 *
 * ON N'ÉCRIT PAS L'OTP NOUS-MÊMES. SMSAPI expose un « SMS Authenticator » qui
 * génère, envoie, stocke et vérifie le code (180 s de validité) :
 *   POST /mfa/codes               → envoie
 *   POST /mfa/codes/verifications → 204 bon · 404 faux · 408 périmé
 * Trois choses de moins à écrire, donc trois de moins à rater : le stockage,
 * l'expiration, et la comparaison en temps constant.
 *
 * ⚠️ LE PIÈGE DE CE CONTRAT. La réponse de /mfa/codes CONTIENT le code en
 * clair. Il ne doit JAMAIS ressortir de ce fichier — ni en réponse HTTP, ni
 * dans un journal. Les fonctions ci-dessous ne le rendent nulle part, et c'est
 * délibéré ; ne pas « l'ajouter pour déboguer ».
 *
 * Réglages (ws_param) : sms_token · sms_from · sms_fast · sms_otp_message
 * Sans jeton, tout est inerte et le dit — jamais de repli silencieux. ── */

function sms_cfg($k, $d = '') {
  return function_exists('ws_param') ? (string) ws_param($k, $d) : $d;
}

function sms_enabled() { return sms_cfg('sms_token') !== ''; }

/* Journal d'incidents, même principe que erp_notes() : un envoi qui échoue doit
 * se voir, pas se deviner. Le code n'y figure jamais, le numéro est masqué. */
function sms_notes($note = null) {
  static $n = [];
  if ($note !== null) { $n[] = $note; return null; }
  return $n;
}

/* SMSAPI attend « 32476316972 » : chiffres seuls, indicatif compris, sans « + ».
 * Nos numéros sont en E.164 (+32476316972). */
function sms_num($e164) {
  $d = preg_replace('/\D+/', '', (string) $e164);
  return $d === '' ? '' : $d;
}

/* Pour l'affichage et les journaux : +32 4•• ••• 972. Jamais le numéro entier. */
function sms_masque($e164) {
  $d = sms_num($e164);
  if (strlen($d) < 6) return '';
  return '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 1) . str_repeat('•', 5) . ' ' . substr($d, -3);
}

/* Appel bas niveau. `api2` est le relais de secours documenté ; on ne le tente
 * qu'une fois, et seulement sur panne réseau ou 5xx — un 4xx est une réponse,
 * la rejouer ne changerait rien et pourrait envoyer deux SMS. */
function sms_http($chemin, array $params, $secours = false) {
  $token = sms_cfg('sms_token');
  if ($token === '') return ['ok' => false, 'code' => 0, 'err' => 'sms_desactive'];
  $base = $secours ? 'https://api2.smsapi.pl' : 'https://api.smsapi.pl';
  $c = curl_init($base . $chemin);
  curl_setopt_array($c, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
  ]);
  $corps = curl_exec($c);
  $http  = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
  $errno = curl_errno($c);
  curl_close($c);
  if (($errno !== 0 || $http >= 500) && !$secours) return sms_http($chemin, $params, true);
  if ($errno !== 0) return ['ok' => false, 'code' => 0, 'err' => 'reseau'];
  $j = json_decode((string) $corps, true);
  return ['ok' => $http >= 200 && $http < 300, 'code' => $http,
          'json' => is_array($j) ? $j : null, 'brut' => (string) $corps];
}

/* Traduit un code d'erreur SMSAPI en cause lisible. Les quatre premiers sont
 * ceux qu'on rencontrera réellement en Belgique — le compte est polonais. */
function sms_cause($e) {
  $t = [
    13  => "numéro invalide, fixe, ou sur liste noire",
    14  => "champ expéditeur non valide (à faire valider chez SMSAPI)",
    98  => "compte restreint : n'envoie qu'au numéro d'inscription — à faire activer",
    101 => "jeton absent ou invalide",
    103 => "plus de points sur le compte SMSAPI",
    112 => "l'envoi vers ce pays est bloqué sur le compte — à faire ouvrir pour la Belgique",
    110 => "service SMS non disponible sur ce compte",
  ];
  return $t[(int) $e] ?? ("erreur SMSAPI n° " . (int) $e);
}

/* ENVOI D'UN CODE. Rend true/false — jamais le code lui-même. */
function sms_otp_envoyer($e164) {
  $num = sms_num($e164);
  if ($num === '') { sms_notes("OTP : numéro vide"); return false; }
  if (!sms_enabled()) { sms_notes("OTP : sms_token absent, aucun envoi"); return false; }

  /* Message sans accent hors table GSM 7 bits : au-delà, un SMS tombe de 160 à
     70 caractères et peut se scinder. « Votre code » les évite tous. */
  $modele = sms_cfg('sms_otp_message', "L'Atelier By - votre code de verification : [%code%]");
  if (strpos($modele, '[%code%]') === false) $modele .= ' [%code%]';

  $p = ['phone_number' => $num, 'content' => $modele,
        'fast' => sms_cfg('sms_fast', '1') === '0' ? '0' : '1'];
  $from = sms_cfg('sms_from');
  if ($from !== '') $p['from'] = $from;

  $r = sms_http('/mfa/codes', $p);
  if (!$r['ok']) {
    $e = $r['json']['error'] ?? ($r['json']['message'] ?? $r['err'] ?? '?');
    sms_notes("OTP vers " . sms_masque($e164) . " : " . (is_numeric($e) ? sms_cause($e) : $e));
    return false;
  }
  return true;   /* le code reste ici — cf. l'avertissement en tête de fichier */
}

/* VÉRIFICATION. Rend 'ok' | 'faux' | 'perime' | 'indisponible'. Trois issues
 * distinctes parce qu'elles appellent trois messages différents à l'écran :
 * se tromper, arriver trop tard, ou tomber sur un service muet ne sont pas la
 * même chose pour la personne en face. */
function sms_otp_verifier($e164, $code) {
  $num  = sms_num($e164);
  $code = preg_replace('/\D+/', '', (string) $code);
  if ($num === '' || $code === '') return 'faux';
  if (!sms_enabled()) return 'indisponible';

  $r = sms_http('/mfa/codes/verifications', ['phone_number' => $num, 'code' => $code]);
  if ($r['code'] === 204) return 'ok';
  if ($r['code'] === 404) return 'faux';
  if ($r['code'] === 408) return 'perime';
  sms_notes("Vérification OTP : réponse inattendue HTTP " . $r['code']);
  return 'indisponible';
}

/* Envoi libre (prospection CRM, plus tard confirmations). $detail reçoit ce
 * que l'opérateur a facturé : ['parties' => n, 'points' => x] — un texte
 * hors table GSM 7 bits se scinde à 70 caractères, et ça se voit ici. */
function sms_envoyer($e164, $message, &$detail = null) {
  $num = sms_num($e164);
  if ($num === '' || !sms_enabled()) return false;
  $p = ['to' => $num, 'message' => (string) $message, 'format' => 'json', 'encoding' => 'utf-8'];
  $from = sms_cfg('sms_from');
  if ($from !== '') $p['from'] = $from;
  $r = sms_http('/sms.do', $p);
  if (!$r['ok'] || isset($r['json']['error'])) {
    $e = $r['json']['error'] ?? ($r['json']['message'] ?? $r['err'] ?? '?');
    sms_notes("SMS vers " . sms_masque($e164) . " : " . (is_numeric($e) ? sms_cause($e) : $e));
    return false;
  }
  $l = $r['json']['list'][0] ?? null;
  if (is_array($l)) $detail = ['parties' => (int) ($l['parts'] ?? 1) ?: 1, 'points' => (float) ($l['points'] ?? 0),
                               'statut' => (string) ($l['status'] ?? '')];
  return true;
}

/* État du compte, pour le diagnostic : un OTP qui ne part plus faute de points
 * doit se voir AVANT que les clients ne se plaignent. */
function sms_etat() {
  if (!sms_enabled()) return ['configure' => false];
  $token = sms_cfg('sms_token');
  $c = curl_init('https://api.smsapi.pl/profile');
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                         CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
  $b = curl_exec($c); $http = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  $j = json_decode((string) $b, true);
  if ($http !== 200 || !is_array($j))
    return ['configure' => true, 'joignable' => false, 'http' => $http];
  return ['configure' => true, 'joignable' => true,
          'points' => isset($j['points']) ? (float) $j['points'] : null,
          'expediteur' => sms_cfg('sms_from') ?: '(défaut du compte)',
          'paiement' => $j['payment_type'] ?? null];
}
