<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductDetailStoreLocation extends Model
{
    //
    protected $fillable = [
        'product_detail_id',
        'store_location_id'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];
}
