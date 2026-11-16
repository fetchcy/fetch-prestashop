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

    case 'state':
        $out['settings'] = FetchSyncSettings::all();
        unset($out['settings']['client_secret'], $out['settings']['webhook_secret']);
        $out['activity'] = FetchSyncLogger::activity(30);
        $out['queue'] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fetchsync_queue');
        break;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
