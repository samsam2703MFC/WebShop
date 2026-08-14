/* webshop-bug-banner.jsx — le bandeau d'erreur du WEBSHOP.
 *
 * POURQUOI IL EXISTE. Les deux back-offices ont un bandeau rouge qui annonce
 * les échecs de chargement (__BO_RENDER_ERRORS). Le webshop, lui, n'avait que
 * des console.error : dix appels — catégories, assortiments, cross-sell, frais
 * de livraison, stock, session — échouaient en silence pour qui ne garde pas la
 * console ouverte. Une grille vide ressemblait alors exactement à une boutique
 * sans produit, et c'est ce qui a fait chercher pendant une heure des articles
 * qui n'avaient jamais manqué.
 *
 * RÈGLE DU DÉPÔT : aucun repli, aucune donnée inventée. Sans repli, un appel qui
 * échoue laisse un écran vide — il FAUT donc que l'écran le dise, sinon on a
 * remplacé un mensonge par un silence.
 *
 * DOM PUR, HORS REACT. Le bandeau doit s'afficher même si React ne monte pas,
 * ou monte à moitié : c'est précisément là qu'on en a le plus besoin. Il ne
 * dépend d'aucun composant, d'aucun état, et se construit à la première erreur.
 *
 * IL RESTE CRÉDIBLE. Il n'apparaît que sur un échec réel — jamais sur une liste
 * vide, qui est une réponse valable du serveur. Un bandeau permanent n'est plus
 * lu, et le jour où il dit quelque chose, personne ne le voit.
 */
(function () {
  var erreurs = [];      // { quoi, detail } — dédupliquées par `quoi`
  var el = null;

  function corps() {
    return erreurs.map(function (e) {
      return '• [' + e.quoi + '] ' + e.detail;
    }).join('\n');
  }

  function rendre() {
    try {
      if (!el) {
        el = document.createElement('div');
        el.id = 'ws-bug';
        el.setAttribute('role', 'alert');
        el.style.cssText =
          'position:fixed;top:0;left:0;right:0;z-index:99999;background:#8d1d2c;color:#fff;' +
          'font:600 13px/1.45 system-ui,sans-serif;padding:10px 44px 10px 14px;' +
          'box-shadow:0 3px 14px rgba(0,0,0,.3);white-space:pre-wrap';
        var x = document.createElement('button');
        x.textContent = '✕';
        x.setAttribute('aria-label', 'Fermer');
        x.style.cssText = 'position:absolute;top:6px;right:8px;background:none;border:none;' +
                          'color:#fff;font-size:16px;cursor:pointer';
        x.onclick = function () { el.remove(); el = null; };
        el.appendChild(x);
        var b = document.createElement('div');
        b.id = 'ws-bug-corps';
        el.appendChild(b);
        (document.body || document.documentElement).appendChild(el);
      }
      var c = document.getElementById('ws-bug-corps');
      if (c) c.textContent = '⚠ ERREUR — PLEASE DEBUG (' + erreurs.length + ')\n' + corps();
    } catch (_) { /* si même ça échoue, il reste la console */ }
  }

  /* note(quoi, detail) — `quoi` identifie la source (« catalogue », « stock »…)
     et sert de clé de déduplication : un appel qui échoue à chaque frappe ne
     doit pas empiler cinquante lignes identiques. */
  function note(quoi, detail) {
    var txt = (detail && detail.message) ? detail.message : String(detail || 'échec');
    for (var i = 0; i < erreurs.length; i++) {
      if (erreurs[i].quoi === quoi) { erreurs[i].detail = txt; rendre(); return; }
    }
    erreurs.push({ quoi: quoi, detail: txt });
    rendre();
  }

  window.WSBug = { note: note, list: function () { return erreurs.slice(); } };

  /* Ce que le code applicatif ne peut pas attraper : une promesse rejetée sans
     .catch, une exception de rendu. Sans ça, l'écran reste blanc sans un mot. */
  window.addEventListener('unhandledrejection', function (ev) {
    note('promesse', (ev && ev.reason && ev.reason.message) || String((ev && ev.reason) || 'rejet non traité'));
  });
  window.addEventListener('error', function (ev) {
    if (ev && ev.message) note('script', ev.message);
  });
})();
