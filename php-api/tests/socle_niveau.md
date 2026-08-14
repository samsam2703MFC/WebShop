# socle_niveau.txt — jusqu'où le socle de test est à jour

Le fichier ne contient qu'un numéro : **la dernière migration déjà appliquée en
production au moment où `socle_test.sql` a été relevé**.

La CI rejoue **toutes les migrations postérieures** à ce numéro, sur le socle.
C'est ce qui manquait, et ça a coûté deux blocages de production dans la même
journée : `migrate.sh` est fail-fast, donc une migration qui échoue empêche tous
les déploiements suivants — pas seulement le sien.

Pourquoi pas rejouer TOUTES les migrations : la `0003` référence `ws_shops`,
table d'avant l'unification vers `shops`. Les anciennes supposent des états de
l'ERP qui n'existent plus ; les rejouer testerait l'historique du schéma, pas le
code d'aujourd'hui.

## Quand le mettre à jour

Quand vous relevez un nouveau `socle_test.sql` depuis une base de production,
inscrivez ici le numéro de la dernière migration qu'elle a appliquée.

**Ne jamais ajouter à la main dans le socle une colonne qu'une migration doit
créer.** La CI passerait sans avoir exécuté cette migration — le trou exact par
lequel la `0070` est arrivée en production.
