<?php

namespace App\Traits;

use Log;
use App\Order;
use App\Store;
use App\Helper;
use App\Invoice;
use Carbon\Carbon;
use App\InvoiceItem;
use App\StockMovement;
use App\ComponentStock;
use App\OrderTaxDetail;
use App\InventoryAction;
use App\InvoiceTaxDetail;
use App\Traits\TaxHelper;
use App\Traits\Stocky\StockyRequest;
use App\ProductSpecification;
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;
use App\ComponentVariationComponent;
use App\Jobs\Datil\IssueInvoiceDatil;
use App\ProductSpecificationComponent;
use App\Jobs\Integrations\Siigo\SiigoSaveInvoice;
use App\Jobs\Integrations\Facturama\FacturamaInvoices;
use App\ProductionOrder;

trait OrderHelper
{
    use TaxHelper;

    // NOTA IMPORTANTE: SOLO USAR ESTA FUNCION ***ANTES*** QUE LA ORDEN HAYA SIDO PAGADA/PROCESADA/TERMINADA.
    /**
     * Recalcula los valores de una orden usando sus productos e impuestos.
     * - Genera y almacena los subtotales para cada tipo de impuesto.
     * - Genera y almacena los valores con/sin impuestos para la orden (base_value y total).
     */
    public function calculateOrderValues(Order $order)
    {
        // Inicializando valores de la orden.
        // return $order->orderDetails;
        $productTaxes = $this->getTaxValuesFromDetails($order->store, $order->orderDetails);
        $productTaxDetails = $productTaxes['product_taxes'];
        $orderBaseValue = $productTaxes['subtotal'];
        $noTaxSubtotal = $productTaxes['no_tax_subtotal'];
        $invoiceTaxes = [];

        // Inicializo un arreglo con los impuestos del store.
        foreach ($order->store->taxes as $tax) {
            if ($tax->enabled && $tax->type === 'invoice') {
                array_push($invoiceTaxes, $tax);
            }
        }

        // Almacenará el valor total de la orden.
        $total = 0;

        // Borro el detalle de impuestos de la orden para volver a crearlo.
        //$order->taxDetails()->delete();

        $delete = $order->taxDetails();
        foreach ($delete as $del) {
            OrderTaxDetail::where('id', $del->id)->delete();
        }

        // Recorro el arreglo de impuestos de la tienda.
        foreach ($productTaxDetails as $totalTaxDetail) {
            // Verifico si hay productos que gravan este impuesto en la orden.
            if ($totalTaxDetail['subtotal'] > 0) {
                // Creo un detalle de impuestos de la orden para este impuesto.
                $orderTaxDetail = new OrderTaxDetail();
                $orderTaxDetail->order_id = $order->id;
                $orderTaxDetail->store_tax_id = $totalTaxDetail['tax'];
                // Verifico si existe un porcentaje de descuento para realizar los calculos necesarios.
                if ($order->discount_percentage > 0) {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'] * (1 - ($order->discount_percentage / 100));
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'] *
                                            (1 - ($order->discount_percentage / 100));
                } else {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'];
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'];
                }
                // Sumo los impuestos de los productos al total de la orden.
                $total += $orderTaxDetail->subtotal;
                $orderTaxDetail->save();
            }
        }

        // Almacenará el valor total de descuento de la orden.
        $discountValue = 0;

        // El valor sin descuento es el valor base acumulado hasta ahora.
        $order->undiscounted_base_value = $orderBaseValue;

        // Calculo el valor del descuento usando el valor base y el porcentaje de descuento.
        if ($order->discount_percentage > 0) {
            $discountValue = $orderBaseValue * ($order->discount_percentage / 100);
            $order->base_value = $orderBaseValue - $discountValue;
        } else {
            $order->base_value = $orderBaseValue;
        }

        $order->discount_value = $discountValue;

        // Sumo el valor base al total de la orden.
        $total += $order->base_value;

        // Almacenará el valor de los impuestos de tipo factura (invoice).
        $totalInvoiceTaxes = 0;

        // Recorro los impuestos de la factura.
        foreach ($invoiceTaxes as $tax) {
            // Creo un detalle de impuestos de la orden para el impuesto de factura.
            $orderTaxDetail = new OrderTaxDetail();
            $orderTaxDetail->order_id = $order->id;
            $orderTaxDetail->store_tax_id = $tax->id;
            $orderTaxDetail->tax_subtotal = $order->base_value;
            // Verifico si existe un porcentaje de descuento para realizar los calculos necesarios.
            if ($order->discount_percentage > 0) {
                $orderTaxDetail->subtotal = $orderBaseValue * ($tax->percentage / 100) *
                                    (1 - ($order->discount_percentage / 100));
            } else {
                $orderTaxDetail->subtotal = $orderBaseValue * ($tax->percentage / 100);
            }
            // Sumo los impuestos de factura el total de la orden.
            $total += $orderTaxDetail->subtotal;
            $orderTaxDetail->save();

            // Acumulo los porcentajes de impuestos de factura.
            $totalInvoiceTaxes += $tax->percentage;
        }

        // Almaceno el valor total de la orden.
        $order->total = $total;
        // Calculo el subtotal para productos que no gravan impuestos (Subtotal 0%).
        if ($order->discount_percentage > 0) {
            $order->no_tax_subtotal = $noTaxSubtotal * (1 - ($order->discount_percentage / 100));
        } else {
            $order->no_tax_subtotal = $noTaxSubtotal;
        }
        $order->save();
        return $order;
    }

