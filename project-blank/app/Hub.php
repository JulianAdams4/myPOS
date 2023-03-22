<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Hub extends Model
{

    protected $fillable = [
        'name', 'user_id', 'spot_origin'
    ];

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function stores()
    {
        return $this->belongsToMany('App\Store');
    }

    public function getStoreIds()
    {
        return $this->stores()->get()->map(function ($store) {
            return $store['id'];
        });
    }
}
