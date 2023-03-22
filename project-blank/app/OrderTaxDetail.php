<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderTaxDetail extends Model
{

    protected $casts = [
        'subtotal' => 'float',
        'tax_subtotal' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo('App\Order');
    }

    public function storeTax()
    {
        return $this->belongsTo('App\StoreTax');
    }    
}