    /**
     * Recalcula los valores de una orden usando sus productos e impuesto principal del país.
     * - Genera y almacena los subtotales del impuesto.
     * - Genera y almacena los valores con/sin impuestos para la orden (base_value y total).
     */
    public function calculateOrderValuesIntegration(Order $order, $origin=null)
    {
        $store = $order->store;
        // Inicializando valores de la orden.
        $orderBaseValue = 0;
        $orderTotalValue = 0;
        $details = $order->orderDetails;
        $totalTaxDetails = [];

        // Inicializo un arreglo con los impuestos del store.
        foreach ($order->store->taxes as $tax) {
            if ($tax->enabled) {
                if ($tax->type !== 'invoice' && ($origin==null? $tax->is_main === 1: true)) {
                    array_push(
                        $totalTaxDetails,
                        [
                            'tax' => $tax->id,
                            'subtotal' => 0,
                            'tax_subtotal' => 0
                        ]
                    );
                }
            }
        }

        // Inicializo subtotal para productos sin impuestos (Subtotal 0%).
        $noTaxSubtotal = 0;

        // Recorro los productos de la orden y calculo los valores para cada uno de sus impuestos.
        foreach ($details as $detail) {
            $taxes = $detail->productDetail->product->taxes;
            $totalIncludedTax = 0;
            $hasTaxes = false;
            foreach ($taxes as $tax) {
                if ($tax->store_id == $store->id
                    && $tax->type === 'included'
                    && $tax->enabled
                    && $tax->is_main === 1
                ) {
                    $hasTaxes = true;
                    $totalIncludedTax += $tax->percentage;
                }
            }
            $totalValue = $detail->value * $detail->quantity;
            $ntValueRaw = $totalValue / (1 + ($totalIncludedTax / 100));
            $taxValueRaw = $totalValue;
            $taxValue = $taxValueRaw;
            $taxDetails = [];
            foreach ($taxes as $tax) {
                if ($tax->store_id == $store->id
                    && $tax->type === 'included'
                    && $tax->enabled
                    && $tax->is_main === 1
                ) {
                    array_push(
                        $taxDetails,
                        [
                            'tax' => [
                                'id' => $tax->id,
                                'name' => $tax->name,
                                'percentage' => $tax->percentage,
                            ],
                            'subtotal' => $ntValueRaw * ($tax->percentage / 100),
                        ]
                    );
                }
            }
            if ($detail->base_value !== $ntValueRaw) {
                $detail->base_value = $ntValueRaw;
                $detail->save();
            }
            if ($detail->total !== $taxValue) {
                $detail->total = $taxValue;
                $detail->save();
            }

            // Verifico si el producto tiene impuestos
            if ($hasTaxes) {
                // Recorro los impeustos del producto
                foreach ($taxDetails as $taxOrderDetail) {
                    $taxInfo = $taxOrderDetail['tax'];
                    $taxSubtotal = $taxOrderDetail['subtotal'];
                    // Comparo con los impuestos de la tienda y acumulo los valores.
                    foreach ($totalTaxDetails as $index => $totalTaxDetail) {
                        if ($totalTaxDetail['tax'] === $taxInfo['id']) {
                            // Almaceno los valores en el arreglo de impuestos de la tienda.
                            $totalTaxDetails[$index]['subtotal'] += $taxSubtotal;
                            $totalTaxDetails[$index]['tax_subtotal'] += $detail->base_value;
                        }
                    }
                }
            } else {
                // Sumo al subtotal de productos sin impuestos (Subtotal 0%).
                $noTaxSubtotal += $detail->base_value;
            }
            // Acumulo el valor base y el total de la orden.
            $orderBaseValue += $detail->base_value;
            $orderTotalValue += $detail->total;
        }

        // Almacenará el valor total de la orden.
        $total = 0;

        // Borro el detalle de impuestos de la orden para volver a crearlo.
        $order->taxDetails()->delete();

        // Recorro el arreglo de impuestos de la tienda.
        foreach ($totalTaxDetails as $totalTaxDetail) {
            // Verifico si hay productos que gravan este impuesto en la orden.
            if ($totalTaxDetail['subtotal'] > 0) {
                // Creo un detalle de impuestos de la orden para este impuesto.
                $orderTaxDetail = new OrderTaxDetail();
                $orderTaxDetail->order_id = $order->id;
                $orderTaxDetail->store_tax_id = $totalTaxDetail['tax'];
                // Verifico si existe un porcentaje de descuento para realizar los calculos necesarios.
                if ($order->discount_percentage > 0) {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'] * (1 - ($order->discount_percentage / 100));
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'] *
                                        (1 - ($order->discount_percentage / 100));
                } else {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'];
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'];
                }
                // Sumo los impuestos de los productos al total de la orden.
                $total += $orderTaxDetail->subtotal;
                $orderTaxDetail->save();
            }
        }

        // Almacenará el valor total de descuento de la orden.
        $discountValue = 0;

        // El valor sin descuento es el valor base acumulado hasta ahora.
        $order->undiscounted_base_value = $orderBaseValue;

        // Calculo el valor del descuento usando el valor base y el porcentaje de descuento.
        if ($order->discount_percentage > 0) {
            $discountValue = $orderBaseValue * ($order->discount_percentage / 100);
            $order->base_value = $orderBaseValue - $discountValue;
        } else {
            $order->base_value = $orderBaseValue;
        }

        $order->discount_value = $discountValue;

        // Sumo el valor base al total de la orden.
        $total += $order->base_value;

        // Almaceno el valor total de la orden.
        $order->total = $total;
        // Calculo el subtotal para productos que no gravan impuestos (Subtotal 0%).
        if ($order->discount_percentage > 0) {
            $order->no_tax_subtotal = $noTaxSubtotal * (1 - ($order->discount_percentage / 100));
        } else {
            $order->no_tax_subtotal = $noTaxSubtotal;
        }
        $order->save();
        return $order;
    }

