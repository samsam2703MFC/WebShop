// admin-bundle.jsx (auto-generated)
// Expose React hooks once, globally, so all modules can use them without redeclaring.
window.useState = React.useState;
window.useEffect = React.useEffect;
window.useMemo = React.useMemo;
window.useRef = React.useRef;
window.useCallback = React.useCallback;
window.useLayoutEffect = React.useLayoutEffect;
const { useState, useEffect, useMemo, useRef, useCallback, useLayoutEffect } = React;


// ===== design-canvas.jsx =====

// DesignCanvas.jsx — Figma-ish design canvas wrapper
// Warm gray grid bg + Sections + Artboards + PostIt notes.
// Artboards are reorderable (grip-drag), deletable, labels/titles are
// inline-editable, and any artboard can be opened in a fullscreen focus
// overlay (←/→/Esc). State persists to a .design-canvas.state.json sidecar
// via the host bridge. No assets, no deps.
//
// Usage:
//   <DesignCanvas>
//     <DCSection id="onboarding" title="Onboarding" subtitle="First-run variants">
//       <DCArtboard id="a" label="A · Dusk" width={260} height={480}>…</DCArtboard>
//       <DCArtboard id="b" label="B · Minimal" width={260} height={480}>…</DCArtboard>
//     </DCSection>
//   </DesignCanvas>

const DC = {
  bg: '#f0eee9',
  grid: 'rgba(0,0,0,0.06)',
  label: 'rgba(60,50,40,0.7)',
  title: 'rgba(40,30,20,0.85)',
  subtitle: 'rgba(60,50,40,0.6)',
  postitBg: '#fef4a8',
  postitText: '#5a4a2a',
  font: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif',
};

// One-time CSS injection (classes are dc-prefixed so they don't collide with
// the hosted design's own styles).
if (typeof document !== 'undefined' && !document.getElementById('dc-styles')) {
  const s = document.createElement('style');
  s.id = 'dc-styles';
  s.textContent = [
    '.dc-editable{cursor:text;outline:none;white-space:nowrap;border-radius:3px;padding:0 2px;margin:0 -2px}',
    '.dc-editable:focus{background:#fff;box-shadow:0 0 0 1.5px #c96442}',
    '[data-dc-slot]{transition:transform .18s cubic-bezier(.2,.7,.3,1)}',
    '[data-dc-slot].dc-dragging{transition:none;z-index:10;pointer-events:none}',
    '[data-dc-slot].dc-dragging .dc-card{box-shadow:0 12px 40px rgba(0,0,0,.25),0 0 0 2px #c96442;transform:scale(1.02)}',
    // isolation:isolate contains artboard content's z-indexes so a
    // z-indexed child (sticky navbar etc.) can't paint over .dc-header or
    // the .dc-menu popover that drops into the top of the card.
    '.dc-card{isolation:isolate;transition:box-shadow .15s,transform .15s}',
    '.dc-card *{scrollbar-width:none}',
    '.dc-card *::-webkit-scrollbar{display:none}',
    // Per-artboard header: grip + label on the left, delete/expand on the
    // right. Single flex row; when the artboard's on-screen width is too
    // narrow for both the label yields (ellipsis, then hidden entirely below
    // ~4ch via the container query) and the buttons stay on the row.
    '.dc-header{position:absolute;bottom:100%;left:-4px;margin-bottom:calc(4px * var(--dc-inv-zoom,1));z-index:2;',
    '  display:flex;align-items:center;container-type:inline-size}',
    '.dc-labelrow{display:flex;align-items:center;gap:4px;height:24px;flex:1 1 auto;min-width:0}',
    '.dc-grip{flex:0 0 auto;cursor:grab;display:flex;align-items:center;padding:5px 4px;border-radius:4px;transition:background .12s,opacity .12s}',
    '.dc-grip:hover{background:rgba(0,0,0,.08)}',
    '.dc-grip:active{cursor:grabbing}',
    '.dc-labeltext{flex:1 1 auto;min-width:0;cursor:pointer;border-radius:4px;padding:3px 6px;',
    '  display:flex;align-items:center;transition:background .12s;overflow:hidden}',
    // Below ~4ch of label room: hide the label entirely, and drop the grip to
    // hover-only (same reveal rule as .dc-btns) so a narrow header is clean
    // until the card is moused.
    '@container (max-width: 110px){',
    '  .dc-labeltext{display:none}',
    '  .dc-grip{opacity:0}',
    '  [data-dc-slot]:hover .dc-grip{opacity:1}',
    '}',
    '.dc-labeltext:hover{background:rgba(0,0,0,.05)}',
    '.dc-labeltext .dc-editable{overflow:hidden;text-overflow:ellipsis;max-width:100%}',
    '.dc-labeltext .dc-editable:focus{overflow:visible;text-overflow:clip}',
    '.dc-btns{flex:0 0 auto;margin-left:auto;display:flex;gap:2px;opacity:0;transition:opacity .12s}',
    '[data-dc-slot]:hover .dc-btns,.dc-btns:has(.dc-menu){opacity:1}',
    '.dc-expand,.dc-kebab{width:22px;height:22px;border-radius:5px;border:none;cursor:pointer;padding:0;',
    '  background:transparent;color:rgba(60,50,40,.7);display:flex;align-items:center;justify-content:center;',
    '  font:inherit;transition:background .12s,color .12s}',
    '.dc-expand:hover,.dc-kebab:hover{background:rgba(0,0,0,.06);color:#2a251f}',
    // Slot hosting an open menu floats above later siblings (which otherwise
    // paint on top — same z-index:auto, later DOM order) so the popup isn't
    // clipped by the next card.
    '[data-dc-slot]:has(.dc-menu){z-index:10}',
    '.dc-menu{position:absolute;top:100%;right:0;margin-top:4px;background:#fff;border-radius:8px;',
    '  box-shadow:0 8px 28px rgba(0,0,0,.18),0 0 0 1px rgba(0,0,0,.05);padding:4px;min-width:160px;z-index:10}',
    '.dc-menu button{display:block;width:100%;padding:7px 10px;border:0;background:transparent;',
    '  border-radius:5px;font-family:inherit;font-size:13px;font-weight:500;line-height:1.2;',
    '  color:#29261b;cursor:pointer;text-align:left;transition:background .12s;white-space:nowrap}',
    '.dc-menu button:hover{background:rgba(0,0,0,.05)}',
    '.dc-menu hr{border:0;border-top:1px solid rgba(0,0,0,.08);margin:4px 2px}',
    '.dc-menu .dc-danger{color:#c96442}',
    '.dc-menu .dc-danger:hover{background:rgba(201,100,66,.1)}',
    // Chrome (titles / labels / buttons) counter-scales against the viewport
    // zoom so it stays a constant on-screen size. --dc-inv-zoom is set by
    // DCViewport on every transform update and inherits to all descendants —
    // any overlay inside the world (e.g. a TweaksPanel on an artboard) can use
    // it the same way.
    //
    // The header uses transform:scale (out-of-flow, so layout impact doesn't
    // matter) with its world-space width set to card-width / inv-zoom so that
    // after counter-scaling its on-screen width exactly matches the card's —
    // that's what lets the container query + text-overflow behave against the
    // card's visible edge at every zoom level.
    //
    // The section head uses CSS zoom instead of transform so its layout box
    // grows with the counter-scale, pushing the card row down — otherwise the
    // constant-screen-size title would overflow into the (shrinking) world-
    // space gap and overlap the artboard headers at low zoom.
    '.dc-header{width:calc((100% + 4px) / var(--dc-inv-zoom,1));',
    '  transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom left}',
    '.dc-sectionhead{zoom:var(--dc-inv-zoom,1)}',
  ].join('\n');
  document.head.appendChild(s);
}

const DCCtx = React.createContext(null);

// ─────────────────────────────────────────────────────────────
// DesignCanvas — stateful wrapper around the pan/zoom viewport.
// Owns runtime state (per-section order, renamed titles/labels, hidden
// artboards, focused artboard). Order/titles/labels/hidden persist to a
// .design-canvas.state.json
// sidecar next to the HTML. Reads go via plain fetch() so the saved
// arrangement is visible anywhere the HTML + sidecar are served together
// (omelette preview, direct link, downloaded zip). Writes go through the
// host's window.omelette bridge — editing requires the omelette runtime.
// Focus is ephemeral.
// ─────────────────────────────────────────────────────────────
const DC_STATE_FILE = '.design-canvas.state.json';

function DesignCanvas({ children, minScale, maxScale, style }) {
  const [state, setState] = React.useState({ sections: {}, focus: null });
  // Hold rendering until the sidecar read settles so the saved order/titles
  // appear on first paint (no source-order flash). didRead gates writes until
  // the read settles so the empty initial state can't clobber a slow read;
  // skipNextWrite suppresses the one echo-write that would otherwise follow
  // hydration.
  const [ready, setReady] = React.useState(false);
  const didRead = React.useRef(false);
  const skipNextWrite = React.useRef(false);

  React.useEffect(() => {
    let off = false;
    fetch('./' + DC_STATE_FILE)
      .then((r) => (r.ok ? r.json() : null))
      .then((saved) => {
        if (off || !saved || !saved.sections) return;
        skipNextWrite.current = true;
        setState((s) => ({ ...s, sections: saved.sections }));
      })
      .catch(() => {})
      .finally(() => { didRead.current = true; if (!off) setReady(true); });
    const t = setTimeout(() => { if (!off) setReady(true); }, 150);
    return () => { off = true; clearTimeout(t); };
  }, []);

  React.useEffect(() => {
    if (!didRead.current) return;
    if (skipNextWrite.current) { skipNextWrite.current = false; return; }
    const t = setTimeout(() => {
      window.omelette?.writeFile(DC_STATE_FILE, JSON.stringify({ sections: state.sections })).catch(() => {});
    }, 250);
    return () => clearTimeout(t);
  }, [state.sections]);

  // Build registries synchronously from children so FocusOverlay can read
  // them in the same render. Only direct DCSection > DCArtboard children are
  // walked — wrapping them in other elements opts out of focus/reorder.
  const registry = {};     // slotId -> { sectionId, artboard }
  const sectionMeta = {};  // sectionId -> { title, subtitle, slotIds[] }
  const sectionOrder = [];
  React.Children.forEach(children, (sec) => {
    if (!sec || sec.type !== DCSection) return;
    const sid = sec.props.id ?? sec.props.title;
    if (!sid) return;
    sectionOrder.push(sid);
    const persisted = state.sections[sid] || {};
    const abs = [];
    React.Children.forEach(sec.props.children, (ab) => {
      if (!ab || ab.type !== DCArtboard) return;
      const aid = ab.props.id ?? ab.props.label;
      if (aid) abs.push([aid, ab]);
    });
    // hidden is scoped to one source revision — when the agent regenerates
    // (artboard-ID set changes), prior deletes don't apply to new content.
    const srcKey = abs.map(([k]) => k).join('\x1f');
    const hidden = persisted.srcKey === srcKey ? (persisted.hidden || []) : [];
    const srcIds = [];
    abs.forEach(([aid, ab]) => {
      if (hidden.includes(aid)) return;
      registry[`${sid}/${aid}`] = { sectionId: sid, artboard: ab };
      srcIds.push(aid);
    });
    const kept = (persisted.order || []).filter((k) => srcIds.includes(k));
    sectionMeta[sid] = {
      title: persisted.title ?? sec.props.title,
      subtitle: sec.props.subtitle,
      slotIds: [...kept, ...srcIds.filter((k) => !kept.includes(k))],
    };
  });

  const api = React.useMemo(() => ({
    state,
    section: (id) => state.sections[id] || {},
    patchSection: (id, p) => setState((s) => ({
      ...s,
      sections: { ...s.sections, [id]: { ...s.sections[id], ...(typeof p === 'function' ? p(s.sections[id] || {}) : p) } },
    })),
    setFocus: (slotId) => setState((s) => ({ ...s, focus: slotId })),
  }), [state]);

  // Esc exits focus; any outside pointerdown commits an in-progress rename.
  React.useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') api.setFocus(null); };
    const onPd = (e) => {
      const ae = document.activeElement;
      if (ae && ae.isContentEditable && !ae.contains(e.target)) ae.blur();
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPd, true);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPd, true);
    };
  }, [api]);

  return (
    <DCCtx.Provider value={api}>
      <DCViewport minScale={minScale} maxScale={maxScale} style={style}>{ready && children}</DCViewport>
      {state.focus && registry[state.focus] && (
        <DCFocusOverlay entry={registry[state.focus]} sectionMeta={sectionMeta} sectionOrder={sectionOrder} />
      )}
    </DCCtx.Provider>
  );
}

