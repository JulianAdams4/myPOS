<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
  protected $fillable = [
      'name','email','document','phone','address'
  ];

  protected $hidden = [
      'created_at',
      'updated_at'
  ];

  public function customer()
  {
    return $this->belongsTo('App\Customer');
  }

  public function orders()
  {
    return $this->hasMany('App\Order');
  }
}
