<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceProviderDetail extends Model
{
    protected $fillable = [
        'invoice_provider_id',
        'component_id',
        'quantity',
        'unit_price',
        'tax',
        'discount',
        'created_at',
        'updated_at'
    ];

    public function variation()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }

    public function invoiceProvider()
    {
        return $this->belongsTo('App\InvoiceProvider', 'invoice_provider_id');
    }
}
