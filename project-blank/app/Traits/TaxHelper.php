<?php

namespace App\Traits;

use App\Store;

trait TaxHelper
{
    /*
     * Recorre OrderDetails y retorna el detalle de sus impuestos.
     * Solo se arma el detalle de impuestos usando los productos (impuestos incluidos y adicionales).
     * Todos los valores NO consideran descuentos.
     * Retorna:
     *  - product_taxes: [
     *      'tax': id del StoreTax,
     *      'subtotal': valor del impuesto
     *      'tax_subtotal': subtotal del impuesto
     *    ]
     *  - subtotal: subtotal de la orden
     *  - no_tax_subtotal: subtotal 0%
     */
    public function getTaxValuesFromDetails(Store $store, $details)
    {
        $subtotal = 0;
        $noTaxSubtotal = 0;
        $productTaxes = [];

        // Solo considero impuestos incluidos y adicionales.
        foreach ($store->taxes as $tax) {
            if (!$tax->enabled) {
                continue;
            }
            if ($tax->type === 'invoice') {
                continue;
            }
            array_push($productTaxes, [
                'tax' => $tax->id,
                'name' => $tax->name,
                'percentage' => $tax->percentage,
                'subtotal' => 0,
                'tax_subtotal' => 0,
                'tax_type' => $tax->tax_type
            ]);
        }

        // Recorro los productos de la orden y calculo los valores para cada uno de sus impuestos.
        foreach ($details as $detail) {
            $taxValues = $detail->tax_values;
            if ($detail->base_value == null) {
                $detail->base_value = $taxValues['no_tax'];
                $detail->save();
            }
            if ($detail->total == null) {
                $detail->total = $taxValues['with_tax'];
                $detail->save();
            }
            // Verifico si el producto tiene impuestos
            if ($taxValues['has_taxes']) {
                $taxDetails = $taxValues['tax_details'];
                // Recorro los impeustos del producto
                foreach ($taxDetails as $taxOrderDetail) {
                    $taxInfo = $taxOrderDetail['tax'];
                    $taxSubtotal = $taxOrderDetail['subtotal'];
                    // Comparo con los impuestos de la tienda y acumulo los valores.
                    foreach ($productTaxes as $index => $totalTaxDetail) {
                        if ($totalTaxDetail['tax'] === $taxInfo['id']) {
                            // Almaceno los valores en el arreglo de impuestos de la tienda.
                            $productTaxes[$index]['subtotal'] += $taxSubtotal;
                            $productTaxes[$index]['tax_subtotal'] += $detail->base_value;
                        }
                    }
                }
            } else {
                // Sumo al subtotal de productos sin impuestos (Subtotal 0%).
                $noTaxSubtotal += $detail->base_value;
            }
            // Acumulo el valor base de la orden.
            $subtotal += $detail->base_value;
        }

        return [
            'product_taxes' => $productTaxes,
            'subtotal' => $subtotal,
            'no_tax_subtotal' => $noTaxSubtotal,
        ];
    }
}