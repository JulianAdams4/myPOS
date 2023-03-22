<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DailyStock extends Model
{
    protected $table = 'daily_stock';

    protected $fillable = [
        'component_stock_id', 'day', 'min_stock', 'max_stock'
    ];

    public function componentStock()
    {
        return $this->belongsTo('App\ComponentStock');
    }
}
