<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StoreTaxesIntegrations extends Model
{
    public $fillable = [
        'store_id',
        'id_tax',
        'integration_name',
        'external_id',
        'integration_id'
    ];

    public function StoreTaxes()
	{
		return $this->belongsTo('App\StoreTax');
    }

    public function integration()
    {
        return $this->belongsTo('App\AvailableMyposIntegration', 'integration_id');
    }
}