// ─────────────────────────────────────────────────────────────
// DCViewport — transform-based pan/zoom (internal)
//
// Input mapping (Figma-style):
//   • trackpad pinch  → zoom   (ctrlKey wheel; Safari gesture* events)
//   • trackpad scroll → pan    (two-finger)
//   • mouse wheel     → zoom   (notched; distinguished from trackpad scroll)
//   • middle-drag / primary-drag-on-bg → pan
//
// Transform state lives in a ref and is written straight to the DOM
// (translate3d + will-change) so wheel ticks don't go through React —
// keeps pans at 60fps on dense canvases.
// ─────────────────────────────────────────────────────────────
function DCViewport({ children, minScale = 0.1, maxScale = 8, style = {} }) {
  const vpRef = React.useRef(null);
  const worldRef = React.useRef(null);
  const tf = React.useRef({ x: 0, y: 0, scale: 1 });
  // Persist viewport across reloads so the user lands back where they were
  // after an agent edit or browser refresh. The sandbox origin is already
  // per-project; pathname keeps multiple canvas files in one project apart.
  const tfKey = 'dc-viewport:' + location.pathname;
  const saveT = React.useRef(0);

  const lastPostedScale = React.useRef();
  const apply = React.useCallback(() => {
    const { x, y, scale } = tf.current;
    const el = worldRef.current;
    if (!el) return;
    el.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scale})`;
    // Exposed for zoom-invariant chrome (labels, buttons, TweaksPanel).
    el.style.setProperty('--dc-inv-zoom', String(1 / scale));
    // Keep the host toolbar's % readout in sync with the canvas scale. Pan
    // ticks leave scale unchanged — skip the cross-frame post for those.
    if (lastPostedScale.current !== scale) {
      lastPostedScale.current = scale;
      window.parent.postMessage({ type: '__dc_zoom', scale }, '*');
    }
    clearTimeout(saveT.current);
    saveT.current = setTimeout(() => {
      try { localStorage.setItem(tfKey, JSON.stringify(tf.current)); } catch {}
    }, 200);
  }, [tfKey]);

  React.useLayoutEffect(() => {
    const flush = () => {
      clearTimeout(saveT.current);
      try { localStorage.setItem(tfKey, JSON.stringify(tf.current)); } catch {}
    };
    try {
      const s = JSON.parse(localStorage.getItem(tfKey) || 'null');
      if (s && Number.isFinite(s.x) && Number.isFinite(s.y) && Number.isFinite(s.scale)) {
        tf.current = { x: s.x, y: s.y, scale: Math.min(maxScale, Math.max(minScale, s.scale)) };
        apply();
      }
    } catch {}
    // Flush on pagehide and unmount so a reload within the 200ms debounce
    // window doesn't drop the last pan/zoom.
    window.addEventListener('pagehide', flush);
    return () => { window.removeEventListener('pagehide', flush); flush(); };
  }, []);

  React.useEffect(() => {
    const vp = vpRef.current;
    if (!vp) return;

    const zoomAt = (cx, cy, factor) => {
      const r = vp.getBoundingClientRect();
      const px = cx - r.left, py = cy - r.top;
      const t = tf.current;
      const next = Math.min(maxScale, Math.max(minScale, t.scale * factor));
      const k = next / t.scale;
      // keep the world point under the cursor fixed
      t.x = px - (px - t.x) * k;
      t.y = py - (py - t.y) * k;
      t.scale = next;
      apply();
    };

    // Mouse-wheel vs trackpad-scroll heuristic. A physical wheel sends
    // line-mode deltas (Firefox) or large integer pixel deltas with no X
    // component (Chrome/Safari, typically multiples of 100/120). Trackpad
    // two-finger scroll sends small/fractional pixel deltas, often with
    // non-zero deltaX. ctrlKey is set by the browser for trackpad pinch.
    const isMouseWheel = (e) =>
      e.deltaMode !== 0 ||
      (e.deltaX === 0 && Number.isInteger(e.deltaY) && Math.abs(e.deltaY) >= 40);

    const onWheel = (e) => {
      e.preventDefault();
      if (isGesturing) return; // Safari: gesture* owns the pinch — discard concurrent wheels
      if ((e.ctrlKey || e.metaKey) && !isMouseWheel(e)) {
        // trackpad pinch, or ctrl/cmd + smooth-scroll mouse. Notched
        // wheels fall through to the fixed-step branch below.
        zoomAt(e.clientX, e.clientY, Math.exp(-e.deltaY * 0.01));
      } else if (isMouseWheel(e)) {
        // notched mouse wheel — fixed-ratio step per click
        zoomAt(e.clientX, e.clientY, Math.exp(-Math.sign(e.deltaY) * 0.18));
      } else {
        // trackpad two-finger scroll — pan
        tf.current.x -= e.deltaX;
        tf.current.y -= e.deltaY;
        apply();
      }
    };

    // Safari sends native gesture* events for trackpad pinch with a smooth
    // e.scale; preferring these over the ctrl+wheel fallback gives a much
    // better feel there. No-ops on other browsers. Safari also fires
    // ctrlKey wheel events during the same pinch — isGesturing makes
    // onWheel drop those entirely so they neither zoom nor pan.
    let gsBase = 1;
    let isGesturing = false;
    const onGestureStart = (e) => { e.preventDefault(); isGesturing = true; gsBase = tf.current.scale; };
    const onGestureChange = (e) => {
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, (gsBase * e.scale) / tf.current.scale);
    };
    const onGestureEnd = (e) => { e.preventDefault(); isGesturing = false; };

    // Drag-pan: middle button anywhere, or primary button on canvas
    // background (anything that isn't an artboard or an inline editor).
    let drag = null;
    const onPointerDown = (e) => {
      const onBg = !e.target.closest('[data-dc-slot], .dc-editable');
      if (!(e.button === 1 || (e.button === 0 && onBg))) return;
      e.preventDefault();
      vp.setPointerCapture(e.pointerId);
      drag = { id: e.pointerId, lx: e.clientX, ly: e.clientY };
      vp.style.cursor = 'grabbing';
    };
    const onPointerMove = (e) => {
      if (!drag || e.pointerId !== drag.id) return;
      tf.current.x += e.clientX - drag.lx;
      tf.current.y += e.clientY - drag.ly;
      drag.lx = e.clientX; drag.ly = e.clientY;
      apply();
    };
    const onPointerUp = (e) => {
      if (!drag || e.pointerId !== drag.id) return;
      vp.releasePointerCapture(e.pointerId);
      drag = null;
      vp.style.cursor = '';
    };

    // Host-driven zoom (toolbar % menu). Zooms around viewport centre so the
    // visible midpoint stays fixed — matching the host's iframe-zoom feel.
    const onHostMsg = (e) => {
      const d = e.data;
      if (d && d.type === '__dc_set_zoom' && typeof d.scale === 'number') {
        const r = vp.getBoundingClientRect();
        zoomAt(r.left + r.width / 2, r.top + r.height / 2, d.scale / tf.current.scale);
      } else if (d && d.type === '__dc_probe') {
        // Host's [readyGen] reset asks whether a canvas is present; it
        // fires on the iframe's native 'load', which for canvases with
        // images/fonts is after our mount-time announce, so re-announce.
        // Clear the pan-tick guard so apply() re-posts the current scale
        // even if it's unchanged — the host just reset dcScale to 1.
        window.parent.postMessage({ type: '__dc_present' }, '*');
        lastPostedScale.current = undefined;
        apply();
      }
    };
    window.addEventListener('message', onHostMsg);
    // Announce canvas mode so the host toolbar proxies its % control here
    // instead of scaling the iframe element (which would just shrink the
    // viewport window of an infinite canvas). The apply() that follows emits
    // the initial __dc_zoom so the toolbar % is correct before first pinch.
    // lastPostedScale reset mirrors the __dc_probe handler: the layout
    // effect's restore-path apply() may already have posted the restored
    // scale (before __dc_present), so clear the guard to re-post it in order.
    window.parent.postMessage({ type: '__dc_present' }, '*');
    lastPostedScale.current = undefined;
    apply();

    vp.addEventListener('wheel', onWheel, { passive: false });
    vp.addEventListener('gesturestart', onGestureStart, { passive: false });
    vp.addEventListener('gesturechange', onGestureChange, { passive: false });
    vp.addEventListener('gestureend', onGestureEnd, { passive: false });
    vp.addEventListener('pointerdown', onPointerDown);
    vp.addEventListener('pointermove', onPointerMove);
    vp.addEventListener('pointerup', onPointerUp);
    vp.addEventListener('pointercancel', onPointerUp);
    return () => {
      window.removeEventListener('message', onHostMsg);
      vp.removeEventListener('wheel', onWheel);
      vp.removeEventListener('gesturestart', onGestureStart);
      vp.removeEventListener('gesturechange', onGestureChange);
      vp.removeEventListener('gestureend', onGestureEnd);
      vp.removeEventListener('pointerdown', onPointerDown);
      vp.removeEventListener('pointermove', onPointerMove);
      vp.removeEventListener('pointerup', onPointerUp);
      vp.removeEventListener('pointercancel', onPointerUp);
    };
  }, [apply, minScale, maxScale]);

  const gridSvg = `url("data:image/svg+xml,%3Csvg width='120' height='120' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M120 0H0v120' fill='none' stroke='${encodeURIComponent(DC.grid)}' stroke-width='1'/%3E%3C/svg%3E")`;
  return (
    <div
      ref={vpRef}
      className="design-canvas"
      style={{
        height: '100vh', width: '100vw',
        background: DC.bg,
        overflow: 'hidden',
        overscrollBehavior: 'none',
        touchAction: 'none',
        position: 'relative',
        fontFamily: DC.font,
        boxSizing: 'border-box',
        ...style,
      }}
    >
      <div
        ref={worldRef}
        style={{
          position: 'absolute', top: 0, left: 0,
          transformOrigin: '0 0',
          willChange: 'transform',
          width: 'max-content', minWidth: '100%',
          minHeight: '100%',
          padding: '60px 0 80px',
        }}
      >
        <div style={{ position: 'absolute', inset: -6000, backgroundImage: gridSvg, backgroundSize: '120px 120px', pointerEvents: 'none', zIndex: -1 }} />
        {children}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// DCSection — editable title + h-row of artboards in persisted order
// ─────────────────────────────────────────────────────────────
function DCSection({ id, title, subtitle, children, gap = 48 }) {
  const ctx = React.useContext(DCCtx);
  const sid = id ?? title;
  const all = React.Children.toArray(children);
  const artboards = all.filter((c) => c && c.type === DCArtboard);
  const rest = all.filter((c) => !(c && c.type === DCArtboard));
  const sec = (ctx && sid && ctx.section(sid)) || {};
  // Must match DesignCanvas's srcKey computation exactly (it filters falsy
  // IDs), or onDelete persists a srcKey that DesignCanvas never recognizes.
  const allIds = artboards.map((a) => a.props.id ?? a.props.label).filter(Boolean);
  const srcKey = allIds.join('\x1f');
  const hidden = sec.srcKey === srcKey ? (sec.hidden || []) : [];
  const srcOrder = allIds.filter((k) => !hidden.includes(k));

  const order = React.useMemo(() => {
    const kept = (sec.order || []).filter((k) => srcOrder.includes(k));
    return [...kept, ...srcOrder.filter((k) => !kept.includes(k))];
  }, [sec.order, srcOrder.join('|')]);

  const byId = Object.fromEntries(artboards.map((a) => [a.props.id ?? a.props.label, a]));

  // marginBottom counter-scales so the on-screen gap between sections stays
  // constant — otherwise at low zoom the (world-space) gap collapses while
  // the screen-constant sectionhead below it doesn't, and the title reads as
  // belonging to the section above. paddingBottom below is just enough for
  // the 24px artboard-header (abs-positioned above each card) plus ~8px, so
  // the title sits tight against its own row at every zoom.
  return (
    <div data-dc-section={sid}
      style={{ marginBottom: 'calc(80px * var(--dc-inv-zoom, 1))', position: 'relative' }}>
      <div style={{ padding: '0 60px' }}>
        <div className="dc-sectionhead" style={{ paddingBottom: 36 }}>
          <DCEditable tag="div" value={sec.title ?? title}
            onChange={(v) => ctx && sid && ctx.patchSection(sid, { title: v })}
            style={{ fontSize: 28, fontWeight: 600, color: DC.title, letterSpacing: -0.4, marginBottom: 6, display: 'inline-block' }} />
          {subtitle && <div style={{ fontSize: 16, color: DC.subtitle }}>{subtitle}</div>}
        </div>
      </div>
      <div style={{ display: 'flex', gap, padding: '0 60px', alignItems: 'flex-start', width: 'max-content' }}>
        {order.map((k) => (
          <DCArtboardFrame key={k} sectionId={sid} artboard={byId[k]} order={order}
            label={(sec.labels || {})[k] ?? byId[k].props.label}
            onRename={(v) => ctx && ctx.patchSection(sid, (x) => ({ labels: { ...x.labels, [k]: v } }))}
            onReorder={(next) => ctx && ctx.patchSection(sid, { order: next })}
            onDelete={() => ctx && ctx.patchSection(sid, (x) => ({
              hidden: [...(x.srcKey === srcKey ? (x.hidden || []) : []), k],
              srcKey,
            }))}
            onFocus={() => ctx && ctx.setFocus(`${sid}/${k}`)} />
        ))}
      </div>
      {rest}
    </div>
  );
}

// DCArtboard — marker; rendered by DCArtboardFrame via DCSection.
function DCArtboard() { return null; }

// Per-artboard export (kind: 'png' | 'html'). Both paths share the same
// self-contained clone: computed styles baked in, @font-face / <img> /
// inline-style background-image urls inlined as data URIs. PNG wraps the
// clone in foreignObject→canvas at 3× the artboard's natural width×height
// (same pipeline the host uses for page captures); HTML wraps it in a
// minimal standalone document. Both are independent of viewport zoom.
async function dcExport(node, w, h, name, kind) {
  try { await document.fonts.ready; } catch {}
  const toDataURL = (url) => fetch(url).then((r) => r.blob()).then((b) => new Promise((res) => {
    const fr = new FileReader(); fr.onload = () => res(fr.result); fr.onerror = () => res(url); fr.readAsDataURL(b);
  })).catch(() => url);

  // Collect @font-face rules. ss.cssRules throws SecurityError on
  // cross-origin sheets (e.g. fonts.googleapis.com) — in that case fetch
  // the CSS text directly (those endpoints send ACAO:*) and regex-extract
  // the blocks. @import and @media/@supports are walked so nested
  // @font-face rules aren't missed.
  const fontRules = [], pending = [], seen = new Set();
  const scrapeCss = (href) => {
    if (seen.has(href)) return; seen.add(href);
    pending.push(fetch(href).then((r) => r.text()).then((css) => {
      for (const m of css.match(/@font-face\s*{[^}]*}/g) || []) fontRules.push({ css: m, base: href });
      for (const m of css.matchAll(/@import\s+(?:url\()?['"]?([^'")\s;]+)/g))
        scrapeCss(new URL(m[1], href).href);
    }).catch(() => {}));
  };
  const walk = (rules, base) => {
    for (const r of rules) {
      if (r.type === CSSRule.FONT_FACE_RULE) fontRules.push({ css: r.cssText, base });
      else if (r.type === CSSRule.IMPORT_RULE && r.styleSheet) {
        const ibase = r.styleSheet.href || base;
        try { walk(r.styleSheet.cssRules, ibase); } catch { scrapeCss(ibase); }
      } else if (r.cssRules) walk(r.cssRules, base);
    }
  };
  for (const ss of document.styleSheets) {
    const base = ss.href || location.href;
    try { walk(ss.cssRules, base); } catch { if (ss.href) scrapeCss(ss.href); }
  }
  while (pending.length) await pending.shift();
  const fontCss = (await Promise.all(fontRules.map(async (rule) => {
    let out = rule.css, m; const re = /url\((['"]?)([^'")]+)\1\)/g;
    while ((m = re.exec(rule.css))) {
      if (m[2].indexOf('data:') === 0) continue;
      let abs; try { abs = new URL(m[2], rule.base).href; } catch { continue; }
      out = out.split(m[0]).join('url("' + await toDataURL(abs) + '")');
    }
    return out;
  }))).join('\n');

  const cloneStyled = (src) => {
    if (src.nodeType === 8 || (src.nodeType === 1 && src.tagName === 'SCRIPT')) return document.createTextNode('');
    const dst = src.cloneNode(false);
    if (src.nodeType === 1) {
      const cs = getComputedStyle(src); let txt = '';
      for (let i = 0; i < cs.length; i++) txt += cs[i] + ':' + cs.getPropertyValue(cs[i]) + ';';
      dst.setAttribute('style', txt + 'animation:none;transition:none;');
      if (src.tagName === 'CANVAS') try { const im = document.createElement('img'); im.src = src.toDataURL(); im.setAttribute('style', txt); return im; } catch {}
    }
    for (let c = src.firstChild; c; c = c.nextSibling) dst.appendChild(cloneStyled(c));
    return dst;
  };
  const clone = cloneStyled(node);
  clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
  // Drop the card's own shadow/radius so the export is a flush w×h rect;
  // the artboard's own background (if any) is already in the computed style.
  clone.style.boxShadow = 'none'; clone.style.borderRadius = '0';

  const jobs = [];
  clone.querySelectorAll('img').forEach((el) => {
    const s = el.getAttribute('src');
    if (s && s.indexOf('data:') !== 0) jobs.push(toDataURL(el.src).then((d) => el.setAttribute('src', d)));
  });
  [clone, ...clone.querySelectorAll('*')].forEach((el) => {
    const bg = el.style.backgroundImage; if (!bg) return;
    let m; const re = /url\(["']?([^"')]+)["']?\)/g;
    while ((m = re.exec(bg))) {
      const tok = m[0], url = m[1];
      if (url.indexOf('data:') === 0) continue;
      jobs.push(toDataURL(url).then((d) => { el.style.backgroundImage = el.style.backgroundImage.split(tok).join('url("' + d + '")'); }));
    }
  });
  await Promise.all(jobs);

  const xml = new XMLSerializer().serializeToString(clone);
  const save = (blob, ext) => {
    if (!blob) return;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name + '.' + ext; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };

  if (kind === 'html') {
    const html = '<!doctype html><html><head><meta charset="utf-8"><title>' + name + '</title>' +
      (fontCss ? '<style>' + fontCss + '</style>' : '') +
      '</head><body style="margin:0">' + xml + '</body></html>';
    return save(new Blob([html], { type: 'text/html' }), 'html');
  }

  // PNG: the SVG's own width/height must be the output resolution — an
  // <img>-loaded SVG rasterizes at its intrinsic size, so sizing it at 1×
  // and ctx.scale()-ing up would just upscale a 1× bitmap. viewBox maps the
  // w×h foreignObject onto the px·w × px·h SVG canvas so the browser renders
  // the HTML at full resolution.
  const px = 3;
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w * px + '" height="' + h * px +
    '" viewBox="0 0 ' + w + ' ' + h + '"><foreignObject width="' + w + '" height="' + h + '">' +
    (fontCss ? '<style><![CDATA[' + fontCss + ']]></style>' : '') + xml + '</foreignObject></svg>';
  const img = new Image();
  await new Promise((res, rej) => {
    img.onload = res; img.onerror = () => rej(new Error('svg load failed'));
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  });
  const cv = document.createElement('canvas');
  cv.width = w * px; cv.height = h * px;
  cv.getContext('2d').drawImage(img, 0, 0);
  cv.toBlob((blob) => save(blob, 'png'), 'image/png');
}

function DCArtboardFrame({ sectionId, artboard, label, order, onRename, onReorder, onFocus, onDelete }) {
  const { id: rawId, label: rawLabel, width = 260, height = 480, children, style = {} } = artboard.props;
  const id = rawId ?? rawLabel;
  const ref = React.useRef(null);
  const cardRef = React.useRef(null);
  const menuRef = React.useRef(null);
  const [menuOpen, setMenuOpen] = React.useState(false);
  const [confirming, setConfirming] = React.useState(false);

  // ⋯ menu: close on any outside pointerdown. Two-click delete lives inside
  // the menu — first click arms the row, second commits; closing disarms.
  React.useEffect(() => {
    if (!menuOpen) { setConfirming(false); return; }
    const off = (e) => { if (!menuRef.current || !menuRef.current.contains(e.target)) setMenuOpen(false); };
    document.addEventListener('pointerdown', off, true);
    return () => document.removeEventListener('pointerdown', off, true);
  }, [menuOpen]);

  const doExport = (kind) => {
    setMenuOpen(false);
    if (!cardRef.current) return;
    const name = String(label || id || 'artboard').replace(/[^\w\s.-]+/g, '_');
    dcExport(cardRef.current, width, height, name, kind)
      .catch((e) => console.error('[design-canvas] export failed:', e));
  };

  // Live drag-reorder: dragged card sticks to cursor; siblings slide into
  // their would-be slots in real time via transforms. DOM order only
  // changes on drop.
  const onGripDown = (e) => {
    e.preventDefault(); e.stopPropagation();
    const me = ref.current;
    // translateX is applied in local (pre-scale) space but pointer deltas and
    // getBoundingClientRect().left are screen-space — divide by the viewport's
    // current scale so the dragged card tracks the cursor at any zoom level.
    const scale = me.getBoundingClientRect().width / me.offsetWidth || 1;
    const peers = Array.from(document.querySelectorAll(`[data-dc-section="${sectionId}"] [data-dc-slot]`));
    const homes = peers.map((el) => ({ el, id: el.dataset.dcSlot, x: el.getBoundingClientRect().left }));
    const slotXs = homes.map((h) => h.x);
    const startIdx = order.indexOf(id);
    const startX = e.clientX;
    let liveOrder = order.slice();
    me.classList.add('dc-dragging');

    const layout = () => {
      for (const h of homes) {
        if (h.id === id) continue;
        const slot = liveOrder.indexOf(h.id);
        h.el.style.transform = `translateX(${(slotXs[slot] - h.x) / scale}px)`;
      }
    };

    const move = (ev) => {
      const dx = ev.clientX - startX;
      me.style.transform = `translateX(${dx / scale}px)`;
      const cur = homes[startIdx].x + dx;
      let nearest = 0, best = Infinity;
      for (let i = 0; i < slotXs.length; i++) {
        const d = Math.abs(slotXs[i] - cur);
        if (d < best) { best = d; nearest = i; }
      }
      if (liveOrder.indexOf(id) !== nearest) {
        liveOrder = order.filter((k) => k !== id);
        liveOrder.splice(nearest, 0, id);
        layout();
      }
    };

    const up = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      const finalSlot = liveOrder.indexOf(id);
      me.classList.remove('dc-dragging');
      me.style.transform = `translateX(${(slotXs[finalSlot] - homes[startIdx].x) / scale}px)`;
      // After the settle transition, kill transitions + clear transforms +
      // commit the reorder in the same frame so there's no visual snap-back.
      setTimeout(() => {
        for (const h of homes) { h.el.style.transition = 'none'; h.el.style.transform = ''; }
        if (liveOrder.join('|') !== order.join('|')) onReorder(liveOrder);
        requestAnimationFrame(() => requestAnimationFrame(() => {
          for (const h of homes) h.el.style.transition = '';
        }));
      }, 180);
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  };

  return (
    <div ref={ref} data-dc-slot={id} style={{ position: 'relative', flexShrink: 0 }}>
      <div className="dc-header" style={{ color: DC.label }} onPointerDown={(e) => e.stopPropagation()}>
        <div className="dc-labelrow">
          <div className="dc-grip" onPointerDown={onGripDown} title="Drag to reorder">
            <svg width="9" height="13" viewBox="0 0 9 13" fill="currentColor"><circle cx="2" cy="2" r="1.1"/><circle cx="7" cy="2" r="1.1"/><circle cx="2" cy="6.5" r="1.1"/><circle cx="7" cy="6.5" r="1.1"/><circle cx="2" cy="11" r="1.1"/><circle cx="7" cy="11" r="1.1"/></svg>
          </div>
          <div className="dc-labeltext" onClick={onFocus} title="Click to focus">
            <DCEditable value={label} onChange={onRename} onClick={(e) => e.stopPropagation()}
              style={{ fontSize: 15, fontWeight: 500, color: DC.label, lineHeight: 1 }} />
          </div>
        </div>
        <div className="dc-btns">
          <div ref={menuRef} style={{ position: 'relative' }}>
            <button className="dc-kebab" title="More" onClick={() => setMenuOpen((o) => !o)}>
              <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><circle cx="2.5" cy="6" r="1.1"/><circle cx="6" cy="6" r="1.1"/><circle cx="9.5" cy="6" r="1.1"/></svg>
            </button>
            {menuOpen && (
              <div className="dc-menu" onPointerDown={(e) => e.stopPropagation()}>
                <button onClick={() => doExport('png')}>Download PNG</button>
                <button onClick={() => doExport('html')}>Download HTML</button>
                <hr />
                <button className="dc-danger"
                  onClick={() => { if (confirming) { setMenuOpen(false); onDelete(); } else setConfirming(true); }}>
                  {confirming ? 'Click again to delete' : 'Delete'}
                </button>
              </div>
            )}
          </div>
          <button className="dc-expand" onClick={onFocus} title="Focus">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round"><path d="M7 1h4v4M5 11H1V7M11 1L7.5 4.5M1 11l3.5-3.5"/></svg>
          </button>
        </div>
      </div>
      <div ref={cardRef} className="dc-card"
        style={{ borderRadius: 2, boxShadow: '0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06)', overflow: 'hidden', width, height, background: '#fff', ...style }}>
        {children || <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#bbb', fontSize: 13, fontFamily: DC.font }}>{id}</div>}
      </div>
    </div>
  );
}

// Inline rename — commits on blur or Enter.
function DCEditable({ value, onChange, style, tag = 'span', onClick }) {
  const T = tag;
  return (
    <T className="dc-editable" contentEditable suppressContentEditableWarning
      onClick={onClick}
      onPointerDown={(e) => e.stopPropagation()}
      onBlur={(e) => onChange && onChange(e.currentTarget.textContent)}
      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
      style={style}>{value}</T>
  );
}

// ─────────────────────────────────────────────────────────────
// Focus mode — overlay one artboard; ←/→ within section, ↑/↓ across
// sections, Esc or backdrop click to exit.
// ─────────────────────────────────────────────────────────────
function DCFocusOverlay({ entry, sectionMeta, sectionOrder }) {
  const ctx = React.useContext(DCCtx);
  const { sectionId, artboard } = entry;
  const sec = ctx.section(sectionId);
  const meta = sectionMeta[sectionId];
  const peers = meta.slotIds;
  const aid = artboard.props.id ?? artboard.props.label;
  const idx = peers.indexOf(aid);
  const secIdx = sectionOrder.indexOf(sectionId);

  const go = (d) => { const n = peers[(idx + d + peers.length) % peers.length]; if (n) ctx.setFocus(`${sectionId}/${n}`); };
  const goSection = (d) => {
    // Sections whose artboards are all deleted have slotIds:[] — step past
    // them to the next non-empty section so ↑/↓ doesn't dead-end.
    const n = sectionOrder.length;
    for (let i = 1; i < n; i++) {
      const ns = sectionOrder[(((secIdx + d * i) % n) + n) % n];
      const first = sectionMeta[ns] && sectionMeta[ns].slotIds[0];
      if (first) { ctx.setFocus(`${ns}/${first}`); return; }
    }
  };

  React.useEffect(() => {
    const k = (e) => {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
      if (e.key === 'ArrowUp') { e.preventDefault(); goSection(-1); }
      if (e.key === 'ArrowDown') { e.preventDefault(); goSection(1); }
    };
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  });

  const { width = 260, height = 480, children } = artboard.props;
  const [vp, setVp] = React.useState({ w: window.innerWidth, h: window.innerHeight });
  React.useEffect(() => { const r = () => setVp({ w: window.innerWidth, h: window.innerHeight }); window.addEventListener('resize', r); return () => window.removeEventListener('resize', r); }, []);
  const scale = Math.max(0.1, Math.min((vp.w - 200) / width, (vp.h - 260) / height, 2));

  const [ddOpen, setDd] = React.useState(false);
  const Arrow = ({ dir, onClick }) => (
    <button onClick={(e) => { e.stopPropagation(); onClick(); }}
      style={{ position: 'absolute', top: '50%', [dir]: 28, transform: 'translateY(-50%)',
        border: 'none', background: 'rgba(255,255,255,.08)', color: 'rgba(255,255,255,.9)',
        width: 44, height: 44, borderRadius: 22, fontSize: 18, cursor: 'pointer',
        display: 'flex', alignItems: 'center', justifyContent: 'center', transition: 'background .15s' }}
      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(255,255,255,.18)')}
      onMouseLeave={(e) => (e.currentTarget.style.background = 'rgba(255,255,255,.08)')}>
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d={dir === 'left' ? 'M11 3L5 9l6 6' : 'M7 3l6 6-6 6'} /></svg>
    </button>
  );

  // Portal to body so position:fixed is the real viewport regardless of any
  // transform on DesignCanvas's ancestors (including the canvas zoom itself).
  return ReactDOM.createPortal(
    <div onClick={() => ctx.setFocus(null)}
      onWheel={(e) => e.preventDefault()}
      style={{ position: 'fixed', inset: 0, zIndex: 100, background: 'rgba(24,20,16,.6)', backdropFilter: 'blur(14px)',
        fontFamily: DC.font, color: '#fff' }}>

      {/* top bar: section dropdown (left) · close (right) */}
      <div onClick={(e) => e.stopPropagation()}
        style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 72, display: 'flex', alignItems: 'flex-start', padding: '16px 20px 0', gap: 16 }}>
        <div style={{ position: 'relative' }}>
          <button onClick={() => setDd((o) => !o)}
            style={{ border: 'none', background: 'transparent', color: '#fff', cursor: 'pointer', padding: '6px 8px',
              borderRadius: 6, textAlign: 'left', fontFamily: 'inherit' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 18, fontWeight: 600, letterSpacing: -0.3 }}>{meta.title}</span>
              <svg width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" style={{ opacity: .7 }}><path d="M2 4l3.5 3.5L9 4"/></svg>
            </span>
            {meta.subtitle && <span style={{ display: 'block', fontSize: 13, opacity: .6, fontWeight: 400, marginTop: 2 }}>{meta.subtitle}</span>}
          </button>
          {ddOpen && (
            <div style={{ position: 'absolute', top: '100%', left: 0, marginTop: 4, background: '#2a251f', borderRadius: 8,
              boxShadow: '0 8px 32px rgba(0,0,0,.4)', padding: 4, minWidth: 200, zIndex: 10 }}>
              {sectionOrder.filter((sid) => sectionMeta[sid].slotIds.length).map((sid) => (
                <button key={sid} onClick={() => { setDd(false); const f = sectionMeta[sid].slotIds[0]; if (f) ctx.setFocus(`${sid}/${f}`); }}
                  style={{ display: 'block', width: '100%', textAlign: 'left', border: 'none', cursor: 'pointer',
                    background: sid === sectionId ? 'rgba(255,255,255,.1)' : 'transparent', color: '#fff',
                    padding: '8px 12px', borderRadius: 5, fontSize: 14, fontWeight: sid === sectionId ? 600 : 400, fontFamily: 'inherit' }}>
                  {sectionMeta[sid].title}
                </button>
              ))}
            </div>
          )}
        </div>
        <div style={{ flex: 1 }} />
        <button onClick={() => ctx.setFocus(null)}
          onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(255,255,255,.12)')}
          onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
          style={{ border: 'none', background: 'transparent', color: 'rgba(255,255,255,.7)', width: 32, height: 32,
            borderRadius: 16, fontSize: 20, cursor: 'pointer', lineHeight: 1, transition: 'background .12s' }}>×</button>
      </div>

      {/* card centered, label + index below — only the card itself stops
          propagation so any backdrop click (including the margins around
          the card) exits focus */}
      <div
        style={{ position: 'absolute', top: 64, bottom: 56, left: 100, right: 100, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 16 }}>
        <div onClick={(e) => e.stopPropagation()} style={{ width: width * scale, height: height * scale, position: 'relative' }}>
          <div style={{ width, height, transform: `scale(${scale})`, transformOrigin: 'top left', background: '#fff', borderRadius: 2, overflow: 'hidden',
            boxShadow: '0 20px 80px rgba(0,0,0,.4)' }}>
            {children || <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#bbb' }}>{aid}</div>}
          </div>
        </div>
        <div onClick={(e) => e.stopPropagation()} style={{ fontSize: 14, fontWeight: 500, opacity: .85, textAlign: 'center' }}>
          {(sec.labels || {})[aid] ?? artboard.props.label}
          <span style={{ opacity: .5, marginLeft: 10, fontVariantNumeric: 'tabular-nums' }}>{idx + 1} / {peers.length}</span>
        </div>
      </div>

      <Arrow dir="left" onClick={() => go(-1)} />
      <Arrow dir="right" onClick={() => go(1)} />

      {/* dots */}
      <div onClick={(e) => e.stopPropagation()}
        style={{ position: 'absolute', bottom: 20, left: '50%', transform: 'translateX(-50%)', display: 'flex', gap: 8 }}>
        {peers.map((p, i) => (
          <button key={p} onClick={() => ctx.setFocus(`${sectionId}/${p}`)}
            style={{ border: 'none', padding: 0, cursor: 'pointer', width: 6, height: 6, borderRadius: 3,
              background: i === idx ? '#fff' : 'rgba(255,255,255,.3)' }} />
        ))}
      </div>
    </div>,
    document.body,
  );
}

// ─────────────────────────────────────────────────────────────
// Post-it — absolute-positioned sticky note
// ─────────────────────────────────────────────────────────────
function DCPostIt({ children, top, left, right, bottom, rotate = -2, width = 180 }) {
  return (
    <div style={{
      position: 'absolute', top, left, right, bottom, width,
      background: DC.postitBg, padding: '14px 16px',
      fontFamily: '"Comic Sans MS", "Marker Felt", "Segoe Print", cursive',
      fontSize: 14, lineHeight: 1.4, color: DC.postitText,
      boxShadow: '0 2px 8px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08)',
      transform: `rotate(${rotate}deg)`,
      zIndex: 5,
    }}>{children}</div>
  );
}

Object.assign(window, { DesignCanvas, DCSection, DCArtboard, DCPostIt });



// ===== tournee-data.jsx =====
// tournee-data.jsx — sample data for the admin tournée list
// Shop palette mirrors --shop-* CSS vars in admin.css

const SHOPS = {
  chatelain: { id: 'chatelain', name: 'Maison Châtelain',  short: 'MC',  city: 'Bruxelles', color: 'var(--shop-chatelain)' },
  sablon:    { id: 'sablon',    name: 'Atelier Sablon',     short: 'AS',  city: 'Bruxelles', color: 'var(--shop-sablon)' },
  carre:     { id: 'carre',     name: 'Le Carré',           short: 'LC',  city: 'Liège',     color: 'var(--shop-carre)' },
  zuid:      { id: 'zuid',      name: 'Zuid Bakery',        short: 'ZB',  city: 'Antwerpen', color: 'var(--shop-zuid)' },
  grognon:   { id: 'grognon',   name: 'Le Grognon',         short: 'LG',  city: 'Namur',     color: 'var(--shop-grognon)' },
  brugge:    { id: 'brugge',    name: 'Brugge Studio',      short: 'BS',  city: 'Brugge',    color: 'var(--shop-brugge)' },
};

const TOURNEES = [
  { id: 'TR-2814', name: 'Quartier Européen — Matin',     window: '07:30 → 11:00', date: 'Mer. 6 mai',
    driver: 'Émile Vandekeere',  driverInitials: 'EV', stops: 12, done: 12, kg: 38, status: 'done',     shop: 'chatelain' },
  { id: 'TR-2815', name: 'Sablon → Louise — Express',     window: '08:00 → 10:30', date: 'Mer. 6 mai',
    driver: 'Lina Boussaïd',     driverInitials: 'LB', stops: 9,  done: 7,  kg: 22, status: 'rolling',  shop: 'sablon' },
  { id: 'TR-2816', name: 'Centre-ville — Bureaux',        window: '09:00 → 12:00', date: 'Mer. 6 mai',
    driver: 'Pierre Lemmens',    driverInitials: 'PL', stops: 14, done: 5,  kg: 41, status: 'rolling',  shop: 'carre' },
  { id: 'TR-2817', name: 'Zuid Office — Tour A & B',       window: '10:00 → 13:00', date: 'Mer. 6 mai',
    driver: 'Jonas De Vos',      driverInitials: 'JD', stops: 8,  done: 0,  kg: 17, status: 'confirmed',shop: 'zuid' },
  { id: 'TR-2818', name: 'Citadelle — Mid-day',           window: '11:00 → 14:00', date: 'Mer. 6 mai',
    driver: 'Camille Henrard',   driverInitials: 'CH', stops: 6,  done: 0,  kg: 14, status: 'confirmed',shop: 'grognon' },
  { id: 'TR-2819', name: 'Bailli + Châtelain — Office',   window: '11:30 → 14:30', date: 'Mer. 6 mai',
    driver: 'Inès Marchal',      driverInitials: 'IM', stops: 11, done: 0,  kg: 28, status: 'planned',  shop: 'chatelain' },
  { id: 'TR-2820', name: 'Markt → Stations — Brugge',     window: '13:00 → 16:00', date: 'Mer. 6 mai',
    driver: 'Driver — à assigner', driverInitials: '?', stops: 7,  done: 0,  kg: 19, status: 'late',     shop: 'brugge' },
  { id: 'TR-2821', name: 'Grand-Place — Apéro Run',       window: '15:30 → 18:30', date: 'Mer. 6 mai',
    driver: 'Mathéo Ruelle',     driverInitials: 'MR', stops: 5,  done: 0,  kg: 11, status: 'planned',  shop: 'sablon' },
];

const SPLIT = [
  { shop: 'chatelain', tournees: 2, stops: 23 },
  { shop: 'sablon',    tournees: 2, stops: 14 },
  { shop: 'carre',     tournees: 1, stops: 14 },
  { shop: 'zuid',      tournees: 1, stops: 8 },
  { shop: 'grognon',   tournees: 1, stops: 6 },
  { shop: 'brugge',    tournees: 1, stops: 7 },
];

Object.assign(window, { SHOPS, TOURNEES, SPLIT });


// ===== tournee-shell.jsx =====
// tournee-shell.jsx — repeating admin chrome (sidebar + topbar + page head + toolbar)

const Icon = ({ d, size = 14, className = '' }) => (
  <svg viewBox="0 0 24 24" width={size} height={size} className={className}>
    {d}
  </svg>
);

const I = {
  chev:    <path d="M9 6l6 6-6 6" />,
  search:  <><circle cx="11" cy="11" r="6"/><path d="M16 16l4 4"/></>,
  cal:     <><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></>,
  filter:  <path d="M4 6h16M7 12h10M10 18h4" />,
  list:    <path d="M4 7h16M4 12h16M4 17h16" />,
  grid:    <><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></>,
  map:     <><path d="M9 4l-5 2v14l5-2 6 2 5-2V4l-5 2-6-2z"/><path d="M9 4v14M15 6v14"/></>,
  plus:    <><path d="M12 5v14M5 12h14"/></>,
  pin:     <><path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></>,
  truck:   <><path d="M3 16V7h11v9M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></>,
  more:    <><circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/></>,
  bell:    <><path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 004 0"/></>,
  home:    <><path d="M4 21V10l8-6 8 6v11"/><path d="M9 21v-7h6v7"/></>,
  shop:    <><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></>,
  user:    <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></>,
  product: <><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></>,
  bag:     <><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 016 0"/></>,
  campaign:<><path d="M4 12V8a2 2 0 012-2h6l8-3v18l-8-3H6a2 2 0 01-2-2v-2"/><path d="M9 14v5"/></>,
  cog:     <><circle cx="12" cy="12" r="3"/><path d="M19 12h2M3 12h2M12 3v2M12 19v2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></>,
  clipboard: <><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 11h6M9 15h4"/></>,
};

function Sidebar() {
  return (
    <aside className="admin__side">
      <div className="admin__brand">
        <div className="admin__brand-mark">L</div>
        <div>
          <div className="admin__brand-name">L'Atelier By</div>
          <span className="admin__brand-sub">Console franchise</span>
        </div>
      </div>

      <div className="admin__nav-group">Pilotage</div>
      <a className="admin__nav-item"><Icon d={I.home}/> Tableau de bord</a>
      <a className="admin__nav-item"><Icon d={I.bag}/> Commandes <span className="admin__nav-count">142</span></a>
      <a className="admin__nav-item admin__nav-item--active"><Icon d={I.truck}/> Tournées <span className="admin__nav-count">8</span></a>
      <a className="admin__nav-item"><Icon d={I.campaign}/> Campagnes</a>

      <div className="admin__nav-group">Catalogue</div>
      <a className="admin__nav-item"><Icon d={I.product}/> Produits</a>
      <a className="admin__nav-item"><Icon d={I.clipboard}/> Bundles &amp; Menus</a>

      <div className="admin__nav-group">Réseau</div>
      <a className="admin__nav-item"><Icon d={I.shop}/> Boutiques</a>
      <a className="admin__nav-item"><Icon d={I.user}/> Clients Office</a>
      <a className="admin__nav-item"><Icon d={I.cog}/> Paramètres</a>
    </aside>
  );
}

function Topbar() {
  return (
    <div className="admin__topbar">
      <div className="admin__breadcrumb">
        <span>Pilotage</span>
        <Icon d={I.chev} size={11}/>
        <strong>Tournées</strong>
      </div>
      <div className="admin__topbar-spacer"/>
      <span className="admin__topbar-pill"><Icon d={I.bell} size={12}/> 2 incidents</span>
      <span className="admin__topbar-pill"><Icon d={I.shop} size={12}/> Toutes boutiques</span>
      <div className="admin__topbar-user">SR</div>
    </div>
  );
}

function PageHead() {
  return (
    <div className="page-head">
      <div>
        <h1 className="page-head__title">Tournées du jour</h1>
        <p className="page-head__sub">8 tournées planifiées · 6 boutiques franchisées · 72 arrêts</p>
      </div>
      <div className="page-head__actions">
        <button className="btn"><Icon d={I.map}/> Voir la carte</button>
        <button className="btn btn--primary"><Icon d={I.plus}/> Nouvelle tournée</button>
      </div>
    </div>
  );
}

function SplitStrip() {
  return (
    <div className="split-strip">
      {SPLIT.map((s) => {
        const shop = SHOPS[s.shop];
        return (
          <div key={s.shop} className="split-strip__cell" style={{ '--shop-color': shop.color }}>
            <div className="split-strip__shop">{shop.name}</div>
            <div className="split-strip__count">{s.tournees}</div>
            <div className="split-strip__sub">{s.stops} arrêts · {shop.city}</div>
          </div>
        );
      })}
    </div>
  );
}

function Toolbar({ shopFilter, setShopFilter }) {
  const shops = ['all', ...Object.keys(SHOPS)];
  return (
    <div className="toolbar">
      <span className="toolbar__date"><Icon d={I.cal}/> Mer. 6 mai 2026</span>
      <div className="toolbar__group">
        {shops.map((k) => {
          const s = k === 'all' ? null : SHOPS[k];
          const active = shopFilter === k;
          return (
            <button
              key={k}
              className={`toolbar__chip toolbar__chip--shop${active ? ' toolbar__chip--active' : ''}`}
              onClick={() => setShopFilter(k)}
            >
              <span className="toolbar__chip-dot" style={{ background: s ? s.color : 'var(--color-text-muted)' }}/>
              {k === 'all' ? 'Toutes' : s.name}
            </button>
          );
        })}
      </div>
      <div className="toolbar__search">
        <Icon d={I.search}/>
        <input placeholder="Chauffeur, ID tournée, adresse…"/>
      </div>
    </div>
  );
}

Object.assign(window, { Sidebar, Topbar, PageHead, SplitStrip, Toolbar, Icon, I });


// ===== tournee-card-a.jsx =====
// tournee-card-A.jsx — VARIANT A: Shop ribbon rail (left)

function StatusPill({ status }) {
  const map = {
    planned:   { lbl: 'Planifiée',  cls: 'tile__status--planned'   },
    confirmed: { lbl: 'Confirmée',  cls: 'tile__status--confirmed' },
    rolling:   { lbl: 'En tournée', cls: 'tile__status--rolling'   },
    done:      { lbl: 'Terminée',   cls: 'tile__status--done'      },
    late:      { lbl: 'En retard',  cls: 'tile__status--late'      },
  };
  const s = map[status];
  return (
    <span className={`tile__status ${s.cls}`}>
      {status === 'rolling'
        ? <span className="tile__rolling-pulse"/>
        : <span className="tile__status-dot"/>}
      {s.lbl}
    </span>
  );
}

function TileA({ t }) {
  const shop = SHOPS[t.shop];
  const pct = t.stops ? Math.round((t.done / t.stops) * 100) : 0;
  return (
    <div className="tile tile--A">
      <div className="tile__shop-rail" style={{ '--shop-color': shop.color }}>
        <div>
          <div className="tile__shop-city">{shop.city}</div>
          <div className="tile__shop-name">{shop.name}</div>
        </div>
        <div className="tile__shop-icon">
          <svg viewBox="0 0 24 24"><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></svg>
        </div>
      </div>

      <div className="tile__time">
        <div className="tile__time-window">{t.window.split(' → ')[0]}</div>
        <div className="tile__time-date">→ {t.window.split(' → ')[1]}</div>
      </div>

      <div className="tile__core">
        <div className="tile__core-id">{t.id}</div>
        <div className="tile__core-name">{t.name}</div>
        <div className="tile__progress">
          <div className="tile__progress-fill" style={{ width: `${pct}%`, background: shop.color }}/>
        </div>
      </div>

      <div className="tile__driver">
        <div className="tile__driver-avatar">{t.driverInitials}</div>
        <div className="tile__driver-info">
          <div className="tile__driver-label">Chauffeur</div>
          <div className="tile__driver-name">{t.driver}</div>
        </div>
      </div>

      <div className="tile__stops">
        <div>
          <div className="tile__stat-num">{t.done}/{t.stops}</div>
          <div className="tile__stat-lbl">Arrêts</div>
        </div>
        <div>
          <div className="tile__stat-num">{t.kg}<span style={{fontSize:'10px', marginLeft:2, color:'var(--color-text-muted)'}}>kg</span></div>
          <div className="tile__stat-lbl">Charge</div>
        </div>
      </div>

      <div className="tile__right">
        <StatusPill status={t.status}/>
        <button className="tile__menu" aria-label="Actions">
          <svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/></svg>
        </button>
      </div>
    </div>
  );
}

Object.assign(window, { TileA, StatusPill });


// ===== tournee-card-b.jsx =====
// tournee-card-B.jsx — VARIANT B: Inline shop chip (compact pill)

function TileB({ t }) {
  const shop = SHOPS[t.shop];
  const pct = t.stops ? Math.round((t.done / t.stops) * 100) : 0;
  return (
    <div className="tile tile--B">
      <div className="tile__time">
        <div className="tile__time-window">{t.window.split(' → ')[0]}</div>
        <div className="tile__time-date">→ {t.window.split(' → ')[1]}</div>
      </div>

      <div className="tile__core">
        <div className="tile__core-id">{t.id} · {t.date}</div>
        <div className="tile__core-name">{t.name}</div>
        <div className="tile__core-meta">
          <span className="shop-chip">
            <span className="shop-chip__avatar" style={{ background: shop.color }}>{shop.short}</span>
            {shop.name}
            <span className="shop-chip__city">· {shop.city}</span>
          </span>
        </div>
      </div>

      <div className="tile__driver">
        <div className="tile__driver-avatar">{t.driverInitials}</div>
        <div className="tile__driver-info">
          <div className="tile__driver-label">Chauffeur</div>
          <div className="tile__driver-name">{t.driver}</div>
        </div>
      </div>

      <div className="tile__stops">
        <div>
          <div className="tile__stat-num">{t.done}/{t.stops}</div>
          <div className="tile__stat-lbl">Arrêts</div>
        </div>
        <div>
          <div className="tile__stat-num">{t.kg}<span style={{fontSize:'10px', marginLeft:2, color:'var(--color-text-muted)'}}>kg</span></div>
          <div className="tile__stat-lbl">Charge</div>
        </div>
      </div>

      <div className="tile__right">
        <StatusPill status={t.status}/>
        <button className="tile__menu" aria-label="Actions">
          <svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/></svg>
        </button>
      </div>
    </div>
  );
}

window.TileB = TileB;


// ===== tournee-card-c.jsx =====
// tournee-card-C.jsx — VARIANT C: Stamped corner badge with colored top border

function TileC({ t }) {
  const shop = SHOPS[t.shop];
  const pct = t.stops ? Math.round((t.done / t.stops) * 100) : 0;
  return (
    <div className="tile tile--C" style={{ '--shop-color': shop.color }}>
      <div className="tile__stamp">
        <span className="tile__stamp-pin">
          <svg viewBox="0 0 24 24"><path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
        </span>
        {shop.name}
        <span className="tile__stamp-divider"/>
        <span className="tile__stamp-city">{shop.city}</span>
      </div>

      <div className="tile__time">
        <div className="tile__time-window">{t.window.split(' → ')[0]}</div>
        <div className="tile__time-date">→ {t.window.split(' → ')[1]}</div>
      </div>

      <div className="tile__core">
        <div className="tile__core-id">{t.id}</div>
        <div className="tile__core-name">{t.name}</div>
        <div className="tile__progress">
          <div className="tile__progress-fill" style={{ width: `${pct}%`, background: shop.color }}/>
        </div>
      </div>

      <div className="tile__driver">
        <div className="tile__driver-avatar">{t.driverInitials}</div>
        <div className="tile__driver-info">
          <div className="tile__driver-label">Chauffeur</div>
          <div className="tile__driver-name">{t.driver}</div>
        </div>
      </div>

      <div className="tile__stops">
        <div>
          <div className="tile__stat-num">{t.done}/{t.stops}</div>
          <div className="tile__stat-lbl">Arrêts</div>
        </div>
        <div>
          <div className="tile__stat-num">{t.kg}<span style={{fontSize:'10px', marginLeft:2, color:'var(--color-text-muted)'}}>kg</span></div>
          <div className="tile__stat-lbl">Charge</div>
        </div>
      </div>

      <div className="tile__right">
        <StatusPill status={t.status}/>
        <button className="tile__menu" aria-label="Actions">
          <svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/></svg>
        </button>
      </div>
    </div>
  );
}

window.TileC = TileC;


// ===== app.jsx =====
// app.jsx — assemble three artboards on a design canvas, one per badge variant.

// (hooks already destructured at bundle top)
function ArtboardNote({ children }) {
  return (
    <div className="artboard-note">
      <div className="artboard-note__icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.01"/></svg>
      </div>
      <div>{children}</div>
    </div>
  );
}

function AdminWindow({ children }) {
  return (
    <div className="admin">
      <Sidebar/>
      <div className="admin__main">
        <Topbar/>
        {children}
      </div>
    </div>
  );
}

function VariantAFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option A — Bandeau boutique (rail vertical)</strong>
        Chaque tournée s'ouvre sur une bande couleur tenue par la boutique : nom en display Vank, ville en petite capitale. Identité forte, lisible de loin, maintien de la hiérarchie L'Atelier (Ruby + Abricot) sur le reste de la tuile. Idéal en réseau multi-franchisés.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileA key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function VariantBFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option B — Pastille boutique en ligne</strong>
        Le badge boutique vit dans la zone d'identification de la tournée, sous le nom : avatar coloré + nom + ville. Discret, dense, parfait pour des listes longues. La couleur reste portée par la pastille seule, le reste de la tuile garde le ton brand neutre.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileB key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function VariantCFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option C — Tampon livraison</strong>
        Chaque tuile reçoit un liseré fin sur sa tranche haute + un « tampon » d'expédition en haut à droite (épingle + boutique + ville). Métaphore évidente — chaque tournée a un point de départ. Marquage fort sans envahir la grille horizontale.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileC key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function App() {
  return (
    <DesignCanvas
      title="Tournées Admin · badge boutique"
      subtitle="[2.7] Delivery Rounds — Shop Badge on Tournée Tile"
    >
      <DCSection id="variants" title="Trois pistes pour rendre la boutique d'attache toujours visible sur la tuile.">
        <DCArtboard id="A" label="A — Bandeau rail" width={1480} height={1020}>
          <VariantAFrame/>
        </DCArtboard>
        <DCArtboard id="B" label="B — Pastille en ligne" width={1480} height={1020}>
          <VariantBFrame/>
        </DCArtboard>
        <DCArtboard id="C" label="C — Tampon livraison" width={1480} height={1020}>
          <VariantCFrame/>
        </DCArtboard>
      </DCSection>
    </DesignCanvas>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);


// ===== qr.jsx =====
// qr.jsx — minimal QR Code encoder (Version 1–10, byte mode, ECC level M)
// Pure JS, no deps. Produces a boolean[size][size] matrix. Designed for short
// URLs (the link generator emits ~80–150 char URLs, well under V10/M cap).
//
// Reference: ISO/IEC 18004 — implementation derived from public-domain
// reference logic; rewritten compact for our needs.

(function () {
  // GF(256) tables for Reed-Solomon
  const EXP = new Uint8Array(512), LOG = new Uint8Array(256);
  for (let i = 0, x = 1; i < 255; i++) {
    EXP[i] = x;
    LOG[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
  for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];

  const gfMul = (a, b) => (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];

  // RS generator polynomial of degree n
  function rsGenerator(n) {
    let poly = [1];
    for (let i = 0; i < n; i++) {
      const next = new Array(poly.length + 1).fill(0);
      for (let j = 0; j < poly.length; j++) {
        next[j] ^= gfMul(poly[j], 1);
        next[j + 1] ^= gfMul(poly[j], EXP[i]);
      }
      poly = next;
    }
    return poly;
  }

  function rsEncode(data, eccLen) {
    const gen = rsGenerator(eccLen);
    const buf = data.concat(new Array(eccLen).fill(0));
    for (let i = 0; i < data.length; i++) {
      const factor = buf[i];
      if (factor === 0) continue;
      for (let j = 0; j < gen.length; j++) {
        buf[i + j] ^= gfMul(gen[j], factor);
      }
    }
    return buf.slice(data.length);
  }

  // Capacity table — bytes for byte-mode, ECC level M, version 1..10
  // (data codewords - 2 mode/length overhead -> usable byte payload)
  // values from ISO 18004 table; precomputed.
  const CAP_M = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213];
  const TOTAL_M = [16, 28, 44, 64, 86, 108, 124, 154, 182, 216]; // data codewords
  const ECC_M  = [10, 16, 26, 36, 48, 64, 72, 88, 110, 130];     // ECC codewords per block
  const BLOCKS_M = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];               // groups
  // For v3..v10 with multi-block we use simpler layouts (group 1 only) when blocks=1,
  // and uniform split when blocks>1. Sufficient for our payload sizes.

  const ALIGN_POS = {
    1:[],2:[6,18],3:[6,22],4:[6,26],5:[6,30],
    6:[6,34],7:[6,22,38],8:[6,24,42],9:[6,26,46],10:[6,28,50]
  };

  function pickVersion(byteLen) {
    for (let v = 1; v <= 10; v++) if (CAP_M[v - 1] >= byteLen) return v;
    throw new Error('QR: payload too long (>213 bytes)');
  }

  // Bit stream writer
  function BitStream() { this.bits = []; }
  BitStream.prototype.put = function (val, len) {
    for (let i = len - 1; i >= 0; i--) this.bits.push((val >>> i) & 1);
  };
  BitStream.prototype.toBytes = function () {
    while (this.bits.length % 8) this.bits.push(0);
    const out = new Array(this.bits.length / 8);
    for (let i = 0; i < out.length; i++) {
      let b = 0;
      for (let j = 0; j < 8; j++) b = (b << 1) | this.bits[i * 8 + j];
      out[i] = b;
    }
    return out;
  };

  function utf8Bytes(str) {
    return Array.from(new TextEncoder().encode(str));
  }

  // Build the data codeword stream
  function buildDataCodewords(text, version) {
    const bytes = utf8Bytes(text);
    const bs = new BitStream();
    bs.put(0b0100, 4);                     // mode = byte
    bs.put(bytes.length, version <= 9 ? 8 : 16); // char count indicator
    for (const b of bytes) bs.put(b, 8);
    bs.put(0, 4);                          // terminator (up to 4)
    let cw = bs.toBytes();
    const total = TOTAL_M[version - 1];
    // pad to total with alternating PAD bytes
    const pad = [0xEC, 0x11];
    let p = 0;
    while (cw.length < total) cw.push(pad[p++ % 2]);
    return cw;
  }

  // Interleave data + ECC across blocks
  function buildFinalCodewords(data, version) {
    const blocks = BLOCKS_M[version - 1];
    const eccLen = ECC_M[version - 1];
    const blockData = [];
    const blockEcc = [];
    const baseLen = Math.floor(data.length / blocks);
    const extra = data.length - baseLen * blocks;
    let idx = 0;
    for (let b = 0; b < blocks; b++) {
      const len = baseLen + (b >= blocks - extra ? 1 : 0);
      const d = data.slice(idx, idx + len);
      idx += len;
      blockData.push(d);
      blockEcc.push(rsEncode(d, eccLen));
    }
    const out = [];
    const maxData = Math.max(...blockData.map((d) => d.length));
    for (let i = 0; i < maxData; i++) for (const d of blockData) if (i < d.length) out.push(d[i]);
    for (let i = 0; i < eccLen; i++) for (const e of blockEcc) out.push(e[i]);
    return out;
  }

  // Module placement
  function makeMatrix(version) {
    const size = 17 + version * 4;
    const m = Array.from({ length: size }, () => new Array(size).fill(null));
    const fn = Array.from({ length: size }, () => new Array(size).fill(false));

    // Finder patterns + separators (3 corners)
    const placeFinder = (r, c) => {
      for (let dr = -1; dr <= 7; dr++) for (let dc = -1; dc <= 7; dc++) {
        const rr = r + dr, cc = c + dc;
        if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
        fn[rr][cc] = true;
        const inner = (dr >= 0 && dr <= 6 && dc >= 0 && dc <= 6);
        if (!inner) { m[rr][cc] = false; continue; }
        const ring = (dr === 0 || dr === 6 || dc === 0 || dc === 6);
        const center = (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
        m[rr][cc] = ring || center;
      }
    };
    placeFinder(0, 0); placeFinder(0, size - 7); placeFinder(size - 7, 0);

    // Timing patterns
    for (let i = 8; i < size - 8; i++) {
      m[6][i] = (i % 2 === 0); fn[6][i] = true;
      m[i][6] = (i % 2 === 0); fn[i][6] = true;
    }

    // Alignment patterns (skip if it overlaps a finder)
    const ap = ALIGN_POS[version];
    for (const r of ap) for (const c of ap) {
      if ((r < 8 && c < 8) || (r < 8 && c > size - 9) || (r > size - 9 && c < 8)) continue;
      for (let dr = -2; dr <= 2; dr++) for (let dc = -2; dc <= 2; dc++) {
        const rr = r + dr, cc = c + dc;
        fn[rr][cc] = true;
        const ring = (Math.abs(dr) === 2 || Math.abs(dc) === 2);
        const center = (dr === 0 && dc === 0);
        m[rr][cc] = ring || center;
      }
    }

    // Reserve format-info area (filled later)
    for (let i = 0; i < 9; i++) { fn[8][i] = true; fn[i][8] = true; }
    for (let i = 0; i < 8; i++) { fn[8][size - 1 - i] = true; fn[size - 1 - i][8] = true; }
    m[size - 8][8] = true; fn[size - 8][8] = true; // dark module

    return { m, fn, size };
  }

  // Place codewords zig-zag from bottom-right
  function placeCodewords(state, codewords) {
    const { m, fn, size } = state;
    const bits = [];
    for (const cw of codewords) for (let i = 7; i >= 0; i--) bits.push((cw >>> i) & 1);
    let bi = 0;
    let upward = true;
    for (let col = size - 1; col > 0; col -= 2) {
      if (col === 6) col = 5; // skip vertical timing
      for (let i = 0; i < size; i++) {
        const row = upward ? size - 1 - i : i;
        for (let dc = 0; dc < 2; dc++) {
          const c = col - dc;
          if (fn[row][c]) continue;
          m[row][c] = bi < bits.length ? bits[bi++] === 1 : false;
        }
      }
      upward = !upward;
    }
  }

  // Mask functions
  const MASKS = [
    (r, c) => ((r + c) % 2) === 0,
    (r) => (r % 2) === 0,
    (r, c) => (c % 3) === 0,
    (r, c) => ((r + c) % 3) === 0,
    (r, c) => ((Math.floor(r / 2) + Math.floor(c / 3)) % 2) === 0,
    (r, c) => ((r * c) % 2 + (r * c) % 3) === 0,
    (r, c) => (((r * c) % 2 + (r * c) % 3) % 2) === 0,
    (r, c) => (((r + c) % 2 + (r * c) % 3) % 2) === 0,
  ];

  function applyMask(state, maskIdx) {
    const fn_ = state.fn, m = state.m, size = state.size;
    const f = MASKS[maskIdx];
    for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) {
      if (fn_[r][c]) continue;
      if (f(r, c)) m[r][c] = !m[r][c];
    }
  }

  // Format info bits — ECC level M = 0b00; 5-bit format = (ecc<<3) | mask
  // BCH(15,5) generator 0x537, mask pattern 0x5412
  function formatBits(maskIdx) {
    const data = (0b00 << 3) | maskIdx;
    let bch = data << 10;
    for (let i = 14; i >= 10; i--) {
      if (bch & (1 << i)) bch ^= 0x537 << (i - 10);
    }
    return ((data << 10) | bch) ^ 0x5412;
  }

  function placeFormat(state, maskIdx) {
    const m = state.m, size = state.size;
    const bits = formatBits(maskIdx);
    for (let i = 0; i < 15; i++) {
      const b = ((bits >>> i) & 1) === 1;
      // around top-left finder
      if (i < 6) m[8][i] = b;
      else if (i < 8) m[8][i + 1] = b;
      else if (i < 9) m[7][8] = b;
      else m[14 - i][8] = b;
      // along right/bottom
      if (i < 8) m[size - 1 - i][8] = b;
      else m[8][size - 15 + i] = b;
    }
    m[size - 8][8] = true;
  }

  // Mask penalty — simplified (rule 1 only is enough for short URLs to pick a clean mask)
  function penalty(state) {
    const m = state.m, size = state.size;
    let p = 0;
    for (let r = 0; r < size; r++) {
      let run = 1;
      for (let c = 1; c < size; c++) {
        if (m[r][c] === m[r][c - 1]) { run++; if (run === 5) p += 3; else if (run > 5) p += 1; }
        else run = 1;
      }
    }
    for (let c = 0; c < size; c++) {
      let run = 1;
      for (let r = 1; r < size; r++) {
        if (m[r][c] === m[r - 1][c]) { run++; if (run === 5) p += 3; else if (run > 5) p += 1; }
        else run = 1;
      }
    }
    return p;
  }

  function encode(text) {
    const version = pickVersion(utf8Bytes(text).length);
    const data = buildDataCodewords(text, version);
    const final = buildFinalCodewords(data, version);

    let best = null;
    for (let mk = 0; mk < 8; mk++) {
      const state = makeMatrix(version);
      placeCodewords(state, final);
      applyMask(state, mk);
      placeFormat(state, mk);
      const p = penalty(state);
      if (!best || p < best.p) best = { p, state, mk };
    }
    return best.state.m; // boolean[size][size]
  }

  // Render to SVG — branded mode draws ruby modules + brand corners
  function render(text, opts = {}) {
    const { size = 220, margin = 4, color = '#8D1D2C', bg = '#ffffff', branded = true, brandLabel } = opts;
    const matrix = encode(text);
    const n = matrix.length;
    const total = n + margin * 2;
    const cell = size / total;

    let modules = '';
    for (let r = 0; r < n; r++) {
      for (let c = 0; c < n; c++) {
        if (!matrix[r][c]) continue;
        // Check if this module is part of a finder square — skip; we draw branded ones below.
        if (branded && isInFinder(r, c, n)) continue;
        const x = (c + margin) * cell;
        const y = (r + margin) * cell;
        if (branded) {
          // dotted modules — circle for organic feel
          modules += `<circle cx="${(x + cell / 2).toFixed(2)}" cy="${(y + cell / 2).toFixed(2)}" r="${(cell * 0.46).toFixed(2)}" fill="${color}"/>`;
        } else {
          modules += `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${cell.toFixed(2)}" height="${cell.toFixed(2)}" fill="${color}"/>`;
        }
      }
    }

    let corners = '';
    if (branded) {
      const drawCorner = (cr, cc) => {
        const x0 = (cc + margin) * cell;
        const y0 = (cr + margin) * cell;
        const w = cell * 7;
        const r = cell * 1.4;
        const innerInset = cell * 2;
        const innerW = cell * 3;
        const innerR = cell * 0.6;
        // rounded outer ring, hollow
        corners += `<rect x="${x0.toFixed(2)}" y="${y0.toFixed(2)}" width="${w.toFixed(2)}" height="${w.toFixed(2)}" rx="${r}" ry="${r}" fill="none" stroke="${color}" stroke-width="${cell.toFixed(2)}"/>`;
        // rounded inner square
        corners += `<rect x="${(x0 + innerInset).toFixed(2)}" y="${(y0 + innerInset).toFixed(2)}" width="${innerW.toFixed(2)}" height="${innerW.toFixed(2)}" rx="${innerR}" ry="${innerR}" fill="${color}"/>`;
      };
      drawCorner(0, 0);
      drawCorner(0, n - 7);
      drawCorner(n - 7, 0);
    }

    const labelEl = brandLabel
      ? `<g transform="translate(${size / 2}, ${size + 14})">
           <text text-anchor="middle" font-family="Vank, Gotham, sans-serif" font-size="13" fill="${color}">${escapeXml(brandLabel)}</text>
         </g>`
      : '';
    const totalH = brandLabel ? size + 26 : size;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${totalH}" width="${size}" height="${totalH}">` +
           `<rect width="${size}" height="${size}" fill="${bg}"/>` +
           modules + corners + labelEl +
           `</svg>`;
  }

  function isInFinder(r, c, n) {
    // top-left, top-right, bottom-left 7×7 finder squares
    if (r < 7 && c < 7) return true;
    if (r < 7 && c >= n - 7) return true;
    if (r >= n - 7 && c < 7) return true;
    return false;
  }

  function escapeXml(s) { return String(s).replace(/[<>&"]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[ch])); }

  // Render to PNG via canvas
  function renderPng(text, opts = {}) {
    const svg = render(text, opts);
    const blob = new Blob([svg], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        const scale = 4;
        const cv = document.createElement('canvas');
        cv.width = img.naturalWidth * scale;
        cv.height = img.naturalHeight * scale;
        const ctx = cv.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob((b) => { URL.revokeObjectURL(url); resolve(b); }, 'image/png');
      };
      img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
      img.src = url;
    });
  }

  window.QR = { encode, render, renderPng };
})();


