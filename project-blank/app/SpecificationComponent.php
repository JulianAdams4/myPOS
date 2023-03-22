<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SpecificationComponent extends Model
{
    protected $fillable = ['component_id', 'specification_id', 'consumption', 'status'];

    public function variation()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }
}
