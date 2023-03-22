<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetricUnit extends Model
{
    use SoftDeletes;

    protected $hidden = [
        'company_id', 'created_at', 'updated_at',
    ];

    protected $fillable = [
        'name', 'company_id', 'short_name', 'status',
    ];

    public function conversionOrigins()
    {
        return $this->hasMany('App\UnitConversion', 'unit_origin_id');
    }

    public function conversionDestinations()
    {
        return $this->hasMany('App\UnitConversion', 'unit_destination_id');
    }
}
