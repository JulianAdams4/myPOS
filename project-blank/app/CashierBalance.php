<?php

namespace App;

use App\Traits\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CashierBalance extends Model
{
    const ANTON_CLOSE = false;
    const ANTON_OPEN = true;

    protected $fillable = [
        'employee_id_open',
        'date_open',
        'date_close',
        'hour_open',
        'value_previous_close',
        'value_open',
        'store_id',
        'observation',
        'cashier_number'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'store_id',
    ];

    protected $appends = [
        'total_expenses',
    ];

    public function isClosed()
    {
        return $this->date_close != null;
    }

    public function hasActiveOrders()
    {
        foreach ($this->orders as $order) {
            if ($order->status == 1 && $order->preorder == 1) {
                return true;
            }
        }

        return false;
    }

    public function getTotalExpensesAttribute()
    {
        $expenses = $this->expenses;
        $totalExpenses = 0;
        foreach ($expenses as $expense) {
            $totalExpenses += $expense->value;
        }
        return $totalExpenses;
    }

    public function expenses()
    {
        return $this->hasMany('App\ExpensesBalance', 'cashier_balance_id');
    }

    public function employeeOpen()
    {
        return $this->belongsTo('App\Employee', 'employee_id_open');
    }

    public function employeeClose()
    {
        return $this->belongsTo('App\Employee', 'employee_id_close');
    }

    public function orders()
    {
        return $this->hasMany('App\Order', 'cashier_balance_id');
    }

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }

    public function getDateOpenAttribute($value)
    {
        if (!isset($this->attributes['hour_open'])) {
            return null;
        }

        $hour = $this->attributes['hour_open'];
        $date = Carbon::parse($value . " " . $hour);

        return TimezoneHelper::localizedDateForStore($date, $this->store)->toDateString();
    }

    public function getHourOpenAttribute($value)
    {
        if (!isset($this->attributes['date_open'])) {
            return null;
        }

        $open = $this->attributes['date_open'];
        $date = Carbon::parse($open . " " . $value);

        return TimezoneHelper::localizedDateForStore($date, $this->store)->toTimeString();
    }

    public function getDateCloseAttribute($value)
    {
        if (!isset($this->attributes['hour_close'])) {
            return null;
        }

        $hour = $this->attributes['hour_close'];

        if (!$hour) {
            return null;
        }

        $date = Carbon::parse($value . " " . $hour);

        return TimezoneHelper::localizedDateForStore($date, $this->store)->toDateString();
    }

    public function getHourCloseAttribute($value)
    {
        if (!isset($this->attributes['date_close'])) {
            return null;
        }

        $close = $this->attributes['date_close'];

        if (!$close) {
            return null;
        }

        $date = Carbon::parse($close . " " . $value);

        return TimezoneHelper::localizedDateForStore($date, $this->store)->toTimeString();
    }
}
