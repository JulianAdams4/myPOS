<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductComponent extends Model
{

    protected $fillable = ['product_id', 'component_id', 'consumption', 'status'];

    public function variation()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }
}
