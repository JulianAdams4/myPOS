<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StoreConfig extends Model
{
    protected $fillable = [
        'is_dark_kitchen',
        'time_zone',
        'auto_open_close_cashier',
        'show_taxes',
        'document_lengths',
        'eats_store_id',
        'uses_print_service',
        'employee_digital_comanda',
        'show_invoice_specs',
        'alternate_bill_sequence',
        'comanda',
        'precuenta',
        'factura',
        'cierre',
        'credit_format',
        'common_bills',
        'show_search_name_comanda',
        'is_dark_kitchen',
        'time_zone',
        'auto_open_close_cashier',
        'currency_symbol',
        'allow_modify_order_payment',
        'inventory_store_id',
        'employees_edit',
        'store_money_format',
        'automatic'
    ];

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }

    public function inventoryStore()
    {
        return $this->belongsTo('App\Store', 'inventory_store_id');
    }

    public function getInventoryStore()
    {
        return $this->inventoryStore ?
        $this->inventoryStore :
        $this->store;
    }
}
