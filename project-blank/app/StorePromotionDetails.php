<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StorePromotionDetails extends Model
{
    protected $fillable=[
        'store_promotion_id',
        'product_id',
        'quantiti',
        'cause_tax',
        'discount_value',
        'status'
    ];
    public function product()
    {
        return $this->hasOne('App\Product', 'id','product_id');
    }

}
