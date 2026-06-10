/* Reconciliation job — detects and repairs drift between the general
   DB and the webshop DB. Run hourly via cron:
     0 * * * *  cd /srv/webshop-backend && node sync/reconcile.js

   Strategy: a reconcile run IS a full sync (idempotent upserts +
   deactivation of vanished rows), but it first measures drift so the
   repair is observable in sync_log / sync-status. */
import { ENTITY_ORDER, MAPPING, fullSyncEntity, setSyncState, logSync } from './lib.js';
import { webshopDb, generalDb } from '../src/db.js';

function keyCols(key) { return Array.isArray(key) ? key : [key]; }

let totalDrift = 0;
for (const entity of ENTITY_ORDER) {
  const m = MAPPING[entity];
  const srcKey = keyCols(m.source.key).join(", '|', ");
  const [[{ n: srcActive }]] = await generalDb.query(
    `SELECT COUNT(*) n FROM ${m.source.table} WHERE ${m.source.activeFlag} = 1`
  );
  const targetCount = entity === 'stock'
    ? `SELECT COUNT(*) n FROM ws_product_shops WHERE available = 1`
    : `SELECT COUNT(*) n FROM ${m.target.table} WHERE ${m.target.activeFlag} = 1 AND external_id IS NOT NULL`;
  const [[{ n: tgtActive }]] = await webshopDb.query(targetCount);

  const stats = await fullSyncEntity(entity, 'reconcile');
  const drift = stats.inserted + stats.deactivated; // rows the worker had missed
  totalDrift += drift;
  console.log(
    `${entity.padEnd(10)} src_active=${srcActive} tgt_active_before=${tgtActive} ` +
    `repaired: +${stats.inserted} ~${stats.updated} -${stats.deactivated} err=${stats.errors}`
  );
  if (drift > 0) {
    await logSync('reconcile', entity, null, 'updated', `drift repaired: ${drift} row(s)`);
  }
}

await setSyncState('last_reconcile', new Date().toISOString());
await setSyncState('last_reconcile_drift', String(totalDrift));
console.log(totalDrift === 0 ? 'no drift detected' : `repaired ${totalDrift} drifted row(s)`);
await webshopDb.end(); await generalDb.end();