// ===== vouchers.jsx =====
// vouchers.jsx — Voucher management
// Dense table (status, code, type, scope, usage bar, validity) + create/edit drawer
// Realistic L'Atelier-flavored campaigns

const VOUCHER_PRODUCTS = [
  { id: 'tarte-citron',      name: 'Tarte au citron meringuée' },
  { id: 'tarte-praline',     name: 'Tarte praliné noisette' },
  { id: 'salade-bressane',   name: 'Salade bressane' },
  { id: 'parfait-vanille',   name: 'Parfait vanille bourbon' },
  { id: 'plat-saumon',       name: 'Saumon, riz, légumes verts' },
  { id: 'plat-volaille',     name: 'Volaille fermière' },
  { id: 'cookie-chocolat',   name: 'Cookie double chocolat' },
  { id: 'pain-cereales',     name: 'Pain aux céréales' },
];

const VOUCHER_CATEGORIES = [
  { id: 'patisseries',  name: 'Pâtisseries' },
  { id: 'salades',      name: 'Salades' },
  { id: 'plats',        name: 'Plats du jour' },
  { id: 'douceurs',     name: 'Douceurs' },
  { id: 'boulangerie',  name: 'Boulangerie' },
];

const VOUCHER_COLLECTIONS = [
  { id: 'menu-midi',    name: 'Menu de midi' },
  { id: 'brunch',       name: 'Le Brunch' },
  { id: 'goute',        name: 'Le Goûter' },
  { id: 'apero',        name: 'Apéro Box' },
];

