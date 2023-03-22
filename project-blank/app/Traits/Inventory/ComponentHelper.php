<?php

namespace App\Traits\Inventory;

use Log;
use App\Component;
use App\ComponentStock;
use App\StockMovement;
use App\ProductComponent;

// Helpers
use App\Traits\Logs\Logging;

trait ComponentHelper
{
    /**
     *  Inserta los componentes que conforman la receta de los items
     * @param array $variations      Receta del item que se desea obtener la receta
     * @param array $componentStack  Array para tener una cuenta de los items ya obtenidos
     *                               para evitar dependencias ciclicas
     * @param integer $depth         Valor que controla la profundidad en la receta
     * @param integer $storeId       Id de la tienda
     * @return void
     */
    public static function injectComponentSubrecipe($variations, &$componentStack, &$depth, $storeId)
    {
        foreach ($variations as $variation) {
            if (!property_exists((object) $componentStack, $variation['id'])) {
                // Init
                $componentStack[$variation['id']] = 1;
                $depth += 1;
                // Check has dependencies
                if (count($variation->subrecipe) > 0) {
                    $subrecipes = $variation->subrecipe;
                    $formattedVariations = [];
                    foreach ($subrecipes as $subrecipe) {
                        $subvariation = Component::where('id', $subrecipe->component_destination_id)
                            ->with([
                                'subrecipe',
                                'componentStocks' => function ($cs) use ($storeId) { $cs->where('store_id', $storeId); }
                            ])
                            ->first();
                        $subvariation['value_reference'] = $subrecipe->value_reference;
                        $subvariation['consumption'] = $subrecipe->consumption;
                        array_push($formattedVariations, clone $subvariation);
                    }
                    $variation['variations'] = $formattedVariations;
                    unset($variation->subrecipe); // **
                    if (count($variation->variations) > 0) {
                        ComponentHelper::injectComponentSubrecipe($variation->variations, $componentStack, $depth, $storeId);
                    }
                }
                else { // **
                    $variation['variations'] = [];
                    unset($variation->subrecipe);
                }
            } else {
                if ($depth == 0) {
                    $componentStack[$variation['id']] = array();
                }
            }
        }
        return $variations;
    }

    /**
     * Notas de "getComponentTotalRecipe":
     *  - "value_reference" = "rendimiento"
     *  - "consumption" = "cantidad"
     *
     * Casos:
     *  1. Si NO tiene "value_reference" y "consumption" significa que NO ES UNA DEPENDENCIA de otro item
     *     (es el item del que se quiere averiguar el costo)
     *     1.1 Si no tiene receta (es un item primario), se toma "Costo1 = ComponentStock->cost"
     *         y se lo aumenta al contador ("acum += Costo1")
     *     1.2 Si tiene receta, se hace un for de su receta y se llama a
     *        "getComponentTotalRecipe" con cada item del for
     *
     *  2. Si SÍ tiene "value_reference" y "consumption" significa que ES UNA DEPENDENCIA de otro item
     *     2.1 Si no tiene receta, se toma "Costo2 = (consumption/value_reference) * componentstock->cost"
     *         y se lo aumenta al contador ("acum += Costo2")
     *     2.2 Si tiene receta, se procede igual que en 1.2
     *
     *  Los casos 1.1 y 2.1 son iguales cuando (consumption/value_reference) = 1, entonces
     *  se deja el valor por defecto del rendimiento = cantidad = 1 y solo se cambian
     *  estos valores si es el caso 2.1
     *
     * ====================================================================================================
     *  Inserta los componentes que conforman la receta de los items
     * @param array $component          Item que se desea obtener el total de la receta
     * @param integer $acumTotal        Acumulador del total del costo del item
     * @return void
     */
    public static function getComponentTotalRecipe($component, &$acumTotal)
    {
        $component = gettype($component) === 'array'
            ? $component
            : json_decode($component->toJson(), true);

        $cantidad = $rendimiento = 1;
        if (isset($component['value_reference']) && isset($component['consumption'])) {
            $cantidad = $component['consumption'];
            $rendimiento = $component['value_reference'];
        }
        $factor = $cantidad / $rendimiento;

        $parent_factor = 1;
        if (isset($component['parent_factor'])) {
            $factor = $factor * $component['parent_factor'];
        }

        // Si tiene receta o no
        $countReceta = isset($component['variations']) ? count($component['variations']) : 0;
        if ($countReceta < 1) {
            $countStock = isset($component['component_stocks']) ? count($component['component_stocks']) : 0;
            if ($countStock > 0) {
                $factor_total = $parent_factor * $factor;
                $itemCost = $component['component_stocks'][0] ? $component['component_stocks'][0]['cost'] : 0;
                $acumTotal += round($factor_total * $itemCost, 2);
            }
        } else {
            foreach ($component['variations'] as $subcomponent) {
                $subcomponent['parent_factor'] = $factor;
                ComponentHelper::getComponentTotalRecipe($subcomponent, $acumTotal);
            }
        }
    }