    /** STATIC
     * Recalcula los valores de una orden usando sus productos e impuesto principal del país.
     * - Genera y almacena los subtotales del impuesto.
     * - Genera y almacena los valores con/sin impuestos para la orden (base_value y total).
     */
    public static function calculateOrderValuesIntegrationStatic(Order $order, $origin=null)
    {
        $store = $order->store;
        // Inicializando valores de la orden.
        $orderBaseValue = 0;
        $orderTotalValue = 0;
        $details = $order->orderDetails;
        $totalTaxDetails = [];

        // Inicializo un arreglo con los impuestos del store.
        foreach ($order->store->taxes as $tax) {
            if ($tax->enabled) {
                if ($tax->type !== 'invoice' && ($origin==null? $tax->is_main === 1: true)) {
                    array_push(
                        $totalTaxDetails,
                        [
                            'tax' => $tax->id,
                            'subtotal' => 0,
                            'tax_subtotal' => 0
                        ]
                    );
                }
            }
        }

        // Inicializo subtotal para productos sin impuestos (Subtotal 0%).
        $noTaxSubtotal = 0;

        // Recorro los productos de la orden y calculo los valores para cada uno de sus impuestos.
        foreach ($details as $detail) {
            $taxes = $detail->productDetail->product->taxes;
            $totalIncludedTax = 0;
            $hasTaxes = false;
            foreach ($taxes as $tax) {
                if ($tax->store_id == $store->id
                    && $tax->type === 'included'
                    && $tax->enabled
                    && $tax->is_main === 1
                ) {
                    $hasTaxes = true;
                    $totalIncludedTax += $tax->percentage;
                }
            }
            $totalValue = $detail->value * $detail->quantity;
            
            $ntValueRaw = $totalValue / (1 + ($totalIncludedTax / 100));
            $taxValueRaw = $totalValue;
            $taxValue = $taxValueRaw;
            
            $taxDetails = [];
            foreach ($taxes as $tax) {
                if ($tax->store_id == $store->id
                    && $tax->type === 'included'
                    && $tax->enabled
                    && $tax->is_main === 1
                ) {
                    array_push(
                        $taxDetails,
                        [
                            'tax' => [
                                'id' => $tax->id,
                                'name' => $tax->name,
                                'percentage' => $tax->percentage,
                            ],
                            'subtotal' => $ntValueRaw * ($tax->percentage / 100),
                        ]
                    );
                }
            }
            if ($detail->base_value !== $ntValueRaw) {
                $detail->base_value = $ntValueRaw;
                $detail->save();
            }
            if ($detail->total !== $taxValue) {
                $detail->total = $taxValue;
                $detail->save();
            }

            // Verifico si el producto tiene impuestos
            if ($hasTaxes) {
                // Recorro los impeustos del producto
                foreach ($taxDetails as $taxOrderDetail) {
                    $taxInfo = $taxOrderDetail['tax'];
                    $taxSubtotal = $taxOrderDetail['subtotal'];
                    // Comparo con los impuestos de la tienda y acumulo los valores.
                    foreach ($totalTaxDetails as $index => $totalTaxDetail) {
                        if ($totalTaxDetail['tax'] === $taxInfo['id']) {
                            // Almaceno los valores en el arreglo de impuestos de la tienda.
                            $totalTaxDetails[$index]['subtotal'] += $taxSubtotal;
                            $totalTaxDetails[$index]['tax_subtotal'] += $detail->base_value;
                        }
                    }
                }
            } else {
                // Sumo al subtotal de productos sin impuestos (Subtotal 0%).
                $noTaxSubtotal += $detail->base_value;
            }
            // Acumulo el valor base y el total de la orden.
            $orderBaseValue += $detail->base_value;
            $orderTotalValue += $detail->total;
        }

        // Almacenará el valor total de la orden.
        $total = 0;

        // Borro el detalle de impuestos de la orden para volver a crearlo.
        $order->taxDetails()->delete();

        // Recorro el arreglo de impuestos de la tienda.
        foreach ($totalTaxDetails as $totalTaxDetail) {
            // Verifico si hay productos que gravan este impuesto en la orden.
            if ($totalTaxDetail['subtotal'] > 0) {
                // Creo un detalle de impuestos de la orden para este impuesto.
                $orderTaxDetail = new OrderTaxDetail();
                $orderTaxDetail->order_id = $order->id;
                $orderTaxDetail->store_tax_id = $totalTaxDetail['tax'];
                // Verifico si existe un porcentaje de descuento para realizar los calculos necesarios.
                if ($order->discount_percentage > 0) {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'] * (1 - ($order->discount_percentage / 100));
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'] *
                                        (1 - ($order->discount_percentage / 100));
                } else {
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'];
                    $orderTaxDetail->tax_subtotal = $totalTaxDetail['tax_subtotal'];
                }
                // Sumo los impuestos de los productos al total de la orden.
                $total += $orderTaxDetail->subtotal;
                $orderTaxDetail->save();
            }
        }

        // Almacenará el valor total de descuento de la orden.
        $discountValue = 0;

        // El valor sin descuento es el valor base acumulado hasta ahora.
        $order->undiscounted_base_value = $orderBaseValue;

        // Calculo el valor del descuento usando el valor base y el porcentaje de descuento.
        if ($order->discount_percentage > 0) {
            $discountValue = $orderBaseValue * ($order->discount_percentage / 100);
            $order->base_value = $orderBaseValue - $discountValue;
        } else {
            $order->base_value = $orderBaseValue;
        }

        $order->discount_value = $discountValue;

        // Sumo el valor base al total de la orden.
        $total += $order->base_value;

        // Almaceno el valor total de la orden.
        $order->total = $total;
        // Calculo el subtotal para productos que no gravan impuestos (Subtotal 0%).
        if ($order->discount_percentage > 0) {
            $order->no_tax_subtotal = $noTaxSubtotal * (1 - ($order->discount_percentage / 100));
        } else {
            $order->no_tax_subtotal = $noTaxSubtotal;
        }
        $order->save();
        return $order;
    }

