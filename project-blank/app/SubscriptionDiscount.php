<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionDiscount extends Model
{
  use SoftDeletes;

  protected $fillable = ['store_id', 'subscription_product_id', 'discount', 'expires_at'];

  public function subscriptionProduct()
  {
    return $this->belongsTo('App\SubscriptionProduct');
  }

  public function store()
  {
    return $this->belongsTo('App\Store');
  }
}
