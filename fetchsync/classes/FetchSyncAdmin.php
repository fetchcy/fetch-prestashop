<?php
/**
 * The module's configuration page (Modules → Module Manager → Fetch →
 * Configure): connection, product sync and catalogue mode, order import,
 * webhook registration, cron URL and recent activity.
 *
 * With multistore, the shop chosen in the back office's shop selector is the
 * one edited. In "All shops" or a group, the page shows an overview of every
 * shop instead: its Fetch store, last sync and status.
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

    /** @var int|null the shop being edited; null for the overview */
    private $idShop;

    /**
     * @param int|null $idShop the shop to edit; default: the back office's shop context
     */
    public function __construct(Module $module, $idShop = null)
    {
        $this->module = $module;
        $this->idShop = null !== $idShop ? (int) $idShop : self::contextShop();
    }

    /** The shop the back office is set to, or null in "All shops" / a group (multistore only). */
    public static function contextShop()
    {
        if (!FetchSyncSettings::multistore()) {
            return FetchSyncSettings::defaultShopId();
        }

        return Shop::CONTEXT_SHOP === Shop::getContext() ? (int) Shop::getContextShopID() : null;
    }

    public function content()
    {
        if (Tools::isSubmit('fetchsync_action')) {
            FetchSyncOrderActions::handlePost();

            return '';
        }
        if (null === $this->idShop) {
            return $this->renderIntro() . $this->overview() . $this->renderCron() . $this->renderActivity(null);
        }

        $admin = $this;

        return FetchSyncContext::forShop($this->idShop, function () use ($admin) {
            return $admin->shopPage();
        });
    }

    /** @internal the settings page of one shop, in that shop's context */
    public function shopPage()
    {
        $this->process();

        return implode('', $this->messages) . $this->mirrorNotice() . $this->renderIntro() . $this->renderForm() . $this->renderActivity($this->idShop);
    }

    /**
     * Handle a settings form submission for the shop being edited.
     *
     * @return string[] the notices it produced (HTML)
     */
    public function process()
    {
        if (null === $this->idShop) {
            return $this->messages;
        }
        if (FetchSyncSettings::scoped() !== $this->idShop) {
            $admin = $this;

            return FetchSyncContext::forShop($this->idShop, function () use ($admin) {
                return $admin->process();
            });
        }
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
                    $this->confirm('Settings saved. The ' . ('backorders' === $resync ? '"Exclude backorder items" setting' : 'price markup') . ' changed, so all products are being resent to Fetch.'
                        . (FetchSyncSettings::mirrorMode() ? ' Mirror mode runs when that full sync finishes.' : ''));
                } elseif (!$mirrorBefore && FetchSyncSettings::mirrorMode()) {
                    $this->confirm('Settings saved. Mirror mode runs at the end of the next full sync: press "Sync all products now". Press "Preview" first to see what it would switch off.');
                } else {
                    $this->confirm('Settings saved.');
                }
        }
        // A shop created after install may lack the module's carrier, placeholder product or hooks.
        if (FetchSyncSettings::isConnected()) {
            FetchSyncInstaller::ensureShop($this->idShop);
        }
    }

    /** @return string|false 'markup' or 'backorders' when that changed setting started a full resync */
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
            'exclude_backorders' => '1' === $get('exclude_backorders') ? 'yes' : 'no',
        );
        if (FetchSyncSettings::MODE_MIRROR !== $new['catalogue_mode']) {
            FetchSyncSettings::setInternal('MIRROR_PENDING', '');
        }

        $secret = trim((string) Tools::getValue('fetchsync_client_secret', ''));
        if ('' !== $secret) {
            $new['client_secret'] = $secret;
        }

        $storeId = $get('store_id');
        $newBase = 'custom' === $new['environment'] ? $customUrl : (isset(FetchSyncSettings::ENVIRONMENTS[$new['environment']]) ? FetchSyncSettings::ENVIRONMENTS[$new['environment']] : '');
        $owner = FetchSyncSettings::storeOwner($storeId, $newBase, $this->idShop);
        if ($owner) {
            $this->error(sprintf('The Fetch store "%s" is already connected to the shop "%s". Each shop needs its own Fetch store; the store was not changed.', self::storeLabel($storeId), FetchSyncSettings::shopName($owner)));
            $storeId = '';
        }
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

        // Every Fetch price depends on the markup, and what is sold depends on the backorder rule: resend the catalogue when either changes.
        $oldMarkup = FetchSyncSettings::sanitizeMarkup($old['price_markup']);
        $markupChanged = (float) $new['price_markup'] !== $oldMarkup;
        $backordersChanged = $new['exclude_backorders'] !== ('no' === $old['exclude_backorders'] ? 'no' : 'yes');
        if ((!$markupChanged && !$backordersChanged) || !FetchSyncSettings::isConnected() || 'yes' !== $new['sync_products']) {
            return false;
        }
        FetchSyncProductSync::startBulkSync($markupChanged
            ? sprintf('Price markup changed from %s%% to %s%%; resending all products', $oldMarkup, $new['price_markup'])
            : 'Exclude backorder items changed; resending all products');

        return $markupChanged ? 'markup' : 'backorders';
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

        // No usable store saved (none, gone, or another shop's): pick the first free one for the merchant.
        $baseUrl = FetchSyncSettings::baseUrl();
        $free = array();
        foreach ($stores as $store) {
            if (!FetchSyncSettings::storeOwner($store['id'], $baseUrl, $this->idShop)) {
                $free[] = $store;
            }
        }
        $saved = (string) FetchSyncSettings::get('store_id');
        if (!in_array($saved, array_column($free, 'id'), true)) {
            $pick = $free ? $free[0] : array('id' => '', 'name' => '');
            FetchSyncSettings::update(array('store_id' => $pick['id'], 'store_name' => $pick['name']));
        }
        if (!$free) {
            $this->warning(sprintf('Connected, but every Fetch store of this key (%d) is already connected to another shop. Each shop needs its own Fetch store; ask Fetch to add one to your key.', count($stores)));

            return;
        }
        $this->confirm(1 === count($stores)
            ? 'Connected. 1 store found.'
            : sprintf('Connected. %d stores found — pick one and save.', count($stores)));
    }

    private static function storeLabel($storeId)
    {
        foreach (self::cachedStores() as $store) {
            if ($store['id'] === (string) $storeId) {
                return $store['name'];
            }
        }

        return (string) $storeId;
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
        $lock = FetchSyncProductSync::lockName();
        if (!FetchSyncCron::lock($lock)) {
            $this->warning('A product sync is running. Try again in a minute.');

            return;
        }
        try {
            $result = FetchSyncMirror::run(true);
        } finally {
            FetchSyncCron::unlock($lock);
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
        $secret = (string) $result['body']['signingSecret'];
        $at = time();
        FetchSyncSettings::update(array('webhook_secret' => $secret, 'webhook_registered' => $at));
        $others = FetchSyncWebhook::shareSecret($this->idShop, $secret, $at);
        FetchSyncLogger::info('Webhook registered at ' . $url);
        $message = 'Webhook registered. New Fetch orders now arrive instantly.';
        if ($others) {
            $message .= ' ' . sprintf(
                'The shop(s) %s use the same key, which has one webhook at Fetch, so they now use the new signing secret too. Each order still goes to the shop of its Fetch store.',
                implode(', ', array_map(array('FetchSyncSettings', 'shopName'), $others))
            );
        }
        $this->confirm($message);
    }

    /** @return array<int,array{id:string,name:string}> */
    private static function cachedStores()
    {
        $stores = json_decode((string) FetchSyncSettings::internal('STORES', ''), true);

        return is_array($stores) ? $stores : array();
    }

    private function renderIntro()
    {
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $html = '<div class="panel"><h3><i class="icon-truck"></i> Fetch</h3><p>'
            . $h('Sell your PrestaShop products on Fetch. Products, prices and stock sync automatically; Fetch orders arrive as PrestaShop orders.')
            . '</p>';
        if (FetchSyncSettings::multistore()) {
            $html .= null === $this->idShop
                ? '<p class="fetchsync-overview-help">' . $h('Each shop connects to its own Fetch store. Choose a shop in the shop selector at the top of the page (or press "Edit" below) to change its settings.') . '</p>'
                : '<p class="fetchsync-shop"><strong>' . $h(sprintf('Settings for the shop "%s".', FetchSyncSettings::shopName($this->idShop))) . '</strong> '
                    . $h('Other shops have their own settings; choose "All shops" in the shop selector for an overview.') . '</p>';
        }

        return $html . '</div>';
    }

    /** "All shops" / group: one row per shop with its Fetch store, last sync and status. */
    public function overview()
    {
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $group = Shop::CONTEXT_GROUP === Shop::getContext() ? (int) Shop::getContextShopGroupID() : 0;
        $db = Db::getInstance();
        $html = '<div class="panel fetchsync-overview"><h3><i class="icon-sitemap"></i> ' . $h('Shops') . '</h3>'
            . '<table class="table"><thead><tr><th>' . implode('</th><th>', array_map($h, array('Shop', 'Fetch store', 'Sync to Fetch', 'Last product sync', 'Waiting', 'Last Fetch order', 'Status', ''))) . '</th></tr></thead><tbody>';
        foreach (FetchSyncSettings::shopIds() as $idShop) {
            if ($group && (int) Shop::getGroupFromShop($idShop) !== $group) {
                continue;
            }
            $row = FetchSyncSettings::withShop($idShop, function ($idShop) use ($db) {
                $lastSync = (int) $db->getValue('SELECT MAX(`synced_at`) FROM `' . _DB_PREFIX_ . FetchSyncProductMapper::TABLE . '` WHERE `id_shop` = ' . (int) $idShop . ' AND `status` = \'synced\'');
                $lastOrder = (int) $db->getValue('SELECT MAX(`date_add`) FROM `' . _DB_PREFIX_ . FetchSyncOrderSync::TABLE . '` WHERE `id_shop` = ' . (int) $idShop . ' AND `id_order` > 0');
                $lastLog = $db->getRow('SELECT `level`, `message`, `date_add` FROM `' . _DB_PREFIX_ . FetchSyncLogger::TABLE . '` WHERE `id_shop` = ' . (int) $idShop . ' ORDER BY `id_fetchsync_log` DESC');

                return array(
                    'connected' => FetchSyncSettings::isConnected(),
                    'store' => (string) FetchSyncSettings::get('store_name') ?: (string) FetchSyncSettings::get('store_id'),
                    'store_id' => (string) FetchSyncSettings::get('store_id'),
                    'sync' => FetchSyncSettings::enabled('sync_products'),
                    'mirror' => FetchSyncSettings::mirrorMode(),
                    'last_sync' => $lastSync,
                    'last_order' => $lastOrder,
                    'pending' => FetchSyncProductSync::pendingCount($idShop),
                    'backoff' => FetchSyncApi::backoffRemaining(),
                    'held' => FetchSyncSettings::mirrorMode() && FetchSyncMirror::pending(),
                    'log' => is_array($lastLog) ? $lastLog : null,
                );
            });
            if (!$row['connected']) {
                $status = 'Not connected';
            } elseif ($row['backoff'] > 0) {
                $status = sprintf('Paused: Fetch asked to slow down (%d s)', $row['backoff']);
            } elseif ($row['held']) {
                $status = 'Mirror held: waiting for "Apply anyway"';
            } elseif ($row['log'] && 'error' === $row['log']['level']) {
                $status = 'Error: ' . Tools::substr($row['log']['message'], 0, 160);
            } else {
                $status = 'Connected';
            }
            $edit = AdminController::$currentIndex . '&configure=' . $this->module->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&setShopContext=s-' . (int) $idShop;
            $html .= '<tr data-shop="' . (int) $idShop . '"><td>' . $h(FetchSyncSettings::shopName($idShop)) . ($idShop === FetchSyncSettings::defaultShopId() ? ' <span class="label label-default">' . $h('default') . '</span>' : '') . '</td>'
                . '<td>' . ($row['connected'] ? $h($row['store']) . ($row['store'] !== $row['store_id'] ? ' <small>(' . $h($row['store_id']) . ')</small>' : '') . ($row['mirror'] ? ' <span class="label label-info">' . $h('mirror') . '</span>' : '') : '<em>' . $h('Not connected') . '</em>') . '</td>'
                . '<td>' . $h($row['connected'] ? ($row['sync'] ? 'On' : 'Off') : '–') . '</td>'
                . '<td>' . $h($row['last_sync'] ? date('Y-m-d H:i', $row['last_sync']) : '–') . '</td>'
                . '<td>' . (int) $row['pending'] . '</td>'
                . '<td>' . $h($row['last_order'] ? date('Y-m-d H:i', $row['last_order']) : '–') . '</td>'
                . '<td class="fetchsync-status">' . $h($status) . '</td>'
                . '<td><a class="btn btn-default" href="' . $h($edit) . '"><i class="icon-pencil"></i> ' . $h('Edit') . '</a></td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** The cron URL, shared by every shop (overview only; a shop's page shows it under Orders). */
    private function renderCron()
    {
        $h = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };

        return '<div class="panel"><h3><i class="icon-time"></i> ' . $h('Cron URL') . '</h3><p><code>' . $h(FetchSyncCron::url()) . '</code></p>'
            . '<p class="help-block">' . $h('One URL for every shop: each run sends every shop\'s queued product changes and picks up every shop\'s orders. Call it every 1–2 minutes from your server\'s cron.') . '</p></div>';
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
                $owner = FetchSyncSettings::storeOwner($store['id'], FetchSyncSettings::baseUrl(), $this->idShop);
                $options[] = array('id' => $store['id'], 'name' => $store['name'] . ($owner ? ' — ' . sprintf('connected to the shop "%s"', FetchSyncSettings::shopName($owner)) : ''));
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
            . '<p class="help-block">' . $h('Call this URL every 1–2 minutes from your server\'s cron (for example: curl -s "<URL>" > /dev/null). It sends queued product changes and picks up orders' . (FetchSyncSettings::multistore() ? ', for every shop' : '') . '. Without it the module does the same work at most every 2 minutes while your shop or back office is being used.') . '</p>';

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
                $switch('exclude_backorders', 'Exclude backorder items', 'Products and combinations that PrestaShop would sell when out of stock (stock 0 or less, and ordering out-of-stock products allowed) are sent to Fetch with stock 0, so they show as out of stock, and are not added to Fetch as new products. One already on Fetch stays and comes back when stock returns.'),
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
                'fetchsync_exclude_backorders' => 'no' === $s['exclude_backorders'] ? 0 : 1,
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

    /** @param int|null $idShop one shop's activity, or every shop's (with a shop column) */
    private function renderActivity($idShop)
    {
        $rows = FetchSyncLogger::activity(20, $idShop);
        $pending = FetchSyncProductSync::pendingCount($idShop);
        $withShop = null === $idShop && FetchSyncSettings::multistore();
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
                $html .= '<tr><td style="width:170px">' . $h($row['date_add']) . '</td><td style="width:70px">' . $level . '</td>'
                    . ($withShop ? '<td style="width:160px">' . $h((int) $row['id_shop'] ? FetchSyncSettings::shopName((int) $row['id_shop']) : '') . '</td>' : '')
                    . '<td>' . $h($row['message']) . '</td></tr>';
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
