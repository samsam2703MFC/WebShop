/* Outbox consumer — near-real-time sync worker.

   Polls fb_outbox every SYNC_POLL_MS (default 1 s → seconds-level
   latency), processes pending events in entity-dependency order,
   marks them processed. An event is only a notification: the current
   source row is re-read, so out-of-order and duplicate events are
   harmless (last state always wins).

   Events whose dependencies are not synced yet (e.g. stock event
   arriving before its product) are LEFT PENDING and retried next
   poll; the hourly reconcile job is the backstop. */
import { config } from '../src/config.js';
import { webshopDb, generalDb } from '../src/db.js';
import { ENTITY_ORDER, syncOne, logSync, setSyncState, RefMissingError } from './lib.js';

const ORDER = Object.fromEntries(ENTITY_ORDER.map((e, i) => [e, i]));
let stopping = false;

async function pollOnce() {
  const [events] = await generalDb.query(
    `SELECT id, entity, op, ref_id FROM fb_outbox
     WHERE processed_at IS NULL ORDER BY id ASC LIMIT ?`,
    [config.sync.batchSize]
  );
  if (!events.length) return 0;

  // Dependency order first, outbox order second.
  events.sort((a, b) => (ORDER[a.entity] - ORDER[b.entity]) || (a.id - b.id));

  let processed = 0;
  for (const ev of events) {
    if (stopping) break;
    try {
      await syncOne(ev.entity, ev.ref_id, ev.op, 'event');
      await generalDb.query('UPDATE fb_outbox SET processed_at = NOW() WHERE id = ?', [ev.id]);
      processed++;
    } catch (e) {
      if (e instanceof RefMissingError) {
        // dependency not synced yet — leave pending, retried next poll
        continue;
      }
      await logSync('event', ev.entity, ev.ref_id, 'error', e.message);
      await generalDb.query('UPDATE fb_outbox SET processed_at = NOW() WHERE id = ?', [ev.id]);
      processed++;
    }
  }
  if (processed) await setSyncState('last_event_sync', new Date().toISOString());
  return processed;
}

async function main() {
  console.log(`sync worker started — poll ${config.sync.pollMs} ms, batch ${config.sync.batchSize}`);
  await setSyncState('worker_started', new Date().toISOString());
  while (!stopping) {
    try {
      const n = await pollOnce();
      if (n) console.log(`processed ${n} event(s)`);
    } catch (e) {
      console.error('poll failed:', e.message); // transient DB error — keep running
    }
    await new Promise((r) => setTimeout(r, config.sync.pollMs));
  }
  await webshopDb.end(); await generalDb.end();
}

process.on('SIGINT', () => { stopping = true; });
process.on('SIGTERM', () => { stopping = true; });
main();
