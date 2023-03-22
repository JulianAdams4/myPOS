<?php

namespace App;

use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $guard = 'employee-api';

    protected $fillable = [
        'name', 'email', 'password', 'store_id', 'created_at', 'plate', 'pin_code', 'phone_number'
    ];

    protected $hidden = [
        'password', 'token_expire_at', 'updated_at', 'access_token'
    ];

    const ADMIN_STORE = 1;
    const DISPATCHER  = 2;
    const WAITER      = 3;
    const CASHIER     = 4;
    const DELIVERY    = 5;

    public function verifyEmployeeBelongsToHub($hub)
    {
        foreach ($hub->stores as $store) {
            if ($store->id == $this->store->id) {
                return true;
            }
        }

        return false;
    }

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function orders()
    {
        return $this->hasMany('App\Order');
    }

    public function invoices()
    {
        return $this->hasManyThrough('App\Invoice', 'App\Order');
    }

    public function fcmTokens()
    {
        return $this->hasMany('App\FcmToken');
    }

    public function isAdminStore()
    {
        return $this->type_employee === Employee::ADMIN_STORE;
    }

    public function isDispatcher()
    {
        return $this->type_employee === Employee::DISPATCHER;
    }

    public function isCashier()
    {
        return $this->type_employee === Employee::CASHIER;
    }

    public function isWaiter()
    {
        return $this->type_employee === Employee::WAITER;
    }

    public function location()
    {
        return $this->belongsTo('App\StoreLocations');
    }
}
