/* ============================================================================
   webshop-landing-b2b.js — Landing B2B Événements
   L'Atelier By — page marketing "grands événements" (B2B).

   Imported from Claude Design ("Landing B2B Evenements.dc.html") and converted
   from the DCLogic design-canvas format into a real React 18 component that
   runs with the project's no-build stack (React UMD + Babel Standalone).

   Renders into #root via evenements-b2b.html. Bilingual FR/NL. The contact
   form routes the request to the shop covering the visitor's zone and shows a
   confirmation modal (no server round-trip in demo mode — wire onSubmit to a
   real endpoint when the backend B2B intake is live).
   ========================================================================== */
(function () {
  'use strict';

  // Parse a CSS declaration string ("a:b;c:d") into a React style object so the
  // design's inline style strings can be kept almost verbatim. Custom props
  // (--x) are preserved as-is; standard props are camelCased.
  function css(str) {
    var out = {};
    if (!str) return out;
    str.split(';').forEach(function (decl) {
      var i = decl.indexOf(':');
      if (i < 0) return;
      var key = decl.slice(0, i).trim();
      var val = decl.slice(i + 1).trim();
      if (!key) return;
      if (key.slice(0, 2) === '--') { out[key] = val; return; }
      var camel = key.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
      out[camel] = val;
    });
    return out;
  }

  var CHECK = React.createElement('svg', {
    width: 12, height: 12, viewBox: '0 0 24 24', fill: 'none',
    stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round', strokeLinejoin: 'round'
  }, React.createElement('path', { d: 'M5 13l4 4 10-11' }));

  var CHECK_LG = React.createElement('svg', {
    width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none',
    stroke: 'currentColor', strokeWidth: 1.7, strokeLinecap: 'round', strokeLinejoin: 'round'
  }, React.createElement('path', { d: 'M5 13l4 4 10-11' }));

  class LandingB2B extends React.Component {
    constructor(props) {
      super(props);
      this.state = {
        lang: 'fr', eventType: '', zoneVal: '', cpVisible: false,
        openFaq: -1, modalOpen: false, routedName: '', activeSection: ''
      };
      this._form = null;
      this._reveal = null;
    }

    componentDidMount() {
      try {
        var l = localStorage.getItem('la_lang');
        if (l === 'fr' || l === 'nl') this.setState({ lang: l });
      } catch (e) {}

      var self = this;
      var spyIds = ['reseau', 'terrains', 'deroule', 'capacites'];
      var reveal = function () {
        var vh = window.innerHeight || document.documentElement.clientHeight;
        document.querySelectorAll('[data-rev]:not(.in)').forEach(function (el) {
          var r = el.getBoundingClientRect();
          if (r.top < vh * 0.92 && r.bottom > 0) el.classList.add('in');
        });
        // Scroll spy — highlight the nav entry for the section under the header.
        var cur = '';
        for (var i = 0; i < spyIds.length; i++) {
          var sec = document.getElementById(spyIds[i]);
          if (sec && sec.getBoundingClientRect().top - 96 <= 0) cur = spyIds[i];
        }
        if (cur !== self.state.activeSection) self.setState({ activeSection: cur });
      };
      this._reveal = reveal;
      setTimeout(reveal, 60);
      setTimeout(reveal, 400);
      // Safety net: never leave content hidden even if scroll events don't fire.
      setTimeout(function () {
        document.querySelectorAll('[data-rev]:not(.in)').forEach(function (el) { el.classList.add('in'); });
      }, 1800);
      window.addEventListener('scroll', reveal, { passive: true });
      document.addEventListener('scroll', reveal, { passive: true, capture: true });
      window.addEventListener('resize', reveal);
    }

    componentDidUpdate() {
      // Re-reveal after re-renders so freshly-mounted blocks animate in too.
      if (this._reveal) this._reveal();
    }

    componentWillUnmount() {
      if (!this._reveal) return;
      window.removeEventListener('scroll', this._reveal);
      document.removeEventListener('scroll', this._reveal, { capture: true });
      window.removeEventListener('resize', this._reveal);
    }

    t(fr, nl) { return this.state.lang === 'nl' ? nl : fr; }

    setLang(l) {
      try { localStorage.setItem('la_lang', l); } catch (e) {}
      this.setState({ lang: l });
    }

    langBtn(l) {
      var active = this.state.lang === l;
      return 'border:none;background:' + (active ? 'var(--lp-ink)' : 'transparent') +
        ';color:' + (active ? 'var(--lp-deep)' : 'var(--lp-muted)') +
        ';font-family:var(--font-ui);font-size:11px;letter-spacing:.06em;padding:5px 11px;border-radius:999px;cursor:pointer;';
    }

    navLink(id) {
      var active = this.state.activeSection === id;
      return 'font-size:13px;letter-spacing:.03em;padding-bottom:3px;border-bottom:2px solid ' +
        (active ? 'var(--lp-abricot)' : 'transparent') +
        ';color:' + (active ? 'var(--lp-ink)' : 'var(--lp-muted)') +
        ';transition:color .25s,border-color .25s;';
    }

    gotoForm() {
      var el = document.getElementById('contact');
      if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.pageYOffset - 60, behavior: 'smooth' });
    }

    pick(key) {
      this.setState({ eventType: key });
      var el = document.getElementById('contact');
      if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.pageYOffset - 60, behavior: 'smooth' });
    }

    resolveShop(zoneId) {
      var z = this.content().zones.find(function (x) { return String(x.id) === String(zoneId); });
      return z ? z.shopId : 0;
    }

    onSubmit(e) {
      e.preventDefault();
      var self = this;
      var form = e.target;
      if (form.company_website && form.company_website.value) return; // honeypot
      if (!form.checkValidity()) { form.reportValidity(); return; }
      var D = this.content();
      var t = function (fr, nl) { return self.state.lang === 'nl' ? nl : fr; };
      var shopId = this.resolveShop(this.state.zoneVal);
      var body;
      if (shopId === 0) {
        body = t(
          'Un événement de cette ampleur hors zone couverte est une décision réseau : votre demande part directement à notre direction événements, qui vous recontacte.',
          'Een evenement van deze omvang buiten de dekkingszone is een netwerkbeslissing: uw aanvraag gaat rechtstreeks naar onze evenementendirectie, die contact opneemt.'
        );
      } else {
        var z = D.zones.find(function (x) { return String(x.id) === String(self.state.zoneVal); });
        var shop = z ? z.shop : '';
        body = t(
          'Votre demande a été transmise à la boutique de ' + shop + ', qui couvre votre zone. Un interlocuteur dédié vous recontacte pour le devis et la dégustation.',
          'Uw aanvraag is doorgestuurd naar de winkel van ' + shop + ', die uw zone dekt. Een toegewijd contact neemt contact op voor de offerte en de proeverij.'
        );
      }
      this.setState({ modalOpen: true, routedName: body, zoneVal: '', eventType: '', cpVisible: false });
      try { form.reset(); } catch (er) {}
    }

    cards() {
      var self = this;
      var t = function (fr, nl) { return self.t(fr, nl); };
      return [
        { key: 'corporate', illo: '/landing/assets/abricot/office-delivery.png', name: t('Événements corporate & conventions', 'Corporate & congressen'), desc: t('Volumes élevés, plusieurs jours, contraintes horaires. Coffee breaks et plateaux simultanés sur plusieurs salles.', 'Grote volumes, meerdere dagen, tijdsbeperkingen. Coffee breaks en schotels tegelijk in meerdere zalen.'), onPick: function () { self.pick('corporate'); } },
        { key: 'catering', illo: '/landing/assets/abricot/sandwiches.png', name: t('Gros catering & traiteur', 'Grote catering & traiteur'), desc: t('Buffets, réceptions, coffee breaks. Vous orchestrez, nous fournissons la partie boulangère à l’échelle.', 'Buffetten, recepties, coffee breaks. U regisseert, wij leveren het bakkerijdeel op schaal.'), onPick: function () { self.pick('catering'); } },
        { key: 'mariage', illo: '/landing/assets/abricot/cake.png', name: t('Mariages & salles de réception', 'Huwelijken & feestzalen'), desc: t('Partenariat avec les salles, formules dédiées. D’une pièce montée à des centaines de mignardises.', 'Samenwerking met zalen, aparte formules. Van een pièce montée tot honderden zoetigheden.'), onPick: function () { self.pick('mariage'); } },
        { key: 'scouts', illo: '/landing/assets/abricot/rolls.png', name: t('Camps scouts & collectivités', 'Scoutskampen & collectiviteiten'), desc: t('Volume, budget serré, livraison sur site. Du pain en quantité, là où vous êtes.', 'Volume, krap budget, levering ter plaatse. Brood in hoeveelheid, waar u ook bent.'), onPick: function () { self.pick('scouts'); } }
      ];
    }

    steps() {
      var self = this;
      var t = function (fr, nl) { return self.t(fr, nl); };
      var raw = [
        { num: '1', title: t('Vous décrivez votre projet', 'U beschrijft uw project'), desc: t('Type d’événement, dates, volumes, contraintes. Cinq minutes suffisent.', 'Type evenement, data, volumes, beperkingen. Vijf minuten volstaan.'), badge: '' },
        { num: '2', title: t('Votre boutique de zone vous recontacte', 'Uw zonewinkel neemt contact op'), desc: t('C’est le franchisé qui couvre votre secteur qui prend le relais — adossé à toute la capacité du réseau.', 'De franchisenemer van uw sector neemt over — gedragen door de volledige capaciteit van het netwerk.'), badge: t('Proximité locale', 'Lokale nabijheid') },
        { num: '3', title: t('Devis + dégustation', 'Offerte + proeverij'), desc: t('Une proposition sur mesure, et de quoi goûter avant de décider.', 'Een voorstel op maat, en iets om te proeven voor u beslist.'), badge: '' },
        { num: '4', title: t('Livraison & suivi', 'Levering & opvolging'), desc: t('Le jour J, tout arrive à l’heure. On reste joignables.', 'Op de dag zelf komt alles op tijd. We blijven bereikbaar.'), badge: '' }
      ];
      return raw.map(function (s) {
        var hi = s.num === '2';
        return {
          num: s.num, title: s.title, desc: s.desc, badge: s.badge,
          descColor: hi ? 'rgba(28,19,16,.78)' : 'rgba(251,243,236,.78)',
          wrapStyle: hi
            ? 'background:var(--lp-abricot);color:var(--lp-deep);border:1px solid var(--lp-abricot);border-radius:14px;padding:30px 26px;position:relative;'
            : 'background:var(--lp-panel);border:1px solid var(--lp-line);border-radius:14px;padding:30px 26px;position:relative;',
          badgeStyle: s.badge
            ? 'display:inline-block;margin-top:18px;font-size:10.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--lp-ruby);background:rgba(141,29,44,.14);border-radius:999px;padding:5px 11px;'
            : 'display:none;'
        };
      });
    }

    faq() {
      var self = this;
      var t = function (fr, nl) { return self.t(fr, nl); };
      var raw = [
        { q: t('Travaillez-vous partout en Belgique ?', 'Werkt u overal in België?'), a: t('Chaque zone est couverte par une boutique du réseau. Hors zone couverte, notre direction événements prend directement le relais.', 'Elke zone wordt gedekt door een winkel van het netwerk. Buiten de dekkingszone neemt onze evenementendirectie rechtstreeks over.') },
        { q: t('Y a-t-il un volume minimum ?', 'Is er een minimumvolume?'), a: t('Le B2B est du sur-mesure : on en parle selon votre événement, sans grille figée.', 'B2B is maatwerk: we bespreken het volgens uw evenement, zonder vaste tabel.') },
        { q: t('Combien de temps à l’avance faut-il prévenir ?', 'Hoeveel op voorhand verwittigen?'), a: t('Un délai de prévenance s’applique, variable selon le volume. Plus tôt vous nous prévenez, mieux c’est.', 'Er geldt een aankondigingstermijn, afhankelijk van het volume. Hoe vroeger u ons verwittigt, hoe beter.') },
        { q: t('Gérez-vous les allergènes et régimes spécifiques ?', 'Beheert u allergenen en specifieke diëten?'), a: t('Oui — c’est intégré au devis et au planning de production.', 'Ja — dat zit vervat in de offerte en de productieplanning.') },
        { q: t('Qui établit le devis et signe ?', 'Wie maakt de offerte en tekent?'), a: t('Votre boutique de zone : elle chiffre, organise la dégustation et assure le suivi. Le réseau porte la capacité ; la proximité reste locale.', 'Uw zonewinkel: die berekent, organiseert de proeverij en verzorgt de opvolging. Het netwerk draagt de capaciteit; de nabijheid blijft lokaal.') }
      ];
      return raw.map(function (item, i) {
        var open = self.state.openFaq === i;
        return {
          question: item.q, answer: item.a, open: open,
          onToggle: function () { self.setState({ openFaq: open ? -1 : i }); },
          iconStyle: 'flex:0 0 auto;width:26px;height:26px;border-radius:50%;border:1px solid var(--lp-line);color:var(--lp-abricot);display:inline-flex;align-items:center;justify-content:center;font-size:18px;font-weight:300;line-height:1;transition:transform .3s;transform:rotate(' + (open ? '45' : '0') + 'deg);',
          answerStyle: 'overflow:hidden;transition:max-height .35s ease,opacity .3s ease;max-height:' + (open ? '340px' : '0') + ';opacity:' + (open ? '1' : '0') + ';'
        };
      });
    }

    content() {
      var self = this;
      var t = function (fr, nl) { return self.t(fr, nl); };
      return {
        c: {
          navReseau: t('Le réseau', 'Het netwerk'),
          navTerrains: t('Terrains de jeu', 'Toepassingen'),
          navDeroule: t('Déroulé', 'Verloop'),
          navCapacites: t('Réalisations', 'Realisaties'),
          headerCta: t('Demander un devis', 'Offerte aanvragen'),
          heroEyebrow: t('B2B · Grands événements', 'B2B · Grote evenementen'),
          heroTitle: t('La capacité d’un réseau, le geste d’un artisan.', 'De capaciteit van een netwerk, het gebaar van een ambachtsman.'),
          heroSub: t('Production centralisée, six points de distribution, une logistique coordonnée. De quoi fournir un événement entier — conventions, buffets, réceptions — sans rien lâcher sur la qualité.', 'Gecentraliseerde productie, zes distributiepunten, gecoördineerde logistiek. Genoeg om een volledig evenement te bevoorraden — congressen, buffetten, recepties — zonder toegeving op kwaliteit.'),
          ctaPrimary: t('Demander un devis', 'Een offerte aanvragen'),
          ctaSecondary: t('Parler de mon projet', 'Mijn project bespreken'),
          tagline: t('Ceci n\'est pas qu\'une boulangerie', 'Dit is niet zomaar een bakkerij'),
          reseauEyebrow: t('Le différenciateur', 'Het onderscheid'),
          reseauTitle: t('Ce qu’une boulangerie seule ne peut pas faire', 'Wat één bakkerij alleen niet kan'),
          reseauBody: t('Une boulangerie de quartier a une limite : son four, son équipe, sa nuit de travail. Passé un certain volume, elle sature. L’Atelier By est construit à l’envers — une production centralisée dimensionnée pour le volume, six boutiques qui distribuent près de chez vous, et une logistique qui relie les deux. La capacité d’un industriel, tenue avec les exigences d’un artisan.', 'Een buurtbakkerij heeft een grens: zijn oven, zijn team, zijn werknacht. Voorbij een bepaald volume raakt hij verzadigd. L’Atelier By is omgekeerd opgebouwd — een gecentraliseerde productie op maat van het volume, zes winkels die dicht bij u leveren, en een logistiek die beide verbindt. De capaciteit van een industrieel, met de eisen van een ambachtsman.'),
          terrainsEyebrow: t('Nos terrains de jeu', 'Onze toepassingen'),
          terrainsTitle: t('À chaque événement, sa logique', 'Elk evenement, zijn eigen logica'),
          terrainsSub: t('Cliquez sur un cas : le formulaire s’ouvre, pré-rempli.', 'Klik op een geval: het formulier opent, vooraf ingevuld.'),
          cardCta: t('Décrire ce projet', 'Dit project beschrijven'),
          ceremonieTag: t('Traitement à part', 'Aparte aanpak'),
          ceremonieCta: t('Nous contacter', 'Contact opnemen'),
          obtenezEyebrow: t('Ce que vous obtenez', 'Wat u krijgt'),
          obtenezTitle: t('Un cadre, pas une promesse en l’air', 'Een kader, geen loze belofte'),
          obtenezBody: t('Chaque demande enclenche le même dispositif : un interlocuteur près de chez vous, adossé à la capacité de tout le réseau.', 'Elke aanvraag zet hetzelfde proces in gang: een aanspreekpunt bij u in de buurt, gedragen door de capaciteit van het hele netwerk.'),
          derouleEyebrow: t('Comment on travaille ensemble', 'Hoe we samenwerken'),
          derouleTitle: t('Quatre étapes, un seul interlocuteur', 'Vier stappen, één aanspreekpunt'),
          eventsEyebrow: t('Réalisations', 'Realisaties'),
          eventsTitle: t('Ce que nous avons déjà servi', 'Wat we al geserveerd hebben'),
          eventsNote: t('Quelques événements récents confiés au réseau. Visuels et logos clients sont gérés depuis le back-office.', 'Enkele recente evenementen die aan het netwerk werden toevertrouwd. Beelden en klantlogo’s worden beheerd vanuit de back-office.'),
          clientLabel: t('Client', 'Klant'),
          refEyebrow: t('Références', 'Referenties'),
          refTitle: t('Ils nous ont confié leur volume', 'Zij vertrouwden ons hun volume toe'),
          faqEyebrow: t('Questions fréquentes', 'Veelgestelde vragen'),
          formEyebrow: t('Contact', 'Contact'),
          formTitle: t('Parlons de votre événement', 'Praten we over uw evenement'),
          formSub: t('Décrivez votre projet. Votre boutique de zone vous recontacte — pas de devis automatique, pas de prix en ligne. Du sur-mesure, discuté de vive voix.', 'Beschrijf uw project. Uw zonewinkel neemt contact op — geen automatische offerte, geen prijzen online. Maatwerk, in gesprek.'),
          fType: t('Type d’événement', 'Type evenement'),
          fDate: t('Date de l’événement', 'Datum van het evenement'),
          fGuests: t('Nombre de convives', 'Aantal gasten'),
          fZone: t('Votre zone', 'Uw zone'),
          fPostal: t('Code postal', 'Postcode'),
          fBudget: t('Budget indicatif', 'Indicatief budget'),
          fCompany: t('Société', 'Onderneming'),
          fVat: t('N° de TVA', 'Btw-nummer'),
          fName: t('Nom du contact', 'Naam contactpersoon'),
          fEmail: t('E-mail professionnel', 'Professioneel e-mailadres'),
          fPhone: t('Téléphone', 'Telefoon'),
          fNeeds: t('Besoins spécifiques', 'Specifieke behoeften'),
          selectPlaceholder: t('Choisir…', 'Kiezen…'),
          budgetPh: t('À titre indicatif', 'Ter indicatie'),
          needsPh: t('Allergènes, régimes, contraintes horaires, plusieurs sites…', 'Allergenen, diëten, tijdsbeperkingen, meerdere locaties…'),
          cpHelp: t('Hors zone couverte : votre demande part à notre direction événements.', 'Buiten dekkingszone: uw aanvraag gaat naar onze evenementendirectie.'),
          consent: t('J’accepte que mes données soient transmises au franchisé de ma zone afin d’être recontacté(e). Elles ne sont utilisées que dans ce cadre.', 'Ik ga ermee akkoord dat mijn gegevens worden doorgegeven aan de franchisenemer van mijn zone om gecontacteerd te worden. Ze worden enkel daarvoor gebruikt.'),
          submit: t('Envoyer ma demande', 'Mijn aanvraag versturen'),
          formNote: t('Aucun engagement. Réponse sous quelques jours ouvrés.', 'Geen verplichting. Antwoord binnen enkele werkdagen.'),
          footerTag: t('Maison de pains et viennoiseries — Belgique.', 'Huis van brood en viennoiserie — België.'),
          footerLegal: t('© 2026 L’Atelier By · Mentions légales · Confidentialité', '© 2026 L’Atelier By · Wettelijke vermeldingen · Privacy'),
          // Footer aligned with the index / accueil landing page
          ftTag: t('Maison de pains et viennoiseries — Belgique, depuis 2019.', 'Huis van brood en viennoiserie — België, sinds 2019.'),
          ftExplore: t('Explorer', 'Ontdekken'),
          ftShops: t('Nos boutiques', 'Onze winkels'),
          ftProducts: t('Produits', 'Producten'),
          ftExperiences: t('Expériences', 'Ervaringen'),
          ftServices: t('Services', 'Diensten'),
          ftCollect: t('Click & Collect', 'Click & Collect'),
          ftOffice: t('Livraison bureau', 'Kantoorlevering'),
          ftOnline: t('Magasin en ligne', 'Online winkel'),
          ftHouse: t('La Maison', 'Het Huis'),
          ftFranchise: t('Franchise', 'Franchise'),
          ftGalette: t('Galette des rois', 'Driekoningentaart'),
          ftCopyright: t('© 2026 L’Atelier By — Tous droits réservés.', '© 2026 L’Atelier By — Alle rechten voorbehouden.'),
          ftLegalLinks: t('Mentions légales · Confidentialité · Conditions', 'Wettelijke vermeldingen · Privacy · Voorwaarden'),
          ftTotop: t('Haut de page ↑', 'Naar boven ↑'),
          modalEyebrow: t('Bien reçu', 'Goed ontvangen'),
          modalTitle: t('Votre demande est enregistrée.', 'Uw aanvraag is geregistreerd.'),
          modalClose: t('Fermer', 'Sluiten')
        },
        pillars: [
          { num: '01', title: t('Production centralisée', 'Gecentraliseerde productie'), body: t('Un seul atelier, dimensionné pour le volume. La constance d’une recette, à grande échelle.', 'Eén atelier, op maat van het volume. De constantheid van één recept, op grote schaal.'), illo: '/landing/assets/abricot/croissant.png' },
          { num: '02', title: t('Six points de distribution', 'Zes distributiepunten'), body: t('Halle, Corbais, Gosselies, Sombreffe, Gembloux, Wavre. Un relais près de votre événement.', 'Halle, Corbais, Gosselies, Sombreffe, Gembloux, Wavre. Een schakel dicht bij uw evenement.'), illo: '/landing/assets/abricot/circle.png' },
          { num: '03', title: t('Logistique coordonnée', 'Gecoördineerde logistiek'), body: t('Planning, fabrication et livraison synchronisés. Le volume arrive à l’heure, et complet.', 'Planning, productie en levering gesynchroniseerd. Het volume komt op tijd, en volledig.'), illo: '/landing/assets/abricot/line.png' }
        ],
        cards: this.cards(),
        ceremony: { key: 'ceremonie', name: t('Cérémonies & funérariums', 'Ceremonies & uitvaart'), desc: t('Discrétion, réactivité, formules sobres. Une réponse rapide, sans mise en scène.', 'Discretie, reactiviteit, sobere formules. Een snel antwoord, zonder enscenering.'), onPick: function () { self.pick('ceremonie'); } },
        eventTypes: [
          { key: 'corporate', name: t('Événements corporate & conventions', 'Corporate & congressen') },
          { key: 'catering', name: t('Gros catering & traiteur', 'Grote catering & traiteur') },
          { key: 'mariage', name: t('Mariages & salles de réception', 'Huwelijken & feestzalen') },
          { key: 'ceremonie', name: t('Cérémonies & funérariums', 'Ceremonies & uitvaart') },
          { key: 'scouts', name: t('Camps scouts & collectivités', 'Scoutskampen & collectiviteiten') }
        ],
        gets: [
          { title: t('Un interlocuteur local dédié', 'Een toegewijd lokaal aanspreekpunt'), desc: t('Votre boutique de zone, votre contact.', 'Uw zonewinkel, uw contact.') },
          { title: t('Un devis sur mesure', 'Een offerte op maat'), desc: t('Chiffré selon votre événement, jamais sur catalogue.', 'Berekend volgens uw evenement, nooit uit een catalogus.') },
          { title: t('Une dégustation', 'Een proeverij'), desc: t('On goûte avant de s’engager.', 'Proeven voor u zich engageert.') },
          { title: t('Un planning de livraison', 'Een leveringsplanning'), desc: t('Horaires, sites, quantités — calés à l’avance.', 'Uren, locaties, hoeveelheden — vooraf vastgelegd.') },
          { title: t('Une facturation claire', 'Een heldere facturatie'), desc: t('Une facture nette, TVA en règle.', 'Een nette factuur, btw in orde.') },
          { title: t('Allergènes & régimes', 'Allergenen & diëten'), desc: t('Gestion des allergènes et des régimes spécifiques.', 'Beheer van allergenen en specifieke diëten.') }
        ],
        steps: this.steps(),
        events: [
          { photoId: 'ev-photo-1', logoId: 'ev-logo-1', tag: t('Corporate', 'Corporate'), caption: t('Convention corporate — plateaux et coffee breaks servis en simultané sur plusieurs salles.', 'Bedrijfscongres — schotels en coffee breaks tegelijk geserveerd in meerdere zalen.'), photoPh: t('Photo de l’événement', 'Foto van het evenement'), logoPh: t('Logo client', 'Klantlogo') },
          { photoId: 'ev-photo-2', logoId: 'ev-logo-2', tag: t('Mariage', 'Huwelijk'), caption: t('Réception de mariage — pièce montée et assortiment de mignardises pour la salle.', 'Huwelijksreceptie — pièce montée en assortiment zoetigheden voor de zaal.'), photoPh: t('Photo de l’événement', 'Foto van het evenement'), logoPh: t('Logo client', 'Klantlogo') },
          { photoId: 'ev-photo-3', logoId: 'ev-logo-3', tag: t('Traiteur', 'Traiteur'), caption: t('Buffet traiteur — livraison coordonnée et coffee breaks tout au long de la journée.', 'Cateringbuffet — gecoördineerde levering en coffee breaks doorheen de dag.'), photoPh: t('Photo de l’événement', 'Foto van het evenement'), logoPh: t('Logo client', 'Klantlogo') }
        ],
        testimonials: [
          { quote: t('« 000 pièces sur 0 jours, sans une livraison en retard. »', '« 000 stuks over 0 dagen, zonder één late levering. »'), author: '—', company: t('Événement corporate', 'Corporate evenement') },
          { quote: t('« 000 convives servis sur 0 sites en simultané. »', '« 000 gasten bediend op 0 locaties tegelijk. »'), author: '—', company: t('Traiteur partenaire', 'Partner-traiteur') },
          { quote: t('« Réactivité et discrétion, à chaque fois. »', '« Reactiviteit en discretie, elke keer. »'), author: '—', company: t('Salle de réception', 'Feestzaal') }
        ],
        faq: this.faq(),
        zones: [
          { id: 1, shopId: 1, shop: 'Halle', name: t('Halle & environs', 'Halle & omgeving') },
          { id: 2, shopId: 2, shop: 'Corbais', name: t('Corbais – Brabant wallon', 'Corbais – Waals-Brabant') },
          { id: 3, shopId: 3, shop: 'Gosselies', name: t('Gosselies – Charleroi', 'Gosselies – Charleroi') },
          { id: 4, shopId: 4, shop: 'Sombreffe', name: 'Sombreffe' },
          { id: 5, shopId: 5, shop: 'Gembloux', name: 'Gembloux' },
          { id: 6, shopId: 6, shop: 'Wavre', name: 'Wavre' },
          { id: 0, shopId: 0, shop: '', name: t('Ma zone n’est pas dans la liste', 'Mijn zone staat niet in de lijst') }
        ]
      };
    }

    render() {
      var self = this;
      var D = this.content();
      var c = D.c;
      var st = this.state;

      var rootStyle = {
        '--lp-bg': '#241a16', '--lp-panel': '#78554B', '--lp-panel-2': '#5f4239',
        '--lp-deep': '#1c1310', '--lp-abricot': '#F2C9A0', '--lp-ruby': '#8D1D2C',
        '--lp-ink': '#FBF3EC', '--lp-muted': 'rgba(251,243,236,.66)', '--lp-faint': 'rgba(251,243,236,.40)',
        '--lp-line': 'rgba(242,201,160,.22)', '--lp-hair': 'rgba(251,243,236,.13)',
        background: 'var(--lp-bg)', color: 'var(--lp-ink)', fontFamily: 'var(--font-ui)',
        fontSize: '16px', lineHeight: '1.65', WebkitFontSmoothing: 'antialiased',
        // overflow-x:clip (not hidden) keeps the sticky header pinned — `hidden`
        // makes this element a scroll container, which silently breaks sticky.
        minHeight: '100vh', overflowX: 'clip'
      };

      return React.createElement('div', { style: rootStyle },
        /* ============ HEADER ============ */
        React.createElement('header', { style: css('position:sticky;top:0;z-index:60;background:rgba(28,19,16,.82);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--lp-hair);') },
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;padding:0 clamp(20px,5vw,56px);height:74px;display:flex;align-items:center;justify-content:space-between;gap:24px;') },
            React.createElement('img', { src: '/landing/assets/logo-white.png', alt: "L'Atelier By", className: 'lp-logo', style: css('height:22px;width:auto;display:block;') }),
            React.createElement('nav', { className: 'lp-nav', style: css('display:flex;align-items:center;gap:30px;') },
              React.createElement('a', { href: '#reseau', style: css(self.navLink('reseau')) }, c.navReseau),
              React.createElement('a', { href: '#terrains', style: css(self.navLink('terrains')) }, c.navTerrains),
              React.createElement('a', { href: '#deroule', style: css(self.navLink('deroule')) }, c.navDeroule),
              React.createElement('a', { href: '#capacites', style: css(self.navLink('capacites')) }, c.navCapacites)
            ),
            React.createElement('div', { style: css('display:flex;align-items:center;gap:16px;') },
              React.createElement('div', { style: css('display:inline-flex;gap:2px;border:1px solid var(--lp-hair);border-radius:999px;padding:3px;') },
                React.createElement('button', { type: 'button', onClick: function () { self.setLang('fr'); }, style: css(self.langBtn('fr')) }, 'FR'),
                React.createElement('button', { type: 'button', onClick: function () { self.setLang('nl'); }, style: css(self.langBtn('nl')) }, 'NL')
              ),
              React.createElement('button', { type: 'button', onClick: function () { self.gotoForm(); }, className: 'lp-header-cta', style: css('background:var(--lp-ruby);color:#fff;border:none;border-radius:999px;padding:10px 20px;font-family:var(--font-ui);font-size:13px;font-weight:500;letter-spacing:.02em;cursor:pointer;') }, c.headerCta)
            )
          )
        ),

        /* ============ HERO ============ */
        React.createElement('section', { style: css('position:relative;padding:clamp(72px,12vw,150px) clamp(20px,5vw,56px) clamp(60px,9vw,110px);overflow:hidden;') },
          React.createElement('img', { src: '/landing/assets/abricot/b2b.png', alt: '', 'aria-hidden': 'true', style: css('position:absolute;top:6%;right:-6%;width:min(560px,52vw);opacity:.13;animation:lpfloat 11s ease-in-out infinite;pointer-events:none;') }),
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;position:relative;') },
            React.createElement('div', { 'data-rev': true, style: css('max-width:880px;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.3em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 26px;') }, c.heroEyebrow),
              React.createElement('h1', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.02em;line-height:1.06;margin:0;font-size:clamp(38px,6.4vw,84px);max-width:16ch;') }, c.heroTitle),
              React.createElement('p', { style: css('font-size:clamp(16px,1.7vw,19px);line-height:1.65;color:var(--lp-muted);margin:30px 0 0;max-width:60ch;') }, c.heroSub),
              React.createElement('div', { style: css('display:flex;flex-wrap:wrap;gap:14px;margin-top:40px;') },
                React.createElement('button', { type: 'button', onClick: function () { self.gotoForm(); }, style: css('display:inline-flex;align-items:center;gap:10px;background:var(--lp-ruby);color:#fff;border:none;border-radius:999px;padding:16px 30px;font-family:var(--font-ui);font-size:15px;font-weight:500;letter-spacing:.01em;cursor:pointer;') },
                  c.ctaPrimary, React.createElement('span', { style: css('font-size:17px;line-height:1;') }, '→')),
                React.createElement('button', { type: 'button', onClick: function () { self.gotoForm(); }, style: css('display:inline-flex;align-items:center;gap:10px;background:transparent;color:var(--lp-ink);border:1px solid var(--lp-line);border-radius:999px;padding:16px 30px;font-family:var(--font-ui);font-size:15px;font-weight:500;letter-spacing:.01em;cursor:pointer;') }, c.ctaSecondary)
              ),
              React.createElement('p', { style: css('font-family:var(--font-accent);font-size:19px;color:var(--lp-abricot);margin:44px 0 0;opacity:.9;') }, c.tagline)
            )
          )
        ),

        /* ============ FORCE DU RÉSEAU ============ */
        React.createElement('section', { id: 'reseau', style: css('scroll-margin-top:88px;background:var(--lp-deep);padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);border-top:1px solid var(--lp-hair);') },
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;') },
            React.createElement('div', { 'data-rev': true, style: css('max-width:760px;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '01'), c.reseauEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0 0 22px;font-size:clamp(28px,4vw,48px);max-width:20ch;') }, c.reseauTitle),
              React.createElement('p', { style: css('font-size:17px;line-height:1.72;color:var(--lp-muted);margin:0;max-width:64ch;') }, c.reseauBody)
            ),
            React.createElement('div', { className: 'lpstag', 'data-rev': true, style: css('display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-top:52px;') },
              D.pillars.map(function (p, i) {
                return React.createElement('div', { key: i, style: css('background:var(--lp-panel);border:1px solid var(--lp-line);border-radius:14px;padding:30px 26px;position:relative;overflow:hidden;') },
                  React.createElement('img', { src: p.illo, alt: '', 'aria-hidden': 'true', style: css('position:absolute;right:-18px;bottom:-18px;width:118px;opacity:.16;pointer-events:none;') }),
                  React.createElement('div', { style: css('font-family:var(--font-display);font-size:22px;color:var(--lp-abricot);margin-bottom:16px;') }, p.num),
                  React.createElement('h3', { style: css('font-family:var(--font-ui);font-weight:500;font-size:19px;line-height:1.25;margin:0 0 10px;position:relative;') }, p.title),
                  React.createElement('p', { style: css('font-size:14.5px;line-height:1.6;color:rgba(251,243,236,.78);margin:0;position:relative;max-width:30ch;') }, p.body)
                );
              })
            )
          )
        ),

        /* ============ TERRAINS DE JEU ============ */
        React.createElement('section', { id: 'terrains', style: css('scroll-margin-top:88px;padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);') },
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;') },
            React.createElement('div', { 'data-rev': true, style: css('display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:20px;max-width:900px;') },
              React.createElement('div', null,
                React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                  React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '02'), c.terrainsEyebrow),
                React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0;font-size:clamp(28px,4vw,48px);') }, c.terrainsTitle)
              ),
              React.createElement('p', { style: css('font-size:14px;color:var(--lp-faint);margin:0;max-width:28ch;') }, c.terrainsSub)
            ),
            React.createElement('div', { className: 'lpstag', 'data-rev': true, style: css('display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-top:44px;') },
              D.cards.map(function (uc, i) {
                return React.createElement('button', { key: i, type: 'button', onClick: uc.onPick, className: 'lp-terrain-card', style: css('text-align:left;cursor:pointer;background:var(--lp-panel);border:1px solid var(--lp-line);border-radius:14px;padding:28px 24px 24px;display:flex;flex-direction:column;min-height:250px;position:relative;overflow:hidden;color:var(--lp-ink);font-family:var(--font-ui);transition:transform .3s cubic-bezier(.4,0,.2,1),background .3s;') },
                  React.createElement('img', { src: uc.illo, alt: '', 'aria-hidden': 'true', style: css('position:absolute;right:-24px;top:-24px;width:150px;opacity:.18;pointer-events:none;') }),
                  React.createElement('h3', { style: css('font-family:var(--font-ui);font-weight:500;font-size:20px;line-height:1.22;margin:0 0 12px;max-width:16ch;position:relative;') }, uc.name),
                  React.createElement('p', { style: css('font-size:14px;line-height:1.6;color:rgba(251,243,236,.78);margin:0 0 auto;position:relative;') }, uc.desc),
                  React.createElement('span', { style: css('display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:500;letter-spacing:.02em;color:var(--lp-abricot);margin-top:22px;position:relative;') },
                    c.cardCta, React.createElement('span', { style: css('font-size:15px;') }, '→'))
                );
              })
            ),
            React.createElement('button', { type: 'button', onClick: D.ceremony.onPick, 'data-rev': true, style: css('width:100%;text-align:left;cursor:pointer;margin-top:18px;background:transparent;border:1px solid var(--lp-hair);border-radius:14px;padding:30px clamp(24px,4vw,40px);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:20px;color:var(--lp-ink);font-family:var(--font-ui);') },
              React.createElement('div', { style: css('max-width:60ch;') },
                React.createElement('p', { style: css('font-family:var(--font-ui);font-size:10.5px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:var(--lp-faint);margin:0 0 10px;') }, c.ceremonieTag),
                React.createElement('h3', { style: css('font-family:var(--font-ui);font-weight:500;font-size:20px;margin:0 0 8px;') }, D.ceremony.name),
                React.createElement('p', { style: css('font-size:14px;line-height:1.6;color:var(--lp-muted);margin:0;') }, D.ceremony.desc)
              ),
              React.createElement('span', { style: css('display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:500;color:var(--lp-muted);white-space:nowrap;') },
                c.ceremonieCta, React.createElement('span', { style: css('font-size:15px;') }, '→'))
            )
          )
        ),

        /* ============ CE QUE VOUS OBTENEZ ============ */
        React.createElement('section', { id: 'obtenez', style: css('scroll-margin-top:88px;background:var(--lp-deep);padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);border-top:1px solid var(--lp-hair);') },
          React.createElement('div', { className: 'lp-obtenez', style: css('max-width:1180px;margin:0 auto;display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:clamp(32px,6vw,80px);align-items:start;') },
            React.createElement('div', { 'data-rev': true },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '03'), c.obtenezEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0 0 20px;font-size:clamp(28px,3.6vw,44px);max-width:14ch;') }, c.obtenezTitle),
              React.createElement('p', { style: css('font-size:16px;line-height:1.7;color:var(--lp-muted);margin:0;max-width:42ch;') }, c.obtenezBody)
            ),
            React.createElement('ul', { className: 'lpstag', 'data-rev': true, style: css('list-style:none;margin:0;padding:0;display:grid;gap:2px;') },
              D.gets.map(function (g, i) {
                return React.createElement('li', { key: i, style: css('display:grid;grid-template-columns:26px 1fr;gap:16px;align-items:start;padding:20px 0;border-top:1px solid var(--lp-hair);') },
                  React.createElement('span', { style: css('width:22px;height:22px;border-radius:50%;border:1px solid var(--lp-abricot);color:var(--lp-abricot);display:inline-flex;align-items:center;justify-content:center;margin-top:2px;') }, CHECK),
                  React.createElement('div', null,
                    React.createElement('h3', { style: css('font-family:var(--font-ui);font-weight:500;font-size:16.5px;margin:0 0 3px;') }, g.title),
                    React.createElement('p', { style: css('font-size:14px;line-height:1.55;color:var(--lp-muted);margin:0;') }, g.desc)
                  )
                );
              })
            )
          )
        ),

        /* ============ COMMENT ON TRAVAILLE ============ */
        React.createElement('section', { id: 'deroule', style: css('scroll-margin-top:88px;padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);') },
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;') },
            React.createElement('div', { 'data-rev': true, style: css('max-width:760px;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '04'), c.derouleEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0;font-size:clamp(28px,4vw,48px);max-width:18ch;') }, c.derouleTitle)
            ),
            React.createElement('div', { className: 'lpstag', 'data-rev': true, style: css('display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px;margin-top:48px;') },
              D.steps.map(function (s, i) {
                return React.createElement('div', { key: i, style: css(s.wrapStyle) },
                  React.createElement('div', { style: css('font-family:var(--font-display);font-size:40px;line-height:1;color:var(--lp-abricot);margin-bottom:18px;') }, s.num),
                  React.createElement('h3', { style: css('font-family:var(--font-ui);font-weight:500;font-size:18px;line-height:1.25;margin:0 0 10px;') }, s.title),
                  React.createElement('p', { style: css('font-size:14px;line-height:1.6;color:' + s.descColor + ';margin:0;') }, s.desc),
                  React.createElement('span', { style: css(s.badgeStyle) }, s.badge)
                );
              })
            )
          )
        ),

        /* ============ RÉALISATIONS ============ */
        React.createElement('section', { id: 'capacites', style: css('scroll-margin-top:88px;background:var(--lp-panel);padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);border-top:1px solid var(--lp-hair);position:relative;overflow:hidden;') },
          React.createElement('img', { src: '/landing/assets/abricot/circle.png', alt: '', 'aria-hidden': 'true', style: css('position:absolute;left:-8%;bottom:-20%;width:min(460px,44vw);opacity:.1;pointer-events:none;') }),
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;position:relative;') },
            React.createElement('div', { 'data-rev': true, style: css('max-width:720px;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '05'), c.eventsEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0 0 16px;font-size:clamp(28px,4vw,48px);') }, c.eventsTitle),
              React.createElement('p', { style: css('font-size:15px;line-height:1.65;color:rgba(251,243,236,.72);margin:0;max-width:56ch;') }, c.eventsNote)
            ),
            React.createElement('div', { className: 'lpstag', 'data-rev': true, style: css('display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-top:52px;') },
              D.events.map(function (ev, i) {
                return React.createElement('figure', { key: i, style: css('margin:0;background:var(--lp-deep);border:1px solid var(--lp-line);border-radius:16px;overflow:hidden;display:flex;flex-direction:column;') },
                  React.createElement('div', { style: css('position:relative;width:100%;aspect-ratio:4/3;background:rgba(251,243,236,.05);') },
                    React.createElement('div', { className: 'lp-slot', style: css('position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:16px;font-size:12px;letter-spacing:.04em;color:var(--lp-faint);') }, ev.photoPh)
                  ),
                  React.createElement('figcaption', { style: css('padding:22px 22px 24px;display:flex;flex-direction:column;gap:10px;flex:1;') },
                    React.createElement('span', { style: css('font-family:var(--font-ui);font-size:10.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-abricot);') }, ev.tag),
                    React.createElement('p', { style: css('font-size:15px;line-height:1.5;color:var(--lp-ink);margin:0;flex:1;') }, ev.caption),
                    React.createElement('div', { style: css('margin-top:12px;padding-top:16px;border-top:1px solid var(--lp-hair);display:flex;align-items:center;gap:10px;') },
                      React.createElement('span', { style: css('font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--lp-faint);white-space:nowrap;') }, c.clientLabel),
                      React.createElement('div', { style: css('position:relative;width:120px;height:40px;background:rgba(251,243,236,.06);border-radius:8px;overflow:hidden;') },
                        React.createElement('div', { className: 'lp-slot', style: css('position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;font-size:9.5px;letter-spacing:.04em;color:var(--lp-faint);') }, ev.logoPh)
                      )
                    )
                  )
                );
              })
            )
          )
        ),

        /* ============ RÉFÉRENCES ============ */
        React.createElement('section', { id: 'references', style: css('scroll-margin-top:88px;padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);') },
          React.createElement('div', { style: css('max-width:1180px;margin:0 auto;') },
            React.createElement('div', { 'data-rev': true, style: css('max-width:760px;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '06'), c.refEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.1;margin:0;font-size:clamp(28px,4vw,48px);') }, c.refTitle)
            ),
            React.createElement('div', { className: 'lpstag', 'data-rev': true, style: css('display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-top:44px;') },
              D.testimonials.map(function (tm, i) {
                return React.createElement('figure', { key: i, style: css('background:var(--lp-deep);border:1px solid var(--lp-hair);border-radius:14px;padding:30px 28px;margin:0;display:flex;flex-direction:column;') },
                  React.createElement('span', { style: css('font-family:var(--font-display);font-size:40px;line-height:.6;color:var(--lp-line);') }, '“'),
                  React.createElement('blockquote', { style: css('font-family:var(--font-ui);font-weight:400;font-size:18px;line-height:1.45;margin:12px 0 auto;color:var(--lp-ink);') }, tm.quote),
                  React.createElement('figcaption', { style: css('margin-top:24px;padding-top:18px;border-top:1px solid var(--lp-hair);font-size:13px;color:var(--lp-muted);') },
                    React.createElement('span', { style: css('color:var(--lp-abricot);') }, tm.author), ' · ', tm.company)
                );
              })
            )
          )
        ),

        /* ============ FAQ ============ */
        React.createElement('section', { id: 'faq', style: css('scroll-margin-top:88px;background:var(--lp-deep);padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);border-top:1px solid var(--lp-hair);') },
          React.createElement('div', { style: css('max-width:840px;margin:0 auto;') },
            React.createElement('p', { 'data-rev': true, style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 30px;') },
              React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '07'), c.faqEyebrow),
            React.createElement('div', { 'data-rev': true, style: css('border-top:1px solid var(--lp-hair);') },
              D.faq.map(function (q, i) {
                return React.createElement('div', { key: i, style: css('border-bottom:1px solid var(--lp-hair);') },
                  React.createElement('button', { type: 'button', onClick: q.onToggle, style: css('width:100%;text-align:left;background:transparent;border:none;color:var(--lp-ink);font-family:var(--font-ui);cursor:pointer;padding:24px 0;display:flex;align-items:center;justify-content:space-between;gap:20px;') },
                    React.createElement('span', { style: css('font-size:17px;font-weight:500;line-height:1.35;') }, q.question),
                    React.createElement('span', { style: css(q.iconStyle) }, '+')
                  ),
                  React.createElement('div', { style: css(q.answerStyle) },
                    React.createElement('p', { style: css('font-size:15px;line-height:1.7;color:var(--lp-muted);margin:0;padding:0 40px 26px 0;max-width:62ch;') }, q.answer)
                  )
                );
              })
            )
          )
        ),

        /* ============ CTA + FORMULAIRE ============ */
        React.createElement('section', { id: 'contact', style: css('scroll-margin-top:74px;padding:clamp(64px,9vw,110px) clamp(20px,5vw,56px);') },
          React.createElement('div', { className: 'lp-contact', style: css('max-width:1180px;margin:0 auto;display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:clamp(32px,6vw,72px);align-items:start;') },
            React.createElement('div', { 'data-rev': true, style: css('position:relative;') },
              React.createElement('p', { style: css('font-family:var(--font-ui);font-size:11px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 18px;') },
                React.createElement('span', { style: css('font-family:var(--font-display);margin-right:12px;') }, '08'), c.formEyebrow),
              React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;letter-spacing:-.015em;line-height:1.08;margin:0 0 22px;font-size:clamp(30px,4.4vw,54px);max-width:14ch;') }, c.formTitle),
              React.createElement('p', { style: css('font-size:16.5px;line-height:1.7;color:var(--lp-muted);margin:0;max-width:44ch;') }, c.formSub),
              React.createElement('p', { style: css('font-family:var(--font-accent);font-size:18px;color:var(--lp-abricot);margin:40px 0 0;opacity:.9;') }, c.tagline),
              React.createElement('img', { src: '/landing/assets/abricot/croissant.png', alt: '', 'aria-hidden': 'true', style: css('width:130px;opacity:.16;margin-top:34px;') })
            ),
            React.createElement('form', { 'data-rev': true, noValidate: true, ref: function (el) { self._form = el; }, onSubmit: function (e) { self.onSubmit(e); }, style: css('background:var(--lp-deep);border:1px solid var(--lp-line);border-radius:18px;padding:clamp(26px,3.4vw,40px);') },
              React.createElement('input', { type: 'text', name: 'company_website', tabIndex: -1, autoComplete: 'off', 'aria-hidden': 'true', style: css('position:absolute;left:-9999px;width:1px;height:1px;opacity:0;') }),
              React.createElement('div', { style: css('display:grid;grid-template-columns:1fr 1fr;gap:16px;') },
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fType),
                  React.createElement('div', { style: css('position:relative;') },
                    React.createElement('select', { className: 'lpsel', name: 'type_evenement', required: true, value: st.eventType, onChange: function (e) { self.setState({ eventType: e.target.value }); }, style: css('width:100%;appearance:none;-webkit-appearance:none;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:13px 40px 13px 15px;') },
                      React.createElement('option', { value: '', style: { color: '#111' } }, c.selectPlaceholder),
                      D.eventTypes.map(function (et) { return React.createElement('option', { key: et.key, value: et.key, style: { color: '#111' } }, et.name); })
                    ),
                    React.createElement('span', { style: css('position:absolute;right:15px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--lp-faint);') }, '▾')
                  )
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fDate),
                  React.createElement('input', { type: 'date', name: 'date_evenement', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;color-scheme:dark;') })
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fGuests),
                  React.createElement('input', { type: 'number', name: 'nombre_convives', min: '1', placeholder: '—', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fZone),
                  React.createElement('div', { style: css('position:relative;') },
                    React.createElement('select', { className: 'lpsel', name: 'zone_id', required: true, value: st.zoneVal, onChange: function (e) { var v = e.target.value; self.setState({ zoneVal: v, cpVisible: v === '0' }); }, style: css('width:100%;appearance:none;-webkit-appearance:none;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:13px 40px 13px 15px;') },
                      React.createElement('option', { value: '', style: { color: '#111' } }, c.selectPlaceholder),
                      D.zones.map(function (z) { return React.createElement('option', { key: z.id, value: z.id, style: { color: '#111' } }, z.name); })
                    ),
                    React.createElement('span', { style: css('position:absolute;right:15px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--lp-faint);') }, '▾')
                  )
                ),
                React.createElement('label', { style: css('grid-column:1/-1;flex-direction:column;gap:8px;display:' + (st.cpVisible ? 'flex' : 'none') + ';') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fPostal),
                  React.createElement('input', { type: 'text', name: 'code_postal', inputMode: 'numeric', required: st.cpVisible, placeholder: '1000', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') }),
                  React.createElement('span', { style: css('font-size:12px;color:var(--lp-faint);line-height:1.5;') }, c.cpHelp)
                ),
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fBudget),
                  React.createElement('input', { type: 'text', name: 'budget_indicatif', placeholder: c.budgetPh, style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fCompany),
                  React.createElement('input', { type: 'text', name: 'societe', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fVat),
                  React.createElement('input', { type: 'text', name: 'numero_tva', placeholder: 'BE 0000.000.000', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fName),
                  React.createElement('input', { type: 'text', name: 'contact_nom', required: true, autoComplete: 'name', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fEmail),
                  React.createElement('input', { type: 'email', name: 'email_pro', required: true, autoComplete: 'email', placeholder: 'vous@societe.be', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fPhone),
                  React.createElement('input', { type: 'tel', name: 'telephone', autoComplete: 'tel', placeholder: '+32 …', style: css('width:100%;font-family:var(--font-ui);font-size:15px;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;flex-direction:column;gap:8px;') },
                  React.createElement('span', { style: css('font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--lp-faint);') }, c.fNeeds),
                  React.createElement('textarea', { name: 'besoins_specifiques', rows: 3, placeholder: c.needsPh, style: css('width:100%;resize:vertical;font-family:var(--font-ui);font-size:15px;line-height:1.5;color:var(--lp-ink);background:rgba(251,243,236,.05);border:1px solid var(--lp-hair);border-radius:10px;padding:12px 15px;') })
                ),
                React.createElement('label', { style: css('grid-column:1/-1;display:flex;align-items:flex-start;gap:11px;cursor:pointer;margin-top:2px;') },
                  React.createElement('input', { type: 'checkbox', name: 'consentement', required: true, style: css('width:17px;height:17px;margin-top:3px;accent-color:var(--lp-ruby);flex:0 0 auto;') }),
                  React.createElement('span', { style: css('font-size:12.5px;line-height:1.55;color:var(--lp-muted);') }, c.consent)
                )
              ),
              React.createElement('button', { type: 'submit', style: css('margin-top:26px;width:100%;display:inline-flex;align-items:center;justify-content:center;gap:10px;background:var(--lp-ruby);color:#fff;border:none;cursor:pointer;font-family:var(--font-ui);font-size:15px;font-weight:500;letter-spacing:.01em;border-radius:999px;padding:16px 24px;') },
                c.submit, React.createElement('span', { style: css('font-size:17px;') }, '→')),
              React.createElement('p', { style: css('margin:16px 0 0;font-size:12px;line-height:1.55;color:var(--lp-faint);text-align:center;') }, c.formNote)
            )
          )
        ),

        /* ============ FOOTER (aligned with index / accueil) ============ */
        React.createElement('footer', { className: 'lp-foot' },
          React.createElement('div', { className: 'lp-foot__wrap' },
            React.createElement('div', { className: 'lp-foot__top' },
              React.createElement('div', { className: 'lp-foot__brand' },
                React.createElement('img', { className: 'lp-logo lp-foot__logo', src: '/landing/assets/logo-white.png', alt: "L'Atelier By" }),
                React.createElement('p', { className: 'lp-foot__tag' }, c.ftTag)
              ),
              React.createElement('div', { className: 'lp-foot__col' },
                React.createElement('h4', null, c.ftExplore),
                React.createElement('a', { href: '/index.html#boutiques' }, c.ftShops),
                React.createElement('a', { href: '/index.html#produits' }, c.ftProducts),
                React.createElement('a', { href: '/index.html#experiences' }, c.ftExperiences)
              ),
              React.createElement('div', { className: 'lp-foot__col' },
                React.createElement('h4', null, c.ftServices),
                React.createElement('a', { href: '/index.html#experiences' }, c.ftCollect),
                React.createElement('a', { href: '/index.html#experiences' }, c.ftOffice),
                React.createElement('a', { href: '/index.html#produits' }, c.ftOnline)
              ),
              React.createElement('div', { className: 'lp-foot__col' },
                React.createElement('h4', null, c.ftHouse),
                React.createElement('a', { href: '/franchise-lead.html' }, c.ftFranchise),
                React.createElement('a', { href: '/galette-des-rois.html' }, c.ftGalette)
              )
            ),
            React.createElement('div', { className: 'lp-foot__bottom' },
              React.createElement('span', null, c.ftCopyright),
              React.createElement('span', null, c.ftLegalLinks),
              React.createElement('button', { type: 'button', onClick: function () { window.scrollTo({ top: 0, behavior: 'smooth' }); } }, c.ftTotop)
            )
          )
        ),

        /* ============ MODALE CONFIRMATION ============ */
        React.createElement('div', { style: css('position:fixed;inset:0;z-index:200;align-items:center;justify-content:center;padding:clamp(0px,4vw,40px);display:' + (st.modalOpen ? 'flex' : 'none') + ';') },
          React.createElement('div', { onClick: function () { self.setState({ modalOpen: false }); }, style: css('position:absolute;inset:0;background:rgba(15,10,8,.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);') }),
          React.createElement('div', { style: css('position:relative;background:var(--lp-panel);border:1px solid var(--lp-line);width:100%;max-width:470px;border-radius:18px;box-shadow:0 40px 100px -30px rgba(0,0,0,.7);padding:clamp(32px,5vw,46px);text-align:center;') },
            React.createElement('button', { type: 'button', onClick: function () { self.setState({ modalOpen: false }); }, 'aria-label': 'Fermer', style: css('position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;border:none;background:transparent;color:var(--lp-muted);font-size:22px;line-height:1;cursor:pointer;') }, '×'),
            React.createElement('span', { style: css('width:54px;height:54px;margin:0 auto 22px;border-radius:50%;border:1px solid var(--lp-abricot);color:var(--lp-abricot);display:inline-flex;align-items:center;justify-content:center;') }, CHECK_LG),
            React.createElement('p', { style: css('font-size:11px;font-weight:500;letter-spacing:.26em;text-transform:uppercase;color:var(--lp-abricot);margin:0 0 14px;') }, c.modalEyebrow),
            React.createElement('h2', { style: css('font-family:var(--font-ui);font-weight:400;font-size:clamp(23px,3vw,28px);letter-spacing:-.01em;line-height:1.2;margin:0 auto;max-width:22ch;') }, c.modalTitle),
            React.createElement('p', { style: css('font-size:14.5px;line-height:1.65;color:rgba(251,243,236,.8);margin:16px auto 0;max-width:38ch;') }, st.routedName),
            React.createElement('button', { type: 'button', onClick: function () { self.setState({ modalOpen: false }); }, style: css('margin-top:30px;display:inline-flex;align-items:center;gap:9px;font-size:13px;letter-spacing:.03em;color:var(--lp-ink);background:transparent;border:1px solid var(--lp-line);border-radius:999px;padding:13px 26px;cursor:pointer;') }, c.modalClose)
          )
        )
      );
    }
  }

  window.LandingB2B = LandingB2B;

  var mount = document.getElementById('root');
  if (mount) {
    ReactDOM.createRoot(mount).render(React.createElement(LandingB2B));
  }
})();
