<?php
/**
 * POST index.php?fc=module&module=fetchsync&controller=webhook
 * (or /module/fetchsync/webhook with friendly URLs).
 *
 * Authenticated by the X-Fetch-Signature HMAC, not by a shop login.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchsyncWebhookModuleFrontController extends FetchSyncFrontController
{
    public function init()
    {
        if ('POST' !== (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
            $this->respond(405, array('error' => 'POST only'));
        }
        $raw = (string) file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_FETCH_SIGNATURE']) ? (string) $_SERVER['HTTP_X_FETCH_SIGNATURE'] : '';

        try {
            $this->bootstrap();
            list($code, $body) = FetchSyncWebhook::handle($raw, $signature);
        } catch (Exception $e) {
            FetchSyncLogger::error('Webhook failed: ' . $e->getMessage());
            $code = 500;
            $body = array('error' => 'internal error');
        }
        $this->respond($code, $body);
    }
}