const VOUCHER_SHOPS = ['chatelain', 'sablon', 'carre', 'zuid', 'grognon', 'brugge'];

// Sample vouchers — realistic L'Atelier campaigns
const SEED_VOUCHERS = [
  {
    id: 'v1', code: 'RENTREE2026', type: 'percent', value: 15,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 25, usageLimit: 500, used: 187,
    validFrom: '2026-04-15', validTo: '2026-05-31',
    status: 'active',
  },
  {
    id: 'v2', code: 'CHATELAIN-VIP', type: 'percent', value: 20,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['chatelain'], channels: 'webshop',
    minOrder: 0, usageLimit: 100, used: 42,
    validFrom: '2026-05-01', validTo: '2026-06-30',
    status: 'active',
  },
  {
    id: 'v3', code: 'BRUNCH-SABLON', type: 'fixed', value: 5,
    scope: 'collections', products: [], categories: [], collections: ['brunch'],
    shops: ['sablon', 'chatelain'], channels: 'webshop',
    minOrder: 35, usageLimit: 200, used: 89,
    validFrom: '2026-04-01', validTo: '2026-12-31',
    status: 'active',
  },
  {
    id: 'v4', code: 'OFFICE-LAUNCH', type: 'percent', value: 10,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'office',
    minOrder: 100, usageLimit: 50, used: 12,
    validFrom: '2026-05-01', validTo: '2026-07-31',
    status: 'active',
  },
  {
    id: 'v5', code: 'TARTES-SAMEDI', type: 'percent', value: 25,
    scope: 'categories', products: [], categories: ['patisseries'], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 0, usageLimit: 1000, used: 736,
    validFrom: '2026-03-01', validTo: '2026-05-15',
    status: 'expiring',
  },
  {
    id: 'v6', code: 'GOUTER10', type: 'fixed', value: 3,
    scope: 'collections', products: [], categories: [], collections: ['goute'],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 12, usageLimit: 300, used: 298,
    validFrom: '2026-04-01', validTo: '2026-06-30',
    status: 'expiring',
  },
  {
    id: 'v7', code: 'BIENVENUE', type: 'fixed', value: 8,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 30, usageLimit: 0, used: 1240,
    validFrom: '2026-01-01', validTo: '2026-12-31',
    status: 'active',
  },
  {
    id: 'v8', code: 'APERO-VENDREDI', type: 'percent', value: 12,
    scope: 'collections', products: [], categories: [], collections: ['apero'],
    shops: ['chatelain', 'sablon', 'carre'], channels: 'webshop',
    minOrder: 25, usageLimit: 400, used: 156,
    validFrom: '2026-05-01', validTo: '2026-09-30',
    status: 'active',
  },
  {
    id: 'v9', code: 'NOEL2025', type: 'percent', value: 30,
    scope: 'cart', products: [], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'both',
    minOrder: 50, usageLimit: 200, used: 200,
    validFrom: '2025-12-01', validTo: '2025-12-31',
    status: 'expired',
  },
  {
    id: 'v10', code: 'BRUGGE-OPEN', type: 'fixed', value: 10,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['brugge'], channels: 'webshop',
    minOrder: 25, usageLimit: 150, used: 0,
    validFrom: '2026-06-01', validTo: '2026-07-31',
    status: 'scheduled',
  },
  {
    id: 'v11', code: 'PARFAIT-DUO', type: 'percent', value: 20,
    scope: 'products', products: ['parfait-vanille'], categories: [], collections: [],
    shops: VOUCHER_SHOPS, channels: 'webshop',
    minOrder: 0, usageLimit: 100, used: 23,
    validFrom: '2026-05-01', validTo: '2026-06-30',
    status: 'active',
  },
  {
    id: 'v12', code: 'TEAM-NAMUR', type: 'percent', value: 15,
    scope: 'shops', products: [], categories: [], collections: [],
    shops: ['grognon'], channels: 'office',
    minOrder: 75, usageLimit: 80, used: 8,
    validFrom: '2026-05-01', validTo: '2026-08-31',
    status: 'active',
  },
];

