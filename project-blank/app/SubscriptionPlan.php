<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
  use SoftDeletes;

  protected $appends = ['country_id'];

  public function subscriptionProduct()
  {
    return $this->belongsTo('App\SubscriptionProduct');
  }

  public function subscriptions()
  {
    return $this->hasMany('App\Subscription');
  }

  public function getCountryIdAttribute()
  {
    return isset($this->subscriptionProduct->country_id) ? $this->subscriptionProduct->country_id : null;
  }
}
