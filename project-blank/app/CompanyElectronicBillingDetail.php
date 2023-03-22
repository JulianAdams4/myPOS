<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CompanyElectronicBillingDetail extends Model
{
  protected $fillable = [
      'company_id',
      'data_for',
      'bill_sequence',
      'env_prod',
      'special_contributor',
      'accounting_needed',
      'business_name',
      'tradename',
      'address'
  ];

  protected $hidden = [
      'created_at',
      'updated_at'
  ];

  public function company()
  {
    return $this->belongsTo('App\Company', 'company_id');
  }
}
