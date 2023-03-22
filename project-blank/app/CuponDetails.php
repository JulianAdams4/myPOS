<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CuponDetails extends Model
{
    protected $fillable = [
        'cupon_id',
        'cupon_code'
    ];
}
