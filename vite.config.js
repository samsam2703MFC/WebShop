import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

// PWA + build de production. Le JSX est pré-compilé (plus de Babel navigateur),
// React/ReactDOM sont empaquetés (plus de CDN).
//   base par défaut '/WebShop/' (GitHub Pages) ; le déploiement FTP le passe à '/'
//   (racine du domaine du client) via SITE_BASE — voir .github/workflows/deploy-ftp.yml.
const base = process.env.SITE_BASE || '/WebShop/';
export default defineConfig({
  base,
  // Tampon de build : affiché par ?tapdebug=1 et window.__WS_BUILD pour
  // savoir QUELLE version un téléphone sert réellement (cache SW vs code).
  define: { __WS_BUILD__: JSON.stringify(new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC') },
  esbuild: {
    jsx: 'transform', // classic runtime → React.createElement (React injecté ci-dessous)
    jsxInject: "import React from 'react';import * as ReactDOM from 'react-dom/client';",
  },
  plugins: [
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon-32.png', 'apple-touch-icon.png'],
      manifest: {
        name: "L'Atelier By — Webshop",
        short_name: "L'Atelier By",
        description: "Commandez en ligne dans votre boutique L'Atelier By.",
        lang: 'fr',
        theme_color: '#8D1D2C',
        background_color: '#fdf6f0',
        display: 'standalone',
        start_url: base,
        scope: base,
        icons: [
          { src: 'pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      // FRAÎCHEUR GARANTIE. Un service worker qui précache la coquille HTML a
      // re-servi en production une version PÉRIMÉE du webshop (l'ancien bundle
      // portait encore les boutiques de démo purgées depuis) : le client voyait
      // des données qui n'existent plus nulle part dans le code ni en base.
      //   • cleanupOutdatedCaches : purge les caches des versions précédentes ;
      //   • skipWaiting + clientsClaim : la nouvelle version prend la main tout
      //     de suite, sans attendre la fermeture de tous les onglets ;
      //   • navigateFallbackDenylist /api/ : une navigation vers l'API ne doit
      //     JAMAIS être servie par la coquille en cache ;
      //   • runtimeCaching NetworkFirst sur les navigations : la coquille est
      //     toujours redemandée au serveur (repli cache seulement hors-ligne),
      //     donc un déploiement est visible au rechargement suivant.
      // L'API (/api/…) n'est jamais mise en cache : les données restent vraies.
      workbox: {
        globPatterns: ['**/*.{js,css,html,png,svg,woff2}'],
        cleanupOutdatedCaches: true,
        skipWaiting: true,
        clientsClaim: true,
        // PAS de fallback de navigation : le scope du SW couvre /webshop/* et
        // le fallback par défaut servait la coquille du WEBSHOP à la place des
        // apps sœurs (/webshop/backoffice_franchisee/…) — le BO s'affichait
        // remplacé par le webshop avec une API introuvable. Chaque navigation
        // part TOUJOURS au serveur (NetworkFirst ci-dessous).
        navigateFallback: null,
        navigateFallbackDenylist: [/^\/api\//, /\/api\//],
        runtimeCaching: [
          {
            urlPattern: ({ request }) => request.mode === 'navigate',
            handler: 'NetworkFirst',
            options: { cacheName: 'ws-html', networkTimeoutSeconds: 5 },
          },
        ],
      },
    }),
  ],
  build: { outDir: 'dist', chunkSizeWarningLimit: 2500 },
});
