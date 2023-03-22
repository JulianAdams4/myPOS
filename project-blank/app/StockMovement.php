<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public function action()
    {
        return $this->belongsTo('App\InventoryAction', 'inventory_action_id');
    }

    public function componentStock()
    {
        return $this->belongsTo('App\ComponentStock', 'component_stock_id');
    }

    public function createdBy()
    {
        return $this->belongsTo('App\Store', 'created_by_id');
    }

    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    public function invoiceProvider()
    {
        return $this->belongsTo('App\InvoiceProvider', 'invoice_provider_id');
    }
}
