<?php
/**
 * Pure mapping rules between a Fetch order (Partner API shape) and PrestaShop.
 * Kept free of database access so it can be unit-tested on its own.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncOrderMapper
{
    /** Customer fields older than 2 hours come back as this. */
    const MASK = '******';

    /**
     * Fetch status → which PrestaShop order state the module uses.
     * "canceled" is PrestaShop's own Canceled state (PS_OS_CANCELED), which
     * puts the stock back; the others are installed by the module.
     */
    const STATUS_MAP = array(
        'DRAFT' => 'awaiting',
        'ACCEPTED' => 'accepted',
        'PREPARING' => 'accepted',
        'READY_FOR_PICKUP' => 'accepted',
        'DISPATCHED' => 'accepted',
        'DELIVERED' => 'delivered',
        'CANCELLED' => 'canceled',
        'REJECTED' => 'canceled',
        'REFUNDED' => 'refunded',
    );

    const STATUS_LABELS = array(
        'DRAFT' => 'Waiting for you to accept',
        'ACCEPTED' => 'Accepted',
        'PREPARING' => 'Preparing',
        'READY_FOR_PICKUP' => 'Ready for pickup',
        'DISPATCHED' => 'Out for delivery',
        'DELIVERED' => 'Delivered',
        'CANCELLED' => 'Cancelled',
        'REJECTED' => 'Rejected',
        'REFUNDED' => 'Refunded',
    );

    /** Statuses after which Fetch has released the stock again. */
    const RESTOCKED = array('CANCELLED', 'REJECTED', 'REFUNDED');

    public static function stateKey($fetchStatus)
    {
        return isset(self::STATUS_MAP[$fetchStatus]) ? self::STATUS_MAP[$fetchStatus] : 'awaiting';
    }

    public static function label($fetchStatus)
    {
        return isset(self::STATUS_LABELS[$fetchStatus]) ? self::STATUS_LABELS[$fetchStatus] : (string) $fetchStatus;
    }

    /** Which shop actions are allowed in a status (mirrors the API's own rules). */
    public static function allowedActions($fetchStatus)
    {
        $actions = array();
        if ('DRAFT' === $fetchStatus) {
            $actions[] = 'accept';
        }
        if (in_array($fetchStatus, array('ACCEPTED', 'PREPARING'), true)) {
            $actions[] = 'ready';
        }
        if (in_array($fetchStatus, array('DRAFT', 'ACCEPTED'), true)) {
            $actions[] = 'reject';
        }

        return $actions;
    }

    /** "Maria Georgiou" → ["Maria", "Georgiou"]. */
    public static function splitName($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ('' === $name) {
            return array('Fetch', 'customer');
        }
        $parts = explode(' ', $name, 2);

        return array($parts[0], isset($parts[1]) ? $parts[1] : '');
    }

    /**
     * Make a name pass PrestaShop's customer-name rule: no digits or symbols,
     * and a dot only before a space or the end ("M. G." is fine).
     */
    public static function cleanName($name, $fallback)
    {
        $name = preg_replace('/[0-9!<>,;?=+()\/\\\\@#"°*`{}_^$%:¤\[\]|]/u', '', (string) $name);
        $name = preg_replace('/[\.。](?!\s|$)/u', '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ('' === $name || '' === trim(str_replace(array('.', '。'), '', $name))) {
            return $fallback;
        }

        return self::cut($name, 255);
    }

    public static function isMasked(array $order)
    {
        $customer = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : array();
        $address = isset($customer['address']) && is_array($customer['address']) ? $customer['address'] : array();
        foreach (array(
            isset($customer['name']) ? $customer['name'] : null,
            isset($customer['phone']) ? $customer['phone'] : null,
            isset($address['line1']) ? $address['line1'] : null,
        ) as $value) {
            if (self::MASK === trim((string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Customer + address fields in PrestaShop's shape, each already cleaned to
     * pass PrestaShop's validators (masked values become empty or a fallback).
     */
    public static function address(array $order)
    {
        $customer = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : array();
        $address = isset($customer['address']) && is_array($customer['address']) ? $customer['address'] : array();
        $name = isset($customer['name']) ? (string) $customer['name'] : '';
        if (self::MASK === trim($name)) {
            $name = '';
        }
        list($first, $last) = self::splitName($name);

        $line1 = self::unmask(isset($address['line1']) ? $address['line1'] : '');
        $line1 = trim(preg_replace('/[!<>?=+@{}_$%]/u', '', $line1));
        $city = trim(preg_replace('/[!<>;?=+@#"°{}_$%]/u', '', self::unmask(isset($address['city']) ? $address['city'] : '')));
        $postcode = trim(self::unmask(isset($address['postalCode']) ? $address['postalCode'] : ''));
        if (!preg_match('/^[a-zA-Z 0-9-]+$/', $postcode)) {
            $postcode = '';
        }
        $phone = trim(self::unmask(isset($customer['phone']) ? $customer['phone'] : ''));
        if (!preg_match('/^[+0-9. ()\/-]*$/', $phone)) {
            $phone = '';
        }
        $pickup = self::isPickup($order);

        return array(
            'firstname' => self::cleanName($first, 'Fetch'),
            'lastname' => self::cleanName($last, '-'),
            'address1' => self::cut('' !== $line1 ? $line1 : ($pickup ? 'Pickup at store' : 'Address not provided'), 128),
            'city' => self::cut('' !== $city ? $city : '-', 64),
            'postcode' => self::cut($postcode, 12),
            'phone' => self::cut($phone, 32),
            'other' => self::cut(str_replace(array('<', '>', '{', '}'), '', self::customerNote($order)), 300),
        );
    }

    public static function customerNote(array $order)
    {
        $note = isset($order['customer']['address']['notes']) ? trim((string) $order['customer']['address']['notes']) : '';

        return self::MASK === $note ? '' : $note;
    }

    public static function isPickup(array $order)
    {
        return isset($order['deliveryType']) && 'pickup' === $order['deliveryType'];
    }

    /** Line price per unit, VAT included, from the line total (Fetch's own figure). */
    public static function unitPrice(array $line)
    {
        $quantity = max(1, (int) (isset($line['quantity']) ? $line['quantity'] : 1));
        $total = isset($line['totalPrice']) ? (float) $line['totalPrice'] : (isset($line['unitPrice']) ? (float) $line['unitPrice'] * $quantity : 0.0);

        return $total / $quantity;
    }

    /** "ps-12" → [12, 0], "ps-12-34" → [12, 34], anything else → null. */
    public static function parseFallbackRef($ref)
    {
        if (preg_match('/^ps-(\d+)(?:-(\d+))?$/', (string) $ref, $m)) {
            return array((int) $m[1], isset($m[2]) ? (int) $m[2] : 0);
        }

        return null;
    }

    private static function unmask($value)
    {
        $value = trim((string) $value);

        return self::MASK === $value ? '' : $value;
    }

    private static function cut($value, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
