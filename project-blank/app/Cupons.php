<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cupons extends Model
{
    protected $fillable = [
        'promotion_id',
        'cupon_name',
        'max_apply',
        'times_applied',
        'unlimited'
    ];
    protected $appends = [
        'total'
    ];
    public function getTotalAttribute()
    {
        return $this->times_applied.'/'.$this->max_apply ;
    }
    public function promotion()
    {
        return $this->hasOne('App\Promotions', 'id','promotion_id');
    }

}
