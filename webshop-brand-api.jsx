/* =====================================================================
   WSBrand — theming/brand config API stub
   ---------------------------------------------------------------------
   The UI must NEVER hardcode brand colors, fonts, logos or labels in JS.
   It calls WSBrand.get() and applies the result to CSS variables.
   To wire a real backend:
     window.WSBrand.endpoint = 'https://your-host/brand';
   Endpoint:
     GET  {endpoint}?shopId=    -> { tokens: {...}, fonts: [...], logo: '', strings: {...} }

   `apply()` writes any token under `tokens` into the document root as
   --<key> custom properties so the existing CSS picks them up
   automatically. This makes franchise theming a one-line swap.
   ===================================================================== */
(function () {
  const FALLBACK = {
    // CSS custom-property names without leading "--"
    tokens: {},   // e.g. { 'color-primary': '#8d1d2c', 'color-text': '#1f1612' }
    fonts: [],    // e.g. [{ family: 'Souvenir', url: '...', weight: 400 }]
    logo: '',     // public URL or data URI
    strings: {},  // override copy keys consumed by WSI18n if desired
  };

  function injectFonts(fonts) {
    if (!fonts || !fonts.length) return;
    const id = 'ws-brand-fonts';
    let style = document.getElementById(id);
    if (!style) { style = document.createElement('style'); style.id = id; document.head.appendChild(style); }
    style.textContent = fonts.map((f) =>
      `@font-face{font-family:"${f.family}";src:url("${f.url}");font-weight:${f.weight||400};font-style:${f.style||'normal'};font-display:swap;}`
    ).join('\n');
  }
  function applyTokens(tokens) {
    if (!tokens) return;
    const root = document.documentElement;
    Object.entries(tokens).forEach(([k, v]) => root.style.setProperty(`--${k}`, String(v)));
  }

  const api = {
    endpoint: null,
    current: { ...FALLBACK },
    async get(shopId) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}?shopId=${encodeURIComponent(shopId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return FALLBACK;
    },
    async apply(shopId) {
      const cfg = await api.get(shopId);
      api.current = cfg;
      injectFonts(cfg.fonts);
      applyTokens(cfg.tokens);
      if (cfg.strings && window.WSI18n && typeof window.WSI18n.merge === 'function') {
        try { window.WSI18n.merge(cfg.strings); } catch (_) {}
      }
      return cfg;
    },
  };
  window.WSBrand = api;
})();
