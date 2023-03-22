<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $casts = [
        'original_content' => 'array',
    ];

    public function statuses()
    {
        return $this->hasMany('App\ProductionOrderStatus', 'production_order_id');
    }

    public function component()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }
}
