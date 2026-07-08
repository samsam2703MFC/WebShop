/*
 * Franchise Buddy — Menus API module (Node/Express + mysql2).
 *
 * Attaches `options`, `available_bundles`, `upsells` (and `has_menu_options`)
 * to products, in the exact shape the storefront expects — see
 * FRANCHISE_BUDDY_MENUS_API.md. DB columns code/price_delta/image are mapped
 * to the API field names id/delta/img here (the serializer layer).
 *
 * Uses raw mysql2 so it drops into any Node stack; if you use Prisma/Sequelize
 * the same queries map 1:1 to your models.
 *
 *   const { attachMenus, mountMenuRoutes } = require('./menus');
 *   // enrich a product list:
 *   await attachMenus(pool, products);            // products[i].id used as key
 *   // or expose standalone routes:
 *   mountMenuRoutes(app, pool);                    // GET /api/v1/products/:id/menus
 */

const num = (v) => (v === null || v === undefined ? 0 : Number(v));

/* Batch-load menu data for many products in a few queries (no N+1). */
async function loadMenus(pool, productIds) {
  const ids = [...new Set(productIds)].filter((v) => v !== null && v !== undefined);
  const empty = {};
  if (!ids.length) return empty;

  const q = (sql, params) => pool.query(sql, params).then(([rows]) => rows);

  const [options, menus, upsells] = await Promise.all([
    q('SELECT * FROM ws_product_options WHERE product_id IN (?) ORDER BY product_id, sort_order, id', [ids]),
    q('SELECT * FROM ws_menus WHERE product_id IN (?) AND active = 1 ORDER BY product_id, sort_order, id', [ids]),
    q('SELECT * FROM ws_product_upsells WHERE product_id IN (?) ORDER BY product_id, sort_order, id', [ids]),
  ]);

  const optionIds = options.map((o) => o.id);
  const menuIds = menus.map((m) => m.id);
  const optChoices = optionIds.length
    ? await q('SELECT * FROM ws_product_option_choices WHERE option_id IN (?) ORDER BY sort_order, id', [optionIds]) : [];
  const slots = menuIds.length
    ? await q('SELECT * FROM ws_menu_slots WHERE menu_id IN (?) ORDER BY sort_order, id', [menuIds]) : [];
  const slotIds = slots.map((s) => s.id);
  const slotChoices = slotIds.length
    ? await q('SELECT * FROM ws_menu_slot_choices WHERE slot_id IN (?) ORDER BY sort_order, id', [slotIds]) : [];

  // group helpers
  const by = (rows, key) => rows.reduce((acc, r) => ((acc[r[key]] ??= []).push(r), acc), {});
  const choicesByOption = by(optChoices, 'option_id');
  const slotsByMenu = by(slots, 'menu_id');
  const choicesBySlot = by(slotChoices, 'slot_id');
  const optionsByProduct = by(options, 'product_id');
  const menusByProduct = by(menus, 'product_id');
  const upsellsByProduct = by(upsells, 'product_id');

  const out = {};
  for (const pid of ids) {
    const opts = (optionsByProduct[pid] || []).map((o) => ({
      id: o.code,
      label: o.label,
      kind: o.kind,
      required: !!o.required,
      choices: (choicesByOption[o.id] || []).map((c) => ({ id: c.code, label: c.label, delta: num(c.price_delta) })),
    }));

    const bundles = (menusByProduct[pid] || []).map((m) => ({
      id: m.code,
      name: m.name,
      description: m.description || '',
      price_modifier: num(m.price_modifier),
      recommended: !!m.recommended,
      advantages: parseAdvantages(m.advantages),
      slots: (slotsByMenu[m.id] || []).map((s) => ({
        id: s.code,
        label: s.label,
        required: !!s.required,
        choices: (choicesBySlot[s.id] || []).map((c) => ({ id: c.code, label: c.label, img: c.image || null, delta: num(c.price_delta) })),
      })),
    }));

    const ups = (upsellsByProduct[pid] || []).map((u) => ({ id: u.code, label: u.label, img: u.image || null, delta: num(u.price_delta) }));

    out[pid] = {
      has_menu_options: bundles.length > 0 || opts.length > 0,
      options: opts,
      available_bundles: bundles,
      upsells: ups,
    };
  }
  return out;
}

function parseAdvantages(v) {
  if (Array.isArray(v)) return v;
  if (typeof v === 'string' && v.trim()) { try { const j = JSON.parse(v); return Array.isArray(j) ? j : []; } catch { return []; } }
  return [];
}

/* Enrich an array of product objects in place (products[i].id is the key). */
async function attachMenus(pool, products) {
  const menus = await loadMenus(pool, products.map((p) => p.id));
  for (const p of products) {
    const m = menus[p.id] || { has_menu_options: false, options: [], available_bundles: [], upsells: [] };
    Object.assign(p, m);
  }
  return products;
}

/* Optional standalone routes. */
function mountMenuRoutes(app, pool) {
  app.get('/api/v1/products/:id/menus', async (req, res) => {
    try {
      const menus = await loadMenus(pool, [Number(req.params.id)]);
      res.json(menus[Number(req.params.id)] || { has_menu_options: false, options: [], available_bundles: [], upsells: [] });
    } catch (e) { res.status(500).json({ error: 'menus_error' }); }
  });
}

module.exports = { loadMenus, attachMenus, mountMenuRoutes };
