// e2e upgrade: install 1.2.0 (git tag v1.2.0), connect and use it, put this tree's files
// in place, run PrestaShop's module upgrade, and check the shop is still connected and
// syncing with nothing reconfigured. Then turn multistore on and add a shop.
//
//   node tests/e2e/upgrade.mjs          (PS_IMAGE, PS_PORT, MOCK_PORT, COMPOSE_PROJECT_NAME, E2E_KEEP,
//                                        FROM_REF=v1.2.0)
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
const KEEP = !!process.env.E2E_KEEP;
const PROJECT = process.env.COMPOSE_PROJECT_NAME || 'fetchsync-e2e';
const FROM_REF = process.env.FROM_REF || 'v1.2.0';
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

// The mounted folder starts as the old release and is overwritten in place (a bind mount
// whose folder is replaced goes stale).
const work = path.join(repo, 'dist', `e2e-${PROJECT}`);
const mounted = path.join(work, 'fetchsync');
const fresh = path.join(work, 'new');
try { compose('down', '-v'); } catch {}
fs.rmSync(work, { recursive: true, force: true });
fs.mkdirSync(work, { recursive: true });
execFileSync('sh', ['-c', `git -C "${repo}" archive "${FROM_REF}" fetchsync | tar -x -C "${work}"`]);
const oldVersion = fs.readFileSync(path.join(mounted, 'config.xml'), 'utf8').match(/<version><!\[CDATA\[([^\]]+)\]\]>/)[1];
const zip = execFileSync(path.join(repo, 'build.sh'), { cwd: repo }).toString().trim();
fs.mkdirSync(fresh, { recursive: true });
execFileSync('unzip', ['-q', path.join(repo, zip), '-d', fresh]);
composeEnv.MODULE_DIR = mounted;
console.log(`upgrading ${FROM_REF} (${oldVersion}) → ${zip}`);

try { fs.unlinkSync(LOG); } catch {}
const mock = spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit', env: { ...process.env, MOCK_PORT: String(MOCK_PORT), MOCK_LOG: LOG } });
// A mock already on the port (another run) would answer instead and log elsewhere.
mock.on('exit', (code) => { if (code) { console.error(`mock Fetch API exited (${code}); is port ${MOCK_PORT} in use?`); process.exit(4); } });
const cleanup = () => {
  mock.removeAllListeners('exit');
  try { mock.kill(); } catch {}
  if (!KEEP) { try { compose('down', '-v'); } catch {} }
};
setTimeout(() => { console.log('E2E HARD TIMEOUT'); cleanup(); process.exit(3); }, 20 * 60 * 1000).unref();

