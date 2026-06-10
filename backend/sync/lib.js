/* Sync core — shared by worker.js (outbox consumer), full-sync.js and
   reconcile.js.

   Principles (per spec):
   * One-way: general DB → webshop DB. Source business tables are
     never written; only fb_outbox.processed_at is marked.
   * Idempotent upserts keyed on stable external ids (SKU / code).
   * Deactivation, never hard delete.
   * Out-of-order safe: an outbox event is only a *notification*;
     the CURRENT source row is re-read at processing time, so stale
     events converge to the latest state.
   * Mapping lives in field-mapping.json, not in code. */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { webshopDb, generalDb } from '../src/db.js';

const dir = path.dirname(fileURLToPath(import.meta.url));
export const MAPPING = JSON.parse(fs.readFileSync(path.join(dir, 'field-mapping.json'), 'utf8'));
delete MAPPING.$comment;

/* Entities in dependency order: refs must exist before referrers. */
export const ENTITY_ORDER = ['famille', 'boutique', 'article', 'stock', 'promo'];

/* ── transforms (referenced by name from field-mapping.json) ───────── */
const transforms = {
  categoryRef: async (faCode) => {
    const [[row]] = await webshopDb.query('SELECT id FROM ws_categories WHERE external_id = ?', [faCode]);
    if (!row) throw new RefMissingError(`category external_id=${faCode} not synced yet`);
    return row.id;
  },
  productRef: async (sku) => {
    const [[row]] = await webshopDb.query('SELECT id FROM ws_products WHERE external_id = ?', [sku]);
    if (!row) throw new RefMissingError(`product external_id=${sku} not synced yet`);
    return row.id;
  },
  shopRef: async (code) => {
    const [[row]] = await webshopDb.query('SELECT id FROM ws_shops WHERE external_id = ?', [code]);
    if (!row) throw new RefMissingError(`shop external_id=${code} not synced yet`);
    return row.id;
  },
  promoKind: async (v) => (v === 'pourcent' ? 'percent' : 'amount'),
};

export class RefMissingError extends Error {}

/* ── logging ───────────────────────────────────────────────────────── */
export async function logSync(runKind, entity, externalId, action, detail = null) {
  await webshopDb.query(
    'INSERT INTO sync_log (run_kind, entity, external_id, action, detail) VALUES (?,?,?,?,?)',
    [runKind, entity, externalId, action, detail]
  );
}

/* ── core mapping ──────────────────────────────────────────────────── */
async function mapRow(entity, srcRow) {
  const m = MAPPING[entity];
  const out = {};
  for (const [srcKeyRaw, spec] of Object.entries(m.fields)) {
    const srcKey = srcKeyRaw.split('#')[0]; // "code#id" → read "code", write to a 2nd target col
    let v = srcRow[srcKey];
    if (spec.transform) v = await transforms[spec.transform](v);
    out[spec.to] = v === undefined ? null : v;
  }
  return out;
}

function keyCols(key) { return Array.isArray(key) ? key : [key]; }

async function fetchSourceRow(entity, refId) {
  const m = MAPPING[entity];
  const cols = keyCols(m.source.key);
  const vals = String(refId).split('|');
  const where = cols.map((c) => `${c} = ?`).join(' AND ');
  const [[row]] = await generalDb.query(`SELECT * FROM ${m.source.table} WHERE ${where}`, vals);
  return row || null;
}

/* Upsert one mapped row into the target table. Returns inserted|updated. */
async function upsertTarget(entity, mapped) {
  const m = MAPPING[entity];
  const tCols = Object.keys(mapped);
  const kCols = keyCols(m.target.key);
  const insertCols = tCols.map((c) => `\`${c}\``).join(', ');
  const placeholders = tCols.map(() => '?').join(', ');
  const updates = tCols.filter((c) => !kCols.includes(c)).map((c) => `\`${c}\` = VALUES(\`${c}\`)`).join(', ');
  const [r] = await webshopDb.query(
    `INSERT INTO ${m.target.table} (${insertCols}) VALUES (${placeholders})
     ON DUPLICATE KEY UPDATE ${updates}`,
    tCols.map((c) => mapped[c])
  );
  // mysql: affectedRows 1 = insert, 2 = update, 0 = no-op update
  return r.affectedRows === 1 ? 'inserted' : 'updated';
}

