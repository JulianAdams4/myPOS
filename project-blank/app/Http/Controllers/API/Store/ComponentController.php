<?php

namespace App\Http\Controllers\API\Store;

use App\Component;
use App\Employee;
use App\ComponentCategory;
use App\ComponentStock;
use App\Helper;
use App\Http\Controllers\Controller;
use App\InventoryAction;
use App\PendingSync;
use App\BlindCount;
use App\BlindCountMovement;
use App\ComponentVariationComponent;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Log;
use App\Traits\LoggingHelper;
use App\Traits\TimezoneHelper;
use App\Traits\Inventory\ComponentHelper;
use App\Traits\Stocky\StockyRequest;
use App\StockMovement;
use App\DailyStock;
use App\Mail\BlindInventory;
use Illuminate\Support\Facades\Mail;
use App\ExportsExcel\ExcelCashierExpenses;
use App\ExportsExcel\ExcelOrdersByEmployee;
use App\Company;

class ComponentController extends Controller
{
    use AuthTrait, LoggingHelper;

    public $authUser;
    public $authEmployee;
    public $authStore;

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

    public function getComponentsByCompany(Request $request)
    {
        $rowsPerPage = 12;
        $store = $this->authStore;
        $search = $request->search ? $request->search : "";
        $cate_query = $request->category ? strtolower($request->category) : "";

        $offset = ($request->page['page'] * $rowsPerPage) - $rowsPerPage;
        if ($search === "") {
            //$components = Component::where('status', 1)->get()->pluck('id');
        } else {
            //$components = Component::search($search)->where('status', 1)->get()->pluck('id');
        }

        $components = Component::where('name','like','%'.$search.'%')
            ->whereHas(
                'category',
                function ($category) use ($store, $cate_query) {
                    if ($cate_query === "") {
                        $category->where('company_id', $store->company_id)->where('status', 1);
                    } else {
                        $category->where('company_id', $store->company_id)->where('status', 1)
                             ->where('search_string', 'LIKE', "%{$cate_query}%");
                    }
                }
            )->with(['componentStocks', 'category', 'unit'])
            ->orderBy('name', 'asc')->get();
            
        if(isset($request->page['header'])){
            if($request->ascending){
                $components = $components->sortBy('category.name')->values();
            }else{
                $components = $components->sortByDesc('category.name')->values();
            }
            
        }
        
            

        foreach ($components as $component) {
            $totalStock = 0;
            $filteredStock = collect([]);
            foreach ($component->componentStocks as $componentStock) {
                if ($componentStock->store_id != $store->id) {
                    continue;
                }
                $filteredStock->push($componentStock);
                $totalStock += $componentStock->stock;
            }
            unset($component->componentStocks);
            $component->componentStocks = $filteredStock;
            $component->total_stock = $totalStock;
        }

        $componentsPage = [];
        $componentsSlice = $components->slice($offset, $rowsPerPage);
        if ($offset > 0) {
            foreach ($componentsSlice as $componentSliceItem) {
                array_push($componentsPage, $componentSliceItem);
            }
        } else {
            $componentsPage = $componentsSlice;
        }
        return response()->json(
            [
                'status' => 'Listando componentes',
                'results' => [
                    'count' => count($components),
                    'data' => $componentsPage,
                ],
            ],
            200
        );
    }

    public function getComponentsByCompanywithAlert(Request $request)
    {
        $rowsPerPage = 5;
        $store = $this->authStore;
        $search = $request->search ? $request->search : "";
        $offset = ($request->page['page'] * $rowsPerPage) - $rowsPerPage;

        if ($search === "") {
           // $componentIds = Component::where('status', 1)->get()->pluck('id');
        } else {
            //$componentIds = Component::where('name', 'LIKE', '%' .$search. '%')->where('status', 1)->get()->pluck('id');
        }

        $categoryIDs = $store->getComponentCategoryIDs();
        $store_group = !$request['stores'] ? [$store->id] : (array) $request['stores'];

        $components = Component::whereIn('component_category_id', $categoryIDs)
            ->where('name','like', '%' .$search. '%')
            ->where('status',1)
            ->whereHas(
                'lastComponentStock',
                function ($lastStock) use ($store_group) {
                    $lastStock->whereIn('store_id', $store_group)
                        ->where(function ($query){
                            $query->where('alert_stock', '=', 'NULL')
                            ->orWhere('stock', '<=', 'alert_stock');
                        });
                }
            )
            ->with([
                'category', 'unit', 'subrecipe.variationSubrecipe',
                'componentStocks' => function ($q) use ($store) {
                    $q->where('store_id', $store->id)->with(['dailyStocks']);
                }
            ])
            ->orderBy('name', 'asc')
            ->get();

        foreach ($components as $component) {
            // Subrecipe
            $formattedSubrecipe = collect([]);
            foreach ($component->subrecipe as $subrecipeItem) {
                $destinationComponent = $subrecipeItem->variationSubrecipe;
                $destinationComponent['consumption'] = $subrecipeItem->consumption;
                $destinationComponent['value_reference'] = $subrecipeItem->value_reference;
                $formattedSubrecipe->push(clone $destinationComponent);
            }
            unset($component->subrecipe);
            $component->subrecipe = $formattedSubrecipe;
            // Stock
            $totalStock = 0;
            $filteredStock = collect([]);
            foreach ($component->componentStocks as $componentStock) {
                if (!in_array($componentStock->store_id, $store_group)) {
                    continue;
                }
                $filteredStock->push(clone $componentStock);
                $totalStock += $componentStock->stock;
            }
            unset($component->componentStocks);
            $component->componentStocks = $filteredStock;
            $component->total_stock = $totalStock;
            foreach ($component->subrecipe as $subcomponents) {
                if ($subcomponents->variationSubrecipe == null) {
                    continue;
                }
                $subcomponents->id = $subcomponents->variationSubrecipe->id;
            }
        }

        $componentsPage = [];
        $componentsSlice = $components->slice($offset, $rowsPerPage);
        if ($offset > 0) {
            foreach ($componentsSlice as $component) {
                array_push($componentsPage, $component);
            }
        } else {
            $componentsPage = $componentsSlice;
        }
        return response()->json(
            [
                'status' => 'Listando componentes',
                'results' => [
                    'count' => count($components),
                    'data' => $componentsPage
                ],
            ],
            200
        );
    }

