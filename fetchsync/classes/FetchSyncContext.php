<?php
/**
 * Makes sure the global Context has a shop, language, currency, country and
 * link when the module runs outside a normal page (webhook, cron, shutdown),
 * and switches it to one shop for that shop's work.
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
     * Run $callback as $idShop: the module's settings of that shop, and
     * PrestaShop's context (shop, its language, currency, country and links),
     * so prices, taxes, stock and URLs are that shop's. Restored afterwards.
     */
    public static function forShop($idShop, $callback)
    {
        $idShop = (int) $idShop;

        return FetchSyncSettings::withShop($idShop, function () use ($idShop, $callback) {
            return FetchSyncContext::psShop($idShop, $callback);
        });
    }

    /** Only PrestaShop's context as $idShop (the module's settings scope is left alone). */
    public static function psShop($idShop, $callback)
    {
        $idShop = (int) $idShop;
        $context = self::background();
        if ((int) $context->shop->id === $idShop && Shop::getContext() === Shop::CONTEXT_SHOP && (int) Shop::getContextShopID() === $idShop) {
            return call_user_func($callback, $idShop);
        }
        $saved = array(
            'shop' => $context->shop,
            'language' => $context->language,
            'currency' => $context->currency,
            'country' => $context->country,
            'link' => $context->link,
        );
        $savedType = Shop::getContext();
        $savedShop = Shop::getContextShopID();
        $savedGroup = Shop::getContextShopGroupID();
        try {
            $shop = new Shop($idShop);
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
            $context->shop = $shop;
            $lang = FetchSyncSettings::withShop($idShop, function () {
                return array(FetchSyncSettings::defaultLangId(), FetchSyncSettings::currencyId(), FetchSyncSettings::countryId());
            });
            $context->language = new Language($lang[0]);
            $currency = new Currency($lang[1], null, $idShop);
            $context->currency = Validate::isLoadedObject($currency) ? $currency : Currency::getDefaultCurrency();
            $context->country = new Country($lang[2]);
            $context->link = new Link();

            return call_user_func($callback, $idShop);
        } finally {
            foreach ($saved as $key => $value) {
                $context->{$key} = $value;
            }
            if (Shop::CONTEXT_SHOP === $savedType) {
                Shop::setContext(Shop::CONTEXT_SHOP, (int) $savedShop);
            } elseif (Shop::CONTEXT_GROUP === $savedType) {
                Shop::setContext(Shop::CONTEXT_GROUP, (int) $savedGroup);
            } elseif (Shop::CONTEXT_ALL === $savedType) {
                Shop::setContext(Shop::CONTEXT_ALL);
            }
        }
    }

    /**
     * Run $callback with the context switched to the given customer/cart
     * (and anything else), restoring the previous context afterwards.
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
