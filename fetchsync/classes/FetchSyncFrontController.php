<?php
/**
 * Base for the webhook and cron endpoints. They skip the normal front-office
 * init (cart, cookies, maintenance page, redirects) so the raw body and the
 * response stay exactly what Fetch sent and expects, but still need the
 * service container and locale that order creation relies on.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class FetchSyncFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    protected function bootstrap()
    {
        FetchSyncContext::background();
        if (null === $this->getContainer()) {
            $this->container = $this->buildContainer();
        }
        if (empty($this->context->currentLocale)) {
            try {
                $this->context->currentLocale = $this->get('prestashop.core.localization.locale.repository')
                    ->getLocale($this->context->language->getLocale());
            } catch (Exception $e) {
                // Prices in e-mails only; never block an import on it.
            }
        }
    }

    protected function respond($code, array $body)
    {
        http_response_code((int) $code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($body);
        exit;
    }
}