    public function getComponentsByCompanyNormal(Request $request)
    {
        $rowsPerPage = 7;
        $store = $this->authStore;
        $search = $request->search ? $request->search : "";
        $offset = ($request->page['page'] * $rowsPerPage) - $rowsPerPage;

        if ($search === "") {
            $componentIds = Component::where('status', 1)->get()->pluck('id');
        } else {
            $componentIds = Component::where('name', 'LIKE', '%' .$search. '%')->where('status', 1)->get()->pluck('id');
        }

        $categoryIDs = $store->getComponentCategoryIDs();
        $store_group = !$request['stores'] ? [$store->id] : (array) $request['stores'];

        $components = Component::whereIn('component_category_id', $categoryIDs)
                    ->whereIn('id', $componentIds)
                    ->whereHas(
                        'lastComponentStock',
                        function ($variation) use ($store_group) {
                            $variation->where('stock', '>', 'alert_stock')->whereIn('store_id', $store_group);
                        }
                    )
                    ->with([
                        'category', 'unit', 'subrecipe.variationSubrecipe',
                        'componentStocks' => function ($q) use ($store) {
                            $q->where('store_id', $store->id)->with(['dailyStocks']);
                        }
                    ])
                    ->orderBy('name', 'asc')
                    ->get();

        foreach ($components as $component) {
            // Subrecipe
            $formattedSubrecipe = collect([]);
            foreach ($component->subrecipe as $subrecipeItem) {
                $destinationComponent = $subrecipeItem->variationSubrecipe;
                $destinationComponent['consumption'] = $subrecipeItem->consumption;
                $destinationComponent['value_reference'] = $subrecipeItem->value_reference;
                $formattedSubrecipe->push(clone $destinationComponent);
            }
            unset($component->subrecipe);
            $component->subrecipe = $formattedSubrecipe;
            // Stock
            $totalStock = 0;
            $filteredStock = collect([]);
            foreach ($component->componentStocks as $componentStock) {
                if (!in_array($componentStock->store_id, $store_group)) {
                    continue;
                }
                $filteredStock->push(clone $componentStock);
                $totalStock += $componentStock->stock;
            }
            unset($component->componentStocks);
            $component->componentStocks = $filteredStock;
            $component->total_stock = $totalStock;

            foreach ($component->subrecipe as $subcomponents) {
                if ($subcomponents->variationSubrecipe != null) {
                    $subcomponents->id = $subcomponents->variationSubrecipe->id;
                }
            }
        }

        $componentsPage = [];
        $componentsSlice = $components->slice($offset, $rowsPerPage);
        if ($offset > 0) {
            foreach ($componentsSlice as $componentSliceItem) {
                array_push($componentsPage, $componentSliceItem);
            }
        } else {
            $componentsPage = $componentsSlice;
        }
        return response()->json(
            [
                'status' => 'Listando componentes',
                'results' => [
                    'count' => count($components),
                    'data' => $componentsPage
                ],
            ],
            200
        );
    }

    public function getListOfInventories(Request $request)
    {
        $store = $this->authStore;
        $inventories = BlindCount::where('store_id', $store->id)->get();
        return response()->json([
            'status' => 'Listando componentes',
            'results' => $inventories,
        ], 200);
    }

