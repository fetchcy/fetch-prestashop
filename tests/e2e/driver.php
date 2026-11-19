<?php
/**
 * e2e driver, mounted at /fetchsync-e2e/ in the PrestaShop root: GET /fetchsync-e2e/driver.php?step=<name>.
 * Each step prints JSON; tests/e2e/run.mjs asserts on it. Test-only, never shipped.
 */
require dirname(__DIR__) . '/config/config.inc.php';
header('Content-Type: application/json');

$context = Context::getContext();
$context->employee = new Employee(1);
$step = isset($_GET['step']) ? $_GET['step'] : '';
// The mock Fetch API as the container sees it (the runner passes its port).
$mockUrl = 'http://host.docker.internal:' . (isset($_GET['mock_port']) ? (int) $_GET['mock_port'] : 9099);
$out = array('step' => $step);
$db = Db::getInstance();
require_once _PS_MODULE_DIR_ . 'fetchsync/fetchsync.php'; // also when the module is not installed yet
$module = Module::getInstanceByName('fetchsync');
FetchSyncContext::background();
if (!isset($context->container)) {
    // Plain script: give PrestaShop the front-office service container a controller would build.
    $context->container = PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
}

function e2e_product_id($reference)
{
    return (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = \'' . pSQL($reference) . '\' ORDER BY id_product DESC');
}

function e2e_ipa($reference)
{
    return (int) Db::getInstance()->getValue('SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE reference = \'' . pSQL($reference) . '\' ORDER BY id_product_attribute DESC');
}

function e2e_stock($reference, $combination = null)
{
    $id = e2e_product_id($reference);
    $ipa = $combination ? e2e_ipa($combination) : 0;
    if ($combination) {
        $id = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product_attribute = ' . $ipa);
    }

    return (int) StockAvailable::getQuantityAvailableByProduct($id, $ipa, (int) Configuration::get('PS_SHOP_DEFAULT'));
}

function e2e_tax_group()
{
    $id = (int) Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE name = \'CY Standard 19%\' AND deleted = 0');
    if ($id) {
        return $id;
    }
    $tax = new Tax();
    $tax->name = array((int) Configuration::get('PS_LANG_DEFAULT') => 'VAT CY 19%');
    $tax->rate = 19;
    $tax->active = 1;
    $tax->add();
    $group = new TaxRulesGroup();
    $group->name = 'CY Standard 19%';
    $group->active = 1;
    $group->add();
    $rule = new TaxRule();
    $rule->id_tax_rules_group = (int) $group->id;
    $rule->id_country = (int) Country::getByIso('CY');
    $rule->id_tax = (int) $tax->id;
    $rule->id_state = 0;
    $rule->zipcode_from = 0;
    $rule->zipcode_to = 0;
    $rule->behavior = 0;
    $rule->add();

    return (int) $group->id;
}

function e2e_simple($name, $reference, $priceIncl, $qty, $taxGroup)
{
    $lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $p = new Product();
    $p->name = array($lang => $name);
    $p->link_rewrite = array($lang => Tools::str2url($name));
    $p->description = array($lang => '<p>' . $name . ' <b>for</b> e2e</p>');
    $p->reference = $reference;
    $p->price = round($priceIncl / 1.19, 6);
    $p->id_tax_rules_group = $taxGroup;
    $p->active = 1;
    $p->visibility = 'both';
    $p->available_for_order = 1;
    $p->show_price = 1;
    $p->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $p->ean13 = '';
    $p->add();
    $p->addToCategories(array((int) Configuration::get('PS_HOME_CATEGORY')));
    StockAvailable::setQuantity((int) $p->id, 0, $qty);

    return $p;
}

/** The same text in every installed language. */
function e2e_langs($text)
{
    $out = array();
    foreach (Language::getIDs(false) as $id) {
        $out[(int) $id] = $text;
    }

    return $out;
}

/** A new feature with one custom value: [id_feature, id_feature_value]. */
function e2e_feature($name, $value)
{
    $feature = new Feature();
    $feature->name = e2e_langs($name);
    $feature->add();
    $featureValue = new FeatureValue();
    $featureValue->id_feature = (int) $feature->id;
    $featureValue->value = e2e_langs($value);
    $featureValue->custom = 1;
    $featureValue->add();

    return array((int) $feature->id, (int) $featureValue->id);
}

function e2e_order_state($idOrder)
{
    $order = new Order((int) $idOrder);
    $row = FetchSyncOrderSync::rowForOrder((int) $idOrder);

    return array(
        'id' => (int) $order->id,
        'state' => (int) $order->current_state,
        'fetch_status' => $row ? $row['fetch_status'] : null,
    );
}

function e2e_states()
{
    return array(
        'awaiting' => (int) FetchSyncSettings::internal('OS_AWAITING'),
        'accepted' => (int) FetchSyncSettings::internal('OS_ACCEPTED'),
        'delivered' => (int) FetchSyncSettings::internal('OS_DELIVERED'),
        'refunded' => (int) FetchSyncSettings::internal('OS_REFUNDED'),
        'canceled' => (int) Configuration::get('PS_OS_CANCELED'),
        'error' => (int) Configuration::get('PS_OS_ERROR'),
    );
}

/** "Save settings" form fields as they stand, plus $extra (the settings form always posts every field). */
function e2e_settings_post(array $extra)
{
    $s = FetchSyncSettings::all();

    return array_merge(array(
        'fetchsync_environment' => $s['environment'],
        'fetchsync_custom_base_url' => $s['custom_base_url'],
        'fetchsync_client_id' => $s['client_id'],
        'fetchsync_client_secret' => '',
        'fetchsync_store_id' => $s['store_id'],
        'fetchsync_sync_products' => 'yes' === $s['sync_products'] ? '1' : '0',
        'fetchsync_import_orders' => 'yes' === $s['import_orders'] ? '1' : '0',
        'fetchsync_auto_accept' => 'yes' === $s['auto_accept'] ? '1' : '0',
        'fetchsync_prep_minutes' => (string) $s['prep_minutes'],
        'fetchsync_price_markup' => (string) FetchSyncSettings::markup(),
        'fetchsync_catalogue_mode' => $s['catalogue_mode'],
        'fetchsync_exclude_backorders' => 'no' === $s['exclude_backorders'] ? '0' : '1',
    ), $extra);
}

