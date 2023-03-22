<?php

namespace App;

use App\Helper;
use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;
use App\Events\OrderCreatedComanda;
use App\Events\OrderDeleted;
use Log;

class Order extends Model
{
    protected $fillable = [
        'customer_id',
        'store_id',
        'address_id',
        'billing_id',
        'phone',
        'route_value',
        'order_value',
        'order_token',
        'order_duration',
        'order_distance',
        'change_value',
        'current_status',
        'delivery_waiting',
        'spot_id',
        'employee_id',
        'cash',
        'identifier',
        'cashier_balance_id',
        'base_value',
        'total',
        'food_service',
        'discount_percentage',
        'discount_value',
        'undiscounted_base_value',
        'tip',
        'created_at',
        'updated_at',
        'people',
        'device_id',
        'is_courtesy'
    ];

    protected $hidden = [
        'billing_id',
        'updated_at',
    ];

    protected $appends = [
        'nt_value',
        'formatted_date',
    ];

    protected $dispatchesEvents = [
        'created' => OrderCreatedComanda::class
    ];

    protected $casts = [
        'order_value' => 'float',
        'total' => 'float',
        'base_value' => 'float',
        'discount_percentage' => 'float',
        'discount_value' => 'float',
        'undiscounted_base_value' => 'float',
        'no_tax_subtotal' => 'float',
        'tip' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Order $order) {
            event(new OrderDeleted($order));
        });
    }

    public function getNtValueAttribute()
    {
        if (isset($this->attributes['order_value'])) {
            return $this->attributes['order_value'] / (1 + Helper::$iva);
        } else {
            return 0;
        }
    }

    public function sumValue($product_id, $process_status)
    {
        $value = 0;
        $base_value = 0;
        $quantity = 0;
        foreach ($this->orderDetails as $detail) {
            if ($detail->product_detail_id == $product_id and $detail->lastProcessStatus()['process_status'] == $process_status) {
                $value = $value + $detail->value;
                $base_value = $base_value + $detail->base_value;
                $quantity = $quantity + $detail->quantity;
            }
        }

        return [
            'value' => $value,
            'base_value' => $base_value,
            'quantity' => $quantity
        ];
    }

    public function cashierBalance()
    {
        return $this->belongsTo('App\CashierBalance', 'cashier_balance_id');
    }

    public function payments()
    {
        return $this->hasMany('App\Payment');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer', 'customer_id');
    }

    public function employee()
    {
        return $this->belongsTo('App\Employee', 'employee_id')->withTrashed();
    }

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }

    public function address()
    {
        return $this->belongsTo('App\Address', 'address_id');
    }

    public function card()
    {
        return $this->belongsTo('App\Card', 'card_id');
    }

    public function billing()
    {
        return $this->belongsTo('App\Billing', 'billing_id');
    }

    public function invoice()
    {
        return $this->hasOne('App\Invoice', 'order_id');
    }

    public function orderDetails()
    {
        return $this->hasMany('App\OrderDetail', 'order_id');
    }

    public function taxDetails()
    {
        return $this->hasMany('App\OrderTaxDetail', 'order_id');
    }

    public function orderStatus()
    {
        return $this->hasMany('App\OrderStatus', 'order_id');
    }

    public function orderConditions()
    {
        return $this->hasMany('App\OrderCondition', 'order_id');
    }

    public function instruction()
    {
        return $this->hasOne('App\Instruction', 'order_id', 'id');
    }

    public function payment()
    {
        return $this->hasOne('App\Payment', 'order_id', 'id');
    }

    public function spot()
    {
        return $this->belongsTo('App\Spot', 'spot_id');
    }

    public function orderIntegrationDetail()
    {
        return $this->hasOne('App\OrderIntegrationDetail', 'order_id');
    }

    public function creditNote()
    {
        return $this->hasOne('App\CreditNote');
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }

    public function getFormattedDateAttribute($value)
    {
        return Helper::formattedDate($this->updated_at);
    }

    public function isDispatched()
    {
        foreach ($this->orderDetails as $detail) {
            if (!$detail->isDispatched()) return false;
        }

        return true;
    }

    public function processStatus()
    {
        return $this->orderDetails->processStatus;
    }
}