    public function updateByBlindInventory(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $data = $request->items;

        $blindInventory = new BlindCount();
        $blindInventory->store_id = $store->id;
        $blindInventory->save();

        if (count($data)==0) {
            return response()->json([
                'status' => 'Error subiendo datos de inventorio ciego',
                'results' => 'Ha enivado un formulario sin items',
            ], 409);
        }

        $movements = [];
        try {
            foreach ($data as $component) {
                $component_id = $component['component_id'];

                $component_sku = $component['sku'];
                $new_stock = $component["value"];
                $componentStock = ComponentStock::where('store_id', $store->id)
                                            ->where('component_id', $component_id)
                                            ->with('component.unit')
                                            ->orderBy('id', 'desc')
                                            ->first();

                if (!$componentStock) {
                    $parentComponentStock = ComponentStock::where('component_id', $component_id)->first();

                    $componentStock = new ComponentStock();
                    $componentStock->component_id = $component_id;
                    $componentStock->alert_stock = $parentComponentStock->alert_stock;
                    $componentStock->stock = 0;
                    $componentStock->merma = $parentComponentStock->merma;
                    $componentStock->cost = $parentComponentStock->cost;
                    $componentStock->store_id = $store->id;
                    $componentStock->save();
                }

                $actual_cost = $componentStock->cost;
                $first_stock = $componentStock->stock;
                $componentStock->stock = $new_stock;
                if ($first_stock - $new_stock > 0) {
                    $action_id = InventoryAction::firstOrCreate([
                        'name' => 'Reajuste negativo por inventario ciego',
                        'action'     => 3, // Restar
                        'code' => 'reajuste negativo por IC',
                    ]);
                } else {
                    $action_id = InventoryAction::firstOrCreate([
                        'name' => 'Reajuste positivo por inventario ciego',
                        'action'     => 1, //Agregar
                        'code' => 'reajuste positivo por IC',
                    ]);
                }


                $inventoryMovement = new StockMovement();
                $inventoryMovement->inventory_action_id = $action_id->id;
                $initialStockMovementTarget = $prevCostLastMovementTarget = 0;
                $lastStockMovementTarget = StockMovement::where('component_stock_id', $componentStock->id)
                                            ->orderBy('id', 'desc')->first();
                if ($lastStockMovementTarget) {
                    $initialStockMovementTarget = $lastStockMovementTarget->final_stock;
                    $prevCostLastMovementTarget = $lastStockMovementTarget->cost;
                }

                $inventoryMovement->initial_stock = $first_stock;
                $inventoryMovement->value = abs($first_stock-$new_stock);
                $inventoryMovement->final_stock = $new_stock;
                $inventoryMovement->cost = $actual_cost;
                $inventoryMovement->component_stock_id = $componentStock->id;
                $inventoryMovement->created_by_id = $store->id;
                $inventoryMovement->user_id = $user->id;
                $inventoryMovement->save();

                //Aqui se crean los registros de BlindInventoryMovements
                $blindMovement = new BlindCountMovement();
                $blindMovement->stock_movement_id = $inventoryMovement->id;
                $blindMovement->blind_count_id = $blindInventory->id;
                $blindMovement->value = $first_stock - $new_stock;
                $blindMovement->cost = $actual_cost;
                $blindMovement->save();

                $item = array();

                $item['current_stock'] = $new_stock.'';
                $item['quantity'] = abs($first_stock-$new_stock).'';
                $item['date'] = $blindMovement->created_at.'';
                $item['movement_type'] = $first_stock - $new_stock > 0 ? 2 : 1;
                $item['external_id'] = $component_id.'';

                StockyRequest::syncUpdateStock($store->id, $item);

                $componentStock->save();

                $compose_result = [];
                $compose_result['id'] = $component_sku;
                $compose_result['diference'] = $first_stock - $new_stock;
                $compose_result['name'] = $component['name'];
                $compose_result['unidad'] = $componentStock->component->unit->name;
                $compose_result['exist_sist'] = $first_stock;
                $compose_result['exist_real'] = $new_stock;
                $compose_result['ult_costo_unit'] = $lastStockMovementTarget? $prevCostLastMovementTarget : 0;
                $compose_result['cost'] = $prevCostLastMovementTarget * ($first_stock - $new_stock);

                array_push($movements, $compose_result);
            }
        } catch (\Exception $e) {
            Log::info($e);
            return response()->json([
                'status' => 'Fallo al guardar por inventario ciego',
                'results' => []
            ], 500);
        }

        // Enviando email
        try {
            foreach ($store->mailRecipients as $mailRecipient) {
                $email = config('app.env') === 'local'
                    ? config('app.mail_development')
                    : $mailRecipient->email;

                if (strpos($email, '@xxx.xxx') !== false) { continue; }

                Mail::to($email)->send(new BlindInventory($store, $movements));

                if (config('app.env') === 'local') { break; }
            }
        } catch (\Exception $e) {
            Log::info('Error enviar emails de inventario ciego');
            Log::info($e);
        }

        // Aqui se pondra el resultado
        return response()->json([
            'status' => 'Listando componentes',
            'results' => 'OK',
        ], 200);
    }


    public function search(Request $request)
    {
        $resultsLimit = 20;
        $store = $this->authStore;
        $requestQuery = $request->searchQuery;
        $searchQuery = "%" . $requestQuery . "%";
        $components = Component::where('status', 1)
            ->where('name', 'like', $searchQuery)
            ->with([
                'unitConsume', 'subrecipe', 'category',
                'componentStocks' => function ($csQuery) use ($store) {
                    $csQuery->where('store_id', $store->id);
                }
            ])
            ->whereHas(
                'category', function ($q) use ($store) {
                    $q->where('company_id', $store->company_id);
                }
            )
            ->limit($resultsLimit)
            ->get();

        $depth = 0;
        $componentStack = array();
        ComponentHelper::injectComponentSubrecipe($components, $componentStack, $depth, $store->id);

        $results = [];
        foreach ($components as $component) {
            if ($component->status == 1) {
                $totalCost = 0;
                ComponentHelper::getComponentTotalRecipe($component, $totalCost);

                $unit = $unitId = null;
                $unitName = 'No definido';
                if ($component->unitConsume != null) {
                    $unit = $component->unitConsume;
                    $unitId = $component->unitConsume->id;
                    $unitName = $component->unitConsume->name;
                }
                array_push(
                    $results,
                    [
                        'id' => $component->id,
                        'name' => $component->name,
                        'unit_id' => $unitId,
                        'unit_name' => $unitName,
                        'consumption' => 0,
                        'unit' => $unit,
                        'component' => [ "id" => "new" ],
                        'component_stocks' => $component->componentStocks,
                        'product_components' => [[ "id" => "new", "consumption" => 0 ]],
                        'cost' => $totalCost,
                    ]
                );
            }
        }
        return response()->json([
            'status' => 'Listando componentes',
            'results' => $results,
        ], 200);
    }

    /**
     *  Nota:
     *  - Por cambios en el Inventario, no se agregan existencias
     *    al momento de crear/editar un item
     *  - Se crea stock en 0 para todas las tiendas de la compañía
     *  - Si el item tiene receta, se sobrescribe el costo con
     *    el costo total de la receta
     */
    public function create(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $company_id = $store->company->id;
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json(['status' => 'No tiene acceso a esta funcionalidad',], 404);
        }

        $request->validate([
            'item.name' => 'required',
            'item.cost' => 'required',
            'item.c_units' => 'required',
            'item.units' => 'required',
            'item.category_id' => 'required',
        ]);