    public function processConsumptionAndStock($order, $variation, $consumption, $quantity, $user = null)
    {
        $order->load('store.configs');
        $inventoryStore = $order->store->configs->getInventoryStore();
        $componentStock = ComponentStock::where('component_id', $variation->id)
            ->where('store_id', $inventoryStore->id)
            ->first();
        if (!$componentStock) {
            $componentStock = new ComponentStock();
            $componentStock->component_id = $variation->id;
            $componentStock->store_id = $inventoryStore->id;
            $componentStock->stock = 0;
            $now = Carbon::now()->toDateTimeString();
            $componentStock->created_at = $now;
            $componentStock->updated_at = $now;
            $componentStock->save();
        }

        $now = Carbon::now()->toDateTimeString();
        $newRecordStockMovement = new StockMovement();
        $consumptionAction = InventoryAction::firstOrCreate(
            ['code' => 'order_consumption'],
            ['name' => 'Consumo por orden', 'action' => 3]
        );
        $revokeAction = InventoryAction::firstOrCreate(
            ['code' => 'revoked_order'],
            ['name' => 'Anulación de orden', 'action' => 1]
        );
        $newRecordStockMovement->inventory_action_id = $consumptionAction->id;
        $newinitial = $lastCost = 0;
        $lastStockMovement = StockMovement::where('component_stock_id', $componentStock->id)
                            ->orderBy('id', 'desc')->first();
        if ($lastStockMovement) {
            $newinitial = $lastStockMovement->final_stock;
        }
        // Ultimo movimiento que no es anulacion
        $lastNoRevokeStockMovement = StockMovement::where([
            ['component_stock_id', '=', $componentStock->id],
            ['inventory_action_id', '<>', $revokeAction->id]
        ])->orderBy('id', 'desc')->first();
        if ($lastNoRevokeStockMovement) {
            $lastCost = $lastNoRevokeStockMovement->cost;
        }
        $newRecordStockMovement->initial_stock = $newinitial;
        $movementValue = $consumption * $quantity;
        $newRecordStockMovement->value = $movementValue;
        $newRecordStockMovement->final_stock = $newinitial - $movementValue; // Es consumo
        // Check Zero Lower Limit
        if ($inventoryStore->configs->zero_lower_limit) {
            if ($newinitial <= 0) { // No baja de Cero o Negativo previo
                $newRecordStockMovement->final_stock = $newinitial;
            } else if ($newRecordStockMovement->final_stock < 0) { // Si da negativo, setea el 0
                $newRecordStockMovement->final_stock = 0;
            }
        }
        $newRecordStockMovement->cost = $lastCost;
        $newRecordStockMovement->component_stock_id = $componentStock->id;
        $newRecordStockMovement->order_id = $order->id; // **
        $newRecordStockMovement->created_by_id = $order->store_id; // **
        $newRecordStockMovement->user_id = $user ? $user->id : null;
        $newRecordStockMovement->created_at = $now;
        $newRecordStockMovement->updated_at = $now;
        $newRecordStockMovement->save();

        $calculatedStock = $componentStock->stock - $movementValue;
        // Check Zero Lower Limit
        if ($inventoryStore->configs->zero_lower_limit) {
            if ($componentStock->stock <= 0) { // No baja de Cero o Negativo previo
                $calculatedStock = $componentStock->stock;
            } else if ($calculatedStock < 0) { // Si da negativo, setea el 0
                $calculatedStock = 0;
            }
        }
        $componentStock->stock = $calculatedStock;
        $componentStock->save();

        $item = array();

        $item['current_stock'] = $calculatedStock.'';
        $item['quantity'] = abs($movementValue).'';
        $item['date'] = $componentStock->created_at.'';
        $item['movement_type'] = 2;
        $item['external_id'] = $variation->id.'';
        
        $component_id = $variation->id.'';
        $unitComponent = $variation;
        $unitPurchase = empty($variation->unit) ? 0 : $variation->unit->id;
        $unitConsume = empty($variation->unitConsume) ? 0 : $variation->unitConsume->id;
        $stocky_array = array();
        $item_stocky = array();
        $item_stocky['name'] = $variation->name;
        $item_stocky['external_id'] = $component_id.'';
        $item_stocky['sku'] = $variation->SKU;
        $item_stocky['purchase_unit_external_id'] = $unitPurchase.'';
        $item_stocky['consumption_unit_external_id'] = $unitConsume.'';
        $item_stocky['supplier_external_id'] = '';
        $item_stocky['cost'] = $componentStock->cost.'';
        $item_stocky['stock'] = $calculatedStock.'';
        $item_stocky['supplier_external_id'] = $component_id.'';

        array_push($stocky_array, $item_stocky);

        $units_array = array();
        $unit_stock_consumption = array();  
        $unit_stock_consumption['name'] = empty($unitComponent->unit) ? "" : $unitComponent->unit->name;
        $unit_stock_consumption['short_name'] = empty($unitComponent->short_name) ? "" : $unitComponent->unit->short_name;
        $unit_stock_consumption['external_id'] = $unitConsume.'';
        array_push($units_array, $unit_stock_consumption);
        $unit_stock_purchase = array();
        $unit_stock_purchase['name'] = empty($unitComponent->unitConsume) ? "" : $unitComponent->unitConsume->name;
        $unit_stock_purchase['short_name'] = empty($unitComponent->unitConsume) ? "" : $unitComponent->unitConsume->short_name;
        $unit_stock_purchase['external_id'] = $unitPurchase.'';
        array_push($units_array, $unit_stock_purchase);

        $provider_new = array();
        $provider_new['name'] = "";
        $provider_new['external_id'] = $component_id.'';
        $provider_array = array();
        array_push($provider_array, $provider_new);

        $items = array();
        $items['items'] = $stocky_array;
        $items['units'] = $units_array;
        $items['suppliers'] = $provider_array;
        StockyRequest::syncInventory($inventoryStore->id, $items);//


    }
    public static function processConsumptionAndStockStatic($order, $variation, $consumption, $quantity, $user = null)
    {
        $order->load('store.configs');
        $inventoryStore = $order->store->configs->getInventoryStore();
        $componentStock = ComponentStock::where('component_id', $variation->id)
            ->where('store_id', $inventoryStore->id)
            ->first();
        if (!$componentStock) {
            $componentStock = new ComponentStock();
            $componentStock->component_id = $variation->id;
            $componentStock->store_id = $inventoryStore->id;
            $componentStock->stock = 0;
            $now = Carbon::now()->toDateTimeString();
            $componentStock->created_at = $now;
            $componentStock->updated_at = $now;
            $componentStock->save();
        }

        $now = Carbon::now()->toDateTimeString();
        $newRecordStockMovement = new StockMovement();
        $consumptionAction = InventoryAction::firstOrCreate(
            ['code' => 'order_consumption'],
            ['name' => 'Consumo por orden', 'action' => 3]
        );
        $revokeAction = InventoryAction::firstOrCreate(
            ['code' => 'revoked_order'],
            ['name' => 'Anulación de orden', 'action' => 1]
        );
        $newRecordStockMovement->inventory_action_id = $consumptionAction->id;
        $newinitial = $lastCost = 0;
        $lastStockMovement = StockMovement::where('component_stock_id', $componentStock->id)
                            ->orderBy('id', 'desc')->first();
        if ($lastStockMovement) {
            $newinitial = $lastStockMovement->final_stock;
        }
        // Ultimo movimiento que no es anulacion
        $lastNoRevokeStockMovement = StockMovement::where([
            ['component_stock_id', '=', $componentStock->id],
            ['inventory_action_id', '<>', $revokeAction->id]
        ])->orderBy('id', 'desc')->first();
        if ($lastNoRevokeStockMovement) {
            $lastCost = $lastNoRevokeStockMovement->cost;
        }
        $newRecordStockMovement->initial_stock = $newinitial;
        $movementValue = $consumption * $quantity;
        $newRecordStockMovement->value = $movementValue;
        $newRecordStockMovement->final_stock = $newinitial - $movementValue; // Es consumo
        // Check Zero Lower Limit
        if ($inventoryStore->configs->zero_lower_limit) {
            if ($newinitial <= 0) {
                $newRecordStockMovement->final_stock = $newinitial;
            } else if ($newRecordStockMovement->final_stock < 0) {
                $newRecordStockMovement->final_stock = 0;
            }
        }
        $newRecordStockMovement->cost = $lastCost;
        $newRecordStockMovement->component_stock_id = $componentStock->id;
        $newRecordStockMovement->order_id = $order->id; // **
        $newRecordStockMovement->created_by_id = $order->store_id; // **
        $newRecordStockMovement->user_id = $user ? $user->id : null;
        $newRecordStockMovement->created_at = $now;
        $newRecordStockMovement->updated_at = $now;
        $newRecordStockMovement->save();

        $calculatedStock = $componentStock->stock - $movementValue;
        // Check Zero Lower Limit
        if ($inventoryStore->configs->zero_lower_limit) {
            if ($componentStock->stock <= 0) {
                $calculatedStock = $componentStock->stock;
            } else if ($calculatedStock < 0) {
                $calculatedStock = 0;
            }
        }
        $componentStock->stock = $calculatedStock;
        $componentStock->save();
    }

