<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ComponentsIntegrations extends Model
{
    public function integration()
    {
        return $this->belongsTo('App\AvailableMyposIntegration', 'integration_id');
    }

    public function components()
    {
        return $this->hasMany('App\Component');
    }
}
