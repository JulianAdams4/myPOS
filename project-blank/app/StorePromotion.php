<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StorePromotion extends Model
{
    protected $fillable = [
        'promotion_id',
        'store_id',
        'status'
    ];
    public function store()
    {
        return $this->hasOne('App\Store', 'id','promotion_type_id');
    }
    public function store_promotion_details()
    {
        return $this->hasMany('App\StorePromotionDetails');
    }

}
