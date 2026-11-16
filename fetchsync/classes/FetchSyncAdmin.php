<?php
/**
 * The module's configuration page (Modules → Module Manager → Fetch →
 * Configure): connection, product sync and catalogue mode, order import,
 * webhook registration, cron URL and recent activity.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncAdmin
{
    /** @var Module */
    private $module;

    /** @var string[] */
    private $messages = array();

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function content()
    {
        if (Tools::isSubmit('fetchsync_action')) {
            FetchSyncOrderActions::handlePost();

            return '';
        }

        $this->process();

        return implode('', $this->messages) . $this->mirrorNotice() . $this->renderIntro() . $this->renderForm() . $this->renderActivity();
    }

    /**
     * Handle a settings form submission.
     *
     * @return string[] the notices it produced (HTML)
     */
    public function process()
    {
        $do = null;
        $actions = array(
            'submitFetchsyncSave' => 'save',
            'submitFetchsyncConnect' => 'connect',
            'submitFetchsyncBulk' => 'bulk',
            'submitFetchsyncWebhook' => 'webhook',
            'submitFetchsyncMirrorPreview' => 'mirror_preview',
            'submitFetchsyncMirrorApply' => 'mirror_apply',
        );
        foreach ($actions as $field => $action) {
            if (Tools::isSubmit($field)) {
                $do = $action;
            }
        }
        if ($do) {
            $this->handle($do);
        }

        return $this->messages;
    }

    private function handle($do)
    {
        // "Apply anyway" posts from its own small form, without the settings fields.
        if ('mirror_apply' === $do) {
            $this->applyMirror();

            return;
        }
        $mirrorBefore = FetchSyncSettings::mirrorMode();
        $resync = $this->save();
        switch ($do) {
            case 'connect':
                $this->connect();
                break;
            case 'bulk':
                if (FetchSyncSettings::isConnected()) {
                    $count = $resync ? FetchSyncProductSync::pendingCount() : FetchSyncProductSync::startBulkSync();
                    $this->confirm(sprintf('Full product sync started: %d product(s) queued. They are sent in the background in batches of 50; progress shows under Recent activity.', $count)
                        . (FetchSyncSettings::mirrorMode() ? ' When it finishes, mirror mode switches off the Fetch products this shop does not sell.' : ''));
                } else {
                    $this->error('Connect to Fetch and choose a store first.');
                }
                break;
            case 'webhook':
                $this->registerWebhook();
                break;
            case 'mirror_preview':
                $this->previewMirror();
                break;
            default:
                if ($resync) {
                    $this->confirm('Settings saved. The price markup changed, so all products are being resent to Fetch.'
                        . (FetchSyncSettings::mirrorMode() ? ' Mirror mode runs when that full sync finishes.' : ''));
                } elseif (!$mirrorBefore && FetchSyncSettings::mirrorMode()) {
                    $this->confirm('Settings saved. Mirror mode runs at the end of the next full sync: press "Sync all products now". Press "Preview" first to see what it would switch off.');
                } else {
                    $this->confirm('Settings saved.');
                }
        }
    }

    /** @return bool true when a changed price markup started a full resync */
    private function save()
    {
        $old = FetchSyncSettings::all();
        $get = function ($key) {
            return trim((string) Tools::getValue('fetchsync_' . $key, ''));
        };

        $customUrl = rtrim($get('custom_base_url'), '/');
        if ('' !== $customUrl && !Validate::isAbsoluteUrl($customUrl)) {
            $this->error('The custom server URL is not a valid URL; it was not saved.');
            $customUrl = (string) $old['custom_base_url'];
        }
        $rawMarkup = $get('price_markup');
        if (!FetchSyncSettings::isMarkupInput($rawMarkup)) {
            $this->error('The price markup must be a number between 0 and 100; it was saved as 0.');
        }

        $new = array(
            'environment' => FetchSyncSettings::sanitizeEnvironment($get('environment')),
            'custom_base_url' => $customUrl,
            'client_id' => $get('client_id'),
            'sync_products' => '1' === $get('sync_products') ? 'yes' : 'no',
            'import_orders' => '1' === $get('import_orders') ? 'yes' : 'no',
            'auto_accept' => '1' === $get('auto_accept') ? 'yes' : 'no',
            'prep_minutes' => max(0, min(180, (int) $get('prep_minutes'))),
            'price_markup' => (string) FetchSyncSettings::sanitizeMarkup($rawMarkup),
            'catalogue_mode' => FetchSyncSettings::sanitizeCatalogueMode($get('catalogue_mode')),
        );
        if (FetchSyncSettings::MODE_MIRROR !== $new['catalogue_mode']) {
            FetchSyncSettings::setInternal('MIRROR_PENDING', '');
        }

        $secret = trim((string) Tools::getValue('fetchsync_client_secret', ''));
        if ('' !== $secret) {
            $new['client_secret'] = $secret;
        }

        $storeId = $get('store_id');
        if ('' !== $storeId) {
            $new['store_id'] = $storeId;
            foreach (self::cachedStores() as $store) {
                if ($store['id'] === $storeId) {
                    $new['store_name'] = $store['name'];
                }
            }
        }

        $serverChanged = $new['environment'] !== FetchSyncSettings::sanitizeEnvironment($old['environment'])
            || $new['custom_base_url'] !== (string) $old['custom_base_url'];
        $credentialsChanged = $serverChanged
            || $new['client_id'] !== (string) $old['client_id']
            || (isset($new['client_secret']) && $new['client_secret'] !== (string) $old['client_secret']);
        if ($credentialsChanged) {
            FetchSyncApi::forgetToken();
            // A different key or server: the old store, store list and webhook secret no longer apply.
            if ($serverChanged || $new['client_id'] !== (string) $old['client_id']) {
                $new['webhook_secret'] = '';
                $new['webhook_registered'] = '';
                FetchSyncSettings::setInternal('STORES', '');
                if ('' === $storeId || $storeId === (string) $old['store_id']) {
                    $new['store_id'] = '';
                    $new['store_name'] = '';
                }
            }
        }

        FetchSyncSettings::update($new);

        // Every Fetch price depends on the markup, so resend the catalogue when it changes.
        $oldMarkup = FetchSyncSettings::sanitizeMarkup($old['price_markup']);
        if ((float) $new['price_markup'] === $oldMarkup || !FetchSyncSettings::isConnected() || 'yes' !== $new['sync_products']) {
            return false;
        }
        FetchSyncProductSync::startBulkSync(sprintf('Price markup changed from %s%% to %s%%; resending all products', $oldMarkup, $new['price_markup']));

        return true;
    }

    private function connect()
    {
        $result = (new FetchSyncApi())->listStores();
        if (!FetchSyncApi::ok($result)) {
            $this->error('Could not connect: ' . FetchSyncApi::errorMessage($result));

            return;
        }
        $body = $result['body'];
        $rows = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : (is_array($body) ? $body : array());
        $stores = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['storeId']) ? $row['storeId'] : (isset($row['id']) ? $row['id'] : '');
            if ('' !== (string) $id) {
                $stores[] = array('id' => (string) $id, 'name' => isset($row['name']) ? (string) $row['name'] : (string) $id);
            }
        }
        if (!$stores) {
            $this->warning('Connected, but this key has no stores. Ask Fetch to link a store to your key.');

            return;
        }
        FetchSyncSettings::setInternal('STORES', json_encode($stores));

        // One store, or the saved one is gone: pick for the merchant.
        $saved = (string) FetchSyncSettings::get('store_id');
        $ids = array_column($stores, 'id');
        if (1 === count($stores) || !in_array($saved, $ids, true)) {
            FetchSyncSettings::update(array('store_id' => $stores[0]['id'], 'store_name' => $stores[0]['name']));
        }
        $this->confirm(1 === count($stores)
            ? 'Connected. 1 store found.'
            : sprintf('Connected. %d stores found — pick one and save.', count($stores)));
    }

    private function previewMirror()
    {
        if (!FetchSyncSettings::isConnected()) {
            $this->error('Connect to Fetch and choose a store first.');

            return;
        }
        $preview = FetchSyncMirror::preview();
        if ('failed' === $preview['status']) {
            $this->error('Preview failed: could not read your Fetch listings (' . $preview['error'] . ').');

            return;
        }
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $html = '<div class="alert alert-info fetchsync-mirror-preview"><p><strong>' . $h('Mirror preview') . ':</strong> '
            . $h(sprintf('%d of %d Fetch products would be switched off.', $preview['off'], $preview['active'])) . '</p>';
        if ($preview['samples']) {
            $html .= '<ul>';
            foreach ($preview['samples'] as $name) {
                $html .= '<li>' . $h($name) . '</li>';
            }
            $html .= '</ul>';
            if ($preview['off'] > count($preview['samples'])) {
                $html .= '<p>' . $h(sprintf('… and %d more.', $preview['off'] - count($preview['samples']))) . '</p>';
            }
        }
        $notes = array(sprintf('%d Fetch product(s) match this shop and stay on sale. Nothing has been changed.', $preview['kept']));
        if ($preview['held']) {
            $notes[] = 'That is more than half of your active Fetch products, so a mirror run will wait for you to press "Apply anyway".';
        }
        if (!FetchSyncSettings::mirrorMode()) {
            $notes[] = 'Choose "Mirror this shop 1:1" and run a full sync to apply it.';
        }
        $html .= '<p>' . $h(implode(' ', $notes)) . '</p></div>';
        $this->messages[] = $html;
    }

    private function applyMirror()
    {
        if (!FetchSyncSettings::isConnected() || !FetchSyncSettings::mirrorMode()) {
            $this->error('Mirror mode is not on.');

            return;
        }
        if (!FetchSyncMirror::pending()) {
            $this->warning('No mirror run is waiting to be applied.');

            return;
        }
        if (!FetchSyncCron::lock('flush')) {
            $this->warning('A product sync is running. Try again in a minute.');

            return;
        }
        try {
            $result = FetchSyncMirror::run(true);
        } finally {
            FetchSyncCron::unlock('flush');
        }
        if ('failed' === $result['status']) {
            $this->error('Mirror not applied: could not read your Fetch listings (' . $result['error'] . '). Nothing was switched off.');

            return;
        }
        $message = sprintf('Mirror applied: %d Fetch product(s) switched off, %d kept.', $result['switched_off'], $result['kept']);
        if ($result['errors']) {
            $this->warning($message . sprintf(' %d could not be switched off; see Recent activity.', $result['errors']));
        } else {
            $this->confirm($message);
        }
    }

    /** Admin notice for a mirror run the over-half guard is holding. */
    public function mirrorNotice()
    {
        $pending = FetchSyncMirror::pending();
        if (!$pending || !FetchSyncSettings::mirrorMode()) {
            return '';
        }
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $action = AdminController::$currentIndex . '&configure=' . $this->module->name . '&token=' . Tools::getAdminTokenLite('AdminModules');
        $text = sprintf(
            'Mirror mode is waiting for you. On %s it would have switched off %d of the %d active products in your Fetch store, more than half, so nothing was switched off. Press "Preview" under Products to see which ones. If that is right, apply it once.',
            date('Y-m-d H:i', (int) $pending['at']),
            (int) $pending['off'],
            (int) $pending['active']
        );

        return '<div class="alert alert-warning fetchsync-mirror-pending"><p>' . $h($text) . '</p>'
            . '<form method="post" action="' . $h($action) . '" style="margin-top:8px">'
            . '<button type="submit" name="submitFetchsyncMirrorApply" value="1" class="btn btn-warning" onclick="return confirm(' . $h(json_encode('Switch these products off on Fetch? They are not deleted, and come back when this shop sells them again.')) . ');">'
            . $h('Apply anyway') . '</button></form></div>';
    }

    private function registerWebhook()
    {
        if (!FetchSyncSettings::isConnected()) {
            $this->error('Connect to Fetch and choose a store first.');

            return;
        }
        $url = FetchSyncWebhook::url();
        if (0 !== strpos($url, 'https://')) {
            $this->warning('Your shop is not on https, so Fetch cannot send order alerts to it. Orders are still picked up every 2 minutes.');

            return;
        }
        $result = (new FetchSyncApi())->registerWebhook($url);
        if (!FetchSyncApi::ok($result) || empty($result['body']['signingSecret'])) {
            $this->error('Could not register the webhook: ' . FetchSyncApi::errorMessage($result));

            return;
        }
        FetchSyncSettings::update(array(
            'webhook_secret' => (string) $result['body']['signingSecret'],
            'webhook_registered' => time(),
        ));
        FetchSyncLogger::info('Webhook registered at ' . $url);
        $this->confirm('Webhook registered. New Fetch orders now arrive instantly.');
    }

    /** @return array<int,array{id:string,name:string}> */
    private static function cachedStores()
    {
        $stores = json_decode((string) FetchSyncSettings::internal('STORES', ''), true);

        return is_array($stores) ? $stores : array();
    }

    private function renderIntro()
    {
        return '<div class="panel"><h3><i class="icon-truck"></i> Fetch</h3><p>'
            . htmlspecialchars('Sell your PrestaShop products on Fetch. Products, prices and stock sync automatically; Fetch orders arrive as PrestaShop orders.', ENT_QUOTES, 'UTF-8')
            . '</p></div>';
    }

    private function renderForm()
    {
        $s = FetchSyncSettings::all();
        $connected = FetchSyncSettings::isConnected();
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };

        $stores = self::cachedStores();
        if ($stores) {
            $options = array();
            foreach ($stores as $store) {
                $options[] = array('id' => $store['id'], 'name' => $store['name']);
            }
            $storeInput = array(
                'type' => 'select',
                'label' => 'Fetch store',
                'name' => 'fetchsync_store_id',
                'options' => array('query' => $options, 'id' => 'id', 'name' => 'name'),
            );
        } else {
            $html = $s['store_id']
                ? '<p class="form-control-static"><strong>' . $h($s['store_name'] ? $s['store_name'] : $s['store_id']) . '</strong></p>'
                    . '<input type="hidden" name="fetchsync_store_id" value="' . $h($s['store_id']) . '">'
                : '<p class="form-control-static"><em>' . $h('Save your credentials, then press "Connect & load stores".') . '</em></p>';
            $storeInput = array('type' => 'html', 'label' => 'Fetch store', 'name' => 'fetchsync_store_html', 'html_content' => $html);
        }

        $webhookHtml = '';
        if ($s['webhook_registered']) {
            $webhookHtml .= '<p>' . $h(sprintf('Webhook registered on %s.', date('Y-m-d H:i', (int) $s['webhook_registered']))) . '</p>';
        }
        $webhookHtml .= '<p><code>' . $h(FetchSyncWebhook::url()) . '</code></p>'
            . '<p class="help-block">' . $h('Fetch then sends new orders here instantly. Without it, orders are still picked up every 2 minutes.') . '</p>';

        $cronHtml = '<p><code>' . $h(FetchSyncCron::url()) . '</code></p>'
            . '<p class="help-block">' . $h('Call this URL every 1–2 minutes from your server\'s cron (for example: curl -s "<URL>" > /dev/null). It sends queued product changes and picks up orders. Without it the module does the same work at most every 2 minutes while your shop or back office is being used.') . '</p>';

        $switch = function ($name, $label, $desc = '') {
            return array(
                'type' => 'switch',
                'label' => $label,
                'name' => 'fetchsync_' . $name,
                'is_bool' => true,
                'desc' => $desc,
                'values' => array(
                    array('id' => $name . '_on', 'value' => 1, 'label' => 'Yes'),
                    array('id' => $name . '_off', 'value' => 0, 'label' => 'No'),
                ),
            );
        };

        $forms = array();
        $forms[] = array('form' => array(
            'legend' => array('title' => 'Connection', 'icon' => 'icon-link'),
            'input' => array(
                array(
                    'type' => 'select',
                    'label' => 'Server',
                    'name' => 'fetchsync_environment',
                    'desc' => 'Live keys, and sandbox keys Fetch issues for testing, both use Production. Your key decides whether you see test data. Staging is only for keys Fetch has moved to staging.',
                    'options' => array(
                        'query' => array(
                            array('id' => 'production', 'name' => 'Production (api.fetch.com.cy)'),
                            array('id' => 'staging', 'name' => 'Staging (api-stg.fetch.com.cy)'),
                            array('id' => 'custom', 'name' => 'Custom URL'),
                        ),
                        'id' => 'id',
                        'name' => 'name',
                    ),
                ),
                array('type' => 'text', 'label' => 'Custom URL', 'name' => 'fetchsync_custom_base_url', 'placeholder' => 'https://', 'desc' => 'Only used with "Custom URL".'),
                array('type' => 'text', 'label' => 'Client ID', 'name' => 'fetchsync_client_id', 'autocomplete' => false),
                array(
                    'type' => 'password',
                    'label' => 'Client secret',
                    'name' => 'fetchsync_client_secret',
                    'autocomplete' => false,
                    'desc' => ($s['client_secret'] ? 'Saved — leave blank to keep. ' : '') . 'From the API key Fetch issued you (developer.fetch.com.cy).',
                ),
                $storeInput,
            ),
            'buttons' => array(
                array('title' => 'Connect & load stores', 'name' => 'submitFetchsyncConnect', 'type' => 'submit', 'icon' => 'process-icon-refresh', 'class' => 'pull-right'),
            ),
        ));
        $forms[] = array('form' => array(
            'legend' => array('title' => 'Products', 'icon' => 'icon-cubes'),
            'input' => array(
                $switch('sync_products', 'Sync to Fetch', 'Send product, price and stock changes to Fetch automatically. Standard products and products with combinations; each product (and each combination) is matched on Fetch by its reference.'),
                array(
                    'type' => 'text',
                    'label' => 'Price markup (%)',
                    'name' => 'fetchsync_price_markup',
                    'class' => 'fixed-width-sm',
                    'suffix' => '%',
                    'desc' => 'Added to every price and sale price sent to Fetch, e.g. 10 sends a €10.00 product as €11.00. Your PrestaShop prices are not changed.',
                ),
                array(
                    'type' => 'select',
                    'label' => 'Catalogue mode',
                    'name' => 'fetchsync_catalogue_mode',
                    'desc' => '"Add and update only" never touches the other products in your Fetch store. '
                        . '"Mirror this shop 1:1": Products in your Fetch store that aren\'t in this shop will be switched off on Fetch (not deleted). Prices and stock always follow this shop. '
                        . 'It runs at the end of every full sync ("Sync all products now", or a price markup change). '
                        . 'Product names, photos and descriptions come from Fetch\'s shared catalogue, which other stores use too, so they are not overwritten. '
                        . 'Press "Preview" to see what it would switch off.',
                    'options' => array(
                        'query' => array(
                            array('id' => FetchSyncSettings::MODE_ADD, 'name' => 'Add and update only'),
                            array('id' => FetchSyncSettings::MODE_MIRROR, 'name' => 'Mirror this shop 1:1'),
                        ),
                        'id' => 'id',
                        'name' => 'name',
                    ),
                ),
            ),
            'buttons' => $connected ? array(
                array('title' => 'Sync all products now', 'name' => 'submitFetchsyncBulk', 'type' => 'submit', 'icon' => 'process-icon-upload', 'class' => 'pull-right'),
                array('title' => 'Preview', 'name' => 'submitFetchsyncMirrorPreview', 'type' => 'submit', 'icon' => 'process-icon-preview', 'class' => 'pull-right'),
            ) : array(),
        ));
        $forms[] = array('form' => array(
            'legend' => array('title' => 'Orders', 'icon' => 'icon-shopping-cart'),
            'input' => array(
                $switch('import_orders', 'Import Fetch orders', 'Create a PrestaShop order for every Fetch order.'),
                $switch('auto_accept', 'Auto-accept', 'Accept new Fetch orders automatically.'),
                array('type' => 'text', 'label' => 'Preparation time', 'name' => 'fetchsync_prep_minutes', 'class' => 'fixed-width-sm', 'suffix' => 'minutes', 'desc' => 'The courier is booked to collect after this.'),
                array('type' => 'html', 'label' => 'Instant order alerts', 'name' => 'fetchsync_webhook_html', 'html_content' => $webhookHtml),
                array('type' => 'html', 'label' => 'Cron URL', 'name' => 'fetchsync_cron_html', 'html_content' => $cronHtml),
            ),
            'buttons' => $connected ? array(
                array('title' => $s['webhook_registered'] ? 'Re-register webhook' : 'Register webhook', 'name' => 'submitFetchsyncWebhook', 'type' => 'submit', 'icon' => 'process-icon-anchor', 'class' => 'pull-right'),
            ) : array(),
            'submit' => array('title' => 'Save settings', 'name' => 'submitFetchsyncSave'),
        ));

        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->module->name;
        $helper->default_form_language = FetchSyncSettings::defaultLangId();
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitFetchsyncSave';
        $helper->tpl_vars = array(
            'fields_value' => array(
                'fetchsync_environment' => FetchSyncSettings::sanitizeEnvironment($s['environment']),
                'fetchsync_custom_base_url' => $s['custom_base_url'],
                'fetchsync_client_id' => $s['client_id'],
                'fetchsync_client_secret' => '',
                'fetchsync_store_id' => $s['store_id'],
                'fetchsync_sync_products' => 'yes' === $s['sync_products'] ? 1 : 0,
                'fetchsync_price_markup' => (string) FetchSyncSettings::markup(),
                'fetchsync_catalogue_mode' => FetchSyncSettings::sanitizeCatalogueMode($s['catalogue_mode']),
                'fetchsync_import_orders' => 'yes' === $s['import_orders'] ? 1 : 0,
                'fetchsync_auto_accept' => 'yes' === $s['auto_accept'] ? 1 : 0,
                'fetchsync_prep_minutes' => (int) $s['prep_minutes'],
                'fetchsync_store_html' => '',
                'fetchsync_webhook_html' => '',
                'fetchsync_cron_html' => '',
            ),
            'languages' => Context::getContext()->controller->getLanguages(),
            'id_language' => Context::getContext()->language->id,
        );

        return $helper->generateForm($forms);
    }

    private function renderActivity()
    {
        $rows = FetchSyncLogger::activity(20);
        $pending = FetchSyncProductSync::pendingCount();
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $html = '<div class="panel"><h3><i class="icon-list"></i> ' . $h('Recent activity') . '</h3>';
        if ($pending) {
            $html .= '<p>' . $h(sprintf('%d product change(s) waiting to be sent.', $pending)) . '</p>';
        }
        if (!$rows) {
            $html .= '<p><em>' . $h('Nothing yet.') . '</em></p>';
        } else {
            $html .= '<table class="table"><tbody>';
            foreach ($rows as $row) {
                $level = 'error' === $row['level'] ? '<span class="label label-danger">Error</span>' : '<span class="label label-info">Info</span>';
                $html .= '<tr><td style="width:170px">' . $h($row['date_add']) . '</td><td style="width:70px">' . $level . '</td><td>' . $h($row['message']) . '</td></tr>';
            }
            $html .= '</tbody></table><p class="help-block">' . $h('Full log: Advanced Parameters → Logs, object type "FetchSync".') . '</p>';
        }

        return $html . '</div>';
    }

    private function confirm($message)
    {
        $this->messages[] = $this->module->displayConfirmation(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    }

    private function error($message)
    {
        $this->messages[] = $this->module->displayError(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    }

    private function warning($message)
    {
        $this->messages[] = $this->module->displayWarning(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    }
}
