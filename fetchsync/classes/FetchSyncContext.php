<?php
/**
 * Makes sure the global Context has a shop, language, currency, country and
 * link when the module runs outside a normal page (webhook, cron, shutdown).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncContext
{
    public static function background()
    {
        $context = Context::getContext();
        if (!Validate::isLoadedObject($context->shop)) {
            $context->shop = new Shop(FetchSyncSettings::defaultShopId());
        }
        if (!Validate::isLoadedObject($context->language)) {
            $context->language = new Language(FetchSyncSettings::defaultLangId());
        }
        if (!Validate::isLoadedObject($context->currency)) {
            $context->currency = Currency::getDefaultCurrency();
        }
        if (!Validate::isLoadedObject($context->country)) {
            $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        if (!$context->link) {
            $context->link = new Link();
        }

        return $context;
    }

    /**
     * Run $callback with the context switched to the default shop and the
     * given customer/cart, restoring the previous context afterwards.
     */
    public static function with(array $values, $callback)
    {
        $context = self::background();
        $saved = array();
        foreach ($values as $key => $value) {
            $saved[$key] = isset($context->{$key}) ? $context->{$key} : null;
            $context->{$key} = $value;
        }
        try {
            return call_user_func($callback, $context);
        } finally {
            foreach ($saved as $key => $value) {
                $context->{$key} = $value;
            }
        }
    }
}
