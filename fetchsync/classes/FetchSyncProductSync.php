<?php
/**
 * Pushes PrestaShop product changes to Fetch.
 *
 * Every change (save, combination edit, specific price, stock change, delete)
 * puts the product in the fetchsync_queue table. The queue is flushed at the
 * end of the same request (after the response has been sent where the server
 * allows it), in chunks of 50 products, so a bulk edit or a busy checkout
 * becomes a handful of API calls rather than one per product. Rows that fail
 * stay queued and are retried with back-off by the cron / pseudo-cron.
 * "Sync all products" queues the whole catalogue the same way.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncProductSync
{
    const QUEUE = 'fetchsync_queue';
    const DEACTIVATIONS = 'fetchsync_deactivation';
    const CHUNK = 50;
    /** Live PUT /products takes at most 200 products per call. */
    const MAX_ITEMS = 200;
    /** Stay under the API's 500 KB request limit. */
    const MAX_BYTES = 450000;
    const MAX_RETRY_DELAY = 3600;

    private static $flushRegistered = false;

    /** Queue a product (a combination queues its product). */
    public static function queue($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0 || $idProduct === (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0)) {
            return;
        }
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::QUEUE . '` (`id_product`, `version`, `attempts`, `next_try`, `date_add`)
            VALUES (' . $idProduct . ', 1, 0, 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\')
            ON DUPLICATE KEY UPDATE `version` = `version` + 1, `attempts` = 0, `next_try` = 0'
        );
        self::scheduleFlush();
    }

    /** A product is being deleted: remember its rows now, it is gone by flush time. */
    public static function queueDeletion(Product $product)
    {
        if (!FetchSyncProductMapper::supports($product) || !FetchSyncProductMapper::wasSynced($product->id)) {
            return;
        }
        $product = FetchSyncProductMapper::loadProduct($product->id);
        foreach (FetchSyncProductMapper::deactivations($product) as $item) {
            self::addDeactivation($item);
        }
        Db::getInstance()->delete(self::QUEUE, '`id_product` = ' . (int) $product->id);
        self::scheduleFlush();
    }

    /** A combination is being deleted: switch its own Fetch product off. */
    public static function queueCombinationDeletion(Combination $combination)
    {
        $idProduct = (int) $combination->id_product;
        $ipa = (int) $combination->id;
        if (!$idProduct || !$ipa || !FetchSyncProductMapper::wasSynced($idProduct, $ipa)) {
            return;
        }
        $product = FetchSyncProductMapper::loadProduct($idProduct);
        $found = false;
        foreach (FetchSyncProductMapper::toFetchItems($product, true) as $pair) {
            if ((int) $pair[1][1] === $ipa) {
                self::addDeactivation($pair[0]);
                $found = true;
            }
        }
        if (!$found) {
            $ref = FetchSyncProductMapper::ref($idProduct, $ipa, $combination->reference);
            self::addDeactivation(FetchSyncProductMapper::deactivation($ref, is_string($product->name) ? $product->name : ''));
        }
        self::scheduleFlush();
    }

    private static function addDeactivation(array $item)
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::DEACTIVATIONS . '` (`ref`, `payload`, `attempts`, `next_try`)
            VALUES (\'' . pSQL($item['ref']) . '\', \'' . pSQL(json_encode($item), true) . '\', 0, 0)
            ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `attempts` = 0, `next_try` = 0'
        );
    }

    public static function scheduleFlush()
    {
        if (self::$flushRegistered) {
            return;
        }
        self::$flushRegistered = true;
        register_shutdown_function(array(__CLASS__, 'shutdownFlush'));
    }

    public static function shutdownFlush()
    {
        self::$flushRegistered = false;
        if (!FetchSyncSettings::isConnected()) {
            return;
        }
        FetchSyncCron::finishRequest();
        try {
            FetchSyncContext::background();
            self::flush(20);
        } catch (Exception $e) {
            FetchSyncLogger::error('Product sync stopped: ' . $e->getMessage());
        }
    }

    /** Queue the whole catalogue of the default shop. */
    public static function startBulkSync($reason = 'Full product sync started')
    {
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` = ' . FetchSyncSettings::defaultShopId()
        );
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::QUEUE . '` (`id_product`, `version`, `attempts`, `next_try`, `date_add`)
            SELECT ps.`id_product`, 1, 0, 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\'
            FROM `' . _DB_PREFIX_ . 'product_shop` ps
            WHERE ps.`id_shop` = ' . FetchSyncSettings::defaultShopId() . '
            AND ps.`id_product` <> ' . (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0) . '
            ON DUPLICATE KEY UPDATE `version` = `version` + 1, `attempts` = 0, `next_try` = 0'
        );
        FetchSyncSettings::setInternal('BULK_RUNNING', '1');
        FetchSyncLogger::info(sprintf('%s: %d product(s) queued.', $reason, $count));
        self::scheduleFlush();

        return $count;
    }

    public static function pendingCount()
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::QUEUE . '`')
            + (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::DEACTIVATIONS . '`');
    }

    /**
     * Send what is due, for at most $budget seconds. One flush at a time.
     *
     * @return int products sent
     */
    public static function flush($budget = 20)
    {
        if (!FetchSyncSettings::isConnected() || FetchSyncApi::backoffRemaining() > 0) {
            return 0;
        }
        if (!FetchSyncCron::lock('flush')) {
            return 0;
        }
        $sent = 0;
        try {
            $deadline = microtime(true) + max(1, (int) $budget);
            $db = Db::getInstance();
            $now = time();

            $rows = $db->executeS(
                'SELECT `ref`, `payload`, `attempts` FROM `' . _DB_PREFIX_ . self::DEACTIVATIONS . '`
                WHERE `next_try` <= ' . $now . ' LIMIT ' . self::MAX_ITEMS
            );
            if ($rows) {
                $items = array();
                foreach ($rows as $row) {
                    $item = json_decode($row['payload'], true);
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
                $refs = array_map(function ($row) {
                    return '\'' . pSQL($row['ref']) . '\'';
                }, $rows);
                if (!$items || self::send($items, array())) {
                    $db->delete(self::DEACTIVATIONS, '`ref` IN (' . implode(',', $refs) . ')');
                } else {
                    self::delay(self::DEACTIVATIONS, '`ref` IN (' . implode(',', $refs) . ')');

                    return 0;
                }
            }

            while (microtime(true) < $deadline) {
                $rows = $db->executeS(
                    'SELECT `id_product`, `version` FROM `' . _DB_PREFIX_ . self::QUEUE . '`
                    WHERE `next_try` <= ' . time() . ' ORDER BY `date_add`, `id_product` LIMIT ' . self::CHUNK
                );
                if (!$rows) {
                    break;
                }
                $ids = array_map('intval', array_column($rows, 'id_product'));
                if (!self::syncIds($ids)) {
                    self::delay(self::QUEUE, '`id_product` IN (' . implode(',', $ids) . ')');
                    break;
                }
                $sent += count($ids);
                foreach ($rows as $row) {
                    // A change that arrived while sending bumped the version: keep it queued.
                    $db->delete(self::QUEUE, '`id_product` = ' . (int) $row['id_product'] . ' AND `version` = ' . (int) $row['version']);
                }
            }

            if (FetchSyncSettings::internal('BULK_RUNNING') && 0 === self::pendingCount()) {
                FetchSyncSettings::setInternal('BULK_RUNNING', '');
                FetchSyncLogger::info('Full product sync finished.');
            }
        } finally {
            FetchSyncCron::unlock('flush');
        }

        return $sent;
    }

    /** Retry with back-off: 2, 4, 8 … minutes, at most hourly. */
    private static function delay($table, $where)
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . $table . '`
            SET `attempts` = `attempts` + 1,
                `next_try` = ' . time() . ' + LEAST(' . self::MAX_RETRY_DELAY . ', 60 * POW(2, LEAST(`attempts` + 1, 6)))
            WHERE ' . $where
        );
    }

    /**
     * @param int[] $ids
     *
     * @return bool false when Fetch could not be reached (rows stay queued)
     */
    public static function syncIds(array $ids)
    {
        $items = array();
        $owners = array();
        foreach ($ids as $id) {
            $product = FetchSyncProductMapper::loadProduct($id);
            if (!FetchSyncProductMapper::supports($product)) {
                continue;
            }
            foreach (FetchSyncProductMapper::toFetchItems($product) as $pair) {
                list($item, $owner) = $pair;
                $items[] = $item;
                $owners[$item['ref']] = $owner;
            }
        }

        $ok = true;
        foreach (self::batches($items) as $batch) {
            $ok = self::send($batch, $owners) && $ok;
            if (!$ok) {
                break;
            }
        }

        return $ok;
    }

    /** Split into requests of at most 200 products and ~450 KB. */
    public static function batches(array $items)
    {
        $out = array();
        $batch = array();
        $bytes = 0;
        foreach ($items as $item) {
            $size = strlen(json_encode($item)) + 1;
            if ($batch && (count($batch) >= self::MAX_ITEMS || $bytes + $size > self::MAX_BYTES)) {
                $out[] = $batch;
                $batch = array();
                $bytes = 0;
            }
            $batch[] = $item;
            $bytes += $size;
        }
        if ($batch) {
            $out[] = $batch;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,array{0:int,1:int}> $owners ref → [id_product, id_product_attribute]
     *
     * @return bool false when the request failed as a whole
     */
    private static function send(array $items, array $owners)
    {
        $storeId = (string) FetchSyncSettings::get('store_id');
        $api = new FetchSyncApi();
        $result = $api->upsertProducts($storeId, $items, 'ps-' . md5(json_encode($items)));

        if (!FetchSyncApi::ok($result)) {
            $message = FetchSyncApi::errorMessage($result);
            FetchSyncLogger::error(sprintf('Product sync failed for %d product(s): %s', count($items), $message));
            foreach ($items as $item) {
                if (isset($owners[$item['ref']])) {
                    self::record($owners[$item['ref']], null, 'error', $message, null);
                }
            }
            if (FetchSyncApi::transient($result) && 429 !== (int) $result['code']) {
                FetchSyncApi::backoff(60);
            }

            return false;
        }

        $body = is_array($result['body']) ? $result['body'] : array();
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : array();
        $rejected = 0;
        $names = array();
        foreach ($items as $item) {
            $names[$item['ref']] = $item['name'];
        }
        foreach ($results as $row) {
            $ref = isset($row['ref']) ? (string) $row['ref'] : '';
            if (!isset($owners[$ref])) {
                continue;
            }
            $status = isset($row['status']) ? (string) $row['status'] : '';
            if ('error' === $status) {
                ++$rejected;
                $error = trim((isset($row['code']) ? $row['code'] . ': ' : '') . (isset($row['message']) ? $row['message'] : 'rejected'));
                self::record($owners[$ref], null, 'error', $error, null);
                FetchSyncLogger::error(sprintf('Fetch rejected "%s" (%s): %s', isset($names[$ref]) ? $names[$ref] : $ref, $ref, $error));
                continue;
            }
            $warnings = !empty($row['warnings']) && is_array($row['warnings']) ? implode(' ', $row['warnings']) : '';
            self::record($owners[$ref], $ref, 'synced', $warnings, !empty($row['productId']) ? (string) $row['productId'] : null);
        }

        FetchSyncLogger::info(sprintf(
            'Synced %d product(s) to Fetch: %d created, %d updated, %d rejected.',
            count($items),
            isset($body['created']) ? (int) $body['created'] : 0,
            isset($body['updated']) ? (int) $body['updated'] : 0,
            isset($body['rejected']) ? (int) $body['rejected'] : $rejected
        ));

        return true;
    }

    /** Store the result; a successful sync also freezes the ref. */
    private static function record(array $owner, $ref, $status, $error, $fetchProductId)
    {
        list($idProduct, $ipa) = $owner;
        $set = array(
            '`status` = \'' . pSQL($status) . '\'',
            '`error` = \'' . pSQL(Tools::substr((string) $error, 0, 1000)) . '\'',
            '`synced_at` = ' . (int) time(),
        );
        if (null !== $ref) {
            $set[] = '`ref` = \'' . pSQL($ref) . '\'';
        }
        if (null !== $fetchProductId) {
            $set[] = '`fetch_product_id` = \'' . pSQL($fetchProductId) . '\'';
        }
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . FetchSyncProductMapper::TABLE . '`
            (`id_product`, `id_product_attribute`, `ref`, `fetch_product_id`, `status`, `error`, `synced_at`)
            VALUES (' . (int) $idProduct . ', ' . (int) $ipa . ', \'' . pSQL((string) $ref) . '\', \'' . pSQL((string) $fetchProductId) . '\',
            \'' . pSQL($status) . '\', \'' . pSQL(Tools::substr((string) $error, 0, 1000)) . '\', ' . (int) time() . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', $set)
        );
        if (null !== $ref) {
            FetchSyncProductMapper::forgetRefCache();
        }
    }

    /** Products whose specific price (sale) started or ended since the last check. */
    public static function queueSaleBoundaries()
    {
        $now = time();
        $last = (int) FetchSyncSettings::internal('SALES_CHECKED', 0);
        FetchSyncSettings::setInternal('SALES_CHECKED', $now);
        if (!$last || $now - $last > 7 * 86400) {
            return 0;
        }
        $from = pSQL(date('Y-m-d H:i:s', $last));
        $to = pSQL(date('Y-m-d H:i:s', $now));
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'specific_price`
            WHERE `id_product` > 0 AND `id_cart` = 0
            AND ((`from` > \'' . $from . '\' AND `from` <= \'' . $to . '\') OR (`to` > \'' . $from . '\' AND `to` <= \'' . $to . '\'))'
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            self::queue((int) $row['id_product']);
        }

        return is_array($rows) ? count($rows) : 0;
    }
}
