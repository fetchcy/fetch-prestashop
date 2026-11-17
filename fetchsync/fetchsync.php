<?php
/**
 * Fetch for PrestaShop — sell your PrestaShop catalogue on Fetch (fetch.com.cy).
 * Products, prices and stock sync to Fetch automatically; Fetch orders arrive
 * as PrestaShop orders you can accept, mark ready or reject.
 *
 * It extends PaymentModule only to create orders with validateOrder(); it
 * offers no payment option at the shop's checkout.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/FetchSyncSettings.php';
require_once __DIR__ . '/classes/FetchSyncLogger.php';
require_once __DIR__ . '/classes/FetchSyncApi.php';
require_once __DIR__ . '/classes/FetchSyncContext.php';
require_once __DIR__ . '/classes/FetchSyncProductMapper.php';
require_once __DIR__ . '/classes/FetchSyncProductSync.php';
require_once __DIR__ . '/classes/FetchSyncMirror.php';
require_once __DIR__ . '/classes/FetchSyncOrderMapper.php';
require_once __DIR__ . '/classes/FetchSyncOrderSync.php';
require_once __DIR__ . '/classes/FetchSyncOrderActions.php';
require_once __DIR__ . '/classes/FetchSyncWebhook.php';
require_once __DIR__ . '/classes/FetchSyncCron.php';
require_once __DIR__ . '/classes/FetchSyncInstaller.php';
require_once __DIR__ . '/classes/FetchSyncAdmin.php';
require_once __DIR__ . '/classes/FetchSyncFrontController.php';

class Fetchsync extends PaymentModule
{
    const HOOKS = array(
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteBefore',
        'actionProductUpdate',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteBefore',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionUpdateQuantity',
        'actionEmailSendBefore',
        'displayAdminOrderSide',
        'displayBackOfficeHeader',
        'actionDispatcher',
        'actionShopDataDuplication',
    );

    public function __construct()
    {
        $this->name = 'fetchsync';
        $this->tab = 'market_place';
        $this->version = '1.2.0';
        $this->author = 'Fetch';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => _PS_VERSION_);
        $this->controllers = array('webhook', 'cron');

        parent::__construct();

        $this->displayName = $this->l('Fetch');
        $this->description = $this->l('Sell your catalogue on Fetch (fetch.com.cy). Products, prices and stock sync to Fetch automatically; Fetch orders arrive as PrestaShop orders you can accept, mark ready or reject.');
        $this->confirmUninstall = $this->l('Uninstalling removes the Fetch settings and sync tables. Orders and products stay. Continue?');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!FetchSyncInstaller::install()) {
            $this->_errors[] = 'Could not create the Fetch tables, order states, carrier or placeholder product.';

            return false;
        }
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        // Every shop gets the carrier and the placeholder product (the hooks are registered for all shops above).
        FetchSyncInstaller::ensureAllShops();

        return true;
    }

    public function uninstall()
    {
        return FetchSyncInstaller::uninstall() && parent::uninstall();
    }

    public function getContent()
    {
        FetchSyncContext::background();
        FetchSyncSettings::adoptLegacy();

        return (new FetchSyncAdmin($this))->content();
    }

    /** A new shop copied from another one: give it the carrier, the placeholder product and the hooks. */
    public function hookActionShopDataDuplication($params)
    {
        if (!empty($params['new_id_shop'])) {
            FetchSyncInstaller::ensureShop((int) $params['new_id_shop']);
        }
    }

    // --- Product sync -------------------------------------------------------
    // Each change is queued for every connected shop that sells the product
    // and has "Sync to Fetch" on (FetchSyncProductSync::queue()).

    public function hookActionObjectProductAddAfter($params)
    {
        $this->queueObject(isset($params['object']) ? $params['object'] : null, 'id');
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->queueObject(isset($params['object']) ? $params['object'] : null, 'id');
    }

    public function hookActionProductUpdate($params)
    {
        if (!empty($params['id_product'])) {
            FetchSyncProductSync::queue((int) $params['id_product']);
        }
    }

    public function hookActionObjectProductDeleteBefore($params)
    {
        if (isset($params['object']) && $params['object'] instanceof Product) {
            FetchSyncProductSync::queueDeletion($params['object']);
        }
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->queueObject(isset($params['object']) ? $params['object'] : null, 'id_product');
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->queueObject(isset($params['object']) ? $params['object'] : null, 'id_product');
    }

    public function hookActionObjectCombinationDeleteBefore($params)
    {
        if (isset($params['object']) && $params['object'] instanceof Combination) {
            FetchSyncProductSync::queueCombinationDeletion($params['object']);
            FetchSyncProductSync::queue((int) $params['object']->id_product);
        }
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->queueSpecificPrice($params);
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->queueSpecificPrice($params);
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->queueSpecificPrice($params);
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!empty($params['id_product'])) {
            FetchSyncProductSync::queue((int) $params['id_product']);
        }
    }

    private function queueSpecificPrice($params)
    {
        $price = isset($params['object']) ? $params['object'] : null;
        // Cart-only prices are the ones this module sets on imported Fetch orders.
        if ($price instanceof SpecificPrice && !(int) $price->id_cart) {
            $this->queueObject($price, 'id_product');
        }
    }

    private function queueObject($object, $field)
    {
        if (is_object($object) && !empty($object->{$field})) {
            FetchSyncProductSync::queue((int) $object->{$field});
        }
    }

    // --- Orders -------------------------------------------------------------

    /** No e-mail ever goes to the placeholder address of a Fetch customer. */
    public function hookActionEmailSendBefore($params)
    {
        $to = isset($params['to']) ? $params['to'] : null;
        foreach (is_array($to) ? $to : array($to) as $email) {
            if (FetchSyncOrderSync::isPlaceholderEmail($email)) {
                PrestaShopLogger::addLog('Fetch: e-mail "' . (isset($params['template']) ? (string) $params['template'] : '') . '" to a Fetch customer not sent', 1, null, 'FetchSync', 0, true);

                return false;
            }
        }

        return true;
    }

    public function hookDisplayAdminOrderSide($params)
    {
        return FetchSyncOrderActions::render($this, isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /** Shipping cost of the Fetch carrier: the Fetch delivery fee while importing, unavailable otherwise. */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        return FetchSyncOrderSync::shippingCostFor($params);
    }

    public function getOrderShippingCostExternal($params)
    {
        return FetchSyncOrderSync::shippingCostFor($params);
    }

    // --- Pseudo-cron ----------------------------------------------------------

    public function hookDisplayBackOfficeHeader($params)
    {
        FetchSyncCron::maybeTick();

        return '';
    }

    public function hookActionDispatcher($params)
    {
        $class = isset($params['controller_class']) ? (string) $params['controller_class'] : '';
        if (0 !== stripos($class, 'Fetchsync')) {
            FetchSyncCron::maybeTick();
        }
    }
}
