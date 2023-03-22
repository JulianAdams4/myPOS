<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class MailRecipient extends Model
{

  protected $fillable = ['store_id', 'email'];

  public function store()
  {
    return $this->belongsTo('App\Store');
  }
}
