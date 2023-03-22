<?php

namespace App;

use App\Helper;
use App\Traits\TimezoneHelper;
use App\TaxesTypes;
use Illuminate\Database\Eloquent\Model;

class OrderDetail extends Model
{
    protected $fillable = [
        'order_id',
        'product_detail_id',
        'quantity',
        'value',
        'name_product',
        'instruction',
        'invoice_name',
        'total',
        'base_value',
        'process_status',
        'created_at',
    ];

    protected $hidden = [
        'order_id',
        'updated_at',
    ];

    protected $appends = [
        'tax_values',
        'nt_value',
        'group',
    ];

    protected $casts = [
        'value' => 'float',
        'total' => 'float',
        'base_value' => 'float',
    ];

    public function getTaxValuesAttribute()
    {
        if (!$this->productDetail) {
            return null;
        }

        $store = $this->order->store;
        $taxes = $this->productDetail->product->taxes;

        $totalIncludedTax = 0;
        $totalAdditionalTax = 0;

        $totalIncludedTaxByValue = 0;
        $totalAdditionalTaxByValue = 0;

        $hasTaxes = false;
        foreach ($taxes as $tax) {
            if ($tax->store_id == $store->id) {
                if ($tax->type === 'included' && $tax->enabled) {
                    $hasTaxes = true;

                    if ($tax->percentage == 0 || null) {
                        $totalIncludedTaxByValue += $this->productDetail->tax_by_value;
                    } else {
                        $totalIncludedTax += $tax->percentage;
                    }
                }

                if ($tax->type === 'additional' && $tax->enabled) {
                    $hasTaxes = true;

                    if ($tax->percentage == 0 || null) {
                        $totalAdditionalTaxByValue += $this->productDetail->tax_by_value;
                    } else {
                        $totalAdditionalTax += $tax->percentage;
                    }
                }
            }
        }
        $totalValue = $this->attributes['value'] * $this->attributes['quantity'];
        $ntValueRaw = $totalValue / (1 + ($totalIncludedTax / 100));
        $ntValue = $ntValueRaw - $totalIncludedTaxByValue;
        $taxValueRaw = $totalValue + ($ntValue * ($totalAdditionalTax / 100));
        $taxValue = $taxValueRaw + $totalAdditionalTaxByValue;
        $taxDetails = [];
        $hasIva = false;
        foreach ($taxes as $tax) {
            if (!$tax->enabled) {
                continue;
            }
            array_push($taxDetails, [
                'tax' => [
                    'id' => $tax->id,
                    'name' => $tax->name,
                    'percentage' => $tax->percentage,
                    'tax_type' => $tax->tax_type
                ],
                'subtotal' => ($tax->percentage == 0 || null) ? $this->productDetail->tax_by_value : $ntValue * ($tax->percentage / 100)
            ]);
            if ($tax->name === "IVA" || $tax->tax_type === Helper::getTaxCodeByName(TaxesTypes::TAXES_CO, 'iva')) {
                $hasIva = true;
            }
        }
        if ($this->order->preorder) {
            if ($this->attributes['base_value'] !== $ntValue) {
                $this->attributes['base_value'] = $ntValue;
                $this->save();
            }
            if ($this->attributes['total'] !== $taxValue) {
                $this->attributes['total'] = $taxValue;
                $this->save();
            }
        }
        return [
            'no_tax' => $ntValue,
            'with_tax' =>  $taxValue,
            'tax_details' => $taxDetails,
            'has_iva' => $hasIva,
            'has_taxes' => $hasTaxes
        ];
    }

    public function getSpecFieldsAttribute()
    {
        $append = "";
        $productName = $this->name_product;
        $invoiceName = $this->invoice_name;
        $instruction = "";
        if ($this->instruction != "") {
            $instruction = "    " . $this->instruction;
        }

        $specInstructions = "";
        $categoriesAdded = [];
        $specsCollection = collect($this->orderSpecifications);
        $groupedSpecsByCategory = $specsCollection->groupBy("specification.specification_category_id");
        foreach ($groupedSpecsByCategory as $key => $specifications) {
            foreach ($specifications as $specification) {
                if ($specification->specification) {
                    $category = $specification->specification->specificationCategory;
                    if ($category != null && !$category->isSizeType()) {
                        if (!in_array($category->name, $categoriesAdded)) {
                            array_push($categoriesAdded, $category->name);
                            if ($specInstructions == "") {
                                $specInstructions =
                                    "    ---- " . $category->name . " ----" .
                                    "\n";
                            } else {
                                $specInstructions =
                                    $specInstructions .
                                    "    -- " . $category->name . " --" .
                                    "\n";
                            }
                        }
                        if ($category->shouldShowQuantity()) {
                            if ($specInstructions == "") {
                                $specInstructions = $specInstructions . "       " . $specification->quantity .
                                    " " . $specification->name_specification . "\n";
                            } else {
                                $specInstructions = $specInstructions . "       " . $specification->quantity .
                                    " " .  $specification->name_specification . "\n";
                            }
                        } else {
                            if ($specInstructions == "") {
                                $specInstructions = $specInstructions . "       " . $specification->name_specification . "\n";
                            } else {
                                $specInstructions = $specInstructions . "       " . $specification->name_specification . "\n";
                            }
                        }
                        continue;
                    }
                    $append = $append . " " . $specification->name_specification;
                }
            }
        }

        if ($append != "") {
            $productName = $productName . $append;
            $invoiceName = $invoiceName . $append;

            // 25 es el maximo establecido para el detalle de un producto en la factura
            if (strlen($invoiceName) > 25) {
                $invoiceName = mb_substr($invoiceName, 0, 22, "utf-8");
                $invoiceName = $invoiceName . "...";
            }
        }

        if ($specInstructions != "") {
            $instruction = (strpos($instruction, $specInstructions) !== false)
                ? $instruction
                : ((preg_match("/[a-zA-Z]/", $instruction) == 0)
                    ? $specInstructions
                    : $instruction . "\n" . $specInstructions);
        }

        // Instruccion no puede ser tan extensa
        // Comentado hasta tener todo separado ya que ahorita es con /n y no todo junto
        // if (strlen($instruction) > 150) {
        //     $instruction = mb_substr($instruction, 0, 150, "utf-8");
        //     $instruction = $instruction . "...";
        // }

        return [
            'name' => $productName,
            'invoice_name' => $invoiceName,
            'instructions' => $instruction
        ];
    }

    public function getGroupAttribute()
    {
        return $this->instruction . ' ' . $this->compound_key;
    }

    public function getInstruction()
    {
        return $this->instruction;
    }

    public function getNtValueAttribute()
    {
        if (isset($this->attributes['value'])) {
            return $this->attributes['value'] / (1 + Helper::$iva);
        } else {
            return 0;
        }
    }

    public function order()
    {
        return $this->belongsTo('App\Order', 'order_id');
    }

    public function productDetail()
    {
        return $this->belongsTo('App\ProductDetail', 'product_detail_id');
    }

    public function orderSpecifications()
    {
        return $this->hasMany('App\OrderProductSpecification', 'order_detail_id');
    }

    public function processStatus()
    {
        return $this->hasMany('App\OrderDetailProcessStatus', 'order_detail_id');
    }

    public function lastProcessStatus()
    {
        return $this->hasMany('App\OrderDetailProcessStatus', 'order_detail_id')->latest()->first();
    }

    public function isDispatched()
    {
        return $this->hasMany('App\OrderDetailProcessStatus', 'order_detail_id')->latest()->first()->isDispatched();
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }
}