/* Deactivate target row(s) for a source ref that disappeared/disabled. */
export async function deactivateTarget(entity, refId) {
  const m = MAPPING[entity];
  if (entity === 'stock') {
    const [sku, boutique] = String(refId).split('|');
    try {
      const productId = await transforms.productRef(sku);
      const shopId = await transforms.shopRef(boutique);
      await webshopDb.query(
        'UPDATE ws_product_shops SET available = 0 WHERE product_id = ? AND shop_id = ?',
        [productId, shopId]
      );
    } catch (e) {
      if (e instanceof RefMissingError) return 'skipped'; // never synced — nothing to deactivate
      throw e;
    }
    return 'deactivated';
  }
  const [r] = await webshopDb.query(
    `UPDATE ${m.target.table} SET ${m.target.activeFlag} = 0 WHERE external_id = ?`,
    [refId]
  );
  return r.affectedRows > 0 ? 'deactivated' : 'skipped';
}

/* Process one logical change: re-read source, upsert or deactivate.
   Throws RefMissingError when a dependency hasn't been synced yet
   (caller decides to retry later). */
export async function syncOne(entity, refId, op, runKind) {
  if (op === 'delete') {
    const action = await deactivateTarget(entity, refId);
    await logSync(runKind, entity, refId, action, 'source row deleted');
    return action;
  }
  const src = await fetchSourceRow(entity, refId);
  if (!src) {
    // upsert event but row already gone — treat as deactivation
    const action = await deactivateTarget(entity, refId);
    await logSync(runKind, entity, refId, action, 'source row missing on upsert event');
    return action;
  }
  const mapped = await mapRow(entity, src);
  const action = await upsertTarget(entity, mapped);
  await logSync(runKind, entity, refId, action, null);
  return action;
}

/* Full sync of one entity: upsert every source row, then deactivate
   every target row whose external key no longer exists in the source. */
export async function fullSyncEntity(entity, runKind = 'full') {
  const m = MAPPING[entity];
  const [srcRows] = await generalDb.query(`SELECT * FROM ${m.source.table}`);
  const stats = { inserted: 0, updated: 0, deactivated: 0, errors: 0 };
  const seen = new Set();

  for (const src of srcRows) {
    const ref = keyCols(m.source.key).map((c) => src[c]).join('|');
    seen.add(ref);
    try {
      const mapped = await mapRow(entity, src);
      stats[await upsertTarget(entity, mapped)]++;
    } catch (e) {
      stats.errors++;
      await logSync(runKind, entity, ref, 'error', e.message);
    }
  }

  // deactivate rows that vanished upstream
  if (entity === 'stock') {
    const [targets] = await webshopDb.query(
      `SELECT ps.product_id, ps.shop_id, p.external_id AS sku, s.external_id AS boutique
       FROM ws_product_shops ps
       JOIN ws_products p ON p.id = ps.product_id
       JOIN ws_shops s ON s.id = ps.shop_id
       WHERE ps.available = 1`
    );
    for (const t of targets) {
      if (!seen.has(`${t.sku}|${t.boutique}`)) {
        await webshopDb.query(
          'UPDATE ws_product_shops SET available = 0 WHERE product_id = ? AND shop_id = ?',
          [t.product_id, t.shop_id]
        );
        await logSync(runKind, entity, `${t.sku}|${t.boutique}`, 'deactivated', 'absent from source');
        stats.deactivated++;
      }
    }
  } else {
    const [targets] = await webshopDb.query(
      `SELECT external_id FROM ${m.target.table} WHERE ${m.target.activeFlag} = 1 AND external_id IS NOT NULL`
    );
    for (const t of targets) {
      if (!seen.has(String(t.external_id))) {
        await deactivateTarget(entity, t.external_id);
        await logSync(runKind, entity, t.external_id, 'deactivated', 'absent from source');
        stats.deactivated++;
      }
    }
  }
  return stats;
}

export async function setSyncState(k, v) {
  await webshopDb.query(
    'INSERT INTO sync_state (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
    [k, String(v)]
  );
}
