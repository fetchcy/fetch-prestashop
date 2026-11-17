<?php
/**
 * Logging to Advanced Parameters > Logs (object type "FetchSync"), plus a
 * short activity list shown on the module's configuration page. Each entry
 * carries the shop it happened in (0 = no particular shop).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncLogger
{
    const TABLE = 'fetchsync_log';
    /** Entries kept, all shops together. */
    const KEEP = 500;

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

        try {
            $db = Db::getInstance();
            $db->insert(self::TABLE, array(
                'id_shop' => (int) FetchSyncSettings::scoped(),
                'level' => pSQL($level),
                'message' => pSQL(Tools::substr($message, 0, 1000)),
                'date_add' => date('Y-m-d H:i:s'),
            ));
            $id = (int) $db->Insert_ID();
            if ($id > self::KEEP && 0 === $id % 20) {
                $db->delete(self::TABLE, 'id_fetchsync_log <= ' . (int) ($id - self::KEEP));
            }
        } catch (Exception $e) {
            // Nor can the activity list (e.g. a table not upgraded yet).
        }
    }

    /**
     * The latest entries of one shop (and those of no particular shop), or of
     * every shop when $idShop is null.
     *
     * @return array<int,array{date_add:string,level:string,message:string,id_shop:string}>
     */
    public static function activity($limit = 20, $idShop = null)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `date_add`, `level`, `message`, `id_shop` FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . (null !== $idShop ? ' WHERE `id_shop` IN (0, ' . (int) $idShop . ')' : '') . '
            ORDER BY `id_fetchsync_log` DESC LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }
}
