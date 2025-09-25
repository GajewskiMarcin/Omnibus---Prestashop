<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

// MODIFICATION: Ensure the required class is available before proceeding.
if (file_exists(__DIR__ . '/classes/PriceLogger.php')) {
    require_once __DIR__ . '/classes/PriceLogger.php';
}

class MgOmnibus extends Module
{
    public $ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    
    // MODIFICATION: Static caches to improve performance by reducing redundant database queries and config lookups.
    private static $config_cache = [];
    private static $price_history_cache = [];

    public function __construct()
    {
        $this->name = 'mgomnibus';
        $this->tab = 'front_office_features';
        $this->version = '5.1.0'; // MODIFICATION: Version bump after applying fixes.
        $this->author = 'marcingajewski.pl';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('MG Omnibus - Lowest Price');
        $this->description = $this->l('Displays the lowest price of a product from the last 30 days according to the Omnibus Directive.');
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHooks() || !$this->installDb() || !$this->installTab()) {
            return false;
        }
        $this->setDefaultConfiguration();
        return true;
    }

    public function uninstall()
    {
        $configKeys = [
            'MGOMNIBUS_DAYS', 'MGOMNIBUS_MODE', 'MGOMNIBUS_PRICE_KIND',
            'MGOMNIBUS_SHOW_PERCENT', 'MGOMNIBUS_PRECISION', 'MGOMNIBUS_RETENTION',
            'MGOMNIBUS_CRON_TOKEN', 'MGOMNIBUS_CUSTOM_CSS', 'MGOMNIBUS_LOG_ALL_COUNTRIES',
            'MGOMNIBUS_LOG_ALL_GROUPS', 'MGOMNIBUS_PRODUCT_LABEL', 'MGOMNIBUS_LISTING_LABEL',
            'MGOMNIBUS_LOGGING_MODE'
        ];

        foreach ($configKeys as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall() && $this->uninstallDb() && $this->uninstallTab();
    }
    
    private function registerHooks()
    {
        return
            $this->registerHook('actionObjectProductUpdateAfter') &&
            $this->registerHook('actionObjectSpecificPriceAddAfter') &&
            $this->registerHook('actionObjectSpecificPriceUpdateAfter') &&
            $this->registerHook('actionObjectSpecificPriceDeleteAfter') &&
            $this->registerHook('displayProductPriceBlock') &&
            $this->registerHook('displayMgOmnibusOnProduct') &&
            $this->registerHook('displayMgOmnibusOnListing') &&
            $this->registerHook('displayHeader');
    }

    private function installDb()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "mgomnibus_price_history` ( `id_history` INT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL, `id_product` INT UNSIGNED NOT NULL, `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0, `id_currency` INT UNSIGNED NOT NULL, `id_country` INT UNSIGNED NOT NULL, `id_group` INT UNSIGNED NOT NULL, `price_tax_excl` DECIMAL(20,6) NOT NULL, `price_tax_incl` DECIMAL(20,6) NOT NULL, `captured_at` DATETIME NOT NULL, PRIMARY KEY (`id_history`), KEY `idx_lookup` (`id_shop`,`id_product`,`id_product_attribute`,`id_currency`,`id_country`,`id_group`,`captured_at`), KEY `idx_product` (`id_product`,`id_product_attribute`,`captured_at`) ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8mb4;";
        return Db::getInstance()->execute($sql);
    }
    
    private function uninstallDb() { return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mgomnibus_price_history`'); }

    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminMgOmnibusLogs';
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Omnibus Price Logs';
        }
        $tab->id_parent = (int)Tab::getIdFromClassName('DEFAULT');
        $tab->module = $this->name;
        return $tab->add();
    }
    
    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminMgOmnibusLogs');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    private function setDefaultConfiguration()
    {
        Configuration::updateValue('MGOMNIBUS_LOGGING_MODE', 'instant');
        Configuration::updateValue('MGOMNIBUS_DAYS', 30);
        Configuration::updateValue('MGOMNIBUS_MODE', 'only_discount');
        Configuration::updateValue('MGOMNIBUS_PRICE_KIND', 'gross');
        Configuration::updateValue('MGOMNIBUS_SHOW_PERCENT', true);
        Configuration::updateValue('MGOMNIBUS_PRECISION', 2);
        Configuration::updateValue('MGOMNIBUS_RETENTION', 365);
        Configuration::updateValue('MGOMNIBUS_CRON_TOKEN', Tools::passwdGen(32));
        $default_css = ".mgomnibus {\n    font-size: 0.9em;\n    color: #7a7a7a;\n    clear: both;\n    margin-top: 5px;\n}\n.mgomnibus--listing {\n    font-size: 0.8em;\n}\n.mgomnibus__percent {\n    display: inline-block;\n    background-color: #f13340;\n    color: #fff;\n    padding: 2px 5px;\n    border-radius: 3px;\n    font-weight: bold;\n    margin-left: 5px;\n    font-size: 0.9em;\n}";
        Configuration::updateValue('MGOMNIBUS_CUSTOM_CSS', $default_css, true); // Save as HTML
        Configuration::updateValue('MGOMNIBUS_LOG_ALL_COUNTRIES', true);
        Configuration::updateValue('MGOMNIBUS_LOG_ALL_GROUPS', true);
        
        $product_label = [];
        $listing_label = [];
        foreach (Language::getLanguages(false) as $lang) {
            $product_label[$lang['id_lang']] = ($lang['iso_code'] == 'pl') ? 'Najniższa cena z 30 dni przed obniżką:' : 'Lowest price in 30 days before discount:';
            $listing_label[$lang['id_lang']] = ($lang['iso_code'] == 'pl') ? 'Najniższa cena z 30 dni:' : 'Lowest price in 30 days:';
        }
        Configuration::updateValue('MGOMNIBUS_PRODUCT_LABEL', $product_label, true);
        Configuration::updateValue('MGOMNIBUS_LISTING_LABEL', $listing_label, true);
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submit' . $this->name)) {
            $errors = $this->postProcess();
            if (count($errors) === 0) {
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                $output .= $this->displayError($errors);
            }
        }
        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getGeneralConfigForm(), $this->getAdvancedConfigForm()]);
    }
    
    protected function getGeneralConfigForm()
    {
        return [
            'form' => [
                'legend' => ['title' => $this->l('General Settings'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('Period in days'), 'name' => 'MGOMNIBUS_DAYS', 'desc' => $this->l('Number of days to check for the lowest price (default 30).'), 'class' => 'fixed-width-xs', 'required' => true],
                    ['type' => 'select', 'label' => $this->l('Display mode'), 'name' => 'MGOMNIBUS_MODE', 'options' => ['query' => [['id' => 'always', 'name' => $this->l('Always')], ['id' => 'only_discount', 'name' => $this->l('Only on discount')]], 'id' => 'id', 'name' => 'name'], 'required' => true],
                    ['type' => 'select', 'label' => $this->l('Price type'), 'name' => 'MGOMNIBUS_PRICE_KIND', 'options' => ['query' => [['id' => 'gross', 'name' => $this->l('Gross (tax incl.)')], ['id' => 'net', 'name' => $this->l('Net (tax excl.)')]], 'id' => 'id', 'name' => 'name'], 'required' => true],
                    ['type' => 'switch', 'label' => $this->l('Show percentage change'), 'name' => 'MGOMNIBUS_SHOW_PERCENT', 'is_bool' => true, 'values' => [['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')], ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')]]],
                    ['type' => 'text', 'label' => $this->l('Precision (decimal places)'), 'name' => 'MGOMNIBUS_PRECISION', 'class' => 'fixed-width-xs', 'desc' => $this->l('Used for price comparison and discount percentage display.'), 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Label on product page'), 'name' => 'MGOMNIBUS_PRODUCT_LABEL', 'lang' => true, 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Label on product listing'), 'name' => 'MGOMNIBUS_LISTING_LABEL', 'lang' => true, 'required' => true],
                    ['type' => 'textarea', 'label' => $this->l('Custom CSS'), 'name' => 'MGOMNIBUS_CUSTOM_CSS', 'desc' => $this->l('Enter your custom CSS styles here. They will be added to the page header.'), 'cols' => 60, 'rows' => 10],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];
    }

    protected function getAdvancedConfigForm()
    {
        $cron_url = $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::get('MGOMNIBUS_CRON_TOKEN')]);
        return [
            'form' => [
                'legend' => ['title' => $this->l('Advanced Settings & CRON'), 'icon' => 'icon-server'],
                'input' => [
                    ['type' => 'select', 'label' => $this->l('Logging Mode'), 'name' => 'MGOMNIBUS_LOGGING_MODE', 'options' => ['query' => [['id' => 'instant', 'name' => $this->l('Instant (recommended)')], ['id' => 'cron', 'name' => $this->l('CRON only (for performance)')]], 'id' => 'id', 'name' => 'name'], 'desc' => $this->l('Instant mode logs prices on every product save. CRON mode logs prices only when the CRON task is run.'), 'required' => true],
                    ['type' => 'checkbox', 'label' => $this->l('Price Logging Context'), 'name' => 'MGOMNIBUS_LOG', 'values' => ['query' => [['id' => 'ALL_COUNTRIES', 'name' => $this->l('Log for all countries'), 'val' => 1], ['id' => 'ALL_GROUPS', 'name' => $this->l('Log for all customer groups'), 'val' => 1]], 'id' => 'id', 'name' => 'name'], 'desc' => $this->l('Uncheck to log prices only for the default country/group, which will reduce the database size.')],
                    ['type' => 'text', 'label' => $this->l('Log retention (days)'), 'name' => 'MGOMNIBUS_RETENTION', 'desc' => $this->l('After how many days old price logs should be deleted by CRON.'), 'class' => 'fixed-width-sm', 'required' => true],
                    ['type' => 'text', 'label' => $this->l('CRON Token'), 'name' => 'MGOMNIBUS_CRON_TOKEN', 'desc' => $this->l('Your CRON URL: ') . '<br><code>' . $cron_url . '</code><br>' . $this->l('Add &limit=all to process all products at once.'), 'class' => 'fixed-width-xxl', 'required' => true]
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];
    }

    protected function getConfigFormValues()
    {
        $languages = Language::getLanguages(false);
        $fields = [];
        $form_inputs = array_merge($this->getGeneralConfigForm()['form']['input'], $this->getAdvancedConfigForm()['form']['input']);
        
        foreach ($form_inputs as $input) {
            if ($input['type'] === 'checkbox') {
                foreach ($input['values']['query'] as $value) {
                     $fields['MGOMNIBUS_LOG_' . $value['id']] = Configuration::get('MGOMNIBUS_LOG_' . $value['id']);
                }
            } elseif (!empty($input['name']) && $input['type'] !== 'checkbox') {
                 if (isset($input['lang']) && $input['lang']) {
                    foreach ($languages as $lang) {
                        $fields[$input['name']][$lang['id_lang']] = Configuration::get($input['name'], $lang['id_lang']);
                    }
                } else {
                    $fields[$input['name']] = Configuration::get($input['name']);
                }
            }
        }
        return $fields;
    }

    /**
     * MODIFICATION: Added robust validation for all configuration fields to enhance security and stability.
     * @return array List of errors, if any.
     */
    protected function postProcess()
    {
        $errors = [];
        $languages = Language::getLanguages(false);

        // Validate and save general settings
        $days = Tools::getValue('MGOMNIBUS_DAYS');
        if (!Validate::isUnsignedInt($days) || $days == 0) $errors[] = $this->l('Period in days must be a positive integer.');
        else Configuration::updateValue('MGOMNIBUS_DAYS', (int)$days);

        $mode = Tools::getValue('MGOMNIBUS_MODE');
        if (!in_array($mode, ['always', 'only_discount'])) $errors[] = $this->l('Invalid display mode selected.');
        else Configuration::updateValue('MGOMNIBUS_MODE', $mode);

        $price_kind = Tools::getValue('MGOMNIBUS_PRICE_KIND');
        if (!in_array($price_kind, ['gross', 'net'])) $errors[] = $this->l('Invalid price type selected.');
        else Configuration::updateValue('MGOMNIBUS_PRICE_KIND', $price_kind);

        $precision = Tools::getValue('MGOMNIBUS_PRECISION');
        if (!Validate::isUnsignedInt($precision)) $errors[] = $this->l('Precision must be a non-negative integer.');
        else Configuration::updateValue('MGOMNIBUS_PRECISION', (int)$precision);
        
        Configuration::updateValue('MGOMNIBUS_SHOW_PERCENT', (bool)Tools::getValue('MGOMNIBUS_SHOW_PERCENT'));
        
        // Validate and save labels
        $product_labels = [];
        $listing_labels = [];
        foreach ($languages as $lang) {
            $product_labels[$lang['id_lang']] = Tools::getValue('MGOMNIBUS_PRODUCT_LABEL_' . $lang['id_lang']);
            if (empty($product_labels[$lang['id_lang']])) $errors[] = $this->l('Label on product page is required for language:') . ' ' . $lang['name'];

            $listing_labels[$lang['id_lang']] = Tools::getValue('MGOMNIBUS_LISTING_LABEL_' . $lang['id_lang']);
            if (empty($listing_labels[$lang['id_lang']])) $errors[] = $this->l('Label on product listing is required for language:') . ' ' . $lang['name'];
        }
        Configuration::updateValue('MGOMNIBUS_PRODUCT_LABEL', $product_labels, true);
        Configuration::updateValue('MGOMNIBUS_LISTING_LABEL', $listing_labels, true);

        // SECURITY: Sanitize custom CSS to prevent Stored XSS attacks.
        $custom_css = Tools::getValue('MGOMNIBUS_CUSTOM_CSS');
        $sanitized_css = strip_tags($custom_css); // Basic but effective sanitization for CSS
        Configuration::updateValue('MGOMNIBUS_CUSTOM_CSS', $sanitized_css, true);

        // Validate and save advanced settings
        $logging_mode = Tools::getValue('MGOMNIBUS_LOGGING_MODE');
        if (!in_array($logging_mode, ['instant', 'cron'])) $errors[] = $this->l('Invalid logging mode selected.');
        else Configuration::updateValue('MGOMNIBUS_LOGGING_MODE', $logging_mode);
        
        $retention = Tools::getValue('MGOMNIBUS_RETENTION');
        if (!Validate::isUnsignedInt($retention) || $retention == 0) $errors[] = $this->l('Log retention must be a positive integer.');
        else Configuration::updateValue('MGOMNIBUS_RETENTION', (int)$retention);

        $cron_token = Tools::getValue('MGOMNIBUS_CRON_TOKEN');
        if (!Validate::isString($cron_token) || empty($cron_token)) $errors[] = $this->l('CRON Token cannot be empty.');
        else Configuration::updateValue('MGOMNIBUS_CRON_TOKEN', $cron_token);
        
        Configuration::updateValue('MGOMNIBUS_LOG_ALL_COUNTRIES', (bool)Tools::getValue('MGOMNIBUS_LOG_ALL_COUNTRIES'));
        Configuration::updateValue('MGOMNIBUS_LOG_ALL_GROUPS', (bool)Tools::getValue('MGOMNIBUS_LOG_ALL_GROUPS'));
        
        // Clear cache after settings change
        self::$config_cache = [];

        return $errors;
    }
    
    /**
     * MODIFICATION: Helper function to get config values using a static cache for performance.
     */
    private function getConfig($key, $id_lang = null, $id_shop_group = null, $id_shop = null, $default = false)
    {
        $cache_key = $key . '_' . (int)$id_lang . '_' . (int)$id_shop_group . '_' . (int)$id_shop;
        if (!isset(self::$config_cache[$cache_key])) {
            self::$config_cache[$cache_key] = Configuration::get($key, $id_lang, $id_shop_group, $id_shop, $default);
        }
        return self::$config_cache[$cache_key];
    }
    
    private function isInstantLoggingMode()
    {
        return $this->getConfig('MGOMNIBUS_LOGGING_MODE', null, null, null, 'instant') === 'instant';
    }

    public function hookActionObjectProductUpdateAfter($params) { if ($this->isInstantLoggingMode()) { $product = $params['object']; if ($product instanceof Product) { PriceLogger::logProductPrices($product->id); } } }
    public function hookActionObjectSpecificPriceAddAfter($params) { if ($this->isInstantLoggingMode()) { $sp = $params['object']; PriceLogger::logProductPrices($sp->id_product); } }
    public function hookActionObjectSpecificPriceUpdateAfter($params) { if ($this->isInstantLoggingMode()) { $sp = $params['object']; PriceLogger::logProductPrices($sp->id_product); } }
    public function hookActionObjectSpecificPriceDeleteAfter($params) { if ($this->isInstantLoggingMode()) { $sp = $params['object']; PriceLogger::logProductPrices($sp->id_product); } }

    public function hookDisplayHeader() { $custom_css = $this->getConfig('MGOMNIBUS_CUSTOM_CSS'); if (!empty($custom_css)) { return '<style>' . $custom_css . '</style>'; } return ''; }
    public function hookDisplayProductPriceBlock($params) { if ($params['type'] === 'after_price') { return $this->renderOmnibusInfo($params['product'], 'product'); } }
    public function hookDisplayMgOmnibusOnProduct($params)
    {
        if (isset($params['product'])) { $product = $params['product']; if (is_array($product) && isset($product['id_product'])) { return $this->renderOmnibusInfo($product, 'product'); } }
        $controller = $this->context->controller;
        if ($controller instanceof ProductController) { return $this->renderOmnibusInfo($controller->getProduct(), 'product'); }
        return '';
    }
    public function hookDisplayMgOmnibusOnListing($params) { if (isset($params['product'])) { return $this->renderOmnibusInfo($params['product'], 'listing'); } return ''; }
    
    /**
     * MODIFICATION: Refactored main rendering logic with caching to prevent N+1 problem on listings.
     * Also improved code readability and variable handling.
     */
    private function renderOmnibusInfo($productData, $viewType)
    {
        $id_product = 0; $id_product_attribute = 0;
        
        if ($productData instanceof Product) {
            $id_product = (int)$productData->id;
            // SECURITY: Ensure id_product_attribute is properly cast to int
            $id_product_attribute = Tools::getIsset('id_product_attribute') ? (int)Tools::getValue('id_product_attribute') : (int)$productData->getDefaultIdProductAttribute();
        } elseif (is_array($productData) && isset($productData['id_product'])) {
            $id_product = (int)$productData['id_product'];
            $id_product_attribute = (int)($productData['id_product_attribute'] ?? ($productData['default_id_product_attribute'] ?? 0));
        } elseif (is_object($productData) && (isset($productData->id) || isset($productData->id_product))) {
            $id_product = (int)($productData->id_product ?? $productData->id);
            $id_product_attribute = (int)($productData->id_product_attribute ?? 0);
        }
        
        if (!$id_product) return '';

        $context = $this->context;
        $id_shop = (int)$context->shop->id;
        $id_currency = (int)$context->currency->id;
        $id_country = (int)$context->country->id;
        $id_group = $context->customer && $context->customer->id ? (int)$context->customer->id_default_group : (int)Configuration::get('PS_UNIDENTIFIED_GROUP');
        if (!$id_group) $id_group = (int)Configuration::get('PS_GUEST_GROUP');
        
        // PERFORMANCE: Use cached config values
        $price_kind_conf = $this->getConfig('MGOMNIBUS_PRICE_KIND');
        $useTax = ($price_kind_conf == 'gross');
        $precision = (int)$this->getConfig('MGOMNIBUS_PRECISION');
        $days = (int)$this->getConfig('MGOMNIBUS_DAYS', null, null, $id_shop, 30);
        $displayMode = $this->getConfig('MGOMNIBUS_MODE', null, null, $id_shop, 'only_discount');
        
        $currentPrice = Product::getPriceStatic($id_product, $useTax, $id_product_attribute, 6, null, false, true, 1, false, null, null, null, $specificPriceOutput, true, true, $context);

        // PERFORMANCE: Create a unique cache key for the current context
        $cache_key = "{$id_product}-{$id_product_attribute}-{$id_shop}-{$id_currency}-{$id_country}-{$id_group}-{$days}";
        
        if (isset(self::$price_history_cache[$cache_key])) {
            $distinctPrices = self::$price_history_cache[$cache_key];
        } else {
            $dateFrom = (new DateTime())->sub(new DateInterval("P{$days}D"))->format('Y-m-d H:i:s');
            $priceKindField = $useTax ? 'price_tax_incl' : 'price_tax_excl';
    
            $sql = new DbQuery();
            $sql->select('DISTINCT `' . pSQL($priceKindField) . '` as price');
            $sql->from('mgomnibus_price_history');
            $sql->where('`id_product` = ' . (int)$id_product);
            $sql->where('`id_product_attribute` = ' . (int)$id_product_attribute);
            $sql->where('`id_shop` = ' . (int)$id_shop);
            $sql->where('`id_currency` = ' . (int)$id_currency);
            $sql->where('`id_country` = ' . (int)$id_country);
            $sql->where('`id_group` = ' . (int)$id_group);
            $sql->where('`captured_at` >= "' . pSQL($dateFrom) . '"');
            $sql->orderBy('price ASC');
            $sql->limit(2); // Fetch up to two lowest distinct prices
    
            $distinctPrices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
            self::$price_history_cache[$cache_key] = $distinctPrices; // Store result in cache for this request
        }
        
        $absoluteMinPrice = 0;
        $referencePrice = 0;

        if (count($distinctPrices) === 1) {
            $absoluteMinPrice = (float)$distinctPrices[0]['price'];
            $referencePrice = $absoluteMinPrice;
        } elseif (count($distinctPrices) > 1) {
            $lowestPrice = (float)$distinctPrices[0]['price'];
            $secondLowestPrice = (float)$distinctPrices[1]['price'];
            
            // Logic to determine reference price for discount calculation
            if (Tools::ps_round($currentPrice, 6) < Tools::ps_round($lowestPrice, 6)) {
                 $absoluteMinPrice = $currentPrice;
                 $referencePrice = $lowestPrice; // If current price is a new low, reference is the previous lowest
            } else if (Tools::ps_round($currentPrice, 6) == Tools::ps_round($lowestPrice, 6)) {
                $absoluteMinPrice = $lowestPrice;
                $referencePrice = $secondLowestPrice; // If current price equals lowest, reference is the second lowest
            } else {
                $absoluteMinPrice = $lowestPrice;
                $referencePrice = $lowestPrice; // Otherwise, the lowest price is the reference
            }
        }

        $shouldDisplay = false;
        $percentChange = 0;
        if ($referencePrice > 0 && Tools::ps_round($currentPrice, $precision) < Tools::ps_round($referencePrice, $precision)) {
             $percentChange = round((1 - ($currentPrice / $referencePrice)) * 100);
        }

        $priceToDisplay = ($percentChange > 0) ? $referencePrice : $absoluteMinPrice;
        
        if ($priceToDisplay > 0) {
            if ($displayMode === 'always') {
                $shouldDisplay = true;
            } elseif ($displayMode === 'only_discount' && $percentChange > 0) {
                $shouldDisplay = true;
            }
        }
        
        if (!$shouldDisplay) return '';
        
        $this->smarty->assign('mgomnibus', [
            'text' => $this->getConfig('MGOMNIBUS_' . strtoupper($viewType) . '_LABEL', $this->context->language->id),
            'min_price_formatted' => Tools::displayPrice($priceToDisplay, $this->context->currency),
            'has_percent' => $percentChange > 0 && $this->getConfig('MGOMNIBUS_SHOW_PERCENT'),
            'percent_text' => '-' . $percentChange . '%',
            'view_type' => $viewType,
        ]);
        
        $template = $viewType === 'listing' ? 'listing.tpl' : 'product.tpl';
        return $this->fetch('module:mgomnibus/views/templates/hook/' . $template);
    }
}