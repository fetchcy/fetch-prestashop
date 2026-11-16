// Minimal mock of the Fetch Partner API for the e2e test. Records every request.
// Reused from fetch-woocommerce/tests/e2e/mock-fetch.mjs; adapted to listen on
// all interfaces (the PrestaShop container reaches it via host.docker.internal),
// to apply the live rules (price required, max 200 products per call) and to
// fail on demand (/__fail-next) so retries can be tested.
//
// It also keeps the store's listings: PUT /products creates or updates the
// listing of each ref, GET /listings pages them keyset-style on (updatedAt,
// listingId) with an opaque cursor like live, and POST /listings/batch applies
// rows with the live rules (price on every row, max 200 rows, max 500 KB, exactly
// one of listingId / ref / barcode).
import http from 'node:http';
import fs from 'node:fs';

const PORT = Number(process.env.MOCK_PORT || 9099);
const HOST = process.env.MOCK_HOST || '0.0.0.0';
const LOG = process.env.MOCK_LOG || new URL('./mock-requests.json', import.meta.url).pathname;
const STORE = 'store-1';
const requests = [];
const orders = new Map(); // orderId → order
const problems = [];
let failNext = null; // { status, retryAfter, count, match, skip }
const listings = new Map(); // listingId → listing
let listingSeq = 0;
let clock = Date.parse('2026-11-01T00:00:00Z');
let pageCap = 500; // live clamps limit to 500; tests lower it to force several pages
const tick = () => new Date((clock += 1000)).toISOString();
const byRef = (ref) => [...listings.values()].find((l) => l.ref === ref);
function upsertListing(fields) {
  const id = fields.listingId || `lst-${++listingSeq}`;
  const prev = listings.get(id) || { listingId: id, productId: `prod-${fields.ref || id}`, variantId: null, barcode: null, sku: null, ref: null, categoryPath: [], price: '0.00', salePrice: null, saleEndsAt: null, currency: 'EUR', stockCount: null, trackStock: false, isActive: true };
  const next = { ...prev, ...fields, listingId: id, updatedAt: tick() };
  listings.set(id, next);
  return next;
}
const encodeCursor = (l) => Buffer.from(JSON.stringify({ k: l.updatedAt, id: l.listingId })).toString('base64url');
const decodeCursor = (c) => { try { return JSON.parse(Buffer.from(c, 'base64url').toString()); } catch { return null; } };
const after = (l, c) => l.updatedAt > c.k || (l.updatedAt === c.k && l.listingId > c.id);

const money = (v) => typeof v === 'string' && /^\d+\.\d{2}$/.test(v);
function validateProduct(p, i) {
  const at = `products[${i}]`;
  if (!p.ref || typeof p.ref !== 'string') problems.push(`${at}.ref missing`);
  if (!p.name || typeof p.name !== 'string') problems.push(`${at}.name missing`);
  // Live Fetch stores accept SIMPLE products only through PUT /products.
  if (p.productType !== undefined && p.productType !== 'SIMPLE') problems.push(`${at}.productType=${p.productType} (live accepts SIMPLE only)`);
  if (p.variants || p.matrix) problems.push(`${at} carries variants/matrix`);
  if (p.price === undefined) problems.push(`${at}.price missing (required on a live store)`);
  if (p.price !== undefined && !money(p.price)) problems.push(`${at}.price not 2-decimal string: ${p.price}`);
  if (p.salePrice !== undefined && !money(p.salePrice)) problems.push(`${at}.salePrice not 2-decimal string: ${p.salePrice}`);
  if (p.stockCount !== undefined && !Number.isInteger(p.stockCount)) problems.push(`${at}.stockCount not int`);
}

function send(res, code, body, headers = {}) {
  res.writeHead(code, { 'content-type': 'application/json', ...headers });
  res.end(JSON.stringify(body));
}
const save = () => fs.writeFileSync(LOG, JSON.stringify({ requests, problems }, null, 2));