async function step(name, params = {}, retry = true) {
  const qs = new URLSearchParams({ step: name, mock_port: String(MOCK_PORT), ps_port: String(PORT), ...params }).toString();
  let r;
  try {
    r = await fetch(`${PS}/fetchsync-e2e/driver.php?${qs}`, { signal: AbortSignal.timeout(240000) });
  } catch (e) {
    if (retry && e?.cause?.code === 'UND_ERR_SOCKET') return step(name, params, false);
    throw e;
  }
  const t = await r.text();
  try { return JSON.parse(t); } catch { throw new Error(`step ${name} returned non-JSON (${r.status}): ${t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500)}`); }
}
const seed = (order) => fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify(order) });
const sign = (body, t = Math.floor(Date.now() / 1000)) => `t=${t},v1=${crypto.createHmac('sha256', 'whsec_test').update(`${t}.${body}`).digest('hex')}`;
const postWebhook = (body, signature) => fetch(`${PS}/index.php?fc=module&module=fetchsync&controller=webhook`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Fetch-Signature': signature }, body });
let cronToken = '';
const cron = async () => { const r = await fetch(`${PS}/index.php?fc=module&module=fetchsync&controller=cron&token=${encodeURIComponent(cronToken)}`, { signal: AbortSignal.timeout(240000) }); return { status: r.status, body: await r.json().catch(() => null) }; };
const mark = () => mockLog().requests.length;
const puts = (n) => mockLog().requests.slice(n).filter((r) => r.method === 'PUT' && r.path === '/api/v1/partner/stores/store-1/products');
const order = (orderId, ref) => ({
  orderId, orderNumber: `U-${orderId}`, storeId: 'store-1', status: 'DRAFT', placedAt: new Date().toISOString(), deliveryType: 'delivery', paymentMethod: 'card', currency: 'EUR',
  customer: { name: 'Maria Georgiou', phone: '+35799123456', address: { line1: 'Makariou 12', city: 'Nicosia', postalCode: '1065', notes: '' } },
  items: [{ listingId: 'x', ref, name: 'USB-C Cable 1m', variantName: null, quantity: 1, unitPrice: '7.00', totalPrice: '7.00', vatRate: '0.19' }],
  subtotal: '7.00', shippingFee: '2.00', total: '9.00',
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
  }
  console.log(`PrestaShop ${ping.ps}, PHP ${ping.php}`);

  // --- The old release, connected and in use ------------------------------------------
  const cfg = await step('configure');
  cronToken = new URL(cfg.cron_url).searchParams.get('token');
  ok(`${oldVersion}: connected to store-1 with a webhook secret`, cfg.connected === true && cfg.webhook_register?.body?.signingSecret === 'whsec_test', cfg);
  ok(`${oldVersion}: User-Agent says ${oldVersion}`, mockLog().requests.some((r) => (r.ua || '').startsWith(`FetchForPrestaShop/${oldVersion}`)));
  const prod = await step('create_products');
  ok(`${oldVersion}: products synced`, prod.simple_meta?.status === 'synced' && prod.queue_after === 0, prod);
  await seed(order('up-1', 'CBL-001'));
  let c = await cron();
  let o = await step('order', { order: 'up-1' });
  ok(`${oldVersion}: an order is imported`, c.status === 200 && o.order?.id > 0, { c, o: o.order });
  const before = await step('state');

  // --- New files, then PrestaShop's upgrade ------------------------------------------------
  execFileSync('rsync', ['-a', '--delete', `${path.join(fresh, 'fetchsync')}/`, `${mounted}/`]);
  await sleep(3000); // opcache revalidation
  const up1 = await step('upgrade');
  ok(`upgrade ${oldVersion} → 1.3.0 runs upgrade-1.3.0.php and succeeds`, up1.from === oldVersion && up1.needed === true && up1.success === true && up1.upgraded_to === '1.3.0' && up1.version === '1.3.0' && up1.active === true, up1);
  const schema = await step('schema');
  ok('the sync, queue, order and log tables have id_shop, in the new keys', Object.values(schema.id_shop).every(Boolean) && schema.keys.fetchsync_queue.join() === 'id_shop,id_product' && schema.keys.fetchsync_product.join() === 'id_shop,id_product,id_product_attribute' && schema.keys.fetchsync_deactivation.join() === 'id_shop,ref', schema.keys);
  ok('existing sync and order rows belong to the default shop', schema.product_rows.length > 0 && schema.product_rows.every((r) => Number(r.id_shop) === 1) && schema.order_rows.length === 1 && Number(schema.order_rows[0].id_shop) === 1, { products: schema.product_rows.slice(0, 3), orders: schema.order_rows });
  ok('the module listens for new shops (actionShopDataDuplication)', schema.hooked === true, schema.hooked);
  const after = await step('state');
  const same = (k) => JSON.stringify(after.settings[k]) === JSON.stringify(before.settings[k]);
  ok('every setting is kept: connection, store, options, webhook date and cron token', ['environment', 'custom_base_url', 'client_id', 'store_id', 'store_name', 'sync_products', 'import_orders', 'auto_accept', 'prep_minutes', 'price_markup', 'catalogue_mode', 'webhook_registered', 'cron_token'].every(same), { before: before.settings, after: after.settings });

  // --- Still connected and syncing, with zero reconfiguration --------------------------------
  let m0 = mark();
  const stock = await step('change_stock');
  const last = puts(m0).at(-1)?.body.products.find((p) => p.ref === 'CBL-001');
  ok('1.3.0: a stock change still reaches store-1', stock.sent === 1 && last?.stockCount === 6, { stock, last });
  ok('1.3.0: User-Agent says 1.3.0', mockLog().requests.slice(m0).every((r) => (r.ua || '').startsWith('FetchForPrestaShop/1.3.0')));
  c = await cron();
  o = await step('order', { order: 'up-1' });
  ok('1.3.0: the order imported before the upgrade is not imported again', c.status === 200 && c.body.orders_polled === true && o.count_by_transaction === 1, { c: c.body, count: o.count_by_transaction });
  const ev = JSON.stringify({ event: 'order.created', eventId: 'evt-up-2', sentAt: new Date().toISOString(), data: order('up-2', 'CBL-001') });
  const wr = await postWebhook(ev, sign(ev));
  o = await step('ms_order', { order: 'up-2' });
  ok('1.3.0: a webhook signed with the old secret is accepted and lands in the default shop', wr.status === 200 && o.id_order > 0 && o.id_shop === 1 && Number(o.row?.id_shop) === 1, { status: wr.status, o });

  // --- Multistore turned on afterwards ------------------------------------------------------
  const add = await step('ms_add_shop');
  let ms = await step('ms_state');
  ok('multistore turned on: the default shop is still connected', ms.multistore && ms.settings.A.connected && ms.settings.A.store_id === 'store-1', ms.settings.A);
  ok('…and the new shop does not inherit its Fetch store', add.B > 0 && ms.settings.B && !ms.settings.B.connected && ms.settings.B.store_id === '' && JSON.stringify(ms.connected) === '[1]', ms.settings.B);
  m0 = mark();
  c = await cron();
  ms = await step('ms_state');
  ok('the first run copies the default shop\'s settings into its own rows', ms.legacy_adopted === 1 && ms.a_has_own_rows && ms.settings.A.connected, ms);
  const st2 = await step('change_stock');
  ok('…and the default shop keeps syncing', st2.sent === 1 && puts(m0).length > 0, st2);
  ok('every request passed the mock\'s live rules', mockLog().problems.length === 0, mockLog().problems);
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