    /**
     * Wrapper para injectComponentSubrecipe and getComponentTotalRecipe
     *
     * @param array $componentId        Id del Item que se desea obtener el total de su receta
     * @param integer $storeId          Id de la tienda
     * @return void
     */
    public static function getComponentCost ($componentId, $storeId) {
        try {
            $depth = 0;
            $accumulator = 0;
            $subrecipeStack = array();
            $component = Component::with('lastComponentStock')->where('id', $componentId)->first();
            ComponentHelper::injectComponentSubrecipe([$component], $subrecipeStack, $depth, $storeId);
            ComponentHelper::getComponentTotalRecipe($component, $accumulator);
            return $accumulator;
        } catch (\Exception $e) {
            Log::info("ComponentHelper: ERROR getComponentCost: " . $e->getMessage());
            Log::info(["componentId" => $componentId, "storeId" => $storeId]);
            return null;
        }
    }


    /**
     * Funcion para obtener el costo de un producto segun su receta con los precios finales de
     * los componentes 
     *
     * @param array $componentId        Id del Producto que se desea obtener el total de su receta
     * @param integer $storeId          Id de la tienda
     * @return costo
     */
    public static function getProductComponentsCost ($productId, $storeId, $startDate, $endDate) {
        try {

            $productValue = ProductComponent::where('id', $productId)
                ->get()
                ->map(function ($compId) use($storeId, $startDate, $endDate) {
                    $componentId = $compId->component_id;
                    $componentCmp = $compId->consumption;
                    $depth = 0;
                    $accumulator = 0;
                    $subrecipeStack = array();
                    $component = Component::where('id', $componentId)->first();

                    ComponentHelper::injectComponentSubrecipe([$component], $subrecipeStack, $depth, $storeId);
                    
                    $totalQuantity = ComponentHelper::getComponentTotalRecipeAvg ($component, $accumulator, $storeId, $startDate, $endDate) *
                                            $componentCmp;
                    return [
                        'cost' => $totalCost,
                    ];
                });

            $total = $productValue->reduce(function ($carry, $item) {
                return $carry + $item['cost'];
            }, 0);

            return $total;
        } catch (\Exception $e) {
            Log::info("ComponentHelper: ERROR getComponentCost: " . $e->getMessage());
            Log::info(["productId" => $productId, "storeId" => $storeId]);
            return null;
        }   
    }



    /**
     * Wrapper para injectComponentSubrecipe and getComponentTotalRecipeAvg
     *
     * @param array $componentId        Id del Item que se desea obtener el total de su receta
     * @param integer $storeId          Id de la tienda
     * @return void
     */
    public static function getComponentCostAvg ($componentId, $storeId, $startDate, $endDate) {
        try {
            $depth = 0;
            $accumulator = 0;
            $subrecipeStack = array();
            $component = Component::where('id', $componentId)->first();
            ComponentHelper::injectComponentSubrecipe([$component], $subrecipeStack, $depth, $storeId);
            ComponentHelper::getComponentTotalRecipeAvg ($component, $accumulator, $storeId, $startDate, $endDate);
            return $accumulator;
        } catch (\Exception $e) {
            Log::info("ComponentHelper: ERROR getComponentCost: " . $e->getMessage());
            Log::info(["componentId" => $componentId, "storeId" => $storeId]);
            return null;
        }
    }


