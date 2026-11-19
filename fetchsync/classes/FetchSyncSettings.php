<?php
/**
 * Module settings (FETCHSYNC_* Configuration values).
 *
 * Every shop of a multistore installation has its own connection, Fetch
 * store and options, stored as PrestaShop's own per-shop Configuration rows.
 * Code runs "in" one shop at a time (withShop()); get() and update() read and
 * write that shop's values. The cron token and the ids of the module's own
 * objects (order states, carrier, placeholder product) are shared by all
 * shops and stay global.
 *
 * Without multistore PrestaShop keeps every value global, exactly as 1.2.0
 * did. When multistore is turned on later, those global values belong to the
 * default shop only (adoptLegacy()); other shops start unconfigured.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncSettings
{
    const PREFIX = 'FETCHSYNC_';

    /** Catalogue modes: add and update only (default), or mirror the shop 1:1. */
    const MODE_ADD = 'add_update';
    const MODE_MIRROR = 'mirror';

    /**
     * Sandbox keys are issued on production too (the key, not the server,
     * decides test data), so staging is only for keys Fetch moved there.
     */
    const ENVIRONMENTS = array(
        'production' => 'https://api.fetch.com.cy',
        'staging' => 'https://api-stg.fetch.com.cy',
    );

    /** Internal state that is not a merchant-facing setting but is removed on uninstall too. */
    const INTERNAL_KEYS = array(
        'TOKEN',
        'STORES',
        'BACKOFF_UNTIL',
        'LAST_TICK',
        'SALES_CHECKED',
        'BULK_RUNNING',
        'BULK_FAILED',
        'MIRROR_PENDING',
        'PLACEHOLDER_ID',
        'CARRIER_REF',
        'OS_AWAITING',
        'OS_ACCEPTED',
        'OS_DELIVERED',
        'OS_REFUNDED',
        'LEGACY_ADOPTED',
    );

    /** Keys (without the prefix) shared by every shop; all the others are per shop. */
    const GLOBAL_KEYS = array(
        'CRON_TOKEN',
        'LAST_TICK',
        'SALES_CHECKED',
        'PLACEHOLDER_ID',
        'CARRIER_REF',
        'OS_AWAITING',
        'OS_ACCEPTED',
        'OS_DELIVERED',
        'OS_REFUNDED',
        'LEGACY_ADOPTED',
    );

    /** @var int|null shop the code is running for; null = the default shop */
    private static $scope = null;

    public static function defaults()
    {
        return array(
            'environment' => 'production',
            'custom_base_url' => '',
            'client_id' => '',
            'client_secret' => '',
            'store_id' => '',
            'store_name' => '',
            'sync_products' => 'yes',
            'import_orders' => 'yes',
            'auto_accept' => 'no',
            'prep_minutes' => 15,
            'price_markup' => '0',
            'catalogue_mode' => self::MODE_ADD,
            'exclude_backorders' => 'yes',
            'webhook_secret' => '',
            'webhook_registered' => '',
            'cron_token' => '',
        );
    }

    public static function key($name)
    {
        return self::PREFIX . strtoupper($name);
    }

    // --- Shops -------------------------------------------------------------------

    /** The shop settings are read for right now. */
    public static function shopId()
    {
        return null !== self::$scope ? self::$scope : self::defaultShopId();
    }

    /** The shop of the current withShop() call, or 0 outside one. */
    public static function scoped()
    {
        return null !== self::$scope ? self::$scope : 0;
    }

    /** Run $callback with settings read and written for $idShop. */
    public static function withShop($idShop, $callback)
    {
        $saved = self::$scope;
        self::$scope = (int) $idShop;
        try {
            return call_user_func($callback, (int) $idShop);
        } finally {
            self::$scope = $saved;
        }
    }

    public static function multistore()
    {
        return class_exists('Shop') && Shop::isFeatureActive();
    }

    /** Every shop of the installation; only the default shop without multistore. */
    public static function shopIds()
    {
        if (!self::multistore()) {
            return array(self::defaultShopId());
        }
        $ids = array_map('intval', array_values(Shop::getShops(false, null, true)));
        sort($ids);

        return $ids;
    }

    /** Shops with a client ID, secret and Fetch store. */
    public static function connectedShopIds()
    {
        $out = array();
        foreach (self::shopIds() as $idShop) {
            if (self::withShop($idShop, function () {
                return FetchSyncSettings::isConnected();
            })) {
                $out[] = $idShop;
            }
        }

        return $out;
    }

    /** The connected shops linked to this Fetch store (normally at most one). */
    public static function shopsForStore($storeId)
    {
        $out = array();
        if ('' === (string) $storeId) {
            return $out;
        }
        foreach (self::connectedShopIds() as $idShop) {
            if ((string) self::getFor($idShop, 'store_id') === (string) $storeId) {
                $out[] = $idShop;
            }
        }

        return $out;
    }

    /**
     * Another shop already connected to this Fetch store on this server, or 0.
     * A Fetch store belongs to one shop: two shops would fight over its catalogue.
     */
    public static function storeOwner($storeId, $baseUrl, $exceptShop)
    {
        if ('' === (string) $storeId) {
            return 0;
        }
        foreach (self::shopIds() as $idShop) {
            if ($idShop === (int) $exceptShop) {
                continue;
            }
            $same = self::withShop($idShop, function () use ($storeId, $baseUrl) {
                return (string) FetchSyncSettings::get('store_id') === (string) $storeId
                    && FetchSyncSettings::baseUrl() === rtrim((string) $baseUrl, '/');
            });
            if ($same) {
                return $idShop;
            }
        }

        return 0;
    }

    public static function shopName($idShop)
    {
        if (!class_exists('Shop')) {
            return '#' . (int) $idShop;
        }
        $shops = Shop::getShops(false);
        if (isset($shops[$idShop]['name'])) {
            return (string) $shops[$idShop]['name'];
        }

        return '#' . (int) $idShop;
    }

    // --- Values --------------------------------------------------------------------

    public static function all()
    {
        $out = array();
        foreach (array_keys(self::defaults()) as $name) {
            $out[$name] = self::get($name);
        }

        return $out;
    }

    public static function get($name)
    {
        $value = self::read(self::key($name), self::shopId());
        if (false === $value || null === $value) {
            $defaults = self::defaults();

            return isset($defaults[$name]) ? $defaults[$name] : null;
        }

        return $value;
    }

    public static function getFor($idShop, $name)
    {
        return self::withShop($idShop, function () use ($name) {
            return FetchSyncSettings::get($name);
        });
    }

    public static function update(array $values)
    {
        foreach ($values as $name => $value) {
            self::write(self::key($name), (string) $value, self::shopId());
        }
    }

    /** Internal values (token cache, state ids, timestamps). */
    public static function internal($name, $default = null)
    {
        $value = self::read(self::PREFIX . $name, self::shopId());

        return false === $value || null === $value ? $default : $value;
    }

    public static function setInternal($name, $value)
    {
        self::write(self::PREFIX . $name, (string) $value, self::shopId());
    }

    public static function deleteAll()
    {
        foreach (array_keys(self::defaults()) as $name) {
            Configuration::deleteByName(self::key($name));
        }
        foreach (self::INTERNAL_KEYS as $name) {
            Configuration::deleteByName(self::PREFIX . $name);
        }
    }

    public static function isGlobalKey($key)
    {
        return in_array(substr((string) $key, strlen(self::PREFIX)), self::GLOBAL_KEYS, true);
    }

    /**
     * One shop's value. With multistore only the shop's own row counts (no
     * fall-back to the group or to all shops, so a new shop never inherits
     * another shop's Fetch store), except that the default shop keeps the
     * global values 1.2.0 wrote until adoptLegacy() has copied them.
     */
    private static function read($key, $idShop)
    {
        if (self::isGlobalKey($key) || !self::multistore()) {
            return Configuration::getGlobalValue($key);
        }
        Configuration::get('PS_SHOP_DEFAULT'); // loads the cache hasKey() reads
        if (Configuration::hasKey($key, null, null, $idShop)) {
            return Configuration::get($key, null, (int) Shop::getGroupFromShop($idShop), $idShop);
        }
        if ($idShop === self::defaultShopId() && !Configuration::getGlobalValue(self::PREFIX . 'LEGACY_ADOPTED')) {
            return Configuration::getGlobalValue($key);
        }

        return false;
    }

    private static function write($key, $value, $idShop)
    {
        if (self::isGlobalKey($key) || !self::multistore()) {
            Configuration::updateGlobalValue($key, $value);

            return;
        }
        $idGroup = (int) Shop::getGroupFromShop($idShop);
        Configuration::get('PS_SHOP_DEFAULT');
        if (!Configuration::hasKey($key, null, null, $idShop) && (string) Configuration::get($key, null, $idGroup, $idShop) === $value) {
            // PrestaShop 1.7 skips a write equal to the inherited (global) value,
            // which would leave the shop without its own row: add the row itself.
            $row = new Configuration();
            $row->name = $key;
            $row->value = $value;
            $row->id_shop_group = $idGroup;
            $row->id_shop = $idShop;
            $row->add();
            Configuration::set($key, $value, $idGroup, $idShop);

            return;
        }
        Configuration::updateValue($key, $value, false, $idGroup, $idShop);
    }

    /**
     * Multistore was turned on: the settings 1.2.0 (or a single-shop install)
     * kept globally become the default shop's own rows. The global rows stay,
     * so turning multistore off again finds them.
     */
    public static function adoptLegacy()
    {
        if (!self::multistore() || Configuration::getGlobalValue(self::PREFIX . 'LEGACY_ADOPTED')) {
            return false;
        }
        $idShop = self::defaultShopId();
        $keys = array();
        foreach (array_keys(self::defaults()) as $name) {
            $keys[] = self::key($name);
        }
        foreach (self::INTERNAL_KEYS as $name) {
            $keys[] = self::PREFIX . $name;
        }
        Configuration::get('PS_SHOP_DEFAULT');
        foreach ($keys as $key) {
            if (self::isGlobalKey($key) || Configuration::hasKey($key, null, null, $idShop)) {
                continue;
            }
            $value = Configuration::getGlobalValue($key);
            if (false !== $value && null !== $value) {
                self::write($key, (string) $value, $idShop);
            }
        }
        Configuration::updateGlobalValue(self::PREFIX . 'LEGACY_ADOPTED', (string) $idShop);

        return true;
    }

    // --- Rules -----------------------------------------------------------------------

    /** "production", "staging" or "custom"; anything else is production. */
    public static function sanitizeEnvironment($raw)
    {
        $env = (string) $raw;

        return 'custom' === $env || isset(self::ENVIRONMENTS[$env]) ? $env : 'production';
    }

    public static function baseUrl()
    {
        $env = self::sanitizeEnvironment(self::get('environment'));
        if ('custom' === $env) {
            return rtrim((string) self::get('custom_base_url'), '/');
        }

        return self::ENVIRONMENTS[$env];
    }

    public static function isConnected()
    {
        return '' !== (string) self::get('client_id') && '' !== (string) self::get('client_secret') && '' !== (string) self::get('store_id');
    }

    public static function enabled($name)
    {
        return 'yes' === self::get($name);
    }

    /** Price markup in percent, 0–100 with at most 2 decimals. */
    public static function markup()
    {
        return self::sanitizeMarkup(self::get('price_markup'));
    }

    /**
     * Same rules as the WooCommerce plugin: accepts "12,5" and a trailing "%";
     * blank or non-numeric is 0; clamped to 0–100; 2 decimals.
     */
    public static function sanitizeMarkup($raw)
    {
        $value = str_replace(array(',', '%', ' '), array('.', '', ''), trim((string) $raw));
        if ('' === $value || !is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, (float) $value)), 2);
    }

    /** True when the input is blank or a number (so the admin can warn about anything else). */
    public static function isMarkupInput($raw)
    {
        $value = str_replace(array(',', '%', ' '), array('.', '', ''), trim((string) $raw));

        return '' === $value || is_numeric($value);
    }

    /** "mirror" or "add_update"; anything else is the default, add and update only. */
    public static function sanitizeCatalogueMode($raw)
    {
        return self::MODE_MIRROR === (string) $raw ? self::MODE_MIRROR : self::MODE_ADD;
    }

    public static function mirrorMode()
    {
        return self::MODE_MIRROR === self::sanitizeCatalogueMode(self::get('catalogue_mode'));
    }

    public static function defaultShopId()
    {
        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    /** Default language of the current shop. */
    public static function defaultLangId()
    {
        return self::shopConfig('PS_LANG_DEFAULT');
    }

    /** Default currency of the current shop. */
    public static function currencyId()
    {
        return self::shopConfig('PS_CURRENCY_DEFAULT');
    }

    /** Default country of the current shop. */
    public static function countryId()
    {
        return self::shopConfig('PS_COUNTRY_DEFAULT');
    }

    /** A PrestaShop setting as the current shop sees it (its own value, its group's, or the global one). */
    public static function shopConfig($key)
    {
        if (!self::multistore()) {
            return (int) Configuration::get($key);
        }
        $idShop = self::shopId();

        return (int) Configuration::get($key, null, (int) Shop::getGroupFromShop($idShop), $idShop);
    }
}
