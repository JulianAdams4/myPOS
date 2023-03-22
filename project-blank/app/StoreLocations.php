<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreLocations extends Model
{

    use SoftDeletes;

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function printers()
    {
        return $this->hasMany('App\StorePrinter');
    }
}
