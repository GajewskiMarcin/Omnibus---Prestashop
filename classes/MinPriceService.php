<?php
if (!defined('_PS_VERSION_')) { exit; }

class MinPriceService
{
    public static function getKey(Context $context, $id_product, $ipa)
    {
        $id_shop = (int)$context->shop->id;
        $id_currency = isset($context->currency) && is_object($context->currency) ? (int)$context->currency->id : (int)Configuration::get('PS_CURRENCY_DEFAULT');
        $id_country = isset($context->country) && is_object($context->country) ? (int)$context->country->id : (int)Configuration::get('PS_COUNTRY_DEFAULT');
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

    public static function getMinForWindow(Context $context, $id_product, $ipa, $days, $priceKind)
    {
        $k = self::getKey($context, $id_product, $ipa);
        $col = ($priceKind === 'net') ? 'price_tax_excl' : 'price_tax_incl';
        $sql = new DbQuery();
        $sql->select('MIN('.pSQL($col).') AS min_price');
        $sql->from('mgomnibus_price_history');
        $sql->where('id_shop='.(int)$k['id_shop']);
        $sql->where('id_product='.(int)$k['id_product']);
        $sql->where('id_product_attribute='.(int)$k['id_product_attribute']);
        $sql->where('id_currency='.(int)$k['id_currency']);
        $sql->where('id_country='.(int)$k['id_country']);
        $sql->where('id_group='.(int)$k['id_group']);
        $sql->where('captured_at >= DATE_SUB(NOW(), INTERVAL '.(int)$days.' DAY)');
        $val = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
        if ($val === false || $val === null) {
            // FALLBACK_GROUP: try unidentified group (visitor) if context group had no data
            $fallbackGroup = (int)Configuration::get('PS_UNIDENTIFIED_GROUP');
            if ($fallbackGroup && $fallbackGroup != (int)$k['id_group']) {
                $sql2 = new DbQuery();
                $sql2->select('MIN('.pSQL($col).') AS min_price');
                $sql2->from('mgomnibus_price_history');
                $sql2->where('id_shop='.(int)$k['id_shop']);
                $sql2->where('id_product='.(int)$k['id_product']);
                $sql2->where('id_product_attribute='.(int)$k['id_product_attribute']);
                $sql2->where('id_currency='.(int)$k['id_currency']);
                $sql2->where('id_country='.(int)$k['id_country']);
                $sql2->where('id_group='.(int)$fallbackGroup);
                $sql2->where('captured_at >= DATE_SUB(NOW(), INTERVAL '.(int)$days.' DAY)');
                $val2 = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql2);
                if ($val2 !== false && $val2 !== null) { return (float)$val2; }
            }
            return null;
        }
        return (float)$val;
    }
}
