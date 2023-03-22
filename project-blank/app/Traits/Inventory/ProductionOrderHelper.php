<?php

namespace App\Traits\Inventory;

// Libraries
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Models
use App\Store;
use App\StoreConfig;
use App\ComponentStock;
use App\Helper;
use App\StockMovement;
use App\InventoryAction;
use App\ComponentVariationComponent;
use App\UnitConversion;

// Helpers
use App\Traits\Logs\Logging;

trait ProductionOrderHelper
{

    /**
     * Consume stock de la receta la receta del insumo elaborado
     *
     * @param integer $storeId              Id de la tienda que tiene el insumo elaborado
     * @param integer $variationId          Id del insumo elaborado
     * @param float   $consumption          Valor a consumir del insumo elaborado
     * @param integer $consumableUnitId     Id de la unidad de medida del insumo elaborado
     *
     * @return array [
     *      'success' => boolean,
     *      'message' => string
     * ]
     *
     */
    public static function reduceConsumableRecipe($store, $variationId, $consumption, $consumableUnitId)
    {
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        if (is_null($storeConfig)) {
            return [
                "success" => false,
                "message" => "No se encontró la configuración de esta tienda",
                "data" => null
            ];
        }
        $inventoryStore = $storeConfig->getInventoryStore();

        try {
            $resultJSON = DB::transaction(
                function () use ($variationId, $inventoryStore, $consumableUnitId, $consumption) {
                    // Verificando que se encuentren disponibles las conversiones a la medida del insumo elaborado
                    // Obteniendo los insumos de la receta
                    $valueReference = null;
                    $subrecipeComponents = ComponentVariationComponent::where('component_origin_id', $variationId)
                        ->with([
                            'variationSubrecipe.unit',
                            'variationSubrecipe',
                        ])
                        ->sharedLock()
                        ->get();
                    if (count($subrecipeComponents) == 0) {
                        return [
                            "success" => false,
                            "message" => "Este insumo no tiene receta",
                            "data" => null
                        ];
                    }

                    $valueReference = $subrecipeComponents[0]["value_reference"];

                    if (is_null($valueReference) || $valueReference == 0) {
                        return [
                            "success" => false,
                            "message" => "Este insumo no tiene valor para el rendimiento estándar",
                            "data" => null
                        ];
                    }

                    $consumptionAction = InventoryAction::firstOrCreate(
                        ['code' => 'order_consumption'],
                        ['name' => 'Consumo por orden de producción', 'action' => 3]
                    );
                    $revokeAction = InventoryAction::firstOrCreate(
                        ['code' => 'revoked_order'],
                        ['name' => 'Revertir consumo por cancelamiento de orden de producción', 'action' => 1]
                    );
                    $originalCost = 0;
                    foreach ($subrecipeComponents as $subrecipeComponent) {
                        // Para nombre del item en los errores
                        $stockName = $subrecipeComponent->variationSubrecipe->name;

                        $consumptionStock = $subrecipeComponent->consumption * ($consumption / $valueReference);
                        $originalCost += $consumptionStock * ($subrecipeComponent->variationSubrecipe->cost / 100);
                        $componentStock = ComponentStock::where('component_id', $subrecipeComponent->component_destination_id)
                            ->where('store_id', $inventoryStore->id)->first();
                        if (is_null($componentStock)) {
                            throw new \Exception("No se encontró stock del insumo: " . $stockName);
                        }

                        // Crear nuevo movimiento(para insumos crudos)
                        $newRecordStockMovement = new StockMovement();
                        $newRecordStockMovement->inventory_action_id = $consumptionAction->id;
                        $newinitial = $lastCost = 0;

                        // Obtenemos el ultimo movimiento de ese componente para hallar el stock actual
                        $lastStockMovement = StockMovement::where('component_stock_id', $componentStock->id)
                            ->orderBy('id', 'desc')->first();
                        if (is_null($lastStockMovement)) {
                            throw new \Exception("No se encontró movimientos de inventario del insumo: " . $stockName);
                        }

                        // Check if last stock is enought (Restrictive Stock)
                        if ($inventoryStore->configs->restrictive_stock_production) {
                            $emptyStock = $lastStockMovement->final_stock <= 0;
                            $notEnought = $lastStockMovement->final_stock < $consumptionStock;
                            if ($emptyStock || $notEnought) {
                                throw new \Exception("No hay suficiente stock del item $stockName");
                            }
                        }

                        /**
                         * Se obtiene el ultimo movimiento que NO es anulacion, ya que este
                         * tiene el costo del item en el momento de la orden.
                         * El costo puede haber cambiado luego de dicho consumo
                         */
                        $lastNoRevokeStockMovement = StockMovement::where([
                            ['component_stock_id', '=', $componentStock->id],
                            ['inventory_action_id', '<>', $revokeAction->id]
                        ])->orderBy('id', 'desc')->first();
                        if (is_null($lastNoRevokeStockMovement)) {
                            throw new \Exception("No se encontró movimiento previo de inventario del insumo: " . $stockName);
                        }

                        $now = Carbon::now()->toDateTimeString();
                        $newinitial = $lastStockMovement->final_stock; // Del ultimo movimiento
                        $lastCost = $lastNoRevokeStockMovement->cost; // Del ultimo que NO es anulacion

                        $newRecordStockMovement->initial_stock = $newinitial;
                        $newRecordStockMovement->value = $consumptionStock;
                        $newRecordStockMovement->final_stock = $newinitial - $consumptionStock; // Es consumo
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
                        $newRecordStockMovement->created_at = $now;
                        $newRecordStockMovement->updated_at = $now;
                        $newRecordStockMovement->save();

                        // Update ComponentStock
                        $componentStock->stock = $newRecordStockMovement->final_stock;
                        $componentStock->save();
                    }

                    return [
                        "success" => true,
                        "message" => "Se consumió el stock de los insumos crudos",
                        "data" => 0,
                        "cost" => $originalCost
                    ];
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::simpleLogError(
                "ProductionOrderHelper: ERROR reduceConsumableRecipe: " . $e->getMessage(),
                ["elaborate_consumable_id" => $variationId, "consumption" => $consumption]
            );
            return [
                "success" => false,
                "message" => $e->getMessage(),
                "data" => null
            ];
        }
    }

    /**
     * Revertir consumo de stock en la receta del insumo elaborado
     *
     * @param integer         $storeId              Id de la tienda que tiene el insumo elaborado
     * @param ProductionOrder $productionOrder      Orden de producción
     * @param integer         $consumableUnitId     Id de la unidad de medida del insumo elaborado
     *
     * @return array [
     *      'success' => boolean,
     *      'message' => string
     * ]
     *
     */
    public static function revertConsumptionStockRecipe($store, $productionOrder, $consumableUnitId)
    {
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        if (is_null($storeConfig)) {
            return [
                "success" => true,
                "message" => "No se encontró la configuración de esta tienda"
            ];
        }
        $inventoryStore = $storeConfig->getInventoryStore();

        try {
            $resultJSON = DB::transaction(
                function () use ($productionOrder, $consumableUnitId, $inventoryStore) {
                    // Verificando que se encuentren disponibles las conversiones a la medida del insumo elaborado
                    // Obteniendo los insumos de la receta
                    $valueReference = null;
                    $subrecipeComponents = ComponentVariationComponent::where(
                        'component_origin_id',
                        $productionOrder->component_id
                    )
                        ->with([
                            'variationSubrecipe.unit',
                            'variationSubrecipe',
                        ])
                        ->sharedLock()
                        ->get();
                    if (count($subrecipeComponents) == 0) {
                        return [
                            "success" => true,
                            "message" => "Este insumo no tiene receta"
                        ];
                    }

                    $valueReference = $subrecipeComponents[0]["value_reference"];

                    if (is_null($valueReference) || $valueReference == 0) {
                        return [
                            "success" => true,
                            "message" => "Este insumo no tiene valor para el rendimiento estándar"
                        ];
                    }

                    // Conversiones disponibles
                    $consumableUnitConversions = UnitConversion::where(
                        'unit_origin_id',
                        $consumableUnitId
                    )
                        ->get();

                    foreach ($subrecipeComponents as $subrecipeComponent) {
                        $consumptionStock = $subrecipeComponent->consumption * ($productionOrder->quantity_produce / $valueReference);
                        $componentStock = ComponentStock::where('component_id', $subrecipeComponent->component_destination_id)
                            ->where('store_id', $inventoryStore->id)
                            ->first();
                        $now = Carbon::now()->toDateTimeString();
                        if (is_null($componentStock)) {
                            continue;
                        }

                        // Crear nuevo movimiento
                        $revokeAction = InventoryAction::firstOrCreate(
                            ['code' => 'revoked_order'],
                            ['name' => 'Revertir consumo por cancelamiento de orden de producción', 'action' => 1]
                        );
                        $newRecordStockMovement = new StockMovement();
                        $newRecordStockMovement->inventory_action_id = $revokeAction->id;
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
                        $newRecordStockMovement->value = $consumptionStock;
                        $newRecordStockMovement->final_stock = $newinitial + $consumptionStock; // Es revertir consumo
                        // Check Zero Lower Limit ***
                        if ($inventoryStore->configs->zero_lower_limit) {
                            if ($newinitial <= 0) {
                                $newRecordStockMovement->final_stock = $newinitial;
                            } else if ($newRecordStockMovement->final_stock < 0) {
                                $newRecordStockMovement->final_stock = 0;
                            }
                        }
                        $newRecordStockMovement->cost = $lastCost;
                        $newRecordStockMovement->component_stock_id = $componentStock->id;
                        $newRecordStockMovement->created_at = $now;
                        $newRecordStockMovement->updated_at = $now;
                        $newRecordStockMovement->save();

                        // Update ComponentStock
                        $componentStock->stock = $newRecordStockMovement->final_stock;
                        $componentStock->save();
                    }

                    return [
                        "success" => true,
                        "message" => "Se revirtió el consumo de stock de los insumos crudos"
                    ];
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::simpleLogError(
                "ProductionOrderHelper: ERROR revertConsumptionStockRecipe: " . $e->getMessage(),
                ["production_order_id" => $productionOrder->id]
            );
            return [
                "success" => false,
                "message" => $e->getMessage()
            ];
        }
    }
}
