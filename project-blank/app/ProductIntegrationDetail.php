<?php

namespace App;

use App\Helper;
use Illuminate\Database\Eloquent\Model;

class ProductIntegrationDetail extends Model
{
    protected $fillable = [
        'product_id',
        'integration_name',
        'sku',
        'name',
        'price',
        'type',
        'subtype',
        'quantity',
        'external_code',
        'external_id'
    ];

    protected $casts = [
        'price' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo('App\Product', 'product_id');
    }

    public function toppings()
    {
        return $this->belongsToMany(
            'App\ToppingIntegrationDetail',  // Tabla destino
            'product_topping_integrations', // Nombre de tabla intermedia
            'product_integration_id',  // ID del origen
            'topping_integration_id' // ID del destino
        )
            ->using('App\CustomFloatPivot')
            ->withPivot('value');
    }

    public function ifoodPromotion()
    {
        return $this->hasOne('App\IfoodProductPromotion', 'product_integration_id');
    }
}
