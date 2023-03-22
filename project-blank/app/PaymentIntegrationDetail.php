<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PaymentIntegrationDetail extends Model
{

  protected $fillable = [
    'id',
    'store_id',
    'integration_name',
    'cin',
    'amount',
    'currency',
    'message',
    'reference_id',
    'created_at',
    'updated_at',
    'order_payment_integration',
    'payment_id',
    'status'
  ];

  public function store() {
    return $this->belongsTo('App\Store', 'store_id');
  }

  public function orderPaymentInt() {
    return $this->belongsTo('App\OrderHasPaymentIntegration', 'id');
  }

}
