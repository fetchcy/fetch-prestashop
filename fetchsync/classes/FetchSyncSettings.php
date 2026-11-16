<?php
/**
 * Module settings, stored as global Configuration values (FETCHSYNC_*).
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
    );

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
            'webhook_secret' => '',
            'webhook_registered' => '',
            'cron_token' => '',
        );
    }

    public static function key($name)
    {
        return self::PREFIX . strtoupper($name);
    }

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
        $value = Configuration::getGlobalValue(self::key($name));
        if (false === $value || null === $value) {
            $defaults = self::defaults();

            return isset($defaults[$name]) ? $defaults[$name] : null;
        }

        return $value;
    }

    public static function update(array $values)
    {
        foreach ($values as $name => $value) {
            Configuration::updateGlobalValue(self::key($name), (string) $value);
        }
    }

    /** Internal values (token cache, state ids, timestamps). */
    public static function internal($name, $default = null)
    {
        $value = Configuration::getGlobalValue(self::PREFIX . $name);

        return false === $value || null === $value ? $default : $value;
    }

    public static function setInternal($name, $value)
    {
        Configuration::updateGlobalValue(self::PREFIX . $name, (string) $value);
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

    public static function defaultLangId()
    {
        return (int) Configuration::get('PS_LANG_DEFAULT');
    }
}
