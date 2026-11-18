// e2e multistore: one PrestaShop with three shops, each connected to its own mock Fetch store.
//
//   A  default shop, default group, localhost:<port>      client-1 → store-1
//   B  default group, its own domain 127.0.0.1:<port>      client-2 → store-2   (mug at 12.00, stock 3)
//   C  "Group 2" sharing stock, localhost:<port>/c/        client-2 → store-3   (group stock 7, markup 10%)
//
// B and C share one Partner key that covers store-2 and store-3. C is created without
// the module's data, so the module associates its carrier and placeholder lazily.
//
//   node tests/e2e/multistore.mjs       (same variables as run.mjs: PS_IMAGE, PS_PORT, MOCK_PORT,
//                                        COMPOSE_PROJECT_NAME, E2E_KEEP, E2E_SOURCE)
import { spawn, execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '../..');
const PORT = Number(process.env.PS_PORT || 8088);
const MOCK_PORT = Number(process.env.MOCK_PORT || 9099);
const MOCK = `http://127.0.0.1:${MOCK_PORT}`;
const MOCK_FOR_PS = `http://host.docker.internal:${MOCK_PORT}`;
const HOST_A = `localhost:${PORT}`;
const HOST_B = `127.0.0.1:${PORT}`;
const KEEP = !!process.env.E2E_KEEP;
const PROJECT = process.env.COMPOSE_PROJECT_NAME || 'fetchsync-e2e';
const results = [];
const ok = (label, cond, extra) => {
  results.push([cond ? 'PASS' : 'FAIL', label]);
  console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${!cond && extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 1200) : ''}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const LOG = path.join(here, `mock-requests-${PROJECT}.json`);
const mockLog = () => JSON.parse(fs.readFileSync(LOG, 'utf8'));
const composeEnv = { ...process.env, PS_PORT: String(PORT), COMPOSE_PROJECT_NAME: PROJECT };
const compose = (...args) => execFileSync('docker', ['compose', '-f', path.join(here, 'docker-compose.yml'), ...args], { cwd: here, env: composeEnv, stdio: ['ignore', 'pipe', 'pipe'] }).toString();

try { compose('down', '-v'); } catch {}
if (!process.env.E2E_SOURCE) {
  const zip = execFileSync(path.join(repo, 'build.sh'), { cwd: repo }).toString().trim();
  const out = path.join(repo, 'dist', `e2e-${PROJECT}`);
  fs.rmSync(out, { recursive: true, force: true });
  fs.mkdirSync(out, { recursive: true });
  execFileSync('unzip', ['-q', path.join(repo, zip), '-d', out]);
  composeEnv.MODULE_DIR = path.join(out, 'fetchsync');
  console.log(`testing ${zip}`);
}
try { fs.unlinkSync(LOG); } catch {}
const mock = spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit', env: { ...process.env, MOCK_PORT: String(MOCK_PORT), MOCK_LOG: LOG } });
// A mock already on the port (another run) would answer instead and log elsewhere.
mock.on('exit', (code) => { if (code) { console.error(`mock Fetch API exited (${code}); is port ${MOCK_PORT} in use?`); process.exit(4); } });
const cleanup = () => {
  mock.removeAllListeners('exit');
  try { mock.kill(); } catch {}
  if (!KEEP) { try { compose('down', '-v'); } catch {} }
};
setTimeout(() => { console.log('E2E HARD TIMEOUT'); cleanup(); process.exit(3); }, 25 * 60 * 1000).unref();

