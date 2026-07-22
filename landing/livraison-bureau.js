/* =====================================================================
   Landing « Livraison au bureau » — page complète (logique + mise en page).
   - En-tête marque + hero + formulaire dans une carte claire + pied.
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

  // Palette locale (la page ne charge pas les tokens --lp-* de la landing B2B).
  var INK = '#241a16', CREAM = '#fdf6f0', CARD = '#ffffff', RUBY = '#8D1D2C',
      ABRICOT = '#E8A15C', MUTED = '#6b5d54', LINE = '#ece3da';

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

  function header() {
    return el('header', { style: 'position:sticky;top:0;z-index:10;background:rgba(253,246,240,.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-bottom:1px solid ' + LINE }, [
      el('div', { style: 'max-width:960px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px' }, [
        el('a', { href: '/webshop/', style: 'font-family:var(--font-display,Vank),serif;font-size:21px;color:' + RUBY + ';text-decoration:none;letter-spacing:.02em', text: "L'Atelier By" }),
        el('a', { href: '/webshop/', style: 'font:500 13px var(--font-ui,system-ui);color:' + MUTED + ';text-decoration:none', text: '← Retour à la boutique' }),
      ]),
    ]);
  }

  function footer() {
    return el('footer', { style: 'text-align:center;padding:34px 20px 48px;color:#9b8f86;font:400 12px var(--font-ui,system-ui)' }, [
      el('span', { text: '© 2026 L’Atelier By — Maison de pains et viennoiseries · Belgique.' }),
    ]);
  }

  function fieldStyle() {
    return 'width:100%;box-sizing:border-box;padding:11px 13px;font:inherit;color:' + INK +
      ';background:#fff;border:1px solid ' + LINE + ';border-radius:10px;outline:none';
  }

  function render(zones) {
    root.innerHTML = '';
    root.appendChild(header());

    var wrap = el('main', { style: 'max-width:640px;margin:0 auto;padding:28px 20px 8px' });

    // ── Hero ──
    wrap.appendChild(el('div', { style: 'text-align:center;padding:10px 0 22px' }, [
      el('h1', { style: 'font-family:var(--font-display,Vank),serif;font-size:34px;line-height:1.1;color:' + INK + ';margin:0 0 12px', text: 'Livraison au bureau' }),
      el('p', { style: 'font:400 15px/1.55 var(--font-ui,system-ui);color:' + MUTED + ';margin:0 auto;max-width:520px', text: "L’Atelier By livre vos bureaux. Choisissez votre zone de livraison — ou signalez-nous la vôtre si elle n’est pas encore desservie." }),
    ]));

    // ── Carte : le formulaire (ancre #contact) ──
    var card = el('div', { id: 'contact', style: 'scroll-margin-top:88px;background:' + CARD + ';border:1px solid ' + LINE + ';border-radius:16px;padding:24px 22px;box-shadow:0 12px 34px rgba(36,26,22,.06)' });

    card.appendChild(el('div', { style: 'font:600 15px var(--font-ui,system-ui);color:' + INK + ';margin-bottom:4px', text: 'Organiser mes livraisons' }));
    card.appendChild(el('p', { style: 'font:400 12.5px/1.5 var(--font-ui,system-ui);color:' + MUTED + ';margin:0 0 16px', text: 'Choisissez votre zone de livraison.' }));

    // Droplist groupée par zone principale
    var sel = el('select', { class: 'lb-select', style: fieldStyle() + ';appearance:auto', 'aria-label': 'Zone de livraison' });
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
    sel.appendChild(el('option', { value: OTHER, text: "Ma zone n'est pas dans la liste" }));

    card.appendChild(el('label', { class: 'lb-field', style: 'display:block;margin:0 0 4px' }, [
      el('span', { style: 'display:block;margin-bottom:6px;font:600 12px var(--font-ui,system-ui);color:' + INK, text: 'Votre zone' }),
      sel,
    ]));

    // Zone couverte : confirmation
    var covered = el('div', { style: 'display:none;margin-top:14px;padding:12px 14px;border-radius:10px;background:#eef6ef;border:1px solid #d3e6d5;color:#2d7a3e;font:500 13px var(--font-ui,system-ui)' });
    card.appendChild(covered);

    // Formulaire « hors zone »
    var form = el('div', { style: 'display:none;margin-top:16px' });
    var cp   = el('input', { class: 'lb-input', style: fieldStyle(), type: 'text', inputmode: 'numeric', placeholder: '1348' });
    var city = el('input', { class: 'lb-input', style: fieldStyle(), type: 'text', placeholder: 'Louvain-la-Neuve' });
    var comp = el('input', { class: 'lb-input', style: fieldStyle(), type: 'text', placeholder: 'Nom de la société' });
    var head = el('input', { class: 'lb-input', style: fieldStyle(), type: 'number', min: '1', placeholder: 'ex. 25' });
    var mail = el('input', { class: 'lb-input', style: fieldStyle(), type: 'email', placeholder: 'vous@societe.be' });
    var lbl = function (t, node) { return el('label', { style: 'display:block;flex:1;min-width:150px;margin:0 0 12px' }, [
      el('span', { style: 'display:block;margin-bottom:6px;font:600 12px var(--font-ui,system-ui);color:' + INK, text: t }), node ]); };
    form.appendChild(el('p', { style: 'font:400 12.5px/1.55 var(--font-ui,system-ui);color:' + MUTED + ';margin:2px 0 14px', text: "Dites-nous où vous êtes : trois demandes depuis une même zone déclenchent l’étude d’une tournée." }));
    form.appendChild(el('div', { style: 'display:flex;gap:12px;flex-wrap:wrap' }, [ lbl('Code postal *', cp), lbl('Commune', city) ]));
    form.appendChild(lbl('Société', comp));
    form.appendChild(el('div', { style: 'display:flex;gap:12px;flex-wrap:wrap' }, [ lbl('Nombre de collaborateurs', head), lbl('E-mail de contact', mail) ]));
    var send = el('button', { type: 'button', style: 'margin-top:4px;background:' + RUBY + ';color:#fff;border:none;border-radius:999px;padding:13px 24px;font:600 14px var(--font-ui,system-ui);cursor:pointer', text: 'Envoyer ma demande' });
    var fmsg = el('div', { style: 'margin-top:12px;font:500 13px var(--font-ui,system-ui);color:' + RUBY });
    form.appendChild(send); form.appendChild(fmsg);
    card.appendChild(form);

    wrap.appendChild(card);
    root.appendChild(wrap);
    root.appendChild(footer());

    sel.addEventListener('change', function () {
      var v = sel.value;
      form.style.display = (v === OTHER) ? 'block' : 'none';
      var isCov = !!(v && v !== OTHER && v !== '');
      covered.style.display = isCov ? 'block' : 'none';
      if (isCov) {
        var z = zones.filter(function (x) { return String(x.id) === v; })[0];
        covered.textContent = z ? ('Votre zone est desservie par la tournée « ' + (z.zoneSecondary || z.tour) + ' ». Vous pouvez commander dès maintenant.') : '';
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
            form.appendChild(el('p', { style: 'font:500 14px/1.5 var(--font-ui,system-ui);color:#2d7a3e', text: 'Merci ! Votre demande est enregistrée — nous vous recontactons si une tournée ouvre près de chez vous.' }));
          } else {
            fmsg.textContent = (res.j && res.j.error) || 'Une erreur est survenue, réessayez.';
          }
        }).catch(function () { send.disabled = false; fmsg.textContent = 'Réseau indisponible, réessayez.'; });
    });
  }

  fetch(API + '/delivery-zones')
    .then(function (r) { return r.ok ? r.json() : []; })
    .then(function (zones) { render(Array.isArray(zones) ? zones : []); })
    .catch(function () { render([]); });
})();