const server = http.createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    let body = null;
    try { body = raw ? JSON.parse(raw) : null; } catch { body = { unparsable: raw }; }
    const p = url.pathname;
    if (!p.startsWith('/__')) {
      requests.push({ method: req.method, path: p, query: Object.fromEntries(url.searchParams), body, auth: req.headers.authorization || null, idem: req.headers['idempotency-key'] || null, ua: req.headers['user-agent'] || null, at: Date.now() });
      save();
    }

    // Test control (no auth).
    if (req.method === 'POST' && p === '/__seed-order') { orders.set(body.orderId, body); return send(res, 200, { ok: true }); }
    if (req.method === 'POST' && p === '/__set-status') { const o = orders.get(body.orderId); if (o) o.status = body.status; return send(res, 200, o || {}); }
    // match: only requests whose "METHOD path" contains it; skip: let that many matching requests through first.
    if (req.method === 'POST' && p === '/__fail-next') { failNext = { status: body.status || 503, retryAfter: body.retryAfter, count: body.count || 1, match: body.match || '', skip: body.skip || 0 }; return send(res, 200, { ok: true }); }
    if (req.method === 'POST' && p === '/__seed-listings') { const out = (body.listings || []).map((l) => upsertListing(l)); return send(res, 200, { ok: true, listings: out }); }
    if (req.method === 'POST' && p === '/__page-cap') { pageCap = Math.max(1, Math.min(500, body.size || 500)); return send(res, 200, { ok: true, pageCap }); }
    if (req.method === 'GET' && p === '/__listings') return send(res, 200, { data: [...listings.values()] });
    if (req.method === 'POST' && p === '/__clear-orders') { orders.clear(); return send(res, 200, { ok: true }); }

    if (req.method === 'POST' && p === '/api/v1/oauth/token') {
      if (body?.grant_type !== 'client_credentials' || body.client_secret !== 'secret-1') return send(res, 401, { statusCode: 401, message: 'invalid_client' });
      return send(res, 200, { access_token: 'tok-1', token_type: 'Bearer', expires_in: 3600 });
    }
    if (req.headers.authorization !== 'Bearer tok-1') return send(res, 401, { statusCode: 401, message: 'Unauthorized' });
    if (failNext && failNext.count > 0 && `${req.method} ${p}`.includes(failNext.match) && failNext.skip-- <= 0) {
      failNext.count -= 1;
      const { status, retryAfter } = failNext;
      if (failNext.count <= 0) failNext = null;
      return send(res, status, { statusCode: status, message: `forced ${status}` }, retryAfter ? { 'retry-after': String(retryAfter) } : {});
    }

    if (req.method === 'GET' && p === '/api/v1/partner/stores') return send(res, 200, { data: [{ id: STORE, name: 'Mock Shop', city: 'Nicosia', isActive: true }] });

    if (req.method === 'PUT' && p === `/api/v1/partner/stores/${STORE}/products`) {
      const list = body?.products || [];
      if (list.length > 200) problems.push(`PUT /products with ${list.length} products (live max 200)`);
      if (raw.length > 500 * 1024) problems.push(`PUT /products body ${raw.length} bytes (max 500 KB)`);
      list.forEach(validateProduct);
      save();
      const results = list.map((x) => ({ ref: x.ref, productId: `prod-${x.ref}`, status: 'created', matchedBy: null }));
      for (const x of list) {
        const prev = byRef(x.ref);
        upsertListing({ listingId: prev?.listingId, ref: x.ref, productId: `prod-${x.ref}`, name: x.name, barcode: x.barcode ?? null, price: x.price, salePrice: x.salePrice ?? null, stockCount: x.stockCount ?? null, trackStock: x.stockCount !== undefined, isActive: x.isActive !== false });
      }
      return send(res, 200, { created: results.length, updated: 0, rejected: 0, results });
    }

    if (req.method === 'GET' && p === `/api/v1/partner/stores/${STORE}/listings`) {
      const asked = url.searchParams.get('limit');
      const limit = Math.min(pageCap, Math.max(1, Math.min(500, Number(asked || 200))));
      if (asked && Number(asked) > 500) problems.push(`GET /listings limit=${asked} (live max 500)`);
      const rawCursor = url.searchParams.get('cursor');
      const cursor = rawCursor ? decodeCursor(rawCursor) : null;
      if (rawCursor && !cursor) return send(res, 400, { statusCode: 400, message: 'invalid cursor' });
      const sorted = [...listings.values()].sort((a, b) => (a.updatedAt < b.updatedAt ? -1 : a.updatedAt > b.updatedAt ? 1 : a.listingId < b.listingId ? -1 : 1));
      const rest = cursor ? sorted.filter((l) => after(l, cursor)) : sorted;
      const data = rest.slice(0, limit);
      return send(res, 200, { data, nextCursor: rest.length > limit ? encodeCursor(data.at(-1)) : null });
    }

    if (req.method === 'POST' && p === `/api/v1/partner/stores/${STORE}/listings/batch`) {
      const items = body?.items;
      if (!req.headers['idempotency-key']) { problems.push('POST /listings/batch without Idempotency-Key'); return send(res, 400, { statusCode: 400, message: 'idempotency-key header required' }); }
      if (raw.length > 500 * 1024) { problems.push(`POST /listings/batch body ${raw.length} bytes (max 500 KB)`); return send(res, 413, { statusCode: 413, message: 'request entity too large' }); }
      if (!Array.isArray(items)) { problems.push('POST /listings/batch without items[]'); return send(res, 400, { statusCode: 400, message: 'items must be an array' }); }
      if (items.length > 200) { problems.push(`POST /listings/batch with ${items.length} rows (live max 200)`); return send(res, 400, { statusCode: 400, message: 'items must contain no more than 200 elements' }); }
      const results = items.map((row, i) => {
        const given = ['listingId', 'ref', 'barcode'].filter((k) => row[k]);
        if (given.length !== 1) { problems.push(`items[${i}] has ${given.length} identifiers`); return { ref: row.ref ?? null, listingId: row.listingId ?? null, status: 'error', code: given.length ? 'ambiguous_ref' : 'ref_not_found', message: 'Provide exactly one of listingId, ref or barcode' }; }
        if (row.price === undefined) { problems.push(`items[${i}].price missing (required on a live store)`); return { ref: row.ref ?? null, listingId: row.listingId ?? null, status: 'error', code: 'validation', message: 'price is required' }; }
        if (!money(row.price)) { problems.push(`items[${i}].price not 2-decimal string: ${row.price}`); return { ref: row.ref ?? null, listingId: row.listingId ?? null, status: 'error', code: 'validation', message: 'price must be a decimal string' }; }
        const target = row.listingId ? listings.get(row.listingId) : row.ref ? byRef(row.ref) : [...listings.values()].find((l) => l.barcode === row.barcode);
        if (!target) return { ref: row.ref ?? null, listingId: row.listingId ?? null, status: 'error', code: 'ref_not_found', message: 'No listing matches this reference in this store' };
        const patch = { listingId: target.listingId, price: row.price };
        if (row.salePrice !== undefined) patch.salePrice = row.salePrice;
        if (row.stockCount !== undefined) patch.stockCount = row.stockCount;
        if (row.isActive !== undefined) patch.isActive = row.isActive;
        upsertListing(patch);
        return { ref: row.ref ?? null, listingId: target.listingId, status: 'updated' };
      });
      save();
      const rejected = results.filter((r) => r.status === 'error').length;
      return send(res, 201, { accepted: results.length - rejected, rejected, results });
    }

    if (req.method === 'GET' && p === `/api/v1/partner/stores/${STORE}/orders`) return send(res, 200, { data: [...orders.values()], nextCursor: null });

    const m = p.match(new RegExp(`^/api/v1/partner/stores/${STORE}/orders/([^/]+)(?:/(accept|ready|reject))?$`));
    if (m) {
      const o = orders.get(m[1]);
      if (!o) return send(res, 404, { statusCode: 404, message: 'not found' });
      if (req.method === 'GET' && !m[2]) return send(res, 200, o);
      const next = { accept: 'ACCEPTED', ready: 'READY_FOR_PICKUP', reject: 'REJECTED' }[m[2]];
      const allowed = { accept: ['DRAFT'], ready: ['ACCEPTED', 'PREPARING'], reject: ['DRAFT', 'ACCEPTED'] }[m[2]];
      if (!allowed.includes(o.status)) return send(res, 409, { statusCode: 409, message: `cannot ${m[2]} from ${o.status}` });
      o.status = next;
      return send(res, 200, o);
    }

    if (req.method === 'PUT' && p === '/api/v1/partner/webhook') return send(res, 200, { url: body.url, signingSecret: 'whsec_test', signatureHeader: 'X-Fetch-Signature', toleranceSeconds: 300 });

    send(res, 404, { statusCode: 404, message: `no mock for ${req.method} ${p}` });
  });
});
server.listen(PORT, HOST, () => console.log(`mock fetch on ${HOST}:${PORT}`));
