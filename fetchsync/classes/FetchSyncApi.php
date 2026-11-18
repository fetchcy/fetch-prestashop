<?php
/**
 * Fetch Partner API client: OAuth client-credentials token (cached), JSON
 * requests, one transparent retry on an expired token.
 *
 * Every call returns array{code:int, body:mixed, error:?string, retry_after:?int}.
 * A transport failure (no HTTP answer) has code 0 and an error message.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncApi
{
    const VERSION = '1.3.0';

    /** @var string */
    private $baseUrl;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct($baseUrl = null, $clientId = null, $clientSecret = null)
    {
        $this->baseUrl = rtrim(null !== $baseUrl ? $baseUrl : FetchSyncSettings::baseUrl(), '/');
        $this->clientId = null !== $clientId ? $clientId : (string) FetchSyncSettings::get('client_id');
        $this->clientSecret = null !== $clientSecret ? $clientSecret : (string) FetchSyncSettings::get('client_secret');
    }

    public static function forgetToken()
    {
        FetchSyncSettings::setInternal('TOKEN', '');
    }

    /** Partner path, e.g. "stores" → /api/v1/partner/stores. */
    public function partner($method, $path, $body = null, array $query = array(), $idempotencyKey = null)
    {
        return $this->request($method, '/api/v1/partner/' . ltrim($path, '/'), $body, $query, $idempotencyKey);
    }

    public function listStores()
    {
        return $this->partner('GET', 'stores');
    }

    public function upsertProducts($storeId, array $products, $idempotencyKey = null)
    {
        return $this->partner('PUT', 'stores/' . rawurlencode($storeId) . '/products', array('products' => $products), array(), $idempotencyKey);
    }

    /** One page of the store's listings: {data, nextCursor}. */
    public function listListings($storeId, array $query = array())
    {
        return $this->partner('GET', 'stores/' . rawurlencode($storeId) . '/listings', null, $query);
    }

    public function batchListings($storeId, array $items, $idempotencyKey)
    {
        return $this->partner('POST', 'stores/' . rawurlencode($storeId) . '/listings/batch', array('items' => $items), array(), $idempotencyKey);
    }

    public function listOrders($storeId, array $query = array())
    {
        return $this->partner('GET', 'stores/' . rawurlencode($storeId) . '/orders', null, $query);
    }

    public function getOrder($storeId, $orderId)
    {
        return $this->partner('GET', 'stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId));
    }

    public function acceptOrder($storeId, $orderId, $prepMinutes)
    {
        return $this->partner('POST', 'stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId) . '/accept', array('prepMinutes' => (int) $prepMinutes));
    }

    public function readyOrder($storeId, $orderId)
    {
        return $this->partner('POST', 'stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId) . '/ready', new stdClass());
    }

    public function rejectOrder($storeId, $orderId, $reason, $note = '')
    {
        $body = array('reason' => $reason);
        if ('' !== $note) {
            $body['note'] = $note;
        }

        return $this->partner('POST', 'stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId) . '/reject', $body);
    }

    public function registerWebhook($url)
    {
        return $this->partner('PUT', 'webhook', array('url' => $url, 'rotateSecret' => '1'));
    }

    public function request($method, $path, $body = null, array $query = array(), $idempotencyKey = null, $retried = false)
    {
        $token = $this->token();
        if (is_array($token)) {
            return $token;
        }

        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= (false === strpos($url, '?') ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = array(
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FetchForPrestaShop/' . self::VERSION . ' (+' . self::shopUrl() . ')',
        );
        $payload = null;
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body);
        }
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $result = self::http($method, $url, $headers, $payload, 30);
        if (401 === $result['code'] && !$retried) {
            self::forgetToken();

            return $this->request($method, $path, $body, $query, $idempotencyKey, true);
        }
        if (429 === $result['code']) {
            self::backoff(null !== $result['retry_after'] ? $result['retry_after'] : 60);
        }

        return $result;
    }

    /** Human-readable error from a failed call (Nest error shape). */
    public static function errorMessage(array $result)
    {
        if (!empty($result['error'])) {
            return $result['error'];
        }
        $body = isset($result['body']) ? $result['body'] : null;
        $message = is_array($body) && isset($body['message']) ? $body['message'] : null;
        if (is_array($message)) {
            $message = implode('; ', $message);
        }

        return sprintf('HTTP %d%s', isset($result['code']) ? $result['code'] : 0, $message ? ': ' . $message : '');
    }

    public static function ok($result)
    {
        return is_array($result) && empty($result['error']) && $result['code'] >= 200 && $result['code'] < 300;
    }

    /** A failure worth retrying later (network, rate limit, server error). */
    public static function transient(array $result)
    {
        return 0 === (int) $result['code'] || 429 === (int) $result['code'] || (int) $result['code'] >= 500;
    }

    /** Pause background calls (product flush, order poll) after a 429. */
    public static function backoff($seconds)
    {
        FetchSyncSettings::setInternal('BACKOFF_UNTIL', time() + max(1, min(3600, (int) $seconds)));
    }

    public static function backoffRemaining()
    {
        return max(0, (int) FetchSyncSettings::internal('BACKOFF_UNTIL', 0) - time());
    }

    /** @return string|array token, or an error result */
    private function token()
    {
        $cached = json_decode((string) FetchSyncSettings::internal('TOKEN', ''), true);
        if (is_array($cached) && isset($cached['token'], $cached['key'], $cached['expires'])
            && $cached['key'] === $this->cacheKey() && $cached['expires'] > time()) {
            return $cached['token'];
        }
        if ('' === $this->clientId || '' === $this->clientSecret) {
            return array('code' => 0, 'body' => null, 'error' => 'Enter your Fetch client ID and secret first.', 'retry_after' => null);
        }

        $result = self::http(
            'POST',
            $this->baseUrl . '/api/v1/oauth/token',
            array('Content-Type: application/json', 'Accept: application/json'),
            json_encode(array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            )),
            20
        );
        if (!self::ok($result) || empty($result['body']['access_token'])) {
            if (empty($result['error'])) {
                $result['error'] = 'Authentication failed: ' . self::errorMessage($result);
            }

            return $result;
        }

        $ttl = isset($result['body']['expires_in']) ? max(60, (int) $result['body']['expires_in'] - 60) : 3000;
        FetchSyncSettings::setInternal('TOKEN', json_encode(array(
            'token' => (string) $result['body']['access_token'],
            'key' => $this->cacheKey(),
            'expires' => time() + $ttl,
        )));

        return (string) $result['body']['access_token'];
    }

    /** A token belongs to one credential on one server. */
    private function cacheKey()
    {
        return md5($this->baseUrl . '|' . $this->clientId);
    }

    private static function shopUrl()
    {
        return class_exists('Tools') ? Tools::getShopDomainSsl(true) : '';
    }

    private static function http($method, $url, array $headers, $payload, $timeout)
    {
        $retryAfter = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
                if (0 === stripos($line, 'Retry-After:')) {
                    $retryAfter = (int) trim(substr($line, 12));
                }

                return strlen($line);
            },
        ));
        if (null !== $payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        if (false === $raw) {
            $error = curl_error($ch);
            curl_close($ch);

            return array('code' => 0, 'body' => null, 'error' => 'Could not reach Fetch: ' . $error, 'retry_after' => null);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array('code' => $code, 'body' => json_decode($raw, true), 'error' => null, 'retry_after' => $retryAfter);
    }
}
