<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ComponentVariation extends Model
{

    protected $casts = [
        'cost' => 'float',
        'value' => 'float',
    ];

    public function unit()
    {
        return $this->belongsTo('App\MetricUnit', 'metric_unit_id');
    }

    public function productComponents()
    {
        return $this->hasMany('App\ProductComponent', 'component_id');
    }

    public function component()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }

    public function lastComponentStock()
    {
        return $this->hasOne('App\ComponentStock')->latest();
    }

    public function componentStocks()
    {
        return $this->hasMany('App\ComponentStock');
    }

    public function subrecipe()
    {
        return $this->hasMany('App\ComponentVariationComponent', 'component_origin_id');
    }
}
