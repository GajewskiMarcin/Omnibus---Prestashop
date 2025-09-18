<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class MgOmnibus extends Module
{
    const CFG_SHOW_MODE = 'MGOMNI_BUS_SHOW_MODE'; // always|only_discount
    const CFG_PRICE_KIND = 'MGOMNI_BUS_PRICE_KIND'; // gross|net
    const CFG_DAYS_WINDOW = 'MGOMNI_BUS_DAYS_WINDOW'; // int
    const CFG_LABEL_PRODUCT = 'MGOMNI_BUS_LABEL_PRODUCT'; // per lang/shop
    const CFG_LABEL_LISTING = 'MGOMNI_BUS_LABEL_LISTING'; // per lang/shop
    const CFG_RETENTION = 'MGOMNI_BUS_RETENTION_DAYS'; // int
    const CFG_CRON_TOKEN = 'MGOMNI_BUS_CRON_TOKEN'; // str

    public function __construct()
    {
        $this->name = 'mgomnibus';
        $this->tab = 'pricing_promotion';
        $this->version = '1.0.5';
        $this->author = 'MG';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('MG Omnibus – najniższa cena 30 dni');
        $this->description = $this->l('Rejestruj ceny i pokazuj najniższą cenę z ostatnich dni (dyrektywa Omnibus).');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // SQL
        if (!$this->installSql()) {
            return false;
        }

        // Default config
        Configuration::updateValue(self::CFG_SHOW_MODE, 'only_discount');
        Configuration::updateValue(self::CFG_PRICE_KIND, 'gross');
        Configuration::updateValue(self::CFG_DAYS_WINDOW, 30);
        Configuration::updateValue(self::CFG_RETENTION, 120);
        Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(32));

        // Lang labels default
        $this->installDefaultLabels();

        // Hooks
        $hooks = [
            'displayMgOmnibusOnProduct',
            'displayMgOmnibusOnListing',
            // Auto: standard Presta hook after price
            'displayProductPriceBlock',
            'actionObjectUpdateAfter',
            'actionSpecificPriceAdd',
            'actionSpecificPriceUpdate',
            'actionSpecificPriceDelete',
        ];
        foreach ($hooks as $h) {
            if (!$this->registerHook($h)) {
                return false;
            }
        }
        return true;
    }

    public function uninstall()
    {
        $this->uninstallSql();
        return parent::uninstall();
    }

    protected function installSql()
    {
        $sql = file_get_contents(__DIR__ . '/sql/install.sql');
         $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql);  $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql); return Db::getInstance()->execute($sql);
    }

    protected function uninstallSql()
    {
        $sql = file_get_contents(__DIR__ . '/sql/uninstall.sql');
         $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql); return Db::getInstance()->execute($sql);
    }

    protected function installDefaultLabels()
    {
        foreach (Shop::getShops(false) as $shop) {
            $id_shop = (int)$shop['id_shop'];
            $valuesP = [];
            $valuesL = [];
            foreach (Language::getLanguages(false) as $lang) {
                $id_lang = (int)$lang['id_lang'];
                $valuesP[$id_lang] = 'Najniższa cena z ostatnich {days} dni: {price}';
                $valuesL[$id_lang] = 'Najniższa cena w ostatnich {days} dniach: {price}';
            }
            Configuration::updateValue(self::CFG_LABEL_PRODUCT, $valuesP, false, null, $id_shop);
            Configuration::updateValue(self::CFG_LABEL_LISTING, $valuesL, false, null, $id_shop);
        }
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('regenCronToken')) {
            Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(32));
        }
        if (Tools::isSubmit('submitMgOmnibus')) {
            $show_mode = Tools::getValue(self::CFG_SHOW_MODE, 'only_discount');
            $price_kind = Tools::getValue(self::CFG_PRICE_KIND, 'gross');
            $days = (int)Tools::getValue(self::CFG_DAYS_WINDOW, 30);
            $ret = (int)Tools::getValue(self::CFG_RETENTION, 120);
            Configuration::updateValue(self::CFG_SHOW_MODE, $show_mode);
            Configuration::updateValue(self::CFG_PRICE_KIND, $price_kind);
            Configuration::updateValue(self::CFG_DAYS_WINDOW, $days);
            Configuration::updateValue(self::CFG_RETENTION, $ret);

            // Labels per lang (per shop)
            foreach (Shop::getShops(false) as $shop) {
                $id_shop = (int)$shop['id_shop'];
                $valsP = [];
                $valsL = [];
                foreach (Language::getLanguages(false) as $lang) {
                    $id_lang = (int)$lang['id_lang'];
                    $lp = Tools::getValue(self::CFG_LABEL_PRODUCT.'_'.$id_lang, '');
                    $ll = Tools::getValue(self::CFG_LABEL_LISTING.'_'.$id_lang, '');
                    if ($lp) $valsP[$id_lang] = $lp; 
                    if ($ll) $valsL[$id_lang] = $ll; 
                }
                if ($valsP) Configuration::updateValue(self::CFG_LABEL_PRODUCT, $valsP, false, null, $id_shop);
                if ($valsL) Configuration::updateValue(self::CFG_LABEL_LISTING, $valsL, false, null, $id_shop);
            }
            $output .= $this->displayConfirmation($this->l('Ustawienia zapisane.'));
        }
        // form
        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('Ustawienia Omnibus')],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Kiedy wyświetlać'),
                        'name' => self::CFG_SHOW_MODE,
                        'options' => [
                            'id' => 'id',
                            'name' => 'name',
                            'query' => [
                                ['id' => 'always', 'name' => $this->l('Zawsze')],
                                ['id' => 'only_discount', 'name' => $this->l('Tylko przy zniżce')],
                            ]
                        ]
                    ],
                    [
                        'type' => 'radio',
                        'label' => $this->l('Rodzaj ceny'),
                        'name' => self::CFG_PRICE_KIND,
                        'values' => [
                            ['id' => 'gross', 'value' => 'gross', 'label' => $this->l('Brutto (z VAT)')],
                            ['id' => 'net', 'value' => 'net', 'label' => $this->l('Netto (bez VAT)')],
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Okno dni'),
                        'name' => self::CFG_DAYS_WINDOW,
                        'suffix' => $this->l('dni'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Retencja'),
                        'name' => self::CFG_RETENTION,
                        'suffix' => $this->l('dni'),
                        'desc' => $this->l('Starsze wpisy będą czyszczone.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Zapisz')]
            ]
        ];

        // Labels per language
        foreach (Language::getLanguages(false) as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $fields_form['form']['input'][] = [
                'type' => 'text',
                'name' => self::CFG_LABEL_PRODUCT.'_'.$id_lang,
                'label' => $this->l('Etykieta – Produkt').' ('.$lang['iso_code'].')',
                'desc' => $this->l('Użyj {price} i {days}')
            ];
            $fields_form['form']['input'][] = [
                'type' => 'text',
                'name' => self::CFG_LABEL_LISTING.'_'.$id_lang,
                'label' => $this->l('Etykieta – Listing').' ('.$lang['iso_code'].')',
                'desc' => $this->l('Użyj {price} i {days}')
            ];
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitMgOmnibus';

        $fields_value = [
            self::CFG_SHOW_MODE => Configuration::get(self::CFG_SHOW_MODE),
            self::CFG_PRICE_KIND => Configuration::get(self::CFG_PRICE_KIND),
            self::CFG_DAYS_WINDOW => (int)Configuration::get(self::CFG_DAYS_WINDOW),
            self::CFG_RETENTION => (int)Configuration::get(self::CFG_RETENTION),
        ];
        foreach (Language::getLanguages(false) as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $fields_value[self::CFG_LABEL_PRODUCT.'_'.$id_lang] = Configuration::get(self::CFG_LABEL_PRODUCT, $id_lang);
            $fields_value[self::CFG_LABEL_LISTING.'_'.$id_lang] = Configuration::get(self::CFG_LABEL_LISTING, $id_lang);
        }
        $helper->fields_value = $fields_value;

        return $output.$helper->generateForm([$fields_form]).$this->renderCronInfo();
    }

    protected function renderCronInfo()
    {
        $token = Configuration::get(self::CFG_CRON_TOKEN);
        $link = $this->context->link->getModuleLink($this->name, 'cron', ['token' => $token], true);
        $html = '<div class="panel">';
        $html .= '<h3>'.$this->l('CRON – dzienny snapshot').' </h3>';
        $html .= '<p>'.$this->l('Wywołaj raz dziennie:').' <code>'.Tools::safeOutput($link).'</code></p>';
        $html .= '<form method="post"><button class="btn btn-default" name="regenCronToken" value="1">'.$this->l('Wygeneruj nowy token').'</button></form>';
        $html .= '</div>';
        return $html;
    }

    /* ===== Hooks ===== */

    public function hookDisplayMgOmnibusOnProduct($params)
    {
        $id_product = null;
        $ipa = 0;
        if (!empty($params['product']) && is_object($params['product'])) {
            $id_product = (int)$params['product']->id;
        } elseif (!empty($params['product']) && is_array($params['product']) && isset($params['product']['id_product'])) {
            $id_product = (int)$params['product']['id_product'];
            $ipa = isset($params['product']['id_product_attribute']) ? (int)$params['product']['id_product_attribute'] : 0;
        } elseif (isset($this->context->controller->product)) {
            $id_product = (int)$this->context->controller->product->id;
        }
        if (!$id_product) return '';

        return $this->renderForProduct($id_product, $ipa, 'product');
    }

    public function hookDisplayMgOmnibusOnListing($params)
    {
        if (empty($params['product'])) return '';
        $prod = $params['product'];
        $id_product = is_array($prod) ? (int)$prod['id_product'] : (int)$prod->id;
        $ipa = is_array($prod) && isset($prod['id_product_attribute']) ? (int)$prod['id_product_attribute'] : 0;
        return $this->renderForProduct($id_product, $ipa, 'listing');
    }

    // Optional auto hook after price (disabled by default)
    public function hookDisplayProductPriceBlock($params)
    {
        if (!isset($params['type']) || $params['type'] !== 'after_price') return '';
        if (isset($params['product'])) {
            $prod = $params['product'];
            $id_product = is_array($prod) ? (int)$prod['id_product'] : (int)$prod->id;
            $ipa = is_array($prod) && isset($prod['id_product_attribute']) ? (int)$prod['id_product_attribute'] : 0;
            return $this->renderForProduct($id_product, $ipa, 'product');
        }
        return '';
    }

    protected function renderForProduct($id_product, $ipa, $contextKey)
    {
        require_once __DIR__.'/classes/MinPriceService.php';
        $days = (int)Configuration::get(self::CFG_DAYS_WINDOW);
        $showMode = Configuration::get(self::CFG_SHOW_MODE);
        $priceKind = Configuration::get(self::CFG_PRICE_KIND);

        $min = MinPriceService::getMinForWindow($this->context, (int)$id_product, (int)$ipa, $days, $priceKind);
        $useTax = ($priceKind === 'gross');
        $spo = null;
        $current = Product::getPriceStatic((int)$id_product, $useTax, (int)$ipa, 6, null, false, true, 1, false, null, null, null, $spo, true, true, $this->context);
        if ($min === null) {
            $showMode = Configuration::get(self::CFG_SHOW_MODE);
            if ($showMode === 'always') {
                $min = (float)$current; // fallback to current
            } else {
                return '';
            }
        }

        // current price in the same mode
        $useTax = ($priceKind === 'gross');
        $spo = null;
        $current = Product::getPriceStatic((int)$id_product, $useTax, (int)$ipa, 6, null, false, true, 1, false, null, null, null, $spo, true, true, $this->context);
        if ($showMode === 'only_discount' && !($current < $min - 1e-6)) {
            return '';
        }

        $labelKey = ($contextKey === 'product') ? self::CFG_LABEL_PRODUCT : self::CFG_LABEL_LISTING;
        $tplText = Configuration::get($labelKey, (int)$this->context->language->id, null, (int)$this->context->shop->id);
        if (!$tplText) $tplText = ($contextKey === 'product') ? 'Najniższa cena z ostatnich {days} dni: {price}' : 'Najniższa cena w ostatnich {days} dniach: {price}';

        $text = strtr($tplText, [
            '{price}' => Tools::displayPrice($min, $this->context->currency),
            '{days}' => (string)$days,
        ]);

        $this->context->smarty->assign([
            'mgomnibus' => [
                'text' => $text,
                'min_price' => $min,
                'min_price_formatted' => Tools::displayPrice($min, $this->context->currency),
                'days' => $days,
                'kind' => $priceKind,
            ]
        ]);

        $tpl = ($contextKey === 'product') ? 'product.tpl' : 'listing.tpl';
        return $this->display(__FILE__, 'views/templates/hook/'.$tpl);
    }

    /* ==== Logging triggers ==== */

    public function hookActionObjectUpdateAfter($params)
    {
        if (!isset($params['object'])) return;
        require_once __DIR__.'/classes/PriceLogger.php';
        $obj = $params['object'];
        $cls = get_class($obj);
        if ($cls === 'Product') {
            PriceLogger::snapshotProduct($this->context, (int)$obj->id, 0);
        } elseif ($cls === 'SpecificPrice') {
            if ((int)$obj->id_product) PriceLogger::snapshotProduct($this->context, (int)$obj->id_product, 0);
            else $this->logAllVisibleIfNeeded();
        } else {
            $this->logAllVisibleIfNeeded();
        }
    }
    public function hookActionSpecificPriceAdd($params) { $this->snapshotFromSpecificPrice($params); }
    public function hookActionSpecificPriceUpdate($params) { $this->snapshotFromSpecificPrice($params); }
    public function hookActionSpecificPriceDelete($params) { $this->snapshotFromSpecificPrice($params); }

    protected function snapshotFromSpecificPrice($params)
    {
        require_once __DIR__.'/classes/PriceLogger.php';
        if (isset($params['specificPrice']) && is_object($params['specificPrice']) && (int)$params['specificPrice']->id_product) {
            PriceLogger::snapshotProduct($this->context, (int)$params['specificPrice']->id_product, 0);
        } elseif (isset($params['id_product'])) {
            PriceLogger::snapshotProduct($this->context, (int)$params['id_product'], 0);
        } else {
            $this->logAllVisibleIfNeeded();
        }
    }

    protected function logAllVisibleIfNeeded()
    {
        require_once __DIR__.'/classes/PriceLogger.php';
        PriceLogger::snapshotSomeProducts($this->context, 50); // light touch
    }
}
