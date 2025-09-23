<?php

class AdminMgOmnibusLogsController extends ModuleAdminController
{
    // DEFINICJE TABLIC PRZENIESIONE POZA CONSTRUCT
    public $fields_list;
    public $bulk_actions;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'mgomnibus_price_history';
        $this->className = 'ObjectModel'; 
        $this->list_id = 'mgomnibus_price_history';
        $this->identifier = 'id_history';
        $this->_defaultOrderBy = 'captured_at';
        $this->_defaultOrderWay = 'DESC';
        $this->list_no_link = true;

        parent::__construct();
        
        // WYPEŁNIENIE TABLIC WEWNĄTRZ CONSTRUCT
        $this->fields_list = [
            'id_history' => ['title' => $this->l('ID'), 'align' => 'center', 'class' => 'fixed-width-xs'],
            'id_shop' => ['title' => $this->l('ID Shop'), 'align' => 'center', 'class' => 'fixed-width-xs'],
            'id_product' => ['title' => $this->l('ID Product'), 'align' => 'center', 'class' => 'fixed-width-sm', 'filter_key' => 'a!id_product'],
            'name' => ['title' => $this->l('Product'), 'filter_key' => 'pl!name'],
            'id_product_attribute' => ['title' => $this->l('ID Attribute'), 'align' => 'center'],
            'price_tax_incl' => ['title' => $this->l('Gross price'), 'type' => 'price', 'currency' => true],
            'price_tax_excl' => ['title' => $this->l('Net price'), 'type' => 'price', 'currency' => true],
            'id_currency' => ['title' => $this->l('ID Currency'), 'align' => 'center'],
            'id_country' => ['title' => $this->l('ID Country'), 'align' => 'center'],
            'id_group' => ['title' => $this->l('ID Group'), 'align' => 'center'],
            'captured_at' => ['title' => $this->l('Date captured'), 'type' => 'datetime'],
        ];

        $this->bulk_actions = ['delete' => ['text' => $this->l('Delete selected'), 'confirm' => $this->l('Delete selected items?')]];
    }

    public function renderList()
    {
        $this->_select = 'pl.name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = a.`id_product` AND pl.`id_lang` = ' . (int)$this->context->language->id . ' AND pl.id_shop = a.id_shop)';
        $this->_where = 'AND a.id_shop IN (' . implode(',', Shop::getContextListShopID()) . ')';
        
        return parent::renderList();
    }

    public function processBulkDelete()
    {
        if (is_array($this->boxes) && !empty($this->boxes)) {
            $ids = array_map('intval', $this->boxes);
            if (Db::getInstance()->delete($this->table, $this->identifier . ' IN (' . implode(',', $ids) . ')')) {
                $this->confirmations[] = $this->l('The selection has been successfully deleted.');
            } else {
                $this->errors[] = $this->l('An error occurred while deleting the selection.');
            }
        }
    }
}