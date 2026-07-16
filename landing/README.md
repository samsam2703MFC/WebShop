# Landing B2B Événements — L'Atelier By

Self-contained marketing landing page for L'Atelier By's B2B / grands-événements
offer. Imported from Claude Design and implemented with the project's no-build
stack (React 18 UMD + `React.createElement`, no bundler).

## Contents

```
landing/
├── evenements-b2b.html        entry point
├── webshop-landing-b2b.jsx    React component (plain ES6, no Babel needed)
├── webshop-landing-b2b.css    brand fonts + tokens + page styles
├── favicon-32.png             tab / touch icons
├── apple-touch-icon.png
├── fonts/                     self-hosted Gotham + Vank cuts
└── assets/                    wordmark + decorative apricot illustrations
```

## Deploy

Upload the whole `landing/` folder to the server and open `evenements-b2b.html`
(e.g. `https://your-domain/landing/evenements-b2b.html`). Everything the page
needs — fonts, images, styles — lives inside the folder and is referenced with
relative paths, so no extra configuration is required.

React and ReactDOM are loaded from the unpkg CDN over HTTPS; the host only needs
outbound internet access. To pin them locally instead, drop the two UMD builds
into the folder and point the two `<script src>` tags in `evenements-b2b.html`
at the local files.

## Notes

- Bilingual FR / NL (toggle in the header, remembered via `localStorage`).
- The contact form is client-side only: it validates input, routes the request
  to the shop covering the visitor's zone, and shows a confirmation modal. Wire
  `onSubmit` in `webshop-landing-b2b.jsx` to a real endpoint when the B2B intake
  backend is ready.
- The wordmark asset is the ruby logo rendered white via a CSS filter
  (`.lp-logo`) so it reads on the dark canvas.
