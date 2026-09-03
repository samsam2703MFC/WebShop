<?php
/* Logo d'entreprise d'un bureau (ws_offices.logo_path, migration 0115).
   Le fichier vit sous assets/office_logos/<id>.<ext> ; la base ne porte que
   le chemin relatif. Il arrive de la console en data: URL (réduit à 600 px
   côté navigateur), dans la ligne du bureau (/franchisee/save, clé logo_url)
   ou dans l'onboarding (/franchisee/onboard-office, clé logo). */

function office_logo_dir(): string { return __DIR__ . '/../assets/office_logos'; }

/* Chemin relatif servi (assets/office_logos/8.png) si le fichier existe, sinon null. */
function office_logo_rel($p): ?string {
  $p = trim((string) ($p ?? ''));
  if ($p === '' || strpos($p, '..') !== false) return null;
  return is_file(__DIR__ . '/../' . ltrim($p, '/')) ? $p : null;
}

/* Contrôle AVANT toute écriture : rend le message d'erreur, ou null si la
   valeur est acceptable ('' = retirer ; URL existante = inchangé ; data: = image). */
function office_logo_check($val): ?string {
  $val = (string) ($val ?? '');
  if ($val === '' || strpos($val, 'data:') !== 0) return null;
  if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=]+)$#', $val, $m)) return 'Logo : PNG, JPEG ou WebP attendu';
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) < 64) return 'Logo illisible';
  if (strlen($bin) > 2 * 1024 * 1024) return 'Logo trop lourd (2 Mo maximum)';
  $info = @getimagesizefromstring($bin);
  if (!$info || !isset($info['mime'])) return 'Logo : image non reconnue';
  if (!isset(['image/png' => 1, 'image/jpeg' => 1, 'image/webp' => 1][$info['mime']])) return 'Logo : PNG, JPEG ou WebP attendu';
  return null;
}

function office_logo_remove(int $officeId, bool $db = true): void {
  foreach (glob(office_logo_dir() . '/' . $officeId . '.*') ?: [] as $f) @unlink($f);
  if ($db) q("UPDATE ws_offices SET logo_path=NULL WHERE id=?", [$officeId]);
}

/* Applique la valeur reçue pour un bureau existant. Rend null quand rien ne
   change (clé absente, URL déjà en place), sinon ['ok'=>…]. */
function office_logo_apply(int $officeId, $val): ?array {
  if ($officeId <= 0 || !col_exists('ws_offices', 'logo_path')) return null;
  $val = (string) ($val ?? '');
  if ($val === '') { office_logo_remove($officeId); return ['ok' => true, 'path' => null]; }
  if (strpos($val, 'data:') !== 0) return null;
  $err = office_logo_check($val);
  if ($err !== null) return ['ok' => false, 'error' => $err];
  preg_match('#^data:image/[a-z]+;base64,(.+)$#', $val, $m);
  $bin  = base64_decode($m[1], true);
  $info = getimagesizefromstring($bin);
  $ext  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']];
  $dir  = office_logo_dir();
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'error' => 'Dossier assets/office_logos non inscriptible sur le serveur'];
  office_logo_remove($officeId, false);
  $name = $officeId . '.' . $ext;
  if (file_put_contents($dir . '/' . $name, $bin) === false) return ['ok' => false, 'error' => 'Logo non enregistré (écriture refusée)'];
  $rel = 'assets/office_logos/' . $name;
  q("UPDATE ws_offices SET logo_path=? WHERE id=?", [$rel, $officeId]);
  return ['ok' => true, 'path' => $rel];
}
