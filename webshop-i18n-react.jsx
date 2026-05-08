/* =====================================================================
   WEBSHOP i18n — React glue
   ---------------------------------------------------------------------
   - useT() hook: returns { t, tProduct, tCategory, lang, setLang } and
     re-renders the calling component on language change.
   - <LangChip />: compact language switcher for top nav (FR ▾).
   - <LangMenu />: full radio list for profile/settings drawer.
   ===================================================================== */

(() => {
  const { useState, useEffect, useRef, useCallback } = React;
  const I = window.WSI18n;
  if (!I) {
    console.error('WSI18n not loaded — make sure webshop-i18n.jsx is included before this file.');
    return;
  }

  /* ---------- hook ------------------------------------------------- */
  function useT() {
    const [lang, setLangState] = useState(I.getLang());
    useEffect(() => I.onChange((l) => setLangState(l)), []);
    const setLang = useCallback((l) => I.setLang(l), []);
    return {
      lang,
      setLang,
      t: I.t,
      tProduct: I.tProduct,
      tCategory: I.tCategory,
    };
  }

  /* ---------- compact chip (top nav) ------------------------------- */
  function LangChip({ className = '' }) {
    const { lang, setLang, t } = useT();
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);

    useEffect(() => {
      if (!open) return;
      const onDoc = (e) => {
        if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
      };
      const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
      document.addEventListener('mousedown', onDoc);
      document.addEventListener('keydown', onKey);
      return () => {
        document.removeEventListener('mousedown', onDoc);
        document.removeEventListener('keydown', onKey);
      };
    }, [open]);

    return (
      <div className={`ws-langchip ${className}`} ref={wrapRef}>
        <button
          type="button"
          className="ws-langchip__btn"
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-label={t('lang.selector.label')}
          onClick={() => setOpen((v) => !v)}
        >
          <span className="ws-langchip__code">{lang.toUpperCase()}</span>
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6"/>
          </svg>
        </button>
        {open && (
          <ul className="ws-langchip__menu" role="listbox" aria-label={t('lang.selector.label')}>
            {I.SUPPORTED.map((code) => (
              <li key={code}>
                <button
                  type="button"
                  role="option"
                  aria-selected={code === lang}
                  className={`ws-langchip__opt ${code === lang ? 'is-on' : ''}`}
                  onClick={() => { setLang(code); setOpen(false); }}
                >
                  <span className="ws-langchip__optcode">{code.toUpperCase()}</span>
                  <span className="ws-langchip__optname">{t('lang.' + code)}</span>
                  {code === lang && (
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                      <path d="M5 12l5 5L20 7"/>
                    </svg>
                  )}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    );
  }

  /* ---------- full dropdown (profile / settings) ------------------- */
  function LangMenu() {
    const { lang, setLang, t } = useT();
    return (
      <div className="ws-langmenu">
        <label className="ws-langmenu__label" htmlFor="ws-langmenu-select">
          {t('profile.preferredLang')}
        </label>
        <div className="ws-langmenu__selectwrap">
          <select
            id="ws-langmenu-select"
            className="ws-langmenu__select"
            value={lang}
            onChange={(e) => setLang(e.target.value)}
            aria-label={t('lang.selector.label')}
          >
            {I.SUPPORTED.map((code) => (
              <option key={code} value={code}>
                {code.toUpperCase()} — {t('lang.' + code)}
              </option>
            ))}
          </select>
          <svg className="ws-langmenu__chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6"/>
          </svg>
        </div>
        <p className="ws-langmenu__help">{t('profile.preferredLang.help')}</p>
      </div>
    );
  }

  Object.assign(window, { useT, LangChip, LangMenu });
})();
