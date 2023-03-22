<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class ProductDetail extends Model
{
    protected $fillable = [
        'product_id',
        'store_id',
        'stock',
        'value',
        'status',
        'production_cost',
        'income',
        'cost_ratio',
        'tax_by_value'
    ];

    protected $casts = [
        'value' => 'float',
        'tax_by_value' => 'float'
    ];

    public function product()
    {
        return $this->belongsTo('App\Product', 'product_id');
    }

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function locations()
    {
        return $this->belongsToMany('App\StoreLocations', 'product_detail_store_locations', 'product_detail_id', 'store_location_id');
    }

    public function orderDetails()
    {
        return $this->hasMany('App\OrderDetail');
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }
}
