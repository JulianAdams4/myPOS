<?php

namespace App;

use App\StockTransfer;
use App\StoreConfigurations;
use App\ComponentCategory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $appends = ['country_id'];

    public function city()
    {
        return $this->belongsTo('App\City');
    }

    public function taxes()
    {
        return $this->hasMany('App\StoreTax');
    }

    public function company()
    {
        return $this->belongsTo('App\Company');
    }

    public function cashierBalances()
    {
        return $this->hasMany('App\CashierBalance');
    }

    public function latestCashierBalance()
    {
        return $this->hasOne('App\CashierBalance')->latest();
    }

    public function currentCashierBalance()
    {
        return $this->hasOne('App\CashierBalance')->whereNull('date_close')->latest();
    }

    public function previousCashierBalance()
    {
        return $this->hasOne('App\CashierBalance')->whereNotNull('date_close')->latest();
    }

    public function address()
    {
        return $this->belongsTo('App\Address');
    }

    public function printers()
    {
        return $this->hasMany('App\StorePrinter');
    }

    public function orders()
    {
        return $this->hasMany('App\Order');
    }

    public function invoices()
    {
        return $this->hasManyThrough('App\Invoice', 'App\Order');
    }

    public function lastInvoiceWithNumber()
    {
        return $this->hasManyThrough('App\Invoice', 'App\Order')
            ->whereNotNull('invoice_number')
            ->latest()->first();
    }

    public function nextInvoiceBillingNumber()
    {
        $lastValidInvoice = $this->lastInvoiceWithNumber();
        return $lastValidInvoice ? ++$lastValidInvoice->invoice_number : '';
    }

    public function spots()
    {
        return $this->hasMany('App\Spot');
    }

    public function eatsSpot()
    {
        return $this->hasOne('App\Spot')->where('origin', Spot::ORIGIN_EATS);
    }

    public function configs()
    {
        return $this->hasOne('App\StoreConfig');
    }

    public function configurations()
    {
        return $this->hasMany('App\StoreConfigurations');
    }

    public function configurationFromKey($key)
    {
        $configObject = StoreConfigurations::select(['value'])->where('store_id', $this->id)->where('key', $key)->first();
        
        return !$configObject ? [] : json_decode($configObject->value);
    }

    public function hubs()
    {
        return $this->belongsToMany('App\Hub');
    }

    public function employees()
    {
        return $this->hasMany('App\Employee');
    }

    public function sections()
    {
        return $this->hasMany('App\Section');
    }

    public function componentStocks()
    {
        return $this->hasMany('App\ComponentStock');
    }

    public function stockTransfers()
    {
        return $this->hasMany('App\StockTransfer');
    }

    public function pendingStockTransfers()
    {
        return $this->hasMany('App\StockTransfer', 'destination_store_id', 'id')
            ->where('status', StockTransfer::PENDING)
            ->orWhere('status', StockTransfer::FAILED);
    }

    public function cards()
    {
        return $this->belongsToMany('App\Card');
    }

    public function getCountryIdAttribute()
    {
        return isset($this->city->country_id) ? $this->city->country_id : null;
    }

    public function integrationIds()
    {
        return $this->hasMany('App\StoreIntegrationId', 'store_id');
    }

    public function integrationTokens()
    {
        return $this->hasMany('App\StoreIntegrationToken', 'store_id');
    }

    public function eatsIntegrationToken()
    {
        return $this->hasOne('App\StoreIntegrationToken', 'store_id')
            ->where('integration_name', AvailableMyposIntegration::NAME_EATS);
    }

    public function locations()
    {
        return $this->hasMany('App\StoreLocations');
    }

    public function subscriptions()
    {
        return $this->hasMany('App\Subscription');
    }

    public function subscriptionDiscounts()
    {
        return $this->hasMany('App\SubscriptionDiscount');
    }

    public function subscriptionInvoiceDetails()
    {
        return $this->hasMany('App\SubscriptionInvoiceDetails');
    }

    public function getComponentCategoryIDs()
    {
        return ComponentCategory::where('company_id', $this->company_id)
            ->where('status', 1)->pluck('id')->toArray();
    }

    public function mailRecipients()
    {
        return $this->hasMany('App\MailRecipient');
    }
}
