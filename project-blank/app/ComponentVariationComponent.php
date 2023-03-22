<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComponentVariationComponent extends Model
{

    use SoftDeletes;

    protected $fillable = [
        'component_origin_id', 'component_destination_id', 'value_reference', 'consumption'
    ];

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    public function variationOrigin()
    {
        return $this->belongsTo('App\Component', 'component_origin_id');
    }

    public function variationSubrecipe()
    {
        return $this->belongsTo('App\Component', 'component_destination_id');
    }
}