    public function reduceComponentsStock(Order $order)
    {
        $orderDetails = $order->orderDetails;
        foreach ($orderDetails as $orderProduct) {
            $productComponents = $orderProduct->productDetail->product->components->where('status', 1);
            foreach ($productComponents as $prodComponent) {
                if ($prodComponent->consumption && $prodComponent->consumption > 0) {
                    $componentVariation = $prodComponent->variation;

                    $this->processConsumptionAndStock(
                        $order,
                        $componentVariation,
                        $prodComponent->consumption,
                        $orderProduct->quantity
                    );
                    // $this->reduceComponentStockFromSubRecipe(
                    //     $order,
                    //     $componentVariation->id,
                    //     $prodComponent->consumption,
                    //     $orderProduct->quantity
                    // );
                    $this->addConsumptionToProductionOrder(
                        $componentVariation->id,
                        $prodComponent->consumption,
                        $orderProduct->quantity
                    );
                } else {
                    // Log::info("No tiene component consumption");
                }
            }
        }
    }
    public static function reduceComponentsStockStatic(Order $order)
    {
        $orderDetails = $order->orderDetails;
        foreach ($orderDetails as $orderProduct) {
            $productComponents = $orderProduct->productDetail->product->components->where('status', 1);
            foreach ($productComponents as $prodComponent) {
                if ($prodComponent->consumption && $prodComponent->consumption > 0) {
                    $componentVariation = $prodComponent->variation;

                    OrderHelper::processConsumptionAndStockStatic(
                        $order,
                        $componentVariation,
                        $prodComponent->consumption,
                        $orderProduct->quantity
                    );
                    // $this->reduceComponentStockFromSubRecipe(
                    //     $order,
                    //     $componentVariation->id,
                    //     $prodComponent->consumption,
                    //     $orderProduct->quantity
                    // );
                    OrderHelper::addConsumptionToProductionOrderStatic(
                        $componentVariation->id,
                        $prodComponent->consumption,
                        $orderProduct->quantity
                    );
                } else {
                    // Log::info("No tiene component consumption");
                }
            }
        }
    }