    /**
     * Funcion para obtener el costo de un producto segun su receta con los precios finales de
     * los componentes 
     *
     * @param integer $componentId        Id del Producto que se desea obtener el total de su receta
     * @param integer $storeId            Id de la tienda
     * @param Date $startDate             Fecha desde la toma del precio del producto
     * @param Date $endDate               Fecha final para la toma del precio del producto
     * @return costo
     */    
    public static function getComponentTotalRecipeAvg ($component, &$acumTotal, $storeId, $startDate, $endDate) {
        
        $component = gettype($component) === 'array'
            ? $component
            : json_decode($component->toJson(), true);

        $cantidad = $rendimiento = 1;
        if (isset($component['value_reference']) && isset($component['consumption'])) {
            $cantidad = $component['consumption'];
            $rendimiento = $component['value_reference'];
        }
        $factor = $cantidad / $rendimiento;

        $parent_factor = 1;
        if (isset($component['parent_factor'])) {
            $factor = $factor * $component['parent_factor'];
        }
        $componentId = $component["id"];
        // Si tiene receta o no
        $countReceta = isset($component['variations']) ? count($component['variations']) : 0;
        if ($countReceta < 1) {
            $countStock = isset($component['component_stocks']) ? count($component['component_stocks']) : 0;
            $factor_total = $parent_factor * $factor;
            if ($countStock >= 1) {
                $itemCost = $component['component_stocks'][0] ? $component['component_stocks'][0]['cost'] : 0;
                $acumTotal += round($factor_total * $itemCost, 2);
                $itemCost = ComponentHelper::getPromValue ($componentId, $storeId, $startDate, $endDate, false);
                $acumTotal += round($factor_total * $itemCost, 2);
            } else {
                $itemCost = ComponentHelper::getPromValue ($componentId, $storeId, $startDate, $endDate, false);
                $acumTotal += round($factor_total * $itemCost, 2);
            }
        } else {
            foreach ($component['variations'] as $subcomponent) {
                $subcomponent['parent_factor'] = $factor;
                ComponentHelper::getComponentTotalRecipeAvg ($subcomponent, $acumTotal, $storeId, $startDate, $endDate);
            }
        }    
    }


    /**
     * Funcion para obtener el costo promedio de un componente especifico 
     *
     * @param integer $componentId        Id del Producto que se desea obtener el total de su receta
     * @param integer $storeId            Id de la tienda
     * @param Date $startDate             Fecha desde la toma del precio del producto
     * @param Date $endDate               Fecha final para la toma del precio del producto
     * @param boolean $getLast            Si se entrega solo con el componentStock o se sigue la toma por promedio
     * @return costo
     */
    public static function getPromValue ($componentId, $storeId, $startDate, $endDate, $getLast) {

        $valueComponent = ComponentStock::select("id","cost")->where("component_id", $componentId)
                                        ->where("store_id", $storeId)->get();
        
        if ($getLast) return $valueComponent->pluck('cost')[0];

        $valueComponentIds = $valueComponent->pluck('id');
        $promCost = 0;
        $promCost = StockMovement::where('component_stock_id', $valueComponentIds)
                                ->whereBetween('created_at', [$startDate, $endDate])
                                ->avg('cost');

        if(! $promCost ) {
            $promCost = StockMovement::select('cost')->where('component_stock_id', $valueComponentIds)
                                ->where('created_at', '<', $endDate)
                                ->latest('created_at')->first();
            $promCost = $promCost ? (float) $promCost["cost"] : 0 ;
        }

        return $promCost;
    }

}
