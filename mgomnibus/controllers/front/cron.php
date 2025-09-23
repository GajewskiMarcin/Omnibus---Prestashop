<?php

class MgOmnibusCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $token = Tools::getValue('token');
        $stored_token = Configuration::get('MGOMNIBUS_CRON_TOKEN');

        if (!$token || $token !== $stored_token) {
            header('HTTP/1.1 403 Forbidden');
            exit('Forbidden: Invalid token');
        }

        $limit = Tools::getValue('limit', '50'); // Default limit of 50 products per run
        $this->snapshotProducts($limit);
        $this->cleanupRetention();

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'message' => 'CRON job executed.']);
        exit;
    }

    private function snapshotProducts($limit)
    {
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $query = new DbQuery();
        $query->select('p.id_product');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)$this->context->shop->id);
        $query->where('ps.active = 1');
        
        if ($limit !== 'all') {
            // Simple offset logic based on day of month to cycle through products
            $dayOfMonth = (int)date('j');
            $totalProducts = (int)$db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product WHERE ps.id_shop = ' . (int)$this->context->shop->id . ' AND ps.active = 1');
            $offset = ($dayOfMonth * (int)$limit) % $totalProducts;
            $query->limit((int)$limit, $offset);
        }

        $products = $db->executeS($query);

        if ($products) {
            foreach ($products as $product) {
                PriceLogger::logProductPrices((int)$product['id_product']);
            }
        }
    }
    
    private function cleanupRetention()
    {
        $retentionDays = (int)Configuration::get('MGOMNIBUS_RETENTION', null, null, null, 365);
        if ($retentionDays > 0) {
            $dateLimit = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            Db::getInstance()->delete('mgomnibus_price_history', 'captured_at < "' . pSQL($dateLimit) . '"');
        }
    }
}