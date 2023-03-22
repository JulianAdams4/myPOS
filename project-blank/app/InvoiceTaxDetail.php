<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceTaxDetail extends Model
{

    protected $casts = [
        'subtotal' => 'float',
        'tax_percentage' => 'float',
        'tax_subtotal' => 'float',
    ];

    public function invoice()
    {
        return $this->belongsTo('App\Invoice');
    }
}
