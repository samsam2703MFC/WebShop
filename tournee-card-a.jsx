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
