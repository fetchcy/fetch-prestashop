<?php
/**
 * "Mirror this shop 1:1" catalogue mode.
 *
 * At the end of a full sync, every listing in the Fetch store that is not a
 * product this shop keeps on sale (no ref, a ref the shop no longer has, a
 * product type the module does not sync, an inactive or deleted product) is
 * switched off on Fetch. Nothing is ever deleted: a product that comes back
 * is switched on again by the normal sync.
 *
 * Only which products are on sale, their price, stock and active state follow
 * the shop. Names, photos and descriptions live in Fetch's shared catalogue,
 * which other stores use too, and are not overwritten.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncMirror
{
    /** GET /listings page size (the API's maximum). */
    const PAGE = 500;
    /** Product names shown by the preview. */
    const SAMPLES = 20;

    /** End of a full sync: mirror when the mode is on and no request of the push failed. */
    public static function afterFullSync($api = null, $keep = null)
    {
        $failed = (bool) FetchSyncSettings::internal('BULK_FAILED');
        FetchSyncSettings::setInternal('BULK_FAILED', '');
        if (!FetchSyncSettings::mirrorMode()) {
            return null;
        }
        if ($failed) {
            FetchSyncLogger::error('Mirror skipped: Fetch could not be reached for part of the full sync, so nothing was switched off. It runs again at the end of the next full sync.');

            return null;
        }
        try {
            return self::run(false, $api, $keep);
        } catch (Exception $e) {
            FetchSyncLogger::error('Mirror stopped: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Scan the store, then switch off what the shop does not sell. A run that
     * would switch off more than half of the active listings is held for the
     * merchant to confirm, unless $force ("Apply anyway").
     *
     * @param FetchSyncApi|null $api
     * @param array<string,bool>|null $keep ref → true; default: the shop's catalogue
     *
     * @return array<string,mixed> summary; status is done, held or failed
     */
    public static function run($force = false, $api = null, $keep = null)
    {
        // Reading a large store and catalogue can outlast a web request's default limit.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $api = $api ? $api : new FetchSyncApi();
        $storeId = (string) FetchSyncSettings::get('store_id');
        $listings = self::scan($api, $storeId);
        if (is_string($listings)) {
            FetchSyncLogger::error('Mirror skipped: could not read your Fetch listings (' . $listings . '). Nothing was switched off.');

            return array('status' => 'failed', 'error' => $listings);
        }
        $plan = self::plan($listings, null !== $keep ? $keep : self::keepRefs());
        $summary = self::summary($plan);

        if (!$force && self::tooMany($summary['off'], $summary['active'])) {
            FetchSyncSettings::setInternal('MIRROR_PENDING', json_encode(array('off' => $summary['off'], 'active' => $summary['active'], 'at' => time())));
            FetchSyncLogger::error(sprintf(
                'Mirror held: it would switch off %d of %d active Fetch products, more than half. Nothing was switched off. Check the preview, then press "Apply anyway" on the Fetch module page.',
                $summary['off'],
                $summary['active']
            ));

            return array('status' => 'held') + $summary;
        }

        FetchSyncSettings::setInternal('MIRROR_PENDING', '');
        list($switched, $errors) = self::apply($api, $storeId, self::rows($plan['off']));
        $message = sprintf(
            'Mirror: scanned %d Fetch listing(s), kept %d, switched off %d, %d error(s).',
            $summary['scanned'],
            $summary['kept'],
            $switched,
            $errors
        );
        if ($errors) {
            FetchSyncLogger::error($message);
        } else {
            FetchSyncLogger::info($message);
        }

        return array('status' => 'done', 'switched_off' => $switched, 'errors' => $errors) + $summary;
    }

    /**
     * What a run would do now, without changing anything.
     *
     * @return array<string,mixed> status preview (with counts and samples) or failed
     */
    public static function preview($api = null, $keep = null)
    {
        $api = $api ? $api : new FetchSyncApi();
        $listings = self::scan($api, (string) FetchSyncSettings::get('store_id'));
        if (is_string($listings)) {
            return array('status' => 'failed', 'error' => $listings);
        }
        $summary = self::summary(self::plan($listings, null !== $keep ? $keep : self::keepRefs()));

        return array('status' => 'preview', 'held' => self::tooMany($summary['off'], $summary['active'])) + $summary;
    }

    /**
     * Every listing of the store, page by page until nextCursor is null.
     *
     * @return array<int,array<string,mixed>>|string the listings, or why a page failed
     */
    public static function scan($api, $storeId)
    {
        $all = array();
        $cursor = null;
        $seen = array();
        do {
            $query = array('limit' => self::PAGE);
            if (null !== $cursor) {
                $query['cursor'] = $cursor;
            }
            $result = $api->listListings($storeId, $query);
            if (!FetchSyncApi::ok($result)) {
                return FetchSyncApi::errorMessage($result);
            }
            $body = $result['body'];
            if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
                return 'unexpected answer from Fetch';
            }
            foreach ($body['data'] as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }
            $cursor = isset($body['nextCursor']) && '' !== (string) $body['nextCursor'] ? (string) $body['nextCursor'] : null;
            if (null !== $cursor) {
                if (isset($seen[$cursor])) {
                    return 'the listing pages repeat';
                }
                $seen[$cursor] = true;
            }
        } while (null !== $cursor);

        return $all;
    }

    /**
     * Split the scan into listings to keep and active listings to switch off.
     * A listing updated during the scan can show up on two pages; the last copy wins.
     *
     * @param array<string,bool> $keep ref → true
     *
     * @return array{scanned:int,active:int,kept:int,off:array<int,array<string,mixed>>}
     */
    public static function plan(array $listings, array $keep)
    {
        $byId = array();
        foreach ($listings as $listing) {
            $id = isset($listing['listingId']) ? (string) $listing['listingId'] : '';
            if ('' !== $id) {
                $byId[$id] = $listing;
            }
        }
        $plan = array('scanned' => count($byId), 'active' => 0, 'kept' => 0, 'off' => array());
        foreach ($byId as $listing) {
            $active = self::isActive($listing);
            if ($active) {
                ++$plan['active'];
            }
            $ref = self::ref($listing);
            if (null !== $ref && isset($keep[$ref])) {
                ++$plan['kept'];
            } elseif ($active) {
                $plan['off'][] = $listing;
            }
        }

        return $plan;
    }

    /**
     * listings/batch rows, always by the scanned listingId (exact and scoped to
     * the store, where a ref can fail to resolve), with the current price (live
     * Fetch requires one on every row).
     */
    public static function rows(array $off)
    {
        $rows = array();
        foreach ($off as $listing) {
            $row = array('listingId' => (string) $listing['listingId']);
            if (isset($listing['price'])) {
                $row['price'] = (string) $listing['price'];
            }
            $row['isActive'] = false;
            $rows[] = $row;
        }

        return $rows;
    }

    /** More than half of the active listings would go. */
    public static function tooMany($off, $active)
    {
        return $off > 0 && $off * 2 > $active;
    }

    /**
     * Send the rows in requests of at most 200 rows and ~450 KB. Stops at the
     * first failed request.
     *
     * @return array{0:int,1:int} switched off, errors
     */
    public static function apply($api, $storeId, array $rows)
    {
        $switched = 0;
        foreach (FetchSyncProductSync::batches($rows) as $batch) {
            $result = $api->batchListings($storeId, $batch, FetchSyncProductSync::idempotencyKey('ps-mirror-'));
            if (!FetchSyncApi::ok($result)) {
                FetchSyncLogger::error(sprintf('Mirror: switching off %d Fetch listing(s) failed: %s', count($batch), FetchSyncApi::errorMessage($result)));
                break;
            }
            $body = is_array($result['body']) ? $result['body'] : array();
            $rejected = 0;
            foreach (isset($body['results']) && is_array($body['results']) ? $body['results'] : array() as $row) {
                if (!is_array($row) || !isset($row['status']) || 'error' !== $row['status']) {
                    continue;
                }
                ++$rejected;
                if ($rejected <= 5) {
                    $id = !empty($row['ref']) ? $row['ref'] : (!empty($row['listingId']) ? $row['listingId'] : '?');
                    $error = trim((isset($row['code']) ? $row['code'] . ': ' : '') . (isset($row['message']) ? $row['message'] : 'rejected'));
                    FetchSyncLogger::error(sprintf('Mirror: Fetch refused to switch off %s: %s', $id, $error));
                }
            }
            $switched += isset($body['accepted']) ? (int) $body['accepted'] : count($batch) - $rejected;
        }

        return array($switched, count($rows) - $switched);
    }

    /**
     * ref → true for every product (and combination) this shop keeps on sale on Fetch.
     *
     * @return array<string,bool>
     */
    public static function keepRefs()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`
            WHERE `id_shop` = ' . FetchSyncSettings::defaultShopId() . '
            AND `id_product` <> ' . (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0) . '
            ORDER BY `id_product`'
        );
        $keep = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            foreach (FetchSyncProductMapper::activeRefs(FetchSyncProductMapper::loadProduct((int) $row['id_product'])) as $ref) {
                $keep[$ref] = true;
            }
        }

        return $keep;
    }

    /** The held run waiting for "Apply anyway": {off, active, at}, or null. */
    public static function pending()
    {
        $pending = json_decode((string) FetchSyncSettings::internal('MIRROR_PENDING', ''), true);

        return is_array($pending) ? $pending : null;
    }

    private static function summary(array $plan)
    {
        $samples = array();
        foreach (array_slice($plan['off'], 0, self::SAMPLES) as $listing) {
            $name = isset($listing['name']) ? trim((string) $listing['name']) : '';
            $samples[] = '' !== $name ? $name : (string) $listing['listingId'];
        }

        return array(
            'scanned' => $plan['scanned'],
            'active' => $plan['active'],
            'kept' => $plan['kept'],
            'off' => count($plan['off']),
            'samples' => $samples,
        );
    }

    private static function ref(array $listing)
    {
        return isset($listing['ref']) && '' !== trim((string) $listing['ref']) ? (string) $listing['ref'] : null;
    }

    private static function isActive(array $listing)
    {
        return !empty($listing['isActive']);
    }
}
