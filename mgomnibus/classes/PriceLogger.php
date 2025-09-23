<?php

class PriceLogger
{
    public static function logProductPrices($id_product)
    {
        $product = new Product($id_product);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $combinations = $product->getAttributesResume(Context::getContext()->language->id);
        $attributes = [0]; // Always include base product
        if ($combinations) {
            foreach ($combinations as $combination) {
                $attributes[] = (int)$combination['id_product_attribute'];
            }
        }
        $attributes = array_unique($attributes);
        
        $shops = Shop::getShops(true, null, true);
        $currencies = Currency::getCurrencies(false, true);

        // --- NEW LOGIC: Check configuration for context ---
        $logAllCountries = (bool)Configuration::get('MGOMNIBUS_LOG_ALL_COUNTRIES');
        $logAllGroups = (bool)Configuration::get('MGOMNIBUS_LOG_ALL_GROUPS');

        $countries = $logAllCountries 
            ? Country::getCountries(Context::getContext()->language->id, true)
            : [['id_country' => (int)Configuration::get('PS_COUNTRY_DEFAULT')]]; // <-- CORRECTED LINE

        $groups = $logAllGroups
            ? Group::getGroups(Context::getContext()->language->id)
            : [['id_group' => Configuration::get('PS_GUEST_GROUP')], ['id_group' => Configuration::get('PS_UNIDENTIFIED_GROUP')]];
        // --- END NEW LOGIC ---

        foreach ($shops as $id_shop) {
            foreach ($attributes as $id_product_attribute) {
                foreach ($currencies as $currency) {
                    foreach ($countries as $country) {
                        foreach ($groups as $group) {
                            $context = self::buildContext($id_shop, $currency['id_currency'], $country['id_country'], $group['id_group']);
                            
                            $price_tax_incl = Product::getPriceStatic($id_product, true, $id_product_attribute, 6, null, false, true, 1, false, null, null, null, $specificPriceOutput, true, true, $context);
                            $price_tax_excl = Product::getPriceStatic($id_product, false, $id_product_attribute, 6, null, false, true, 1, false, null, null, null, $specificPriceOutput, true, true, $context);
                            
                            if ($price_tax_incl > 0 || $price_tax_excl > 0) {
                                self::savePrice($id_shop, $id_product, $id_product_attribute, $currency['id_currency'], $country['id_country'], $group['id_group'], $price_tax_excl, $price_tax_incl);
                            }
                        }
                    }
                }
            }
        }
    }

    private static function savePrice($id_shop, $id_product, $id_product_attribute, $id_currency, $id_country, $id_group, $price_tax_excl, $price_tax_incl)
    {
        $sql = new DbQuery();
        $sql->select('id_history');
        $sql->from('mgomnibus_price_history');
        $sql->where('id_shop = ' . (int)$id_shop);
        $sql->where('id_product = ' . (int)$id_product);
        $sql->where('id_product_attribute = ' . (int)$id_product_attribute);
        $sql->where('id_currency = ' . (int)$id_currency);
        $sql->where('id_country = ' . (int)$id_country);
        $sql->where('id_group = ' . (int)$id_group);
        $sql->where('ABS(price_tax_incl - ' . (float)$price_tax_incl . ') < 0.000001');
        $sql->where('captured_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');

        if (Db::getInstance()->getValue($sql)) {
            return;
        }
        
        Db::getInstance()->insert('mgomnibus_price_history', [
            'id_shop' => (int)$id_shop,
            'id_product' => (int)$id_product,
            'id_product_attribute' => (int)$id_product_attribute,
            'id_currency' => (int)$id_currency,
            'id_country' => (int)$id_country,
            'id_group' => (int)$id_group,
            'price_tax_excl' => (float)$price_tax_excl,
            'price_tax_incl' => (float)$price_tax_incl,
            'captured_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    private static function buildContext($id_shop, $id_currency, $id_country, $id_group)
    {
        $context = clone Context::getContext();
        $context->shop = new Shop($id_shop);
        $context->currency = new Currency($id_currency);
        $context->country = new Country($id_country);
        $customer = new Customer();
        $customer->id_default_group = $id_group;
        $context->customer = $customer;
        return $context;
    }
}