/** "Sync all products now", flushed to the end (where mirror mode runs). */
function e2e_full_sync()
{
    $last = (int) Db::getInstance()->getValue('SELECT MAX(id_fetchsync_log) FROM ' . _DB_PREFIX_ . 'fetchsync_log');
    FetchSyncProductSync::startBulkSync('e2e full sync');
    for ($i = 0; $i < 20 && FetchSyncSettings::internal('BULK_RUNNING'); ++$i) {
        FetchSyncProductSync::flush(60);
    }

    return array(
        'finished' => !FetchSyncSettings::internal('BULK_RUNNING'),
        'activity' => array_column(Db::getInstance()->executeS('SELECT message FROM ' . _DB_PREFIX_ . 'fetchsync_log WHERE id_fetchsync_log > ' . $last . ' ORDER BY id_fetchsync_log'), 'message'),
        'pending' => FetchSyncMirror::pending(),
    );
}


// --- Multistore helpers (tests/e2e/multistore.mjs, upgrade.mjs) ------------------

/** Shop id by name (0 when it does not exist). */
function ms_shop($name)
{
    return (int) Db::getInstance()->getValue('SELECT id_shop FROM ' . _DB_PREFIX_ . 'shop WHERE name = \'' . pSQL($name) . '\' AND deleted = 0');
}

/** Shop ids of the scenario: A is the default shop, B and C are created by ms_setup. */
function ms_shops()
{
    return array_filter(array('A' => (int) Configuration::get('PS_SHOP_DEFAULT'), 'B' => ms_shop('Shop B'), 'C' => ms_shop('Shop C')));
}

/** A new shop copied from the default one, like Shop parameters → Add shop with "Import data". */
function ms_create_shop($name, $idGroup, $domain, $virtualUri, array $import)
{
    $from = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
    $shop = new Shop();
    $shop->name = $name;
    $shop->id_shop_group = (int) $idGroup;
    $shop->id_category = (int) $from->id_category;
    $shop->theme_name = $from->theme_name;
    $shop->color = '';
    $shop->active = 1;
    $shop->deleted = 0;
    $shop->add();
    $url = new ShopUrl();
    $url->id_shop = (int) $shop->id;
    $url->domain = $domain;
    $url->domain_ssl = $domain;
    $url->physical_uri = $from->physical_uri;
    $url->virtual_uri = $virtualUri;
    $url->main = 1;
    $url->active = 1;
    $url->add();
    // copyShopData() reads the root categories the form posts.
    $_POST['categoryBox'] = array((int) Configuration::get('PS_ROOT_CATEGORY'), (int) $from->id_category);
    $shop->copyShopData((int) $from->id, $import);
    Category::updateFromShop($_POST['categoryBox'], (int) $shop->id);
    $shop->associateSuperAdmins();
    Db::getInstance()->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'group_shop (id_shop, id_group) VALUES (' . (int) $shop->id . ', ' . (int) Configuration::get('PS_UNIDENTIFIED_GROUP') . '), (' . (int) $shop->id . ', ' . (int) Configuration::get('PS_GUEST_GROUP') . '), (' . (int) $shop->id . ', ' . (int) Configuration::get('PS_CUSTOMER_GROUP') . ')');

    return (int) $shop->id;
}

/** "Save settings" for one shop, from its own settings plus $extra. */
function ms_save($module, $idShop, array $extra)
{
    $post = FetchSyncSettings::withShop($idShop, function () use ($extra) {
        return e2e_settings_post($extra);
    });
    $_POST = $post;
    $admin = new FetchSyncAdmin($module, $idShop);

    return array_map(function ($html) {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }, $admin->process());
}

/** A shop's settings without the secrets. */
function ms_settings($idShop)
{
    return FetchSyncSettings::withShop($idShop, function () {
        $s = FetchSyncSettings::all();
        $s['has_secret'] = '' !== (string) $s['client_secret'];
        $s['webhook_secret_hint'] = '' !== (string) $s['webhook_secret'] ? Tools::substr((string) $s['webhook_secret'], 0, 10) : '';
        unset($s['client_secret'], $s['webhook_secret']);
        $s['connected'] = FetchSyncSettings::isConnected();

        return $s;
    });
}

/** The module's objects associated with a shop. */
function ms_associations($idShop)
{
    $db = Db::getInstance();
    $carrier = FetchSyncInstaller::carrier();
    $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);

    return array(
        'module' => (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'module_shop WHERE id_module = ' . (int) Module::getModuleIdByName('fetchsync') . ' AND id_shop = ' . (int) $idShop),
        'hooks' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'hook_module WHERE id_module = ' . (int) Module::getModuleIdByName('fetchsync') . ' AND id_shop = ' . (int) $idShop),
        'carrier' => (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'carrier_shop WHERE id_carrier = ' . (int) $carrier->id . ' AND id_shop = ' . (int) $idShop),
        'carrier_lang' => (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'carrier_lang WHERE id_carrier = ' . (int) $carrier->id . ' AND id_shop = ' . (int) $idShop),
        'placeholder' => (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = ' . $placeholder . ' AND id_shop = ' . (int) $idShop),
    );
}

