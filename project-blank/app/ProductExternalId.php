<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductExternalId extends Model
{
    public function products()
    {
        return $this->belongsTo('App\Product', 'product_id');
    }
}
