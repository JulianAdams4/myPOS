<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class OrderCondition extends Model
{
    protected $fillable = [
      'order_id',
      'name',
      'formatted_created_at',
    ];

    protected $hidden = [
      'created_at',
      'updated_at',
    ];

    public function order()
    {
        return $this->belongsTo('App\Order', 'order_id');
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
}
