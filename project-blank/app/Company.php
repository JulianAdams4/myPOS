<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Log;

class Company extends Model
{
    use Searchable;

    protected $table = 'companies';

    protected $fillable = [
        'name',
        'identifier',
        'contact',
        'TIN',
        'email'
    ];

    public function categories()
    {
        return $this->hasMany('App\ProductCategory', 'company_id');
    }

    public function specCategories()
    {
        return $this->hasMany('App\SpecificationCategory', 'company_id');
    }

    public function stores()
    {
        return $this->hasMany('App\Store', 'company_id');
    }

    public function taxes()
    {
        return $this->hasMany('App\CompanyTax');
    }

    public function billingInformation()
    {
        return $this->hasOne('App\CompanyElectronicBillingDetail', 'company_id');
    }

    public function componentCategories()
    {
        return $this->hasMany('App\ComponentCategory', 'company_id');
    }

    public function stripeCustomerCompany()
    {
        return $this->hasOne('App\StripeCustomerCompany');
    }

    public function franchises()
    {
        return $this->hasMany('App\Franchise', 'origin_company_id');
    }

    public function franchiseOf()
    {
        return $this->hasOne('App\Franchise', 'company_id');
    }

    public function getStripeIdAttribute()
    {
        return isset($this->stripeCustomerCompany->stripe_customer_id) ? $this->stripeCustomerCompany->stripe_customer_id : null;
    }

    public function subscriptionInvoiceDetails()
    {
        return $this->hasManyThrough('App\SubscriptionInvoiceDetails', 'App\Store');
    }

    public function getHasUnpaidInvoicesAttribute()
    {
        foreach ($this->subscriptionInvoiceDetails()->get() as $subscriptionInvoiceDetail) {
            @$invoiceStatus = $subscriptionInvoiceDetail->invoices->status;
            if ($invoiceStatus != SubscriptionInvoices::PAID) return true;
        }

        return false;
    }

    protected $appends = [
        'has_unpaid_invoices'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'has_unpaid_invoices'
    ];
}
