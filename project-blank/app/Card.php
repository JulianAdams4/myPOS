<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    //
    public function stores()
    {
        return $this->belongsToMany('App\Store');
    }
}
