<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderReason extends Model
{
    // Constantes del los tipos de razones
    const CANCEL_NO_REVERT = 1;
    const CANCEL_REVERT = 2;
    const CANCEL_OTHERS_NO_REVERT = 3;

    public function productionOrderStatus()
    {
        return $this->belongsTo('App\ProductionOrderStatus', 'reason_id');
    }
}
