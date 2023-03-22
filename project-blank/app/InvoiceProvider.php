<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceProvider extends Model
{

  public function details()
  {
    return $this->hasMany('App\InvoiceProviderDetail');
  }

  public function provider()
  {
    return $this->belongsTo('App\Providers');
  }
}
