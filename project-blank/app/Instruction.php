<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Instruction extends Model
{
  protected $fillable = [
      'description','order_id',
  ];

  protected $hidden = [
      'order_id',
      'created_at',
      'updated_at'
  ];

  public function order()
  {
    return $this->belongsTo('App\Order');
  }
}
