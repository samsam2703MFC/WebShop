<?php
/* Téléphones : normalisation (indicatif + national + E.164) et numéro à joindre
   d'après une fiche client. Pur (aucune requête) — partagé par index.php et
   php-api/tests/tel_fiche_test.php. */

function norm_phone($prefix, $raw) {
  $pfx = trim((string) $prefix) !== '' ? trim((string) $prefix) : '+32';
  if ($pfx[0] !== '+') $pfx = '+' . ltrim($pfx, '+');
  $pd = preg_replace('/[^0-9]/', '', $pfx);
  $d  = preg_replace('/[^0-9]/', '', (string) $raw);
  if ($d === '') return [$pfx, '', ''];
  if (strpos($d, '00' . $pd) === 0)                              $d = substr($d, 2 + strlen($pd));
  elseif (strpos($d, $pd) === 0 && strlen($d) > strlen($pd) + 6) $d = substr($d, strlen($pd));
  $d = ltrim($d, '0');
  if ($d === '') return [$pfx, '', ''];
  return [$pfx, '0' . $d, $pfx . $d];
}

/* Numéro E.164 auquel envoyer un SMS à un compte, D'APRÈS SA FICHE (phone_e164,
   sinon phone + phone_prefix). L'E.164 stocké part tel quel ; un numéro national
   seul est complété avec l'indicatif DE LA FICHE, +32 seulement s'il n'y en a pas.
   Avant, tout repassait par norm_phone('+32', …) : un +48 725… enregistré depuis le
   profil PWA (indicatifs européens) ressortait en +3248725… — le code partait dans
   le vide pour tout compte non belge. '' si la fiche n'a pas de numéro. */
function tel_fiche_e164(array $u) {
  $tel = trim((string) ($u['phone_e164'] ?? ''));
  if ($tel === '') $tel = trim((string) ($u['phone'] ?? ''));
  if ($tel === '') return '';
  $d = preg_replace('/\D+/', '', $tel);
  if ($tel[0] === '+') return $d === '' ? '' : '+' . $d;           // déjà international
  if (preg_match('/^00\d{6,}$/', $d)) return '+' . substr($d, 2);   // 00 + indicatif (anciennes fiches)
  $pfx = trim((string) ($u['phone_prefix'] ?? ''));
  [, , $e164] = norm_phone($pfx !== '' ? $pfx : '+32', $tel);
  return (string) $e164;
}
