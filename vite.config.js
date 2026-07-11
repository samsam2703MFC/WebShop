import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

// PWA + build de production. Le JSX est pré-compilé (plus de Babel navigateur),
// React/ReactDOM sont empaquetés (plus de CDN). base = sous-dossier GitHub Pages.
export default defineConfig({
  base: '/WebShop/',
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
        start_url: '/WebShop/',
        scope: '/WebShop/',
        icons: [
          { src: 'pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: { globPatterns: ['**/*.{js,css,html,png,svg,woff2}'] },
    }),
  ],
  build: { outDir: 'dist', chunkSizeWarningLimit: 2500 },
});
