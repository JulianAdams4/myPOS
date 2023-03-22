<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{

    protected $casts = [
        'base_value' => 'float',
        'total' => 'float',
    ];

    public function invoice()
    {
        return $this->belongsTo('App\Invoice');
    }

    public function orderDetail()
    {
        return $this->belongsTo('App\OrderDetail', 'order_detail_id');
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->invoice == null || $this->invoice->order == null || $this->invoice->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->invoice->order->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->invoice == null || $this->invoice->order == null || $this->invoice->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->invoice->order->store)->toDateTimeString();
    }
}
