<?php
/**
 * 1.3.0 → 1.3.1: "Exclude backorder items".
 *
 * Turns the new setting on (it is on by default) for the installation and for
 * every shop that has no value yet. Other settings are not touched. Without
 * a stored value the setting reads as on anyway; this makes it explicit.
 *
 * The configuration key is spelled out so the upgrade does not depend on
 * which version of the module classes is loaded.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_1_3_1($module)
{
    $key = 'FETCHSYNC_EXCLUDE_BACKORDERS';
    if (false === Configuration::getGlobalValue($key)) {
        Configuration::updateGlobalValue($key, 'yes');
    }
    if (class_exists('Shop') && Shop::isFeatureActive()) {
        Configuration::get('PS_SHOP_DEFAULT'); // loads the cache hasKey() reads
        foreach (Shop::getShops(false, null, true) as $idShop) {
            $idShop = (int) $idShop;
            if (!Configuration::hasKey($key, null, null, $idShop)) {
                Configuration::updateValue($key, 'yes', false, (int) Shop::getGroupFromShop($idShop), $idShop);
            }
        }
    }

    return true;
}
