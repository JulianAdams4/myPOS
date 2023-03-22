<?php

namespace App;

use App\ComponentStock;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use Log;

class Component extends Model
{
    use Searchable;

    protected $fillable = [
        'name', 'component_category_id', 'status', 'value', 'SKU', 'metric_unit_id', 'metric_unit_factor', 'conversion_metric_unit_id', 'conversion_metric_factor'
    ];

    protected $hidden = [
        'status', 'created_at', 'updated_at',
    ];

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ngrams' => utf8_encode((new TNTIndexer)->buildTrigrams($this->name)),
        ];
    }

    public function category()
    {
        return $this->belongsTo('App\ComponentCategory', 'component_category_id');
    }

    public function unit()
    {
        return $this->belongsTo('App\MetricUnit', 'metric_unit_id');
    }

    public function unitConsume()
    {
        return $this->belongsTo('App\MetricUnit', 'conversion_metric_unit_id');
    }

    public function productComponents()
    {
        return $this->hasMany('App\ProductComponent', 'component_id');
    }

    public function lastComponentStock()
    {
        return $this->hasOne('App\ComponentStock')->latest();
    }

    public function componentStocks()
    {
        return $this->hasMany('App\ComponentStock');
    }

    public function subrecipe()
    {
        return $this->hasMany('App\ComponentVariationComponent', 'component_origin_id');
    }

    public function provider()
    {
        return $this->hasMany('App\InvoiceProviderDetail');
    }


}
