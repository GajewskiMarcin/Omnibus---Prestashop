<?php
if (!defined('_PS_VERSION_')) { exit; }

class PriceLogger
{
    protected static function pricePair($id_product, $ipa, Context $context)
    {
        $spo = null;
        $gross = Product::getPriceStatic((int)$id_product, true, (int)$ipa, 6, null, false, true, 1, false, null, null, null, $spo, true, true, $context);
        $net = Product::getPriceStatic((int)$id_product, false, (int)$ipa, 6, null, false, true, 1, false, null, null, null, $spo, true, true, $context);
        return [$net, $gross];
    }

    protected static function key(Context $context, $id_product, $ipa)
    {
        $id_shop = (int)$context->shop->id;
        $id_currency = (isset($context->currency) && is_object($context->currency)) ? (int)$context->currency->id : (int)Configuration::get('PS_CURRENCY_DEFAULT');
        $id_country = (isset($context->country) && is_object($context->country)) ? (int)$context->country->id : (int)Configuration::get('PS_COUNTRY_DEFAULT');
        $id_group = (isset($context->customer) && is_object($context->customer) && (int)$context->customer->id_default_group) ? (int)$context->customer->id_default_group : (int)Configuration::get('PS_UNIDENTIFIED_GROUP');
        return [
            'id_shop' => $id_shop,
            'id_product' => (int)$id_product,
            'id_product_attribute' => (int)$ipa,
            'id_currency' => $id_currency,
            'id_country' => $id_country,
            'id_group' => $id_group,
        ];
    }

    protected static function existsSameWithin(Context $context, $id_product, $ipa, $net, $gross, $hours=24)
    {
        $k = self::key($context, $id_product, $ipa);
        $sql = new DbQuery();
        $sql->select('COUNT(1)');
        $sql->from('mgomnibus_price_history');
        foreach ($k as $field=>$v) { $sql->where(pSQL($field).'='.(int)$v); }
        $sql->where('price_tax_excl = '.(float)$net);
        $sql->where('price_tax_incl = '.(float)$gross);
        $sql->where('captured_at >= DATE_SUB(NOW(), INTERVAL '.(int)$hours.' HOUR)');
        return (bool)Db::getInstance()->getValue($sql);
    }

    protected static function insert(Context $context, $id_product, $ipa, $net, $gross)
    {
        $k = self::key($context, $id_product, $ipa);
        $row = array_merge($k, [
            'price_tax_excl' => (float)$net,
            'price_tax_incl' => (float)$gross,
            'captured_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::getInstance()->insert('mgomnibus_price_history', $row);
    }

    public static function snapshotProduct(Context $context, $id_product, $ipa=0, $debounceHours=24)
    {
        list($net, $gross) = self::pricePair($id_product, $ipa, $context);
        if (!self::existsSameWithin($context, $id_product, $ipa, $net, $gross, $debounceHours)) {
            self::insert($context, $id_product, $ipa, $net, $gross);
        }
    }

    public static function snapshotSomeProducts(Context $context, $limit = 50)
    {
        $sql = new DbQuery();
        $sql->select('p.id_product');
        $sql->from('product', 'p');
        $sql->where('p.active = 1');
        $sql->orderBy('p.id_product ASC');
        $sqlLimit = (int)$limit;
        if ($sqlLimit > 0) { $sql->limit($sqlLimit); }
        $ids = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        if (!$ids) return;
        foreach ($ids as $row) {
            self::snapshotProduct($context, (int)$row['id_product'], 0);
        }
    }

    public static function cleanupRetention($days)
    {
        $days = (int)$days;
        if ($days <= 0) return;
        Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'mgomnibus_price_history WHERE captured_at < DATE_SUB(NOW(), INTERVAL '.(int)$days.' DAY)');
    }
}
