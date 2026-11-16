<?php
/**
 * GET index.php?fc=module&module=fetchsync&controller=cron&token=<cron token>
 *
 * Sends queued product changes and polls Fetch for orders. Call it every 1–2
 * minutes from the server's cron; the token is shown on the module's page.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchsyncCronModuleFrontController extends FetchSyncFrontController
{
    public function init()
    {
        $expected = (string) FetchSyncSettings::get('cron_token');
        $given = (string) Tools::getValue('token', '');
        if ('' === $expected || !hash_equals($expected, $given)) {
            $this->respond(403, array('error' => 'invalid token'));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        ignore_user_abort(true);
        try {
            $this->bootstrap();
            $summary = FetchSyncCron::run(50);
        } catch (Exception $e) {
            FetchSyncLogger::error('Cron run failed: ' . $e->getMessage());
            $this->respond(500, array('error' => 'internal error'));
        }
        $this->respond(200, array('ok' => true) + $summary);
    }
}
