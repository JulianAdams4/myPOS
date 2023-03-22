<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{

    protected $fillable = [
        'order_id',
        'billing_id',
        'status',
        'subtotal',
        'tax',
        'total',
        'name',
        'document',
        'email',
        'phone',
        'address',
        'food_service',
        'tip',
        'was_printed'
    ];

    protected $casts = [
        'subtotal' => 'float',
        'tax' => 'float',
        'total' => 'float',
        'discount_percentage' => 'float',
        'discount_value' => 'float',
        'undiscounted_subtotal' => 'float',
        'tip' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo('App\Order', 'order_id');
    }

    public function billing()
    {
        return $this->belongsTo('App\Billing', 'billing_id');
    }

    public function items()
    {
        return $this->hasMany('App\InvoiceItem');
    }

    public function taxDetails()
    {
        return $this->hasMany('App\InvoiceTaxDetail');
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }

    public function integrations()
    {
        return $this->hasMany('App\InvoiceIntegrationDetails');
    }
}