<?php

namespace App;

use App\Helper;
use Illuminate\Database\Eloquent\Model;

class ProductToppingIntegration extends Model{

    protected $casts = [
        'value' => 'float',
    ];

    public function product(){
        return $this->belongsTo('App\ProductIntegrationDetail', 'product_integration_id');
    }

    public function topping(){
        return $this->belongsTo('App\ToppingIntegrationDetail', 'topping_integration_id');
    }

}
