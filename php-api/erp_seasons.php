<?php
/* ============================================================================
 * GAMMES SAISONNIÈRES servies par l'ERP.
 *
 *   GET {base}/product-availability-periods                → les gammes
 *   GET {base}/product-availability-periods/name-aliases        → noms traduits
 *   GET {base}/product-availability-periods/description-aliases → descriptions
 *
 * Ce que ça remplace : ws_season (nom, slug, icône, ordre) — une table locale
 * que personne ne pouvait traduire et qui ignorait 13 des 15 gammes du réseau.
 *
 * DEUX DRAPEAUX, DEUX SENS — la nuance décide de ce qui s'affiche :
 *   is_active       : la gamme existe et tourne en magasin ;
 *   webshop_active  : elle est PUBLIÉE sur le webshop.
 * On n'affiche que les deux à 1. Le pendant exact de ce qu'on fait déjà sur
 * les produits ; une gamme active en boutique n'est pas forcément une gamme
 * qu'on veut mettre en avant en ligne.
 *
 * LE LIBELLÉ EST NETTOYÉ, et pas par coquetterie : l'ERP nomme ses gammes
 * pour la GESTION — « 🌸 Gamme Printanière (Mars à Mai) ». Sur une vignette,
 * « Gamme » est un mot de métier et la période fait doublon avec le filtre de
 * date déjà affiché. On garde « Printanière », et l'emoji sert d'icône de
 * repli quand le webshop n'a pas de dessin pour cette gamme. Le libellé
 * COMPLET reste renvoyé (`labelFull`) : si un écran le veut, il l'a.
 *
 * Inerte tant que ws_param.seasons_source ne vaut pas 'erp'.
 * ========================================================================== */

function erp_seasons_enabled() {
  if (!function_exists('ws_param')) return false;
  try { return strtolower((string) (ws_param('seasons_source', '') ?: '')) === 'erp'; }
  catch (Throwable $e) { return false; }
}

/* « 🌸 Gamme Printanière (Mars à Mai) » → ['🌸', 'Printanière', libellé complet] */
function erp_season_libelle($nom) {
  $nom = trim((string) $nom);
  if ($nom === '') return ['', '', ''];
  $emoji = '';
  // Un préfixe non alphanumérique est un pictogramme : on le détache.
  if (preg_match('/^([^\p{L}\p{N}]+)\s*/u', $nom, $m)) {
    $emoji = trim($m[1]);
    $nom = trim(mb_substr($nom, mb_strlen($m[0])));
  }
  $full = $nom;
  $court = preg_replace('/\s*\([^)]*\)\s*$/u', '', $nom);        // « (Mars à Mai) »
  $court = preg_replace('/^Gamme\s+/iu', '', trim($court));       // « Gamme »
  if ($court === '') $court = $full;
  return [$emoji, $court, $full];
}

/* Slug stable, pour rattacher les icônes déjà dessinées (ete.png, automne.png…)
 * sans deviner par le libellé. Sans accent, sans espace. */
function erp_season_slug($court) {
  $s = mb_strtolower(trim((string) $court));
  $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                  'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','–'=>'-','’'=>"'"]);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  return trim((string) $s, '-');
}

/* Le chemin PUBLIC de la photo d'une gamme, ou null.
 * Lit le dossier une seule fois : la barre de gammes appelle ceci une fois par
 * période, et un is_file() par extension et par gamme à chaque affichage de
 * page se paie sur un catalogue à cache zéro. */
function erp_season_photo($id) {
  static $map = null;
  if ($map === null) {
    $map = [];
    $dir = __DIR__ . '/../assets/season_pictures';
    if (is_dir($dir)) {
      foreach (scandir($dir) ?: [] as $f)
        if (preg_match('/^(\d+)\.(png|jpe?g|webp)$/i', $f, $mm))
          $map[$mm[1]] = 'assets/season_pictures/' . $f;
    }
  }
  return $map[(string) $id] ?? null;
}

/* Les gammes PUBLIÉES, dans la langue demandée.
 * Rend [] si la source est inerte ou l'API muette — l'appelant sert alors une
 * barre vide : depuis la 0100 il n'y a plus de table locale à laquelle revenir. */
