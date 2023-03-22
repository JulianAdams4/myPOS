<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpecificationCategory extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'section_id',
        'priority',
        'required',
        'max',
        'status',
        'show_quantity',
        'type',
        'subtitle'
    ];

    protected $hidden = [
        'created_at', 'updated_at',
    ];

    const NORMAL_TYPE = 1;
    const SIZE_TYPE   = 2;

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ngrams' => utf8_encode((new TNTIndexer)->buildTrigrams($this->name)),
        ];
    }

    public function specifications()
    {
        return $this->hasMany('App\Specification', 'specification_category_id');
    }

    public function company()
    {
        return $this->belongsTo('App\Company', 'company_id');
    }

    public function productSpecs()
    {
        return $this->hasManyThrough('App\ProductSpecification', 'App\Specification');
    }

    // Si la categoria de especificaciones es de tipo "tamaño".
    public function isSizeType()
    {
        return $this->type == SpecificationCategory::SIZE_TYPE;
    }

    // Si se debe mostrar cantidad para esta categoria.
    public function shouldShowQuantity()
    {
        return $this->show_quantity == 1;
    }

    public function section()
    {
        return $this->belongsTo('App\Section', 'section_id');
    }
}
