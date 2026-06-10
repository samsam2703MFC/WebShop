/* Stripe integration — hosted Checkout (cards + Bancontact) + webhooks.

   Why hosted Checkout over embedded Payment Element:
   * Bancontact + cards supported with zero extra UI work
   * No card data ever touches our origin (smallest PCI scope: SAQ A)
   * Stripe handles 3DS/SCA, retries, locale (fr-BE) out of the box
   * The storefront is a static GitHub Pages site — a redirect flow
     fits it naturally; an embedded element would still need our API
     for intents anyway.

   The Stripe client is injectable (setStripeClient) so tests can
   substitute a mock without network access. */
import Stripe from 'stripe';
import { config } from './config.js';
import { webshopDb } from './db.js';

let stripeClient = config.stripe.secretKey ? new Stripe(config.stripe.secretKey) : null;
export function setStripeClient(c) { stripeClient = c; }
export function stripeEnabled() { return !!stripeClient; }

const toCents = (eur) => Math.round(Number(eur) * 100);

/* Create a hosted Checkout Session for a priced order.
   Amounts come exclusively from the server-side quote. */
export async function createCheckoutSession({ orderId, quote, customerEmail }) {
  if (!stripeClient) throw new Error('Stripe not configured (STRIPE_SECRET_KEY missing)');

  const line_items = quote.lines.map((l) => ({
    quantity: l.qty,
    price_data: {
      currency: 'eur',
      unit_amount: toCents(l.unit_price_ttc),
      product_data: { name: l.name },
    },
  }));
  if (quote.delivery_fee && quote.delivery_fee.fee_amount > 0) {
    line_items.push({
      quantity: 1,
      price_data: {
        currency: 'eur',
        unit_amount: toCents(quote.delivery_fee.fee_amount),
        product_data: { name: 'Frais de livraison' },
      },
    });
  }

  const params = {
    mode: 'payment',
    payment_method_types: ['card', 'bancontact'],
    locale: 'fr',
    line_items,
    customer_email: customerEmail || undefined,
    client_reference_id: orderId,
    metadata: { order_id: orderId },
    payment_intent_data: { metadata: { order_id: orderId } },
    success_url: config.stripe.successUrl.replace('{ORDER_ID}', orderId),
    cancel_url: config.stripe.cancelUrl.replace('{ORDER_ID}', orderId),
    expires_at: Math.floor(Date.now() / 1000) + 30 * 60, // 30 min hold
  };

  // Discounts: Stripe Checkout needs a coupon; for percent/amount discounts
  // we create a one-off coupon scoped to this session.
  if (quote.discount_ttc > 0) {
    const coupon = await stripeClient.coupons.create({
      amount_off: toCents(quote.discount_ttc),
      currency: 'eur',
      duration: 'once',
      name: quote.discounts.map((d) => d.label).join(' + ').slice(0, 40) || 'Réduction',
    });
    params.discounts = [{ coupon: coupon.id }];
  }

  return stripeClient.checkout.sessions.create(params);
}

export function verifyWebhook(rawBody, signature) {
  if (!stripeClient) throw new Error('Stripe not configured');
  if (!config.stripe.webhookSecret) throw new Error('STRIPE_WEBHOOK_SECRET missing');
  return stripeClient.webhooks.constructEvent(rawBody, signature, config.stripe.webhookSecret);
}

/* Idempotency gate: returns false when this event id was already
   processed (Stripe retries deliveries). */
export async function claimEvent(event, orderId) {
  const [r] = await webshopDb.query(
    'INSERT IGNORE INTO ws_stripe_events (event_id, type, order_id) VALUES (?,?,?)',
    [event.id, event.type, orderId || null]
  );
  return r.affectedRows === 1;
}

/* Status transitions guarded so a late/duplicate event can never
   un-pay or re-open an order. */
export async function markOrderPaid(orderId, paymentIntentId) {
  const [r] = await webshopDb.query(
    `UPDATE ws_orders SET status = 'paid', paid_at = NOW(),
       stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id)
     WHERE id = ? AND status = 'pending_payment'`,
    [paymentIntentId || null, orderId]
  );
  if (r.affectedRows === 1) {
    // burn voucher usage exactly once, on payment
    await webshopDb.query(
      `UPDATE ws_vouchers v JOIN ws_orders o ON o.voucher_code = v.code
       SET v.used_count = v.used_count + 1 WHERE o.id = ?`, [orderId]
    );
  }
  return r.affectedRows === 1;
}

export async function releaseOrder(orderId, toStatus) {
  const [r] = await webshopDb.query(
    `UPDATE ws_orders SET status = ? WHERE id = ? AND status = 'pending_payment'`,
    [toStatus, orderId]
  );
  return r.affectedRows === 1;
}

/* Webhook event dispatcher — shared by the route and the tests. */
export async function handleStripeEvent(event) {
  const obj = event.data?.object || {};
  const orderId = obj.metadata?.order_id || obj.client_reference_id || null;
  if (!(await claimEvent(event, orderId))) return { handled: false, reason: 'duplicate' };
  if (!orderId) return { handled: false, reason: 'no order_id' };

  switch (event.type) {
    case 'checkout.session.completed':
      if (obj.payment_status === 'paid') {
        return { handled: await markOrderPaid(orderId, obj.payment_intent), action: 'paid' };
      }
      return { handled: false, reason: 'session completed but not paid (async method pending)' };
    case 'checkout.session.async_payment_succeeded': // Bancontact settles async
    case 'payment_intent.succeeded':
      return { handled: await markOrderPaid(orderId, obj.payment_intent || obj.id), action: 'paid' };
    case 'checkout.session.async_payment_failed':
    case 'payment_intent.payment_failed':
      return { handled: await releaseOrder(orderId, 'payment_failed'), action: 'payment_failed' };
    case 'checkout.session.expired':
    case 'payment_intent.canceled':
      return { handled: await releaseOrder(orderId, 'canceled'), action: 'canceled' };
    default:
      return { handled: false, reason: `ignored event type ${event.type}` };
  }
}
