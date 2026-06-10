/* Full sync CLI — initial load + disaster recovery.
   Usage: npm run sync:full            (all entities)
          node sync/full-sync.js article stock   (subset) */
import { ENTITY_ORDER, fullSyncEntity, setSyncState } from './lib.js';
import { webshopDb, generalDb } from '../src/db.js';

const requested = process.argv.slice(2);
const entities = requested.length ? ENTITY_ORDER.filter((e) => requested.includes(e)) : ENTITY_ORDER;

const t0 = Date.now();
let hadErrors = false;
for (const entity of entities) {
  const stats = await fullSyncEntity(entity, 'full');
  if (stats.errors > 0) hadErrors = true;
  console.log(
    `${entity.padEnd(10)} inserted=${stats.inserted} updated=${stats.updated} ` +
    `deactivated=${stats.deactivated} errors=${stats.errors}`
  );
}
await setSyncState('last_full_sync', new Date().toISOString());
console.log(`done in ${Date.now() - t0} ms`);
await webshopDb.end(); await generalDb.end();
process.exit(hadErrors ? 1 : 0);
