<?php
if (!defined('_PS_VERSION_')) { exit; }

class MgOmnibusCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $token = Tools::getValue('token');
        $wanted = Configuration::get(MgOmnibus::CFG_CRON_TOKEN);
        if (!$token || $token !== $wanted) {
            header('HTTP/1.1 403 Forbidden'); die('Forbidden');
        }
        require_once _PS_MODULE_DIR_.'mgomnibus/classes/PriceLogger.php';
        $limitParam = Tools::getValue('limit');
        $limit = ($limitParam === 'all') ? 0 : (int)($limitParam ?: 5000);
        PriceLogger::snapshotSomeProducts($this->context, $limit);
        PriceLogger::cleanupRetention((int)Configuration::get(MgOmnibus::CFG_RETENTION));
        header('Content-Type: application/json');
        die(json_encode(['status'=>'ok','captured'=>$limitParam?:$limit]));
    }
}
