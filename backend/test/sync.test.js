/* Sync pipeline tests — run against the local dev DBs.
   Covers the four required properties:
   mapping correctness, idempotency, deactivation, out-of-order events. */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';

process.env.NODE_ENV = 'test';
const { webshopDb, generalDb } = await import('../src/db.js');
const { syncOne, fullSyncEntity, RefMissingError } = await import('../sync/lib.js');

const SKU = 'TEST-SYNC-001';

before(async () => {
  await generalDb.query('DELETE FROM fb_articles WHERE sku LIKE "TEST-%"');
  await webshopDb.query('DELETE FROM ws_products WHERE external_id LIKE "TEST-%"');
});

after(async () => {
  await generalDb.query('DELETE FROM fb_articles WHERE sku LIKE "TEST-%"');
  await generalDb.query('DELETE FROM fb_outbox WHERE ref_id LIKE "TEST-%"');
  await webshopDb.query('DELETE FROM ws_products WHERE external_id LIKE "TEST-%"');
  await webshopDb.query('DELETE FROM sync_log WHERE external_id LIKE "TEST-%"');
  await webshopDb.end(); await generalDb.end();
});

test('mapping correctness: ERP fields land in the right webshop columns', async () => {
  await generalDb.query(
    `INSERT INTO fb_articles (sku, famille_code, designation, prix_ttc, taux_tva, retrait_seul, delai_jours)
     VALUES (?, 'sweet', 'Test éclair café', 9.90, 21.00, 1, 2)`, [SKU]
  );
  const action = await syncOne('article', SKU, 'upsert', 'event');
  assert.equal(action, 'inserted');

  const [[p]] = await webshopDb.query('SELECT * FROM ws_products WHERE external_id = ?', [SKU]);
  assert.equal(p.name, 'Test éclair café');          // designation → name (utf8mb4 intact)
  assert.equal(Number(p.price), 9.90);               // prix_ttc → price
  assert.equal(Number(p.vat_rate), 21.00);           // taux_tva → vat_rate
  assert.equal(p.no_delivery, 1);                    // retrait_seul → no_delivery
  assert.equal(p.lead_time, 2);                      // delai_jours → lead_time
  const [[cat]] = await webshopDb.query('SELECT id FROM ws_categories WHERE external_id = ?', ['sweet']);
  assert.equal(p.cat, cat.id);                       // famille_code resolved via categoryRef
});

test('idempotency: replaying the same event does not duplicate or corrupt', async () => {
  await syncOne('article', SKU, 'upsert', 'event');
  await syncOne('article', SKU, 'upsert', 'event'); // replay
  const [rows] = await webshopDb.query('SELECT * FROM ws_products WHERE external_id = ?', [SKU]);
  assert.equal(rows.length, 1);
  assert.equal(Number(rows[0].price), 9.90);
});

test('out-of-order events converge to current source state', async () => {
  // Source is updated twice; the "older" event is processed AFTER the
  // newer one (events carry no payload, the current row is re-read,
  // so any order converges to the latest state).
  await generalDb.query('UPDATE fb_articles SET prix_ttc = 11.50 WHERE sku = ?', [SKU]);
  await syncOne('article', SKU, 'upsert', 'event'); // "newest" event
  await syncOne('article', SKU, 'upsert', 'event'); // stale duplicate arriving late
  const [[p]] = await webshopDb.query('SELECT price FROM ws_products WHERE external_id = ?', [SKU]);
  assert.equal(Number(p.price), 11.50);
});

test('dependency ordering: stock event before its product raises RefMissingError', async () => {
  // Article + stock exist in the SOURCE, but the article was never
  // synced to the target — the stock event must signal "retry later".
  await generalDb.query(
    `INSERT INTO fb_articles (sku, famille_code, designation, prix_ttc) VALUES ('TEST-EARLY-1','sweet','Early',2.00)`
  );
  await generalDb.query(
    `INSERT INTO fb_stock (sku, boutique_code, dispo) VALUES ('TEST-EARLY-1','chatelain',1)`
  );
  await assert.rejects(
    () => syncOne('stock', 'TEST-EARLY-1|chatelain', 'upsert', 'event'),
    RefMissingError
  );
  await generalDb.query('DELETE FROM fb_stock WHERE sku = "TEST-EARLY-1"');
  await generalDb.query('DELETE FROM fb_articles WHERE sku = "TEST-EARLY-1"');
});

test('deactivation: source delete soft-disables, never hard-deletes', async () => {
  await generalDb.query('DELETE FROM fb_articles WHERE sku = ?', [SKU]);
  const action = await syncOne('article', SKU, 'delete', 'event');
  assert.equal(action, 'deactivated');
  const [[p]] = await webshopDb.query('SELECT active FROM ws_products WHERE external_id = ?', [SKU]);
  assert.ok(p, 'row must still exist');
  assert.equal(p.active, 0);
});

test('full sync deactivates rows absent from source and is idempotent', async () => {
  // Recreate then remove from source; full sync must deactivate the orphan.
  await generalDb.query(
    `INSERT INTO fb_articles (sku, famille_code, designation, prix_ttc) VALUES ('TEST-ORPHAN-1','sweet','Orphan',1.00)`
  );
  await syncOne('article', 'TEST-ORPHAN-1', 'upsert', 'event');
  await generalDb.query('DELETE FROM fb_articles WHERE sku = "TEST-ORPHAN-1"');

  const s1 = await fullSyncEntity('article', 'reconcile');
  assert.ok(s1.deactivated >= 1, 'orphan should be deactivated');
  const s2 = await fullSyncEntity('article', 'reconcile');
  assert.equal(s2.inserted, 0, 'second run inserts nothing');
  assert.equal(s2.deactivated, 0, 'second run deactivates nothing');
});
