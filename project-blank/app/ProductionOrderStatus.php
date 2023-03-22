<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderStatus extends Model
{
    public function productionOrder()
    {
        return $this->belongsTo('App\ProductionOrder', 'production_order_id');
    }

    public function reason()
    {
        return $this->belongsTo('App\ProductionOrderReason', 'reason_id');
    }
}
