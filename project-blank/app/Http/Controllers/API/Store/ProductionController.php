<?php

namespace App\Http\Controllers\API\Store;

// Libraries
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Models
use App\Component;
use App\ComponentStock;
use App\ProductionOrder;
use App\ProductionOrderStatus;
use App\ProductionOrderReason;
use App\StoreConfig;
use App\StockMovement;
use App\InventoryAction;
use App\ComponentVariationComponent;

// Helpers
use App\Traits\AuthTrait;
use App\Traits\TimezoneHelper;
use App\Helper;
use App\Traits\FormatterHelper;
use App\Traits\Logs\Logging;
use App\Traits\Inventory\ProductionOrderHelper;
use App\Traits\Inventory\ComponentHelper;
use Log;

class ProductionController extends Controller
{
    use AuthTrait;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
    }

    /**
     *
     * Búsqueda de un insumo elaborado.
     *
     * @param string $text   Texto a buscar dentro de los insumos elaborados
     *
     * @return array         Insumos elaborados que coinciden con la búsqueda
     *
     */
    public function searchElaborateConsumable($text)
    {
        $store = $this->authStore;

        // Buscando components de la company a la que pertenece el store, usando Scout para el search
        // $resultsIds = Component::search($text)
        //     ->where('status', 1)
        //     ->get()
        //     ->pluck('id');

        // Buscando la data ya usando queries complejos, ya que scout sólo admite where simple
        $categoryIDs = $store->getComponentCategoryIDs();
        $results = Component::whereIn('component_category_id', $categoryIDs)
            ->where('name','like', '%' .$text. '%')
            ->where('status', 1)
            ->whereHas(
                'lastComponentStock',
                function ($componentStock) use ($store) {
                    $componentStock->where('store_id', $store->id);
                }
            )
            ->with([
                'componentStocks',
                'unit',
                'unitConsume',
                'subrecipe.variationSubrecipe',
                'subrecipe.variationSubrecipe.category',
                'subrecipe.variationSubrecipe.unit',
                'subrecipe.variationSubrecipe.unitConsume',
                'category'
            ])
            ->get();

        $dataFormatted = [];
        $resultSet = [];
        foreach ($results as $variation) {
            // Verificando se tiene insumos
            // Verficando si el insumo tiene receta para ser un insumo elaborado
            if (count($variation->subrecipe) > 0) {
                $searchNoAccent = Helper::remove_accents($text);
                $nameNoAccent = Helper::remove_accents($variation->name);
                // Calculando la distancia levenshtein para ver qué tan similares son ambos textos
                $distance = levenshtein($searchNoAccent, $nameNoAccent);
                $recipe = [];
                $referenceValue = 0;
                foreach ($variation->subrecipe as $subRecipe) {
                    if ($subRecipe->variationSubrecipe->category != null && $subRecipe->variationSubrecipe->category->status == 1) {
                        $referenceValue = $subRecipe->value_reference;
                        // Creando receta del insumo elaborado

                        @$unit_name = $subRecipe->variationSubrecipe->unitConsume->name ?? $subRecipe->variationSubrecipe->unit->name;
                        @$unit_short_name = $subRecipe->variationSubrecipe->unitConsume->short_name ?? $subRecipe->variationSubrecipe->unit->short_name;

                        array_push(
                            $recipe,
                            [
                                "id" => $subRecipe->variationSubrecipe->id,
                                "name" => $subRecipe->variationSubrecipe->name,
                                "unit" => [
                                    "name" => $unit_name,
                                    "short_name" => $unit_short_name,
                                ],
                                "consumption" => $subRecipe->consumption
                            ]
                        );
                    }
                }

                $u_consume = $variation->unitConsume;

                // Data del insumo elaborado
                $data = [
                    "id" => $variation->id,
                    "name" => $variation->name,
                    "unit" => [
                        "name" => $u_consume ? $u_consume->name : "",
                        "short_name" => $u_consume ? $u_consume->short_name : "",
                    ],
                    "reference_value" => $referenceValue,
                    "recipe" => $recipe,
                    "ldistance" => $distance
                ];
                // Agregando insumo elaborado a los resultados de búsqueda
                $resultSet[] = $data;
            }
        }

        // Ordenando los insumos por la distancia de levenshtein(más parecidos primero)
        usort($resultSet, function ($a, $b) {
            return $a["ldistance"] > $b["ldistance"];
        });

        return response()->json([
            'status' => 'Listando insumos elaborados',
            'results' => array_slice($resultSet, 0, 10)
        ], 200);
    }

    /**
     *
     * Crear una orden de producción
     *
     * @param Request $request   Data para crear la orden de producción
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function create(Request $request)
    {
        $store = $this->authStore;
        $user = $this->authUser;

        try {
            $resultJSON = DB::transaction(
                function () use ($request, $user, $store) {
                    $elaborateConsumable = Component::where(
                        'id',
                        $request["elaborate_consumable"]["id"]
                    )->first();

                    if (is_null($elaborateConsumable)) {
                        throw new \Exception("Este insumo elaborado no existe");
                    }

                    // Consumir stock de los insumos primarios
                    $resultConsumption = ProductionOrderHelper::reduceConsumableRecipe(
                        $store,
                        $elaborateConsumable->id,
                        $request["quantity_produce"],
                        $elaborateConsumable->metric_unit_id
                    );
                    if ($resultConsumption["success"] == false) {
                        throw new \Exception($resultConsumption["message"]);
                    }

                    // Obtener el código para la nueva orden de producción
                    // Se ignora las órdenes de producción creada automáticamente y que no han sido atendidas
                    $lastProductionOrder = ProductionOrder::where('store_id', $store->id)
                        ->whereHas(
                            'statuses',
                            function ($statuses) {
                                $statuses->where('status', 'in_process');
                            }
                        )
                        ->orderBy('code', 'DESC')
                        ->first();
                    $code = 1;
                    if (!is_null($lastProductionOrder)) {
                        $code = $lastProductionOrder->code;
                    }

                    $jsonRequest = $request->json()->all();
                    // Crear orden de producción
                    $productionOrder = new ProductionOrder();
                    $productionOrder->code = $code;
                    $productionOrder->component_id = $request["elaborate_consumable"]["id"];
                    $productionOrder->store_id = $store->id;
                    $productionOrder->original_content = $jsonRequest["elaborate_consumable"];
                    $productionOrder->quantity_produce = $request["quantity_produce"];
                    $productionOrder->cost = $resultConsumption["cost"];
                    // $productionOrder->sum_consumables = $resultConsumption["data"]; Por el momento no se va a usar esto para la merma
                    $productionOrder->save();

                    // Crear statuses de la orden de producción(Creado y En proceso)
                    $productionOrderStatus = new ProductionOrderStatus();
                    $productionOrderStatus->status = 'created';
                    $productionOrderStatus->production_order_id = $productionOrder->id;
                    $productionOrderStatus->user_id = $user->id;
                    $productionOrderStatus->save();
                    $productionOrderStatus = new ProductionOrderStatus();
                    $productionOrderStatus->status = 'in_process';
                    $productionOrderStatus->production_order_id = $productionOrder->id;
                    $productionOrderStatus->user_id = $user->id;
                    $productionOrderStatus->save();

                    // Cambiando la cantidad de stock del insumo elaborado
                    $consumableId = $request["elaborate_consumable"]["id"];
                    $componentStock = ComponentStock::where('component_id', $consumableId)
                        ->where('store_id', $store->id)
                        ->first();

                    if (is_null($componentStock)) {
                        $componentStock = new ComponentStock();
                        $componentStock->stock = $request["quantity_produce"];
                        $componentStock->alert_stock = 0;
                        $componentStock->store_id = $store->id;
                        $componentStock->component_id = $consumableId;
                        $componentStock->save();
                    }

                    $new_production_order = ProductionOrder::select(
                        'id',
                        'component_id',
                        'code',
                        'original_content',
                        'sum_consumables',
                        'quantity_produce',
                        'consumed_stock',
                        'cost',
                        'created_at'
                    )
                        ->where('id', $productionOrder->id)
                        ->with(['component', 'component.unit'])
                        ->first();

                    return response()->json([
                        "status" => "Orden de producción creada exitosamente",
                        "results" => $new_production_order,
                    ], 200);
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::logError(
                "ProductionController API Store: ERROR CREATE, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo crear la orden de producción: ' .  $e->getMessage(),
                'results' => null,
            ], 409);
        }
    }

    /**
     *
     * Devuelve el listado de las órdenes de producción terminadas/canceladas
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function listFinishedProductionOrders()
    {
        $store = $this->authStore;

        $productionOrders = ProductionOrder::select(
            'id',
            'component_id',
            'code',
            'original_content',
            'quantity_produce',
            'consumed_stock',
            'sum_consumables',
            'total_produced',
            'ullage',
            'cost',
            'observations',
            'created_at'
        )
            ->where('store_id', $store->id)
            ->whereHas(
                'statuses',
                function ($statuses) {
                    $statuses->where('status', 'finished')->orWhere('status', 'cancelled');
                }
            )
            ->with(['statuses.reason', 'component', 'component.unit'])
            ->orderBy('code', 'DESC')
            ->get();

        return response()->json([
            "status" => "Órdenes de producción terminadas / canceladas",
            "results" => $productionOrders,
        ], 200);
    }

    /**
     *
     * Devuelve el listado de las órdenes de producción en proceso
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function listInProccessProductionOrders()
    {
        $store = $this->authStore;

        $productionOrders = ProductionOrder::select(
            'id',
            'component_id',
            'code',
            'original_content',
            'quantity_produce',
            'consumed_stock',
            'sum_consumables',
            'cost',
            'created_at'
        )
            ->where('store_id', $store->id)
            ->whereDoesntHave(
                'statuses',
                function ($statuses) {
                    $statuses->where('status', 'finished')->orWhere('status', 'cancelled');
                }
            )
            ->whereHas(
                'statuses',
                function ($statuses) {
                    $statuses->where('status', 'in_process');
                }
            )
            ->with([
                'component',
                'component.unit',
                'component.componentStocks'
            ])
            ->orderBy('code', 'DESC')
            ->get();

        return response()->json([
            "status" => "Órdenes de producción en proceso",
            "results" => $productionOrders,
        ], 200);
    }

    /**
     *
     * Devuelve el listado de las órdenes de producción que necesitan crearse(stock bajo)
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function listNeededProductionOrders()
    {
        $store = $this->authStore;
        // $today = TimezoneHelper::localizedNowDateForStore($store);

        $productionOrders = ProductionOrder::select(
            'id',
            'component_id',
            'code',
            'original_content',
            'quantity_produce',
            'consumed_stock',
            'sum_consumables',
            'total_produced',
            'ullage',
            'cost',
            'observations',
            'created_at'
        )
            ->where('store_id', $store->id)
            ->whereHas(
                'statuses',
                function ($statuses) {
                    $statuses->where('status', 'finished');
                }
            )
            ->whereHas(
                'component.componentStocks',
                function ($stocks) use ($store) {
                    $stocks->where('store_id', $store->id);
                    // ->whereHas(
                    //     'dailyStocks',
                    //     function ($dailyStocks) use ($store, $today) {
                    //         $dailyStocks->where('day', $today->dayOfWeek)
                    //             ->whereColumn('stock', '<=', 'min_stock');
                    //     }
                    // );
                }
            )
            ->with([
                'statuses.reason',
                'component',
                'component.unit',
                'component.componentStocks',
                'component.componentStocks.dailyStocks'
            ])
            ->orderBy('code', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->groupBy('component_id');

        $uniqueProductionOrders = [];
        foreach ($productionOrders as $ordersComponents) {
            array_push(
                $uniqueProductionOrders,
                $ordersComponents[0]
            );
        }

        return response()->json([
            "status" => "Órdenes de producción necesarias",
            "results" => $uniqueProductionOrders,
        ], 200);
    }

    /**
     *
     * Terminar una orden de producción
     *
     * @param Request $request   Data para finalizar la orden de producción
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function finish(Request $request)
    {
        $store = $this->authStore;
        $user = $this->authUser;

        try {
            $resultJSON = DB::transaction(
                function () use ($request, $user, $store) {
                    $storeConfig = StoreConfig::where('store_id', $store->id)->first();
                    $inventoryStore = $storeConfig->getInventoryStore();
                    $productionOrder = ProductionOrder::where('id', $request["id"])
                        ->where('store_id', $store->id)
                        ->whereDoesntHave(
                            'statuses',
                            function ($statuses) {
                                $statuses->where('status', 'finished')->orWhere('status', 'cancelled');
                            }
                        )
                        ->first();

                    if (is_null($productionOrder)) {
                        return response()->json([
                            "status" => "Esta orden de producción no existe o ya está terminada",
                            "results" => null,
                        ], 409);
                    }

                    $productionOrder->total_produced = $request["total_produced"];
                    $productionOrder->observations = $request["observations"];
                    // Cálculo de merma
                    $ullage = $productionOrder->quantity_produce - $request["total_produced"];
                    if ($ullage < 0) {
                        $ullage = 0;
                    }
                    $productionOrder->ullage = $ullage;
                    $productionOrder->save();

                    // Crear statuses de la orden de producción(Finalizado)
                    $productionOrderStatus = new ProductionOrderStatus();
                    $productionOrderStatus->status = 'finished';
                    $productionOrderStatus->production_order_id = $productionOrder->id;
                    $productionOrderStatus->user_id = $user->id;
                    $productionOrderStatus->save();

                    // Agregar stock al insumo elaborado
                    $consumptionStock = $request["total_produced"];
                    $componentStockConsumable = ComponentStock::where(
                        'component_id',
                        $productionOrder->component_id
                    )
                        ->where('store_id', $inventoryStore->id)
                        ->first();

                    $now = Carbon::now()->toDateTimeString();
                    $component = Component::where('id', $productionOrder->component_id)->first();
                    if (is_null($componentStockConsumable)) {
                        throw new \Exception("No se encontró stock del insumo: $component->name");
                    }

                    // Crear nuevo movimiento
                    $newRecordStockMovement = new StockMovement();
                    $consumptionAction = InventoryAction::firstOrCreate(
                        ['code' => 'create_order_consumption'],
                        ['name' => 'Agregar stock del insumo elaborado por orden de producción', 'action' => 1]
                    );
                    $revokeAction = InventoryAction::firstOrCreate(
                        ['code' => 'revert_stock_revoked_order'],
                        ['name' => 'Revertir stock del insumo elaborado por cancelamiento de orden de producción', 'action' => 3]
                    );
                    $newRecordStockMovement->inventory_action_id = $consumptionAction->id;
                    $newinitial = $lastCost = 0;
                    $lastStockMovement = StockMovement::where('component_stock_id', $componentStockConsumable->id)
                        ->orderBy('id', 'desc')->first();
                    if ($lastStockMovement) {
                        $newinitial = $lastStockMovement->final_stock;
                    }
                    // Ultimo movimiento que no es anulacion
                    $lastNoRevokeStockMovement = StockMovement::where([
                        ['component_stock_id', '=', $componentStockConsumable->id],
                        ['inventory_action_id', '<>', $revokeAction->id] // Diferente de anulación de orden tambien
                    ])->orderBy('id', 'desc')->first();
                    if ($lastNoRevokeStockMovement) {
                        $lastCost = $lastNoRevokeStockMovement->cost;
                    }

                    // Para StockMovements y ComponentStock
                    $final_stock = $newinitial + $consumptionStock; // Se añaden existencias

                    // Check Zero Lower Limit ***
                    if ($inventoryStore->configs->zero_lower_limit) {
                        if ($newinitial <= 0) {
                            $final_stock = $newinitial;
                        } else if ($final_stock < 0) {
                            $final_stock = 0;
                        }
                    }

                    $newRecordStockMovement->initial_stock = $newinitial;
                    $newRecordStockMovement->value = $consumptionStock;
                    $newRecordStockMovement->final_stock = $final_stock; // ***
                    $newRecordStockMovement->cost = $lastCost;
                    $newRecordStockMovement->component_stock_id = $componentStockConsumable->id;
                    $newRecordStockMovement->created_by_id = null;
                    $newRecordStockMovement->user_id = $user->id;
                    $newRecordStockMovement->created_at = $now;
                    $newRecordStockMovement->updated_at = $now;
                    $newRecordStockMovement->save();

                    // Avoid negative stock
                    $componentStockConsumable->stock = $final_stock; // ***
                    $componentStockConsumable->save();

                    // Seteando el costo del insumo elaborado
                    $costWasUpdated = false;
                    if ($productionOrder->total_produced > 0) {
                        $overridedCost = ComponentHelper::getComponentCost($component->id, $inventoryStore->id);
                        if ($overridedCost) {
                            $production_factor = $productionOrder->total_produced / $productionOrder->quantity_produce;
                            $newItemCost = round($overridedCost / $production_factor, 4);
                            $componentStockConsumable->cost = $newItemCost;
                            // Modificamos el movimiento de "Añadir existencias" del item elaborado
                            $newRecordStockMovement->cost = $newItemCost;
                            $newRecordStockMovement->save();
                            $costWasUpdated = true;
                        }
                    }
                    $componentStockConsumable->save();

                    $addMsg = $costWasUpdated ? "El costo del item fue actualizado debido a la merma obtenida" : "";
                    return response()->json([
                        "status" => "Orden de producción finalizada. $addMsg",
                        "results" => $productionOrder,
                    ], 200);
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::logError(
                "ProductionController API Store: ERROR FINISH, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => $e->getMessage(),
                'results' => null,
            ], 409);
        }
    }

    /**
     *
     * Cancelar una orden de producción
     *
     * @param Request $request   Data para finalizar la orden de producción
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function cancel(Request $request)
    {
        $store = $this->authStore;
        $user = $this->authUser;

        try {
            $resultJSON = DB::transaction(
                function () use ($request, $user, $store) {
                    $storeConfig = StoreConfig::where('store_id', $store->id)->first();
                    $inventoryStore = $storeConfig->getInventoryStore();

                    $productionOrder = ProductionOrder::where('id', $request["id"])
                        ->where('store_id', $store->id)
                        ->whereDoesntHave(
                            'statuses',
                            function ($statuses) {
                                $statuses->where('status', 'finished')->orWhere('status', 'cancelled');
                            }
                        )
                        ->first();

                    if (is_null($productionOrder)) {
                        return response()->json([
                            "status" => "Esta orden de producción no existe o ya está cancelada",
                            "results" => null,
                        ], 409);
                    }

                    $reason = ProductionOrderReason::where('id', $request["reason_id"])
                        ->first();

                    if (is_null($reason)) {
                        return response()->json([
                            "status" => "Este motivo de cancelación no existe",
                            "results" => null,
                        ], 409);
                    }

                    // Crear statuses de la orden de producción(Cancelado)
                    $productionOrderStatus = new ProductionOrderStatus();
                    $productionOrderStatus->status = 'cancelled';
                    $productionOrderStatus->production_order_id = $productionOrder->id;
                    $productionOrderStatus->user_id = $user->id;
                    $productionOrderStatus->reason_id = $request["reason_id"];
                    $productionOrderStatus->save();

                    // Revertir descuento de inventario para la razón correspondiente
                    if ($reason->type == 2) {
                        $elaborateConsumable = Component::where(
                            'id',
                            $productionOrder->component_id
                        )->first();
                        if (is_null($elaborateConsumable)) {
                            throw new \Exception("Este insumo elaborado no existe");
                        }
                        $resultRevert = ProductionOrderHelper::revertConsumptionStockRecipe(
                            $store,
                            $productionOrder,
                            $elaborateConsumable->metric_unit_id
                        );
                        if ($resultRevert["success"] == false) {
                            throw new \Exception($resultRevert["message"]);
                        }
                    }

                    return response()->json([
                        "status" => "Orden de producción cancelada",
                        "results" => $productionOrder,
                    ], 200);
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::logError(
                "ProductionController API Store: ERROR CANCEL, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo cancelar la orden de producción',
                'results' => null,
            ], 409);
        }
    }

    /**
     *
     * Devuelve el listado de los motivos para cancelar una orden de producción
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function getCancelReasons()
    {
        $store = $this->authStore;
        $ignoredReasons = [];

        $cancelReasons = ProductionOrderReason::select(
            'id',
            'reason',
            'type'
        )->get();

        foreach ($cancelReasons as $cancelReason) {
            $hasObservation = false;
            if ($cancelReason["type"] == ProductionOrderReason::CANCEL_OTHERS_NO_REVERT) {
                $hasObservation = true;
            }
            $cancelReason["with_observation"] = $hasObservation;
            unset($cancelReason["type"]);
        }

        return response()->json([
            "status" => "Motivos para cancelar orden de producción",
            "results" => $cancelReasons,
        ], 200);
    }

    public function getTirillaData($id, $consumption)
    {
        $subrecipeComponents = ComponentVariationComponent::where('component_origin_id', $id)
            ->with([
                'variationSubrecipe.unit',
                'variationSubrecipe',
            ])
            ->sharedLock()
            ->get();

        $counts = [];

        foreach ($subrecipeComponents as $key => $product) {
            $counts[] = [
                'id' => $product->id,
                'date' => Carbon::parse($product->created_at),
                'name' => $product->variationSubrecipe->name,
                'categorias' =>  $product->variationSubrecipe->category->name,
                'consumption' =>  $product->consumption * ($consumption / $product->value_reference),
                'unit' => $product->variationSubrecipe->unit->short_name,
            ];
        }
        return $counts;
    }

    /**
     *
     * Crear una orden de producción
     *
     * @param Request $request   Data para hacer formato de tirilla
     *
     * @return Response          Respuesta de PDF file
     *
     */
    public function getTirillaProductionPDF(Request $request)
    {
        if ($request->data == null) {
            $request->data = $request;
        }

        $id = $request->data['component_id'];

        $store = $this->authStore;
        $country_code = $store->country_code;

        $data = $request->data;

        $store_date = TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("Inventario tomado en " . TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString());

        // Primera hoja donde apracerán detalles del objetivo

        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Declaración de producción");
        $excel->getDefaultStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $excel->getDefaultStyle()->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $sheet->getPageMargins()
            ->setLeft(0.1)
            ->setRight(0.1)
            ->setTop(0.3)
            ->setBottom(0.3)
            ->setHeader(0.5);

        $excel->getActiveSheet()->getPageSetup()
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $excel->getActiveSheet()->getPageSetup()->setFitToWidth(1);
        $excel->getActiveSheet()->setShowGridLines(false);

        $lineaSheet = array();
        $nombreEmpresa = array();
        $ordenes = array();

        ###############  TITULO INICIO #################
        $titulo = $store->name;

        $nombreEmpresa['titulo1'] = $titulo;
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(12);
        $sheet->mergeCells('A1:G1');
        array_push($lineaSheet, $nombreEmpresa);

        $declaracion = array();
        $declaracion['un'] = "Declaración de producción";
        $orden_de_produccion = array();
        $orden_de_produccion['un'] = "Orden de producción # " . $data['id'];
        $producto_elaborado = array();
        $producto_elaborado['un'] = "Producto elaborado:" . " " . $data['component']['name'];
        $cantidad_producir = array();
        $cantidad_producir['un'] = round($data['quantity_produce'], 2) . " " . $data['component']['unit']['name'];
        $fecha = array();
        $fecha['un'] = "" . $data['created_at'];

        array_push($lineaSheet, $declaracion);
        array_push($lineaSheet, $orden_de_produccion);
        array_push($lineaSheet, $producto_elaborado);
        array_push($lineaSheet, $cantidad_producir);
        array_push($lineaSheet, $fecha);

        $sheet->getStyle('A2:G6')->getFont()->setBold(true)->setSize(12);
        $sheet->mergeCells('A2:G2');
        $sheet->mergeCells('A3:G3');
        $sheet->mergeCells('A4:G4');
        $sheet->mergeCells('A5:G5');
        $sheet->mergeCells('A6:G6');

        array_push($lineaSheet, $ordenes); #push linea 7
        array_push($lineaSheet, $ordenes); #push linea 8

        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            '',
            'item',
            '',
            'Unidad',
            '',
            'Receta estándar'
        );
        $campos = array();


        array_push($lineaSheet, $columnas); #Push linea 9
        array_push($lineaSheet, $campos); #Push linea 10

        $sheet->getStyle('A9:F9')->getFont()->setBold(true)->setSize(12);

        $sheet->getColumnDimension('b')->setWidth(20);
        $sheet->getColumnDimension('d')->setWidth(20);
        $sheet->getColumnDimension('f')->setWidth(20);

        $category_data = $this->getTirillaData($id, $data['quantity_produce']);

        $num_fila = 10; //Se empezará a ubicar los datos desde la fila 5 debido al logo

        foreach ($category_data as $d) {
            $datos = array();
            $datos["y"] = "";
            $datos["name"] = $d["name"];
            $datos["y1"] = "";
            $datos["consumption"] = $d['unit'];
            $datos["y2"] = "";
            $datos['unit'] = FormatterHelper::getNumberFormatByCountryCode($country_code, $d["consumption"]);
            $num_fila++; #8
            array_push($lineaSheet, $datos);
        }

        $final_line = array();
        $final_line["un1"] = "";
        $final_line["un"] = "____________________________";

        array_push($lineaSheet, array());
        $num_fila = $num_fila + 2;

        array_push($lineaSheet, $final_line);
        $sheet->mergeCells('B' . $num_fila . ':C' . $num_fila);
        $responsable = array();
        $responsable["un1"] = "";
        $responsable["un"] = "Responsable del proceso";

        $num_fila++;
        $sheet->mergeCells('B' . $num_fila . ':C' . $num_fila);
        array_push($lineaSheet, $responsable);

        $sheet->fromArray($lineaSheet);
        $excel->setActiveSheetIndex(0);

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Dompdf');

        $nombreArchivo = 'Inventario ' . TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->headers->set('Access-Control-Allow-Methods', 'POST');
        $response->headers->set('Access-Control-Allow-Headers', ' Origin, Content-Type, Accept, Authorization, X-Request-With');
        $response->headers->set('Access-Control-Allow-Credentials', ' true');
        $response->send();
    }
}
