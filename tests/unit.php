<?php
/**
 * Standalone unit tests (no PrestaShop): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules, the price
 * markup maths, the server setting, request batching and the mirror mode's
 * scan / plan / guard / switch-off logic against a fake Partner API. The
 * webhook, status, markup and mirror cases are the same as the WooCommerce
 * plugin's.
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

function pSQL($string, $htmlOk = false)
{
    return addslashes((string) $string);
}

/** Just enough of PrestaShop's Db, Tools and logger for FetchSyncLogger; messages land in Db::$logged. */
class Db
{
    public static $logged = array();
    private static $instance;

    public static function getInstance()
    {
        return self::$instance ? self::$instance : (self::$instance = new self());
    }

    public function insert($table, array $data)
    {
        self::$logged[] = $data['level'] . ': ' . stripslashes($data['message']);

        return true;
    }

    public function Insert_ID()
    {
        return count(self::$logged);
    }

    public function delete($table, $where)
    {
        return true;
    }
}

class Tools
{
    public static function substr($string, $start, $length = null)
    {
        return null === $length ? substr($string, $start) : substr($string, $start, $length);
    }
}

class PrestaShopLogger
{
    public static function addLog()
    {
        return true;
    }
}

/** Just enough of PrestaShop's Configuration for FetchSyncSettings. */
class Configuration
{
    public static $values = array();

    public static function getGlobalValue($key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }

    public static function updateGlobalValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function get($key)
    {
        return self::getGlobalValue($key);
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }
}

$dir = __DIR__ . '/../fetchsync/classes/';
require $dir . 'FetchSyncSettings.php';
require $dir . 'FetchSyncOrderMapper.php';
require $dir . 'FetchSyncWebhook.php';
require $dir . 'FetchSyncProductMapper.php';
require $dir . 'FetchSyncProductSync.php';
require $dir . 'FetchSyncLogger.php';
require $dir . 'FetchSyncApi.php';
require $dir . 'FetchSyncMirror.php';