    public function reduceComponentsStockBySpecification(Order $order)//
    {
        $orderDetails = $order->orderDetails;
        foreach ($orderDetails as $detail) {
            $orderSpecifications = $detail->orderSpecifications;
            foreach ($orderSpecifications as $orderSpecification) {
                $componentConsumptions = $orderSpecification->specification->components->where('status', 1);
                $prodSpecs = $orderSpecification->orderDetail->productDetail->product
                    ->productSpecifications->where('specification_id', $orderSpecification->specification_id);
                if (count($prodSpecs) > 0) {
                    foreach ($prodSpecs as $prodSpec) {
                        $prodSpecsComp = ProductSpecificationComponent::where(
                            'prod_spec_id',
                            $prodSpec->id
                        )->with([
                            'variation' => function ($variation) {
                                $variation->with(['unit']);
                            }
                        ])->get();
                        if (count($prodSpecsComp)) {
                            foreach ($prodSpecsComp as $prodSpecComp) {
                                if ($prodSpecComp->consumption) {
                                    $componentVariation = $prodSpecComp->variation;
                                    $this->processConsumptionAndStock(
                                        $order,
                                        $componentVariation,
                                        $prodSpecComp->consumption,
                                        $orderSpecification->quantity
                                    );
                                    // $this->reduceComponentStockFromSubRecipe(
                                    //     $order,
                                    //     $componentVariation->id,
                                    //     $prodSpecComp->consumption,
                                    //     $orderSpecification->quantity
                                    // );
                                    $this->addConsumptionToProductionOrder(
                                        $componentVariation->id,
                                        $prodSpecComp->consumption,
                                        $orderSpecification->quantity
                                    );
                                } else {
                                    // Log::info("No tiene component consumption");
                                }
                            }
                        }
                    }
                } else {
                    foreach ($componentConsumptions as $specificationComponent) {
                        if ($specificationComponent->consumption) {
                            $componentVariation = $specificationComponent->variation;

                            $this->processConsumptionAndStock(
                                $order,
                                $componentVariation,
                                $specificationComponent->consumption,
                                $orderSpecification->quantity
                            );
                            // $this->reduceComponentStockFromSubRecipe(
                            //     $order,
                            //     $componentVariation->id,
                            //     $specificationComponent->consumption,
                            //     $orderSpecification->quantity
                            // );
                            $this->addConsumptionToProductionOrder(
                                $componentVariation->id,
                                $specificationComponent->consumption,
                                $orderSpecification->quantity
                            );
                        } else {
                            // Log::info("No tiene component consumption");
                        }
                    }
                }
            }
        }
    }
    public static function reduceComponentsStockBySpecificationStatic(Order $order)//
    {
        $orderDetails = $order->orderDetails;
        foreach ($orderDetails as $detail) {
            $orderSpecifications = $detail->orderSpecifications;
            foreach ($orderSpecifications as $orderSpecification) {
                $componentConsumptions = $orderSpecification->specification->components->where('status', 1);
                $prodSpecs = $orderSpecification->orderDetail->productDetail->product
                    ->productSpecifications->where('specification_id', $orderSpecification->specification_id);
                if (count($prodSpecs) > 0) {
                    foreach ($prodSpecs as $prodSpec) {
                        $prodSpecsComp = ProductSpecificationComponent::where(
                            'prod_spec_id',
                            $prodSpec->id
                        )->with([
                            'variation' => function ($variation) {
                                $variation->with(['unit']);
                            }
                        ])->get();
                        if (count($prodSpecsComp)) {
                            foreach ($prodSpecsComp as $prodSpecComp) {
                                if ($prodSpecComp->consumption) {
                                    $componentVariation = $prodSpecComp->variation;
                                    OrderHelper::processConsumptionAndStockStatic(
                                        $order,
                                        $componentVariation,
                                        $prodSpecComp->consumption,
                                        $orderSpecification->quantity
                                    );
                                    // $this->reduceComponentStockFromSubRecipe(
                                    //     $order,
                                    //     $componentVariation->id,
                                    //     $prodSpecComp->consumption,
                                    //     $orderSpecification->quantity
                                    // );
                                    OrderHelper::addConsumptionToProductionOrderStatic(
                                        $componentVariation->id,
                                        $prodSpecComp->consumption,
                                        $orderSpecification->quantity
                                    );
                                } else {
                                    // Log::info("No tiene component consumption");
                                }
                            }
                        }
                    }
                } else {
                    foreach ($componentConsumptions as $specificationComponent) {
                        if ($specificationComponent->consumption) {
                            $componentVariation = $specificationComponent->variation;

                            $this->processConsumptionAndStock(
                                $order,
                                $componentVariation,
                                $specificationComponent->consumption,
                                $orderSpecification->quantity
                            );
                            // $this->reduceComponentStockFromSubRecipe(
                            //     $order,
                            //     $componentVariation->id,
                            //     $specificationComponent->consumption,
                            //     $orderSpecification->quantity
                            // );
                            $this->addConsumptionToProductionOrder(
                                $componentVariation->id,
                                $specificationComponent->consumption,
                                $orderSpecification->quantity
                            );
                        } else {
                            // Log::info("No tiene component consumption");
                        }
                    }
                }
            }
        }
    }

    public function reduceComponentStockFromSubRecipe($order, $componentVariationId, $consumption, $quantity)
    {
        $order->load('store.configs');
        $inventoryStore = $order->store->configs->getInventoryStore();
        $subrecipeComponents = ComponentVariationComponent::where('component_origin_id', $componentVariationId)
            ->with('variationSubrecipe')
            ->get();
        $componentStock = ComponentStock::where('component_id', $componentVariationId)
            ->where('store_id', $inventoryStore->id)
            ->first();
        // Verificando que tenga stock para descontar el stock de los items de la subreceta
        if ($componentStock) {
            if ($componentStock->stock > 0) {
                foreach ($subrecipeComponents as $subrecipeComponent) {
                    if ($subrecipeComponent->consumption && $subrecipeComponent->consumption > 0) {
                        $componentVariation = $subrecipeComponent->variationSubrecipe;

                        $totalOrderConsumption = $quantity * $consumption;
                        $quantityConsumptionSubRecipe = 0;
                        if ($subrecipeComponent->value_reference != 0) {
                            $quantityConsumptionSubRecipe = $totalOrderConsumption / $subrecipeComponent->value_reference;
                        }

                        $this->processConsumptionAndStock(
                            $order,
                            $componentVariation,
                            $subrecipeComponent->consumption,
                            $quantityConsumptionSubRecipe
                        );
                    }
                }
            }
        }
    }

