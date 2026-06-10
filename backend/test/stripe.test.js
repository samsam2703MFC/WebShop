/* Stripe webhook + pricing tests.
   handleStripeEvent is exercised directly with synthetic events —
   covers idempotency (Stripe retries) and status-transition guards. */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

process.env.NODE_ENV = 'test';
const { webshopDb, generalDb } = await import('../src/db.js');
const { handleStripeEvent } = await import('../src/stripe.js');
const { quote, vatSplit, resolveDeliveryFee } = await import('../src/pricing.js');

let orderId;

before(async () => {
  orderId = 'ord-test-' + crypto.randomUUID().slice(0, 8);
  await webshopDb.query(
    `INSERT INTO ws_orders (id, shop_id, mode, status, subtotal_ttc, total_ttc, total_htva, total_tva)
     VALUES (?, 'chatelain', 'collect', 'pending_payment', 10, 10, 9.43, 0.57)`, [orderId]
  );
});

after(async () => {
  await webshopDb.query('DELETE FROM ws_orders WHERE id LIKE "ord-test-%"');
  await webshopDb.query('DELETE FROM ws_stripe_events WHERE event_id LIKE "evt_test_%"');
  await webshopDb.end(); await generalDb.end();
});

function evt(id, type, orderRef, extra = {}) {
  return { id, type, data: { object: { metadata: { order_id: orderRef }, ...extra } } };
}

test('checkout.session.completed (paid) marks order paid', async () => {
  const r = await handleStripeEvent(
    evt('evt_test_1', 'checkout.session.completed', orderId, { payment_status: 'paid', payment_intent: 'pi_test_1' })
  );
  assert.equal(r.handled, true);
  const [[o]] = await webshopDb.query('SELECT status, stripe_payment_intent_id FROM ws_orders WHERE id = ?', [orderId]);
  assert.equal(o.status, 'paid');
  assert.equal(o.stripe_payment_intent_id, 'pi_test_1');
});

test('idempotency: redelivered event id is skipped', async () => {
  const r = await handleStripeEvent(
    evt('evt_test_1', 'checkout.session.completed', orderId, { payment_status: 'paid' })
  );
  assert.equal(r.handled, false);
  assert.equal(r.reason, 'duplicate');
});

test('status guard: late payment_failed cannot un-pay a paid order', async () => {
  const r = await handleStripeEvent(evt('evt_test_2', 'payment_intent.payment_failed', orderId));
  assert.equal(r.handled, false); // guard: only pending_payment can transition
  const [[o]] = await webshopDb.query('SELECT status FROM ws_orders WHERE id = ?', [orderId]);
  assert.equal(o.status, 'paid');
});

test('payment_failed releases a pending order', async () => {
  const failId = 'ord-test-' + crypto.randomUUID().slice(0, 8);
  await webshopDb.query(
    `INSERT INTO ws_orders (id, shop_id, mode, status, subtotal_ttc, total_ttc, total_htva, total_tva)
     VALUES (?, 'chatelain', 'collect', 'pending_payment', 5, 5, 4.72, 0.28)`, [failId]
  );
  const r = await handleStripeEvent(evt('evt_test_3', 'payment_intent.payment_failed', failId));
  assert.equal(r.handled, true);
  const [[o]] = await webshopDb.query('SELECT status FROM ws_orders WHERE id = ?', [failId]);
  assert.equal(o.status, 'payment_failed');
});

test('VAT split: Belgian 6% and 21% rates', () => {
  assert.deepEqual(vatSplit(10.60, 6), { htva: 10.00, tva: 0.60 });
  assert.deepEqual(vatSplit(12.10, 21), { htva: 10.00, tva: 2.10 });
});

test('fee resolution priority: site rule beats office and global', async () => {
  const site = await resolveDeliveryFee({ siteId: 'site-acme-loi', officeClientId: 'off-acme', shopId: 'chatelain', subtotal: 10 });
  assert.equal(site.resolved_level, 'site');
  assert.equal(site.fee_amount, 4.5);
  assert.equal(site.payment_type, 'deferred');

  const office = await resolveDeliveryFee({ officeClientId: 'off-acme', shopId: 'chatelain', subtotal: 10 });
  assert.equal(office.resolved_level, 'office');
  assert.equal(office.fee_amount, 5);

  const global = await resolveDeliveryFee({ shopId: 'nope', subtotal: 10 });
  assert.equal(global.resolved_level, 'global');
  assert.equal(global.fee_amount, 7);
});

test('fee waived above free_delivery_minimum', async () => {
  const r = await resolveDeliveryFee({ siteId: 'site-acme-loi', shopId: 'chatelain', subtotal: 45 });
  assert.equal(r.fee_amount, 0);
});

test('quote rejects no_delivery products in delivery mode', async () => {
  const [[q]] = await webshopDb.query("SELECT id FROM ws_products WHERE no_delivery = 1 AND active = 1 LIMIT 1");
  if (!q) return; // seed changed — skip
  await assert.rejects(
    () => quote({ shopId: 'chatelain', mode: 'delivery', basket: [{ productId: q.id, qty: 1 }] }),
    (e) => e.status === 422
  );
});