async function step(name, params = {}, retry = true) {
  const qs = new URLSearchParams({ step: name, mock_port: String(MOCK_PORT), ps_port: String(PORT), ...params }).toString();
  let r;
  try {
    r = await fetch(`http://${HOST_A}/fetchsync-e2e/driver.php?${qs}`, { signal: AbortSignal.timeout(240000) });
  } catch (e) {
    if (retry && e?.cause?.code === 'UND_ERR_SOCKET') return step(name, params, false);
    throw e;
  }
  const t = await r.text();
  try { return JSON.parse(t); } catch { throw new Error(`step ${name} returned non-JSON (${r.status}): ${t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500)}`); }
}
const save = (shop, fields) => step('ms_save', { shop, fields: JSON.stringify(fields) });
const seed = (order) => fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify(order) });
const sign = (body, secret, t = Math.floor(Date.now() / 1000)) => `t=${t},v1=${crypto.createHmac('sha256', secret).update(`${t}.${body}`).digest('hex')}`;
const postWebhook = (host, body, signature) => fetch(`http://${host}/index.php?fc=module&module=fetchsync&controller=webhook`, { method: 'POST', headers: { 'content-type': 'application/json', ...(signature ? { 'X-Fetch-Signature': signature } : {}) }, body });
const event = (name, eventId, data) => JSON.stringify({ event: name, eventId, sentAt: new Date().toISOString(), data });
let cronToken = '';
const cron = async () => { const r = await fetch(`http://${HOST_A}/index.php?fc=module&module=fetchsync&controller=cron&token=${encodeURIComponent(cronToken)}`, { signal: AbortSignal.timeout(240000) }); return { status: r.status, body: await r.json().catch(() => null) }; };
const mark = () => mockLog().requests.length;
const since = (n) => mockLog().requests.slice(n);
const putsTo = (reqs, store) => reqs.filter((r) => r.method === 'PUT' && r.path === `/api/v1/partner/stores/${store}/products`);
const lastItem = (reqs, store, ref) => putsTo(reqs, store).flatMap((r) => r.body.products).filter((p) => p.ref === ref).at(-1);
const listings = async (store) => (await (await fetch(`${MOCK}/__listings?store=${store}`)).json()).data;
const order = (orderId, storeId, ref, qty, unit) => ({
  orderId, orderNumber: `N-${orderId}`, storeId, status: 'DRAFT', placedAt: new Date().toISOString(), deliveryType: 'delivery', paymentMethod: 'card', currency: 'EUR',
  customer: { name: 'Eleni Christou', phone: '+35799000000', address: { line1: 'Ledras 1', city: 'Nicosia', postalCode: '1011', notes: '' } },
  items: [{ listingId: 'x', ref, name: 'Shared Mug', variantName: null, quantity: qty, unitPrice: unit, totalPrice: (Number(unit) * qty).toFixed(2), vatRate: '0.19' }],
  subtotal: (Number(unit) * qty).toFixed(2), shippingFee: '2.00', total: (Number(unit) * qty + 2).toFixed(2),
});

