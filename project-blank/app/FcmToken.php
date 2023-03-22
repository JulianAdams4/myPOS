<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FcmToken extends Model
{
    public function employee()
    {
        return $this->belongsTo('App\Employee');
    }
}
