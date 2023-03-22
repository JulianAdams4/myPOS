<?php

namespace App;

use App\Helper;
use Illuminate\Database\Eloquent\Model;

class ProductsConnectionIntegration extends Model{

    protected $fillable = [
        'connection_type', 'main_product_id', 'component_product_id'
    ];

    public function main_product(){
        return $this->belongsTo('App\ProductIntegrationDetail', 'main_product_id');
    }

    public function component_product(){
        return $this->belongsTo('App\ProductIntegrationDetail', 'component_product_id');
    }

    //// REMEMBER: El campo connection_type es para destacar el tipo de union entre estos productos
    //// Puede ser union por: combo (main es combo y component es un producto del combo)
    //// Puede ser union por: extra (main es combo/producto y component es extra)

}
