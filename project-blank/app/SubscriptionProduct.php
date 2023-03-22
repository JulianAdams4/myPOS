<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionProduct extends Model
{
  use SoftDeletes;

  public function country()
  {
    return $this->belongsTo('App\Country');
  }

  public function subscriptionPlans()
  {
    return $this->hasMany('App\SubscriptionPlans');
  }
}
