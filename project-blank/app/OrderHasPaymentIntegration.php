<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderHasPaymentIntegration extends Model
{
    protected $fillable = [
        'id',
        'store_id',
        'order_id',
        'integration_name',
        'amount',
        'currency',
        'message',
        'reference_id',
        'created_at',
        'updated_at',
      ];

    public function order() {
        return $this->belongsTo('App\Order', 'order_id');
    }

    public function store() {
        return $this->belongsTo('App\Store', 'store_id');
    }
}
