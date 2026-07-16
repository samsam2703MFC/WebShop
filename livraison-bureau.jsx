/* =====================================================================
   L'Atelier By — Livraison Bureau (office-delivery landing page)

   Imported from Claude Design "Livraison Bureau.dc.html" and ported to the
   webshop's React 18 UMD + Babel Standalone runtime (no build step).

   Self-contained B2B lead-capture landing: the visitor picks their delivery
   zone, sees the exact modalities of the shop that serves it (days, cutoff,
   slots, minimum), and submits a contact request that is routed to that shop.
   The zone/shop referential below mirrors the network model; wiring it to a
   live endpoint only requires swapping `shops`/`zones` for an API fetch.
   Mounts into #root and exposes window.LivraisonBureau.
   ===================================================================== */
(function () {
  const { useState, useEffect, useRef } = React;

  // ---- Référentiel réseau (place names — non traduits) ----
  const SHOPS = {
    0: { id: 0, city: 'Réseau', email: 'direction@latelierby.be', is_system: true },
    1: { id: 1, name: 'Halle', city: 'Halle', email: 'halle@latelierby.be' },
    2: { id: 2, name: 'Corbais', city: 'Corbais', email: 'corbais@latelierby.be' },
    3: { id: 3, name: 'Gosselies', city: 'Gosselies', email: 'gosselies@latelierby.be' },
    4: { id: 4, name: 'Sombreffe', city: 'Sombreffe', email: 'sombreffe@latelierby.be' },
    5: { id: 5, name: 'Gembloux', city: 'Gembloux', email: 'gembloux@latelierby.be' },
    6: { id: 6, name: 'Wavre', city: 'Wavre', email: 'wavre@latelierby.be' }
  };
  const ZONES = [
    { id: 1, shop_id: 6, region: 'bw', zone_name: 'Wavre & Rixensart', city: 'Wavre', cutoff_time: '16:00', days: ['lun', 'mar', 'mer', 'jeu', 'ven'], slots: ['07:30 – 09:00', '09:00 – 10:00', '11:30 – 13:00'], min: 6, note: { fr: 'Créneau lunch disponible toute la semaine.', nl: 'Lunchtijdvak de hele week beschikbaar.' }, priority: 1, is_active: true },
    { id: 2, shop_id: 2, region: 'bw', zone_name: 'Louvain-la-Neuve & Ottignies', city: 'Louvain-la-Neuve', cutoff_time: '17:00', days: ['lun', 'mar', 'mer', 'jeu', 'ven'], slots: ['07:30 – 09:00', '09:00 – 10:00', '11:30 – 13:00'], min: 6, note: { fr: 'Accès campus : badge visiteur requis.', nl: 'Campustoegang: bezoekersbadge vereist.' }, priority: 2, is_active: true },
    { id: 3, shop_id: 2, region: 'bw', zone_name: "Nivelles & Braine-l'Alleud", city: 'Nivelles', cutoff_time: '16:00', days: ['mar', 'jeu', 'ven'], slots: ['08:00 – 09:30'], min: 8, note: null, priority: 3, is_active: true },
    { id: 4, shop_id: 1, region: 'bf', zone_name: 'Halle & environs', city: 'Halle', cutoff_time: '16:00', days: ['mar', 'mer', 'jeu', 'ven'], slots: ['07:30 – 09:00', '09:00 – 10:30'], min: 8, note: { fr: 'Samedi sur demande pour les événements.', nl: 'Zaterdag op aanvraag voor evenementen.' }, priority: 1, is_active: true },
    { id: 5, shop_id: 1, region: 'bf', zone_name: 'Beersel – Sint-Pieters-Leeuw', city: 'Beersel', cutoff_time: '15:00', days: ['mar', 'jeu'], slots: ['08:00 – 09:30'], min: 10, note: null, priority: 2, is_active: true },
    { id: 6, shop_id: 5, region: 'nm', zone_name: 'Gembloux & campus agro', city: 'Gembloux', cutoff_time: '17:00', days: ['mar', 'mer', 'jeu', 'ven'], slots: ['07:30 – 09:00', '09:00 – 10:30'], min: 6, note: null, priority: 1, is_active: true },
    { id: 7, shop_id: 4, region: 'nm', zone_name: 'Sombreffe – Ligny', city: 'Sombreffe', cutoff_time: '16:30', days: ['lun', 'mer', 'ven'], slots: ['07:45 – 09:15'], min: 8, note: { fr: 'Livraison groupée le mercredi.', nl: 'Gegroepeerde levering op woensdag.' }, priority: 2, is_active: true },
    { id: 8, shop_id: 6, region: 'eb', zone_name: 'Overijse – Tervuren', city: 'Overijse', cutoff_time: '15:30', days: ['mar', 'mer', 'ven'], slots: ['08:00 – 09:30'], min: 8, note: null, priority: 1, is_active: true },
    { id: 9, shop_id: 2, region: 'eb', zone_name: 'Auderghem – Woluwe', city: 'Auderghem', cutoff_time: '15:00', days: ['mar', 'jeu'], slots: ['08:00 – 09:30'], min: 10, note: { fr: 'Accès parking limité : livraison au rez.', nl: 'Beperkte parking: levering op gelijkvloers.' }, priority: 2, is_active: true }
  ];

  const PRODUCT_KEYS = ['sandwich', 'fruits', 'dessert', 'salades', 'breakfast'];
  // Real photography for each product key (design shipped empty image-slots).
  // Chosen for a consistent photographic look across the carousel.
  const PRODUCT_IMAGES = {
    sandwich: 'img/p-sandwich-club.png',
    fruits: 'img/p-chou-farci.png',
    dessert: 'img/p-parfait.png',
    salades: 'img/p-salade-chef-saumon.png',
    breakfast: 'img/p-sand-oeufs-bacon.png'
  };

  // ---- i18n ----
  const T = {
    fr: {
      navZone: 'Votre zone', navProcess: 'Comment ça marche', navFormules: 'Les formules', navProduits: 'Nos produits', navFaq: 'FAQ', ctaHeader: 'Être contacté',
      heroEyebrow: 'Livraison au bureau', heroTitle: 'La boulangerie qui vient', heroScript: 'au bureau.',
      heroLede: "Petits-déjeuners, pauses et lunchs faits maison, déposés à votre équipe le matin. En ponctuel ou chaque semaine — c'est votre boutique de quartier qui livre.",
      heroCta1: 'Organiser les livraisons de mon bureau', heroCta2: 'Voir comment ça marche',
      heroNote: "Ceci n'est pas qu'une boulangerie — c'est votre pause du matin, livrée.",
      zoneEyebrow: 'Votre zone pilote tout', zoneTitle: 'Où livrons-nous ?',
      zoneLede: 'Nos jours, horaires et créneaux dépendent de la boutique qui vous livre. Choisissez votre zone : vous verrez aussitôt vos modalités exactes.',
      zoneSelectLabel: 'Sélectionnez votre zone de livraison', zoneChoose: 'Choisir une zone…', zoneOutOption: "Ma zone n'est pas dans la liste",
      zoneGeneric: "Chaque boutique du réseau organise ses propres tournées. Indiquez votre zone : les jours livrés, l'heure limite de commande, les créneaux et le minimum s'afficheront ici — aucun chiffre générique qui serait faux ailleurs.",
      zoneLivePar: 'Livré par', lblDays: 'Jours livrés', lblCutoff: 'Heure limite de commande', lblSlots: 'Créneaux de livraison', lblMin: 'Minimum de commande',
      zoneCta: 'Organiser mes livraisons dans cette zone', cutoffSuffix: ', la veille', minSuffix: 'personnes min.',
      zoneOutTitle: "Votre zone n'est pas encore couverte ?", zoneOutBody: 'Laissez-nous votre commune : chaque demande hors zone nous aide à cartographier où étendre nos tournées.',
      zoneOutLabel: 'Code postal / commune', zoneOutPh: 'ex. 1300 Wavre', zoneOutCta: 'Enregistrer ma demande',
      shopHintSys: 'Demande dirigée vers la direction opérationnelle.', shopHintPrefix: 'Livré par la boutique de',
      regions: { bw: 'Brabant wallon', bf: 'Brabant flamand', nm: 'Namur', eb: 'Est de Bruxelles' },
      days: { lun: 'Lun', mar: 'Mar', mer: 'Mer', jeu: 'Jeu', ven: 'Ven', sam: 'Sam', dim: 'Dim' },
      ucEyebrow: 'Pour qui, pour quoi', ucTitle: "Chaque moment d'équipe a sa formule.",
      ucLede: "Que vous soyez cinq ou quatre-vingts, on s'occupe des pauses qui font du bien au bureau.",
      useCases: [
        { name: 'Réunions & comités', description: 'De quoi tenir une matinée de travail : viennoiseries, café, eau, sans interrompre le rythme.', icon: 'img/sandwiches.png' },
        { name: "Petits-déjeuners d'accueil", description: 'Recevez clients et partenaires avec un petit-déjeuner qui donne le ton.', icon: 'img/croissant.png' },
        { name: 'Onboarding & arrivées', description: 'Marquez le premier jour des nouveaux : une attention simple, chaleureuse, sur le bureau.', icon: 'img/rolls.png' },
        { name: 'Anniversaires & pots', description: 'Un moment à fêter ? Cakes, tartes et pièces sucrées, livrés prêts à partager.', icon: 'img/cupcake.png' },
        { name: "Vendredis d'équipe", description: 'Terminez la semaine en douceur avec une pause gourmande récurrente.', icon: 'img/cookies.png' }
      ],
      procEyebrow: 'Comment ça marche', procTitle: 'Quatre étapes, zéro gestion.',
      steps: [
        { title: "Vous nous dites ce qu'il vous faut", description: 'Nombre de personnes, type de pause, dates envisagées. En deux minutes.' },
        { title: 'Votre boutique vous propose une formule', description: 'Adaptée à votre zone et à ses modalités de livraison.' },
        { title: 'Vous validez votre planning', description: "Ponctuel ou récurrent, ajustable d'une semaine à l'autre. Vous gardez la main." },
        { title: 'On livre, on facture en fin de mois', description: "Livraison au bureau à l'heure convenue. Une seule facture mensuelle, rien à gérer." }
      ],
      stepZoneHint: 'Choisir ma zone pour voir mes modalités →', stepBefore: 'avant',
      offEyebrow: 'Les formules', offTitle: 'Des compositions pensées pour le bureau.',
      offLede: 'Votre boutique adapte les quantités et la mise en place. On vous propose une formule, jamais un catalogue à trier.',
      offerBadge: 'Dispo en hebdo', lblFor: 'Pour :', lblSetup: 'Mise en place :',
      offers: [
        { name: "Le Petit-Déjeuner d'équipe", description: 'La corbeille du matin : viennoiseries pur beurre, pains et jus pressé.', icon: 'img/croissant.png', includes: ['Croissants & pains au chocolat', 'Petits pains & confitures maison', 'Jus de fruits frais'], audience: 'Matinées de travail, réunions', setup: 'Corbeilles + serviettes incluses', rec: true },
        { name: 'La Pause Gourmande', description: "Le petit plaisir de l'après-midi, sucré et réconfortant.", icon: 'img/cookies.png', includes: ['Cookies & financiers du jour', 'Cakes tranchés', 'Café et thé en option'], audience: 'Pauses, fins de journée', setup: 'Prêt à partager', rec: true },
        { name: 'Le Lunch au Bureau', description: 'Un déjeuner complet et fait maison, sans sortir du bureau.', icon: 'img/sandwiches.png', includes: ['Sandwiches garnis & wraps', 'Salades de saison', 'Tartes salées'], audience: "Déjeuners d'équipe, ateliers", setup: 'Vaisselle & mise en place incluses', rec: false },
        { name: 'Le Plateau Célébration', description: 'Pour marquer le coup : pièces festives et desserts à partager.', icon: 'img/cake-slice.png', includes: ['Tartes sucrées & entremets', 'Cupcakes décorés', 'Pièces à partager'], audience: 'Anniversaires, pots, événements', setup: 'Présentoir & découpe inclus', rec: true }
      ],
      recEyebrow: 'Récurrence', recTitle: 'Envie de régularité ? Passez en hebdo.',
      recBody: "Un abonnement hebdomadaire pour vos formules éligibles. Vous gardez la main : modifiez, mettez en pause ou annulez d'une semaine à l'autre, sans engagement.",
      recPoints: ['Livraison hebdomadaire automatique', 'Pause ou annulation en un clic', 'Sans engagement de durée'],
      prodEyebrow: 'Nos produits', prodTitle: 'Frais du jour, sans exception.',
      prodLede: "Dans tout le réseau L'Atelier By, tout est préparé et livré le jour même. Nos salades sont composées et assaisonnées à la main, chaque matin en boutique.",
      prodBadge: 'Frais du jour', prodAssure: ['Tout est frais du jour', 'Salades faites maison', 'Livré le matin même'],
      products: [
        { title: 'Plateau de sandwichs', note: 'Pains du jour, garnitures fraîches, tranchés et dressés le matin même.' },
        { title: 'Plateau de fruits', note: 'Fruits de saison découpés le jour de la livraison, jamais la veille.' },
        { title: 'Buffet dessert', note: 'Tartes, entremets et douceurs préparés en boutique par nos pâtissiers.' },
        { title: 'Salades maison', note: 'Composées et assaisonnées à la main, chaque matin — recettes maison.' },
        { title: 'Plateau petit-déjeuner', note: 'Viennoiserie pur beurre et pains, encore tièdes à la livraison.' }
      ],
      specs: [
        { value: '80', label: 'personnes servies en une seule livraison' },
        { value: '6', label: 'boutiques artisanales partout en Belgique' },
        { value: '07:30', label: 'au bureau, viennoiseries encore tièdes' },
        { value: '1', label: 'facture mensuelle, zéro gestion' }
      ],
      clientsLabel: 'Ils font livrer leurs équipes', clients: ['Novéa', 'Wallonis', 'Studio Klein', 'Groupe Haval', 'Praxis'],
      testimonials: [
        { quote: "Le mardi, toute l'équipe descend à 9h pour les viennoiseries. C'est devenu notre rituel — et je n'ai plus rien à gérer.", author: 'Camille D.', company: "Office Manager — cabinet d'avocats, Wavre", initial: 'C' },
        { quote: "On a réglé nos petits-déjeuners d'onboarding en cinq minutes. Une facture par mois, livré à l'heure, à chaque fois.", author: 'Naïm B.', company: 'HR — scale-up tech, Louvain-la-Neuve', initial: 'N' }
      ],
      faqEyebrow: 'Questions fréquentes', faqTitle: "Tout ce qu'il faut savoir.", faqLinkText: ' Choisir ma zone →',
      faqs: [
        { question: 'Quel est le délai pour commander ?', answer: "Le délai et l'heure limite dépendent de la boutique qui vous livre.", zoneLink: true },
        { question: 'Puis-je modifier ou annuler une livraison ?', answer: "Oui. En récurrent comme en ponctuel, vous ajustez ou annulez d'une semaine à l'autre, dans le respect de l'heure limite de votre zone.", zoneLink: false },
        { question: 'Comment gérez-vous les allergènes ?', answer: "La liste complète des allergènes accompagne chaque formule. Signalez vos contraintes à votre boutique : elle adapte la composition quand c'est possible.", zoneLink: false },
        { question: 'Comment se passe la facturation ?', answer: 'Une seule facture mensuelle regroupe toutes vos livraisons du mois. Pas de paiement à la commande, rien à avancer.', zoneLink: false },
        { question: 'La TVA est-elle incluse ?', answer: 'Tous les montants communiqués par votre boutique incluent la TVA applicable. Votre facture en détaille le taux.', zoneLink: false },
        { question: 'Quels moyens de paiement acceptez-vous ?', answer: 'Le règlement se fait par virement à réception de la facture mensuelle. Les modalités précises vous sont confirmées par votre boutique.', zoneLink: false }
      ],
      cEyebrow: 'Organiser mes livraisons', cTitle: "Dites-nous en un peu plus, on s'occupe du reste.",
      cLede: 'Pas de commande ni de devis à cette étape : votre boutique de zone vous rappelle pour construire vos livraisons ensemble.',
      contactPoints: ['Réponse de votre boutique sous 48 h ouvrées', "Aucun engagement — on construit vos livraisons ensemble", "Vos données ne sont transmises qu'à la boutique de votre zone"],
      fields: { first_name: { label: 'Prénom' }, last_name: { label: 'Nom' }, company: { label: 'Société' }, email: { label: 'E-mail professionnel', ph: 'vous@societe.be' }, phone: { label: 'Téléphone', ph: '+32 …' }, zone_id: { label: 'Votre zone de livraison' }, postal_code: { label: 'Code postal / commune', ph: 'ex. 1300 Wavre' }, team_size: { label: "Taille de l'équipe", ph: 'ex. 25' }, frequency: { label: 'Fréquence souhaitée' }, message: { label: 'Votre message', ph: 'Dites-nous en plus sur vos habitudes, vos contraintes…' } },
      freqChoose: 'Choisir…', freqOptions: ['Ponctuel', 'Hebdomadaire', 'Mensuel'],
      consentText: "J'accepte que mes données soient transmises à la boutique L'Atelier By de ma zone pour le traitement de ma demande, conformément à la politique de confidentialité.",
      submitLabel: 'Envoyer ma demande', formNote: "Aucune commande n'est passée ici. Votre demande est simplement transmise à la boutique de votre zone.",
      err: { required: 'Champ requis.', email: 'E-mail invalide.', zone: 'Sélectionnez votre zone.', postal: 'Indiquez votre code postal / commune.', consent: 'Merci de confirmer votre consentement.' },
      footTag: "Maison de pains et viennoiseries — Belgique, depuis 2019. Ceci n'est pas qu'une boulangerie.",
      footCol1: "L'offre bureau", footCol2: 'Le réseau', footCol3: 'Contact',
      footNet1: 'Nos 6 boutiques', footNet2: 'Magasin en ligne', footNet3: 'Franchise', footEmail: 'bureau@latelierby.be',
      footLegal: '© 2026 L’Atelier By — Tous droits réservés.', footLegal2: 'Mentions légales · Confidentialité · RGPD',
      mEyebrow: 'Demande reçue', mTitle: "Merci — c'est bien enregistré.",
      mShopLabel: 'Destinataire', mNotifLabel: 'Notification', mRefLabel: 'Référence (shop_id)', mClose: 'Fermer',
      shopSystemName: 'Direction Opérationnelle',
      routingSys: "Votre commune n'est pas encore couverte : votre demande part vers notre direction opérationnelle, qui la garde pour étendre nos tournées.",
      routingNormalPre: 'Votre demande est transmise à la boutique de ', routingNormalPost: ', qui vous recontacte pour organiser vos livraisons.'
    },
    nl: {
      navZone: 'Uw zone', navProcess: 'Hoe het werkt', navFormules: 'De formules', navProduits: 'Onze producten', navFaq: 'FAQ', ctaHeader: 'Contact opnemen',
      heroEyebrow: 'Levering op kantoor', heroTitle: 'De bakkerij die naar', heroScript: 'kantoor komt.',
      heroLede: "Ontbijt, pauzes en lunches, huisgemaakt en 's ochtends bij uw team geleverd. Eenmalig of elke week — uw buurtwinkel levert.",
      heroCta1: 'De leveringen voor mijn kantoor regelen', heroCta2: 'Bekijk hoe het werkt',
      heroNote: 'Dit is niet zomaar een bakkerij — het is uw ochtendpauze, geleverd.',
      zoneEyebrow: 'Uw zone bepaalt alles', zoneTitle: 'Waar leveren we?',
      zoneLede: 'Onze dagen, uren en tijdvakken hangen af van de winkel die u belevert. Kies uw zone: u ziet meteen uw exacte voorwaarden.',
      zoneSelectLabel: 'Selecteer uw leveringszone', zoneChoose: 'Kies een zone…', zoneOutOption: 'Mijn zone staat niet in de lijst',
      zoneGeneric: 'Elke winkel van het netwerk organiseert zijn eigen rondes. Geef uw zone op: de leverdagen, het uiterste besteltijdstip, de tijdvakken en het minimum verschijnen hier — geen algemeen cijfer dat elders onjuist zou zijn.',
      zoneLivePar: 'Geleverd door', lblDays: 'Leverdagen', lblCutoff: 'Uiterste besteltijd', lblSlots: 'Leveringstijdvakken', lblMin: 'Minimum bestelling',
      zoneCta: 'Mijn leveringen in deze zone regelen', cutoffSuffix: ', de dag ervoor', minSuffix: 'personen min.',
      zoneOutTitle: 'Uw zone is nog niet gedekt?', zoneOutBody: 'Laat ons uw gemeente weten: elke aanvraag buiten zone helpt ons in kaart te brengen waar we onze rondes uitbreiden.',
      zoneOutLabel: 'Postcode / gemeente', zoneOutPh: 'bv. 1300 Wavre', zoneOutCta: 'Mijn aanvraag registreren',
      shopHintSys: 'Aanvraag doorgestuurd naar de operationele directie.', shopHintPrefix: 'Geleverd door de winkel van',
      regions: { bw: 'Waals-Brabant', bf: 'Vlaams-Brabant', nm: 'Namen', eb: 'Ten oosten van Brussel' },
      days: { lun: 'Ma', mar: 'Di', mer: 'Wo', jeu: 'Do', ven: 'Vr', sam: 'Za', dim: 'Zo' },
      ucEyebrow: 'Voor wie, waarvoor', ucTitle: 'Elk teammoment heeft zijn formule.',
      ucLede: 'Of u nu met vijf of tachtig bent, wij zorgen voor de pauzes die deugd doen op kantoor.',
      useCases: [
        { name: 'Vergaderingen & comités', description: 'Genoeg voor een werkochtend: viennoiserie, koffie, water, zonder het ritme te breken.', icon: 'img/sandwiches.png' },
        { name: 'Onthaalontbijten', description: 'Ontvang klanten en partners met een ontbijt dat de toon zet.', icon: 'img/croissant.png' },
        { name: 'Onboarding & nieuwkomers', description: "Vier de eerste dag van nieuwe collega's: een eenvoudige, warme attentie op het bureau.", icon: 'img/rolls.png' },
        { name: 'Verjaardagen & recepties', description: 'Iets te vieren? Cakes, taarten en zoetigheden, klaar om te delen.', icon: 'img/cupcake.png' },
        { name: 'Teamvrijdagen', description: 'Sluit de week zacht af met een terugkerende lekkere pauze.', icon: 'img/cookies.png' }
      ],
      procEyebrow: 'Hoe het werkt', procTitle: 'Vier stappen, nul beheer.',
      steps: [
        { title: 'U vertelt ons wat u nodig hebt', description: 'Aantal personen, type pauze, gewenste data. In twee minuten.' },
        { title: 'Uw winkel stelt u een formule voor', description: 'Afgestemd op uw zone en de leveringsvoorwaarden ervan.' },
        { title: 'U bevestigt uw planning', description: 'Eenmalig of terugkerend, week na week aanpasbaar. U houdt de controle.' },
        { title: 'Wij leveren, we factureren op het maandeinde', description: 'Levering op kantoor op het afgesproken uur. Eén maandelijkse factuur, niets te beheren.' }
      ],
      stepZoneHint: 'Kies mijn zone om mijn voorwaarden te zien →', stepBefore: 'vóór',
      offEyebrow: 'De formules', offTitle: 'Samenstellingen bedacht voor kantoor.',
      offLede: 'Uw winkel past de hoeveelheden en de presentatie aan. Wij stellen een formule voor, nooit een catalogus om uit te zoeken.',
      offerBadge: 'Wekelijks beschikbaar', lblFor: 'Voor:', lblSetup: 'Presentatie:',
      offers: [
        { name: 'Het Teamontbijt', description: 'De ochtendmand: roomboterviennoiserie, brood en versgeperst sap.', icon: 'img/croissant.png', includes: ['Croissants & chocoladebroodjes', 'Pistolets & huisgemaakte confituur', 'Vers fruitsap'], audience: 'Werkochtenden, vergaderingen', setup: 'Manden + servetten inbegrepen', rec: true },
        { name: 'De Zoete Pauze', description: 'Het namiddagplezier, zoet en troostend.', icon: 'img/cookies.png', includes: ['Cookies & financiers van de dag', 'Gesneden cake', 'Koffie en thee optioneel'], audience: 'Pauzes, einde van de dag', setup: 'Klaar om te delen', rec: true },
        { name: 'De Lunch op Kantoor', description: 'Een volledige, huisgemaakte lunch zonder het kantoor te verlaten.', icon: 'img/sandwiches.png', includes: ['Belegde broodjes & wraps', 'Seizoenssalades', 'Hartige taarten'], audience: 'Teamlunches, workshops', setup: 'Servies & presentatie inbegrepen', rec: false },
        { name: 'Het Feestplateau', description: 'Om het moment te vieren: feestelijke stukken en desserts om te delen.', icon: 'img/cake-slice.png', includes: ['Zoete taarten & entremets', 'Versierde cupcakes', 'Stukken om te delen'], audience: 'Verjaardagen, recepties, evenementen', setup: 'Presentatie & versnijding inbegrepen', rec: true }
      ],
      recEyebrow: 'Herhaling', recTitle: 'Zin in regelmaat? Ga wekelijks.',
      recBody: 'Een wekelijks abonnement voor uw in aanmerking komende formules. U houdt de controle: wijzig, pauzeer of annuleer van week tot week, zonder verbintenis.',
      recPoints: ['Automatische wekelijkse levering', 'Pauzeren of annuleren met één klik', 'Zonder looptijdverbintenis'],
      prodEyebrow: 'Onze producten', prodTitle: 'Elke dag vers, zonder uitzondering.',
      prodLede: "In het hele netwerk van L'Atelier By wordt alles dezelfde dag bereid en geleverd. Onze salades worden elke ochtend met de hand samengesteld en op smaak gebracht in de winkel.",
      prodBadge: 'Vers van de dag', prodAssure: ['Alles vers van de dag', 'Salades huisgemaakt', 'Dezelfde ochtend geleverd'],
      products: [
        { title: 'Sandwichschotel', note: 'Brood van de dag, verse garnituur, dezelfde ochtend gesneden en gedresseerd.' },
        { title: 'Fruitschotel', note: 'Seizoensfruit gesneden op de dag van levering, nooit de dag ervoor.' },
        { title: 'Dessertbuffet', note: 'Taarten, entremets en zoetigheden bereid in de winkel door onze patissiers.' },
        { title: 'Huisgemaakte salades', note: 'Elke ochtend met de hand samengesteld en op smaak gebracht — eigen recepten.' },
        { title: 'Ontbijtschotel', note: 'Roomboterviennoiserie en brood, nog lauw bij levering.' }
      ],
      specs: [
        { value: '80', label: 'personen bediend in één levering' },
        { value: '6', label: 'ambachtelijke winkels in heel België' },
        { value: '07:30', label: 'op kantoor, viennoiserie nog lauw' },
        { value: '1', label: 'maandelijkse factuur, nul beheer' }
      ],
      clientsLabel: 'Zij laten hun teams beleveren', clients: ['Novéa', 'Wallonis', 'Studio Klein', 'Groupe Haval', 'Praxis'],
      testimonials: [
        { quote: 'Op dinsdag komt het hele team om 9u naar beneden voor de viennoiserie. Het is ons ritueel geworden — en ik moet niets meer beheren.', author: 'Camille D.', company: 'Office Manager — advocatenkantoor, Wavre', initial: 'C' },
        { quote: 'We regelden onze onboarding-ontbijten in vijf minuten. Eén factuur per maand, telkens op tijd geleverd.', author: 'Naïm B.', company: 'HR — tech scale-up, Louvain-la-Neuve', initial: 'N' }
      ],
      faqEyebrow: 'Veelgestelde vragen', faqTitle: 'Alles wat u moet weten.', faqLinkText: ' Kies mijn zone →',
      faqs: [
        { question: 'Wat is de besteltermijn?', answer: 'De termijn en het uiterste tijdstip hangen af van de winkel die u belevert.', zoneLink: true },
        { question: 'Kan ik een levering wijzigen of annuleren?', answer: 'Ja. Zowel terugkerend als eenmalig past u aan of annuleert u van week tot week, met respect voor het uiterste tijdstip van uw zone.', zoneLink: false },
        { question: 'Hoe gaan jullie om met allergenen?', answer: 'De volledige allergenenlijst hoort bij elke formule. Meld uw beperkingen aan uw winkel: die past de samenstelling aan waar mogelijk.', zoneLink: false },
        { question: 'Hoe verloopt de facturatie?', answer: 'Eén maandelijkse factuur bundelt al uw leveringen van de maand. Geen betaling bij bestelling, niets voor te schieten.', zoneLink: false },
        { question: 'Is de btw inbegrepen?', answer: 'Alle bedragen die uw winkel meedeelt zijn inclusief de toepasselijke btw. Uw factuur vermeldt het tarief.', zoneLink: false },
        { question: 'Welke betaalmethodes aanvaarden jullie?', answer: 'Betaling gebeurt via overschrijving na ontvangst van de maandfactuur. De precieze modaliteiten worden door uw winkel bevestigd.', zoneLink: false }
      ],
      cEyebrow: 'Mijn leveringen regelen', cTitle: 'Vertel ons wat meer, wij doen de rest.',
      cLede: 'Geen bestelling of offerte in deze stap: uw zonewinkel belt u terug om samen uw leveringen op te bouwen.',
      contactPoints: ['Antwoord van uw winkel binnen 48 werkuren', 'Geen verbintenis — we bouwen uw leveringen samen op', 'Uw gegevens worden enkel aan de winkel van uw zone bezorgd'],
      fields: { first_name: { label: 'Voornaam' }, last_name: { label: 'Naam' }, company: { label: 'Bedrijf' }, email: { label: 'Professioneel e-mailadres', ph: 'u@bedrijf.be' }, phone: { label: 'Telefoon', ph: '+32 …' }, zone_id: { label: 'Uw leveringszone' }, postal_code: { label: 'Postcode / gemeente', ph: 'bv. 1300 Wavre' }, team_size: { label: 'Grootte van het team', ph: 'bv. 25' }, frequency: { label: 'Gewenste frequentie' }, message: { label: 'Uw bericht', ph: 'Vertel ons meer over uw gewoontes, uw beperkingen…' } },
      freqChoose: 'Kiezen…', freqOptions: ['Eenmalig', 'Wekelijks', 'Maandelijks'],
      consentText: "Ik ga ermee akkoord dat mijn gegevens worden bezorgd aan de winkel van L'Atelier By in mijn zone voor de behandeling van mijn aanvraag, conform het privacybeleid.",
      submitLabel: 'Mijn aanvraag versturen', formNote: 'Hier wordt geen bestelling geplaatst. Uw aanvraag wordt gewoon aan de winkel van uw zone bezorgd.',
      err: { required: 'Verplicht veld.', email: 'Ongeldig e-mailadres.', zone: 'Selecteer uw zone.', postal: 'Geef uw postcode / gemeente op.', consent: 'Bevestig uw toestemming.' },
      footTag: 'Huis van brood en viennoiserie — België, sinds 2019. Dit is niet zomaar een bakkerij.',
      footCol1: 'Het kantooraanbod', footCol2: 'Het netwerk', footCol3: 'Contact',
      footNet1: 'Onze 6 winkels', footNet2: 'Webshop', footNet3: 'Franchise', footEmail: 'kantoor@latelierby.be',
      footLegal: '© 2026 L’Atelier By — Alle rechten voorbehouden.', footLegal2: 'Wettelijke vermeldingen · Privacy · AVG',
      mEyebrow: 'Aanvraag ontvangen', mTitle: 'Bedankt — goed geregistreerd.',
      mShopLabel: 'Bestemmeling', mNotifLabel: 'Melding', mRefLabel: 'Referentie (shop_id)', mClose: 'Sluiten',
      shopSystemName: 'Operationele directie',
      routingSys: 'Uw gemeente is nog niet gedekt: uw aanvraag gaat naar onze operationele directie, die ze bewaart om onze rondes uit te breiden.',
      routingNormalPre: 'Uw aanvraag wordt bezorgd aan de winkel van ', routingNormalPost: ', die contact met u opneemt om uw leveringen te regelen.'
    }
  };

  // ---- Services partagés ----
  function resolveShop(zoneId) {
    if (zoneId === '' || zoneId == null) return null;
    if (String(zoneId) === '0') return SHOPS[0];
    const z = ZONES.find(x => String(x.id) === String(zoneId));
    if (!z || !z.is_active) return SHOPS[0];
    return SHOPS[z.shop_id] || SHOPS[0];
  }
  function getZoneParams(zoneId) {
    const z = ZONES.find(x => String(x.id) === String(zoneId));
    if (!z || !z.is_active) return null;
    return z;
  }
  const wrap = (k, n) => ((k % n) + n) % n;

  // ---- Icons ----
  const Arrow = (p) => (
    <svg width={p.size || 16} height={p.size || 16} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 5l7 7-7 7" /></svg>
  );
  const Check = (p) => (
    <svg width={p.size || 16} height={p.size || 16} viewBox="0 0 24 24" fill="none" stroke={p.stroke || 'currentColor'} strokeWidth={p.sw || 2} strokeLinecap="round" strokeLinejoin="round" style={p.style}><path d="M5 13l4 4 10-11" /></svg>
  );
  const Caret = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6" /></svg>
  );
  const ChevL = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
  );
  const ChevR = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 6l6 6-6 6" /></svg>
  );

  // ---- Carousel builder (center-focus) ----
  function buildCarousel(list, idx, radius, setIdx) {
    const n = list.length, at = (k) => ((k % n) + n) % n, out = [];
    for (let d = -radius; d <= radius; d++) {
      const j = at(idx + d), dist = Math.abs(d), center = d === 0;
      out.push({
        offer: list[j], idx: j,
        transform: 'scale(' + (center ? 1 : (1 - dist * 0.09).toFixed(3)) + ')',
        opacity: center ? 1 : Math.max(0.34, 1 - dist * 0.33),
        z: 10 - dist, cursor: center ? 'default' : 'pointer',
        border: center ? 'var(--lp-ruby)' : 'var(--lp-hair)',
        shadow: center ? '0 30px 60px -34px rgba(107,68,32,0.55)' : '0 12px 30px -26px rgba(107,68,32,0.4)',
        onClick: center ? (() => {}) : (() => setIdx(j))
      });
    }
    return out;
  }
  function buildDots(list, idx, setIdx) {
    return list.map((_, i) => ({ w: i === idx ? '26px' : '8px', bg: i === idx ? 'var(--lp-ruby)' : 'var(--lp-hair)', onClick: () => setIdx(i) }));
  }

  // Reveal-on-scroll: add .is-visible when a [data-reveal] enters the viewport.
  function useReveals(deps) {
    useEffect(() => {
      const els = document.querySelectorAll('.lb [data-reveal]:not(.is-visible)');
      if (!els.length) return undefined;
      if (typeof IntersectionObserver === 'undefined') {
        els.forEach(el => el.classList.add('is-visible'));
        return undefined;
      }
      const io = new IntersectionObserver((ents) => {
        ents.forEach(en => { if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); } });
      }, { threshold: 0.08, rootMargin: '0px 0px -6% 0px' });
      els.forEach(el => io.observe(el));
      return () => io.disconnect();
    }, deps);
  }

  function LivraisonBureau() {
    const [lang, setLang] = useState('fr');
    const [zoneId, setZoneId] = useState('');
    const [form, setForm] = useState({});
    const [errors, setErrors] = useState({});
    const [submitted, setSubmitted] = useState(false);
    const [submission, setSubmission] = useState(null);
    const [offerIndex, setOfferIndex] = useState(0);
    const [ucIndex, setUcIndex] = useState(0);
    const [prodIndex, setProdIndex] = useState(0);

    useReveals([lang, zoneId]);

    const L = T[lang];
    const z = getZoneParams(zoneId);
    const zoneNone = zoneId === '';
    const zoneOut = zoneId === '0';
    const shop = resolveShop(zoneId);

    // ---- Handlers ----
    const onZoneSelect = (e) => {
      const v = e.target.value;
      setZoneId(v);
      setForm(s => ({ ...s, zone_id: v }));
      setErrors(s => ({ ...s, zone_id: undefined, postal_code: undefined }));
    };
    const onPostalInput = (e) => {
      const v = e.target.value;
      setForm(s => ({ ...s, postal_code: v }));
      setErrors(s => ({ ...s, postal_code: undefined }));
    };
    const setField = (key, val) => {
      setForm(s => ({ ...s, [key]: val }));
      setErrors(s => ({ ...s, [key]: undefined }));
    };
    const onSubmit = (e) => {
      e.preventDefault();
      const f = form || {};
      const E = L.err;
      const errs = {};
      ['first_name', 'last_name', 'company', 'email'].forEach(k => { if (!f[k] || String(f[k]).trim() === '') errs[k] = E.required; });
      if (f.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) errs.email = E.email;
      if (zoneId === '') errs.zone_id = E.zone;
      if (zoneId === '0' && (!f.postal_code || !f.postal_code.trim())) errs.postal_code = E.postal;
      if (!f.consent) errs.consent = E.consent;
      if (Object.keys(errs).length) { setErrors(errs); return; }
      setSubmission({ shop: resolveShop(zoneId) });
      setSubmitted(true);
    };
    const closeModal = () => setSubmitted(false);

    // ---- Derived data ----
    const active = ZONES.filter(x => x.is_active);
    const regionOrder = ['bw', 'bf', 'nm', 'eb'];
    const byRegion = {};
    active.forEach(x => { (byRegion[x.region] = byRegion[x.region] || []).push(x); });
    const zoneGroups = regionOrder.filter(r => byRegion[r]).map(r => ({
      label: L.regions[r],
      zones: byRegion[r].sort((a, b) => a.priority - b.priority).map(x => ({ idStr: String(x.id), zone_name: x.zone_name }))
    }));

    const daysStr = z ? z.days.map(d => L.days[d]).join(' · ') : '';
    const cutoffStr = z ? (z.cutoff_time + L.cutoffSuffix) : '';
    const slotsStr = z ? z.slots.join('  /  ') : '';
    const minStr = z ? (z.min + ' ' + L.minSuffix) : '';

    const offersL = L.offers.map(o => ({ ...o, badge: o.rec ? L.offerBadge : null }));
    const carousel = buildCarousel(offersL, wrap(offerIndex, offersL.length), 1, setOfferIndex);
    const ucCarousel = buildCarousel(L.useCases, wrap(ucIndex, L.useCases.length), 2, setUcIndex);
    const offerDots = buildDots(offersL, wrap(offerIndex, offersL.length), setOfferIndex);
    const ucDots = buildDots(L.useCases, wrap(ucIndex, L.useCases.length), setUcIndex);

    const prods = L.products.map((p, i) => ({ ...p, img: PRODUCT_IMAGES[PRODUCT_KEYS[i]] }));
    const prodCarousel = buildCarousel(prods, wrap(prodIndex, prods.length), 1, setProdIndex);
    const prodDots = buildDots(prods, wrap(prodIndex, prods.length), setProdIndex);

    const zoneShopHint = shop ? (shop.is_system ? L.shopHintSys : (L.shopHintPrefix + ' ' + shop.name + '.')) : null;
    const zoneBeforeChip = z ? (L.stepBefore + ' ' + z.cutoff_time) : null;

    const rShop = submission ? submission.shop : null;
    const rName = rShop ? (rShop.is_system ? L.shopSystemName : rShop.name) : '';
    const routingMessage = rShop ? (rShop.is_system ? L.routingSys : (L.routingNormalPre + rShop.name + L.routingNormalPost)) : '';

    // Form field definitions (piloté par lp_form_field)
    const f = form || {};
    const border = (k) => errors[k] ? 'lb-fld--err' : '';
    const formDefs = [
      { key: 'first_name', type: 'text', full: false, required: true },
      { key: 'last_name', type: 'text', full: false, required: true },
      { key: 'company', type: 'text', full: true, required: true },
      { key: 'email', type: 'email', full: false, required: true },
      { key: 'phone', type: 'tel', full: false, required: false },
      { key: 'zone_id', type: 'zone', full: true, required: true },
      { key: 'postal_code', type: 'text', full: true, required: true, depends: 'zone0' },
      { key: 'team_size', type: 'number', full: false, required: false },
      { key: 'frequency', type: 'freq', full: false, required: false },
      { key: 'message', type: 'textarea', full: true, required: false },
      { key: 'consent', type: 'checkbox', full: true, required: true }
    ];

    const stepChip = (i) => (i === 1 && z) || (i === 1 && !z);

    return (
      <div className="lb" id="top">

        {/* ============ HEADER ============ */}
        <header className="lb-header" data-screen-label="Header">
          <div className="lb-header__inner">
            <a href="#top" className="lb-brand">
              <img src="img/brand/logo.png" alt="L'Atelier By" />
              <span className="lb-brand__tag">Bureau</span>
            </a>
            <nav className="lb-nav">
              <a href="#zone">{L.navZone}</a>
              <a href="#process">{L.navProcess}</a>
              <a href="#formules">{L.navFormules}</a>
              <a href="#produits">{L.navProduits}</a>
              <a href="#faq">{L.navFaq}</a>
            </nav>
            <div className="lb-header__right">
              <div className="lb-lang">
                <button type="button" aria-label="Français" className={lang === 'fr' ? 'is-active' : ''} onClick={() => setLang('fr')}>FR</button>
                <button type="button" aria-label="Nederlands" className={lang === 'nl' ? 'is-active' : ''} onClick={() => setLang('nl')}>NL</button>
              </div>
              <a href="#contact" className="lb-btn lb-btn--primary lb-btn--sm">{L.ctaHeader}</a>
            </div>
          </div>
        </header>

        <main>

          {/* ============ HERO ============ */}
          <section className="lb-hero" data-screen-label="Hero">
            <div className="lb-hero__art"><img src="img/sandwiches-platter.png" alt="" /></div>
            <div className="lb-wrap lb-hero__inner">
              <div className="lb-hero__body">
                <p className="lb-eyebrow lb-eyebrow--inline"><span></span> {L.heroEyebrow}</p>
                <h1>{L.heroTitle} <span className="lb-script">{L.heroScript}</span></h1>
                <p className="lb-hero__lede">{L.heroLede}</p>
                <div className="lb-hero__ctas">
                  <a href="#contact" className="lb-btn lb-btn--primary">{L.heroCta1}<Arrow /></a>
                  <a href="#process" className="lb-btn lb-btn--ghost">{L.heroCta2}</a>
                </div>
                <p className="lb-hero__note"><span></span> {L.heroNote}</p>
              </div>
            </div>
          </section>

          {/* ============ ZONE PIVOT ============ */}
          <section id="zone" className="lb-section lb-section--surface" data-screen-label="Zone selector" data-reveal>
            <div className="lb-wrap">
              <div className="lb-zone__head">
                <p className="lb-eyebrow">{L.zoneEyebrow}</p>
                <h2 className="lb-h2">{L.zoneTitle}</h2>
                <p className="lb-lede">{L.zoneLede}</p>
              </div>

              <div className="lb-zone__card">
                <label className="lb-label">{L.zoneSelectLabel}</label>
                <div className="lb-select-wrap">
                  <select className="lb-select" value={zoneId} onChange={onZoneSelect}>
                    <option value="">{L.zoneChoose}</option>
                    {zoneGroups.map((g, gi) => (
                      <optgroup key={gi} label={g.label}>
                        {g.zones.map((zz) => (<option key={zz.idStr} value={zz.idStr}>{zz.zone_name}</option>))}
                      </optgroup>
                    ))}
                    <option value="0">{L.zoneOutOption}</option>
                  </select>
                  <span className="lb-select-caret"><Caret /></span>
                </div>

                {zoneNone && (<p className="lb-zone__generic">{L.zoneGeneric}</p>)}

                {z && (
                  <div className="lb-zone__reveal">
                    <div>
                      <span className="lb-chip-live">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                        {L.zoneLivePar} {shop && !shop.is_system ? shop.name : ''} · {shop && !shop.is_system ? shop.city : ''}
                      </span>
                    </div>
                    <div className="lb-params">
                      <div className="lb-param"><p className="lb-param__k">{L.lblDays}</p><p className="lb-param__v">{daysStr}</p></div>
                      <div className="lb-param"><p className="lb-param__k">{L.lblCutoff}</p><p className="lb-param__v lb-param__v--ruby">{cutoffStr}</p></div>
                      <div className="lb-param"><p className="lb-param__k">{L.lblSlots}</p><p className="lb-param__v">{slotsStr}</p></div>
                      <div className="lb-param"><p className="lb-param__k">{L.lblMin}</p><p className="lb-param__v">{minStr}</p></div>
                    </div>
                    {z.note && (
                      <p className="lb-zone__note">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
                        <span>{z.note[lang]}</span>
                      </p>
                    )}
                    <a href="#contact" className="lb-btn lb-btn--primary lb-btn--sm lb-zone__cta">{L.zoneCta}<Arrow size={15} /></a>
                  </div>
                )}

                {zoneOut && (
                  <div className="lb-zone__reveal">
                    <p className="lb-zone__out-body"><b style={{ fontWeight: 600 }}>{L.zoneOutTitle}</b> {L.zoneOutBody}</p>
                    <label className="lb-label">{L.zoneOutLabel} <span style={{ color: 'var(--lp-ruby)' }}>*</span></label>
                    <input type="text" className="lb-input" value={f.postal_code || ''} onInput={onPostalInput} onChange={onPostalInput} placeholder={L.zoneOutPh} />
                    <a href="#contact" className="lb-btn lb-btn--primary lb-btn--sm" style={{ marginTop: '18px' }}>{L.zoneOutCta}<Arrow size={15} /></a>
                  </div>
                )}
              </div>
            </div>
          </section>

          {/* ============ USE CASES ============ */}
          <section className="lb-section" data-screen-label="Use cases" data-reveal>
            <div className="lb-wrap">
              <div className="lb-head">
                <p className="lb-eyebrow">{L.ucEyebrow}</p>
                <h2 className="lb-h2">{L.ucTitle}</h2>
                <p className="lb-lede">{L.ucLede}</p>
              </div>
              <div className="lb-carousel lb-carousel--tight">
                <button type="button" className="lb-carousel__nav lb-carousel__nav--prev" onClick={() => setUcIndex(wrap(ucIndex - 1, L.useCases.length))} aria-label="Précédent"><ChevL /></button>
                {ucCarousel.map((c, i) => (
                  <div key={i} className="lb-card lb-uc-card" onClick={c.onClick}
                    style={{ transform: c.transform, opacity: c.opacity, zIndex: c.z, cursor: c.cursor, borderColor: c.border, boxShadow: c.shadow }}>
                    <div className="lb-uc-card__icon"><img src={c.offer.icon} alt="" /></div>
                    <h3 className="lb-card-title">{c.offer.name}</h3>
                    <p className="lb-card-desc">{c.offer.description}</p>
                  </div>
                ))}
                <button type="button" className="lb-carousel__nav lb-carousel__nav--next" onClick={() => setUcIndex(wrap(ucIndex + 1, L.useCases.length))} aria-label="Suivant"><ChevR /></button>
              </div>
              <div className="lb-dots">
                {ucDots.map((d, i) => (<button key={i} type="button" onClick={d.onClick} aria-label="Aller à l'élément" style={{ width: d.w, background: d.bg }}></button>))}
              </div>
            </div>
          </section>

          {/* ============ PROCESS ============ */}
          <section id="process" className="lb-section lb-section--abricot" data-screen-label="Process" data-reveal>
            <div className="lb-wrap">
              <div className="lb-head" style={{ marginBottom: '48px' }}>
                <p className="lb-eyebrow">{L.procEyebrow}</p>
                <h2 className="lb-h2">{L.procTitle}</h2>
              </div>
              <div className="lb-steps">
                {L.steps.map((s, i) => (
                  <div key={i} className="lb-step">
                    <span className="lb-step__num">{i + 1}</span>
                    <h3 className="lb-card-title">{s.title}</h3>
                    <p className="lb-card-desc">{s.description}</p>
                    {i === 1 && z && (
                      <div className="lb-step__chips">
                        <span className="lb-chip-sm">{daysStr}</span>
                        <span className="lb-chip-sm">{zoneBeforeChip}</span>
                      </div>
                    )}
                    {i === 1 && !z && (<a href="#zone" className="lb-step__hint">{L.stepZoneHint}</a>)}
                  </div>
                ))}
              </div>
            </div>
          </section>

          {/* ============ FORMULES ============ */}
          <section id="formules" className="lb-section" data-screen-label="Formules" data-reveal>
            <div className="lb-wrap">
              <div className="lb-head">
                <p className="lb-eyebrow">{L.offEyebrow}</p>
                <h2 className="lb-h2">{L.offTitle}</h2>
                <p className="lb-lede">{L.offLede}</p>
              </div>
              <div className="lb-carousel">
                <button type="button" className="lb-carousel__nav lb-carousel__nav--prev" onClick={() => setOfferIndex(wrap(offerIndex - 1, offersL.length))} aria-label="Formule précédente"><ChevL /></button>
                {carousel.map((c, i) => (
                  <div key={i} className="lb-card lb-offer-card" onClick={c.onClick}
                    style={{ transform: c.transform, opacity: c.opacity, zIndex: c.z, cursor: c.cursor, borderColor: c.border, boxShadow: c.shadow }}>
                    <div className="lb-offer-card__top">
                      {c.offer.badge && (<span className="lb-offer-card__badge">{c.offer.badge}</span>)}
                      <img src={c.offer.icon} alt="" />
                    </div>
                    <div className="lb-offer-card__body">
                      <h3>{c.offer.name}</h3>
                      <p className="lb-card-desc">{c.offer.description}</p>
                      <ul className="lb-offer-card__incl">
                        {c.offer.includes.map((inc, ii) => (
                          <li key={ii}><Check size={16} stroke="var(--lp-ruby)" /><span>{inc}</span></li>
                        ))}
                      </ul>
                      <div className="lb-offer-card__meta">
                        <p><b>{L.lblFor}</b> {c.offer.audience}</p>
                        <p><b>{L.lblSetup}</b> {c.offer.setup}</p>
                      </div>
                    </div>
                  </div>
                ))}
                <button type="button" className="lb-carousel__nav lb-carousel__nav--next" onClick={() => setOfferIndex(wrap(offerIndex + 1, offersL.length))} aria-label="Formule suivante"><ChevR /></button>
              </div>
              <div className="lb-dots">
                {offerDots.map((d, i) => (<button key={i} type="button" onClick={d.onClick} aria-label="Aller à la formule" style={{ width: d.w, background: d.bg }}></button>))}
              </div>

              <div className="lb-rec">
                <div className="lb-rec__text">
                  <p className="lb-eyebrow">{L.recEyebrow}</p>
                  <h3>{L.recTitle}</h3>
                  <p>{L.recBody}</p>
                </div>
                <div className="lb-rec__points">
                  {L.recPoints.map((r, i) => (<div key={i}><span></span> {r}</div>))}
                </div>
              </div>
            </div>
          </section>

          {/* ============ NOS PRODUITS ============ */}
          <section id="produits" className="lb-section" data-screen-label="Products" data-reveal>
            <div className="lb-wrap">
              <div className="lb-head--wide" style={{ marginBottom: '28px' }}>
                <p className="lb-eyebrow">{L.prodEyebrow}</p>
                <h2 className="lb-h2" style={{ marginBottom: '16px' }}>{L.prodTitle}</h2>
                <p className="lb-lede">{L.prodLede}</p>
              </div>
              <div className="lb-prod-assure">
                {L.prodAssure.map((a, i) => (
                  <span key={i} className="lb-assure"><Check size={15} stroke="var(--lp-ruby)" sw={2.2} />{a}</span>
                ))}
              </div>
              <div className="lb-carousel">
                <button type="button" className="lb-carousel__nav lb-carousel__nav--prev" onClick={() => setProdIndex(wrap(prodIndex - 1, prods.length))} aria-label="Produit précédent"><ChevL /></button>
                {prodCarousel.map((c, i) => (
                  <div key={i} className="lb-card lb-prod-card" onClick={c.onClick}
                    style={{ transform: c.transform, opacity: c.opacity, zIndex: c.z, cursor: c.cursor, borderColor: c.border, boxShadow: c.shadow }}>
                    <div className="lb-prod-card__media">
                      <img src={c.offer.img} alt={c.offer.title} />
                      <span className="lb-prod-card__badge">{L.prodBadge}</span>
                    </div>
                    <div className="lb-prod-card__body">
                      <h3>{c.offer.title}</h3>
                      <p>{c.offer.note}</p>
                    </div>
                  </div>
                ))}
                <button type="button" className="lb-carousel__nav lb-carousel__nav--next" onClick={() => setProdIndex(wrap(prodIndex + 1, prods.length))} aria-label="Produit suivant"><ChevR /></button>
              </div>
              <div className="lb-dots">
                {prodDots.map((d, i) => (<button key={i} type="button" onClick={d.onClick} aria-label="Aller au produit" style={{ width: d.w, background: d.bg }}></button>))}
              </div>
            </div>
          </section>

          {/* ============ PROOF ============ */}
          <section className="lb-section lb-section--abricot" data-screen-label="Proof" data-reveal>
            <div className="lb-wrap">
              <div className="lb-specs">
                {L.specs.map((c, i) => (
                  <div key={i} className="lb-spec"><p className="lb-spec__v">{c.value}</p><p className="lb-spec__l">{c.label}</p></div>
                ))}
              </div>
              <p className="lb-clients-label">{L.clientsLabel}</p>
              <div className="lb-clients">
                {L.clients.map((cl, i) => (<span key={i}>{cl}</span>))}
              </div>
              <div className="lb-testimonials">
                {L.testimonials.map((t, i) => (
                  <figure key={i} className="lb-quote">
                    <div className="lb-quote__mark">“</div>
                    <blockquote>{t.quote}</blockquote>
                    <figcaption>
                      <span className="lb-quote__avatar">{t.initial}</span>
                      <span><span className="lb-quote__author">{t.author}</span><span className="lb-quote__company">{t.company}</span></span>
                    </figcaption>
                  </figure>
                ))}
              </div>
            </div>
          </section>

          {/* ============ FAQ ============ */}
          <section id="faq" className="lb-section" data-screen-label="FAQ" data-reveal>
            <div className="lb-wrap lb-faq">
              <div className="lb-head--center" style={{ marginBottom: '44px' }}>
                <p className="lb-eyebrow">{L.faqEyebrow}</p>
                <h2 className="lb-h2">{L.faqTitle}</h2>
              </div>
              <div className="lb-faq__list">
                {L.faqs.map((q, i) => (
                  <details key={i} className="lb-faq__item">
                    <summary>
                      {q.question}
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lp-ruby)" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                    </summary>
                    <div className="lb-faq__answer">
                      {q.answer}
                      {q.zoneLink && (<a href="#zone">{L.faqLinkText}</a>)}
                    </div>
                  </details>
                ))}
              </div>
            </div>
          </section>

          {/* ============ CTA + FORM ============ */}
          <section id="contact" className="lb-section lb-contact" data-screen-label="Contact form" data-reveal>
            <div className="lb-wrap lb-contact__grid">
              <div>
                <p className="lb-eyebrow">{L.cEyebrow}</p>
                <h2>{L.cTitle}</h2>
                <p className="lb-contact__lede">{L.cLede}</p>
                <ul className="lb-contact__points">
                  {L.contactPoints.map((p, i) => (
                    <li key={i}><Check size={18} stroke="var(--lp-ruby)" /><span>{p}</span></li>
                  ))}
                </ul>
              </div>

              <form className="lb-form" onSubmit={onSubmit}>
                <div className="lb-form__grid">
                  {formDefs.map((d) => {
                    if (d.depends === 'zone0' && zoneId !== '0') return null;
                    const cls = 'lb-field' + (d.full ? ' lb-field--full' : '');
                    const star = d.required ? <span className="lb-field__star">*</span> : null;
                    const label = (L.fields[d.key] && L.fields[d.key].label) || '';
                    const ph = (L.fields[d.key] && L.fields[d.key].ph) || '';
                    const err = errors[d.key];

                    if (d.type === 'checkbox') {
                      return (
                        <div key={d.key} className={cls}>
                          <label className="lb-consent">
                            <input type="checkbox" checked={!!f[d.key]} onChange={(e) => setField(d.key, e.target.checked)} />
                            <span>{L.consentText}</span>
                          </label>
                          {err && (<span className="lb-field__err">{err}</span>)}
                        </div>
                      );
                    }
                    if (d.type === 'textarea') {
                      return (
                        <div key={d.key} className={cls}>
                          <label>{label} {star}</label>
                          <textarea className={'lb-fld ' + border(d.key)} value={f[d.key] || ''} onChange={(e) => setField(d.key, e.target.value)} placeholder={ph} rows="3"></textarea>
                          {err && (<span className="lb-field__err">{err}</span>)}
                        </div>
                      );
                    }
                    if (d.type === 'freq') {
                      return (
                        <div key={d.key} className={cls}>
                          <label>{label} {star}</label>
                          <div className="lb-field__selwrap">
                            <select className={'lb-fld lb-fld--select ' + border(d.key)} value={f[d.key] || ''} onChange={(e) => setField(d.key, e.target.value)}>
                              <option value="">{L.freqChoose}</option>
                              {L.freqOptions.map((opt) => (<option key={opt} value={opt}>{opt}</option>))}
                            </select>
                            <span className="lb-field__caret"><Caret /></span>
                          </div>
                          {err && (<span className="lb-field__err">{err}</span>)}
                        </div>
                      );
                    }
                    if (d.type === 'zone') {
                      return (
                        <div key={d.key} className={cls}>
                          <label>{label} {star}</label>
                          <div className="lb-field__selwrap">
                            <select className={'lb-fld lb-fld--select ' + border(d.key)} value={zoneId} onChange={onZoneSelect}>
                              <option value="">{L.zoneChoose}</option>
                              {zoneGroups.map((g, gi) => (
                                <optgroup key={gi} label={g.label}>
                                  {g.zones.map((zz) => (<option key={zz.idStr} value={zz.idStr}>{zz.zone_name}</option>))}
                                </optgroup>
                              ))}
                              <option value="0">{L.zoneOutOption}</option>
                            </select>
                            <span className="lb-field__caret"><Caret /></span>
                          </div>
                          {zoneShopHint && (<span className="lb-field__hint">{zoneShopHint}</span>)}
                          {err && (<span className="lb-field__err">{err}</span>)}
                        </div>
                      );
                    }
                    // text / email / tel / number
                    return (
                      <div key={d.key} className={cls}>
                        <label>{label} {star}</label>
                        <input type={d.type} className={'lb-fld ' + border(d.key)} value={f[d.key] || ''} onChange={(e) => setField(d.key, e.target.value)} placeholder={ph} />
                        {err && (<span className="lb-field__err">{err}</span>)}
                      </div>
                    );
                  })}
                </div>
                <button type="submit" className="lb-btn lb-submit">{L.submitLabel}<Arrow size={15} /></button>
                <p className="lb-form__note">{L.formNote}</p>
              </form>
            </div>
          </section>

        </main>

        {/* ============ FOOTER ============ */}
        <footer className="lb-footer" data-screen-label="Footer">
          <div className="lb-wrap">
            <div className="lb-footer__cols">
              <div>
                <img src="img/brand/logo.png" alt="L'Atelier By" className="lb-footer__logo" />
                <p className="lb-footer__tag">{L.footTag}</p>
              </div>
              <div>
                <h4>{L.footCol1}</h4>
                <a href="#zone">{L.navZone}</a>
                <a href="#formules">{L.navFormules}</a>
                <a href="#process">{L.navProcess}</a>
              </div>
              <div>
                <h4>{L.footCol2}</h4>
                <a href="#">{L.footNet1}</a>
                <a href="#">{L.footNet2}</a>
                <a href="#">{L.footNet3}</a>
              </div>
              <div>
                <h4>{L.footCol3}</h4>
                <a href="#contact">{L.ctaHeader}</a>
                <a href="#">{L.footEmail}</a>
              </div>
            </div>
            <div className="lb-footer__bottom">
              <span>{L.footLegal}</span>
              <span>{L.footLegal2}</span>
            </div>
          </div>
        </footer>

        {/* ============ CONFIRMATION MODAL ============ */}
        {submitted && (
          <div className="lb-modal">
            <div className="lb-modal__scrim" onClick={closeModal}></div>
            <div className="lb-modal__card">
              <button type="button" className="lb-modal__close" onClick={closeModal} aria-label="Fermer">×</button>
              <span className="lb-modal__badge">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M5 13l4 4 10-11" /></svg>
              </span>
              <p className="lb-eyebrow" style={{ margin: '0 0 12px' }}>{L.mEyebrow}</p>
              <h2 className="lb-modal__title">{L.mTitle}</h2>
              <p className="lb-modal__msg">{routingMessage}</p>
              <div className="lb-modal__recap">
                <div className="lb-modal__row"><span>{L.mShopLabel}</span><span>{rName}</span></div>
                <div className="lb-modal__row"><span>{L.mNotifLabel}</span><span>{rShop ? rShop.email : ''}</span></div>
                <div className="lb-modal__row"><span>{L.mRefLabel}</span><span>#{rShop ? rShop.id : ''}</span></div>
              </div>
              <button type="button" className="lb-modal__done" onClick={closeModal}>{L.mClose}</button>
            </div>
          </div>
        )}

      </div>
    );
  }

  window.LivraisonBureau = LivraisonBureau;

  const mount = document.getElementById('root');
  if (mount) {
    ReactDOM.createRoot(mount).render(React.createElement(LivraisonBureau));
  }
})();
