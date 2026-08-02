<?php
/* Config de l'API PHP — édite les identifiants de TA base, puis uploade.
 * (Les getenv() servent aux tests ; en prod, remplace par tes vraies valeurs.) */
return [
  'db' => [
    'host' => getenv('WS_DB_HOST') ?: 'localhost',
    'port' => getenv('WS_DB_PORT') ?: '3306',
    'name' => getenv('WS_DB_NAME') ?: 'test-webshop_db',
    'user' => getenv('WS_DB_USER') ?: 'test_webshop_user',
    'pass' => getenv('WS_DB_PASS') ?: 'change-me',
  ],
  // Secret qui signe les jetons de session (/auth). Long & aléatoire.
  'auth_secret'  => getenv('WS_AUTH_SECRET') ?: 'change-me-long-random',
  // Jeton du back-office admin (/admin/* et la page admin/). Long & aléatoire.
  'admin_token'  => getenv('WS_ADMIN_TOKEN') ?: 'change-me-admin-token',
  // E-mails de commande (from). Laisse vide pour désactiver l'envoi.
  'mail_from'    => getenv('WS_MAIL_FROM') ?: 'no-reply@atelierby.be',
  // Origines autorisées (ton GitHub Pages).
  'cors_origins' => array_values(array_filter(array_map('trim',
                    explode(',', getenv('WS_CORS') ?: 'https://samsam2703mfc.github.io')))),
  // Base du webshop mobile (redirection depuis le footer PWA). Le slug de la
  // boutique est ajouté en query : <webshop_base>?shop=<slug>.
  'webshop_base' => getenv('WS_WEBSHOP_BASE') ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html',
  // Paiement (optionnel) — colle ta clé sk_live_… / sk_test_… pour activer Stripe.
  'stripe_secret'    => getenv('WS_STRIPE_SECRET') ?: '',
  'checkout_success' => getenv('WS_CHECKOUT_SUCCESS') ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html?paid=1',
  'checkout_cancel'  => getenv('WS_CHECKOUT_CANCEL')  ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html?canceled=1',
  /* Secret de signature du WEBHOOK Stripe (whsec_…). À créer dans le tableau de
   * bord Stripe › Développeurs › Webhooks, en pointant vers :
   *     <origine>/webshop/api/payments/stripe-webhook
   * abonné à : checkout.session.completed, checkout.session.expired,
   *            checkout.session.async_payment_succeeded / _failed.
   *
   * SANS ce secret, l'endpoint REFUSE tout. Un webhook non signé n'est qu'une
   * URL publique qui marque des commandes « payées » : qui la connaît s'offre
   * la boutique. Le retour du navigateur sur checkout_success ne fait pas foi
   * non plus — c'est le CLIENT qui le déclenche, il peut ne jamais revenir
   * après avoir payé, ou forger l'URL sans avoir payé. Seul Stripe, signant
   * son événement, dit la vérité sur un encaissement. */
  'stripe_webhook_secret' => getenv('WS_STRIPE_WEBHOOK_SECRET') ?: '',

  /* ── Back-offices Franchise Buddy : deux sessions TOTALEMENT isolées ──────────
   * Chaque BO a SON secret HMAC (jetons non interchangeables) et SON cookie.
   * Génère deux secrets longs et DIFFÉRENTS :  openssl rand -hex 32
   * En prod : cookie_secure = true (HTTPS obligatoire) ; cookie_domain vide =
   * cookie « host-only ». Pour des sous-domaines dédiés (bo-franchise.… /
   * bo-siege.…), tu peux fixer un domaine et/ou des login_url absolus. */
  'bo' => [
    'franchisee' => [
      'secret'    => getenv('FB_FRANCHISEE_SECRET') ?: 'change-me-franchisee-32o-min',
      'cookie'    => getenv('FB_FRANCHISEE_COOKIE') ?: 'fb_franchisee_session',
      'login_url' => getenv('FB_FRANCHISEE_LOGIN')  ?: '/bo/franchisee/login',
    ],
    'franchisor' => [
      'secret'    => getenv('FB_FRANCHISOR_SECRET') ?: 'change-me-franchisor-32o-min',
      'cookie'    => getenv('FB_FRANCHISOR_COOKIE') ?: 'fb_franchisor_session',
      'login_url' => getenv('FB_FRANCHISOR_LOGIN')  ?: '/bo/franchisor/login',
    ],
    // '' = cookie host-only (recommandé si une seule app). Sinon '.tondomaine.be'.
    'cookie_domain' => getenv('FB_COOKIE_DOMAIN') ?: '',
    // true en prod (HTTPS). Mettre 0 UNIQUEMENT pour un test local en http.
    'cookie_secure' => (getenv('FB_COOKIE_SECURE') ?: '1') !== '0',
  ],
];
