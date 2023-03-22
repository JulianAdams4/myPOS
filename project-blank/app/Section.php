<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{

    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'subtitle',
        'is_main',
        'assigned_of'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function store()
    {
        return $this->belongsTo('App\Store', 'store_id');
    }

    public function availabilities()
    {
        return $this->hasMany('App\SectionAvailability', 'section_id');
    }

    public function categories()
    {
        return $this->hasMany('App\ProductCategory', 'section_id');
    }

    public function specificationsCategories()
    {
        return $this->hasMany('App\SpecificationCategory', 'section_id');
    }

    public function integrations()
    {
        return $this->hasMany('App\SectionIntegration', 'section_id');
    }

    public function discounts()
    {
        return $this->hasMany('App\SectionDiscount', 'section_id');
    }
}