switch ($step) {
    case 'ping':
        // Up = PrestaShop finished installing itself (config, languages, order states, catalogue).
        $out['up'] = (int) Configuration::get('PS_LANG_DEFAULT') > 0 && (int) Configuration::get('PS_OS_CANCELED') > 0
            && (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product') > 0
            && (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module WHERE name = \'ps_facetedsearch\'') > 0;
        $out['ready'] = Module::isInstalled('fetchsync') && Module::isEnabled('fetchsync');
        $out['ps'] = _PS_VERSION_;
        $out['php'] = PHP_VERSION;
        break;

    case 'module':
        // Install/uninstall over HTTP (PrestaShop 1.7's console cannot boot a shop in this image).
        // Installing a module updates translations through the Symfony container, which a plain script lacks.
        global $kernel;
        if (!$kernel && is_file(_PS_ROOT_DIR_ . '/app/AppKernel.php')) {
            require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
            $kernel = new AppKernel('prod', false);
            $kernel->boot();
        }
        $instance = Module::getInstanceByName('fetchsync');
        $out['ok'] = 'install' === $_GET['do'] ? (bool) $instance->install() : (bool) $instance->uninstall();
        $out['errors'] = $instance->getErrors();
        break;

    case 'configure':
        Configuration::updateValue('PS_MAIL_METHOD', 3); // never send mail from the test shop
        FetchSyncSettings::update(array(
            'environment' => 'custom',
            'custom_base_url' => $mockUrl,
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'sync_products' => 'yes',
            'import_orders' => 'yes',
            'auto_accept' => 'no',
            'prep_minutes' => 20,
            'price_markup' => '0',
        ));
        FetchSyncApi::forgetToken();
        $out['stores'] = (new FetchSyncApi())->listStores();
        FetchSyncSettings::update(array('store_id' => 'store-1', 'store_name' => 'Mock Shop'));
        $hook = (new FetchSyncApi())->registerWebhook(FetchSyncWebhook::url());
        $out['webhook_register'] = $hook;
        FetchSyncSettings::update(array('webhook_secret' => $hook['body']['signingSecret'], 'webhook_registered' => time()));
        $out['webhook_url'] = FetchSyncWebhook::url();
        $out['cron_url'] = FetchSyncCron::url();
        $out['connected'] = FetchSyncSettings::isConnected();
        $out['tax_group'] = e2e_tax_group();
        // Start from an empty queue (demo products were saved while installing).
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['carrier'] = (int) FetchSyncInstaller::carrier()->id;
        break;

    case 'create_products':
        $tax = e2e_tax_group();
        $simple = e2e_simple('USB-C Cable 1m', 'CBL-001', 9.50, 10, $tax);
        // Sale: 2.50 off, VAT included → 7.00.
        $sp = new SpecificPrice();
        $sp->id_product = (int) $simple->id;
        $sp->id_product_attribute = 0;
        $sp->id_shop = 0;
        $sp->id_shop_group = 0;
        $sp->id_currency = 0;
        $sp->id_country = 0;
        $sp->id_group = 0;
        $sp->id_customer = 0;
        $sp->id_cart = 0;
        $sp->price = -1;
        $sp->from_quantity = 1;
        $sp->reduction = 2.5;
        $sp->reduction_tax = 1;
        $sp->reduction_type = 'amount';
        $sp->from = '0000-00-00 00:00:00';
        $sp->to = '0000-00-00 00:00:00';
        $sp->add();

        $case = e2e_simple('Phone Case', 'CASE', 12.00, 0, $tax);
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach (array('Black' => 4, 'White' => 0) as $colour => $qty) {
            $idAttribute = (int) $db->getValue(
                'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
                INNER JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $lang . '
                WHERE al.name = \'' . pSQL($colour) . '\' ORDER BY a.id_attribute'
            );
            $ipa = $case->addCombinationEntity(0, 0, 0, 0, 0, 0, array(), 'CASE-' . strtoupper($colour), 0, '', 'Black' === $colour);
            $combination = new Combination((int) $ipa);
            $combination->setAttributes(array($idAttribute));
            StockAvailable::setQuantity((int) $case->id, (int) $ipa, $qty);
            $out['attributes'][$colour] = $idAttribute;
        }
        // A summary on the simple product; paragraphs, SEO meta and two features
        // (attached out of position order) on the case, which every combination carries.
        $simple->description_short = e2e_langs('<p>Short <i>summary</i></p>');
        $simple->update();
        $case->description = e2e_langs('<p>Slim case.</p><p>Fits <b>most</b> phones.</p>');
        $case->meta_title = e2e_langs('Phone Case | Mock Shop');
        $case->meta_description = e2e_langs('A slim case.');
        $case->update();
        $height = e2e_feature('Height', '1 cm');
        $material = e2e_feature('Material', 'Silicone');
        foreach (array($material, $height) as $feature) {
            $db->insert('feature_product', array('id_feature' => $feature[0], 'id_product' => (int) $case->id, 'id_feature_value' => $feature[1]));
        }
        $out['pending'] = array_map('intval', array_column($db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'fetchsync_queue'), 'id_product'));
        $out['simple_id'] = (int) $simple->id;
        $out['case_id'] = (int) $case->id;
        $out['sent'] = FetchSyncProductSync::flush(30);
        $out['simple_meta'] = $db->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'fetchsync_product WHERE id_product = ' . (int) $simple->id);
        $out['queue_after'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        break;

    case 'change_stock':
        $id = e2e_product_id('CBL-001');
        StockAvailable::setQuantity($id, 0, 6);
        $out['pending'] = array_map('intval', array_column($db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'fetchsync_queue'), 'id_product'));
        $out['sent'] = FetchSyncProductSync::flush(30);
        $out['stock'] = e2e_stock('CBL-001');
        break;

    case 'rate_limited_change':
        // The runner has told the mock to answer the next call with 429 Retry-After: 2.
        $id = e2e_product_id('CBL-001');
        StockAvailable::setQuantity($id, 0, 7);
        $out['first_sent'] = FetchSyncProductSync::flush(30);
        $out['queued_after_429'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue WHERE id_product = ' . $id);
        $out['backoff'] = FetchSyncApi::backoffRemaining();
        $out['error_recorded'] = $db->getValue('SELECT error FROM ' . _DB_PREFIX_ . 'fetchsync_product WHERE id_product = ' . $id);
        sleep($out['backoff'] + 1);
        // Retry timing is exponential; make the row due now for the test.
        $db->execute('UPDATE ' . _DB_PREFIX_ . 'fetchsync_queue SET next_try = 0');
        $out['second_sent'] = FetchSyncProductSync::flush(30);
        $out['queued_after_retry'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue WHERE id_product = ' . $id);
        StockAvailable::setQuantity($id, 0, 6);
        FetchSyncProductSync::flush(30);
        break;

    case 'markup':
        // Same path as pressing "Save settings" with Price markup = 10.
        $_POST = array(
            'submitFetchsyncSave' => '1',
            'fetchsync_environment' => 'custom',
            'fetchsync_custom_base_url' => $mockUrl . '/',
            'fetchsync_client_id' => 'client-1',
            'fetchsync_client_secret' => '',
            'fetchsync_store_id' => 'store-1',
            'fetchsync_sync_products' => '1',
            'fetchsync_import_orders' => '1',
            'fetchsync_auto_accept' => '0',
            'fetchsync_prep_minutes' => '20',
            'fetchsync_price_markup' => '10 %',
            'fetchsync_exclude_backorders' => '1',
        );
        $admin = new FetchSyncAdmin($module);
        $out['messages'] = array_map('strip_tags', $admin->process());
        $out['markup'] = FetchSyncSettings::markup();
        $out['queued'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['token_kept'] = '' !== (string) FetchSyncSettings::internal('TOKEN', '');
        $out['custom_url'] = FetchSyncSettings::get('custom_base_url');
        $sent = 0;
        for ($i = 0; $i < 10 && (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue'); ++$i) {
            $sent += FetchSyncProductSync::flush(30);
        }
        $out['sent'] = $sent;
        $out['queue_after'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['activity'] = array_column(FetchSyncLogger::activity(5), 'message');
        // Saving again with the same value must not queue anything.
        $admin2 = new FetchSyncAdmin($module);
        $admin2->process();
        $out['queued_on_unchanged_save'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        break;

    case 'render_box':
        $idOrder = FetchSyncOrderSync::find((string) $_GET['order']);
        $out['html'] = FetchSyncOrderActions::render($module, $idOrder);
        $out['empty_for_other_order'] = FetchSyncOrderActions::render($module, 1);
        break;

    case 'action':
        $idOrder = FetchSyncOrderSync::find((string) $_GET['order']);
        $args = array('prepMinutes' => isset($_GET['prep']) ? (int) $_GET['prep'] : 15, 'reason' => isset($_GET['reason']) ? $_GET['reason'] : 'OTHER', 'note' => isset($_GET['note']) ? $_GET['note'] : '');
        $out['error'] = FetchSyncOrderActions::run($idOrder, (string) $_GET['do'], $args);
        $out['order'] = e2e_order_state($idOrder);
        $out['states'] = e2e_states();
        if (!empty($_GET['again'])) {
            $out['again_error'] = FetchSyncOrderActions::run($idOrder, (string) $_GET['do'], $args);
        }
        break;

    case 'order':
        $idOrder = FetchSyncOrderSync::find((string) $_GET['order']);
        $out['order'] = $idOrder ? e2e_order_state($idOrder) : null;
        $out['states'] = e2e_states();
        $out['stock_cbl'] = e2e_stock('CBL-001');
        $out['stock_black'] = e2e_stock('CASE', 'CASE-BLACK');
        $out['placeholder_stock'] = (int) StockAvailable::getQuantityAvailableByProduct((int) FetchSyncSettings::internal('PLACEHOLDER_ID'), 0);
        $out['count_by_transaction'] = (int) $db->getValue('SELECT COUNT(DISTINCT o.id_order) FROM ' . _DB_PREFIX_ . 'orders o INNER JOIN ' . _DB_PREFIX_ . 'order_payment op ON op.order_reference = o.reference WHERE op.transaction_id = \'' . pSQL((string) $_GET['order']) . '\'');
        $out['mail_suppressed'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'log WHERE message LIKE \'Fetch: e-mail %not sent\'');
        $out['mail_rows_to_fetch_customers'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mail WHERE recipient LIKE \'%fetch.invalid\'');
        if ($idOrder) {
            $order = new Order($idOrder);
            $address = new Address((int) $order->id_address_delivery);
            $customer = new Customer((int) $order->id_customer);
            $out['customer'] = $customer->firstname . ' ' . $customer->lastname;
            $out['email'] = $customer->email;
            $out['guest'] = (int) $customer->is_guest;
            $out['address'] = $address->address1 . ' / ' . $address->city . ' / ' . $address->postcode . ' / ' . $address->phone;
            $out['other'] = $address->other;
            $out['row'] = FetchSyncOrderSync::rowForOrder($idOrder);
            $out['items'] = array();
            foreach ($order->getProductsDetail() as $d) {
                $out['items'][] = array('name' => $d['product_name'], 'product_id' => (int) $d['product_id'], 'ipa' => (int) $d['product_attribute_id'], 'qty' => (int) $d['product_quantity'], 'total' => (float) $d['total_price_tax_incl'], 'reference' => $d['product_reference']);
            }
            $out['total'] = (float) $order->total_paid_tax_incl;
            $out['total_real'] = (float) $order->total_paid_real;
            $out['shipping'] = (float) $order->total_shipping_tax_incl;
            $out['carrier'] = (int) $order->id_carrier;
            $out['fetch_carrier'] = (int) FetchSyncInstaller::carrier()->id;
            $out['payment'] = $order->payment;
            $out['module'] = $order->module;
            $out['transaction'] = $db->getValue('SELECT transaction_id FROM ' . _DB_PREFIX_ . 'order_payment WHERE order_reference = \'' . pSQL($order->reference) . '\'');
            $out['note'] = $order->note;
            $out['date_add'] = $order->date_add;
        }
        break;

    case 'install_state':
        $out['tables'] = array_map('current', $db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'fetchsync%\''));
        $out['installed'] = Module::isInstalled('fetchsync');
        $out['hooks'] = array_column($db->executeS('SELECT h.name FROM ' . _DB_PREFIX_ . 'hook_module hm INNER JOIN ' . _DB_PREFIX_ . 'hook h ON h.id_hook = hm.id_hook WHERE hm.id_module = ' . (int) Module::getModuleIdByName('fetchsync')), 'name');
        $out['states'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_state WHERE module_name = \'fetchsync\' AND deleted = 0');
        $out['carriers_active'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'carrier WHERE external_module_name = \'fetchsync\' AND deleted = 0');
        $out['config_keys'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'configuration WHERE name LIKE \'FETCHSYNC\\_%\'');
        $out['placeholder'] = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);
        $out['cron_token_len'] = Tools::strlen((string) FetchSyncSettings::get('cron_token'));
        break;

    case 'checkout_carriers':
        // A normal shop cart must never be offered the Fetch carrier.
        $customer = new Customer();
        $customer->firstname = 'Shop';
        $customer->lastname = 'Visitor';
        $customer->email = 'visitor-' . time() . '@example.com';
        $customer->passwd = FetchSyncOrderSync::randomPasswordHash();
        $customer->is_guest = 1;
        $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
        $customer->add();
        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) Country::getByIso('CY');
        $address->alias = 'Home';
        $address->firstname = 'Shop';
        $address->lastname = 'Visitor';
        $address->address1 = 'Street 1';
        $address->city = 'Nicosia';
        $address->postcode = '1000';
        $address->add();
        $cart = new Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->add();
        $context->customer = $customer;
        $context->cart = $cart;
        $cart->updateQty(1, e2e_product_id('CBL-001'));
        $options = $cart->getDeliveryOptionList(null, true);
        $ids = array();
        foreach ($options as $byAddress) {
            foreach (array_keys($byAddress) as $key) {
                $ids = array_merge($ids, array_filter(array_map('intval', explode(',', $key))));
            }
        }
        $out['carriers'] = array_values(array_unique($ids));
        $out['fetch_carrier'] = (int) FetchSyncInstaller::carrier()->id;
        break;

    case 'poll_only':
        $out['polled'] = FetchSyncOrderSync::poll();
        break;

    case 'auto_accept':
        FetchSyncSettings::update(array('auto_accept' => 'yes'));
        FetchSyncOrderSync::poll();
        FetchSyncSettings::update(array('auto_accept' => 'no'));
        $idOrder = FetchSyncOrderSync::find('ord-5');
        $out['order'] = $idOrder ? e2e_order_state($idOrder) : null;
        $out['states'] = e2e_states();
        break;

    case 'delete_product':
        $tax = e2e_tax_group();
        $temp = e2e_simple('Temporary Lamp', 'LAMP-TMP', 20.00, 3, $tax);
        FetchSyncProductSync::flush(30);
        $out['synced'] = FetchSyncProductMapper::wasSynced((int) $temp->id);
        $temp = new Product((int) $temp->id);
        $temp->delete();
        $out['deactivations_queued'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_deactivation');
        $out['sent'] = FetchSyncProductSync::flush(30);
        $out['deactivations_after'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_deactivation');
        break;

    case 'mirror_setup':
        // GIFT-CARD becomes a virtual product (the module does not sync those) and
        // GONE-1 is deleted while sync is off, so both stay active on Fetch.
        $tax = e2e_tax_group();
        $gift = e2e_simple('Gift Card e2e', 'GIFT-CARD', 12.00, 5, $tax);
        $gone = e2e_simple('Gone Lamp', 'GONE-1', 5.00, 2, $tax);
        FetchSyncProductSync::flush(30);
        $out['synced'] = FetchSyncProductMapper::wasSynced((int) $gift->id) && FetchSyncProductMapper::wasSynced((int) $gone->id);
        $db->update('product', array('is_virtual' => 1), 'id_product = ' . (int) $gift->id);
        Cache::clean('objectmodel_Product_' . (int) $gift->id . '_*');
        $out['gift_supported'] = FetchSyncProductMapper::supports(FetchSyncProductMapper::loadProduct((int) $gift->id));
        FetchSyncSettings::update(array('sync_products' => 'no'));
        (new Product((int) $gone->id))->delete();
        FetchSyncSettings::update(array('sync_products' => 'yes'));
        $out['deactivations'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_deactivation');
        $out['gift_id'] = (int) $gift->id;
        break;

    case 'mirror_save':
        // "Save settings" with a catalogue mode.
        $_POST = e2e_settings_post(array('submitFetchsyncSave' => '1', 'fetchsync_catalogue_mode' => (string) $_GET['mode']));
        $out['messages'] = array_map('strip_tags', (new FetchSyncAdmin($module))->process());
        $out['mode'] = FetchSyncSettings::get('catalogue_mode');
        break;

    case 'mirror_full_sync':
        $out += e2e_full_sync();
        $out['notice'] = (new FetchSyncAdmin($module))->mirrorNotice();
        break;

    case 'mirror_preview':
        // The "Preview" button (it saves the form first, like every button).
        $_POST = e2e_settings_post(array('submitFetchsyncMirrorPreview' => '1'));
        $out['messages'] = array_map(function ($html) {
            return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<li>', ' | ', $html))));
        }, (new FetchSyncAdmin($module))->process());
        $out['preview'] = FetchSyncMirror::preview();
        $out['mode'] = FetchSyncSettings::get('catalogue_mode');
        break;

    case 'mirror_reactivate':
        // GIFT-CARD is a normal product again: the normal sync switches it back on.
        $id = e2e_product_id('GIFT-CARD');
        $db->update('product', array('is_virtual' => 0), 'id_product = ' . $id);
        FetchSyncProductSync::queue($id);
        $out['sent'] = FetchSyncProductSync::flush(30);
        break;

    case 'mirror_apply':
        // "Apply anyway" posts only its button.
        $_POST = array('submitFetchsyncMirrorApply' => '1');
        $out['messages'] = array_map('strip_tags', (new FetchSyncAdmin($module))->process());
        $out['pending'] = FetchSyncMirror::pending();
        $out['notice'] = (new FetchSyncAdmin($module))->mirrorNotice();
        $out['activity'] = array_column(FetchSyncLogger::activity(5), 'message');
        $out['settings_kept'] = FetchSyncSettings::isConnected() && FetchSyncSettings::mirrorMode();
        break;

    // --- Multistore --------------------------------------------------------------------

    case 'ms_setup':
        // Shop A (the default shop) is connected the 1.2.0 way, while there is one shop.
        Configuration::updateValue('PS_MAIL_METHOD', 3);
        FetchSyncSettings::update(array(
            'environment' => 'custom',
            'custom_base_url' => $mockUrl,
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'sync_products' => 'yes',
            'import_orders' => 'yes',
            'auto_accept' => 'no',
            'prep_minutes' => 20,
            'price_markup' => '0',
            'store_id' => 'store-1',
            'store_name' => 'Mock Shop',
        ));
        FetchSyncApi::forgetToken();
        $hook = (new FetchSyncApi())->registerWebhook(FetchSyncWebhook::url());
        FetchSyncSettings::update(array('webhook_secret' => $hook['body']['signingSecret'], 'webhook_registered' => time()));
        $tax = e2e_tax_group();
        $mug = e2e_simple('Shared Mug', 'MUG-1', 10.00, 5, $tax);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['cron_token'] = FetchSyncSettings::get('cron_token');

        // Multistore on, shop B in the default group on its own domain, with every
        // kind of data (the module's too, so actionShopDataDuplication runs).
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        $port = (int) $_GET['ps_port'];
        $all = array();
        foreach (array_keys(Shop::getAssoTables()) as $table) {
            $all[$table] = true;
        }
        $all['ModuleFetchsync'] = true;
        $default = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        $idB = ms_create_shop('Shop B', (int) $default->id_shop_group, '127.0.0.1:' . $port, '', $all);

        // Shop C in a group that shares stock, on shop A's domain under /c/, created
        // without the module's data: the module catches up lazily.
        $group = new ShopGroup();
        $group->name = 'Group 2';
        $group->share_customer = 1;
        $group->share_stock = 1;
        $group->share_order = 0;
        $group->active = 1;
        $group->add();
        $some = array();
        foreach (array('product', 'category', 'lang', 'currency', 'country', 'zone', 'group', 'tax_rules_group', 'attribute_group', 'feature', 'image', 'meta_lang') as $table) {
            $some[$table] = true;
        }
        $idC = ms_create_shop('Shop C', (int) $group->id, 'localhost:' . $port, 'c/', $some);

        // Shop B sells the mug at 12.00 with 3 in stock; shop C's group has 7.
        $db->update('product_shop', array('price' => round(12.00 / 1.19, 6)), 'id_product = ' . (int) $mug->id . ' AND id_shop = ' . $idB);
        StockAvailable::setQuantity((int) $mug->id, 0, 3, $idB);
        StockAvailable::setQuantity((int) $mug->id, 0, 7, $idC);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['shops'] = array('A' => (int) $default->id, 'B' => $idB, 'C' => $idC);
        $out['group_c'] = (int) $group->id;
        $out['mug'] = (int) $mug->id;
        $out['assoc_b'] = ms_associations($idB);
        $out['assoc_c_before'] = ms_associations($idC);
        break;

    case 'ms_add_shop':
        // Multistore turned on after the fact, with a copy of the default shop.
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        $all = array();
        foreach (array_keys(Shop::getAssoTables()) as $table) {
            $all[$table] = true;
        }
        $all['ModuleFetchsync'] = true;
        $default = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        $out['B'] = ms_create_shop('Shop B', (int) $default->id_shop_group, '127.0.0.1:' . (int) $_GET['ps_port'], '', $all);
        $out['assoc_b'] = ms_associations($out['B']);
        break;

    case 'ms_state':
        // A fresh request: multistore is now on.
        $shops = ms_shops();
        $out['multistore'] = FetchSyncSettings::multistore();
        $out['shops'] = $shops;
        foreach ($shops as $key => $idShop) {
            $out['settings'][$key] = ms_settings($idShop);
            $out['assoc'][$key] = ms_associations($idShop);
            $out['pending'][$key] = FetchSyncProductSync::pendingCount($idShop);
        }
        $out['connected'] = FetchSyncSettings::connectedShopIds();
        $out['legacy_adopted'] = (int) Configuration::getGlobalValue('FETCHSYNC_LEGACY_ADOPTED');
        $out['a_has_own_rows'] = (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'configuration WHERE name = \'FETCHSYNC_STORE_ID\' AND id_shop = ' . (int) $shops['A']);
        $out['cron_token'] = FetchSyncSettings::get('cron_token');
        $out['cron_url'] = FetchSyncCron::url();
        $out['activity'] = array_column(FetchSyncLogger::activity(10, null), 'message');
        break;

    case 'ms_adopt':
        $out['adopted'] = FetchSyncSettings::adoptLegacy();
        $out['legacy_adopted'] = (int) Configuration::getGlobalValue('FETCHSYNC_LEGACY_ADOPTED');
        break;

    case 'ms_save':
        // "Save settings" (or another button) for one shop; extra fields come from the query.
        $shops = ms_shops();
        $idShop = $shops[$_GET['shop']];
        $extra = isset($_GET['fields']) ? json_decode($_GET['fields'], true) : array();
        // Webhook URLs must be https; pretend the shop is.
        Configuration::set('PS_SSL_ENABLED', 1, 0, 0);
        Configuration::set('PS_SSL_ENABLED_EVERYWHERE', 1, 0, 0);
        $out['messages'] = ms_save($module, $idShop, $extra);
        $out['settings'] = ms_settings($idShop);
        $out['assoc'] = ms_associations($idShop);
        $out['webhook_url'] = FetchSyncSettings::withShop($idShop, function () {
            return FetchSyncWebhook::url();
        });
        break;

    case 'ms_touch':
        // A product edit in "All shops": every syncing shop that sells it is queued.
        $id = e2e_product_id((string) $_GET['ref']);
        $p = new Product($id);
        $p->update();
        $out['queued'] = array();
        foreach (ms_shops() as $key => $idShop) {
            $out['queued'][$key] = (bool) $db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'fetchsync_queue WHERE id_shop = ' . $idShop . ' AND id_product = ' . $id);
        }
        break;

    case 'ms_stock':
        $shops = ms_shops();
        $id = e2e_product_id((string) $_GET['ref']);
        $out['stock'] = array();
        foreach ($shops as $key => $idShop) {
            $out['stock'][$key] = (int) StockAvailable::getQuantityAvailableByProduct($id, 0, $idShop);
        }
        break;

    case 'ms_order':
        $idOrder = FetchSyncOrderSync::find((string) $_GET['order']);
        $out['id_order'] = $idOrder;
        if ($idOrder) {
            $order = new Order($idOrder);
            $customer = new Customer((int) $order->id_customer);
            $currency = new Currency((int) $order->id_currency);
            $out['id_shop'] = (int) $order->id_shop;
            $out['id_shop_group'] = (int) $order->id_shop_group;
            $out['id_lang'] = (int) $order->id_lang;
            $out['currency'] = $currency->iso_code;
            $out['customer_shop'] = (int) $customer->id_shop;
            $out['row'] = FetchSyncOrderSync::rowForOrder($idOrder);
            $out['state'] = (int) $order->current_state;
            $out['carrier'] = (int) $order->id_carrier;
            $out['fetch_carrier'] = (int) FetchSyncInstaller::carrier()->id;
            $out['items'] = array();
            foreach ($order->getProductsDetail() as $d) {
                $out['items'][] = array('product_id' => (int) $d['product_id'], 'reference' => $d['product_reference'], 'qty' => (int) $d['product_quantity'], 'total' => (float) $d['total_price_tax_incl']);
            }
            $out['count_by_transaction'] = (int) $db->getValue('SELECT COUNT(DISTINCT o.id_order) FROM ' . _DB_PREFIX_ . 'orders o INNER JOIN ' . _DB_PREFIX_ . 'order_payment op ON op.order_reference = o.reference WHERE op.transaction_id = \'' . pSQL((string) $_GET['order']) . '\'');
        }
        $out['states'] = e2e_states();
        foreach (ms_shops() as $key => $idShop) {
            $out['shop_lang'][$key] = FetchSyncSettings::withShop($idShop, function () {
                return FetchSyncSettings::defaultLangId();
            });
        }
        break;

    case 'ms_action':
        $idOrder = FetchSyncOrderSync::find((string) $_GET['order']);
        $out['error'] = FetchSyncOrderActions::run($idOrder, (string) $_GET['do'], array('prepMinutes' => 10));
        $out['row'] = FetchSyncOrderSync::rowForOrder($idOrder);
        break;

    case 'ms_full_sync':
        // "Sync all products now" on one shop, flushed to the end (mirror runs there).
        $shops = ms_shops();
        $idShop = $shops[$_GET['shop']];
        $out += FetchSyncContext::forShop($idShop, function () {
            return e2e_full_sync();
        });
        break;

    case 'ms_break_key':
        // Shop B's secret stops working (or works again).
        $shops = ms_shops();
        FetchSyncSettings::withShop($shops['B'], function () {
            FetchSyncSettings::update(array('client_secret' => 'bad' === $_GET['to'] ? 'not-the-secret' : 'secret-2'));
            FetchSyncApi::forgetToken();
            FetchSyncSettings::setInternal('BACKOFF_UNTIL', 0);
        });
        $db->execute('UPDATE ' . _DB_PREFIX_ . 'fetchsync_queue SET next_try = 0, attempts = 0');
        break;

    case 'ms_overview':
        Shop::setContext(Shop::CONTEXT_ALL);
        $admin = new FetchSyncAdmin($module);
        $out['context_shop'] = FetchSyncAdmin::contextShop();
        $html = $admin->overview();
        $out['rows'] = array();
        if (preg_match_all('~<tr data-shop="(\d+)">(.*?)</tr>~s', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $cells = array();
                preg_match_all('~<td[^>]*>(.*?)</td>~s', $row[2], $c);
                foreach ($c[1] as $cell) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8')));
                }
                $out['rows'][(int) $row[1]] = $cells;
            }
        }
        $out['edit_links'] = preg_match_all('~setShopContext=s-\d+~', $html);
        break;

    // --- Exclude backorder items ---------------------------------------------------------

    case 'bo_create':
        // A product with an out-of-stock behaviour (0 deny, 1 allow, 2 default), optionally with
        // two combinations (quantities in "combos", e.g. "3,0"), flushed to Fetch.
        $tax = e2e_tax_group();
        $p = e2e_simple('Backorder ' . $_GET['ref'], (string) $_GET['ref'], 10.00, (int) $_GET['qty'], $tax);
        if (!empty($_GET['combos'])) {
            $lang = (int) Configuration::get('PS_LANG_DEFAULT');
            foreach (explode(',', (string) $_GET['combos']) as $i => $qty) {
                $colour = 0 === $i ? 'Black' : 'White';
                $idAttribute = (int) $db->getValue(
                    'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
                    INNER JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $lang . '
                    WHERE al.name = \'' . pSQL($colour) . '\' ORDER BY a.id_attribute'
                );
                $ipa = $p->addCombinationEntity(0, 0, 0, 0, 0, 0, array(), $_GET['ref'] . '-' . strtoupper($colour), 0, '', 0 === $i);
                $combination = new Combination((int) $ipa);
                $combination->setAttributes(array($idAttribute));
                StockAvailable::setQuantity((int) $p->id, (int) $ipa, (int) $qty);
            }
        }
        StockAvailable::setProductOutOfStock((int) $p->id, (int) $_GET['oos']);
        $out['id'] = (int) $p->id;
        $out['sent'] = FetchSyncProductSync::flush(30);
        $out['rows'] = $db->executeS('SELECT id_product_attribute AS ipa, ref, status FROM ' . _DB_PREFIX_ . 'fetchsync_product WHERE id_shop = ' . FetchSyncSettings::shopId() . ' AND id_product = ' . (int) $p->id . ' ORDER BY id_product_attribute');
        break;

    case 'bo_stock':
        // New quantity (of a combination when "combo" is a reference), flushed.
        $id = e2e_product_id((string) $_GET['ref']);
        $ipa = !empty($_GET['combo']) ? e2e_ipa((string) $_GET['combo']) : 0;
        StockAvailable::setQuantity($id, $ipa, (int) $_GET['qty']);
        $out['sent'] = FetchSyncProductSync::flush(30);
        break;

    case 'bo_oos':
        StockAvailable::setProductOutOfStock(e2e_product_id((string) $_GET['ref']), (int) $_GET['oos']);
        FetchSyncProductSync::queue(e2e_product_id((string) $_GET['ref']));
        $out['sent'] = FetchSyncProductSync::flush(30);
        break;

    case 'bo_config':
        Configuration::updateValue((string) $_GET['name'], (string) $_GET['value']);
        $out['value'] = Configuration::get((string) $_GET['name']);
        break;

    case 'bo_save':
        // The settings form with "Exclude backorder items" on or off; a change resends the catalogue.
        $_POST = e2e_settings_post(array('submitFetchsyncSave' => '1', 'fetchsync_exclude_backorders' => (string) $_GET['on']));
        $out['messages'] = array_map(function ($html) {
            return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        }, (new FetchSyncAdmin($module))->process());
        for ($i = 0; $i < 20 && FetchSyncSettings::internal('BULK_RUNNING'); ++$i) {
            FetchSyncProductSync::flush(60);
        }
        $out['setting'] = FetchSyncSettings::get('exclude_backorders');
        $out['finished'] = !FetchSyncSettings::internal('BULK_RUNNING');
        break;

    case 'bo_ms_create':
        // One product in shops A, B and C: A allows orders (out_of_stock 1), B and C follow the shop's
        // "allow ordering of out-of-stock products", which B refuses and C allows. All have no stock.
        $shops = ms_shops();
        $tax = e2e_tax_group();
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $p = new Product();
        $p->name = array($lang => 'Backorder MS');
        $p->link_rewrite = array($lang => 'backorder-ms');
        $p->reference = 'BO-MS';
        $p->price = round(10.00 / 1.19, 6);
        $p->id_tax_rules_group = $tax;
        $p->active = 1;
        $p->visibility = 'both';
        $p->available_for_order = 1;
        $p->show_price = 1;
        $p->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
        $p->id_shop_list = array_values($shops);
        $p->add();
        $p->addToCategories(array((int) Configuration::get('PS_HOME_CATEGORY')));
        $groupC = (int) Shop::getGroupFromShop($shops['C']);
        Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 0, false, (int) Shop::getGroupFromShop($shops['B']), $shops['B']);
        Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 1, false, $groupC, $shops['C']);
        Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 0, false, (int) Shop::getGroupFromShop($shops['A']), $shops['A']);
        foreach ($shops as $idShop) {
            StockAvailable::setQuantity((int) $p->id, 0, 0, $idShop);
        }
        StockAvailable::setProductOutOfStock((int) $p->id, 1, $shops['A']);
        StockAvailable::setProductOutOfStock((int) $p->id, 2, $shops['B']);
        StockAvailable::setProductOutOfStock((int) $p->id, 2, $shops['C']);
        $out['id'] = (int) $p->id;
        $out['shops'] = $shops;
        $out['in_shop'] = array();
        foreach ($shops as $key => $idShop) {
            $out['in_shop'][$key] = FetchSyncProductMapper::inShop((int) $p->id, $idShop);
        }
        break;

    case 'bo_ms_stock':
        $shops = ms_shops();
        StockAvailable::setQuantity(e2e_product_id('BO-MS'), 0, (int) $_GET['qty'], $shops[$_GET['shop']]);
        break;

    case 'bo_ms_rows':
        $out['rows'] = $db->executeS('SELECT id_shop, ref, status FROM ' . _DB_PREFIX_ . 'fetchsync_product WHERE ref = \'BO-MS\' ORDER BY id_shop');
        break;

    case 'upgrade':
        // What PrestaShop does after new module files are in place: run the upgrade scripts.
        $module = Module::getInstanceByName('fetchsync');
        $module->installed = true;
        $module->database_version = (string) $db->getValue('SELECT version FROM ' . _DB_PREFIX_ . 'module WHERE name = \'fetchsync\'');
        $out['from'] = $module->database_version;
        $out['needed'] = (bool) Module::initUpgradeModule($module);
        $result = $module->runUpgradeModule();
        $out['success'] = (bool) $result['success'];
        $out['upgraded_to'] = $result['upgraded_to'];
        $out['version'] = (string) $db->getValue('SELECT version FROM ' . _DB_PREFIX_ . 'module WHERE name = \'fetchsync\'');
        $out['active'] = (bool) $db->getValue('SELECT active FROM ' . _DB_PREFIX_ . 'module WHERE name = \'fetchsync\'');
        break;

    case 'schema':
        foreach (array('fetchsync_queue', 'fetchsync_deactivation', 'fetchsync_product', 'fetchsync_order', 'fetchsync_log') as $table) {
            $out['id_shop'][$table] = (bool) $db->executeS('SHOW COLUMNS FROM ' . _DB_PREFIX_ . $table . ' LIKE \'id_shop\'');
            $out['keys'][$table] = array_values(array_unique(array_column($db->executeS('SHOW INDEX FROM ' . _DB_PREFIX_ . $table . ' WHERE Key_name = \'PRIMARY\''), 'Column_name')));
        }
        $out['product_rows'] = $db->executeS('SELECT id_shop, id_product, ref FROM ' . _DB_PREFIX_ . 'fetchsync_product ORDER BY id_product');
        $out['order_rows'] = $db->executeS('SELECT id_shop, fetch_order_id, id_order FROM ' . _DB_PREFIX_ . 'fetchsync_order');
        $out['hooked'] = in_array('actionShopDataDuplication', array_column($db->executeS('SELECT h.name FROM ' . _DB_PREFIX_ . 'hook_module hm INNER JOIN ' . _DB_PREFIX_ . 'hook h ON h.id_hook = hm.id_hook WHERE hm.id_module = ' . (int) Module::getModuleIdByName('fetchsync')), 'name'), true);
        break;

    case 'state':
        $out['settings'] = FetchSyncSettings::all();
        unset($out['settings']['client_secret'], $out['settings']['webhook_secret']);
        $out['activity'] = FetchSyncLogger::activity(30);
        $out['queue'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        $out['exclude_rows'] = $db->executeS('SELECT id_shop, value FROM ' . _DB_PREFIX_ . 'configuration WHERE name = \'FETCHSYNC_EXCLUDE_BACKORDERS\' ORDER BY id_shop');
        break;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
