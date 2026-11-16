<?php
/**
 * The "Fetch" block on the PrestaShop order page: status plus Accept /
 * Mark ready / Reject, each calling the Fetch API and then mirroring the
 * result onto the PrestaShop order.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncOrderActions
{
    const REJECT_REASONS = array(
        'OUT_OF_STOCK' => 'Out of stock',
        'STORE_CLOSED' => 'Store closed',
        'CANNOT_FULFIL' => 'Cannot fulfil',
        'OTHER' => 'Other',
    );

    public static function render(Module $module, $idOrder)
    {
        $row = FetchSyncOrderSync::rowForOrder((int) $idOrder);
        if (!$row) {
            return '';
        }
        $context = Context::getContext();
        $status = (string) $row['fetch_status'];
        $actions = FetchSyncOrderMapper::allowedActions($status);

        $notice = null;
        if (isset($context->cookie->fetchsync_notice) && $context->cookie->fetchsync_notice) {
            $decoded = json_decode($context->cookie->fetchsync_notice, true);
            if (is_array($decoded) && 2 === count($decoded)) {
                $notice = array('type' => 'error' === $decoded[0] ? 'danger' : 'success', 'message' => (string) $decoded[1]);
            }
            unset($context->cookie->fetchsync_notice);
            $context->cookie->write();
        }

        $context->smarty->assign(array(
            'fetchsync_id_order' => (int) $idOrder,
            'fetchsync_number' => '' !== (string) $row['fetch_order_number'] ? (string) $row['fetch_order_number'] : (string) $row['fetch_order_id'],
            'fetchsync_label' => FetchSyncOrderMapper::label($status),
            'fetchsync_can_accept' => in_array('accept', $actions, true),
            'fetchsync_can_ready' => in_array('ready', $actions, true),
            'fetchsync_can_reject' => in_array('reject', $actions, true),
            'fetchsync_prep_minutes' => (int) FetchSyncSettings::get('prep_minutes'),
            'fetchsync_reasons' => self::REJECT_REASONS,
            'fetchsync_url' => self::actionUrl(),
            'fetchsync_notice' => $notice,
        ));

        return $module->display($module->getLocalPath() . 'fetchsync.php', 'views/templates/hook/admin_order.tpl');
    }

    /** The buttons post to the module's configuration URL, which carries the admin token. */
    public static function actionUrl()
    {
        return Context::getContext()->link->getAdminLink('AdminModules', true, array(), array('configure' => 'fetchsync'));
    }

    public static function handlePost()
    {
        $context = Context::getContext();
        $idOrder = (int) Tools::getValue('fetchsync_order');
        $action = (string) Tools::getValue('fetchsync_action');
        $error = null;
        if (!$context->employee || !$context->employee->can('edit', 'AdminOrders')) {
            $error = 'You are not allowed to change orders.';
        } elseif (!FetchSyncOrderSync::rowForOrder($idOrder)) {
            $error = 'This is not a Fetch order.';
        } else {
            $error = self::run($idOrder, $action, array(
                'prepMinutes' => Tools::getIsset('fetchsync_prep_minutes') ? (int) Tools::getValue('fetchsync_prep_minutes') : (int) FetchSyncSettings::get('prep_minutes'),
                'reason' => (string) Tools::getValue('fetchsync_reason', 'OTHER'),
                'note' => trim(strip_tags((string) Tools::getValue('fetchsync_note', ''))),
            ));
        }
        $context->cookie->fetchsync_notice = json_encode($error ? array('error', $error) : array('success', 'Fetch updated.'));
        $context->cookie->write();
        Tools::redirectAdmin($context->link->getAdminLink('AdminOrders', true, array(), array('id_order' => $idOrder, 'vieworder' => 1)));
    }

    /**
     * Call Fetch for one action and mirror the new status.
     *
     * @return string|null error message, or null on success
     */
    public static function run($idOrder, $action, array $args = array())
    {
        $row = FetchSyncOrderSync::rowForOrder((int) $idOrder);
        if (!$row) {
            return 'This is not a Fetch order.';
        }
        $storeId = (string) FetchSyncSettings::get('store_id');
        $fetchId = (string) $row['fetch_order_id'];
        $api = new FetchSyncApi();

        switch ($action) {
            case 'accept':
                $prep = max(0, min(180, isset($args['prepMinutes']) ? (int) $args['prepMinutes'] : 15));
                $result = $api->acceptOrder($storeId, $fetchId, $prep);
                break;
            case 'ready':
                $result = $api->readyOrder($storeId, $fetchId);
                break;
            case 'reject':
                $reason = isset($args['reason']) && isset(self::REJECT_REASONS[$args['reason']]) ? $args['reason'] : 'OTHER';
                $note = isset($args['note']) ? Tools::substr((string) $args['note'], 0, 300) : '';
                $result = $api->rejectOrder($storeId, $fetchId, $reason, $note);
                break;
            default:
                return 'Unknown action.';
        }

        if (!FetchSyncApi::ok($result)) {
            $message = FetchSyncApi::errorMessage($result);
            FetchSyncOrderSync::note((int) $idOrder, sprintf('Fetch %s failed: %s', $action, $message));
            FetchSyncLogger::error(sprintf('Fetch %s failed for order #%d: %s', $action, $idOrder, $message));

            return $message;
        }

        // The API answers with the order; re-read it if the shape is unexpected.
        $data = is_array($result['body']) && isset($result['body']['status']) ? $result['body'] : null;
        if (!$data) {
            $fresh = $api->getOrder($storeId, $fetchId);
            $data = FetchSyncApi::ok($fresh) && is_array($fresh['body']) ? $fresh['body'] : null;
        }
        if ($data && isset($data['status'])) {
            FetchSyncOrderSync::applyStatus((int) $idOrder, (string) $data['status']);
        }
        FetchSyncLogger::info(sprintf('Fetch order %s: %s sent from PrestaShop order #%d.', '' !== (string) $row['fetch_order_number'] ? $row['fetch_order_number'] : $fetchId, $action, $idOrder));

        return null;
    }
}