$failures = 0;
$passes = 0;
function check($label, $actual, $expected)
{
    global $failures, $passes;
    if ($actual === $expected) {
        ++$passes;

        return;
    }
    ++$failures;
    echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

// --- Webhook signature -------------------------------------------------------
$secret = 'whsec_test';
$body = '{"event":"order.created","eventId":"evt_1","data":{"orderId":"o1"}}';
$now = 1793260000;
$sign = function ($t, $b, $s) {
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $b, $s);
};
check('valid signature', FetchSyncWebhook::verify($sign($now, $body, $secret), $body, $secret, $now), true);
check('valid within tolerance', FetchSyncWebhook::verify($sign($now - 299, $body, $secret), $body, $secret, $now), true);
check('tampered body', FetchSyncWebhook::verify($sign($now, $body, $secret), $body . ' ', $secret, $now), false);
check('wrong secret', FetchSyncWebhook::verify($sign($now, $body, 'other'), $body, $secret, $now), false);
check('stale timestamp', FetchSyncWebhook::verify($sign($now - 301, $body, $secret), $body, $secret, $now), false);
check('future timestamp beyond tolerance', FetchSyncWebhook::verify($sign($now + 301, $body, $secret), $body, $secret, $now), false);
check('missing header', FetchSyncWebhook::verify('', $body, $secret, $now), false);
check('garbage header', FetchSyncWebhook::verify('nonsense', $body, $secret, $now), false);
check('second v1 matches', FetchSyncWebhook::verify('t=' . $now . ',v1=deadbeef,v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $body, $secret, $now), true);
check('timestamp swapped after signing', FetchSyncWebhook::verify('t=' . ($now + 1) . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $body, $secret, $now), false);
check('spaces around parts', FetchSyncWebhook::verify('t=' . $now . ', v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $body, $secret, $now), true);

// --- Status mapping ----------------------------------------------------------
$map = array(
    'DRAFT' => 'awaiting',
    'ACCEPTED' => 'accepted',
    'PREPARING' => 'accepted',
    'READY_FOR_PICKUP' => 'accepted',
    'DISPATCHED' => 'accepted',
    'DELIVERED' => 'delivered',
    'CANCELLED' => 'canceled',
    'REJECTED' => 'canceled',
    'REFUNDED' => 'refunded',
);
foreach ($map as $fetch => $key) {
    check("stateKey($fetch)", FetchSyncOrderMapper::stateKey($fetch), $key);
}
check('unknown status falls back to awaiting', FetchSyncOrderMapper::stateKey('WHATEVER'), 'awaiting');
check('label DRAFT', FetchSyncOrderMapper::label('DRAFT'), 'Waiting for you to accept');
check('label unknown passes through', FetchSyncOrderMapper::label('NEW_ONE'), 'NEW_ONE');
check('restocking statuses', FetchSyncOrderMapper::RESTOCKED, array('CANCELLED', 'REJECTED', 'REFUNDED'));

// --- Allowed actions mirror the API's transition rules -------------------------
// accept: DRAFT · ready: ACCEPTED, PREPARING · reject: DRAFT, ACCEPTED
check('actions DRAFT', FetchSyncOrderMapper::allowedActions('DRAFT'), array('accept', 'reject'));
check('actions ACCEPTED', FetchSyncOrderMapper::allowedActions('ACCEPTED'), array('ready', 'reject'));
check('actions PREPARING', FetchSyncOrderMapper::allowedActions('PREPARING'), array('ready'));
foreach (array('READY_FOR_PICKUP', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REJECTED', 'REFUNDED') as $st) {
    check("actions $st", FetchSyncOrderMapper::allowedActions($st), array());
}

// --- Names and addresses ---------------------------------------------------------
check('split two names', FetchSyncOrderMapper::splitName('Maria Georgiou'), array('Maria', 'Georgiou'));
check('split three names', FetchSyncOrderMapper::splitName('  Anna  Maria   Ioannou '), array('Anna', 'Maria Ioannou'));
check('split one name', FetchSyncOrderMapper::splitName('Andreas'), array('Andreas', ''));
check('split empty', FetchSyncOrderMapper::splitName(''), array('Fetch', 'customer'));
check('split masked initials', FetchSyncOrderMapper::splitName('M. G.'), array('M.', 'G.'));
check('clean name keeps Greek', FetchSyncOrderMapper::cleanName('Μαρία', 'x'), 'Μαρία');
check('clean name keeps "M." before end', FetchSyncOrderMapper::cleanName('M.', 'x'), 'M.');
check('clean name drops digits and symbols', FetchSyncOrderMapper::cleanName('Ann4 (Shop)', 'x'), 'Ann Shop');
check('clean name drops inner dots', FetchSyncOrderMapper::cleanName('J.R', 'x'), 'JR');
check('clean name masked → fallback', FetchSyncOrderMapper::cleanName('******', 'Fetch'), 'Fetch');
check('clean name empty → fallback', FetchSyncOrderMapper::cleanName('', '-'), '-');

$order = array(
    'deliveryType' => 'delivery',
    'customer' => array(
        'name' => 'Maria Georgiou',
        'phone' => '+35799123456',
        'address' => array(
            'line1' => 'Makariou 12 Flat 3',
            'city' => 'Nicosia',
            'postalCode' => '1065',
            'notes' => 'Ring twice',
        ),
    ),
);
check(
    'address',
    FetchSyncOrderMapper::address($order),
    array(
        'firstname' => 'Maria',
        'lastname' => 'Georgiou',
        'address1' => 'Makariou 12 Flat 3',
        'city' => 'Nicosia',
        'postcode' => '1065',
        'phone' => '+35799123456',
        'other' => 'Ring twice',
    )
);
check('single name → lastname "-"', FetchSyncOrderMapper::address(array('customer' => array('name' => 'Andreas')))['lastname'], '-');
check('customer note', FetchSyncOrderMapper::customerNote($order), 'Ring twice');
check('not pickup', FetchSyncOrderMapper::isPickup($order), false);
check('not masked', FetchSyncOrderMapper::isMasked($order), false);
$pickup = array(
    'deliveryType' => 'pickup',
    'customer' => array('name' => 'A B', 'phone' => '', 'address' => array('line1' => '', 'city' => 'Larnaca', 'postalCode' => null, 'notes' => null)),
);
check('pickup', FetchSyncOrderMapper::isPickup($pickup), true);
check('pickup null postcode → empty string', FetchSyncOrderMapper::address($pickup)['postcode'], '');
check('pickup without address line', FetchSyncOrderMapper::address($pickup)['address1'], 'Pickup at store');
check('missing customer block', FetchSyncOrderMapper::address(array())['firstname'], 'Fetch');

$masked = array('customer' => array('name' => '******', 'phone' => '******', 'address' => array('line1' => '******', 'city' => '******', 'postalCode' => '******', 'notes' => '******')));
check('masked detected', FetchSyncOrderMapper::isMasked($masked), true);
check(
    'masked → valid placeholders, never "******"',
    FetchSyncOrderMapper::address($masked),
    array(
        'firstname' => 'Fetch',
        'lastname' => 'customer',
        'address1' => 'Address not provided',
        'city' => '-',
        'postcode' => '',
        'phone' => '',
        'other' => '',
    )
);
check('masked phone only is masked', FetchSyncOrderMapper::isMasked(array('customer' => array('name' => 'A', 'phone' => '******'))), true);
check('address drops characters PrestaShop refuses', FetchSyncOrderMapper::address(array('customer' => array('name' => 'A B', 'address' => array('line1' => 'Flat <3> {x}', 'city' => 'Lim#assol', 'postalCode' => '30_41'))))['address1'], 'Flat 3 x');
check('postcode with invalid chars dropped', FetchSyncOrderMapper::address(array('customer' => array('address' => array('postalCode' => '30_41'))))['postcode'], '');

check('unit price from line total', FetchSyncOrderMapper::unitPrice(array('quantity' => 2, 'unitPrice' => '7.00', 'totalPrice' => '14.00')), 7.0);
check('unit price falls back to unitPrice', FetchSyncOrderMapper::unitPrice(array('quantity' => 3, 'unitPrice' => '2.50')), 2.5);
check('fallback ref ps-12', FetchSyncOrderMapper::parseFallbackRef('ps-12'), array(12, 0));
check('fallback ref ps-12-34', FetchSyncOrderMapper::parseFallbackRef('ps-12-34'), array(12, 34));
check('fallback ref other', FetchSyncOrderMapper::parseFallbackRef('CBL-001'), null);

// --- Price markup (same cases as the WooCommerce plugin) -------------------------
check('markup 0% leaves the price alone', FetchSyncProductMapper::applyMarkup('10.00', 0), '10.00');
check('markup 10% on 10.00', FetchSyncProductMapper::applyMarkup('10.00', 10), '11.00');
check('markup 10% on 9.50', FetchSyncProductMapper::applyMarkup('9.50', 10), '10.45');
check('markup 10% on 7.00', FetchSyncProductMapper::applyMarkup('7.00', 10), '7.70');
check('markup 10% on 12.00', FetchSyncProductMapper::applyMarkup('12.00', 10), '13.20');
check('markup 12.5% on 10.00', FetchSyncProductMapper::applyMarkup('10.00', 12.5), '11.25');
check('markup 12.5% on 7.99 rounds half-up', FetchSyncProductMapper::applyMarkup('7.99', 12.5), '8.99');
check('markup 15% on 9.99 (11.4885) rounds half-up', FetchSyncProductMapper::applyMarkup('9.99', 15), '11.49');
check('markup 10% on 0.05 (0.055) rounds half-up', FetchSyncProductMapper::applyMarkup('0.05', 10), '0.06');
check('markup 100% doubles', FetchSyncProductMapper::applyMarkup('19.99', 100), '39.98');
check('markup on 0.00 stays 0.00', FetchSyncProductMapper::applyMarkup('0.00', 10), '0.00');
check('markup returns a decimal string', is_string(FetchSyncProductMapper::applyMarkup('10.00', 10)), true);
check('VAT-incl price rounded first, then marked up', FetchSyncProductMapper::applyMarkup(FetchSyncProductMapper::money(7.983193 * 1.19), 10), '10.45');

check('sanitize markup "10"', FetchSyncSettings::sanitizeMarkup('10'), 10.0);
check('sanitize markup "12,5"', FetchSyncSettings::sanitizeMarkup('12,5'), 12.5);
check('sanitize markup "15 %"', FetchSyncSettings::sanitizeMarkup('15 %'), 15.0);
check('sanitize markup blank → 0', FetchSyncSettings::sanitizeMarkup(''), 0.0);
check('sanitize markup garbage → 0', FetchSyncSettings::sanitizeMarkup('abc'), 0.0);
check('sanitize markup negative → 0', FetchSyncSettings::sanitizeMarkup('-5'), 0.0);
check('sanitize markup above 100 → 100', FetchSyncSettings::sanitizeMarkup('150'), 100.0);
check('sanitize markup keeps 2 decimals', FetchSyncSettings::sanitizeMarkup('7.456'), 7.46);
check('markup input check: number', FetchSyncSettings::isMarkupInput('12,5 %'), true);
check('markup input check: garbage', FetchSyncSettings::isMarkupInput('ten'), false);

// --- Server setting ------------------------------------------------------------------
$server = function (array $saved) {
    Configuration::$values = array();
    foreach ($saved as $key => $value) {
        Configuration::$values[FetchSyncSettings::key($key)] = $value;
    }

    return FetchSyncSettings::baseUrl();
};
check('server: nothing saved → production', $server(array()), 'https://api.fetch.com.cy');
check('server: production ignores the custom URL', $server(array('environment' => 'production', 'custom_base_url' => 'https://ignored.example')), 'https://api.fetch.com.cy');
check('server: staging', $server(array('environment' => 'staging')), 'https://api-stg.fetch.com.cy');
check('server: custom, trailing slash dropped', $server(array('environment' => 'custom', 'custom_base_url' => 'https://partner.example/')), 'https://partner.example');
check('server: unknown value → production', $server(array('environment' => 'sandbox')), 'https://api.fetch.com.cy');
check('sanitize environment production', FetchSyncSettings::sanitizeEnvironment('production'), 'production');
check('sanitize environment staging', FetchSyncSettings::sanitizeEnvironment('staging'), 'staging');
check('sanitize environment custom', FetchSyncSettings::sanitizeEnvironment('custom'), 'custom');
check('sanitize environment garbage', FetchSyncSettings::sanitizeEnvironment('sandbox'), 'production');
check('sanitize environment blank', FetchSyncSettings::sanitizeEnvironment(''), 'production');

// --- Settings defaults and connection ---------------------------------------------------
Configuration::$values = array();
check('default sync on', FetchSyncSettings::enabled('sync_products'), true);
check('default import on', FetchSyncSettings::enabled('import_orders'), true);
check('default auto-accept off', FetchSyncSettings::enabled('auto_accept'), false);
check('default markup 0', FetchSyncSettings::markup(), 0.0);
check('not connected without store', $server(array('client_id' => 'a', 'client_secret' => 'b')) && FetchSyncSettings::isConnected(), false);
$server(array('client_id' => 'a', 'client_secret' => 'b', 'store_id' => 's'));
check('connected with id, secret and store', FetchSyncSettings::isConnected(), true);
Configuration::$values = array();

// --- Money, text and batching ----------------------------------------------------------
check('money pads decimals', FetchSyncProductMapper::money(7), '7.00');
check('money rounds', FetchSyncProductMapper::money(9.499999), '9.50');
check('plain text strips HTML and entities', FetchSyncProductMapper::plainText("<p>Fast&nbsp;<b>USB-C</b>\n cable &amp; more</p>"), 'Fast USB-C cable & more');
check('plain text max 5000 chars', strlen(FetchSyncProductMapper::plainText(str_repeat('a', 6000))), 5000);
$items = array();
for ($i = 0; $i < 450; ++$i) {
    $items[] = array('ref' => 'r' . $i, 'name' => 'n', 'price' => '1.00');
}
check('450 items → batches of 200, 200, 50', array_map('count', FetchSyncProductSync::batches($items)), array(200, 200, 50));
$big = array();
for ($i = 0; $i < 10; ++$i) {
    $big[] = array('ref' => 'b' . $i, 'description' => str_repeat('x', 100000));
}
$sizes = array_map(function ($batch) {
    return strlen(json_encode(array('products' => $batch)));
}, FetchSyncProductSync::batches($big));
check('large items split under 500 KB per request', max($sizes) < 500 * 1024 && count($sizes) > 1, true);
check('no items → no batches', FetchSyncProductSync::batches(array()), array());

// --- Mirror mode -------------------------------------------------------------------------

/** Partner API double: pages GET /listings with a numeric cursor, records listings/batch. */
class FakeFetchApi
{
    public $listings = array();
    public $pageSize = 2;
    public $failPage = null;
    public $rejectIds = array();
    public $failBatches = false;
    public $pages = array();
    public $batches = array();

    public function __construct(array $listings = array())
    {
        $this->listings = $listings;
    }

    public function listListings($storeId, array $query = array())
    {
        $this->pages[] = $query;
        if (count($this->pages) === $this->failPage) {
            return array('code' => 503, 'body' => array('statusCode' => 503, 'message' => 'forced 503'), 'error' => null, 'retry_after' => null);
        }
        $offset = isset($query['cursor']) ? (int) $query['cursor'] : 0;
        $next = $offset + $this->pageSize < count($this->listings) ? (string) ($offset + $this->pageSize) : null;

        return array('code' => 200, 'body' => array('data' => array_slice($this->listings, $offset, $this->pageSize), 'nextCursor' => $next), 'error' => null, 'retry_after' => null);
    }

    public function batchListings($storeId, array $items, $idempotencyKey)
    {
        $this->batches[] = array('store' => $storeId, 'items' => $items, 'key' => $idempotencyKey, 'bytes' => strlen(json_encode(array('items' => $items))));
        if ($this->failBatches) {
            return array('code' => 0, 'body' => null, 'error' => 'Could not reach Fetch: timeout', 'retry_after' => null);
        }
        $results = array();
        $rejected = 0;
        foreach ($items as $item) {
            $id = isset($item['listingId']) ? $item['listingId'] : null;
            if (in_array($id, $this->rejectIds, true)) {
                ++$rejected;
                $results[] = array('ref' => null, 'listingId' => $id, 'status' => 'error', 'code' => 'not_permitted', 'message' => 'Listing is locked');
                continue;
            }
            $results[] = array('ref' => null, 'listingId' => $id, 'status' => 'updated');
        }

        return array('code' => 201, 'body' => array('accepted' => count($items) - $rejected, 'rejected' => $rejected, 'results' => $results), 'error' => null, 'retry_after' => null);
    }

    public function switchedOff()
    {
        $out = array();
        foreach ($this->batches as $batch) {
            foreach ($batch['items'] as $item) {
                $out[] = $item['listingId'];
            }
        }

        return $out;
    }
}

function listing($id, $ref, $active = true, $price = '10.00', $name = null)
{
    return array('listingId' => $id, 'productId' => 'p-' . $id, 'variantId' => null, 'name' => null !== $name ? $name : 'Item ' . $id, 'ref' => $ref, 'price' => $price, 'salePrice' => null, 'stockCount' => 3, 'isActive' => $active);
}

function mirrorReset($mode)
{
    Configuration::$values = array();
    Db::$logged = array();
    FetchSyncSettings::update(array('client_id' => 'c', 'client_secret' => 's', 'store_id' => 'store-1', 'catalogue_mode' => $mode));
}

function logged($pattern)
{
    return count(preg_grep($pattern, Db::$logged));
}

// The shop sells A-1, A-2, A-3 (and A-4, inactive on Fetch). GIFT is a product the
// module does not sync (filtered out), OLD-9 is gone from the shop, lst-c has no ref.
$keep = array('A-1' => true, 'A-2' => true, 'A-3' => true, 'A-4' => true);
$store = array(
    listing('lst-1', 'A-1'),
    listing('lst-2', 'A-2'),
    listing('lst-3', 'A-3'),
    listing('lst-4', 'A-4', false),
    listing('lst-c', null, true, '4.50', 'Portal-only item'),
    listing('lst-g', 'GIFT', true, '25.00', 'Gift card'),
    listing('lst-o', 'OLD-9', true, '7.99', 'Old product'),
    listing('lst-x', 'OLD-X', false, '1.00', 'Already off'),
    listing('lst-2', 'A-2'), // seen again on a later page: counted once
);

check('default catalogue mode: add and update only', FetchSyncSettings::get('catalogue_mode'), 'add_update');
check('sanitize catalogue mode mirror', FetchSyncSettings::sanitizeCatalogueMode('mirror'), 'mirror');
check('sanitize catalogue mode garbage', FetchSyncSettings::sanitizeCatalogueMode('delete_all'), 'add_update');
check('mirror internals removed on uninstall', array_values(array_intersect(array('BULK_FAILED', 'MIRROR_PENDING'), FetchSyncSettings::INTERNAL_KEYS)), array('BULK_FAILED', 'MIRROR_PENDING'));

mirrorReset('add_update');
$api = new FakeFetchApi($store);
check('mirror off: end of full sync does nothing', FetchSyncMirror::afterFullSync($api, $keep), null);
check('mirror off: no listing read, nothing switched off', array(count($api->pages), count($api->batches)), array(0, 0));

mirrorReset('mirror');
FetchSyncSettings::setInternal('BULK_FAILED', '1');
$api = new FakeFetchApi($store);
check('full sync with a failed request: mirror skipped', FetchSyncMirror::afterFullSync($api, $keep), null);
check('full sync with a failed request: nothing read or switched off', array(count($api->pages), count($api->batches)), array(0, 0));
check('full sync with a failed request: skip logged', logged('/^error: Mirror skipped: Fetch could not be reached/'), 1);
check('failure flag cleared for the next full sync', FetchSyncSettings::internal('BULK_FAILED'), '');

mirrorReset('mirror');
$api = new FakeFetchApi($store);
$run = FetchSyncMirror::afterFullSync($api, $keep);
check('mirror on: run done', $run['status'], 'done');
check('mirror on: scan pages with limit 500 and follows nextCursor', array_map(function ($q) {
    return isset($q['cursor']) ? $q['limit'] . '@' . $q['cursor'] : $q['limit'] . '@';
}, $api->pages), array('500@', '500@2', '500@4', '500@6', '500@8'));
check('mirror on: counts (scanned once per listing, active, kept)', array($run['scanned'], $run['active'], $run['kept'], $run['off']), array(8, 6, 4, 3));
check('mirror on: switches off the no-ref, filtered-out and gone listings only', $api->switchedOff(), array('lst-c', 'lst-g', 'lst-o'));
check('mirror on: rows by the scanned listingId (never ref), with the current price and isActive false', $api->batches[0]['items'], array(
    array('listingId' => 'lst-c', 'price' => '4.50', 'isActive' => false),
    array('listingId' => 'lst-g', 'price' => '25.00', 'isActive' => false),
    array('listingId' => 'lst-o', 'price' => '7.99', 'isActive' => false),
));
check('mirror on: one row identifier each (live refuses ambiguous rows)', count(array_filter($api->batches[0]['items'], function ($row) {
    return 1 !== count(array_intersect_key($row, array('ref' => 1, 'listingId' => 1, 'barcode' => 1)));
})), 0);
check('mirror on: batch sent to the configured store with an idempotency key', $api->batches[0]['store'] === 'store-1' && 0 === strpos($api->batches[0]['key'], 'ps-mirror-'), true);
check('mirror on: result', array($run['switched_off'], $run['errors']), array(3, 0));
check('mirror on: run logged with scanned, kept, switched off, errors', logged('/^info: Mirror: scanned 8 Fetch listing\(s\), kept 4, switched off 3, 0 error\(s\)\.$/'), 1);
check('a listing the shop sells that is off on Fetch is left alone (the normal sync switches it back on)', in_array('lst-4', $api->switchedOff(), true), false);

$again = new FakeFetchApi($store);
FetchSyncMirror::run(false, $again, $keep);
check('a later run with the same rows uses a new idempotency key (sandbox replays keys)', $again->batches[0]['key'] !== $api->batches[0]['key'], true);
check('product push: a fresh idempotency key per request, not a hash of the payload', preg_match('/^ps-[0-9a-f]{32}$/', FetchSyncProductSync::idempotencyKey()) && FetchSyncProductSync::idempotencyKey() !== FetchSyncProductSync::idempotencyKey(), true);
check('mirror: keys carry their own prefix', 0 === strpos(FetchSyncProductSync::idempotencyKey('ps-mirror-'), 'ps-mirror-'), true);

mirrorReset('mirror');
$api = new FakeFetchApi($store);
$api->failPage = 2;
$run = FetchSyncMirror::afterFullSync($api, $keep);
check('scan page failing: run failed', $run['status'], 'failed');
check('scan page failing: nothing switched off', count($api->batches), 0);
check('scan page failing: logged', logged('/^error: Mirror skipped: could not read your Fetch listings \(HTTP 503: forced 503\)\. Nothing was switched off\.$/'), 1);
$api = new FakeFetchApi($store);
$api->failPage = 5;
check('last scan page failing: nothing switched off either', array(FetchSyncMirror::run(false, $api, $keep)['status'], count($api->batches)), array('failed', 0));

// Guard: more than half of the active listings would go.
check('guard: 3 of 4 is more than half', FetchSyncMirror::tooMany(3, 4), true);
check('guard: exactly half applies', FetchSyncMirror::tooMany(2, 4), false);
check('guard: nothing to switch off', FetchSyncMirror::tooMany(0, 0), false);
check('guard: the only active listing', FetchSyncMirror::tooMany(1, 1), true);
mirrorReset('mirror');
$few = array('A-1' => true);
$api = new FakeFetchApi($store);
$run = FetchSyncMirror::afterFullSync($api, $few);
check('guard: run held', array($run['status'], $run['off'], $run['active']), array('held', 5, 6));
check('guard: nothing switched off', count($api->batches), 0);
check('guard: pending run stored for the admin notice', array_intersect_key((array) FetchSyncMirror::pending(), array('off' => 1, 'active' => 1)), array('off' => 5, 'active' => 6));
check('guard: held run logged', logged('/^error: Mirror held: it would switch off 5 of 6 active Fetch products, more than half\. Nothing was switched off\./'), 1);
$api = new FakeFetchApi($store);
$run = FetchSyncMirror::run(true, $api, $few);
check('Apply anyway: applied despite the guard', array($run['status'], $run['switched_off']), array('done', 5));
check('Apply anyway: switches off everything the shop does not sell', $api->switchedOff(), array('lst-2', 'lst-3', 'lst-c', 'lst-g', 'lst-o'));
check('Apply anyway: pending run cleared', FetchSyncMirror::pending(), null);

// Preview: counts and up to 20 names, changes nothing.
mirrorReset('add_update');
$many = array();
for ($i = 0; $i < 30; ++$i) {
    $many[] = listing('lst-f' . $i, 'F-' . $i, true, '2.00', 'Foreign ' . $i);
}
for ($i = 0; $i < 40; ++$i) {
    $many[] = listing('lst-k' . $i, 'K-' . $i);
}
$keepMany = array();
for ($i = 0; $i < 40; ++$i) {
    $keepMany['K-' . $i] = true;
}
$api = new FakeFetchApi($many);
$api->pageSize = 500;
$preview = FetchSyncMirror::preview($api, $keepMany);
check('preview: N of M', array($preview['status'], $preview['off'], $preview['active'], $preview['kept']), array('preview', 30, 70, 40));
check('preview: at most 20 sample names', $preview['samples'], array_map(function ($i) {
    return 'Foreign ' . $i;
}, range(0, 19)));
check('preview: under half, would not be held', $preview['held'], false);
check('preview: works before mirror mode is on and switches nothing off', count($api->batches), 0);
$api = new FakeFetchApi($many);
check('preview: flags a run the guard would hold', FetchSyncMirror::preview($api, array('K-0' => true))['held'], true);
$api = new FakeFetchApi($many);
$api->failPage = 1;
check('preview: a failed scan is reported', FetchSyncMirror::preview($api, $keepMany), array('status' => 'failed', 'error' => 'HTTP 503: forced 503'));
check('preview: no-name listing shown by id', FetchSyncMirror::preview(new FakeFetchApi(array(listing('lst-n', null, true, '1.00', ''))), array())['samples'], array('lst-n'));

// Batching above 200 rows.
mirrorReset('mirror');
$big = array();
for ($i = 0; $i < 450; ++$i) {
    $big[] = listing('lst-b' . $i, 'B-' . $i, true, '3.00', str_repeat('Long name ', 20));
    $big[] = listing('lst-s' . $i, 'S-' . $i);
}
$keepBig = array();
for ($i = 0; $i < 450; ++$i) {
    $keepBig['S-' . $i] = true;
}
$api = new FakeFetchApi($big);
$api->pageSize = 500;
$run = FetchSyncMirror::afterFullSync($api, $keepBig);
check('batching: 450 rows → requests of 200, 200, 50', array_map(function ($b) {
    return count($b['items']);
}, $api->batches), array(200, 200, 50));
check('batching: every request under 450 KB', max(array_column($api->batches, 'bytes')) < 450000, true);
check('batching: two scan pages of at most 500', count($api->pages), 2);
check('batching: all 450 switched off, none of the shop\'s', array($run['switched_off'], $run['errors'], count(preg_grep('/^lst-s/', $api->switchedOff()))), array(450, 0, 0));
$rows = array();
for ($i = 0; $i < 150; ++$i) {
    $rows[] = array('ref' => 'R-' . $i, 'price' => '1.00', 'isActive' => false, 'pad' => str_repeat('x', 4000));
}
check('batching: large rows split by size too', array_map('count', FetchSyncProductSync::batches($rows)), array(110, 40));

// Errors.
mirrorReset('mirror');
$api = new FakeFetchApi($store);
$api->rejectIds = array('lst-o');
$run = FetchSyncMirror::run(false, $api, $keep);
check('a refused row is an error, the rest are switched off', array($run['switched_off'], $run['errors']), array(2, 1));
check('a refused row is logged with Fetch\'s reason', logged('/^error: Mirror: Fetch refused to switch off lst-o: not_permitted: Listing is locked$/'), 1);
check('a run with errors is logged as an error', logged('/^error: Mirror: scanned 8 Fetch listing\(s\), kept 4, switched off 2, 1 error\(s\)\.$/'), 1);
$api = new FakeFetchApi($big);
$api->pageSize = 500;
$api->failBatches = true;
$run = FetchSyncMirror::run(false, $api, $keepBig);
check('a failed batch request stops the run; nothing counted as switched off', array(count($api->batches), $run['switched_off'], $run['errors']), array(1, 0, 450));
Configuration::$values = array();

echo "$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
