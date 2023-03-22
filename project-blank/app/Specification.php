<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Specification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'specification_category_id',
        'name',
        'status',
        'value',
        'priority'
    ];

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    protected $casts = [
        'value' => 'float',
    ];

    public function specificationCategory()
    {
        return $this->belongsTo('App\SpecificationCategory', 'specification_category_id');
    }

    public function products()
    {
        return $this->belongsToMany(
            'App\Product',
            'product_specifications',
            'specification_id',
            'product_id'
        )
            ->withPivot('status', 'value');
    }

    public function productSpecifications()
    {
        return $this->hasMany('App\ProductSpecification');
    }

    public function variations()
    {
        return $this->belongsToMany(
            'App\Component',  // Tabla destino
            'specification_components', // Nombre de tabla intermedia
            'specification_id',  // ID del origen
            'component_id'
        ); // ID del destino
    }

    public function components()
    {
        return $this->hasMany('App\SpecificationComponent', 'specification_id');
    }

    public function productSpecComponents()
    {
        return $this->hasMany('App\ProductSpecificationComponent', 'specification_id');
    }
}
