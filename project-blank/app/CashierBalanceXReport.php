<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CashierBalanceXReport extends Model
{
    protected $casts = [
        'order_ids' => 'array'
    ];
}
