<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductSpecification extends Model
{
    protected $fillable = ['product_id', 'specification_id', 'status', 'value'];

    protected $hidden = [
        'created_at', 'updated_at'
    ];

    protected $appends = [
        'tax_values'
    ];

    protected $casts = [
        'value' => 'float',
    ];

    public function getTaxValuesAttribute()
    {
        $taxes = $this->product->taxes;
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
        $ntValueRaw = $this->attributes['value'] / (1 + ($totalIncludedTax / 100));
        $ntValue = $ntValueRaw;
        $taxValueRaw = $this->attributes['value'] + ($ntValue * ($totalAdditionalTax / 100));
        $taxValue = $taxValueRaw;
        return [
            'no_tax_raw' => $ntValueRaw,
            'no_tax' => $ntValue,
            'with_tax' => $taxValue
        ];
    }

    public function specification()
    {
        return $this->belongsTo('App\Specification', 'specification_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Product', 'product_id');
    }

    public function componentConsumption()
    {
        return $this->hasMany('App\ProductSpecificationComponent', 'prod_spec_id');
    }
}
