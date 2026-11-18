<?php
/**
 * Receives Fetch order webhooks at
 * index.php?fc=module&module=fetchsync&controller=webhook.
 *
 * Fetch signs every delivery: `X-Fetch-Signature: t=<unix seconds>,v1=<hex>`
 * where v1 = HMAC-SHA256(secret, "<t>.<raw body>"). Deliveries older than five
 * minutes are refused so a captured request cannot be replayed.
 *
 * With multistore one URL serves every shop, whatever domain it is called
 * on: a delivery goes to the shop connected to the payload's Fetch storeId
 * and must be signed with that shop's secret.
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

    /** This shop's webhook URL, on the shop's own domain. */
    public static function url()
    {
        return Context::getContext()->link->getModuleLink('fetchsync', 'webhook', array(), null, FetchSyncSettings::defaultLangId(), FetchSyncSettings::shopId());
    }

    /**
     * @return array{0:int,1:array<string,mixed>} HTTP status and JSON body
     */
    public static function handle($raw, $signatureHeader)
    {
        $raw = (string) $raw;
        $signatureHeader = (string) $signatureHeader;
        // Read only to choose the shop; that shop's secret then decides.
        $payload = json_decode($raw, true);
        $idShop = self::shopFor(is_array($payload) ? $payload : array());
        if (!$idShop) {
            // Not a store of this installation: acknowledge a delivery any shop signed, so Fetch stops retrying.
            foreach (FetchSyncSettings::connectedShopIds() as $candidate) {
                $secret = (string) FetchSyncSettings::getFor($candidate, 'webhook_secret');
                if ('' !== $secret && self::verify($signatureHeader, $raw, $secret, time())) {
                    return array(200, array('ok' => true, 'ignored' => 'store not connected'));
                }
            }

            return array(401, array('error' => 'invalid signature'));
        }
        $secret = (string) FetchSyncSettings::getFor($idShop, 'webhook_secret');
        if ('' === $secret || !self::verify($signatureHeader, $raw, $secret, time())) {
            return array(401, array('error' => 'invalid signature'));
        }

        if (!is_array($payload) || empty($payload['event']) || empty($payload['data']) || !is_array($payload['data'])) {
            return array(400, array('error' => 'invalid payload'));
        }

        $eventId = isset($payload['eventId']) ? (string) $payload['eventId'] : '';
        if ('' !== $eventId && self::seen($eventId)) {
            return array(200, array('ok' => true, 'duplicate' => true));
        }

        FetchSyncContext::forShop($idShop, function () use ($payload) {
            if (0 === strpos((string) $payload['event'], 'order.') && FetchSyncSettings::enabled('import_orders')) {
                FetchSyncOrderSync::import($payload['data']);
            }
        });
        if ('' !== $eventId) {
            self::remember($eventId);
        }

        return array(200, array('ok' => true, 'shop' => $idShop));
    }

    /**
     * The connected shop a delivery is for: the one linked to its storeId, or
     * the only connected shop when the delivery names no store. 0 for none.
     */
    public static function shopFor(array $payload)
    {
        $storeId = isset($payload['data']['storeId']) && is_scalar($payload['data']['storeId']) ? (string) $payload['data']['storeId'] : '';
        if ('' !== $storeId) {
            $shops = FetchSyncSettings::shopsForStore($storeId);

            return $shops ? (int) $shops[0] : 0;
        }
        $connected = FetchSyncSettings::connectedShopIds();

        return 1 === count($connected) ? (int) $connected[0] : 0;
    }

    /**
     * After registering a webhook with a key: the key has one endpoint and
     * one signing secret at Fetch, so every shop using the same key on the
     * same server gets the new secret too.
     *
     * @return int[] the other shops that were updated
     */
    public static function shareSecret($idShop, $secret, $registeredAt)
    {
        $key = FetchSyncSettings::withShop($idShop, function () {
            return array(FetchSyncSettings::baseUrl(), (string) FetchSyncSettings::get('client_id'));
        });
        $updated = array();
        foreach (FetchSyncSettings::shopIds() as $other) {
            if ($other === (int) $idShop) {
                continue;
            }
            FetchSyncSettings::withShop($other, function () use ($key, $secret, $registeredAt, $other, &$updated) {
                if ('' !== $key[1] && FetchSyncSettings::baseUrl() === $key[0] && (string) FetchSyncSettings::get('client_id') === $key[1]) {
                    FetchSyncSettings::update(array('webhook_secret' => $secret, 'webhook_registered' => $registeredAt));
                    $updated[] = $other;
                }
            });
        }

        return $updated;
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