try {
  compose('up', '-d');
  console.log('waiting for PrestaShop to install…');
  let up = false;
  for (let i = 0, streak = 0; i < 300 && !up; i++) {
    try { streak = (await step('ping')).up === true ? streak + 1 : 0; } catch { streak = 0; }
    up = streak >= 3;
    if (!up) await sleep(3000);
  }
  ok('PrestaShop is up', up);
  let ping = await step('ping');
  if (!ping.ready) {
    const r = await step('module', { do: 'install' });
    if (!r.ok) throw new Error(`install failed: ${JSON.stringify(r.errors)}`);
    ping = await step('ping');
  }
  console.log(`PrestaShop ${ping.ps}, PHP ${ping.php}`);

  // --- Three shops ---------------------------------------------------------------------
  const setup = await step('ms_setup');
  ok('setup: shops B and C created next to the default shop A', setup.shops?.B > 0 && setup.shops?.C > 0 && setup.shops.A !== setup.shops.B, setup.shops);
  ok('a shop copied with the module\'s data gets the carrier, the placeholder and the hooks (actionShopDataDuplication)', setup.assoc_b?.module && setup.assoc_b.carrier && setup.assoc_b.carrier_lang && setup.assoc_b.placeholder && setup.assoc_b.hooks >= 15, setup.assoc_b);
  ok('a shop created without the module\'s data has no Fetch carrier yet', setup.assoc_c_before?.carrier === false && setup.assoc_c_before.module === false, setup.assoc_c_before);
  let st = await step('ms_state');
  cronToken = new URL(st.cron_url).searchParams.get('token');
  ok('multistore on: shop A keeps the settings it had as the only shop', st.multistore && st.settings.A.connected && st.settings.A.store_id === 'store-1' && st.settings.A.webhook_secret_hint === 'whsec_test', st.settings.A);
  ok('…and shops B and C do not inherit them', !st.settings.B.connected && st.settings.B.store_id === '' && st.settings.B.client_id === '' && !st.settings.C.connected && st.settings.C.client_id === '', { B: st.settings.B, C: st.settings.C });
  ok('only shop A is connected; the cron token is unchanged', JSON.stringify(st.connected) === JSON.stringify([st.shops.A]) && st.cron_token === setup.cron_token, { connected: st.connected });
  const adopt = await step('ms_adopt');
  st = await step('ms_state');
  ok('the default shop\'s global settings are copied into its own rows once', adopt.adopted === true && st.legacy_adopted === st.shops.A && st.a_has_own_rows && st.settings.A.connected && st.settings.A.store_id === 'store-1', { adopt, st: st.settings.A });

  // --- Per-shop settings -----------------------------------------------------------------
  const base = { fetchsync_environment: 'custom', fetchsync_custom_base_url: MOCK_FOR_PS };
  let s = await save('B', { ...base, submitFetchsyncConnect: '1', fetchsync_client_id: 'client-2', fetchsync_client_secret: 'secret-2' });
  ok('shop B connects with the shared key and picks store-2 of its 2 stores', s.settings.connected && s.settings.store_id === 'store-2' && s.settings.store_name === 'Mock Shop B' && s.messages.some((m) => /2 stores found/.test(m)), s);
  s = await save('C', { ...base, submitFetchsyncConnect: '1', fetchsync_client_id: 'client-2', fetchsync_client_secret: 'secret-2' });
  ok('shop C, same key: store-2 is taken by B, so C gets store-3', s.settings.connected && s.settings.store_id === 'store-3', s);
  ok('saving shop C associates the module, its hooks, the carrier and the placeholder with it (lazily)', s.assoc.module && s.assoc.carrier && s.assoc.carrier_lang && s.assoc.placeholder && s.assoc.hooks >= 15, s.assoc);
  s = await save('C', { submitFetchsyncSave: '1', fetchsync_store_id: 'store-2' });
  ok('two shops cannot pick the same Fetch store: C stays on store-3', s.settings.store_id === 'store-3' && s.messages.some((m) => /already connected to the shop "Shop B"/.test(m)), s);
  s = await save('C', { submitFetchsyncSave: '1', fetchsync_price_markup: '10' });
  ok('shop C has its own price markup (10%); A and B keep 0', s.settings.price_markup === '10', s.settings);
  st = await step('ms_state');
  ok('A, B and C are connected, each to its own store', st.connected.length === 3 && ['store-1', 'store-2', 'store-3'].every((id, i) => st.settings[['A', 'B', 'C'][i]].store_id === id) && st.settings.A.price_markup === '0' && st.settings.B.price_markup === '0', st.settings);

  // Webhooks: one per key at Fetch. B registers (secret #1), then C on the same key (secret #2, which B gets too).
  let m0 = mark();
  s = await save('B', { submitFetchsyncWebhook: '1' });
  const whB1 = (await (await fetch(`${MOCK}/__webhooks`)).json())['client-2'];
  ok('shop B registers its webhook on its own domain', s.messages.some((m) => /Webhook registered/.test(m)) && whB1?.url?.startsWith(`https://${HOST_B}/`) && s.settings.webhook_secret_hint === 'whsec_two_', { s, whB1 });
  s = await save('C', { submitFetchsyncWebhook: '1' });
  const whC = (await (await fetch(`${MOCK}/__webhooks`)).json())['client-2'];
  st = await step('ms_state');
  ok('shop C registers with the same key: B gets the new secret too, A keeps its own', whC.count === 2 && s.messages.some((m) => /Shop B use the same key/.test(m)) && st.settings.B.webhook_registered === st.settings.C.webhook_registered && st.settings.A.webhook_secret_hint === 'whsec_test', { messages: s.messages, B: st.settings.B, C: st.settings.C });
  ok('webhook registration used the right key', since(m0).filter((r) => r.path === '/api/v1/partner/webhook').every((r) => r.auth === 'Bearer tok-2'), since(m0).map((r) => r.auth));

  // --- Products: one change, three stores, three prices and stocks --------------------------
  m0 = mark();
  const touch = await step('ms_touch', { ref: 'MUG-1' });
  ok('a product change is queued for every connected shop that sells it', touch.queued.A && touch.queued.B && touch.queued.C, touch.queued);
  let c = await cron();
  ok('one cron run sends every shop\'s queue', c.status === 200 && c.body.ok && Object.keys(c.body.shops || {}).length === 3 && c.body.orders_polled === true, c.body);
  let reqs = since(m0);
  const mugA = lastItem(reqs, 'store-1', 'MUG-1');
  const mugB = lastItem(reqs, 'store-2', 'MUG-1');
  const mugC = lastItem(reqs, 'store-3', 'MUG-1');
  ok('store-1 gets shop A\'s price and stock (10.00, 5)', mugA?.price === '10.00' && mugA.stockCount === 5 && mugA.isActive === true, mugA);
  ok('store-2 gets shop B\'s own price and stock (12.00, 3)', mugB?.price === '12.00' && mugB.stockCount === 3 && mugB.isActive === true, mugB);
  ok('store-3 gets the stock shop C\'s group shares (7) and C\'s 10% markup (11.00)', mugC?.price === '11.00' && mugC.stockCount === 7, mugC);
  ok('each store is reached with its own key; refs repeat across stores', putsTo(reqs, 'store-1').every((r) => r.auth === 'Bearer tok-1') && putsTo(reqs, 'store-2').every((r) => r.auth === 'Bearer tok-2') && mugA.ref === mugB.ref && mockLog().problems.length === 0, { problems: mockLog().problems });

  // --- Orders ------------------------------------------------------------------------------------
  await seed(order('ms-ord-b1', 'store-2', 'MUG-1', 1, '12.00'));
  m0 = mark();
  c = await cron();
  let ob = await step('ms_order', { order: 'ms-ord-b1' });
  ok('an order for store-2 lands in shop B (shop, group, currency, language, customer)', ob.id_order > 0 && ob.id_shop === st.shops.B && ob.customer_shop === st.shops.B && ob.currency === 'EUR' && ob.id_lang === ob.shop_lang.B && Number(ob.row?.id_shop) === st.shops.B && ob.row?.fetch_store_id === 'store-2', ob);
  ok('…matched to the mug at the Fetch price, on the Fetch carrier', ob.items?.[0]?.reference === 'MUG-1' && Math.abs(ob.items[0].total - 12) < 0.001 && ob.carrier === ob.fetch_carrier && ob.state === ob.states.awaiting, ob.items);
  let stock = await step('ms_stock', { ref: 'MUG-1' });
  ok('shop B\'s stock goes 3 → 2; A and C are untouched', stock.stock.B === 2 && stock.stock.A === 5 && stock.stock.C === 7, stock.stock);
  c = await cron();
  reqs = since(m0);
  ok('the stock change reaches store-2 only as 2', lastItem(reqs, 'store-2', 'MUG-1')?.stockCount === 2 && (lastItem(reqs, 'store-1', 'MUG-1')?.stockCount ?? 5) === 5, { b: lastItem(reqs, 'store-2', 'MUG-1'), a: lastItem(reqs, 'store-1', 'MUG-1') });
  ok('orders were polled per store with each store\'s key', reqs.some((r) => r.path === '/api/v1/partner/stores/store-2/orders' && r.auth === 'Bearer tok-2') && reqs.some((r) => r.path === '/api/v1/partner/stores/store-1/orders' && r.auth === 'Bearer tok-1') && !reqs.some((r) => r.store && r.auth === 'Bearer tok-1' && r.store !== 'store-1'), reqs.filter((r) => r.path.endsWith('/orders')).map((r) => [r.path, r.auth]));
  c = await cron();
  ob = await step('ms_order', { order: 'ms-ord-b1' });
  ok('polling again does not import the order twice', ob.count_by_transaction === 1, ob.count_by_transaction);
  m0 = mark();
  const act = await step('ms_action', { order: 'ms-ord-b1', do: 'accept' });
  const acc = since(m0).find((r) => r.path.endsWith('/accept'));
  ok('accepting shop B\'s order calls store-2 with B\'s key', act.error === null && acc?.path === '/api/v1/partner/stores/store-2/orders/ms-ord-b1/accept' && acc.auth === 'Bearer tok-2' && act.row.fetch_status === 'ACCEPTED', { act, acc });

  // --- Webhooks: routed by storeId, verified with that shop's secret ----------------------------------
  const ordB2 = order('ms-ord-b2', 'store-2', 'MUG-1', 1, '12.00');
  const evB2 = event('order.created', 'evt-ms-b2', ordB2);
  let r = await postWebhook(HOST_B, evB2, sign(evB2, 'whsec_test'));
  ok('a webhook for store-2 signed with shop A\'s secret → 401', r.status === 401, r.status);
  r = await postWebhook(HOST_B, evB2, sign(evB2, 'whsec_two_1'));
  ok('…signed with B\'s old (rotated) secret → 401', r.status === 401, r.status);
  r = await postWebhook(HOST_B, evB2, sign(evB2, 'whsec_two_2'));
  let body = await r.json();
  ob = await step('ms_order', { order: 'ms-ord-b2' });
  ok('…signed with B\'s secret, on B\'s domain → 200, order in shop B', r.status === 200 && body.shop === st.shops.B && ob.id_shop === st.shops.B, { body, ob });
  const ordC = order('ms-ord-c1', 'store-3', 'MUG-1', 2, '11.00');
  const evC = event('order.created', 'evt-ms-c1', ordC);
  r = await postWebhook(HOST_A, evC, sign(evC, 'whsec_two_2'));
  body = await r.json();
  let oc = await step('ms_order', { order: 'ms-ord-c1' });
  stock = await step('ms_stock', { ref: 'MUG-1' });
  ok('a webhook for store-3 posted to shop A\'s domain lands in shop C (shared domain)', r.status === 200 && body.shop === st.shops.C && oc.id_shop === st.shops.C && oc.customer_shop === st.shops.C, { body, oc });
  ok('shop C\'s order takes the group\'s shared stock (7 → 5)', stock.stock.C === 5 && stock.stock.A === 5 && stock.stock.B === 1, stock.stock);
  const evA = event('order.created', 'evt-ms-a1', order('ms-ord-a1', 'store-1', 'MUG-1', 1, '10.00'));
  r = await postWebhook(HOST_B, evA, sign(evA, 'whsec_two_2'));
  ok('a webhook for store-1 signed with another shop\'s secret → 401', r.status === 401, r.status);
  r = await postWebhook(HOST_B, evA, sign(evA, 'whsec_test'));
  let oa = await step('ms_order', { order: 'ms-ord-a1' });
  ok('…with shop A\'s secret, even on B\'s domain → order in shop A', r.status === 200 && oa.id_shop === st.shops.A, oa);
  const evX = event('order.created', 'evt-ms-x', order('ms-ord-x', 'store-9', 'MUG-1', 1, '10.00'));
  r = await postWebhook(HOST_A, evX, sign(evX, 'whsec_test'));
  body = await r.json();
  const ox = await step('ms_order', { order: 'ms-ord-x' });
  ok('a signed webhook for a store no shop is connected to is acknowledged and ignored', r.status === 200 && body.ignored && ox.id_order === 0, body);

  // --- Mirror on shop A leaves store-2 alone ------------------------------------------------------------
  await fetch(`${MOCK}/__seed-listings`, { method: 'POST', body: JSON.stringify({ store: 'store-1', listings: [{ ref: 'FOREIGN-A', name: 'Only on store-1', price: '4.00', isActive: true }] }) });
  await fetch(`${MOCK}/__seed-listings`, { method: 'POST', body: JSON.stringify({ store: 'store-2', listings: [{ ref: 'FOREIGN-B', name: 'Only on store-2', price: '4.00', isActive: true }] }) });
  s = await save('A', { submitFetchsyncSave: '1', fetchsync_catalogue_mode: 'mirror' });
  ok('mirror mode is a per-shop setting', s.settings.catalogue_mode === 'mirror' && (await step('ms_state')).settings.B.catalogue_mode === 'add_update', s.settings);
  m0 = mark();
  const fsA = await step('ms_full_sync', { shop: 'A' });
  reqs = since(m0);
  const l1 = await listings('store-1');
  const l2 = await listings('store-2');
  ok('mirror on shop A switches off what A does not sell in store-1', fsA.finished && l1.find((l) => l.ref === 'FOREIGN-A')?.isActive === false && l1.find((l) => l.ref === 'MUG-1')?.isActive === true && fsA.activity.some((a) => /^Mirror: scanned/.test(a)), { activity: fsA.activity });
  ok('…and does not read, push to or switch off anything in store-2', l2.find((l) => l.ref === 'FOREIGN-B')?.isActive === true && !reqs.some((r) => r.store && r.store !== 'store-1'), reqs.filter((r) => r.store && r.store !== 'store-1').map((r) => `${r.method} ${r.path}`));
  ok('shop A\'s full sync sends shop A\'s catalogue only', putsTo(reqs, 'store-1').length > 0 && putsTo(reqs, 'store-2').length === 0 && putsTo(reqs, 'store-3').length === 0, putsTo(reqs, 'store-1').length);

  // --- Cron isolation: shop B's key fails, A and C carry on --------------------------------------------
  await step('ms_break_key', { to: 'bad' });
  m0 = mark();
  await step('ms_touch', { ref: 'MUG-1' });
  await seed(order('ms-ord-c2', 'store-3', 'MUG-1', 1, '11.00'));
  c = await cron();
  reqs = since(m0);
  oc = await step('ms_order', { order: 'ms-ord-c2' });
  ok('with shop B\'s key failing, the cron still answers 200', c.status === 200 && c.body.ok === true, c);
  ok('…and still syncs shops A and C and imports C\'s order', putsTo(reqs, 'store-1').length > 0 && putsTo(reqs, 'store-3').length > 0 && oc.id_shop === st.shops.C, { a: putsTo(reqs, 'store-1').length, c: putsTo(reqs, 'store-3').length, oc: oc.id_shop });
  st = await step('ms_state');
  ok('shop B keeps its change queued and logs its own error', st.pending.B >= 1 && st.pending.A === 0 && st.activity.some((a) => /Authentication failed|invalid_client/.test(a)), { pending: st.pending, activity: st.activity });
  await step('ms_break_key', { to: 'good' });
  m0 = mark();
  c = await cron();
  st = await step('ms_state');
  ok('once B\'s key works again, its queue is sent', st.pending.B === 0 && putsTo(since(m0), 'store-2').length > 0, { pending: st.pending });

  // --- Overview -------------------------------------------------------------------------------------------
  const ov = await step('ms_overview');
  const rowOf = (id) => ov.rows[id] || [];
  ok('"All shops" shows an overview, not a settings form', ov.context_shop === null && Object.keys(ov.rows).length === 3 && ov.edit_links === 3, ov);
  ok('the overview lists each shop with its Fetch store, last sync and status', rowOf(st.shops.A)[1]?.includes('Mock Shop') && rowOf(st.shops.B)[1]?.includes('Mock Shop B') && rowOf(st.shops.C)[1]?.includes('Mock Shop C') && [st.shops.A, st.shops.B, st.shops.C].every((id) => /^\d{4}-\d\d-\d\d/.test(rowOf(id)[3] || '') && rowOf(id)[6] === 'Connected'), ov.rows);
  ok('every request passed the mock\'s live rules', mockLog().problems.length === 0, mockLog().problems);
} catch (e) {
  console.error('E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((x) => x[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  if (KEEP) console.log(`PrestaShop left running at http://${HOST_A}`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
