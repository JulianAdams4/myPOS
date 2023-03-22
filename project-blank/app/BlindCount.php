<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BlindCount extends Model
{
    protected $fillable = [
        'store_id',
    ];

    protected $hidden = [
        'updated_at',
    ];

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }

    public function blindcountmovements()
    {
        return $this->hasMany('App\BlindCountMovement', 'blind_count_id');
    }
}
