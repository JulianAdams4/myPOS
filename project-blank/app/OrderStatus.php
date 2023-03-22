<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{

  protected $fillable = [
    'order_id',
    'name',
    'latitude',
    'longitude',
    'duration',
    'status'
  ];

  public function order() {
    return $this->belongsTo('App\Order', 'order_id');
  }

}