// Persist vouchers to localStorage so admin <-> storefront stay in sync (and survive reload)
const VOUCHERS_KEY = 'latelier-admin:vouchers';

function loadVouchers() {
  try {
    const raw = localStorage.getItem(VOUCHERS_KEY);
    if (!raw) return SEED_VOUCHERS;
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed) && parsed.length) return parsed;
  } catch {}
  return SEED_VOUCHERS;
}

function saveVouchers(list) {
  try { localStorage.setItem(VOUCHERS_KEY, JSON.stringify(list)); } catch {}
}

// Validity status calc — reads validity dates and usageLimit/used.
function deriveStatus(v, now = new Date()) {
  const from = new Date(v.validFrom);
  const to = new Date(v.validTo);
  if (v.usageLimit > 0 && v.used >= v.usageLimit) return 'exhausted';
  if (now < from) return 'scheduled';
  if (now > to) return 'expired';
  // Expiring soon: < 14 days remaining
  const days = Math.ceil((to - now) / (1000 * 60 * 60 * 24));
  if (days <= 14) return 'expiring';
  return 'active';
}

const STATUS_LABELS = {
  active:    { lbl: 'Actif',     cls: 'vstatus--active' },
  scheduled: { lbl: 'Planifié',  cls: 'vstatus--scheduled' },
  expiring:  { lbl: 'Expire',    cls: 'vstatus--expiring' },
  expired:   { lbl: 'Expiré',    cls: 'vstatus--expired' },
  exhausted: { lbl: 'Épuisé',    cls: 'vstatus--exhausted' },
};

