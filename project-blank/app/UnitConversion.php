<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UnitConversion extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    protected $casts = [
        'multiplier' => 'float'
    ];

    public function unitOrigin()
    {
        return $this->belongsTo('App\MetricUnit', 'unit_origin_id');
    }

    public function unitDestination()
    {
        return $this->belongsTo('App\MetricUnit', 'unit_destination_id');
    }
}
