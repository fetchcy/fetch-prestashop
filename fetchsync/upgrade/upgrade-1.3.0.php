<?php
/**
 * 1.2.0 → 1.3.0: multistore.
 *
 * - The sync, queue, order and log tables gain `id_shop`; their rows are the
 *   default shop's, the only shop 1.2.0 synced.
 * - The settings stay where 1.2.0 put them. Without multistore they keep
 *   working as they are; with multistore they become the default shop's own
 *   settings. Other shops start unconfigured.
 * - The carrier and the placeholder product are associated with every shop,
 *   and new shops get them too (actionShopDataDuplication).
 *
 * Nothing needs reconfiguring: the default shop stays connected to its Fetch
 * store, with the same webhook secret and cron URL.
 *
 * The SQL is inline: PrestaShop can run this file while the 1.2.0 classes
 * are still loaded in the same request. The rest also happens at run time
 * (configuration page, cron, first order of a shop) if it cannot happen here.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_1_3_0($module)
{
    $db = Db::getInstance();
    $p = _DB_PREFIX_;
    $shop = (int) Configuration::get('PS_SHOP_DEFAULT');
    $tables = array(
        'fetchsync_queue' => array('FIRST', 'DROP PRIMARY KEY, ADD PRIMARY KEY (`id_shop`, `id_product`)'),
        'fetchsync_deactivation' => array('FIRST', 'DROP PRIMARY KEY, ADD PRIMARY KEY (`id_shop`, `ref`)'),
        'fetchsync_product' => array('FIRST', 'DROP PRIMARY KEY, ADD PRIMARY KEY (`id_shop`, `id_product`, `id_product_attribute`), DROP INDEX `ref`, ADD INDEX `ref` (`id_shop`, `ref`)'),
        'fetchsync_order' => array('AFTER `fetch_order_id`', 'ADD INDEX `id_shop` (`id_shop`)'),
        'fetchsync_log' => array('AFTER `id_fetchsync_log`', 'ADD INDEX `id_shop` (`id_shop`)'),
    );
    foreach ($tables as $table => $sql) {
        if ($db->executeS('SHOW COLUMNS FROM `' . $p . $table . '` LIKE \'id_shop\'')) {
            continue;
        }
        $ok = $db->execute('ALTER TABLE `' . $p . $table . '` ADD `id_shop` INT UNSIGNED NOT NULL DEFAULT 0 ' . $sql[0])
            && $db->execute('UPDATE `' . $p . $table . '` SET `id_shop` = ' . $shop)
            && $db->execute('ALTER TABLE `' . $p . $table . '` ' . $sql[1]);
        if (!$ok) {
            return false;
        }
    }
    if (!$module->registerHook('actionShopDataDuplication')) {
        return false;
    }
    if (method_exists('FetchSyncSettings', 'adoptLegacy')) {
        FetchSyncSettings::adoptLegacy();
    }
    if (method_exists('FetchSyncInstaller', 'ensureAllShops')) {
        FetchSyncInstaller::ensureAllShops();
    }

    return true;
}
