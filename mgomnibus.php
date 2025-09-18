<?php
if (!defined('_PS_VERSION_')) { exit; }

class MgOmnibus extends Module
{
    const CFG_SHOW_MODE = 'MGOMNI_BUS_SHOW_MODE'; // always|only_discount
    const CFG_PRICE_KIND = 'MGOMNI_BUS_PRICE_KIND'; // gross|net
    const CFG_DAYS_WINDOW = 'MGOMNI_BUS_DAYS_WINDOW'; // int
    const CFG_LABEL_PRODUCT = 'MGOMNI_BUS_LABEL_PRODUCT'; // multilanguage
    const CFG_LABEL_LISTING = 'MGOMNI_BUS_LABEL_LISTING'; // multilanguage
    const CFG_RETENTION = 'MGOMNI_BUS_RETENTION_DAYS'; // int
    const CFG_CRON_TOKEN = 'MGOMNI_BUS_CRON_TOKEN'; // str

    public function __construct()
    {
        $this->name = 'mgomnibus';
        $this->tab = 'pricing_promotion';
        $this->version = '1.0.6';
        $this->author = 'MG';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('MG Omnibus – najniższa cena 30 dni');
        $this->description = $this->l('Rejestruje ceny i pokazuje najniższą cenę z ostatnich dni (dyrektywa Omnibus).');
    }

    public function install()
    {
        if (!parent::install()) return false;
        if (!$this->installSql()) return false;

        Configuration::updateValue(self::CFG_SHOW_MODE, 'only_discount');
        Configuration::updateValue(self::CFG_PRICE_KIND, 'gross');
        Configuration::updateValue(self::CFG_DAYS_WINDOW, 30);
        Configuration::updateValue(self::CFG_RETENTION, 120);
        Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(32));

        $this->installDefaultLabels();

        $hooks = [
            'displayMgOmnibusOnProduct',
            'displayMgOmnibusOnListing',
            'displayProductPriceBlock', // możesz odczepić w Pozycjach
            'actionObjectUpdateAfter',
            'actionSpecificPriceAdd',
            'actionSpecificPriceUpdate',
            'actionSpecificPriceDelete',
        ];
        foreach ($hooks as $h) if (!$this->registerHook($h)) return false;

