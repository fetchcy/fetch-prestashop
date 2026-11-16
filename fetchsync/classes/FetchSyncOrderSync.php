<?php
/**
 * Brings Fetch orders into PrestaShop and keeps their status in step.
 *
 * Orders arrive by webhook (instant) and by a 2-minute poll (safety net for a
 * missed or unreachable webhook). Both paths go through import(), which is
 * idempotent on the Fetch order id (a row claimed in fetchsync_order before
 * the order is built), so a webhook and a poll seeing the same order never
 * create two PrestaShop orders.
 *
 * Each Fetch order becomes a real PrestaShop order made with validateOrder():
 * a guest customer with a placeholder address at fetch.invalid (PrestaShop
 * never emails it), the delivery address, the lines at the prices the
 * customer paid on Fetch, and the delivery fee as shipping. PrestaShop reduces
 * the stock; the stock change flows back to Fetch through the product sync.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncOrderSync
{
    const TABLE = 'fetchsync_order';
    const POLL_LOOKBACK = 172800;
    const EMAIL_DOMAIN = 'fetch.invalid';
    const PAYMENT_METHOD = 'Paid on Fetch';
    const PLACEHOLDER_REF = 'FETCH-UNMATCHED';
    const CLAIM_TIMEOUT = 300;

    /** Shipping fee for the cart being turned into an order (read by the module's carrier). */
    private static $buildingCart = 0;
    private static $shippingFee = 0.0;

    public static function shippingCostFor($cart)
    {
        if (self::$buildingCart && $cart instanceof Cart && (int) $cart->id === self::$buildingCart) {
            return self::$shippingFee;
        }

        return false;
    }

    /** Pull recent orders and import or update each one. */
    public static function poll()
    {
        if (!FetchSyncSettings::enabled('import_orders') || !FetchSyncSettings::isConnected() || FetchSyncApi::backoffRemaining() > 0) {
            return false;
        }
        if (!FetchSyncCron::lock('poll')) {
            return false;
        }
        try {
            $storeId = (string) FetchSyncSettings::get('store_id');
            $api = new FetchSyncApi();
            $query = array(
                'since' => gmdate('c', time() - self::POLL_LOOKBACK),
                'limit' => '100',
            );
            for ($page = 0; $page < 20; ++$page) {
                $result = $api->listOrders($storeId, $query);
                if (!FetchSyncApi::ok($result)) {
                    FetchSyncLogger::error('Could not read Fetch orders: ' . FetchSyncApi::errorMessage($result));

                    return false;
                }
                $body = is_array($result['body']) ? $result['body'] : array();
                foreach (isset($body['data']) && is_array($body['data']) ? $body['data'] : array() as $order) {
                    if (is_array($order)) {
                        self::import($order);
                    }
                }
                if (empty($body['nextCursor'])) {
                    break;
                }
                $query['cursor'] = (string) $body['nextCursor'];
            }
        } finally {
            FetchSyncCron::unlock('poll');
        }

        return true;
    }

    /**
     * Create the PrestaShop order for a Fetch order, or bring an existing one
     * up to date. Returns the PrestaShop order id, or 0 when skipped.
     */
    public static function import(array $data)
    {
        if (empty($data['orderId']) || empty($data['status'])) {
            return 0;
        }
        if (!empty($data['storeId']) && (string) $data['storeId'] !== (string) FetchSyncSettings::get('store_id')) {
            return 0; // Another store on the same credential.
        }
        $fetchId = (string) $data['orderId'];
        $status = (string) $data['status'];

        $row = self::row($fetchId);
        if (!$row) {
            $row = self::relink($fetchId);
        }
        if ($row && (int) $row['id_order'] > 0) {
            self::fillCustomer($row, $data);
            self::applyStatus((int) $row['id_order'], $status);

            return (int) $row['id_order'];
        }
        if ($row) {
            if (time() - (int) $row['date_add'] < self::CLAIM_TIMEOUT) {
                return 0; // Another request is creating it right now.
            }
            Db::getInstance()->delete(self::TABLE, '`fetch_order_id` = \'' . pSQL($fetchId) . '\' AND `id_order` = 0');
        }

        // A cancelled order we never saw needs no PrestaShop order.
        if (in_array($status, FetchSyncOrderMapper::RESTOCKED, true)) {
            return 0;
        }
        if (!self::claim($data)) {
            return 0;
        }

        $idOrder = 0;
        try {
            $idOrder = self::create($data);
        } catch (Exception $e) {
            FetchSyncLogger::error('Could not create the PrestaShop order for Fetch order ' . self::number($data) . ': ' . $e->getMessage());
        }
        if (!$idOrder) {
            Db::getInstance()->delete(self::TABLE, '`fetch_order_id` = \'' . pSQL($fetchId) . '\' AND `id_order` = 0');

            return 0;
        }

        if ('DRAFT' === $status && FetchSyncSettings::enabled('auto_accept')) {
            FetchSyncOrderActions::run($idOrder, 'accept', array('prepMinutes' => (int) FetchSyncSettings::get('prep_minutes')));
        }

        return $idOrder;
    }

    /** @return array<string,mixed>|null */
    public static function row($fetchId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `fetch_order_id` = \'' . pSQL($fetchId) . '\''
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function rowForOrder($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $idOrder
        );

        return is_array($row) ? $row : null;
    }

    public static function find($fetchId)
    {
        $row = self::row((string) $fetchId);

        return $row ? (int) $row['id_order'] : 0;
    }

    /**
     * After a reinstall the link table is gone; the Fetch order id is also the
     * payment's transaction id, so re-link from there instead of importing twice.
     */
    private static function relink($fetchId)
    {
        $idOrder = (int) Db::getInstance()->getValue(
            'SELECT o.`id_order` FROM `' . _DB_PREFIX_ . 'orders` o
            INNER JOIN `' . _DB_PREFIX_ . 'order_payment` op ON op.`order_reference` = o.`reference`
            WHERE o.`module` = \'fetchsync\' AND op.`transaction_id` = \'' . pSQL($fetchId) . '\'
            ORDER BY o.`id_order` ASC'
        );
        if (!$idOrder) {
            return null;
        }
        Db::getInstance()->insert(self::TABLE, array(
            'fetch_order_id' => pSQL($fetchId),
            'id_order' => $idOrder,
            'fetch_status' => '',
            'date_add' => time(),
            'date_upd' => time(),
        ), false, true, Db::INSERT_IGNORE);

        return self::row($fetchId);
    }

    private static function claim(array $data)
    {
        $db = Db::getInstance();
        $db->insert(self::TABLE, array(
            'fetch_order_id' => pSQL((string) $data['orderId']),
            'id_order' => 0,
            'fetch_order_number' => pSQL(isset($data['orderNumber']) ? (string) $data['orderNumber'] : ''),
            'fetch_store_id' => pSQL(isset($data['storeId']) ? (string) $data['storeId'] : ''),
            'fetch_status' => pSQL((string) $data['status']),
            'customer_masked' => FetchSyncOrderMapper::isMasked($data) ? 1 : 0,
            'restocked' => 0,
            'date_add' => time(),
            'date_upd' => time(),
        ), false, true, Db::INSERT_IGNORE);

        return 1 === (int) $db->Affected_Rows();
    }

    private static function number(array $data)
    {
        return !empty($data['orderNumber']) ? (string) $data['orderNumber'] : (string) $data['orderId'];
    }

    /** @return int PrestaShop order id, 0 on failure */
    private static function create(array $data)
    {
        $idShop = FetchSyncSettings::defaultShopId();
        $shop = new Shop($idShop);
        $language = new Language(FetchSyncSettings::defaultLangId());
        $idCurrency = !empty($data['currency']) ? (int) Currency::getIdByIsoCode((string) $data['currency'], $idShop) : 0;
        if (!$idCurrency) {
            $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        }
        $currency = new Currency($idCurrency, null, $idShop);
        $country = new Country((int) Country::getByIso('CY'));
        if (!Validate::isLoadedObject($country) || !$country->active) {
            $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        $carrier = FetchSyncInstaller::carrier();
        $idState = self::stateId(FetchSyncOrderMapper::stateKey((string) $data['status']));
        $module = Module::getInstanceByName('fetchsync');
        if (!$module || !$module->active || !$idState || !Validate::isLoadedObject($carrier)) {
            throw new PrestaShopException('the Fetch module is not fully installed (order states, carrier) or is disabled.');
        }

        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        $values = array(
            'shop' => $shop,
            'language' => $language,
            'currency' => $currency,
            'country' => $country,
            'customer' => new Customer(),
            'cart' => new Cart(),
        );

        return FetchSyncContext::with($values, function ($context) use ($data, $shop, $language, $currency, $country, $carrier, $idState, $module) {
            $fields = FetchSyncOrderMapper::address($data);
            $fetchId = (string) $data['orderId'];

            $customer = new Customer();
            $customer->firstname = $fields['firstname'];
            $customer->lastname = $fields['lastname'];
            $customer->email = 'order-' . Tools::substr(sha1($fetchId), 0, 20) . '@' . FetchSyncOrderSync::EMAIL_DOMAIN;
            $customer->passwd = FetchSyncOrderSync::randomPasswordHash();
            $customer->is_guest = 1;
            $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
            $customer->groupBox = array((int) Configuration::get('PS_GUEST_GROUP'));
            $customer->id_shop = (int) $shop->id;
            $customer->id_shop_group = (int) $shop->id_shop_group;
            $customer->id_lang = (int) $language->id;
            $customer->newsletter = 0;
            $customer->optin = 0;
            $customer->active = 1;
            if (!$customer->add()) {
                throw new PrestaShopException('could not create the guest customer');
            }
            $context->customer = $customer;

            $address = new Address();
            $address->id_customer = (int) $customer->id;
            $address->id_country = (int) $country->id;
            $address->alias = 'Fetch';
            $address->firstname = $fields['firstname'];
            $address->lastname = $fields['lastname'];
            $address->address1 = $fields['address1'];
            $address->city = $fields['city'];
            $address->postcode = $fields['postcode'];
            $address->phone = $fields['phone'];
            $address->phone_mobile = $fields['phone'];
            $address->other = $fields['other'];
            if (!$address->add()) {
                throw new PrestaShopException('could not create the delivery address');
            }

            $cart = new Cart();
            $cart->id_customer = (int) $customer->id;
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice = (int) $address->id;
            $cart->id_currency = (int) $currency->id;
            $cart->id_lang = (int) $language->id;
            $cart->id_shop = (int) $shop->id;
            $cart->id_shop_group = (int) $shop->id_shop_group;
            $cart->id_carrier = (int) $carrier->id;
            $cart->secure_key = $customer->secure_key;
            $cart->recyclable = 0;
            $cart->gift = 0;
            if (!$cart->add()) {
                throw new PrestaShopException('could not create the cart');
            }
            $context->cart = $cart;
            // Before any line is added: PrestaShop caches the delivery options as the cart fills.
            FetchSyncOrderSync::buildingCart((int) $cart->id, isset($data['shippingFee']) ? (float) $data['shippingFee'] : 0.0);
            try {
                return FetchSyncOrderSync::fillAndValidate($context, $data, $customer, $address, $cart, $shop, $currency, $carrier, $idState, $module);
            } finally {
                FetchSyncOrderSync::buildingCart(0, 0.0);
            }
        });
    }

    /**
     * Lines, shipping and validateOrder() for the cart create() prepared.
     *
     * @internal used by create()
     */
    public static function fillAndValidate($context, array $data, Customer $customer, Address $address, Cart $cart, Shop $shop, Currency $currency, Carrier $carrier, $idState, $module)
    {
        $fetchId = (string) $data['orderId'];
        $unmatched = array();
        $lines = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        foreach ($lines as $line) {
            if (!is_array($line) || !self::addLine($cart, $address, $line)) {
                $unmatched[] = is_array($line) ? $line : array();
            }
        }
        $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);
        if ($unmatched || !$lines) {
            $total = 0.0;
            foreach ($unmatched as $line) {
                $total += isset($line['totalPrice']) ? (float) $line['totalPrice'] : 0.0;
            }
            if (!$placeholder || !self::addToCart($cart, $address, $placeholder, 0, 1, $total)) {
                throw new PrestaShopException('could not add the unmatched lines (placeholder product missing)');
            }
        }

        $cart->delivery_option = json_encode(array((int) $address->id => (int) $carrier->id . ','));
        $cart->update();

        $amount = (float) Tools::ps_round((float) $cart->getOrderTotal(true, Cart::BOTH), 2);
        $module->validateOrder(
            (int) $cart->id,
            (int) $idState,
            $amount,
            self::PAYMENT_METHOD,
            null,
            array('transaction_id' => $fetchId),
            (int) $currency->id,
            false,
            $customer->secure_key,
            $shop
        );
        $idOrder = (int) $module->currentOrder;
        if (!$idOrder) {
            throw new PrestaShopException('validateOrder created no order');
        }

        Db::getInstance()->update(self::TABLE, array(
            'id_order' => $idOrder,
            'date_upd' => time(),
        ), '`fetch_order_id` = \'' . pSQL($fetchId) . '\'');

        if ($unmatched) {
            self::splitUnmatched($idOrder, $placeholder, $unmatched);
            StockAvailable::setQuantity($placeholder, 0, 0, (int) $shop->id, false);
        }
        self::finish($idOrder, $data, $unmatched);

        return $idOrder;
    }

    /** @internal used by create() */
    public static function buildingCart($idCart, $fee)
    {
        self::$buildingCart = (int) $idCart;
        self::$shippingFee = (float) $fee;
    }

    public static function randomPasswordHash()
    {
        $plain = Tools::passwdGen(32);
        if (class_exists('PrestaShop\PrestaShop\Adapter\ServiceLocator')) {
            $crypto = PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');

            return $crypto->hash($plain);
        }

        return Tools::hash($plain);
    }

    /** A Fetch line → a cart line for the product whose ref matches, at the Fetch price. */
    public static function addLine(Cart $cart, Address $address, array $line)
    {
        $target = self::productForRef(isset($line['ref']) ? (string) $line['ref'] : '');
        if (!$target) {
            return false;
        }
        $quantity = max(1, (int) (isset($line['quantity']) ? $line['quantity'] : 1));
        $total = FetchSyncOrderMapper::unitPrice($line) * $quantity;

        return self::addToCart($cart, $address, $target[0], $target[1], $quantity, $total);
    }

    /**
     * Put a product in the cart with a cart-only specific price, so the order
     * line costs exactly what the customer paid on Fetch (VAT included).
     */
    public static function addToCart(Cart $cart, Address $address, $idProduct, $ipa, $quantity, $totalInclTax)
    {
        $product = new Product((int) $idProduct, false, (int) $cart->id_lang, (int) $cart->id_shop);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }
        $rate = (float) $product->getTaxesRate($address);
        $unitExcl = round(((float) $totalInclTax / max(1, (int) $quantity)) / (1 + $rate / 100), 6);

        $price = new SpecificPrice();
        $price->id_shop = (int) $cart->id_shop;
        $price->id_shop_group = 0;
        $price->id_cart = (int) $cart->id;
        $price->id_product = (int) $idProduct;
        $price->id_product_attribute = (int) $ipa;
        $price->id_currency = 0;
        $price->id_country = 0;
        $price->id_group = 0;
        $price->id_customer = 0;
        $price->id_specific_price_rule = 0;
        $price->price = $unitExcl;
        $price->from_quantity = 1;
        $price->reduction = 0;
        $price->reduction_tax = 1;
        $price->reduction_type = 'amount';
        $price->from = '0000-00-00 00:00:00';
        $price->to = '0000-00-00 00:00:00';
        if (!$price->add()) {
            return false;
        }

        $added = $cart->updateQty((int) $quantity, (int) $idProduct, (int) $ipa ? (int) $ipa : null, false, 'up', (int) $address->id, null, false, true);
        if (true !== $added) {
            $price->delete();

            return false;
        }

        return true;
    }

    /** Our frozen ref first, then a combination or product reference, then ps-<id>[-<ipa>]. */
    public static function productForRef($ref)
    {
        $ref = trim((string) $ref);
        if ('' === $ref) {
            return null;
        }
        $db = Db::getInstance();
        $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);

        $row = $db->getRow(
            'SELECT fp.`id_product`, fp.`id_product_attribute` FROM `' . _DB_PREFIX_ . FetchSyncProductMapper::TABLE . '` fp
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = fp.`id_product`
            WHERE fp.`ref` = \'' . pSQL($ref) . '\''
        );
        if ($row && self::validTarget((int) $row['id_product'], (int) $row['id_product_attribute'])) {
            return array((int) $row['id_product'], (int) $row['id_product_attribute']);
        }

        $row = $db->getRow(
            'SELECT MIN(`id_product`) AS `id_product`, MIN(`id_product_attribute`) AS `id_product_attribute`
            FROM `' . _DB_PREFIX_ . 'product_attribute`
            WHERE `reference` = \'' . pSQL($ref) . '\' HAVING COUNT(*) = 1'
        );
        if ($row) {
            return array((int) $row['id_product'], (int) $row['id_product_attribute']);
        }

        $idProduct = (int) $db->getValue(
            'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
            WHERE p.`reference` = \'' . pSQL($ref) . '\' AND p.`id_product` <> ' . $placeholder . '
            AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.`id_product` = p.`id_product`)
            ORDER BY p.`id_product`'
        );
        if ($idProduct) {
            return array($idProduct, 0);
        }

        $parsed = FetchSyncOrderMapper::parseFallbackRef($ref);
        if ($parsed && $parsed[0] !== $placeholder && self::validTarget($parsed[0], $parsed[1])) {
            return $parsed;
        }

        return null;
    }

    private static function validTarget($idProduct, $ipa)
    {
        $db = Db::getInstance();
        if ($ipa) {
            return (bool) $db->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute`
                WHERE `id_product` = ' . (int) $idProduct . ' AND `id_product_attribute` = ' . (int) $ipa
            );
        }

        return (bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . (int) $idProduct)
            && !$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int) $idProduct);
    }

    /**
     * Unmatched Fetch lines went into the cart as one placeholder line; turn it
     * back into one order line per Fetch line, by name, without stock.
     */
    public static function splitUnmatched($idOrder, $placeholder, array $lines)
    {
        $idDetail = (int) Db::getInstance()->getValue(
            'SELECT `id_order_detail` FROM `' . _DB_PREFIX_ . 'order_detail`
            WHERE `id_order` = ' . (int) $idOrder . ' AND `product_id` = ' . (int) $placeholder
        );
        if (!$idDetail) {
            return;
        }
        $source = new OrderDetail($idDetail);
        foreach ($lines as $line) {
            $quantity = max(1, (int) (isset($line['quantity']) ? $line['quantity'] : 1));
            $total = (float) Tools::ps_round(FetchSyncOrderMapper::unitPrice($line) * $quantity, 2);
            $unit = $total / $quantity;
            $name = isset($line['name']) && '' !== (string) $line['name'] ? (string) $line['name'] : 'Fetch item';
            if (!empty($line['variantName'])) {
                $name .= ' – ' . $line['variantName'];
            }

            $detail = clone $source;
            $detail->id = null;
            $detail->product_name = Tools::substr(str_replace(array('<', '>', '{', '}'), '', $name), 0, 255);
            $detail->product_reference = Tools::substr(isset($line['ref']) ? (string) $line['ref'] : '', 0, 64);
            $detail->product_quantity = $quantity;
            $detail->product_quantity_in_stock = $quantity;
            $detail->unit_price_tax_incl = $unit;
            $detail->unit_price_tax_excl = $unit;
            $detail->total_price_tax_incl = $total;
            $detail->total_price_tax_excl = $total;
            $detail->product_price = $unit;
            $detail->original_product_price = $unit;
            $detail->add();
        }
        $source->delete();
    }

    /** Totals, date, notes and log once validateOrder() has made the order. */
    public static function finish($idOrder, array $data, array $unmatched)
    {
        $db = Db::getInstance();
        $order = new Order((int) $idOrder);
        $number = self::number($data);

        // Fetch's total is authoritative; a cent of rounding is corrected here.
        if (isset($data['total'])) {
            $fetchTotal = (float) $data['total'];
            if (abs((float) $order->total_paid_tax_incl - $fetchTotal) >= 0.005) {
                $db->update('orders', array(
                    'total_paid' => $fetchTotal,
                    'total_paid_tax_incl' => $fetchTotal,
                    'total_paid_real' => $fetchTotal,
                ), '`id_order` = ' . (int) $idOrder);
                $db->update('order_payment', array('amount' => $fetchTotal), '`order_reference` = \'' . pSQL($order->reference) . '\'');
                self::note($idOrder, sprintf('Total set to the Fetch total %s (PrestaShop computed %s).', FetchSyncProductMapper::money($fetchTotal), FetchSyncProductMapper::money($order->total_paid_tax_incl)));
            }
        }
        if (!empty($data['placedAt']) && false !== ($placed = strtotime((string) $data['placedAt']))) {
            $db->update('orders', array('date_add' => date('Y-m-d H:i:s', $placed)), '`id_order` = ' . (int) $idOrder);
        }

        self::note($idOrder, sprintf('Fetch order %s imported (%s).', $number, FetchSyncOrderMapper::label((string) $data['status'])));
        $customerNote = FetchSyncOrderMapper::customerNote($data);
        if ('' !== $customerNote) {
            self::note($idOrder, 'Customer note: ' . $customerNote);
        }
        if (FetchSyncOrderMapper::isPickup($data)) {
            self::note($idOrder, 'Pickup order: the customer collects it from the store.');
        }
        if ($unmatched) {
            $names = array();
            foreach ($unmatched as $line) {
                $names[] = isset($line['name']) ? $line['name'] : (isset($line['ref']) ? $line['ref'] : '?');
            }
            self::note($idOrder, 'These Fetch items match no PrestaShop product (no reference match), so their stock was not reduced: ' . implode(', ', $names));
        }
        FetchSyncLogger::info(sprintf('Imported Fetch order %s as PrestaShop order #%d.', $number, $idOrder));
    }

    /** Append a line to the order's private note (shown on the order page). */
    public static function note($idOrder, $text)
    {
        $line = '[' . date('Y-m-d H:i') . '] ' . str_replace(array("\r", "\n"), ' ', $text);
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'orders`
            SET `note` = TRIM(CONCAT(IFNULL(`note`, \'\'), \'\n\', \'' . pSQL($line) . '\'))
            WHERE `id_order` = ' . (int) $idOrder
        );
    }

    /** Mirror a Fetch status onto the PrestaShop order. */
    public static function applyStatus($idOrder, $fetchStatus)
    {
        $row = self::rowForOrder($idOrder);
        if (!$row || (string) $row['fetch_status'] === (string) $fetchStatus) {
            return;
        }
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $db = Db::getInstance();
        $restocked = (int) $row['restocked'];
        $canceled = (int) Configuration::get('PS_OS_CANCELED');
        $key = FetchSyncOrderMapper::stateKey($fetchStatus);
        $target = self::stateId($key);
        $label = 'Fetch: ' . FetchSyncOrderMapper::label($fetchStatus) . '.';

        if ('canceled' === $key) {
            // Already refunded and restocked by us: keep "Refunded", PrestaShop would restock twice.
            if ($restocked) {
                $target = 0;
            } else {
                $restocked = 1;
            }
        } elseif ('refunded' === $key) {
            if (!$restocked && (int) $order->current_state !== $canceled) {
                self::restock($order);
            }
            $restocked = 1;
        } elseif ((int) $order->current_state === $canceled) {
            // Back from Canceled: PrestaShop takes the stock again.
            $restocked = 0;
        }

        $db->update(self::TABLE, array(
            'fetch_status' => pSQL($fetchStatus),
            'restocked' => $restocked,
            'date_upd' => time(),
        ), '`id_order` = ' . (int) $idOrder);

        if ($target && (int) $order->current_state !== (int) $target) {
            self::changeState($order, $target);
        }
        self::note($idOrder, $label);
    }

    private static function changeState(Order $order, $idState)
    {
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $employee = Context::getContext()->employee;
        $history->id_employee = $employee && Validate::isLoadedObject($employee) ? (int) $employee->id : 0;
        $history->changeIdOrderState((int) $idState, $order, true);
        $history->add();
    }

    /** Give the stock back for an order Fetch refunded (PrestaShop only does it on Canceled). */
    private static function restock(Order $order)
    {
        $placeholder = (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0);
        foreach ($order->getProductsDetail() as $detail) {
            if ((int) $detail['product_id'] === $placeholder || !(int) $detail['product_id']) {
                continue;
            }
            if (StockAvailable::dependsOnStock((int) $detail['product_id'])) {
                continue;
            }
            StockAvailable::updateQuantity(
                (int) $detail['product_id'],
                (int) $detail['product_attribute_id'],
                (int) $detail['product_quantity'],
                (int) $order->id_shop
            );
        }
    }

    /**
     * Fetch masks name, phone and address 2 hours after the order. An order
     * first seen masked is completed when an unmasked copy turns up; good data
     * is never overwritten with masked data.
     */
    private static function fillCustomer(array $row, array $data)
    {
        if (!(int) $row['customer_masked'] || FetchSyncOrderMapper::isMasked($data)) {
            return;
        }
        $order = new Order((int) $row['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $fields = FetchSyncOrderMapper::address($data);
        $customer = new Customer((int) $order->id_customer);
        if (Validate::isLoadedObject($customer)) {
            $customer->firstname = $fields['firstname'];
            $customer->lastname = $fields['lastname'];
            $customer->update();
        }
        $address = new Address((int) $order->id_address_delivery);
        if (Validate::isLoadedObject($address)) {
            foreach (array('firstname', 'lastname', 'address1', 'city', 'postcode', 'phone', 'other') as $field) {
                $address->{$field} = $fields[$field];
            }
            $address->phone_mobile = $fields['phone'];
            $address->update();
        }
        Db::getInstance()->update(self::TABLE, array('customer_masked' => 0, 'date_upd' => time()), '`id_order` = ' . (int) $order->id);
        self::note((int) $order->id, 'Customer details completed from Fetch.');
    }

    /** PrestaShop order state id for a mapping key. */
    public static function stateId($key)
    {
        switch ($key) {
            case 'canceled':
                return (int) Configuration::get('PS_OS_CANCELED');
            case 'accepted':
                return (int) FetchSyncSettings::internal('OS_ACCEPTED', 0);
            case 'delivered':
                return (int) FetchSyncSettings::internal('OS_DELIVERED', 0);
            case 'refunded':
                return (int) FetchSyncSettings::internal('OS_REFUNDED', 0);
            default:
                return (int) FetchSyncSettings::internal('OS_AWAITING', 0);
        }
    }

    public static function isPlaceholderEmail($email)
    {
        $suffix = '@' . self::EMAIL_DOMAIN;

        return is_string($email) && Tools::strlen($email) > Tools::strlen($suffix)
            && Tools::strtolower(Tools::substr($email, -Tools::strlen($suffix))) === $suffix;
    }
}
