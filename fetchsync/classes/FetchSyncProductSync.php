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
 * "Sync all products" queues the whole catalogue the same way; in mirror
 * mode its end also switches off what the shop no longer sells
 * (FetchSyncMirror).
 *
 * With multistore, every connected shop syncs to its own Fetch store: a
 * change is queued once per connected shop that sells the product, and each
 * shop's queue is sent with that shop's prices, stock, language and markup.
 *
 * With "Exclude backorder items" a product or combination PrestaShop would
 * sell out of stock is sent with stock 0 and is never created on Fetch; one
 * Fetch already has keeps being updated.
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

    /**
     * Shops that sync products: connected, with "Sync to Fetch" on.
     *
     * @return int[]
     */
    public static function syncingShops()
    {
        $out = array();
        foreach (FetchSyncSettings::connectedShopIds() as $idShop) {
            if ('yes' === FetchSyncSettings::getFor($idShop, 'sync_products')) {
                $out[] = $idShop;
            }
        }

        return $out;
    }

    /**
     * Queue a product (a combination queues its product) for every syncing
     * shop that sells it, or has sent it to Fetch before (so a product taken
     * out of a shop is switched off in that shop's Fetch store).
     */
    public static function queue($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0 || $idProduct === (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0)) {
            return;
        }
        $queued = false;
        foreach (self::syncingShops() as $idShop) {
            $wanted = FetchSyncProductMapper::inShop($idProduct, $idShop) || FetchSyncSettings::withShop($idShop, function () use ($idProduct) {
                return FetchSyncProductMapper::wasSynced($idProduct);
            });
            if ($wanted) {
                self::queueIn($idShop, $idProduct);
                $queued = true;
            }
        }
        if ($queued) {
            self::scheduleFlush();
        }
    }

    private static function queueIn($idShop, $idProduct)
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::QUEUE . '` (`id_shop`, `id_product`, `version`, `attempts`, `next_try`, `date_add`)
            VALUES (' . (int) $idShop . ', ' . (int) $idProduct . ', 1, 0, 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\')
            ON DUPLICATE KEY UPDATE `version` = `version` + 1, `attempts` = 0, `next_try` = 0'
        );
    }

    /** A product is being deleted: remember its rows now, in every shop that sent it; it is gone by flush time. */
    public static function queueDeletion(Product $product)
    {
        $idProduct = (int) $product->id;
        $queued = false;
        foreach (self::syncingShops() as $idShop) {
            $queued = FetchSyncContext::forShop($idShop, function ($idShop) use ($idProduct) {
                if (!FetchSyncProductMapper::wasSynced($idProduct)) {
                    return false;
                }
                $product = FetchSyncProductMapper::loadProduct($idProduct);
                if (!FetchSyncProductMapper::supports($product)) {
                    return false;
                }
                foreach (FetchSyncProductMapper::deactivations($product) as $item) {
                    FetchSyncProductSync::addDeactivation($idShop, $item);
                }
                Db::getInstance()->delete(FetchSyncProductSync::QUEUE, '`id_shop` = ' . (int) $idShop . ' AND `id_product` = ' . $idProduct);

                return true;
            }) || $queued;
        }
        if ($queued) {
            self::scheduleFlush();
        }
    }

    /** A combination is being deleted: switch its own Fetch product off, in every shop that sent it. */
    public static function queueCombinationDeletion(Combination $combination)
    {
        $idProduct = (int) $combination->id_product;
        $ipa = (int) $combination->id;
        if (!$idProduct || !$ipa) {
            return;
        }
        $queued = false;
        foreach (self::syncingShops() as $idShop) {
            $queued = FetchSyncContext::forShop($idShop, function ($idShop) use ($idProduct, $ipa, $combination) {
                if (!FetchSyncProductMapper::wasSynced($idProduct, $ipa)) {
                    return false;
                }
                $product = FetchSyncProductMapper::loadProduct($idProduct);
                $found = false;
                foreach (FetchSyncProductMapper::toFetchItems($product, true) as $pair) {
                    if ((int) $pair[1][1] === $ipa) {
                        FetchSyncProductSync::addDeactivation($idShop, $pair[0]);
                        $found = true;
                    }
                }
                if (!$found) {
                    $ref = FetchSyncProductMapper::ref($idProduct, $ipa, $combination->reference);
                    FetchSyncProductSync::addDeactivation($idShop, FetchSyncProductMapper::deactivation($ref, is_string($product->name) ? $product->name : ''));
                }

                return true;
            }) || $queued;
        }
        if ($queued) {
            self::scheduleFlush();
        }
    }

    /** @internal */
    public static function addDeactivation($idShop, array $item)
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::DEACTIVATIONS . '` (`id_shop`, `ref`, `payload`, `attempts`, `next_try`)
            VALUES (' . (int) $idShop . ', \'' . pSQL($item['ref']) . '\', \'' . pSQL(json_encode($item), true) . '\', 0, 0)
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
        if (!FetchSyncSettings::connectedShopIds()) {
            return;
        }
        FetchSyncCron::finishRequest();
        try {
            FetchSyncContext::background();
            self::flushAll(20);
        } catch (Exception $e) {
            FetchSyncLogger::error('Product sync stopped: ' . $e->getMessage());
        }
    }

    /**
     * Send what is due in every connected shop, each in its own context. A
     * shop that fails (bad key, Fetch down for it) does not stop the others.
     *
     * @return int products sent
     */
    public static function flushAll($budget = 20)
    {
        $shops = FetchSyncSettings::connectedShopIds();
        $sent = 0;
        $deadline = microtime(true) + max(1, (int) $budget);
        foreach ($shops as $i => $idShop) {
            // Each shop gets an equal share of what is left.
            $share = max(1, (int) floor(($deadline - microtime(true)) / (count($shops) - $i)));
            try {
                $sent += FetchSyncContext::forShop($idShop, function () use ($share) {
                    return FetchSyncProductSync::flush($share);
                });
            } catch (Exception $e) {
                FetchSyncSettings::withShop($idShop, function () use ($e) {
                    FetchSyncLogger::error('Product sync stopped: ' . $e->getMessage());
                });
            }
        }

        return $sent;
    }

    /** Queue this shop's whole catalogue. */
    public static function startBulkSync($reason = 'Full product sync started')
    {
        $idShop = FetchSyncSettings::shopId();
        $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` = ' . $idShop
        );
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::QUEUE . '` (`id_shop`, `id_product`, `version`, `attempts`, `next_try`, `date_add`)
            SELECT ' . $idShop . ', ps.`id_product`, 1, 0, 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\'
            FROM `' . _DB_PREFIX_ . 'product_shop` ps
            WHERE ps.`id_shop` = ' . $idShop . '
            AND ps.`id_product` <> ' . $placeholder . '
            ON DUPLICATE KEY UPDATE `version` = `version` + 1, `attempts` = 0, `next_try` = 0'
        );
        FetchSyncSettings::setInternal('BULK_RUNNING', '1');
        FetchSyncSettings::setInternal('BULK_FAILED', '');
        FetchSyncLogger::info(sprintf('%s: %d product(s) queued.', $reason, $count));
        self::scheduleFlush();

        return $count;
    }

    /** Product changes waiting in this shop's queue (null: in every shop's). */
    public static function pendingCount($idShop = false)
    {
        $idShop = false === $idShop ? FetchSyncSettings::shopId() : $idShop;
        $where = null === $idShop ? '' : ' WHERE `id_shop` = ' . (int) $idShop;

        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::QUEUE . '`' . $where)
            + (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::DEACTIVATIONS . '`' . $where);
    }

    /**
     * Send what is due in this shop, for at most $budget seconds. One flush
     * per shop at a time. Run it inside FetchSyncContext::forShop().
     *
     * @return int products sent
     */
    public static function flush($budget = 20)
    {
        if (!FetchSyncSettings::isConnected() || FetchSyncApi::backoffRemaining() > 0) {
            return 0;
        }
        $idShop = FetchSyncSettings::shopId();
        $lock = self::lockName();
        if (!FetchSyncCron::lock($lock)) {
            return 0;
        }
        $sent = 0;
        try {
            $deadline = microtime(true) + max(1, (int) $budget);
            $db = Db::getInstance();
            $now = time();

            $rows = $db->executeS(
                'SELECT `ref`, `payload`, `attempts` FROM `' . _DB_PREFIX_ . self::DEACTIVATIONS . '`
                WHERE `id_shop` = ' . $idShop . ' AND `next_try` <= ' . $now . ' LIMIT ' . self::MAX_ITEMS
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
                $inShop = '`id_shop` = ' . $idShop . ' AND `ref` IN (' . implode(',', $refs) . ')';
                if (!$items || self::send($items, array())) {
                    $db->delete(self::DEACTIVATIONS, $inShop);
                } else {
                    self::delay(self::DEACTIVATIONS, $inShop);

                    return 0;
                }
            }

            while (microtime(true) < $deadline) {
                $rows = $db->executeS(
                    'SELECT `id_product`, `version` FROM `' . _DB_PREFIX_ . self::QUEUE . '`
                    WHERE `id_shop` = ' . $idShop . ' AND `next_try` <= ' . time() . ' ORDER BY `date_add`, `id_product` LIMIT ' . self::CHUNK
                );
                if (!$rows) {
                    break;
                }
                $ids = array_map('intval', array_column($rows, 'id_product'));
                if (!self::syncIds($ids)) {
                    self::delay(self::QUEUE, '`id_shop` = ' . $idShop . ' AND `id_product` IN (' . implode(',', $ids) . ')');
                    break;
                }
                $sent += count($ids);
                foreach ($rows as $row) {
                    // A change that arrived while sending bumped the version: keep it queued.
                    $db->delete(self::QUEUE, '`id_shop` = ' . $idShop . ' AND `id_product` = ' . (int) $row['id_product'] . ' AND `version` = ' . (int) $row['version']);
                }
            }

            if (FetchSyncSettings::internal('BULK_RUNNING') && 0 === self::pendingCount()) {
                FetchSyncSettings::setInternal('BULK_RUNNING', '');
                FetchSyncLogger::info('Full product sync finished.');
                FetchSyncMirror::afterFullSync();
            }
        } finally {
            FetchSyncCron::unlock($lock);
        }

        return $sent;
    }

    /** The lock that keeps one flush (or mirror run) per shop at a time. */
    public static function lockName()
    {
        return 'flush-' . FetchSyncSettings::shopId();
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
        $idShop = FetchSyncSettings::shopId();
        foreach ($ids as $id) {
            $pairs = array();
            if (FetchSyncProductMapper::inShop($id, $idShop)) {
                $product = FetchSyncProductMapper::loadProduct($id);
                if (FetchSyncProductMapper::supports($product)) {
                    $pairs = FetchSyncProductMapper::toFetchItems($product);
                }
            } elseif (FetchSyncProductMapper::wasSynced($id)) {
                // Taken out of this shop: switch it off, priced as the shop that still has it.
                $pairs = self::itemsFromOwnShop($id);
            }
            foreach ($pairs as $pair) {
                list($item, $owner) = $pair;
                // A backorder item is not created on Fetch; one already there keeps syncing (stock 0) so it can come back.
                if (!empty($pair[2]) && !FetchSyncProductMapper::wasSynced($owner[0], $owner[1])) {
                    continue;
                }
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

    /** Switched-off items for a product no longer in this shop, built in the product's default shop. */
    private static function itemsFromOwnShop($idProduct)
    {
        $source = (int) Db::getInstance()->getValue(
            'SELECT `id_shop_default` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . (int) $idProduct
        );
        if (!$source || !FetchSyncProductMapper::inShop($idProduct, $source)) {
            return array();
        }

        return FetchSyncContext::psShop($source, function () use ($idProduct, $source) {
            $product = FetchSyncProductMapper::loadProduct($idProduct, $source);

            return FetchSyncProductMapper::supports($product) ? FetchSyncProductMapper::toFetchItems($product, true) : array();
        });
    }

    /**
     * A fresh key for every request. Sandbox replays a key it has seen, so a
     * key derived from the payload could replay a stale answer for a product
     * sent again unchanged (and leave a returning product switched off); live
     * ignores the key.
     */
    public static function idempotencyKey($prefix = 'ps-')
    {
        return $prefix . bin2hex(random_bytes(16));
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
        $result = $api->upsertProducts($storeId, $items, self::idempotencyKey());

        if (!FetchSyncApi::ok($result)) {
            $message = FetchSyncApi::errorMessage($result);
            FetchSyncLogger::error(sprintf('Product sync failed for %d product(s): %s', count($items), $message));
            if (FetchSyncSettings::internal('BULK_RUNNING')) {
                // Mirror mode only acts on a full sync whose every request went through.
                FetchSyncSettings::setInternal('BULK_FAILED', '1');
            }
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

    /** Store the result in this shop's rows; a successful sync also freezes the ref. */
    private static function record(array $owner, $ref, $status, $error, $fetchProductId)
    {
        list($idProduct, $ipa) = $owner;
        $idShop = FetchSyncSettings::shopId();
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
            (`id_shop`, `id_product`, `id_product_attribute`, `ref`, `fetch_product_id`, `status`, `error`, `synced_at`)
            VALUES (' . $idShop . ', ' . (int) $idProduct . ', ' . (int) $ipa . ', \'' . pSQL((string) $ref) . '\', \'' . pSQL((string) $fetchProductId) . '\',
            \'' . pSQL($status) . '\', \'' . pSQL(Tools::substr((string) $error, 0, 1000)) . '\', ' . (int) time() . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', $set)
        );
        if (null !== $ref) {
            FetchSyncProductMapper::forgetRefCache();
        }
    }

    /** Products whose specific price (sale) started or ended since the last check, in every shop. */
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
