<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ExpensesBalance extends Model
{
    protected $casts = [
        'value' => 'float',
    ];

    public function cashierBalance()
    {
        return $this->belongsTo('App\CashierBalance', 'cashier_balance_id');
    }
}
