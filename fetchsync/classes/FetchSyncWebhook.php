<?php
/**
 * Receives Fetch order webhooks at
 * index.php?fc=module&module=fetchsync&controller=webhook.
 *
 * Fetch signs every delivery: `X-Fetch-Signature: t=<unix seconds>,v1=<hex>`
 * where v1 = HMAC-SHA256(secret, "<t>.<raw body>"). Deliveries older than five
 * minutes are refused so a captured request cannot be replayed.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncWebhook
{
    const TOLERANCE = 300;
    const TABLE = 'fetchsync_event';
    /** Fetch retries for about 22 hours; remember event ids for a week. */
    const KEEP_SECONDS = 604800;

    public static function url()
    {
        return Context::getContext()->link->getModuleLink('fetchsync', 'webhook', array(), null, FetchSyncSettings::defaultLangId(), FetchSyncSettings::defaultShopId());
    }

    /**
     * @return array{0:int,1:array<string,mixed>} HTTP status and JSON body
     */
    public static function handle($raw, $signatureHeader)
    {
        $secret = (string) FetchSyncSettings::get('webhook_secret');
        if ('' === $secret || !self::verify((string) $signatureHeader, (string) $raw, $secret, time())) {
            return array(401, array('error' => 'invalid signature'));
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['event']) || empty($payload['data']) || !is_array($payload['data'])) {
            return array(400, array('error' => 'invalid payload'));
        }

        $eventId = isset($payload['eventId']) ? (string) $payload['eventId'] : '';
        if ('' !== $eventId && self::seen($eventId)) {
            return array(200, array('ok' => true, 'duplicate' => true));
        }

        if (0 === strpos((string) $payload['event'], 'order.') && FetchSyncSettings::enabled('import_orders')) {
            FetchSyncOrderSync::import($payload['data']);
        }
        if ('' !== $eventId) {
            self::remember($eventId);
        }

        return array(200, array('ok' => true));
    }

    /** Constant-time check of a `t=…,v1=…` header against the raw body. */
    public static function verify($header, $rawBody, $secret, $now)
    {
        $timestamp = null;
        $signatures = array();
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (2 !== count($pair)) {
                continue;
            }
            if ('t' === $pair[0]) {
                $timestamp = (int) $pair[1];
            } elseif ('v1' === $pair[0]) {
                $signatures[] = $pair[1];
            }
        }
        if (!$timestamp || !$signatures || abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function seen($eventId)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `event_id` = \'' . pSQL($eventId) . '\''
        );
    }

    private static function remember($eventId)
    {
        $db = Db::getInstance();
        $db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`event_id`, `received_at`)
            VALUES (\'' . pSQL(Tools::substr($eventId, 0, 191)) . '\', ' . (int) time() . ')'
        );
        if (0 === mt_rand(0, 49)) {
            $db->delete(self::TABLE, '`received_at` < ' . (int) (time() - self::KEEP_SECONDS));
        }
    }
}