function erp_seasons($lang = '') {
  static $cache = [];
  $lg = strtolower(substr((string) $lang, 0, 2));
  if (isset($cache[$lg])) return $cache[$lg];
  if (!erp_seasons_enabled() || !function_exists('erp_get')) return $cache[$lg] = [];

  $d = erp_get('product-availability-periods', 300);
  $lst = is_array($d) ? (array_is_list($d) ? $d : ($d['items'] ?? $d['data'] ?? null)) : null;
  if (!is_array($lst)) return $cache[$lg] = [];

  // Traductions : mêmes clés fk_id/alias_value que les produits — erp_alias_map
  // les lit déjà. alias_value AVANT effective_value (qui retombe sur le
  // français), sinon on servirait du français en croyant traduire.
  $nomTr = $desTr = [];
  if ($lg !== '' && function_exists('erp_alias_map')) {
    foreach (erp_alias_map('product-availability-periods/name-aliases?lang_code=' . urlencode($lg)) as $id => $byL)
      if (!empty($byL[$lg])) $nomTr[(string) $id] = $byL[$lg];
    foreach (erp_alias_map('product-availability-periods/description-aliases?lang_code=' . urlencode($lg)) as $id => $byL)
      if (!empty($byL[$lg])) $desTr[(string) $id] = $byL[$lg];
  }

  $out = [];
  foreach ($lst as $r) {
    if (!is_array($r) || empty($r['id'])) continue;
    if (isset($r['is_active']) && !(int) $r['is_active']) continue;
    // PUBLIÉE sur le webshop, et rien d'autre. Champ absent (ERP plus ancien)
    // → on n'affiche pas : mieux vaut une barre vide qu'une gamme interne
    // exposée aux clients.
    if (!array_key_exists('webshop_active', $r) || !(int) $r['webshop_active']) continue;

    $id = (int) $r['id'];
    $nom = $nomTr[(string) $id] ?? (string) ($r['name'] ?? ($r['base_name'] ?? ''));
    [$emoji, $court, $full] = erp_season_libelle($nom);
    if ($court === '') continue;
    $out[] = [
      'id'          => erp_season_slug($court),   // le front matche par cet id
      'erpId'       => $id,
      'label'       => $court,
      'labelFull'   => $full,
      'emoji'       => $emoji,
      'description' => (string) ($desTr[(string) $id] ?? ($r['description'] ?? '')),
      'fromMd'      => isset($r['from_md']) ? (int) $r['from_md'] : null,
      'toMd'        => isset($r['to_md'])   ? (int) $r['to_md']   : null,
      'recurring'   => !empty($r['is_recurring']),
      /* La photo VENUE DE L'ERP, rapatriée par sync_season_photos.php. On ne
         sert JAMAIS l'URL de l'ERP telle quelle : elle est signée et expire en
         1200 s, la vignette serait morte vingt minutes après. On ne sert que
         le fichier réellement présent — absent, `null`, et le front dessine
         son emoji plutôt que de pointer une image manquante. */
      'img'         => erp_season_photo($id),
    ];
  }
  return $cache[$lg] = $out;
}

/* La gamme À METTRE EN AVANT sur un produit qui en porte plusieurs.
 * Règle : la période la plus COURTE gagne — « Chandeleur » (16 jours) dit
 * quelque chose au client, « Standard » (toute l'année) ne dit rien. À défaut
 * de règle de priorité côté ERP, c'est celle qui informe le plus. */
function erp_season_principale(array $periodes, array $publiees) {
  $parId = [];
  foreach ($publiees as $p) $parId[(int) $p['erpId']] = $p;
  $best = null; $bestDur = PHP_INT_MAX;
  foreach ($periodes as $per) {
    if (!is_array($per) || empty($per['id'])) continue;
    $id = (int) $per['id'];
    if (!isset($parId[$id])) continue;                      // non publiée → ignorée
    $f = (int) ($per['from_md'] ?? 0); $t = (int) ($per['to_md'] ?? 0);
    // Durée en jours calendaires approchés (MMJJ), gérant le passage d'année.
    $j = fn ($md) => ((int) ($md / 100)) * 31 + ($md % 100);
    $dur = $j($t) - $j($f); if ($dur < 0) $dur += 372;
    if ($dur < $bestDur) { $bestDur = $dur; $best = $parId[$id]; }
  }
  return $best;
}
