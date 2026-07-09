/* Auth bridge → ws_customers (bcrypt passwords + stateless HMAC token).
 * No session table needed: the token is a signed {id, exp} the server verifies
 * on each request. Frontend sends `Authorization: Bearer <token>`. */
import { Router } from 'express';
import crypto from 'node:crypto';
import bcrypt from 'bcryptjs';

const wrap = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error(e); res.status(500).json({ error: 'Erreur interne' });
});

const SECRET = process.env.AUTH_SECRET || process.env.ADMIN_TOKEN || 'dev-secret-change-me';
const TTL = 30 * 86400; // 30 days

function sign(payload) {
  const body = Buffer.from(JSON.stringify(payload)).toString('base64url');
  const sig = crypto.createHmac('sha256', SECRET).update(body).digest('base64url');
  return `${body}.${sig}`;
}
function verify(token) {
  const [body, sig] = String(token || '').split('.');
  if (!body || !sig) return null;
  const expect = crypto.createHmac('sha256', SECRET).update(body).digest('base64url');
  const a = Buffer.from(sig), b = Buffer.from(expect);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    const p = JSON.parse(Buffer.from(body, 'base64url').toString());
    if (p.exp && p.exp < Math.floor(Date.now() / 1000)) return null;
    return p;
  } catch { return null; }
}
export function userIdFromReq(req) {
  const token = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');
  return verify(token)?.id || null;
}

async function userPayload(db, id) {
  const [[u]] = await db.query(
    `SELECT id, email, first_name, last_name, phone, office_id, preferred_shop_id,
            preferred_lang, is_business, fidelity_active
       FROM ws_customers WHERE id = ?`, [id]);
  if (!u) return null;
  return {
    id: u.id, email: u.email, firstName: u.first_name, lastName: u.last_name,
    phone: u.phone, officeId: u.office_id, preferredShopId: u.preferred_shop_id,
    lang: u.preferred_lang, isBusiness: !!u.is_business,
    fidelityApp: { active: !!u.fidelity_active },
  };
}

export function createAuthRouter(db) {
  const r = Router();

  r.post('/auth/register', wrap(async (req, res) => {
    const { email, password, firstName = '', lastName = '' } = req.body || {};
    const mail = String(email || '').trim().toLowerCase();
    if (!/.+@.+\..+/.test(mail)) return res.status(400).json({ error: 'Email invalide' });
    if (String(password || '').length < 6) return res.status(400).json({ error: 'Mot de passe trop court (min. 6)' });
    const [[exists]] = await db.query('SELECT id FROM ws_customers WHERE email = ?', [mail]);
    if (exists) return res.status(409).json({ error: 'Un compte existe déjà avec cet email.' });
    const hash = await bcrypt.hash(password, 10);
    const [ins] = await db.query(
      'INSERT INTO ws_customers (email, password_hash, first_name, last_name) VALUES (?,?,?,?)',
      [mail, hash, firstName, lastName]);
    const token = sign({ id: ins.insertId, exp: Math.floor(Date.now() / 1000) + TTL });
    res.status(201).json({ user: await userPayload(db, ins.insertId), token });
  }));

  r.post('/auth/login', wrap(async (req, res) => {
    const { email, password } = req.body || {};
    const [[u]] = await db.query(
      'SELECT id, password_hash FROM ws_customers WHERE email = ? AND active = 1',
      [String(email || '').trim().toLowerCase()]);
    if (!u || !(await bcrypt.compare(String(password || ''), u.password_hash))) {
      return res.status(401).json({ error: 'Identifiants incorrects.' });
    }
    const token = sign({ id: u.id, exp: Math.floor(Date.now() / 1000) + TTL });
    res.json({ user: await userPayload(db, u.id), token });
  }));

  r.get('/auth/me', wrap(async (req, res) => {
    const id = userIdFromReq(req);
    const u = id && await userPayload(db, id);
    if (!u) return res.status(401).json({ error: 'Non connecté.' });
    res.json({ user: u });
  }));

  r.patch('/auth/me', wrap(async (req, res) => {
    const id = userIdFromReq(req);
    if (!id) return res.status(401).json({ error: 'Non connecté.' });
    const { firstName, lastName, phone, preferredShopId } = req.body || {};
    const sets = [], vals = [];
    for (const [col, v] of Object.entries({ first_name: firstName, last_name: lastName, phone, preferred_shop_id: preferredShopId })) {
      if (v !== undefined) { sets.push(`${col} = ?`); vals.push(v); }
    }
    if (sets.length) { vals.push(id); await db.query(`UPDATE ws_customers SET ${sets.join(', ')} WHERE id = ?`, vals); }
    res.json({ user: await userPayload(db, id) });
  }));

  return r;
}