const CHANNEL_LABELS = {
  webshop: 'Webshop',
  office: 'Office Shop',
  both: 'Webshop + Office',
};

function formatScope(v) {
  if (v.scope === 'cart')        return 'Panier entier';
  if (v.scope === 'products')    return `${v.products.length} produit${v.products.length > 1 ? 's' : ''}`;
  if (v.scope === 'categories')  return `${v.categories.length} catégorie${v.categories.length > 1 ? 's' : ''}`;
  if (v.scope === 'collections') return `${v.collections.length} collection${v.collections.length > 1 ? 's' : ''}`;
  if (v.scope === 'shops')       return `${v.shops.length} boutique${v.shops.length > 1 ? 's' : ''}`;
  return '—';
}

function formatValue(v) {
  return v.type === 'percent' ? `−${v.value}%` : `−${v.value}€`;
}

function formatDate(s) {
  const d = new Date(s);
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit' });
}

// ─────────────────────────────────────────────────────────────
// Vouchers page
// ─────────────────────────────────────────────────────────────
function VouchersPage() {
  const [vouchers, setVouchers] = useState(loadVouchers);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [editing, setEditing] = useState(null);   // voucher being edited or {} for new

  useEffect(() => { saveVouchers(vouchers); }, [vouchers]);

  // Recompute status from dates so seeded data stays accurate over time
  const enriched = useMemo(
    () => vouchers.map((v) => ({ ...v, status: deriveStatus(v) })),
    [vouchers],
  );

  const filtered = useMemo(() => {
    return enriched.filter((v) => {
      if (filter !== 'all' && v.status !== filter) return false;
      if (search && !v.code.toLowerCase().includes(search.toLowerCase())) return false;
      return true;
    });
  }, [enriched, filter, search]);

  const counts = useMemo(() => {
    const c = { all: enriched.length, active: 0, scheduled: 0, expiring: 0, expired: 0, exhausted: 0 };
    enriched.forEach((v) => { c[v.status] = (c[v.status] || 0) + 1; });
    return c;
  }, [enriched]);

  const onSave = (next) => {
    setVouchers((list) => {
      const exists = list.findIndex((x) => x.id === next.id);
      if (exists >= 0) {
        const copy = list.slice(); copy[exists] = next; return copy;
      }
      return [next, ...list];
    });
    setEditing(null);
  };

  const onDelete = (id) => {
    setVouchers((list) => list.filter((x) => x.id !== id));
    setEditing(null);
  };

  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Marketing', 'Vouchers']}/>

      <div className="page-head">
        <div>
          <h1 className="page-head__title">Codes promo & vouchers</h1>
          <p className="page-head__sub">{counts.active} actifs · {counts.scheduled} planifiés · {counts.expiring} expirent bientôt</p>
        </div>
        <div className="page-head__actions">
          <button className="btn"><NavIcon k="clipboard" size={12}/> Exporter CSV</button>
          <button className="btn btn--primary" onClick={() => setEditing({})}>
            <svg viewBox="0 0 24 24" width={12} height={12}><path d="M12 5v14M5 12h14" stroke="currentColor" fill="none" strokeWidth="2"/></svg>
            Nouveau voucher
          </button>
        </div>
      </div>

      <div className="vstats">
        <VStat label="Vouchers actifs" value={counts.active} accent="active"/>
        <VStat label="Utilisations ce mois" value={enriched.reduce((s, v) => s + (v.status !== 'expired' ? v.used : 0), 0).toLocaleString('fr-FR')}/>
        <VStat label="Économie offerte" value="€4 287" sub="cumulé 30 j."/>
        <VStat label="Taux d'utilisation" value="58%" sub="vouchers actifs"/>
      </div>

      <div className="toolbar">
        <div className="toolbar__group">
          {[
            ['all', 'Tous', counts.all],
            ['active', 'Actifs', counts.active],
            ['scheduled', 'Planifiés', counts.scheduled],
            ['expiring', 'Expirent', counts.expiring],
            ['expired', 'Expirés', counts.expired],
            ['exhausted', 'Épuisés', counts.exhausted],
          ].map(([k, lbl, n]) => (
            <button
              key={k}
              className={`toolbar__chip${filter === k ? ' toolbar__chip--active' : ''}`}
              onClick={() => setFilter(k)}
            >
              {lbl} <span style={{ opacity: 0.6, marginLeft: 4 }}>{n}</span>
            </button>
          ))}
        </div>
        <div className="toolbar__search" style={{ marginLeft: 'auto' }}>
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6" stroke="currentColor" fill="none" strokeWidth="1.7"/><path d="M16 16l4 4" stroke="currentColor" fill="none" strokeWidth="1.7"/></svg>
          <input
            placeholder="Code, nom de campagne…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      <div className="voucher-table-wrap">
        <table className="voucher-table">
          <thead>
            <tr>
              <th style={{ width: 110 }}>Statut</th>
              <th>Code</th>
              <th style={{ width: 90 }}>Réduction</th>
              <th>Périmètre</th>
              <th style={{ width: 110 }}>Canal</th>
              <th>Boutiques</th>
              <th style={{ width: 180 }}>Utilisation</th>
              <th style={{ width: 130 }}>Validité</th>
              <th style={{ width: 40 }}></th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((v) => (
              <VoucherRow key={v.id} voucher={v} onClick={() => setEditing(v)}/>
            ))}
            {filtered.length === 0 && (
              <tr><td colSpan={9} className="voucher-table__empty">Aucun voucher ne correspond aux filtres.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {editing && (
        <VoucherDrawer
          voucher={editing}
          onClose={() => setEditing(null)}
          onSave={onSave}
          onDelete={onDelete}
        />
      )}
    </div>
  );
}

function VStat({ label, value, sub, accent }) {
  return (
    <div className={`vstat${accent ? ' vstat--' + accent : ''}`}>
      <div className="vstat__label">{label}</div>
      <div className="vstat__value">{value}</div>
      {sub && <div className="vstat__sub">{sub}</div>}
    </div>
  );
}

function VoucherRow({ voucher, onClick }) {
  const v = voucher;
  const s = STATUS_LABELS[v.status] || STATUS_LABELS.active;
  const pct = v.usageLimit > 0 ? Math.min(100, Math.round((v.used / v.usageLimit) * 100)) : null;
  const shops = v.shops.length === VOUCHER_SHOPS.length
    ? 'Toutes'
    : v.shops.slice(0, 3).map((id) => SHOPS[id].short).join(' · ') + (v.shops.length > 3 ? ` +${v.shops.length - 3}` : '');

  return (
    <tr className="voucher-row" onClick={onClick}>
      <td>
        <span className={`vstatus ${s.cls}`}>
          <span className="vstatus__dot"/> {s.lbl}
        </span>
      </td>
      <td>
        <div className="vcode">{v.code}</div>
        <div className="vcode__sub">
          {v.minOrder > 0 ? `Min. ${v.minOrder}€` : 'Sans minimum'}
        </div>
      </td>
      <td className="vvalue">{formatValue(v)}</td>
      <td>
        <div className="vscope">{formatScope(v)}</div>
      </td>
      <td>
        <span className={`vchannel vchannel--${v.channels}`}>{CHANNEL_LABELS[v.channels]}</span>
      </td>
      <td>
        <div className="vshops">
          <div className="vshops__dots">
            {v.shops.slice(0, 6).map((id) => (
              <span key={id} className="vshops__dot" style={{ background: SHOPS[id].color }} title={SHOPS[id].name}/>
            ))}
          </div>
          <span className="vshops__txt">{shops}</span>
        </div>
      </td>
      <td>
        {pct !== null ? (
          <>
            <div className="vusage__row">
              <span>{v.used.toLocaleString('fr-FR')} / {v.usageLimit.toLocaleString('fr-FR')}</span>
              <span className="vusage__pct">{pct}%</span>
            </div>
            <div className="vusage__bar"><div className="vusage__fill" style={{ width: pct + '%' }}/></div>
          </>
        ) : (
          <div className="vusage__row vusage__row--unlimited">
            <span>{v.used.toLocaleString('fr-FR')}</span>
            <span className="vusage__pct">illimité</span>
          </div>
        )}
      </td>
      <td>
        <div className="vvalidity">{formatDate(v.validFrom)}</div>
        <div className="vvalidity__sub">→ {formatDate(v.validTo)}</div>
      </td>
      <td><button className="vrow__more" onClick={(e) => { e.stopPropagation(); onClick(); }}>›</button></td>
    </tr>
  );
}

