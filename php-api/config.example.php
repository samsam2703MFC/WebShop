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
  // Paiement (optionnel) — colle ta clé sk_live_… / sk_test_… pour activer Stripe.
  'stripe_secret'    => getenv('WS_STRIPE_SECRET') ?: '',
  'checkout_success' => getenv('WS_CHECKOUT_SUCCESS') ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html?paid=1',
  'checkout_cancel'  => getenv('WS_CHECKOUT_CANCEL')  ?: 'https://samsam2703mfc.github.io/WebShop/webshop-full.html?canceled=1',
];
