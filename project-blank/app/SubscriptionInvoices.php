<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SubscriptionInvoices extends Model
{

    const PAID = 'paid';
    const PENDING = 'pending';
    const FAILED = 'failed';

    protected $fillable = [
        'external_invoice_id',
        'integration_name',
        'subtotal',
        'discounts',
        'total_taxes',
        'total',
        'billing_date',
        'company_id',
        'country',
        'status'
    ];

    public function subscriptionInvoiceDetails()
    {
        return $this->hasMany('App\SubscriptionInvoiceDetails', 'subs_invoice_id');
    }

    public function areAllDetailsPaid()
    {
        foreach ($this->subscriptionInvoiceDetails()->get() as $subscriptionInvoiceDetail) {
            if ($subscriptionInvoiceDetail->status != SubscriptionInvoiceDetails::PAID)
                return false;
        }

        return true;
    }

    public function company()
    {
        return $this->belongsTo('App\Company');
    }

    public function country()
    {
        return $this->belongsTo('App\Country', 'country');
    }
}
