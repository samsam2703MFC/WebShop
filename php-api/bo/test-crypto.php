<?php
/* ============================================================================
 * test-crypto.php — Auto-test de l'ISOLATION cryptographique, SANS base.
 * Vérifie que les jetons d'un BO sont invalides pour l'autre (secret + scope),
 * la détection d'altération, l'expiration et l'unicité du jeton CSRF par BO.
 *
 * Nécessite un config.php contenant une section 'bo' (deux secrets DIFFÉRENTS).
 * Lancer :  php bo/test-crypto.php   (depuis php-api/)
 * ========================================================================== */
require __DIR__ . '/../lib.php';   // charge aussi bo/bootstrap.php (guard, etc.)

$pass = 0; $fail = 0;
function check($label, $cond) {
  global $pass, $fail;
  if ($cond) { echo "  ✅ $label\n"; $pass++; } else { echo "  ❌ $label\n"; $fail++; }
}

$payload = ['id' => 1, 'role' => 'franchise', 'sid' => 'abc', 'exp' => time() + 300];
$tFee = bo_sign('franchisee', $payload);
$tFor = bo_sign('franchisor', ['id' => 9, 'role' => 'siege', 'sid' => 'zzz', 'exp' => time() + 300]);

check("jeton franchisé valide pour SON BO",           is_array(bo_verify('franchisee', $tFee)) && bo_verify('franchisee', $tFee)['id'] === 1);
check("jeton franchiseur valide pour SON BO",         is_array(bo_verify('franchisor', $tFor)));

/* Le cœur : aucune fuite entre BO */
check("jeton FRANCHISÉ rejeté par le BO FRANCHISEUR", bo_verify('franchisor', $tFee) === null);
check("jeton FRANCHISEUR rejeté par le BO FRANCHISÉ", bo_verify('franchisee', $tFor) === null);

/* Altération de la signature */
$tamper = substr($tFee, 0, -2) . (substr($tFee, -1) === 'A' ? 'BB' : 'AA');
check("signature altérée rejetée",                    bo_verify('franchisee', $tamper) === null);

/* Expiration */
$expired = bo_sign('franchisee', ['id' => 1, 'sid' => 'x', 'exp' => time() - 10]);
check("jeton expiré rejeté",                          bo_verify('franchisee', $expired) === null);

/* Scope forgé : un payload sans le bon `bo` ne passe pas (bo_sign force `bo`,
   donc on teste qu'un jeton signé franchisee ne peut jamais se faire passer
   pour franchisor même si on tentait de réutiliser sa signature) */
check("secret par BO distinct (jetons non interchangeables)", $tFee !== $tFor && bo_verify('franchisor', $tFee) === null);

/* CSRF unique par BO */
check("jeton CSRF distinct entre BO pour un même sid",
      bo_csrf('franchisee', 'same-sid') !== bo_csrf('franchisor', 'same-sid'));

echo "\nRésultat crypto : $pass OK / $fail KO\n";
exit($fail === 0 ? 0 : 1);
