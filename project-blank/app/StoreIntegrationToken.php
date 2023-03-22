<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreIntegrationToken extends Model
{
  use SoftDeletes;

  /*For SoftDeletes*/
  protected $dates = ['deleted_at'];

  protected $fillable = [
      'store_id',
      'integration_name',
      'token',
      'token_type',
      'type',
      'refresh_token',
      'scope',
      'password'
  ];

  protected $hidden = [
      'created_at',
      'updated_at',
      'expires_in',
      'refresh_token',
      'scopes'
  ];

  public function store()
  {
    return $this->belongsTo('App\Store', 'store_id');
  }
}
