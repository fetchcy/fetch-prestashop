<?php
/**
 * Tables, order states, the Fetch carrier and the placeholder product.
 *
 * Uninstall drops the module's tables and settings and retires the carrier.
 * Order states and the placeholder product stay: existing orders use them.
 *
 * Order states are global in PrestaShop. The carrier and the placeholder
 * product are associated with every shop, including shops created later
 * (actionShopDataDuplication, and ensureShop() before a shop is configured or
 * gets an order).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncInstaller
{
    const TABLES = array(
        'fetchsync_queue',
        'fetchsync_deactivation',
        'fetchsync_product',
        'fetchsync_order',
        'fetchsync_event',
        'fetchsync_log',
    );

    /** Module order states, found again on reinstall by module name + colour. */
    const STATES = array(
        'OS_AWAITING' => array('name' => 'Fetch: waiting for you to accept', 'color' => '#FF8C00', 'logable' => 1, 'paid' => 1, 'shipped' => 0, 'delivery' => 0),
        'OS_ACCEPTED' => array('name' => 'Fetch: accepted', 'color' => '#3E7CC9', 'logable' => 1, 'paid' => 1, 'shipped' => 0, 'delivery' => 0),
        'OS_DELIVERED' => array('name' => 'Fetch: delivered', 'color' => '#2E8B57', 'logable' => 1, 'paid' => 1, 'shipped' => 1, 'delivery' => 1),
        'OS_REFUNDED' => array('name' => 'Fetch: refunded', 'color' => '#C0392B', 'logable' => 0, 'paid' => 1, 'shipped' => 0, 'delivery' => 0),
    );

    public static function install()
    {
        return self::createTables()
            && self::installPlaceholder()
            && self::installStates()
            && self::installCarrier()
            && self::installDefaults();
    }

    public static function uninstall()
    {
        // Retired the way PrestaShop retires carriers: orders keep pointing at it.
        Db::getInstance()->update('carrier', array('deleted' => 1, 'active' => 0), '`external_module_name` = \'fetchsync\'');
        $ok = true;
        foreach (self::TABLES as $table) {
            $ok = Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`') && $ok;
        }
        FetchSyncSettings::deleteAll();

        return $ok;
    }

    public static function createTables()
    {
        $engine = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_;
        $sql = array(
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_queue` (
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_product` INT UNSIGNED NOT NULL,
                `version` INT UNSIGNED NOT NULL DEFAULT 1,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_try` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`, `id_product`),
                KEY `next_try` (`next_try`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_deactivation` (
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `ref` VARCHAR(191) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_try` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `ref`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_product` (
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `ref` VARCHAR(191) NOT NULL DEFAULT '',
                `fetch_product_id` VARCHAR(64) NOT NULL DEFAULT '',
                `status` VARCHAR(16) NOT NULL DEFAULT '',
                `error` TEXT NULL,
                `synced_at` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `id_product`, `id_product_attribute`),
                KEY `ref` (`id_shop`, `ref`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_order` (
                `fetch_order_id` VARCHAR(64) NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `fetch_order_number` VARCHAR(64) NOT NULL DEFAULT '',
                `fetch_store_id` VARCHAR(64) NOT NULL DEFAULT '',
                `fetch_status` VARCHAR(32) NOT NULL DEFAULT '',
                `customer_masked` TINYINT(1) NOT NULL DEFAULT 0,
                `restocked` TINYINT(1) NOT NULL DEFAULT 0,
                `date_add` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`fetch_order_id`),
                KEY `id_order` (`id_order`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_event` (
                `event_id` VARCHAR(191) NOT NULL,
                `received_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `received_at` (`received_at`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}fetchsync_log` (
                `id_fetchsync_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `level` VARCHAR(8) NOT NULL,
                `message` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_fetchsync_log`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        );
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /** Hidden product that carries Fetch lines matching no product of the shop. */
    public static function installPlaceholder()
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = \'' . pSQL(FetchSyncOrderSync::PLACEHOLDER_REF) . '\''
        );
        if (!$id) {
            $product = new Product();
            $product->name = self::langField('Fetch item (not in your catalogue)');
            $product->link_rewrite = self::langField('fetch-item');
            $product->reference = FetchSyncOrderSync::PLACEHOLDER_REF;
            $product->price = 0;
            $product->id_tax_rules_group = 0;
            $product->active = 0;
            $product->visibility = 'none';
            $product->available_for_order = 1;
            $product->show_price = 0;
            $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
            if (property_exists($product, 'product_type')) {
                $product->product_type = 'standard';
            }
            if (!$product->add()) {
                return false;
            }
            $product->addToCategories(array((int) Configuration::get('PS_HOME_CATEGORY')));
            $id = (int) $product->id;
        }
        StockAvailable::setProductOutOfStock($id, 1);
        FetchSyncSettings::setInternal('PLACEHOLDER_ID', $id);

        return true;
    }

    public static function installStates()
    {
        foreach (self::STATES as $key => $def) {
            $id = (int) Db::getInstance()->getValue(
                'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state`
                WHERE `module_name` = \'fetchsync\' AND `color` = \'' . pSQL($def['color']) . '\' AND `deleted` = 0'
            );
            if (!$id) {
                $state = new OrderState();
                $state->name = self::langField($def['name']);
                $state->color = $def['color'];
                $state->module_name = 'fetchsync';
                $state->send_email = 0;
                $state->invoice = 0;
                $state->logable = $def['logable'];
                $state->paid = $def['paid'];
                $state->shipped = $def['shipped'];
                $state->delivery = $def['delivery'];
                $state->hidden = 0;
                $state->unremovable = 1;
                $state->pdf_invoice = 0;
                $state->pdf_delivery = 0;
                $state->template = self::langField('');
                if (!$state->add()) {
                    return false;
                }
                $id = (int) $state->id;
            }
            FetchSyncSettings::setInternal($key, $id);
        }

        return true;
    }

    /**
     * "Fetch delivery": an external-module carrier whose cost is the Fetch
     * delivery fee while an order is being imported, and which is unavailable
     * (cost false) at the shop's own checkout.
     */
    public static function installCarrier()
    {
        $carrier = self::carrier();
        if (!Validate::isLoadedObject($carrier)) {
            $carrier = new Carrier();
            $carrier->name = 'Fetch delivery';
            $carrier->delay = self::langField('Delivered by Fetch');
            $carrier->active = 1;
            $carrier->deleted = 0;
            $carrier->is_module = 1;
            $carrier->shipping_external = 1;
            $carrier->external_module_name = 'fetchsync';
            $carrier->need_range = 1;
            $carrier->shipping_handling = 0;
            $carrier->is_free = 0;
            $carrier->range_behavior = 0;
            $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
            $carrier->id_tax_rules_group = 0;
            $carrier->url = '';
            if (!$carrier->add()) {
                return false;
            }
            $groups = array();
            foreach (Group::getGroups(FetchSyncSettings::defaultLangId()) as $group) {
                $groups[] = (int) $group['id_group'];
            }
            $carrier->setGroups($groups);
            // One open price range at 0 in every zone, or PrestaShop treats the carrier as out of range.
            $range = new RangePrice();
            $range->id_carrier = (int) $carrier->id;
            $range->delimiter1 = 0;
            $range->delimiter2 = 1000000000;
            $range->add();
            // addZone() also gives each zone a 0 price on the range above (for every shop).
            foreach (Zone::getZones() as $zone) {
                $carrier->addZone((int) $zone['id_zone']);
            }
            $carrier = new Carrier((int) $carrier->id);
        }
        FetchSyncSettings::setInternal('CARRIER_REF', (int) ($carrier->id_reference ? $carrier->id_reference : $carrier->id));

        return true;
    }

    /** @return Carrier|null the current (non-deleted) Fetch carrier */
    public static function carrier()
    {
        $reference = (int) FetchSyncSettings::internal('CARRIER_REF', 0);
        if ($reference) {
            $carrier = Carrier::getCarrierByReference($reference);
            if ($carrier instanceof Carrier && !$carrier->deleted) {
                return $carrier;
            }
        }
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE `external_module_name` = \'fetchsync\' AND `deleted` = 0 ORDER BY `id_carrier` DESC'
        );

        return $id ? new Carrier($id) : null;
    }

    public static function installDefaults()
    {
        $values = array();
        if (!Configuration::getGlobalValue(FetchSyncSettings::key('cron_token'))) {
            $values['cron_token'] = Tools::passwdGen(32);
        }
        // Without multistore the defaults are stored the way 1.2.0 stored them.
        if (!FetchSyncSettings::multistore()) {
            foreach (FetchSyncSettings::defaults() as $name => $default) {
                if ('cron_token' !== $name && false === Configuration::getGlobalValue(FetchSyncSettings::key($name))) {
                    $values[$name] = $default;
                }
            }
        }
        FetchSyncSettings::update($values);
        FetchSyncSettings::adoptLegacy();

        return true;
    }

    public static function ensureAllShops()
    {
        $ok = true;
        foreach (FetchSyncSettings::shopIds() as $idShop) {
            $ok = self::ensureShop($idShop) && $ok;
        }

        return $ok;
    }

    /**
     * Associate the module, its hooks, the carrier and the placeholder product
     * with a shop (one created after install, or created without the module's
     * data). Cheap when everything is already there.
     */
    public static function ensureShop($idShop)
    {
        $idShop = (int) $idShop;
        if ($idShop <= 0) {
            return false;
        }
        $db = Db::getInstance();
        $ok = true;

        $module = Module::getInstanceByName('fetchsync');
        if ($module && $module->id) {
            if (!$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $module->id . ' AND `id_shop` = ' . $idShop)) {
                $ok = $db->insert('module_shop', array('id_module' => (int) $module->id, 'id_shop' => $idShop)) && $ok;
            }
            $hooked = $db->executeS(
                'SELECT LOWER(h.`name`) AS `name` FROM `' . _DB_PREFIX_ . 'hook_module` hm
                INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`
                WHERE hm.`id_module` = ' . (int) $module->id . ' AND hm.`id_shop` = ' . $idShop
            );
            $hooked = array_column(is_array($hooked) ? $hooked : array(), 'name');
            $missing = array_values(array_filter(Fetchsync::HOOKS, function ($hook) use ($hooked) {
                return !in_array(strtolower($hook), $hooked, true);
            }));
            if ($missing) {
                $ok = $module->registerHook($missing, array($idShop)) && $ok;
            }
        }

        $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);
        if ($placeholder) {
            if (!$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . $placeholder . ' AND `id_shop` = ' . $idShop)) {
                $from = self::sourceShop('product_shop', '`id_product` = ' . $placeholder);
                if ($from) {
                    $ok = self::copyShopRows('product_shop', '`id_product` = ' . $placeholder, $from, $idShop) && $ok;
                    $ok = self::copyShopRows('product_lang', '`id_product` = ' . $placeholder, $from, $idShop) && $ok;
                }
            }
            StockAvailable::setProductOutOfStock($placeholder, 1, $idShop);
        }

        $carrier = self::carrier();
        if (Validate::isLoadedObject($carrier)) {
            $where = '`id_carrier` = ' . (int) $carrier->id;
            if (!$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'carrier_shop` WHERE ' . $where . ' AND `id_shop` = ' . $idShop)) {
                $ok = $db->insert('carrier_shop', array('id_carrier' => (int) $carrier->id, 'id_shop' => $idShop)) && $ok;
            }
            if (!$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'carrier_lang` WHERE ' . $where . ' AND `id_shop` = ' . $idShop)) {
                $from = self::sourceShop('carrier_lang', $where);
                if ($from) {
                    $ok = self::copyShopRows('carrier_lang', $where, $from, $idShop) && $ok;
                }
            }
            if (!$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop` WHERE ' . $where . ' AND `id_shop` = ' . $idShop)) {
                $from = self::sourceShop('carrier_tax_rules_group_shop', $where);
                if ($from) {
                    $ok = self::copyShopRows('carrier_tax_rules_group_shop', $where, $from, $idShop) && $ok;
                }
            }
        }

        return (bool) $ok;
    }

    /** The default shop when it has the rows, otherwise the first shop that has them. */
    private static function sourceShop($table, $where)
    {
        $rows = Db::getInstance()->executeS('SELECT DISTINCT `id_shop` FROM `' . _DB_PREFIX_ . $table . '` WHERE ' . $where . ' ORDER BY `id_shop`');
        $ids = array_map('intval', array_column(is_array($rows) ? $rows : array(), 'id_shop'));
        $default = (int) Configuration::get('PS_SHOP_DEFAULT');

        return in_array($default, $ids, true) ? $default : ($ids ? $ids[0] : 0);
    }

    /** Copy one shop's rows of a *_shop or *_lang table to another shop, column for column. */
    private static function copyShopRows($table, $where, $fromShop, $toShop)
    {
        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`');
        $names = array();
        $select = array();
        foreach (is_array($columns) ? $columns : array() as $column) {
            $names[] = '`' . bqSQL($column['Field']) . '`';
            $select[] = 'id_shop' === $column['Field'] ? (int) $toShop : '`' . bqSQL($column['Field']) . '`';
        }
        if (!$names) {
            return false;
        }

        return Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . $table . '` (' . implode(', ', $names) . ')
            SELECT ' . implode(', ', $select) . ' FROM `' . _DB_PREFIX_ . $table . '`
            WHERE ' . $where . ' AND `id_shop` = ' . (int) $fromShop
        );
    }

    private static function langField($value)
    {
        $out = array((int) Configuration::get('PS_LANG_DEFAULT') => $value);
        foreach ((array) Db::getInstance()->executeS('SELECT `id_lang` FROM `' . _DB_PREFIX_ . 'lang`') as $row) {
            $out[(int) $row['id_lang']] = $value;
        }

        return $out;
    }
}
