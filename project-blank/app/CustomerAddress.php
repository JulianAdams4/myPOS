<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerAddress extends Model
{
    //
    use SoftDeletes;

    public function address()
    {
        return $this->belongsTo('App\Address');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }
}
