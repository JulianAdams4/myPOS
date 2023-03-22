<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StoreConfigurations extends Model
{
    const TOPPINGS_STEPPER_MODE_KEY = "toppingsStepperMode";
    const REQUIRE_LOCATOR_KEY = "requireLocator";
    const DISABLE_CLOSE_MAIL_KEY = "disableCloseMail";

    protected $fillable = [
        'store_id',
        'key',
        'value',
    ];

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }
}
