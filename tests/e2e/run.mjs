// e2e: mock Fetch API (node) + PrestaShop 8.1 + MariaDB (docker compose) + the module.
//
//   node tests/e2e/run.mjs              build the ZIP, boot a fresh stack, run, tear down
//   E2E_KEEP=1 node tests/e2e/run.mjs   leave PrestaShop and the mock running afterwards
//   E2E_REUSE=1 node tests/e2e/run.mjs  reuse a running stack + mock (fast iteration)
//   E2E_SOURCE=1 ...                    mount fetchsync/ instead of the unzipped release
import { spawn, execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '../..');
const PORT = Number(process.env.PS_PORT || 8088);
const PS = `http://localhost:${PORT}`;
const MOCK_PORT = Number(process.env.MOCK_PORT || 9099);
const MOCK = `http://127.0.0.1:${MOCK_PORT}`;
const REUSE = !!process.env.E2E_REUSE;
const KEEP = !!process.env.E2E_KEEP || REUSE;
const results = [];
const ok = (label, cond, extra) => {
  results.push([cond ? 'PASS' : 'FAIL', label]);
  console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${!cond && extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 900) : ''}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const mockLog = () => JSON.parse(fs.readFileSync(path.join(here, 'mock-requests.json'), 'utf8'));
const compose = (...args) => execFileSync('docker', ['compose', '-f', path.join(here, 'docker-compose.yml'), ...args], { cwd: here, env: composeEnv, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
const sql = (query) => compose('exec', '-T', 'db', 'mariadb', '-N', '-uroot', '-padmin', 'prestashop', '-e', query).trim();
const consoleCmd = (...args) => compose('exec', '-T', '-u', 'www-data', 'prestashop', 'php', 'bin/console', ...args);
// bin/console prestashop:module on 8.x; the module API over HTTP where the console cannot boot (1.7 image).
async function moduleAction(action) {
  try { consoleCmd('prestashop:module', action, 'fetchsync'); return 'console'; } catch {}
  const r = await step('module', { do: action });
  if (!r.ok) throw new Error(`module ${action} failed: ${JSON.stringify(r.errors)}`);
  return 'http';
}

// Test the release ZIP, unzipped, unless asked for the source tree.
let composeEnv = { ...process.env, PS_PORT: String(PORT) };
// Start from nothing; a bind mount whose folder is replaced under a running container goes stale.
if (!REUSE) { try { compose('down', '-v'); } catch {} }
if (!process.env.E2E_SOURCE && !REUSE) {
  const zip = execFileSync(path.join(repo, 'build.sh'), { cwd: repo }).toString().trim();
  const out = path.join(repo, 'dist', `e2e-${process.env.COMPOSE_PROJECT_NAME || 'fetchsync-e2e'}`);
  fs.rmSync(out, { recursive: true, force: true });
  fs.mkdirSync(out, { recursive: true });
  execFileSync('unzip', ['-q', path.join(repo, zip), '-d', out]);
  composeEnv.MODULE_DIR = path.join(out, 'fetchsync');
  console.log(`testing ${zip}`);
}

if (!REUSE) {
  try { fs.unlinkSync(path.join(here, 'mock-requests.json')); } catch {}
}
const mock = REUSE ? null : spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit', env: { ...process.env, MOCK_PORT: String(MOCK_PORT) } });
const cleanup = () => {
  try { mock?.kill(); } catch {}
  if (!KEEP) { try { compose('down', '-v'); } catch {} }
};
setTimeout(() => { console.log('E2E HARD TIMEOUT'); cleanup(); process.exit(3); }, 20 * 60 * 1000).unref();

async function step(name, params = {}, retry = true) {
  const qs = new URLSearchParams({ step: name, mock_port: String(MOCK_PORT), ...params }).toString();
  let r;
  try {
    r = await fetch(`${PS}/fetchsync-e2e/driver.php?${qs}`, { signal: AbortSignal.timeout(180000) });
  } catch (e) {
    // Apache may close an idle keep-alive socket just as it is reused.
    if (retry && e?.cause?.code === 'UND_ERR_SOCKET') return step(name, params, false);
    throw e;
  }
  const t = await r.text();
  try { return JSON.parse(t); } catch { throw new Error(`step ${name} returned non-JSON (${r.status}): ${t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500)}`); }
}
const seed = (order) => fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify(order) });
const sign = (body, t = Math.floor(Date.now() / 1000)) => `t=${t},v1=${crypto.createHmac('sha256', 'whsec_test').update(`${t}.${body}`).digest('hex')}`;
const webhookUrl = `${PS}/index.php?fc=module&module=fetchsync&controller=webhook`;
const postWebhook = (body, signature) => fetch(webhookUrl, { method: 'POST', headers: { 'content-type': 'application/json', ...(signature ? { 'X-Fetch-Signature': signature } : {}) }, body });
let cronToken = '';
const cron = (token = cronToken) => fetch(`${PS}/index.php?fc=module&module=fetchsync&controller=cron&token=${encodeURIComponent(token)}`, { signal: AbortSignal.timeout(180000) });
const event = (name, eventId, data) => JSON.stringify({ event: name, eventId, sentAt: new Date().toISOString(), data });

