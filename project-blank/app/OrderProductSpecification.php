<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderProductSpecification extends Model
{
    protected $fillable = [
		'order_detail_id',
		'specification_id',
		'value',
		'name_specification',
        'quantity',
        'created_at',
	];

	protected $hidden = [
		'updated_at',
	];

	protected $appends = [
		'tax_values',
    ];

    protected $casts = [
        'value' => 'float',
    ];

	public function getTaxValuesAttribute() {
		$taxes = $this->orderDetail->productDetail->product->taxes;
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

	public function orderDetail() {
        return $this->belongsTo('App\OrderDetail', 'order_detail_id');
	}

	public function specification() {
        return $this->belongsTo('App\Specification', 'specification_id');
    }
}
