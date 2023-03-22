<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComponentCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'company_id', 'search_string', 'status', 'priority', 'synced_id',
    ];

    protected $hidden = [
        'search_string', 'priority', 'company_id', 'created_at', 'updated_at',
    ];

    public function company()
    {
        return $this->belongsTo('App\Company', 'company_id');
    }

    public function components()
    {
        return $this->hasMany('App\Component');
    }
}
