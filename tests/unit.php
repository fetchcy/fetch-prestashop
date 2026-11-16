<?php
/**
 * Standalone unit tests (no PrestaShop): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules, the price
 * markup maths, the server setting and request batching. The webhook, status
 * and markup cases are the same as the WooCommerce plugin's.
 */
define('_PS_VERSION_', '8.1.0');

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

echo "$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
