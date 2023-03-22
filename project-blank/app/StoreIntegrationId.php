<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StoreIntegrationId extends Model
{
    public $table = 'store_integration_ids';
    public $fillable = ['store_id','type','integration_id','integration_name','external_store_id'];
    public $timestamps = false;

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }
}
