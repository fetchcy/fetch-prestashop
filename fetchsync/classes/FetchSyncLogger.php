<?php
/**
 * Logging to Advanced Parameters > Logs (object type "FetchSync"), plus a
 * short activity list shown on the module's configuration page.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncLogger
{
    const TABLE = 'fetchsync_log';
    const KEEP = 200;

    public static function info($message, array $context = array())
    {
        self::log('info', $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::log('error', $message, $context);
    }

    private static function log($level, $message, array $context)
    {
        $full = $message . ($context ? ' ' . json_encode($context) : '');
        try {
            // PrestaShopLogger refuses messages with < > { } (Validate::isMessage).
            PrestaShopLogger::addLog(
                Tools::substr('Fetch: ' . str_replace(array('<', '>', '{', '}'), array('(', ')', '(', ')'), $full), 0, 1000),
                'error' === $level ? 3 : 1,
                null,
                'FetchSync',
                0,
                true
            );
        } catch (Exception $e) {
            // Logging must never break a sync.
        }

        $db = Db::getInstance();
        $db->insert(self::TABLE, array(
            'level' => pSQL($level),
            'message' => pSQL(Tools::substr($message, 0, 1000)),
            'date_add' => date('Y-m-d H:i:s'),
        ));
        $id = (int) $db->Insert_ID();
        if ($id > self::KEEP && 0 === $id % 20) {
            $db->delete(self::TABLE, 'id_fetchsync_log <= ' . (int) ($id - self::KEEP));
        }
    }

    /** @return array<int,array{date_add:string,level:string,message:string}> */
    public static function activity($limit = 20)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `date_add`, `level`, `message` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            ORDER BY `id_fetchsync_log` DESC LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }
}
