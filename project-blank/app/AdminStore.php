<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Events\AdminStoreCreatedEvent;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminStore extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $guard = 'store';

    protected $fillable = [
        'store_id', 'name', 'email', 'password', 'api_token', 'active',
        'activation_token', 'passcode',
    ];

    protected $hidden = [
        'password',
    ];

    protected $dispatchesEvents = [
        'created' => AdminStoreCreatedEvent::class,
    ];

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function stockTransfers()
    {
        return $this->hasMany('App\StockTransfer', 'admin_store_id');
    }

    public function stockTransfersProcessed()
    {
        return $this->hasMany('App\StockTransfer', 'processed_by_id');
    }
}
