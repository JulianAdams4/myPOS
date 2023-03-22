<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationComponent extends Model
{
    protected $fillable = [
        'prod_spec_id', 'component_id', 'consumption'
    ];

    public function variation()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }
}