        return true;
    }

    public function uninstall()
    {
        $this->uninstallSql();
        return parent::uninstall();
    }

    protected function isCeAdminRequest()
    {
        if (!defined('_PS_ADMIN_DIR_')) { return false; }
        $c = Tools::getValue('controller');
        $route = Tools::getValue('route');
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $needle = function($s){ return $s && (stripos($s, 'creative') !== false || stripos($s, 'element') !== false || stripos($s, 'ce-') !== false); };
        return $needle($c) || $needle($route) || $needle($uri);
    }

    protected function installSql()
    {
        $sql = file_get_contents(__DIR__.'/sql/install.sql');
        $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql);
        return Db::getInstance()->execute($sql);
    }
    protected function uninstallSql()
    {
        $sql = file_get_contents(__DIR__.'/sql/uninstall.sql');
        $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql);
        return Db::getInstance()->execute($sql);
    }

    protected function installDefaultLabels()
    {
        foreach (Shop::getShops(false) as $shop) {
            $id_shop = (int)$shop['id_shop'];
            $valsP = [];
            $valsL = [];
            foreach (Language::getLanguages(false) as $lang) {
                $id_lang = (int)$lang['id_lang'];
                $valsP[$id_lang] = 'Najniższa cena z ostatnich {days} dni: {price}';
                $valsL[$id_lang] = 'Najniższa cena w ostatnich {days} dniach: {price}';
            }
            Configuration::updateValue(self::CFG_LABEL_PRODUCT, $valsP, false, null, $id_shop);
            Configuration::updateValue(self::CFG_LABEL_LISTING, $valsL, false, null, $id_shop);
        }
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('regenCronToken')) {
            Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(32));
        }
        if (Tools::isSubmit('submitMgOmnibus')) {
            Configuration::updateValue(self::CFG_SHOW_MODE, Tools::getValue(self::CFG_SHOW_MODE, 'only_discount'));
            Configuration::updateValue(self::CFG_PRICE_KIND, Tools::getValue(self::CFG_PRICE_KIND, 'gross'));
            Configuration::updateValue(self::CFG_DAYS_WINDOW, (int)Tools::getValue(self::CFG_DAYS_WINDOW, 30));
            Configuration::updateValue(self::CFG_RETENTION, (int)Tools::getValue(self::CFG_RETENTION, 120));

            foreach (Shop::getShops(false) as $shop) {
                $id_shop = (int)$shop['id_shop'];
                $valsP = []; $valsL = [];
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
            $out .= $this->displayConfirmation($this->l('Ustawienia zapisane.'));
        }

        $fields = [
            'form' => [
                'legend' => ['title' => $this->l('Ustawienia Omnibus')],
                'input' => [
                    [
                        'type' => 'select',
                        'name' => self::CFG_SHOW_MODE,
                        'label' => $this->l('Kiedy wyświetlać'),
                        'options' => [
                            'query' => [
                                ['id' => 'always', 'name' => $this->l('Zawsze')],
                                ['id' => 'only_discount', 'name' => $this->l('Tylko przy zniżce')],
                            ],
                            'id' => 'id', 'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'radio',
                        'name' => self::CFG_PRICE_KIND,
                        'label' => $this->l('Rodzaj ceny'),
                        'values' => [
                            ['id' => 'gross', 'value' => 'gross', 'label' => $this->l('Brutto (z VAT)')],
                            ['id' => 'net', 'value' => 'net', 'label' => $this->l('Netto (bez VAT)')],
                        ]
                    ],
                    [
                        'type' => 'text',
                        'name' => self::CFG_DAYS_WINDOW,
                        'label' => $this->l('Okno dni'),
                        'suffix' => $this->l('dni'),
                    ],
                    [
                        'type' => 'text',
                        'name' => self::CFG_RETENTION,
                        'label' => $this->l('Retencja'),
                        'suffix' => $this->l('dni'),
                    ],
                ],
                'submit' => ['title' => $this->l('Zapisz')]
            ]
        ];
        foreach (Language::getLanguages(false) as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $fields['form']['input'][] = [
                'type' => 'text',
                'name' => self::CFG_LABEL_PRODUCT.'_'.$id_lang,
                'label' => $this->l('Etykieta – Produkt').' ('.$lang['iso_code'].')',
                'desc' => $this->l('Użyj {price} i {days}')
            ];
            $fields['form']['input'][] = [
                'type' => 'text',
                'name' => self::CFG_LABEL_LISTING.'_'.$id_lang,
                'label' => $this->l('Etykieta – Listing').' ('.$lang['iso_code'].')',
                'desc' => $this->l('Użyj {price} i {days}')
            ];
        }

        $h = new HelperForm();
        $h->module = $this;
        $h->name_controller = $this->name;
        $h->token = Tools::getAdminTokenLite('AdminModules');
        $h->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $h->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $h->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $h->title = $this->displayName;
        $h->submit_action = 'submitMgOmnibus';

        $values = [
            self::CFG_SHOW_MODE => Configuration::get(self::CFG_SHOW_MODE),
            self::CFG_PRICE_KIND => Configuration::get(self::CFG_PRICE_KIND),
            self::CFG_DAYS_WINDOW => (int)Configuration::get(self::CFG_DAYS_WINDOW),
            self::CFG_RETENTION => (int)Configuration::get(self::CFG_RETENTION),
        ];
        foreach (Language::getLanguages(false) as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $values[self::CFG_LABEL_PRODUCT.'_'.$id_lang] = Configuration::get(self::CFG_LABEL_PRODUCT, $id_lang);
            $values[self::CFG_LABEL_LISTING.'_'.$id_lang] = Configuration::get(self::CFG_LABEL_LISTING, $id_lang);
        }
        $h->fields_value = $values;

        return $out.$h->generateForm([$fields]).$this->renderCronInfo();
    }

    protected function renderCronInfo()
    {
        $token = Configuration::get(self::CFG_CRON_TOKEN);
        $link = $this->context->link->getModuleLink($this->name, 'cron', ['token' => $token], true);
        $html = '<div class="panel">';
        $html .= '<h3>'.$this->l('CRON – dzienny snapshot').'</h3>';
        $html .= '<p>'.$this->l('Wywołaj raz dziennie:').' <code>'.Tools::safeOutput($link).'</code></p>';
        $html .= '<form method="post"><button class="btn btn-default" name="regenCronToken" value="1">'.$this->l('Wygeneruj nowy token').'</button></form>';
        $html .= '</div>';
        return $html;
    }

    /* ===== Render ===== */
    public function hookDisplayMgOmnibusOnProduct($params)
    {
        $id_product = null; $ipa = 0;
        if (!empty($params['product'])) {
            $p = $params['product'];
            $id_product = is_array($p) ? (int)$p['id_product'] : (int)$p->id;
            $ipa = is_array($p) && isset($p['id_product_attribute']) ? (int)$p['id_product_attribute'] : 0;
        } elseif (isset($this->context->controller->product)) {
            $id_product = (int)$this->context->controller->product->id;
        }
        if (!$id_product) return '';
        return $this->renderForProduct($id_product, $ipa, 'product');
    }
    public function hookDisplayMgOmnibusOnListing($params)
    {
        if (empty($params['product'])) return '';
        $p = $params['product'];
        $id_product = is_array($p) ? (int)$p['id_product'] : (int)$p->id;
        $ipa = is_array($p) && isset($p['id_product_attribute']) ? (int)$p['id_product_attribute'] : 0;
        return $this->renderForProduct($id_product, $ipa, 'listing');
    }
    public function hookDisplayProductPriceBlock($params)
    {
        if (!isset($params['type']) || $params['type'] !== 'after_price') return '';
        if (!isset($params['product'])) return '';
        $p = $params['product'];
        $id_product = is_array($p) ? (int)$p['id_product'] : (int)$p->id;
        $ipa = is_array($p) && isset($p['id_product_attribute']) ? (int)$p['id_product_attribute'] : 0;
        return $this->renderForProduct($id_product, $ipa, 'product');
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
            if ($showMode === 'always') {
                $min = (float)$current; // fallback: brak historii
            } else {
                return '';
            }
        }

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
        $this->context->smarty->assign(['mgomnibus' => [
            'text' => $text,
            'min_price' => $min,
            'min_price_formatted' => Tools::displayPrice($min, $this->context->currency),
            'days' => $days,
            'kind' => $priceKind,
        ]]);
        $tpl = ($contextKey === 'product') ? 'product.tpl' : 'listing.tpl';
        return $this->display(__FILE__, 'views/templates/hook/'.$tpl);
    }

    /* ===== Logging ===== */
    public function hookActionObjectUpdateAfter($params)
    {
        if ($this->isCeAdminRequest()) { return; }
        if (!isset($params['object'])) return;
        $obj = $params['object'];
        $cls = get_class($obj);
        require_once __DIR__.'/classes/PriceLogger.php';
        if ($cls === 'Product') {
            PriceLogger::snapshotProduct($this->context, (int)$obj->id, 0);
        } elseif ($cls === 'SpecificPrice') {
            if ((int)$obj->id_product) PriceLogger::snapshotProduct($this->context, (int)$obj->id_product, 0);
            else $this->logAllVisibleIfNeeded();
        } else {
            $this->logAllVisibleIfNeeded();
        }
    }
    public function hookActionSpecificPriceAdd($params) { if ($this->isCeAdminRequest()) { return; } $this->snapshotFromSpecificPrice($params); }
    public function hookActionSpecificPriceUpdate($params) { if ($this->isCeAdminRequest()) { return; } $this->snapshotFromSpecificPrice($params); }
    public function hookActionSpecificPriceDelete($params) { if ($this->isCeAdminRequest()) { return; } $this->snapshotFromSpecificPrice($params); }

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
        PriceLogger::snapshotSomeProducts($this->context, 50);
    }
}
