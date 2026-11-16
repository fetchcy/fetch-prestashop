<?php
/**
 * Background work: send queued product changes, catch sales that started or
 * ended, and poll Fetch for orders (the safety net behind the webhook).
 *
 * Runs from the cron URL (recommended, every minute or two) and, as a
 * fallback, at most every 2 minutes at the end of an ordinary shop or
 * back-office request ("pseudo-cron").
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncCron
{
    const INTERVAL = 120;

    private static $tickRegistered = false;

    /** @return array<string,mixed> summary */
    public static function run($budget = 25)
    {
        FetchSyncContext::background();
        $summary = array('products_sent' => 0, 'sales_queued' => 0, 'orders_polled' => false);
        if (!FetchSyncSettings::isConnected()) {
            $summary['skipped'] = 'not connected';

            return $summary;
        }
        FetchSyncSettings::setInternal('LAST_TICK', time());
        if (FetchSyncSettings::enabled('sync_products')) {
            $summary['sales_queued'] = FetchSyncProductSync::queueSaleBoundaries();
        }
        $summary['products_sent'] = FetchSyncProductSync::flush(max(5, (int) $budget - 10));
        $summary['orders_polled'] = FetchSyncOrderSync::poll();
        $summary['pending'] = FetchSyncProductSync::pendingCount();

        return $summary;
    }

    /** Pseudo-cron: schedule a run at the end of this request when one is due. */
    public static function maybeTick()
    {
        if (self::$tickRegistered || !FetchSyncSettings::isConnected()) {
            return;
        }
        $last = (int) FetchSyncSettings::internal('LAST_TICK', 0);
        if (time() - $last < self::INTERVAL) {
            return;
        }
        FetchSyncSettings::setInternal('LAST_TICK', time());
        self::$tickRegistered = true;
        register_shutdown_function(array(__CLASS__, 'shutdownTick'));
    }

    public static function shutdownTick()
    {
        self::finishRequest();
        try {
            self::run(20);
        } catch (Exception $e) {
            FetchSyncLogger::error('Background run stopped: ' . $e->getMessage());
        }
    }

    /** Send the response to the browser before slow background work, where the server allows it. */
    public static function finishRequest()
    {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /** Named MySQL lock so two requests never flush or poll at the same time. */
    public static function lock($name)
    {
        return 1 === (int) Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL(self::lockName($name)) . '\', 0)');
    }

    public static function unlock($name)
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL(self::lockName($name)) . '\')');
    }

    private static function lockName($name)
    {
        return Tools::substr('fetchsync_' . md5(_DB_NAME_ . _DB_PREFIX_) . '_' . $name, 0, 64);
    }

    public static function url()
    {
        return Context::getContext()->link->getModuleLink(
            'fetchsync',
            'cron',
            array('token' => (string) FetchSyncSettings::get('cron_token')),
            null,
            FetchSyncSettings::defaultLangId(),
            FetchSyncSettings::defaultShopId()
        );
    }
}
