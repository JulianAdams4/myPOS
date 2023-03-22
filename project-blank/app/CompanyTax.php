<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CompanyTax extends Model
{
	protected $fillable = ['name', 'percentage', 'type', 'enabled', 'company_id'];

    public function company()
	{
		return $this->belongsTo('App\Company');
	}
}
