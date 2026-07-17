/* =====================================================================
   Landing « Livraison au bureau » — logique de fond (pas de design).
   - Droplist alimentée par ws_tours ACTIVES (GET /delivery-zones),
     options groupées par zone principale (optgroup), chaque option = une
     zone secondaire. Aucune liste écrite en dur : l'ouverture d'une tournée
     fait apparaître sa zone sans toucher à cette page.
   - Dernière option « Ma zone n'est pas dans la liste » → formulaire
     (CP, commune, société, effectif, e-mail) → POST /zone-request
     (persisté en base ws_zone_requests + mail admin, rate-limit IP côté serveur).
   ===================================================================== */
(function () {
  'use strict';
  // La landing est servie sous /landing ; l'API du webshop vit sous /webshop/api
  // (même origine). Surchargée par window.LB_API si besoin.
  var API = (window.LB_API) || (location.origin + '/webshop/api');
  var OTHER = '__other__';
  var root = document.getElementById('root');

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      if (k === 'class') e.className = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }

  function render(zones) {
    root.innerHTML = '';
    var wrap = el('div', { class: 'lb-wrap' });
    wrap.appendChild(el('h1', { text: 'Livraison au bureau' }));
    wrap.appendChild(el('p', { text: 'Choisissez votre zone de livraison.' }));

    // ── Droplist : groupée par zone principale ──
    var sel = el('select', { class: 'lb-select', 'aria-label': 'Zone de livraison' });
    sel.appendChild(el('option', { value: '', text: 'Choisissez votre zone de livraison…' }));

    var groups = {}; var order = [];
    zones.forEach(function (z) {
      var key = z.zonePrincipal || 'Autres zones';
      if (!groups[key]) { groups[key] = []; order.push(key); }
      groups[key].push(z);
    });
    order.forEach(function (key) {
      var og = el('optgroup', { label: key });
      groups[key].forEach(function (z) {
        og.appendChild(el('option', { value: String(z.id), text: z.zoneSecondary || z.tour }));
      });
      sel.appendChild(og);
    });
    // ── « Ma zone n'est pas dans la liste » — toujours en dernière position ──
    sel.appendChild(el('option', { value: OTHER, text: "Ma zone n'est pas dans la liste" }));

    var field = el('label', { class: 'lb-field' }, [ el('span', { text: 'Votre zone' }), sel ]);
    wrap.appendChild(field);

    // ── Zone couverte : confirmation + accès au webshop ──
    var covered = el('div', { class: 'lb-msg lb-hidden' });
    wrap.appendChild(covered);

    // ── Formulaire « hors zone » ──
    var form = el('div', { class: 'lb-hidden' });
    var cp   = el('input', { class: 'lb-input', type: 'text', inputmode: 'numeric', placeholder: '1348' });
    var city = el('input', { class: 'lb-input', type: 'text', placeholder: 'Louvain-la-Neuve' });
    var comp = el('input', { class: 'lb-input', type: 'text', placeholder: 'Nom de la société' });
    var head = el('input', { class: 'lb-input', type: 'number', min: '1', placeholder: 'ex. 25' });
    var mail = el('input', { class: 'lb-input', type: 'email', placeholder: 'vous@societe.be' });
    form.appendChild(el('p', { text: "Dites-nous où vous êtes : trois demandes depuis une même zone déclenchent l'étude d'une tournée." }));
    form.appendChild(el('div', { class: 'lb-row' }, [
      el('label', { class: 'lb-field' }, [ el('span', { text: 'Code postal *' }), cp ]),
      el('label', { class: 'lb-field' }, [ el('span', { text: 'Commune' }), city ]),
    ]));
    form.appendChild(el('label', { class: 'lb-field' }, [ el('span', { text: 'Société' }), comp ]));
    form.appendChild(el('div', { class: 'lb-row' }, [
      el('label', { class: 'lb-field' }, [ el('span', { text: 'Nombre de collaborateurs' }), head ]),
      el('label', { class: 'lb-field' }, [ el('span', { text: 'E-mail de contact' }), mail ]),
    ]));
    var send = el('button', { class: 'lp-header-cta', type: 'button', text: 'Envoyer ma demande' });
    var fmsg = el('div', { class: 'lb-msg' });
    form.appendChild(send); form.appendChild(fmsg);
    wrap.appendChild(form);

    sel.addEventListener('change', function () {
      var v = sel.value;
      form.classList.toggle('lb-hidden', v !== OTHER);
      covered.classList.toggle('lb-hidden', !(v && v !== OTHER && v !== ''));
      if (v && v !== OTHER && v !== '') {
        var z = zones.filter(function (x) { return String(x.id) === v; })[0];
        covered.textContent = z ? ('Votre zone est desservie par la tournée « ' + (z.zoneSecondary || z.tour) + ' ».') : '';
      }
    });

    send.addEventListener('click', function () {
      fmsg.textContent = '';
      if (!cp.value.trim()) { fmsg.textContent = 'Le code postal est requis.'; return; }
      send.disabled = true;
      fetch(API + '/zone-request', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          postalCode: cp.value.trim(), city: city.value.trim(), company: comp.value.trim(),
          headcount: parseInt(head.value, 10) || null, email: mail.value.trim(),
        }),
      }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          send.disabled = false;
          if (res.ok) {
            form.innerHTML = '';
            form.appendChild(el('p', { text: 'Merci ! Votre demande est enregistrée — nous vous recontactons si une tournée ouvre près de chez vous.' }));
          } else {
            fmsg.textContent = (res.j && res.j.error) || 'Une erreur est survenue, réessayez.';
          }
        }).catch(function () { send.disabled = false; fmsg.textContent = 'Réseau indisponible, réessayez.'; });
    });

    root.appendChild(wrap);
  }

  fetch(API + '/delivery-zones')
    .then(function (r) { return r.ok ? r.json() : []; })
    .then(function (zones) { render(Array.isArray(zones) ? zones : []); })
    .catch(function () { render([]); });
})();