const baseOrder = {
  orderId: 'ord-1', orderNumber: '238400', storeId: 'store-1', status: 'DRAFT', placedAt: new Date().toISOString(), scheduledFor: null,
  deliveryType: 'delivery', paymentMethod: 'card', currency: 'EUR',
  customer: { name: 'Maria Georgiou', phone: '+35799123456', address: { line1: 'Makariou 12 Flat 3', city: 'Nicosia', postalCode: '1065', notes: 'Ring twice' } },
  items: [
    { listingId: 'lst-1', ref: 'CBL-001', name: 'USB-C Cable 1m', variantName: null, quantity: 2, unitPrice: '7.00', totalPrice: '14.00', vatRate: '0.19' },
    { listingId: 'lst-2', ref: null, name: 'Screen protector', variantName: null, quantity: 1, unitPrice: '5.00', totalPrice: '5.00', vatRate: '0.19' },
  ],
  subtotal: '19.00', shippingFee: '2.45', total: '21.45',
};

try {
  if (!REUSE) {
    compose('up', '-d');
    console.log('waiting for PrestaShop to install (first run takes a few minutes)…');
  }
  let up = false;
  for (let i = 0, streak = 0; i < 300 && !up; i++) {
    try { streak = (await step('ping')).up === true ? streak + 1 : 0; } catch { streak = 0; }
    up = streak >= 3; // stable for a few seconds, not mid-install
    if (!up) await sleep(3000);
  }
  ok('PrestaShop is up', up);
  const ping = await step('ping');
  console.log(`PrestaShop ${ping.ps}, PHP ${ping.php}`);

  // --- Install / uninstall --------------------------------------------------
  if (!REUSE) {
    // PrestaShop 8 installs modules it finds while installing itself; 1.7 does not.
    if (!ping.ready) await moduleAction('install');
    const via = await moduleAction('uninstall');
    console.log(`module install/uninstall via ${via}`);
    const gone = await step('install_state');
    ok('uninstall drops tables and settings, retires the carrier, keeps order states', gone.installed === false && gone.tables.length === 0 && gone.config_keys === 0 && gone.carriers_active === 0 && gone.states === 4, gone);
    await moduleAction('install');
  }
  const inst = await step('install_state');
  ok('install creates 6 tables, 4 order states, the carrier, the placeholder and a cron token', inst.installed && inst.tables.length === 6 && inst.states === 4 && inst.carriers_active === 1 && inst.placeholder > 0 && inst.cron_token_len === 32, inst);
  ok('install registers the hooks', ['actionUpdateQuantity', 'actionObjectProductUpdateAfter', 'actionObjectCombinationDeleteBefore', 'displayAdminOrderSide', 'actionEmailSendBefore', 'actionDispatcher'].every((h) => inst.hooks.includes(h)), inst.hooks);

  // --- Connect ---------------------------------------------------------------
  const cfg = await step('configure');
  ok('connect: token + GET /partner/stores', cfg.stores?.code === 200 && cfg.stores.body.data[0].id === 'store-1', cfg.stores);
  ok('register webhook returns the signing secret', cfg.webhook_register?.body?.signingSecret === 'whsec_test', cfg.webhook_register);
  cronToken = new URL(cfg.cron_url).searchParams.get('token');
  let log = mockLog();
  ok('token request is client_credentials', log.requests.some((r) => r.path === '/api/v1/oauth/token' && r.body?.grant_type === 'client_credentials' && r.body?.client_id === 'client-1'));
  ok('User-Agent identifies the module', log.requests.some((r) => /^FetchForPrestaShop\/1\.0\.0/.test(r.ua || '')), log.requests.at(-1)?.ua);

  // --- Products ---------------------------------------------------------------
  const prod = await step('create_products');
  ok('product + combination changes are queued', prod.pending?.includes(prod.simple_id) && prod.pending?.includes(prod.case_id), prod.pending);
  log = mockLog();
  let puts = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
  let all = puts.flatMap((r) => r.body.products);
  const simple = all.find((p) => p.ref === 'CBL-001');
  const black = all.find((p) => p.ref === 'CASE-BLACK');
  const white = all.find((p) => p.ref === 'CASE-WHITE');
  ok('simple product: reference as ref, VAT-incl price 9.50, sale 7.00, stock 10, active', simple && simple.productType === 'SIMPLE' && simple.price === '9.50' && simple.salePrice === '7.00' && simple.stockCount === 10 && simple.isActive === true, simple);
  ok('description sent as plain text', simple?.description === 'USB-C Cable 1m for e2e', simple?.description);
  ok('combinations sent as one SIMPLE product each (live Fetch refuses VARIABLE)',
    !all.some((p) => p.productType === 'VARIABLE' || p.ref === 'CASE')
    && black?.productType === 'SIMPLE' && black.stockCount === 4 && black.price === '12.00' && black.name === 'Phone Case – Black'
    && white?.productType === 'SIMPLE' && white.stockCount === 0, { black, white });
  ok('PUT payloads pass mock validation (live rules)', log.problems.length === 0, log.problems);
  ok('idempotency key sent', puts.every((r) => !!r.idem), puts.map((r) => r.idem));
  ok('result recorded (ref frozen, Fetch product id, synced) and queue emptied', prod.simple_meta?.ref === 'CBL-001' && prod.simple_meta?.fetch_product_id === 'prod-CBL-001' && prod.simple_meta?.status === 'synced' && prod.queue_after === 0, prod);

  let before = puts.length;
  const st = await step('change_stock');
  log = mockLog();
  puts = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
  let last = puts.at(-1)?.body.products.find((p) => p.ref === 'CBL-001');
  ok('stock change sends a new PUT with the new stock', puts.length === before + 1 && last?.stockCount === 6 && st.pending?.length === 1, { pending: st.pending, last });

  await fetch(`${MOCK}/__fail-next`, { method: 'POST', body: JSON.stringify({ status: 429, retryAfter: 2 }) });
  const rl = await step('rate_limited_change');
  ok('429: product stays queued, back-off honours Retry-After, error recorded', rl.first_sent === 0 && rl.queued_after_429 === 1 && rl.backoff >= 1 && rl.backoff <= 2 && /429/.test(rl.error_recorded || ''), rl);
  ok('after the back-off the queued change is sent', rl.second_sent === 1 && rl.queued_after_retry === 0, rl);

  // --- Price markup -------------------------------------------------------------
  before = mockLog().requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products')).length;
  const mk = await step('markup');
  ok('saving markup "10 %" stores 10 and queues the whole catalogue', mk.markup === 10 && mk.queued >= 3 && /price markup changed/i.test(mk.messages.join(' ')), mk);
  ok('custom URL trailing slash trimmed; token kept when only the markup changed', mk.custom_url === `http://host.docker.internal:${MOCK_PORT}` && mk.token_kept === true, mk);
  ok('saving the same markup again queues nothing', mk.queued_on_unchanged_save === 0, mk.queued_on_unchanged_save);
  log = mockLog();
  puts = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products')).slice(before);
  all = puts.flatMap((r) => r.body.products);
  const mSimple = all.find((p) => p.ref === 'CBL-001');
  const mBlack = all.find((p) => p.ref === 'CASE-BLACK');
  ok('markup 10%: 9.50 → 10.45, sale 7.00 → 7.70, 12.00 → 13.20; stock untouched', mSimple?.price === '10.45' && mSimple?.salePrice === '7.70' && mSimple?.stockCount === 6 && mBlack?.price === '13.20' && mBlack?.stockCount === 4, { mSimple, mBlack });
  ok('resync sent every queued product, in batches of at most 200', mk.queue_after === 0 && puts.every((r) => r.body.products.length <= 200) && log.problems.length === 0, { sent: mk.sent, batches: puts.map((r) => r.body.products.length), problems: log.problems });
  const refs = all.map((p) => p.ref);
  ok('combinations sharing one reference get distinct ps-<id>-<combination> refs', new Set(refs).size === refs.length && refs.some((r) => /^ps-\d+-\d+$/.test(r)), refs.slice(0, 12));

  // --- Cron endpoint -------------------------------------------------------------
  const badCron = await cron('wrong-token');
  ok('cron with a wrong token → 403', badCron.status === 403, badCron.status);

  // --- Orders: poll via the cron URL ------------------------------------------------
  await seed(baseOrder);
  let c = await cron();
  let cj = await c.json();
  ok('cron run polls orders', c.status === 200 && cj.ok === true && cj.orders_polled === true, cj);
  let o = await step('order', { order: 'ord-1' });
  const S = o.states;
  ok('Fetch order imported as a PrestaShop order in "waiting for you to accept"', o.order?.id > 0 && o.order.state === S.awaiting && o.order.fetch_status === 'DRAFT', o.order);
  ok('line matched by reference at the Fetch price (2 × 7.00)', o.items?.some((i) => i.product_id > 0 && i.reference === 'CBL-001' && i.qty === 2 && Math.abs(i.total - 14) < 0.001), o.items);
  ok('unmatched line kept by name, not linked to a product of the shop', o.items?.some((i) => i.name === 'Screen protector' && i.reference === '' && i.qty === 1 && Math.abs(i.total - 5) < 0.001) && o.items.length === 2, o.items);
  ok('delivery fee as shipping on the Fetch carrier; total = Fetch total 21.45', Math.abs(o.shipping - 2.45) < 0.001 && o.carrier === o.fetch_carrier && Math.abs(o.total - 21.45) < 0.001 && Math.abs(o.total_real - 21.45) < 0.001, { shipping: o.shipping, carrier: o.carrier, fetch: o.fetch_carrier, total: o.total, real: o.total_real, note: o.note });
  ok('no total correction was needed', !/Total set to the Fetch total/.test(o.note || ''), o.note);
  ok('guest customer with a placeholder e-mail at fetch.invalid', o.guest === 1 && /^order-[0-9a-f]{20}@fetch\.invalid$/.test(o.email) && o.customer === 'Maria Georgiou', { email: o.email, customer: o.customer });
  ok('delivery address, phone and note mapped', o.address === 'Makariou 12 Flat 3 / Nicosia / 1065 / +35799123456' && o.other === 'Ring twice' && /Customer note: Ring twice/.test(o.note), { address: o.address, other: o.other });
  ok('payment "Paid on Fetch" by fetchsync, transaction id = Fetch order id', o.payment === 'Paid on Fetch' && o.module === 'fetchsync' && o.transaction === 'ord-1', { payment: o.payment, transaction: o.transaction });
  ok('stock reduced on import (6 → 4); unmatched line reduces nothing', o.stock_cbl === 4 && o.placeholder_stock === 0, { stock: o.stock_cbl, placeholder: o.placeholder_stock });
  ok('unmatched items noted on the order', /match no PrestaShop product.*Screen protector/.test(o.note || ''), o.note);
  ok('order confirmation e-mail to the Fetch customer suppressed', o.mail_suppressed >= 1 && o.mail_rows_to_fetch_customers === 0, { suppressed: o.mail_suppressed, rows: o.mail_rows_to_fetch_customers });
  log = mockLog();
  last = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products')).at(-1)?.body.products.find((p) => p.ref === 'CBL-001');
  ok('import stock change flows back to Fetch (stock 4)', last?.stockCount === 4, last);

  c = await cron();
  o = await step('order', { order: 'ord-1' });
  ok('a second poll does not duplicate the order', o.count_by_transaction === 1, o.count_by_transaction);

  await seed({ ...baseOrder, orderId: 'ord-2', orderNumber: '238401', items: [baseOrder.items[1]], subtotal: '5.00', total: '7.45' });
  await cron();
  const o2 = await step('order', { order: 'ord-2' });
  ok('a second Fetch order becomes its own PrestaShop order', o2.order?.id > 0 && o2.order.id !== o.order.id && Math.abs(o2.total - 7.45) < 0.001, o2);

  const ck = await step('checkout_carriers');
  ok('the Fetch carrier is never offered at the shop\'s own checkout', ck.carriers.length > 0 && !ck.carriers.includes(ck.fetch_carrier), ck);

  // --- Order block + actions ------------------------------------------------------
  const box = await step('render_box', { order: 'ord-1' });
  ok('order block: Accept and Reject for a new order, no Ready', box.html.includes('value="accept"') && box.html.includes('value="reject"') && !box.html.includes('value="ready"') && box.html.includes('238400'), box.html.slice(0, 400));
  ok('order block is empty on a non-Fetch order', box.empty_for_other_order === '', box.empty_for_other_order);

  let act = await step('action', { order: 'ord-1', do: 'accept', prep: '25', again: '1' });
  log = mockLog();
  const acceptReq = log.requests.find((r) => r.path.endsWith('/orders/ord-1/accept'));
  ok('accept calls Fetch with prepMinutes', acceptReq?.body?.prepMinutes === 25, acceptReq);
  ok('accept → "Fetch: accepted"', act.error === null && act.order.state === act.states.accepted && act.order.fetch_status === 'ACCEPTED', act);
  ok('second accept refused by Fetch (409) and reported', typeof act.again_error === 'string' && act.again_error.includes('409'), act.again_error);

  act = await step('action', { order: 'ord-1', do: 'ready' });
  ok('ready calls Fetch and mirrors READY_FOR_PICKUP', act.error === null && act.order.fetch_status === 'READY_FOR_PICKUP' && act.order.state === act.states.accepted && mockLog().requests.some((r) => r.path.endsWith('/orders/ord-1/ready')), act);

  act = await step('action', { order: 'ord-2', do: 'reject', reason: 'OUT_OF_STOCK', note: 'Last one broken' });
  const rejectReq = mockLog().requests.find((r) => r.path.endsWith('/orders/ord-2/reject'));
  ok('reject sends reason + note and cancels the order', rejectReq?.body?.reason === 'OUT_OF_STOCK' && rejectReq?.body?.note === 'Last one broken' && act.order.state === act.states.canceled && act.order.fetch_status === 'REJECTED', { body: rejectReq?.body, act });

  // --- Webhooks ------------------------------------------------------------------------
  const ord3 = { ...baseOrder, orderId: 'ord-3', orderNumber: '238402', items: [{ listingId: 'lst-3', ref: 'CASE-BLACK', name: 'Phone Case', variantName: 'Black', quantity: 1, unitPrice: '13.20', totalPrice: '13.20', vatRate: '0.19' }], subtotal: '13.20', shippingFee: '2.50', total: '15.70' };
  const created = event('order.created', 'evt_created_3', { ...ord3, previousStatus: null });
  let r = await postWebhook(created, sign(created));
  ok('signed order.created webhook → 200', r.status === 200, await r.text());
  let o3 = await step('order', { order: 'ord-3' });
  ok('webhook created the order: combination matched at the Fetch price, stock 4 → 3', o3.order?.state === o3.states.awaiting && o3.items?.[0]?.ipa > 0 && Math.abs(o3.items[0].total - 13.2) < 0.001 && o3.stock_black === 3 && Math.abs(o3.total - 15.7) < 0.001, o3);
  r = await postWebhook(created, sign(created));
  const dup = await r.json();
  o3 = await step('order', { order: 'ord-3' });
  ok('replayed eventId acknowledged as duplicate, no second order', r.status === 200 && dup.duplicate === true && o3.count_by_transaction === 1, { dup, count: o3.count_by_transaction });

  const tampered = created.replace('evt_created_3', 'evt_x');
  r = await postWebhook(tampered, `t=${Math.floor(Date.now() / 1000)},v1=${'0'.repeat(64)}`);
  ok('badly signed webhook → 401', r.status === 401, r.status);
  r = await postWebhook(created);
  ok('unsigned webhook → 401', r.status === 401, r.status);
  r = await postWebhook(created, sign(created, Math.floor(Date.now() / 1000) - 600));
  ok('webhook signed 10 minutes ago → 401', r.status === 401, r.status);
  r = await fetch(webhookUrl);
  ok('GET on the webhook URL → 405', r.status === 405, r.status);

  // Fetch's own copy changes too, so later polls see the cancellation.
  await fetch(`${MOCK}/__set-status`, { method: 'POST', body: JSON.stringify({ orderId: 'ord-1', status: 'CANCELLED' }) });
  const cancel1 = event('order.cancelled', 'evt_cancel_1', { ...baseOrder, status: 'CANCELLED', previousStatus: 'READY_FOR_PICKUP' });
  r = await postWebhook(cancel1, sign(cancel1));
  o = await step('order', { order: 'ord-1' });
  ok('order.cancelled → PrestaShop Canceled, stock restored (4 → 6)', r.status === 200 && o.order.state === o.states.canceled && o.order.fetch_status === 'CANCELLED' && o.stock_cbl === 6, { status: r.status, order: o.order, stock: o.stock_cbl });

  const refund3 = event('order.status_changed', 'evt_refund_3', { ...ord3, status: 'REFUNDED', previousStatus: 'DRAFT' });
  r = await postWebhook(refund3, sign(refund3));
  o3 = await step('order', { order: 'ord-3' });
  ok('REFUNDED → "Fetch: refunded", stock restored (3 → 4)', o3.order.state === o3.states.refunded && o3.stock_black === 4, { order: o3.order, stock: o3.stock_black });
  const cancel3 = event('order.cancelled', 'evt_cancel_3', { ...ord3, status: 'CANCELLED', previousStatus: 'REFUNDED' });
  r = await postWebhook(cancel3, sign(cancel3));
  o3 = await step('order', { order: 'ord-3' });
  ok('cancel after refund does not restock twice (stock stays 4)', o3.order.fetch_status === 'CANCELLED' && o3.stock_black === 4 && o3.order.state === o3.states.refunded, { order: o3.order, stock: o3.stock_black });

  // --- Masked customer data ---------------------------------------------------------------
  const masked = { ...baseOrder, orderId: 'ord-4', orderNumber: '238403', placedAt: new Date(Date.now() - 3 * 3600e3).toISOString(), items: [baseOrder.items[0]], subtotal: '14.00', total: '16.45', customer: { name: '******', phone: '******', address: { line1: '******', city: '******', postalCode: '******', notes: '******' } } };
  await seed(masked);
  await cron();
  let o4 = await step('order', { order: 'ord-4' });
  ok('order first seen masked is still imported, with placeholder names', o4.order?.id > 0 && o4.customer === 'Fetch customer' && String(o4.row?.customer_masked) === '1' && o4.address.startsWith('Address not provided'), { customer: o4.customer, address: o4.address, row: o4.row });
  await seed({ ...masked, status: 'ACCEPTED', customer: baseOrder.customer });
  await cron();
  o4 = await step('order', { order: 'ord-4' });
  ok('a later unmasked copy completes the customer details', o4.customer === 'Maria Georgiou' && o4.address === 'Makariou 12 Flat 3 / Nicosia / 1065 / +35799123456' && String(o4.row?.customer_masked) === '0' && o4.order.state === o4.states.accepted, { customer: o4.customer, address: o4.address });
  await seed({ ...masked, status: 'DELIVERED' });
  await cron();
  o4 = await step('order', { order: 'ord-4' });
  ok('masked data never overwrites good data; DELIVERED → "Fetch: delivered"', o4.customer === 'Maria Georgiou' && o4.address.startsWith('Makariou 12') && o4.order.state === o4.states.delivered, { customer: o4.customer, state: o4.order });

  o = await step('order', { order: 'ord-1' });
  ok('later polls leave the cancelled order and its stock alone', o.order.state === o.states.canceled && o.stock_cbl === 4 /* 6 after the cancel, minus ord-4's 2 */, { order: o.order, stock: o.stock_cbl });

  // --- Auto-accept ----------------------------------------------------------------------------
  await seed({ ...baseOrder, orderId: 'ord-5', orderNumber: '238404', items: [baseOrder.items[0]], subtotal: '14.00', total: '16.45' });
  const aa = await step('auto_accept');
  const aaReq = mockLog().requests.find((x) => x.path.endsWith('/orders/ord-5/accept'));
  ok('auto-accept accepts new orders with the preparation time', aa.order?.state === aa.states.accepted && aaReq?.body?.prepMinutes === 20, { aa, body: aaReq?.body });

  // --- Delete ------------------------------------------------------------------------------
  const del = await step('delete_product');
  log = mockLog();
  const lamp = log.requests.filter((x) => x.method === 'PUT' && x.path.endsWith('/products')).flatMap((x) => x.body.products).filter((p) => p.ref === 'LAMP-TMP');
  ok('deleting a synced product switches it off on Fetch (full row, price included)', del.synced === true && del.deactivations_queued === 1 && del.deactivations_after === 0 && lamp.at(-1)?.isActive === false && lamp.at(-1)?.price === '22.00', { del, lamp: lamp.at(-1) });
  ok('all PUT payloads still pass mock validation', log.problems.length === 0, log.problems);
} catch (e) {
  console.error('E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((x) => x[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  if (KEEP) console.log(`PrestaShop left running at ${PS}`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
