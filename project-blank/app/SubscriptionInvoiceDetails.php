<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SubscriptionInvoiceDetails extends Model
{
    const PAID = 'paid';
    const PENDING = 'pending';
    const FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'subscription_plan_id',
        'subs_invoice_id',
        'subtotal',
        'discounts',
        'total_taxes',
        'total',
        'subs_start',
        'subs_end',
        'description',
        'status'
    ];

    public function invoices()
    {
        return $this->belongsTo('App\SubscriptionInvoices', 'subs_invoice_id');
    }

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function plan()
    {
        return $this->belongsTo('App\SubscriptionPlan', 'subscription_plan_id');
    }
}
