<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductCategoryExternalId extends Model
{
    public function categories()
    {
        return $this->belongsTo('App\ProductCategory', 'id');
    }
}
