/* Tous les .jsx du webshop parsent-ils ?
 *
 *   node php-api/tests/jsx_parse_test.cjs
 *
 * POURQUOI CE TEST EXISTE. Le webshop compile son JSX DANS LE NAVIGATEUR
 * (webshop-full.html charge @babel/standalone et sert les .jsx tels quels). Il
 * n'y a donc aucune étape de build entre le commit et le client : une
 * parenthèse manquante part en production et n'est découverte qu'à l'écran,
 * page blanche. Ce test est le seul filet avant la mise en ligne.
 *
 * TypeScript ne sert ici que d'ANALYSEUR SYNTAXIQUE : on ne vérifie aucun type,
 * uniquement que le fichier se parse en tant que JSX. C'est exactement ce que
 * Babel fera côté client, sans avoir à embarquer Babel.
 *
 * Il a d'abord été écrit en `node -e "..."` dans le workflow, et il a échoué en
 * CI sur « Cannot read properties of undefined (reading 'ES2020') » : la
 * résolution de `require('typescript')` depuis une chaîne évaluée ne pointait
 * pas où on croyait. Un fichier, comme les autres tests du dossier, se lance
 * pareil à la main et en CI — et se débogue.
 */
const fs = require('fs');
const path = require('path');

// Résolution explicite : le module local du dépôt d'abord, puis l'installation
// globale (poste de développement). Si aucun ne répond, on le DIT et on
// s'abstient — un test qui ne peut pas s'exécuter n'est pas un test qui passe.
let ts = null;
for (const p of [path.join(process.cwd(), 'node_modules', 'typescript'), 'typescript']) {
  try { ts = require(p); break; } catch (_) { /* essai suivant */ }
}
if (!ts || !ts.ScriptTarget) {
  console.error('typescript introuvable (npm install --no-save typescript) — test ignoré.');
  process.exit(0);
}

const racine = process.cwd();
const fichiers = fs.readdirSync(racine).filter((f) => f.endsWith('.jsx'));
if (!fichiers.length) {
  console.error('aucun .jsx à la racine — test ignoré.');
  process.exit(0);
}

let ko = 0;
for (const f of fichiers) {
  const src = fs.readFileSync(path.join(racine, f), 'utf8');
  const sf = ts.createSourceFile(f, src, ts.ScriptTarget.ES2020, true, ts.ScriptKind.JSX);
  const diags = sf.parseDiagnostics || [];
  if (diags.length) {
    ko++;
    const d = diags[0];
    // Ligne et colonne : sans elles, « erreur de syntaxe » dans un fichier de
    // 5 000 lignes ne dit rien d'utile.
    const { line, character } = sf.getLineAndCharacterOfPosition(d.start || 0);
    console.log(`  ✕ ${f}:${line + 1}:${character + 1} — ` +
                ts.flattenDiagnosticMessageText(d.messageText, ' '));
  }
}
if (ko) {
  console.error(`${ko} fichier(s) .jsx ne parsent pas — ils partiraient tels quels en production.`);
  process.exit(1);
}
console.log(`  ✓ les ${fichiers.length} fichiers .jsx parsent`);
process.exit(0);