    public function addConsumptionToProductionOrder($componentVariationId, $consumption, $quantity)
    {
        $subrecipeComponents = ComponentVariationComponent::where('component_origin_id', $componentVariationId)
            ->with('variationSubrecipe')
            ->get();
        // Verificando que tenga stock para descontar el stock de los items de la subreceta
        if (count($subrecipeComponents) > 0) {
            $lastProductionOrder = ProductionOrder::where(
                'component_id',
                $componentVariationId
            )
                ->whereHas(
                    'statuses',
                    function ($statuses) {
                        // Esto es para filtrar y sólo obtener las órdenes de producción finalizadas
                        $statuses->where('status', 'finished');
                    }
                )
                ->orderBy('id', 'DESC')
                ->first();
            if (!is_null($lastProductionOrder)) {
                $lastProductionOrder->consumed_stock += $consumption * $quantity;
                $lastProductionOrder->save();
            }
        }
    }
    public static function addConsumptionToProductionOrderStatic($componentVariationId, $consumption, $quantity)
    {
        $subrecipeComponents = ComponentVariationComponent::where('component_origin_id', $componentVariationId)
            ->with('variationSubrecipe')
            ->get();
        // Verificando que tenga stock para descontar el stock de los items de la subreceta
        if (count($subrecipeComponents) > 0) {
            $lastProductionOrder = ProductionOrder::where(
                'component_id',
                $componentVariationId
            )
                ->whereHas(
                    'statuses',
                    function ($statuses) {
                        // Esto es para filtrar y sólo obtener las órdenes de producción finalizadas
                        $statuses->where('status', 'finished');
                    }
                )
                ->orderBy('id', 'DESC')
                ->first();
            if (!is_null($lastProductionOrder)) {
                $lastProductionOrder->consumed_stock += $consumption * $quantity;
                $lastProductionOrder->save();
            }
        }
    }

    public function dataHumanOrder(Order $order)
    {
        $newOrderDetails = collect([]);
        foreach ($order->orderDetails as $storedOrderDetail) {
            $storedOrderDetail->append('spec_fields');
            $newOrderDetails->push($storedOrderDetail);
        }
        return $newOrderDetails;
    }

    /**
     * Crea los detalles de taxes del invoice, los items del invoice y coloca la clave compuesta
     * de los detalles de la orden
     * - Usar despues de crear un Invoice
     * - Necesita del Invoice y de una Order
     */
    public function populateInvoiceTaxDetails(Order $order, Invoice $invoice)
    {
        if ($order->no_tax_subtotal > 0) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = 'Sin impuestos (0%)';
            $invoiceTaxDetail->tax_percentage = 0;
            $invoiceTaxDetail->subtotal = 0;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($order->no_tax_subtotal, 0);
            $invoiceTaxDetail->print = 1;
            $invoiceTaxDetail->save();
        }

