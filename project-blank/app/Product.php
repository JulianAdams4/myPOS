<?php

namespace App;

use App\Helper;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;

class Product extends Model
{
    use Searchable;

    protected $table = 'products';

    protected $fillable = [
        'product_category_id',
        'name',
        'search_string',
        'description',
        'priority',
        'base_value',
        'image',
        'status',
        'invoice_name',
        'sku',
        'ask_instruction',
        'eats_product_name',
        'image_version',
        'is_alcohol',
        'type_product'
    ];

    protected $hidden = [
        'priority', 'type', 'created_at', 'updated_at'
    ];

    protected $appends = [
        'nt_value', 'tax_values'
    ];

    protected $casts = [
        'base_value' => 'float'
    ];

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ngrams' => utf8_encode((new TNTIndexer)->buildTrigrams($this->name)),
        ];
    }

    public function getTaxValuesAttribute()
    {
        $taxes = $this->taxes;
        $totalIncludedTax = 0;
        $totalAdditionalTax = 0;
        foreach ($taxes as $tax) {
            if ($tax->type === 'included' && $tax->enabled) {
                $totalIncludedTax += $tax->percentage;
            }
            if ($tax->type === 'additional' && $tax->enabled) {
                $totalAdditionalTax += $tax->percentage;
            }
        }
        $ntValueRaw = $this->attributes['base_value'] / (1 + ($totalIncludedTax / 100));
        $ntValue = $ntValueRaw;
        $taxValueRaw = $this->attributes['base_value'] + ($ntValue * ($totalAdditionalTax / 100));
        $taxValue = $taxValueRaw;
        return [
            'no_tax_raw' => $ntValueRaw,
            'no_tax' => $ntValue,
            'with_tax' => $taxValue,
        ];
    }

    public function getNtValueAttribute()
    {
        if (isset($this->attributes['base_value'])) {
            return $this->attributes['base_value'] / (1 + Helper::$iva);
        } else {
            return 0;
        }
    }

    public function taxes()
    {
        return $this->belongsToMany(
            'App\StoreTax', // Tabla destino
            'product_taxes', // Nombre de tabla intermedia
            'product_id', // ID del origen
            'store_tax_id'
        ); // ID del destino
    }

    public function details()
    {
        return $this->hasMany('App\ProductDetail', 'product_id');
    }

    public function specifications()
    {
        return $this->belongsToMany(
            'App\Specification', // Tabla destino
            'product_specifications', // Nombre de tabla intermedia
            'product_id', // ID del origen
            'specification_id'
        ) // ID del destino
            ->withPivot('status', 'value');
    }

    public function variations()
    {
        return $this->belongsToMany(
            'App\Component', // Tabla destino
            'product_components', // Nombre de tabla intermedia
            'product_id', // ID del origen
            'component_id'
        ); // ID del destino
    }

    public function components()
    {
        return $this->hasMany('App\ProductComponent', 'product_id');
    }

    public function category()
    {
        return $this->belongsTo('App\ProductCategory', 'product_category_id');
    }

    public function statelessCategory()
    {
        return $this->belongsTo('App\ProductCategory', 'product_category_id')->withTrashed();
    }

    public function product_details()
    {
        return $this->hasMany('App\ProductDetail', 'product_id');
    }

    public function compatibles()
    {
        return $this->belongsToMany('App\Product', 'product_compatibilities', 'product_id_origin', 'product_id_compatible')
            ->withPivot('description', 'status');
    }

    public function origins()
    {
        return $this->belongsToMany('App\Product', 'product_compatibilities', 'product_id_compatible', 'product_id_origin')
            ->withPivot('description', 'status');
    }

    public function getCategoryNameAttribute()
    {
        return $this->category['name'];
    }

    public function productSpecifications()
    {
        return $this->hasMany('App\ProductSpecification')->where('status', 1);
    }

    public function integrations()
    {
        return $this->hasMany('App\ProductIntegrationDetail');
    }
}