// ─────────────────────────────────────────────────────────────
// Drawer — create / edit voucher
// ─────────────────────────────────────────────────────────────
function VoucherDrawer({ voucher, onClose, onSave, onDelete }) {
  const isNew = !voucher.id;
  const [draft, setDraft] = useState(() => ({
    id: voucher.id || ('v' + Math.random().toString(36).slice(2, 8)),
    code: voucher.code || '',
    type: voucher.type || 'percent',
    value: voucher.value ?? 10,
    scope: voucher.scope || 'cart',
    products: voucher.products || [],
    categories: voucher.categories || [],
    collections: voucher.collections || [],
    shops: voucher.shops || VOUCHER_SHOPS.slice(),
    channels: voucher.channels || 'both',
    minOrder: voucher.minOrder ?? 0,
    usageLimit: voucher.usageLimit ?? 100,
    used: voucher.used ?? 0,
    validFrom: voucher.validFrom || new Date().toISOString().slice(0, 10),
    validTo: voucher.validTo || new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
  }));

  const set = (k, v) => setDraft((d) => ({ ...d, [k]: v }));
  const toggle = (k, val) => setDraft((d) => ({
    ...d,
    [k]: d[k].includes(val) ? d[k].filter((x) => x !== val) : [...d[k], val],
  }));

  const valid = draft.code.trim().length >= 3 && draft.value > 0 && draft.validFrom <= draft.validTo;

  return (
    <>
      <div className="drawer-scrim" onClick={onClose}/>
      <aside className="drawer">
        <div className="drawer__head">
          <div>
            <div className="drawer__eyebrow">{isNew ? 'Nouveau voucher' : 'Modifier'}</div>
            <h2 className="drawer__title">
              {draft.code ? <span className="vcode" style={{ fontSize: 18 }}>{draft.code}</span> : 'Nouveau voucher'}
            </h2>
          </div>
          <button className="drawer__close" onClick={onClose}>×</button>
        </div>

        <div className="drawer__body">

          <Section title="Code & réduction">
            <Field label="Code">
              <input
                className="input input--mono"
                value={draft.code}
                onChange={(e) => set('code', e.target.value.toUpperCase().replace(/\s+/g, '-'))}
                placeholder="RENTREE2026"
              />
              <button className="ghost-btn" type="button" onClick={() => set('code', genCode())}>Générer</button>
            </Field>

            <Field label="Type de réduction">
              <Radio
                options={[['percent', 'Pourcentage'], ['fixed', 'Montant fixe (€)']]}
                value={draft.type}
                onChange={(v) => set('type', v)}
              />
            </Field>

            <Field label={draft.type === 'percent' ? 'Valeur (%)' : 'Valeur (€)'}>
              <input
                className="input input--num"
                type="number"
                value={draft.value}
                min={0}
                onChange={(e) => set('value', Number(e.target.value))}
              />
              <span className="input-suffix">{draft.type === 'percent' ? '%' : '€'}</span>
            </Field>
          </Section>

          <Section title="Périmètre — sur quoi le code s'applique">
            <Field label="Type">
              <select className="input" value={draft.scope} onChange={(e) => set('scope', e.target.value)}>
                <option value="cart">Panier entier</option>
                <option value="products">Produits spécifiques</option>
                <option value="categories">Catégories</option>
                <option value="collections">Collections</option>
                <option value="shops">Boutiques uniquement</option>
              </select>
            </Field>

            {draft.scope === 'products' && (
              <ChipMultiSelect
                label="Produits"
                options={VOUCHER_PRODUCTS}
                selected={draft.products}
                toggle={(id) => toggle('products', id)}
              />
            )}
            {draft.scope === 'categories' && (
              <ChipMultiSelect
                label="Catégories"
                options={VOUCHER_CATEGORIES}
                selected={draft.categories}
                toggle={(id) => toggle('categories', id)}
              />
            )}
            {draft.scope === 'collections' && (
              <ChipMultiSelect
                label="Collections"
                options={VOUCHER_COLLECTIONS}
                selected={draft.collections}
                toggle={(id) => toggle('collections', id)}
              />
            )}
          </Section>

          <Section title="Boutiques">
            <div className="shop-multi">
              {VOUCHER_SHOPS.map((id) => {
                const shop = SHOPS[id];
                const active = draft.shops.includes(id);
                return (
                  <button
                    key={id}
                    type="button"
                    className={`shop-multi__chip${active ? ' shop-multi__chip--active' : ''}`}
                    onClick={() => toggle('shops', id)}
                  >
                    <span className="shop-multi__avatar" style={{ background: shop.color }}>{shop.short}</span>
                    <div>
                      <div className="shop-multi__name">{shop.name}</div>
                      <div className="shop-multi__city">{shop.city}</div>
                    </div>
                    <span className={`shop-multi__check${active ? ' shop-multi__check--on' : ''}`}>{active ? '✓' : ''}</span>
                  </button>
                );
              })}
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
              <button className="ghost-btn" type="button" onClick={() => set('shops', VOUCHER_SHOPS.slice())}>Tout sélectionner</button>
              <button className="ghost-btn" type="button" onClick={() => set('shops', [])}>Tout désélectionner</button>
            </div>
          </Section>

          <Section title="Canal">
            <Radio
              options={[['webshop', 'Webshop'], ['office', 'Office Shop'], ['both', 'Les deux']]}
              value={draft.channels}
              onChange={(v) => set('channels', v)}
            />
          </Section>

          <Section title="Conditions & limites">
            <Field label="Montant minimum (€)">
              <input
                className="input input--num"
                type="number"
                value={draft.minOrder}
                min={0}
                onChange={(e) => set('minOrder', Number(e.target.value))}
              />
              <span className="input-hint">0 = sans minimum</span>
            </Field>

            <Field label="Limite d'utilisation">
              <input
                className="input input--num"
                type="number"
                value={draft.usageLimit}
                min={0}
                onChange={(e) => set('usageLimit', Number(e.target.value))}
              />
              <span className="input-hint">0 = illimité</span>
            </Field>
          </Section>

          <Section title="Période de validité">
            <div style={{ display: 'flex', gap: 12 }}>
              <Field label="Du">
                <input
                  className="input"
                  type="date"
                  value={draft.validFrom}
                  onChange={(e) => set('validFrom', e.target.value)}
                />
              </Field>
              <Field label="Au">
                <input
                  className="input"
                  type="date"
                  value={draft.validTo}
                  onChange={(e) => set('validTo', e.target.value)}
                />
              </Field>
            </div>
          </Section>

          <div className="drawer__preview">
            <div className="drawer__preview-label">Aperçu</div>
            <div className="vpreview">
              <div className="vpreview__code">{draft.code || 'CODE'}</div>
              <div className="vpreview__value">{formatValue(draft)}</div>
              <div className="vpreview__sub">
                {formatScope(draft)} · {CHANNEL_LABELS[draft.channels]} · {draft.shops.length} boutique{draft.shops.length > 1 ? 's' : ''}
              </div>
              <div className="vpreview__sub">
                {draft.minOrder > 0 ? `Min. ${draft.minOrder}€ · ` : ''}
                {draft.usageLimit > 0 ? `${draft.usageLimit} utilisations max` : 'Illimité'} ·
                {' '}{formatDate(draft.validFrom)} → {formatDate(draft.validTo)}
              </div>
            </div>
          </div>

        </div>

        <div className="drawer__foot">
          {!isNew && (
            <button className="btn btn--danger" onClick={() => {
              if (confirm(`Supprimer le voucher ${draft.code} ?`)) onDelete(draft.id);
            }}>Supprimer</button>
          )}
          <div style={{ flex: 1 }}/>
          <button className="btn" onClick={onClose}>Annuler</button>
          <button className="btn btn--primary" disabled={!valid} onClick={() => onSave(draft)}>
            {isNew ? 'Créer le voucher' : 'Enregistrer'}
          </button>
        </div>
      </aside>
    </>
  );
}

function Section({ title, children }) {
  return (
    <div className="dsection">
      <div className="dsection__title">{title}</div>
      <div className="dsection__body">{children}</div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label className="field">
      <span className="field__lbl">{label}</span>
      <span className="field__row">{children}</span>
    </label>
  );
}

function Radio({ options, value, onChange }) {
  return (
    <div className="radio-group">
      {options.map(([v, l]) => (
        <button
          key={v}
          type="button"
          className={`radio${value === v ? ' radio--active' : ''}`}
          onClick={() => onChange(v)}
        >
          <span className="radio__dot"/>
          {l}
        </button>
      ))}
    </div>
  );
}

function ChipMultiSelect({ label, options, selected, toggle }) {
  return (
    <Field label={label}>
      <div className="chip-multi">
        {options.map((o) => (
          <button
            key={o.id}
            type="button"
            className={`chip-multi__chip${selected.includes(o.id) ? ' chip-multi__chip--active' : ''}`}
            onClick={() => toggle(o.id)}
          >
            {o.name}
          </button>
        ))}
      </div>
    </Field>
  );
}

function genCode() {
  const wraps = ['MENU', 'ATELIER', 'GOUTER', 'BRUNCH', 'OFFICE'];
  const yr = new Date().getFullYear();
  const w = wraps[Math.floor(Math.random() * wraps.length)];
  const n = Math.floor(Math.random() * 90) + 10;
  return `${w}${n}-${yr}`;
}

Object.assign(window, { VouchersPage, loadVouchers, saveVouchers, deriveStatus, VOUCHER_PRODUCTS, VOUCHER_CATEGORIES, VOUCHER_COLLECTIONS, VOUCHER_SHOPS });


// ===== links.jsx =====
// links.jsx — Direct link generator
// Produces pre-parameterized storefront URLs (shop, date, mode, product, category, voucher)
// Renders branded QR (ruby modules + brand corners), copy URL, download PNG/SVG,
// live storefront preview, and a saved-link history.

const LINK_PRODUCTS = [
  { id: 'tarte-citron',      name: 'Tarte au citron meringuée', cat: 'patisseries', price: 6.5 },
  { id: 'tarte-praline',     name: 'Tarte praliné noisette',     cat: 'patisseries', price: 7.0 },
  { id: 'salade-bressane',   name: 'Salade bressane',            cat: 'salades',     price: 12.5 },
  { id: 'parfait-vanille',   name: 'Parfait vanille bourbon',    cat: 'douceurs',    price: 5.0 },
  { id: 'plat-saumon',       name: 'Saumon, riz, légumes verts', cat: 'plats',       price: 14.5 },
  { id: 'plat-volaille',     name: 'Volaille fermière',          cat: 'plats',       price: 13.0 },
  { id: 'cookie-chocolat',   name: 'Cookie double chocolat',     cat: 'douceurs',    price: 3.5 },
  { id: 'pain-cereales',     name: 'Pain aux céréales',          cat: 'boulangerie', price: 4.2 },
];

const LINK_CATS = [
  { id: 'patisseries', name: 'Pâtisseries' },
  { id: 'salades',     name: 'Salades' },
  { id: 'plats',       name: 'Plats du jour' },
  { id: 'douceurs',    name: 'Douceurs' },
  { id: 'boulangerie', name: 'Boulangerie' },
];

const LINK_COLLECTIONS = [
  { id: 'menu-midi', name: 'Menu de midi' },
  { id: 'brunch',    name: 'Le Brunch' },
  { id: 'goute',     name: 'Le Goûter' },
  { id: 'apero',     name: 'Apéro Box' },
];

const LINK_SHOPS = ['chatelain', 'sablon', 'carre', 'zuid', 'grognon', 'brugge'];

// History persisted to localStorage
const LINKS_KEY = 'latelier-admin:saved-links';

function loadLinks() {
  try {
    const raw = localStorage.getItem(LINKS_KEY);
    if (!raw) return SEED_LINKS;
    const p = JSON.parse(raw);
    return Array.isArray(p) ? p : SEED_LINKS;
  } catch { return SEED_LINKS; }
}
function saveLinks(list) {
  try { localStorage.setItem(LINKS_KEY, JSON.stringify(list)); } catch {}
}

const SEED_LINKS = [
  {
    id: 'l1', name: 'Newsletter rentrée — Tartes',
    config: { shop: 'chatelain', mode: 'collect', date: '2026-09-01', product: 'tarte-citron', voucher: 'RENTREE2026' },
    createdAt: '2026-04-28', clicks: 1240,
  },
  {
    id: 'l2', name: 'Affiche QR Sablon — Brunch dimanche',
    config: { shop: 'sablon', mode: 'collect', date: '', collection: 'brunch', voucher: 'BRUNCH-SABLON' },
    createdAt: '2026-04-22', clicks: 312,
  },
  {
    id: 'l3', name: 'Bureau Européen — Onboarding livraison',
    config: { shop: 'chatelain', mode: 'delivery', date: '', voucher: 'OFFICE-LAUNCH' },
    createdAt: '2026-05-01', clicks: 87,
  },
];

// ─────────────────────────────────────────────────────────────
// Build the storefront URL from the config
// ─────────────────────────────────────────────────────────────
function buildLinkUrl(cfg) {
  const base = location.origin + location.pathname.replace(/[^/]+$/, '') + 'webshop-full.html';
  const params = new URLSearchParams();
  if (cfg.shop)       params.set('shop', cfg.shop);
  if (cfg.mode)       params.set('mode', cfg.mode);
  if (cfg.date)       params.set('date', cfg.date);
  if (cfg.product)    params.set('product', cfg.product);
  if (cfg.openModal)  params.set('open', '1');
  if (cfg.category)   params.set('cat', cfg.category);
  if (cfg.collection) params.set('col', cfg.collection);
  if (cfg.voucher)    params.set('voucher', cfg.voucher);
  const qs = params.toString();
  return qs ? `${base}?${qs}` : base;
}