        foreach ($order->taxDetails as $taxDetail) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = $taxDetail->storeTax->name;
            $invoiceTaxDetail->tax_percentage = $taxDetail->storeTax->percentage;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($taxDetail->tax_subtotal, 0);
            $invoiceTaxDetail->subtotal = Helper::bankersRounding($taxDetail->subtotal, 0);
            $invoiceTaxDetail->print = ($taxDetail->storeTax->type === 'invoice') ? 0 : 1;
            $invoiceTaxDetail->save();
        }

        $orderCollection = collect($order);
        $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey(
            $order->orderDetails->load('orderSpecifications.specification.specificationCategory')
        );
        $orderCollection->forget('orderDetails');
        $orderCollection->put('orderDetails', $groupedOrderDetails);
        foreach ($orderCollection['orderDetails'] as $orderDetail) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->invoice_id = $invoice->id;
            $invoiceItem->product_name = $orderDetail['name_product'];
            foreach ($orderDetail['order_specifications'] as $specification) {
                if ($specification['specification']['specification_category']['type'] == 2) {
                    $invoiceItem->product_name = $orderDetail['name_product'] . " " .
                                        $specification['name_specification'];
                    break;
                }
            }
            $invoiceItem->quantity = $orderDetail['quantity'];
            $invoiceItem->base_value = Helper::bankersRounding($orderDetail['base_value'], 0);
            $invoiceItem->total = Helper::bankersRounding($orderDetail['total'], 0);
            $invoiceItem->has_iva = $orderDetail['tax_values']['has_iva'];
            $invoiceItem->compound_key = $orderDetail['compound_key'];
            $invoiceItem->order_detail_id = $orderDetail['id'];
            $invoiceItem->save();
        }
    }
    /**
     * Crea los detalles de taxes del invoice, los items del invoice y coloca la clave compuesta
     * de los detalles de la orden
     * - Usar despues de crear un Invoice
     * - Necesita del Invoice y de una Order
     */
    public static function populateInvoiceTaxDetailsStatic(Order $order, Invoice $invoice)
    {
        if ($order->no_tax_subtotal > 0) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = 'Sin impuestos (0%)';
            $invoiceTaxDetail->tax_percentage = 0;
            $invoiceTaxDetail->subtotal = 0;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($order->no_tax_subtotal, 0);
            $invoiceTaxDetail->print = 1;
            $invoiceTaxDetail->save();
        }

        foreach ($order->taxDetails as $taxDetail) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = $taxDetail->storeTax->name;
            $invoiceTaxDetail->tax_percentage = $taxDetail->storeTax->percentage;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($taxDetail->tax_subtotal, 0);
            $invoiceTaxDetail->subtotal = Helper::bankersRounding($taxDetail->subtotal, 0);
            $invoiceTaxDetail->print = ($taxDetail->storeTax->type === 'invoice') ? 0 : 1;
            $invoiceTaxDetail->save();
        }

        $orderCollection = collect($order);
        $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey(
            $order->orderDetails->load('orderSpecifications.specification.specificationCategory')
        );
        $orderCollection->forget('orderDetails');
        $orderCollection->put('orderDetails', $groupedOrderDetails);
        foreach ($orderCollection['orderDetails'] as $orderDetail) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->invoice_id = $invoice->id;
            $invoiceItem->product_name = $orderDetail['name_product'];
            foreach ($orderDetail['order_specifications'] as $specification) {
                if ($specification['specification']['specification_category']['type'] == 2) {
                    $invoiceItem->product_name = $orderDetail['name_product'] . " " .
                                        $specification['name_specification'];
                    break;
                }
            }
            $invoiceItem->quantity = $orderDetail['quantity'];
            $invoiceItem->base_value = Helper::bankersRounding($orderDetail['base_value'], 0);
            $invoiceItem->total = Helper::bankersRounding($orderDetail['total'], 0);
            $invoiceItem->has_iva = $orderDetail['tax_values']['has_iva'];
            $invoiceItem->compound_key = $orderDetail['compound_key'];
            $invoiceItem->order_detail_id = $orderDetail['id'];
            $invoiceItem->save();
        }
    }

    public function getConsumptionDetails($orderProductSpecification, $productId)
    {
        $prodSpec = ProductSpecification::where(
            'product_id',
            $productId
        )
        ->where('specification_id', $orderProductSpecification->specification_id)
        ->first();
        if ($prodSpec) {
            $prodSpecComp = ProductSpecificationComponent::where(
                'prod_spec_id',
                $prodSpec->id
            )->with([
                'variation' => function ($variation) {
                    $variation->with(['unit']);
                }
            ])->first();
            if ($prodSpecComp) {
                $short_name = isset($prodSpecCom->variation->unit) ? $prodSpecComp->variation->unit->short_name : "";

                return "    Por Especificación:  " . $prodSpecComp->variation->name
                    . "  " . ($prodSpecComp->consumption * $orderProductSpecification->quantity)
                    . "(" . $short_name . ")"
                    . "\n";
            }
        }
        return "";
    }

    /**
     * Descripción: ejecuta las integración de billing de una tienda teniendo en cuenta los siguientes parámetros
     * @param object store obejeto de la clase App\Store
     * @param object invoice obejeto de la clase App\Invoice
     * @param string source indica la fuente desde donde se llama a la función
     * @param string execSpecificIntegration necesario si se quiere ejecutar una integración en específico
     * @param string forNoExecuteIntegration necesario si NO se quiere ejecutar una integración en específico
     * @param array otherData necesario si se quiere pasar información a una función externa
     *
     * @return void
    */
    public function prepareToSendForElectronicBilling(
        Store $store,
        Invoice $invoice,
        $source,
        $execSpecificIntegration = null,
        $forNoExecuteIntegration = null,
        $otherData = []
    ) {
        $integrations = StoreIntegrationToken::where("store_id", $store->id)->where('type', "billing")->get();

        if (!$integrations) {
            return;
        }

        foreach ($integrations as $integration) {
            /*Para ejecutar una única integración a petición */
            if (!empty($execSpecificIntegration)) {
                $integration->integration_name = $execSpecificIntegration;
            }

            switch ($integration->integration_name) {
                case "datil":
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_NORMAL) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new IssueInvoiceDatil($store, $invoice, 1))->onConnection('backoffice');
                    }

                    break;

                case AvailableMyposIntegration::NAME_SIIGO:
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_SIIGO) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_EATS || AvailableMyposIntegration::NAME_RAPPI || AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new SiigoSaveInvoice(
                            $otherData['cashier'],
                            $otherData['invoice'],
                            $store
                        ))->onConnection('backoffice');
                    }

                    break;

                case AvailableMyposIntegration::NAME_FACTURAMA:
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_FACTURAMA) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_EATS || AvailableMyposIntegration::NAME_RAPPI || AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new FacturamaInvoices('save', $invoice, $store, null))->onConnection('backoffice');
                    }

                    break;

                default:
                    Log::info("No tiene integracion de facturacion electronica");
                    break;
            }
        }
    }
    public static function prepareToSendForElectronicBillingStatic(
        Store $store,
        Invoice $invoice,
        $source,
        $execSpecificIntegration = null,
        $forNoExecuteIntegration = null,
        $otherData = []
    ) {
        $integrations = StoreIntegrationToken::where("store_id", $store->id)->where('type', "billing")->get();

        if (!$integrations) {
            return;
        }

        foreach ($integrations as $integration) {
            /*Para ejecutar una única integración a petición */
            if (!empty($execSpecificIntegration)) {
                $integration->integration_name = $execSpecificIntegration;
            }

            switch ($integration->integration_name) {
                case "datil":
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_NORMAL) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new IssueInvoiceDatil($store, $invoice, 1))->onConnection('backoffice');
                    }

                    break;

                case AvailableMyposIntegration::NAME_SIIGO:
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_SIIGO) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_EATS || AvailableMyposIntegration::NAME_RAPPI || AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new SiigoSaveInvoice(
                            $otherData['cashier'],
                            $otherData['invoice'],
                            $store
                        ))->onConnection('backoffice');
                    }

                    break;

                case AvailableMyposIntegration::NAME_FACTURAMA:
                    if ($forNoExecuteIntegration === AvailableMyposIntegration::NAME_FACTURAMA) {
                        break;
                    }

                    if ($source == AvailableMyposIntegration::NAME_EATS || AvailableMyposIntegration::NAME_RAPPI || AvailableMyposIntegration::NAME_NORMAL) {
                        dispatch(new FacturamaInvoices('save', $invoice, $store, null))->onConnection('backoffice');
                    }

                    break;

                default:
                    Log::info("No tiene integracion de facturacion electronica");
                    break;
            }
        }
    }

    /**
     * Sum the tips and return de result.
     * @param Array $payments
     *
     * @return Int
     */
    public function totalTips(array $payments = null)
    {
        $totalTip = 0;

        foreach ($payments as $payment) {
            $totalTip += (int) isset($payment['tip']) ? $payment['tip'] : 0;
        }
        return (int) $totalTip;
    }
}
