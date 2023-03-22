<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Franchise extends Model
{
  use SoftDeletes;

  protected $fillable = [];

  public function company()
  {
    return $this->belongsTo('App\Company', 'company_id');
  }

  public function originCompany()
  {
    return $this->belongsTo('App\Company', 'origin_company_id');
  }
}