// ─────────────────────────────────────────────────────────────
// LinksPage
// ─────────────────────────────────────────────────────────────
function LinksPage() {
  const [cfg, setCfg] = useState({
    shop: 'chatelain',
    mode: 'collect',
    date: '',
    product: '',
    openModal: false,
    category: '',
    collection: '',
    voucher: '',
  });
  const [name, setName] = useState('Lien sans nom');
  const [history, setHistory] = useState(loadLinks);
  const [savedToast, setSavedToast] = useState(false);

  useEffect(() => { saveLinks(history); }, [history]);

  const url = buildLinkUrl(cfg);

  const set = (k, v) => setCfg((c) => ({ ...c, [k]: v }));

  const onSave = () => {
    const link = {
      id: 'l' + Math.random().toString(36).slice(2, 8),
      name: name.trim() || 'Lien sans nom',
      config: { ...cfg },
      createdAt: new Date().toISOString().slice(0, 10),
      clicks: 0,
    };
    setHistory((h) => [link, ...h]);
    setSavedToast(true);
    setTimeout(() => setSavedToast(false), 2400);
  };

  const onLoad = (link) => {
    setCfg({
      shop: '', mode: '', date: '', product: '', openModal: false,
      category: '', collection: '', voucher: '',
      ...link.config,
    });
    setName(link.name);
  };

  // Voucher dropdown — load active vouchers from storage
  const allVouchers = useMemo(() => loadVouchers(), []);
  const validVouchers = useMemo(
    () => allVouchers.filter((v) => deriveStatus(v) === 'active' || deriveStatus(v) === 'expiring' || deriveStatus(v) === 'scheduled'),
    [allVouchers],
  );

  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Marketing', 'Liens directs']}/>

      <div className="page-head">
        <div>
          <h1 className="page-head__title">Générateur de liens directs</h1>
          <p className="page-head__sub">Pré-paramétrez l'expérience webshop : boutique, date, produit, voucher. Téléchargez le QR pour vos campagnes print.</p>
        </div>
      </div>

      <div className="link-grid">
        {/* LEFT — config form */}
        <div className="link-form">
          <div className="link-form__head">
            <input
              className="input link-form__name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nom de la campagne"
            />
          </div>

          <Section title="Boutique de destination">
            <div className="shop-multi shop-multi--single">
              <button
                type="button"
                className={`shop-multi__chip${!cfg.shop ? ' shop-multi__chip--active' : ''}`}
                onClick={() => set('shop', '')}
              >
                <span className="shop-multi__avatar shop-multi__avatar--neutral">∅</span>
                <div>
                  <div className="shop-multi__name">Aucune (le client choisit)</div>
                  <div className="shop-multi__city">Pas de pré-sélection</div>
                </div>
              </button>
              {LINK_SHOPS.map((id) => {
                const shop = SHOPS[id];
                const active = cfg.shop === id;
                return (
                  <button
                    key={id}
                    type="button"
                    className={`shop-multi__chip${active ? ' shop-multi__chip--active' : ''}`}
                    onClick={() => set('shop', id)}
                  >
                    <span className="shop-multi__avatar" style={{ background: shop.color }}>{shop.short}</span>
                    <div>
                      <div className="shop-multi__name">{shop.name}</div>
                      <div className="shop-multi__city">{shop.city}</div>
                    </div>
                  </button>
                );
              })}
            </div>
          </Section>

          <Section title="Mode de service">
            <Radio
              options={[
                ['', 'Aucun (par défaut Collecte)'],
                ['collect', 'Click & Collect'],
                ['delivery', 'Livraison au bureau'],
              ]}
              value={cfg.mode}
              onChange={(v) => set('mode', v)}
            />
          </Section>

          <Section title="Date pré-sélectionnée">
            <Field label="Date">
              <input
                className="input"
                type="date"
                value={cfg.date}
                onChange={(e) => set('date', e.target.value)}
              />
              {cfg.date && <button className="ghost-btn" type="button" onClick={() => set('date', '')}>Effacer</button>}
            </Field>
            <div className="link-form__hint">Vide = la date est laissée libre côté client.</div>
          </Section>

          <Section title="Mise en avant produit">
            <Field label="Produit">
              <select className="input" value={cfg.product} onChange={(e) => set('product', e.target.value)}>
                <option value="">— Aucun —</option>
                {LINK_PRODUCTS.map((p) => (
                  <option key={p.id} value={p.id}>{p.name} · {p.price.toFixed(2)}€</option>
                ))}
              </select>
            </Field>
            {cfg.product && (
              <div className="link-toggle">
                <input
                  type="checkbox"
                  id="openmodal"
                  checked={cfg.openModal}
                  onChange={(e) => set('openModal', e.target.checked)}
                />
                <label htmlFor="openmodal">
                  <strong>Ouvrir la fiche produit</strong>
                  <span>Si décoché : le produit est mis en évidence dans la grille (halo ruby).</span>
                </label>
              </div>
            )}
          </Section>

          <Section title="Catégorie ou collection (filtre)">
            <Field label="Catégorie">
              <select className="input" value={cfg.category} onChange={(e) => { set('category', e.target.value); if (e.target.value) set('collection', ''); }}>
                <option value="">— Aucune —</option>
                {LINK_CATS.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Collection">
              <select className="input" value={cfg.collection} onChange={(e) => { set('collection', e.target.value); if (e.target.value) set('category', ''); }}>
                <option value="">— Aucune —</option>
                {LINK_COLLECTIONS.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
          </Section>

          <Section title="Voucher pré-appliqué">
            <Field label="Code">
              <select className="input input--mono" value={cfg.voucher} onChange={(e) => set('voucher', e.target.value)}>
                <option value="">— Aucun —</option>
                {validVouchers.map((v) => (
                  <option key={v.id} value={v.code}>{v.code} · {formatValue(v)}</option>
                ))}
              </select>
            </Field>
            {cfg.voucher && (
              <div className="link-form__hint">Le code sera appliqué automatiquement au passage en caisse, avec validation en temps réel côté client.</div>
            )}
          </Section>

          <div className="link-form__foot">
            <button className="btn" onClick={() => setCfg({ shop: 'chatelain', mode: 'collect', date: '', product: '', openModal: false, category: '', collection: '', voucher: '' })}>Réinitialiser</button>
            <button className="btn btn--primary" onClick={onSave}>Enregistrer ce lien</button>
          </div>
        </div>

        {/* RIGHT — output */}
        <div className="link-output">
          <UrlPanel url={url}/>
          <QrPanel url={url} brandLabel={name}/>
          <PreviewPanel cfg={cfg}/>
        </div>
      </div>

      {/* History */}
      <div className="link-history">
        <div className="link-history__head">
          <h3>Liens enregistrés</h3>
          <span>{history.length} liens</span>
        </div>
        <div className="link-history__list">
          {history.map((link) => (
            <LinkHistoryRow key={link.id} link={link} onLoad={() => onLoad(link)} onDelete={() => setHistory((h) => h.filter((x) => x.id !== link.id))}/>
          ))}
          {history.length === 0 && (
            <div className="link-history__empty">Aucun lien enregistré pour l'instant.</div>
          )}
        </div>
      </div>

      {savedToast && (
        <div className="toast">Lien enregistré — disponible dans l'historique.</div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// URL panel — display, copy, length warning
// ─────────────────────────────────────────────────────────────
function UrlPanel({ url }) {
  const [copied, setCopied] = useState(false);
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch {}
  };
  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">URL générée</span>
        <span className="opanel__meta">{url.length} caractères</span>
      </div>
      <div className="url-box">
        <div className="url-box__txt">{url}</div>
        <button className={`url-box__copy${copied ? ' url-box__copy--ok' : ''}`} onClick={onCopy}>
          {copied ? '✓ Copié' : 'Copier'}
        </button>
      </div>
      <div className="opanel__actions">
        <a className="ghost-btn" href={url} target="_blank" rel="noreferrer">Ouvrir dans un nouvel onglet ↗</a>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// QR panel — branded SVG, download PNG/SVG
// ─────────────────────────────────────────────────────────────
function QrPanel({ url, brandLabel }) {
  const [branded, setBranded] = useState(true);
  const containerRef = useRef(null);
  const [svgMarkup, setSvgMarkup] = useState('');

  useEffect(() => {
    if (!window.QR) return;
    try {
      const m = window.QR.render(url, {
        size: 220,
        branded,
        brandLabel: brandLabel ? brandLabel.toUpperCase() : '',
        color: '#8D1D2C',
        bg: '#ffffff',
      });
      setSvgMarkup(m);
    } catch (e) {
      setSvgMarkup('');
      console.error(e);
    }
  }, [url, branded, brandLabel]);

  const downloadSvg = () => {
    const blob = new Blob([svgMarkup], { type: 'image/svg+xml' });
    triggerDownload(blob, sanitize(brandLabel) + '.svg');
  };
  const downloadPng = async () => {
    if (!window.QR) return;
    try {
      const blob = await window.QR.renderPng(url, {
        size: 360, branded,
        brandLabel: brandLabel ? brandLabel.toUpperCase() : '',
        color: '#8D1D2C', bg: '#ffffff',
      });
      triggerDownload(blob, sanitize(brandLabel) + '.png');
    } catch (e) { console.error(e); }
  };

  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">QR code</span>
        <div className="qr-toggle">
          <button className={`qr-toggle__btn${branded ? ' qr-toggle__btn--active' : ''}`} onClick={() => setBranded(true)}>Brandé</button>
          <button className={`qr-toggle__btn${!branded ? ' qr-toggle__btn--active' : ''}`} onClick={() => setBranded(false)}>Standard</button>
        </div>
      </div>
      <div className="qr-card">
        <div className="qr-card__qr" ref={containerRef} dangerouslySetInnerHTML={{ __html: svgMarkup }}/>
        <div className="qr-card__meta">
          <div className="qr-card__brand">L'Atelier By</div>
          <div className="qr-card__url">{url.replace(/^https?:\/\//, '').slice(0, 32)}…</div>
        </div>
      </div>
      <div className="opanel__actions">
        <button className="btn" onClick={downloadPng}>Télécharger PNG</button>
        <button className="btn" onClick={downloadSvg}>Télécharger SVG</button>
      </div>
    </div>
  );
}

function triggerDownload(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function sanitize(s) {
  return (s || 'lien-latelier').replace(/[^\w-]+/g, '-').toLowerCase().slice(0, 40);
}

// ─────────────────────────────────────────────────────────────
// Preview — miniature storefront state
// ─────────────────────────────────────────────────────────────
function PreviewPanel({ cfg }) {
  const shop = cfg.shop ? SHOPS[cfg.shop] : null;
  const product = LINK_PRODUCTS.find((p) => p.id === cfg.product);

  return (
    <div className="opanel">
      <div className="opanel__head">
        <span className="opanel__lbl">Aperçu de l'arrivée</span>
        <span className="opanel__meta">État pré-chargé</span>
      </div>
      <div className="ppreview">
        <div className="ppreview__nav">
          <span className="ppreview__shop">
            {shop ? (
              <>
                <span className="ppreview__dot" style={{ background: shop.color }}/>
                {shop.name}
              </>
            ) : (
              <>
                <span className="ppreview__dot ppreview__dot--neutral"/>
                Choisissez votre boutique
              </>
            )}
          </span>
          <span className={`ppreview__mode ppreview__mode--${cfg.mode || 'collect'}`}>
            {cfg.mode === 'delivery' ? 'Livraison' : 'Collecte'}
          </span>
          <span className="ppreview__date">
            {cfg.date ? formatDate(cfg.date) : 'Date à choisir'}
          </span>
        </div>
        <div className="ppreview__filter">
          {cfg.category && <span className="ppreview__chip">Cat. {LINK_CATS.find((c) => c.id === cfg.category)?.name}</span>}
          {cfg.collection && <span className="ppreview__chip">Col. {LINK_COLLECTIONS.find((c) => c.id === cfg.collection)?.name}</span>}
          {!cfg.category && !cfg.collection && <span className="ppreview__chip ppreview__chip--ghost">Tous les produits</span>}
        </div>
        <div className="ppreview__grid">
          {LINK_PRODUCTS.slice(0, 6).map((p) => {
            const highlighted = p.id === cfg.product && !cfg.openModal;
            const opened = p.id === cfg.product && cfg.openModal;
            const dim = (cfg.category && p.cat !== cfg.category);
            return (
              <div
                key={p.id}
                className={`ppreview__card${highlighted ? ' ppreview__card--hl' : ''}${dim ? ' ppreview__card--dim' : ''}`}
              >
                <div className="ppreview__card-img"/>
                <div className="ppreview__card-name">{p.name.length > 18 ? p.name.slice(0, 18) + '…' : p.name}</div>
                <div className="ppreview__card-price">{p.price.toFixed(2)}€</div>
                {opened && <div className="ppreview__modal">Fiche ouverte</div>}
              </div>
            );
          })}
        </div>
        {cfg.voucher && (
          <div className="ppreview__voucher">
            <span className="ppreview__voucher-tag">VOUCHER</span>
            <span className="vcode">{cfg.voucher}</span>
            <span className="ppreview__voucher-state">✓ Pré-appliqué au panier</span>
          </div>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// History row
// ─────────────────────────────────────────────────────────────
function LinkHistoryRow({ link, onLoad, onDelete }) {
  const cfg = link.config;
  const url = buildLinkUrl(cfg);
  const shop = cfg.shop ? SHOPS[cfg.shop] : null;
  const [copied, setCopied] = useState(false);

  const onCopy = async (e) => {
    e.stopPropagation();
    try { await navigator.clipboard.writeText(url); setCopied(true); setTimeout(() => setCopied(false), 1500); } catch {}
  };

  return (
    <div className="lhrow" onClick={onLoad}>
      <div className="lhrow__main">
        <div className="lhrow__name">{link.name}</div>
        <div className="lhrow__meta">
          {shop && <span className="lhrow__chip"><span className="lhrow__dot" style={{ background: shop.color }}/>{shop.name}</span>}
          {cfg.mode && <span className="lhrow__chip">{cfg.mode === 'delivery' ? 'Livraison' : 'Collecte'}</span>}
          {cfg.product && <span className="lhrow__chip">Produit</span>}
          {cfg.voucher && <span className="lhrow__chip lhrow__chip--ruby">{cfg.voucher}</span>}
          {cfg.date && <span className="lhrow__chip">{formatDate(cfg.date)}</span>}
        </div>
      </div>
      <div className="lhrow__stats">
        <div className="lhrow__clicks">{link.clicks.toLocaleString('fr-FR')}</div>
        <div className="lhrow__clicks-lbl">clics</div>
      </div>
      <div className="lhrow__actions" onClick={(e) => e.stopPropagation()}>
        <button className="btn" onClick={onCopy}>{copied ? '✓' : 'Copier URL'}</button>
        <button className="btn" onClick={onLoad}>Charger</button>
        <button className="btn btn--ghost" onClick={() => { if (confirm(`Supprimer "${link.name}" ?`)) onDelete(); }}>×</button>
      </div>
    </div>
  );
}

Object.assign(window, { LinksPage, buildLinkUrl });


// ===== admin-app.jsx =====
// admin-app.jsx — Admin shell with sidebar routing
// Tournées (existing) · Vouchers (new) · Liens directs (new)

// (useState/useEffect/useMemo/useRef destructured earlier in bundle)

// ─────────────────────────────────────────────────────────────
// Sidebar — routes between sections
// ─────────────────────────────────────────────────────────────
const NAV_ICONS = {
  home: <><path d="M4 21V10l8-6 8 6v11"/><path d="M9 21v-7h6v7"/></>,
  bag: <><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 016 0"/></>,
  truck: <><path d="M3 16V7h11v9M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></>,
  campaign: <><path d="M4 12V8a2 2 0 012-2h6l8-3v18l-8-3H6a2 2 0 01-2-2v-2"/><path d="M9 14v5"/></>,
  product: <><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></>,
  clipboard: <><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 11h6M9 15h4"/></>,
  shop: <><path d="M4 9l1.5-4h13L20 9"/><path d="M4 9v11h16V9"/><path d="M9 13h6v7H9z"/></>,
  user: <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></>,
  cog: <><circle cx="12" cy="12" r="3"/><path d="M19 12h2M3 12h2M12 3v2M12 19v2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></>,
  ticket: <><path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 100 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 100-4V8z"/><path d="M14 6v2M14 11v2M14 16v2"/></>,
  link: <><path d="M10 13a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1.5 1.5"/><path d="M14 11a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1.5-1.5"/></>,
  bell: <><path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 004 0"/></>,
  chev: <path d="M9 6l6 6-6 6"/>,
};

function NavIcon({ k, size = 14 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size}>{NAV_ICONS[k]}</svg>
  );
}

function AdminSidebar({ route, setRoute }) {
  const item = (key, k, label, count) => (
    <a
      key={key}
      className={`admin__nav-item${route === key ? ' admin__nav-item--active' : ''}`}
      onClick={() => setRoute(key)}
    >
      <NavIcon k={k}/> {label}
      {count != null && <span className="admin__nav-count">{count}</span>}
    </a>
  );

  return (
    <aside className="admin__side">
      <div className="admin__brand">
        <div className="admin__brand-mark">L</div>
        <div>
          <div className="admin__brand-name">L'Atelier By</div>
          <span className="admin__brand-sub">Console franchise</span>
        </div>
      </div>

      <div className="admin__nav-group">Pilotage</div>
      {item('dash', 'home', 'Tableau de bord')}
      {item('orders', 'bag', 'Commandes', 142)}
      {item('tournees', 'truck', 'Tournées', 8)}
      {item('campaigns', 'campaign', 'Campagnes')}

      <div className="admin__nav-group">Marketing</div>
      {item('vouchers', 'ticket', 'Vouchers', 12)}
      {item('links', 'link', 'Liens directs')}

      <div className="admin__nav-group">Catalogue</div>
      {item('products', 'product', 'Produits')}
      {item('bundles', 'clipboard', 'Bundles & Menus')}

      <div className="admin__nav-group">Réseau</div>
      {item('shops', 'shop', 'Boutiques')}
      {item('clients', 'user', 'Clients Office')}
      {item('settings', 'cog', 'Paramètres')}
    </aside>
  );
}

function AdminTopbar({ crumbs }) {
  return (
    <div className="admin__topbar">
      <div className="admin__breadcrumb">
        {crumbs.map((c, i) => (
          <React.Fragment key={i}>
            {i > 0 && <NavIcon k="chev" size={11}/>}
            {i === crumbs.length - 1 ? <strong>{c}</strong> : <span>{c}</span>}
          </React.Fragment>
        ))}
      </div>
      <div className="admin__topbar-spacer"/>
      <span className="admin__topbar-pill"><NavIcon k="bell" size={12}/> 2 incidents</span>
      <span className="admin__topbar-pill"><NavIcon k="shop" size={12}/> Toutes boutiques</span>
      <div className="admin__topbar-user">SR</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Tournées page — preserves existing variant gallery in a canvas
// ─────────────────────────────────────────────────────────────
function TourneesPage() {
  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Pilotage', 'Tournées']}/>
      <div style={{ flex: 1, minHeight: 0, position: 'relative' }}>
        <DesignCanvas style={{ height: '100%', width: '100%' }}>
          <DCSection id="variants" title="Trois pistes pour rendre la boutique d'attache toujours visible sur la tuile.">
            <DCArtboard id="A" label="A — Bandeau rail" width={1480} height={1020}>
              <VariantAFrame/>
            </DCArtboard>
            <DCArtboard id="B" label="B — Pastille en ligne" width={1480} height={1020}>
              <VariantBFrame/>
            </DCArtboard>
            <DCArtboard id="C" label="C — Tampon livraison" width={1480} height={1020}>
              <VariantCFrame/>
            </DCArtboard>
          </DCSection>
        </DesignCanvas>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// App router
// ─────────────────────────────────────────────────────────────
function AdminApp() {
  // Persist route to URL hash so reload keeps the user on the same page.
  const [route, setRouteState] = useState(() => {
    const h = (location.hash || '#tournees').slice(1);
    return ['tournees', 'vouchers', 'links'].includes(h) ? h : 'tournees';
  });
  const setRoute = (r) => {
    setRouteState(r);
    if (location.hash !== '#' + r) history.replaceState(null, '', '#' + r);
  };
  useEffect(() => {
    const onHash = () => {
      const h = (location.hash || '#tournees').slice(1);
      if (['tournees', 'vouchers', 'links'].includes(h)) setRouteState(h);
    };
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  let page;
  if (route === 'tournees') page = <TourneesPage/>;
  else if (route === 'vouchers') page = <VouchersPage/>;
  else if (route === 'links') page = <LinksPage/>;
  else page = <PlaceholderPage route={route} setRoute={setRoute}/>;

  return (
    <div className="admin">
      <AdminSidebar route={route} setRoute={setRoute}/>
      {page}
    </div>
  );
}

function PlaceholderPage({ route, setRoute }) {
  return (
    <div className="admin__main">
      <AdminTopbar crumbs={['Pilotage', route]}/>
      <div className="admin-empty">
        <div className="admin-empty__inner">
          <div className="admin-empty__title">Section en cours de design</div>
          <div className="admin-empty__sub">Cette section n'est pas encore prototypée. Sélectionnez Tournées, Vouchers ou Liens directs.</div>
          <div className="admin-empty__actions">
            <button className="btn" onClick={() => setRoute('tournees')}>← Tournées</button>
            <button className="btn btn--primary" onClick={() => setRoute('vouchers')}>Vouchers</button>
            <button className="btn" onClick={() => setRoute('links')}>Liens directs</button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { AdminApp, AdminSidebar, AdminTopbar, NavIcon });


// ===== mount =====
ReactDOM.createRoot(document.getElementById("root")).render(<AdminApp/>);
