<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use SoftDeletes;

    protected $table = 'product_categories';

    protected $fillable = [
        'company_id',
        'search_string',
        'name',
        'section_id',
        'subtitle',
        'priority',
        'image',
        'status',
        'image_version'
    ];

    protected $hidden = [
        'company_id', 'status', 'created_at', 'updated_at'
    ];

    public function products()
    {
        return $this->hasMany('App\Product', 'product_category_id');
    }

    public function company()
    {
        return $this->belongsTo('App\Company', 'company_id');
    }

    public function section()
    {
        return $this->belongsTo('App\Section', 'section_id');
    }
    
}
