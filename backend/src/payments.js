/* Payment bridge → Stripe hosted Checkout for a ws_orders row.
 * Builds line items from ws_order_lines. Degrades gracefully (503) when
 * STRIPE_SECRET_KEY is not set, so the rest of the API still works. */
import { Router } from 'express';
import Stripe from 'stripe';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

const stripe = process.env.STRIPE_SECRET_KEY ? new Stripe(process.env.STRIPE_SECRET_KEY) : null;

export function createPaymentsRouter(db) {
  const r = Router();

  /* POST /payments/checkout — { orderId, successUrl?, cancelUrl? }.
     Creates a Stripe Checkout session for an existing order and returns its URL. */
  r.post('/payments/checkout', wrap(async (req, res) => {
    const { orderId, successUrl, cancelUrl } = req.body || {};
    const [[o]] = await db.query('SELECT * FROM ws_orders WHERE id = ? OR order_ref = ? LIMIT 1', [orderId, orderId]);
    if (!o) return res.status(404).json({ error: 'Commande introuvable' });
    const [lines] = await db.query('SELECT product_name, qty, unit_price FROM ws_order_lines WHERE order_id = ?', [o.id]);
    if (!lines.length) return res.status(400).json({ error: 'Commande vide' });

    if (!stripe) {
      // No key configured — the order stays 'pending' for another payment path.
      return res.status(503).json({ error: 'Paiement indisponible (Stripe non configuré)', orderId: o.id, status: o.status });
    }

    const session = await stripe.checkout.sessions.create({
      mode: 'payment',
      line_items: lines.map((l) => ({
        quantity: l.qty,
        price_data: {
          currency: 'eur',
          unit_amount: Math.round(Number(l.unit_price) * 100),
          product_data: { name: l.product_name },
        },
      })),
      success_url: successUrl || process.env.CHECKOUT_SUCCESS_URL || 'https://example.com/?order={ORDER}&paid=1',
      cancel_url: cancelUrl || process.env.CHECKOUT_CANCEL_URL || 'https://example.com/?order={ORDER}&canceled=1',
      metadata: { order_id: String(o.id), order_ref: o.order_ref || '' },
    });
    await db.query('UPDATE ws_orders SET payment_method = ?, payment_status = ? WHERE id = ?', ['card', 'pending', o.id]);
    res.json({ ok: true, orderId: o.id, checkoutUrl: session.url });
  }));

  /* Stripe webhook — marks the order paid. Needs the raw body (mounted in the
     server before express.json for this path). */
  r.post('/payments/webhook', wrap(async (req, res) => {
    if (!stripe) return res.status(503).json({ error: 'Stripe non configuré' });
    let event = req.body;
    const secret = process.env.STRIPE_WEBHOOK_SECRET;
    if (secret) {
      try { event = stripe.webhooks.constructEvent(req.rawBody || req.body, req.headers['stripe-signature'], secret); }
      catch { return res.status(400).json({ error: 'Signature invalide' }); }
    }
    if (event.type === 'checkout.session.completed') {
      const oid = event.data.object.metadata?.order_id;
      if (oid) await db.query("UPDATE ws_orders SET payment_status='paid', status='confirmed' WHERE id = ?", [oid]);
    }
    res.json({ received: true });
  }));

  return r;
}
