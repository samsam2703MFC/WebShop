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
