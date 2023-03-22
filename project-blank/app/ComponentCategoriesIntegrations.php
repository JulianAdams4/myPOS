<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ComponentCategoriesIntegrations extends Model
{
    public function categories()
	{
		return $this->belongsTo('App\ComponentCategory');
    }

    public function integration()
    {
        return $this->belongsTo('App\AvailableMyposIntegration', 'integration_id');
    }
}