        $component = Component::where('name', $request->item['name'])
            ->where('status', 1)
            ->whereHas('category', function ($category) use ($company_id) {
                return $category->where('company_id', $company_id);
            })
            ->first();
        if ($component) {
            return response()->json([
                'status' => 'Este item ya existe',
                'results' => null,
            ], 409);
        }

        $componentCategory = ComponentCategory::find($request->item['category_id']);
        if (!$componentCategory) {
            return response()->json([
                'status' => 'Esta categoría no existe',
                'results' => null,
            ], 409);
        }

        try {
            $componentJSON = DB::transaction(
                function () use ($request, $store, $user) {
                    $now = Carbon::now()->toDateTimeString();
                    $company = Company::where('id', $store->company->id)->with('stores')->first();
                    $companyStores = $company['stores']->toArray();

                    $newComponent = new Component();
                    $newComponent->name = $request->item['name'] ?: '';
                    $newComponent->SKU = $request->item['sku'] ?: null;
                    $newComponent->metric_unit_id = $request->item['units'] ?: null;
                    $newComponent->metric_unit_factor = $request->item['units_factor'] ?: null;
                    $newComponent->conversion_metric_unit_id = $request->item['c_units'] ?: null;
                    $newComponent->conversion_metric_factor = $request->item['c_units_factor'] ?: null;
                    $newComponent->component_category_id = $request->item['category_id'];
                    $newComponent->created_at = $now;
                    $newComponent->updated_at = $now;
                    $newComponent->save();

                    // Creando stocks del producto
                    $newStocks = array();
                    foreach ($companyStores as $compStore) {
                        $isCurrStore = $compStore['id'] === $store->id;
                        array_push(
                            $newStocks,
                            [
                                'stock' => 0,
                                'store_id' => $compStore['id'],
                                'component_id' => $newComponent->id,
                                'alert_stock' => $isCurrStore ? $request->item['alert_stock'] ?: null : null,
                                'cost' => $isCurrStore
                                    ? $request->item['cost'] ? Helper::getValueInCents($request->item['cost'], $store->country_code) : 0
                                    : 0, // **
                                'merma' => $isCurrStore ? $request->item['merma'] ?: null : null, // **
                                'created_at' => $now,
                                'updated_at' => $now
                            ]
                        );
                    }
                    ComponentStock::insert($newStocks);

                    $currentStock = ComponentStock::where('component_id', $newComponent->id)
                        ->where('store_id', $store->id)
                        ->first();

                    // Creando consumo por dia del producto
                    if ($request->item['day_consume']) {
                        $stocksPerDay = [];
                        foreach ((array) $request->item['day_consume'] as $stockPerDay) {
                            array_push(
                                $stocksPerDay,
                                [
                                    'component_stock_id' => $currentStock->id,
                                    'day' => $stockPerDay['day'],
                                    'min_stock' => $stockPerDay['min_stock'],
                                    'max_stock' => $stockPerDay['max_stock']
                                ]
                            );
                        }
                        DailyStock::insert($stocksPerDay);
                    }

                    // Creando subrecetas
                    $overridedCost = $currentStock->cost;
                    if ($request->item["subrecipe"]) {
                        $subrecipeItems = [];
                        foreach ((array) $request->item['subrecipe'] as $subrecipeItem) {
                            array_push(
                                $subrecipeItems,
                                [
                                    'component_origin_id' => $newComponent->id,
                                    'component_destination_id' => $subrecipeItem['id'],
                                    'consumption' => $subrecipeItem['consumption'],
                                    'value_reference' => $request->item["performance"] ?: null
                                ]
                            );
                        }
                        ComponentVariationComponent::insert($subrecipeItems);
                        // Dado que tiene receta, sobreescribimos el costo con el costo de la receta
                        $overridedCost = ComponentHelper::getComponentCost($newComponent->id, $store->id);
                        if ($overridedCost) {
                            $currentStock->cost = $overridedCost;
                            $currentStock->save(); // ***
                        }
                    }

                    $actionInventory = InventoryAction::firstOrCreate(
                        ['code' => 'receive'],
                        ['name' => 'Existencias recibidas', 'action' => 1]
                    );
                    $newRecordStockMovement = new StockMovement();
                    $newRecordStockMovement->inventory_action_id = $actionInventory->id;
                    $newRecordStockMovement->initial_stock = 0; // Recien creado
                    $newRecordStockMovement->value = $currentStock->stock;
                    $newRecordStockMovement->final_stock = $currentStock->stock;
                    $newRecordStockMovement->cost = $overridedCost;
                    $newRecordStockMovement->component_stock_id = $currentStock->id;
                    $newRecordStockMovement->created_by_id = $store->id;
                    $newRecordStockMovement->user_id = $user->id;
                    $newRecordStockMovement->save();

                    return response()->json([
                        "status" => "Item creado con éxito",
                        "results" => null,
                    ], 200);
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ComponentController API Store: ERROR GUARDAR ITEM, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo crear el item',
                'results' => "null",
            ], 409);
        }
    }

    public function delete(Request $request)
    {
        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }
        $component = Component::where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($store) {
                    $q->where('company_id', $store->company_id);
                }
            )
            ->where('id', $request->id_item)
            ->first();

        if ($component) {
            try {
                $componentJSON = DB::transaction(
                    function () use ($component) {
                        $component->status = 0;
                        $component->save();
                        return response()->json(
                            [
                                "status" => "Item borrado con éxito",
                                "results" => null,
                            ],
                            200
                        );
                    }
                );
                return $componentJSON;
            } catch (\Exception $e) {
                Log::info("ComponentController API Store: NO SE PUDO ELIMINAR EL ITEM");
                Log::info($e);
                return response()->json(
                    [
                        'status' => 'No se pudo eliminar el item',
                        'results' => "null",
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'El item no existe',
                    'results' => "null",
                ],
                409
            );
        }
    }

    public function infoItem($id)
    {
        $store = $this->authStore;

        $component = Component::where('status', 1)
            ->with(
                [
                    'category',
                    'componentStocks' => function ($stocks) use ($store) {
                        $stocks->where('store_id', $store->id);
                    },
                    'subrecipe' => function ($subrecipe) {
                        $subrecipe->with([
                            'variationOrigin' => function ($variationOrigin) {
                                $variationOrigin->with([
                                    'unit'
                                ]);
                            },
                            'variationSubrecipe' => function ($variationSubrecipe) {
                                $variationSubrecipe->with([
                                    'unit'
                                ]);
                            },
                        ]);
                    },
                ]
            )
            ->whereHas(
                'category',
                function ($q) use ($store) {
                    $q->where('company_id', $store->company_id);
                }
            )
            ->where('id', $id)
            ->first();

        if (!$component) {
            return response()->json(
                [
                    'status' => 'El item no existe',
                    'results' => null,
                ],
                409
            );
        }

        foreach ($component->variations as $variation) {
            $filteredStock = collect([]);
            foreach ($variation->componentStocks as $componentStock) {
                if ($componentStock->store_id != $store->id) {
                    continue;
                }
                $filteredStock->push($componentStock);
            }
            unset($variation->componentStocks);
            $variation->componentStocks = $filteredStock;
        }

        return response()->json(
            [
                'status' => 'Item info',
                'results' => $component,
            ],
            200
        );
    }

    public function deleteCategory($categoryId)
    {
        $store = $this->authStore;
        try {
            $categoryJSON = DB::transaction(function () use ($categoryId, $store) {
                $itemCategory = ComponentCategory::where('id', $categoryId)
                    ->where('company_id', $store->company_id)
                    ->first();
                if (!$itemCategory) {
                    return response()->json([
                        'status' => 'Esta categoría no existe'. $categoryId . ' '. $store->company_id,
                        'results' => null
                    ], 409);
                }
                $itemCategory->status = 0;
                $itemCategory->updated_at = TimezoneHelper::localizedNowDateForStore($store);
                $itemCategory->delete(); // To check
                return response()->json([
                    'status' => 'Categoría eliminada exitosamente',
                    'results' => null
                ], 200);
            });
            return $categoryJSON;
        } catch (\Exception $e) {
            Log::info("ItemCategoryController API Store delete: ERROR AL ELIMINAR LA CATEGORIA, storeId: " . $store->id);
            Log::info($e->getMessage());
            Log::info($e->getFile());
            Log::info($e->getLine());
            return response()->json([
                'status' => 'No se pudo eliminar la categoría',
                'results' => null
            ], 409);
        }
    }

    /**
     *  Nota:
     *  - Actualiza la información excepto la cantidad de existencias
     *  - De igual forma, se añade un registro a la tabla de movimientos
     *    de tipo 'update_cost'
     *  - Se verifica si hay una producción:
     *    - Si tiene producción se deja el costo de la misma
     *    - Si no tiene producción, se verifica la recera y si tiene,
     *      se sobreescribe con ese costo. Caso contrario, se deja el costo
     *      que tiene el item
     */
    public function update(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }

        $request->validate([
            'item.name' => 'required',
            //'item.cost' => 'required',
            'item.c_units' => 'required',
            'item.units' => 'required',
            'item.category_id' => 'required',
        ]);

        $component = Component::where('status', 1)
            ->where('id', $request->item["id"])
            ->whereHas(
                'category',
                function ($q) use ($store) {
                    $q->where('company_id', $store->company_id);
                }
            )
            ->with('subrecipe')
            ->first();

        if (!$component) {
            return response()->json([
                'status' => 'El item no existe',
                'results' => "null",
            ], 409);
        }

        try {
            $componentJSON = DB::transaction(
                function () use ($component, $request, $store, $user) {
                    $now = Carbon::now()->toDateTimeString();
                    $company = Company::where('id', $store->company->id)->with('stores')->first();
                    $companyStores = $company['stores']->toArray();

                    $component->name = $request->item['name'] ?: $component->name; // Required name
                    $component->SKU = $request->item['sku'] ?: null;
                    $component->metric_unit_id = $request->item['units'] ?: null;
                    $component->metric_unit_factor = $request->item['units_factor'] ?: null;
                    $component->conversion_metric_unit_id = $request->item['c_units'] ?: null;
                    $component->conversion_metric_factor = $request->item['c_units_factor'] ?: null;
                    $component->component_category_id = $request->item['category_id'];
                    $component->updated_at = $now;
                    $component->save();

                    $componentStock = ComponentStock::where('store_id', $store->id)
                        ->where('component_id', $component->id)
                        ->first();
                    if (!$componentStock) {
                        return response()->json([
                            'status' => 'No hay stock para esta tienda',
                            'results' => "null",
                        ], 409);
                    }

                    // Actualizando stock
                    $componentStock->alert_stock = $request->item['alert_stock'] === null
                        ? null : $request->item['alert_stock'];
                    $componentStock->merma = $request->item['merma'] ?: null;
                    $componentStock->updated_at = $now;
                    $componentStock->save();

                    // Actualizando minimos y máximos
                    DailyStock::where('component_stock_id', $componentStock->id)->delete();
                    if ($request->item['day_consume']) {
                        $stocksPerDay = [];
                        foreach ((array) $request->item['day_consume'] as $stockPerDay) {
                            array_push(
                                $stocksPerDay,
                                [
                                    'component_stock_id' => $componentStock->id,
                                    'day' => $stockPerDay['day'],
                                    'min_stock' => $stockPerDay['min_stock'],
                                    'max_stock' => $stockPerDay['max_stock']
                                ]
                            );
                        }
                        DailyStock::insert($stocksPerDay);
                    }

                    // Actualizando subrecetas
                    ComponentVariationComponent::where('component_origin_id', $component->id)->delete();
                    if ($request->item["subrecipe"]) {
                        $subrecipeItems = [];
                        foreach ((array) $request->item['subrecipe'] as $subrecipeItem) {
                            array_push(
                                $subrecipeItems,
                                [
                                    'component_origin_id' => $component->id,
                                    'component_destination_id' => $subrecipeItem['id'],
                                    'consumption' => $subrecipeItem['consumption'],
                                    'value_reference' => $request->item["performance"] ?: null
                                ]
                            );
                        }
                        ComponentVariationComponent::insert($subrecipeItems);
                    }

                    return response()->json(
                        [
                            "status" => "Item modificado con éxito",
                            "results" => null,
                        ],
                        200
                    );
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ComponentController API Store: ERROR EDITAR ITEM, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo editar el item',
                'results' => "null",
            ], 409);
        }
    }

    public function inventoryActions()
    {
        $actions = InventoryAction::whereNotIn(
            'code',
            [
                'send_transfer',
                'receive_transfer',
                'order_consumption',
                'update_cost'
            ]
        )->get();
        return response()->json(
            [
                'status' => 'Acciones para inventario',
                'results' => $actions,
            ],
            200
        );
    }

    public function inventoryGroupUpload(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }
        try {
            $processJSON = DB::transaction(
                function () use ($request, $store, $user) {
                    $itemsInventory = $request->data;
                    $now = Carbon::now()->toDateTimeString();

                    foreach ($itemsInventory as $itemInventory) {
                        $componentStock = ComponentStock::where('component_id', $itemInventory["id"])
                            ->where('store_id', $store->id)
                            ->first();
                        if (!$componentStock) {
                            $componentStock = new ComponentStock();
                            $componentStock->component_id = $itemInventory['id'];
                            $componentStock->created_at = $now;
                            $componentStock->store_id = $store->id;
                            $componentStock->save();
                        }

                        $component = Component::where('id', $itemInventory["id"])->first();
                        if (!$component) {
                            return response()->json(
                                [
                                    "status" => "Este artículo/item no existe",
                                    "results" => null,
                                ],
                                409
                            );
                        }

                        $actionInventory = InventoryAction::where('id', $itemInventory["idAction"])->first();
                        if (!$actionInventory) {
                            return response()->json([
                                "status" => "Esta acción no se encuentra definida",
                                "results" => null,
                            ], 409);
                        }

                        // Stock Previo
                        $newinitial = $newFinal = 0;
                        $lastStockMovement = StockMovement::where('component_stock_id', $componentStock->id)
                            ->orderBy('id', 'desc')->first();
                        if ($lastStockMovement) {
                            $newinitial = $lastStockMovement->final_stock;
                        }

                        $new_cost = $itemInventory["cost"];
                        $new_quantity = $itemInventory["newStock"];
                        // Merma de Compra
                        if ($componentStock->merma !== null) {
                            $factor_merma = 1.00 - round($componentStock->merma / 100, 2);
                            $mermed_quantity = $new_quantity * $factor_merma;
                            $mermed_cost = ($new_cost * $new_quantity) / $mermed_quantity;
                            // Sobreescribimos el costo y la cantidad ingresada
                            $new_cost = $mermed_cost;
                            $new_quantity = $mermed_quantity;
                        }

                        // Calculo del nuevo stock
                        if ($actionInventory->action === 1) { // Agregar
                            $newFinal = $newinitial + $new_quantity;
                        } elseif ($actionInventory->action === 3) { // Restar
                            $newFinal = $newinitial - $itemInventory["newStock"];
                            // Check Zero Lower Limit
                            if ($store->configs->zero_lower_limit) {
                                if ($newinitial <= 0) { // No baja del Negativo previo
                                    $newFinal = $newinitial;
                                } else if ($newFinal < 0) { // Si da negativo, setea el 0
                                    $newFinal = 0;
                                }
                            }
                        } elseif ($actionInventory->action === 2) { // Reset
                            $newFinal = $new_quantity;
                        }

                        $newRecordStockMovement = new StockMovement();
                        $newRecordStockMovement->inventory_action_id = $actionInventory->id;
                        $newRecordStockMovement->initial_stock = $newinitial;
                        $newRecordStockMovement->value = $new_quantity;
                        $newRecordStockMovement->final_stock = $newFinal; // ***
                        $newRecordStockMovement->cost = $new_cost; // ***
                        $newRecordStockMovement->component_stock_id = $componentStock->id;
                        $newRecordStockMovement->created_by_id = $store->id;
                        $newRecordStockMovement->user_id = $user->id;
                        if (isset(($itemInventory["expirationDate"]))) {
                            $newRecordStockMovement->expiration_date = $itemInventory["expirationDate"];
                        }
                        $newRecordStockMovement->created_at = $now;
                        $newRecordStockMovement->updated_at = $now;
                        $newRecordStockMovement->save();

                        // Actualizamos el stock
                        $componentStock->stock = $newFinal;
                        // Actualizamos el costo si ha variado
                        if ($componentStock->cost != $itemInventory["cost"]) {
                            $componentStock->cost = $itemInventory["cost"];
                            if ($componentStock->cost < 0) {
                                $componentStock->cost = 0;
                            }
                        }
                        $componentStock->updated_at = $now;
                        $componentStock->save();
                    }
                    return response()->json(
                        [
                            "status" => "Información guardada con éxito",
                            "results" => null,
                        ],
                        200
                    );
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            Log::info("ComponentController API STORE : NO SE PUDO GUARDAR inventoryGroupUpload");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo guardar esta información',
                    'results' => null,
                ],
                409
            );
        }
    }

    public function inventorySync(Request $request)
    {
        $store = $this->authStore;

        try{            
            $item = StockyRequest::getStockyInventory($store->id);
            StockyRequest::syncInventory($store->id, $item);
        } catch (\Exception $e) {
        Log::info("ComponentController API STORE : NO SE PUDO ENVIAR EL MENSAJE inventorySync");
        Log::info($e);
        return response()->json(
            [
                'status' => 'No se pudo enviar esta información',
                'results' => null,
            ],
            409
        );
        }
    }


    public function inventoryPreviousInfoItem($id)
    {
        $store = $this->authStore;

        $stockDB = ComponentStock::where('component_id', $id)
            ->where('store_id', $store->id)->first();
        if (!$stockDB) {
            return response()->json([
                'status' => 'Información anterior',
                'results' => null,
            ], 200);
        }

        $lastStockMovement = StockMovement::where('component_stock_id', $stockDB->id)
            ->orderBy('created_at', 'desc')->first();
        if (!$lastStockMovement) {
            return response()->json([
                'status' => 'Información anterior',
                'results' => null,
            ], 200);
        }

        return response()->json([
            'status' => 'Información anterior',
            'results' => $lastStockMovement
        ], 200);
    }


    public function BlindInventoryXLS(Request $request)
    {
        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Inventario Ciego");
        $excel->getDefaultStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $excel->getDefaultStyle()->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $inventory_id = $request->inventory_id;

        $lineaSheet = array();
        $nombreEmpresa = array();
        $ordenes = array();
        ###############  TITULO INICIO #################
        $titulo_empresa = "Inventario Ciego ". Carbon::today();

        $num_fila = 5; //Se empezará a ubicar los datos desde la fila 5 debido al logo

        $nombreEmpresa['titulo'] = '';
        $nombreEmpresa['titulo2'] = '';
        $nombreEmpresa['titulo3'] = $titulo_empresa;
        array_push($lineaSheet, $nombreEmpresa);

        array_push($lineaSheet, $ordenes); #push linea 2
        array_push($lineaSheet, $ordenes); #push linea 3
        array_push($lineaSheet, $ordenes); #push linea 4

        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            'SKU',
            'Item',
            'Unidad',
            'Existencia Sist',
            'Existencia Real',
            'Diferencia',
            'Ult Costo Unitario',
            'Costo Total Diferencia',
        );
        $campos = array();

        array_push($lineaSheet, $columnas);
        array_push($lineaSheet, $campos);
        
        $sheet->mergeCells('A1:A3');

        $sheet->getStyle('A5:H5')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('a')->setWidth(30);
        $sheet->getColumnDimension('b')->setWidth(45);
        $sheet->getColumnDimension('c')->setWidth(15);
        $sheet->getColumnDimension('d')->setWidth(15);
        $sheet->getColumnDimension('e')->setWidth(15);
        $sheet->getColumnDimension('f')->setWidth(15);
        $sheet->getColumnDimension('g')->setWidth(15);
        $sheet->getColumnDimension('h')->setWidth(15);

        $estiloRight = array(
            'alignment' => array(
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            )
        );

        $category_data = $this->InventoryData($inventory_id);

        foreach ($category_data as $d) {
            $datos = array();
            $costo_total = $d['cost'] * $d["value"];
            $datos["device_id"] = $d["sku"] == ''? 'SKU No registrado' : $d["sku"];
            $datos["item"] = $d["name"];
            $datos["unidad"] = $d["unidad"];
            $datos["exist_sist"] = $d["exist_sist"];
            $datos["exist_real"] = $d["exist_real"];
            $datos["diferencia"] = $d["value"];
            $datos["ult_cost_unitario"] = $d["ult_costo"]==""? "0": $d["ult_costo"];
            $datos['costo_total_dif'] = $costo_total == ""? "0": $costo_total;
            $num_fila++; #8
            array_push($lineaSheet, $datos);
        }

        $nombreArchivo = 'Reporte de Transacciones ' . Carbon::today();
        $sheet->fromArray($lineaSheet);
        $excel->setActiveSheetIndex(0);
        ############### LOGO  ##############
        $imagenGacela = public_path() . '/images/logo.png';

        $objGacela = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $objGacela->setName('Sample image');
        $objGacela->setDescription('Sample image');
        $objGacela->setPath($imagenGacela);
        $objGacela->setWidthAndHeight(160, 75);
        $objGacela->setCoordinates('A1');
        $objGacela->setWorksheet($excel->getActiveSheet());
        ############## FIN LOGO #############

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Xls');

        $nombreArchivo = 'Inventario Ciego ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }


    public function InventoryData($report_id)
    {
        $store = $this->authStore;
        //get everything with eager loading
        $movements = BlindCountMovement::where('blind_count_id', '=', $report_id)
                                    ->with(['stockmovements.componentStock.component' => function ($query) {
                                        $query->select('id', 'name');
                                    }])
                                    ->with('stockmovements.componentStock.component.lastComponentStock')
                                    ->orderBy('id', 'desc')
                                    ->get();
        $counts = [];

        foreach ($movements as $key => $transaction) {
            $unit_name = $transaction->stockmovements->componentStock->component->unit;
            $counts[] = [
                'id' => $transaction->id3,
                'date' => Carbon::parse($transaction->created_at),
                'name' => $transaction->stockmovements->componentStock->component->name. " "
                . $transaction->stockmovements->componentStock->name,
                'cost' => $transaction->cost,
                'value'=> $transaction->value,
                'sku' => $transaction->stockmovements->componentStock->component->SKU,
                'ult_costo' => $transaction->stockmovements->componentStock->component->lastComponentStock->cost,
                'unidad' => $unit_name == "" ? "" : $unit_name->name,
                'exist_sist' => $transaction->stockmovements->initial_stock,
                'exist_real' => $transaction->stockmovements->final_stock,
            ];
        }

        return $counts;
    }


    /**
     * Not used yet
     */
    public function DownloadPDFInventory(Request $request)
    {
        $store = $this->authStore;
        $store_date = TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("Inventario tomado en ". TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString());

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Inventario Actual");
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
        $titulo_empresa = "Inventario de ". TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();

        $num_fila = 5; //Se empezará a ubicar los datos desde la fila 5 debido al logo

        $nombreEmpresa['titulo0'] = "";
        $nombreEmpresa['titulo1'] = $titulo_empresa;
        $sheet->getStyle('B1:F1')->getFont()->setBold(true)->setSize(16);
        $sheet->mergeCells('B1:F1');
        array_push($lineaSheet, $nombreEmpresa);

        array_push($lineaSheet, $ordenes); #push linea 2
        array_push($lineaSheet, $ordenes); #push linea 3
        array_push($lineaSheet, $ordenes); #push linea 4

        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            'ID',
            'Nombre',
            'Categoría',
            'Existencias',
            'Min_Stock',
            'Max_Stock',
            'Ideal_Stock'
        );
        $campos = array();

        array_push($lineaSheet, $columnas);
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:G5')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('a')->setWidth(10);
        $sheet->getColumnDimension('b')->setWidth(20);
        $sheet->getColumnDimension('c')->setWidth(10);
        $sheet->getColumnDimension('d')->setWidth(10);
        $sheet->getColumnDimension('e')->setWidth(10);
        $sheet->getColumnDimension('f')->setWidth(10);
        $sheet->getColumnDimension('g')->setWidth(10);

        $category_data = $this->reportData();

        foreach ($category_data as $d) {
            $datos = array();
            $datos["inv_id"] = $d["id"];
            $datos["name"] = $d["name"];
            $datos["categorias"] = $d["categorias"];
            $datos['existencias'] = $d['existencias'] == 0 ? "0" : $d['existencias'];
            $datos['min'] = $d['min_stock']== 0? "0": $d['min_stock'];
            $datos['max'] = $d['max_stock'] == 0? "0": $d['max_stock'];
            $datos['ideal'] = $d['ideal_stock']== 0? "0": $d['ideal_stock'];
            $num_fila++; #8
            array_push($lineaSheet, $datos);
        }

        ############### LOGO  ##############
        $imagenGacela = public_path() . '/images/logo.png';

        $objGacela = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $objGacela->setName('Sample image');
        $objGacela->setDescription('Sample image');
        $objGacela->setPath($imagenGacela);
        $objGacela->setWidthAndHeight(160, 75);
        $objGacela->setCoordinates('A1');
        $objGacela->setWorksheet($excel->getActiveSheet());
        ############## FIN LOGO #############

        $excel->setActiveSheetIndex(0);

        $sheet->fromArray($lineaSheet);

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Dompdf');

        $nombreArchivo = 'Inventario ' . TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    /**
     * Not used yet
     */
    public function DownloadCSVInventory(Request $request)
    {
        $store = $this->authStore;

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Inventario");
        $excel->getDefaultStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $excel->getDefaultStyle()->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);


        $lineaSheet = array();
        $nombreEmpresa = array();
        $ordenes = array();
        ###############  TITULO INICIO #################
        $titulo_empresa = "Inventario con fecha de". TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();

        $num_fila = 5; //Se empezará a ubicar los datos desde la fila 5 debido al logo

        $nombreEmpresa['titulo3'] = $titulo_empresa;
        array_push($lineaSheet, $nombreEmpresa);

        array_push($lineaSheet, $ordenes); #push linea 2
        array_push($lineaSheet, $ordenes); #push linea 3
        array_push($lineaSheet, $ordenes); #push linea 4

        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            'ID',
            'Nombre',
            'Categoría',
            'Existencias',
            'Min_Stock',
            'Max_Stock',
            'Ideal_Stock'
        );
        $campos = array();

        array_push($lineaSheet, $columnas);
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:G5')->getFont()->setBold(true)->setSize(12);

        $category_data = $this->reportData();

        foreach ($category_data as $d) {
            $datos = array();
            $datos["inv_id"] = $d["id"];
            $datos["name"] = $d["name"];
            $datos["categorias"] = $d["categorias"];
            $datos['existencias'] = $d['existencias'] == 0 ? "0" : $d['existencias'];
            $datos['min'] = $d['min_stock']== 0? "0": $d['min_stock'];
            $datos['max'] = $d['max_stock'] == 0? "0": $d['max_stock'];
            $datos['ideal'] = $d['ideal_stock']== 0? "0": $d['ideal_stock'];
            $num_fila++; #8
            array_push($lineaSheet, $datos);
        }

        $sheet->fromArray($lineaSheet);
        $excel->setActiveSheetIndex(0);


        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Csv');

        $nombreArchivo = 'Inventario con fecha de' . TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    /**
     * Used in "DownloadCSVInventory" & "DownloadPDFInventory"
     */
    public function reportData()
    {
        $store = $this->authStore;

        $components = ComponentStock::where('store_id', $store->id)->get();

        $counts = [];
        foreach ($components as $key => $transaction) {
            $counts[] = [
                'id' => $transaction->id,
                'date' => Carbon::parse($transaction->created_at),
                'name' => $transaction->component->name. " "
                . $transaction->name,
                'categorias' => $transaction->component->category->name,
                'existencias'=> $transaction->stock,
                'min_stock' => $transaction->min_stock,
                'max_stock' => $transaction->max_stock,
                'ideal_stock' => $transaction->ideal_stock
            ];
        }
        return $counts;
    }
}
