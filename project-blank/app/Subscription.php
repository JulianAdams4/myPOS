<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
  use SoftDeletes;

  protected $fillable = ['store_id', 'subscription_plan_id', 'billing_date', 'activation_date'];

  public function subscriptionPlan()
  {
    return $this->belongsTo('App\SubscriptionPlan');
  }

  public function store()
  {
    return $this->belongsTo('App\Store');
  }
}
