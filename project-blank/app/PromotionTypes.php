<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PromotionTypes extends Model
{
    protected $fillable = [
        'name',
        'is_discount_ype',
        'status'
    ];
}
