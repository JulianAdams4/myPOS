<?php

namespace App\Http\Controllers;

use App\Goal;
use App\GoalType;
use App\Helper;
use App\Order;
use App\OrderDetail;
use App\ProductCategory;
use App\Product;
use App\Store;
use App\Section;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\Traits\TimezoneHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
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

    public function createGoal(Request $request)
    {
        $store = $this->authStore;
        
        try {
            $startDate = Carbon::parse($request['start_date'])->startOfDay();
            $finalDate = Carbon::parse($request['end_date'])->endOfDay();
            $goalValue = $request['value'];
            $goalTypeId = $request['goal_type_id'];
            $scope = $request['scope'];
            $storeId = $request['store_id'] ? $request['store_id'] : $store->id;
            $employeeId = $request['employee_id'];
            $productId = $request['product_id'];
            $productCategoryId = $request['product_category_id'];

            $newGoal = new Goal();
            $newGoal->start_date = $startDate;
            $newGoal->end_date = $finalDate;
            $newGoal->value = $goalValue;
            $newGoal->goal_type_id = $goalTypeId;
            $newGoal->scope = $scope;
            $newGoal->store_id = $storeId;
            $newGoal->employee_id = $employeeId;
            $newGoal->product_category_id = $productCategoryId;
            $newGoal->product_id = $productId;
            $newGoal->save();

            return response()->json([
                'status' => 'Exito',
                'results' => null
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL CREAR META, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo crear la meta',
                    'results' => null
                ],
                500
            );
        }
    }

    public function getEmployeesGoals()
    {
        $store = $this->authStore;
        
        try {
            $totalSalesGT = GoalType::firstOrCreate(
                ['code' => 'total_sales'], // To check
                ['name' => 'Total en ventas'] // To create
            );
            $totalCategorySalesGT = GoalType::firstOrCreate(
                ['code' => 'product_category'],
                ['name' => 'Categoria de producto']
            );
            $totalProductQuantityGT = GoalType::firstOrCreate(
                ['code' => 'product_quantity'],
                ['name' => 'Cantidad de producto']
            );

            $allEmployeesGoals = Goal::where([
                ['status', '=', 1],
                ['scope', '=', 'employee'],
                ['store_id', '=', $store->id]
            ])
                ->with(['employee'])
                ->get();

            $responseGoals = [];
            /* Se obtiene un array de uniques employee_id */
            foreach ($allEmployeesGoals->unique('employee_id') as $uniqueEmployeeGoal) {
                $employeeGoals = $allEmployeesGoals->where('employee_id', $uniqueEmployeeGoal->employee_id);
                // Fecha minima y maxima de las metas de un empleado
                $minGoalDate = $employeeGoals->min('start_date');
                $maxGoalDate = $employeeGoals->max('end_date');
                // Total de ordenes de un empleado
                $totalOrdersQuantity = Order::where('status', 1)
                    ->where('preorder', 0)
                    ->whereBetween('created_at', [$minGoalDate, $maxGoalDate])
                    ->where('employee_id', $uniqueEmployeeGoal->employee_id)
                    ->get()
                    ->count('id');

                $total_sales_goals = [];
                $product_category_goals = [];
                $product_quantity_goals = [];
                foreach ($employeeGoals as $employeeGoal) {
                    $startDate = Carbon::parse($employeeGoal['start_date'])->startOfDay();
                    $finalDate = Carbon::parse($employeeGoal['end_date'])->endOfDay();
                    // TOTAL EN VENTAS (EMPLEADO)
                    if ($employeeGoal['goal_type_id'] == $totalSalesGT->id) {
                        $totalSoldInOrders = Order::where('employee_id', $employeeGoal->employee_id)
                            ->where('store_id', $store->id)
                            ->where('status', 1)
                            ->where('preorder', 0)
                            ->whereBetween('created_at', [$startDate, $finalDate])
                            ->get()
                            ->sum('total');
                        $totalSoldInOrders = Helper::bankersRounding($totalSoldInOrders, 2) / 100;
                        $percentage = floatval($totalSoldInOrders) >= floatval($employeeGoal->value)
                            ? 100
                            : (floatval($totalSoldInOrders) / floatval($employeeGoal->value)) * 100;
                        $isCompleted = floatval($totalSoldInOrders) >= floatval($employeeGoal->value);
                        array_push(
                            $total_sales_goals,
                            [
                                'goal_id' => $employeeGoal->id,
                                'type' => $totalSalesGT->name,
                                'type_code' => $totalSalesGT->code,
                                'current_value' => $totalSoldInOrders,
                                'goal_value' => $employeeGoal->value,
                                'percentage' => $percentage,
                                'is_completed' => $isCompleted,
                                'start_date' => $employeeGoal['start_date'],
                                'end_date' => $employeeGoal['end_date']
                            ]
                        );
                    }
                    // VENTAS POR CATEGORIA (EMPLEADO)
                    if ($employeeGoal['goal_type_id'] == $totalCategorySalesGT->id) {
                        $categoryId = $employeeGoal['product_category_id'];
                        $productCategory = ProductCategory::where('id', $categoryId)->first();
                        $totalSoldByCategory = OrderDetail::whereHas(
                            'order',
                            function ($q) use ($startDate, $finalDate) {
                                $q->where('status', 1)->whereBetween('created_at', [$startDate, $finalDate]);
                            }
                        )
                            ->whereHas(
                                'productDetail.product',
                                function ($query) use ($categoryId) {
                                    $query->where('product_category_id', $categoryId);
                                }
                            )
                            ->get()
                            ->sum('value');
                        $totalSoldByCategory = Helper::bankersRounding($totalSoldByCategory, 2) / 100;
                        $percentage = floatval($totalSoldByCategory) >= floatval($employeeGoal->value)
                            ? 100
                            : (floatval($totalSoldByCategory) / floatval($employeeGoal->value)) * 100;
                        $isCompleted = floatval($totalSoldByCategory) >= floatval($employeeGoal->value);
                        array_push(
                            $product_category_goals,
                            [
                                'goal_id' => $employeeGoal->id,
                                'type' => $totalCategorySalesGT->name,
                                'type_code' => $totalCategorySalesGT->code,
                                'name' => $productCategory->name,
                                'current_value' => $totalSoldByCategory,
                                'goal_value' => $employeeGoal->value,
                                'percentage' => $percentage,
                                'is_completed' => $isCompleted,
                                'start_date' => $employeeGoal['start_date'],
                                'end_date' => $employeeGoal['end_date']
                            ]
                        );
                    }
                    // CANTIDAD DE PRODUCTO (EMPLEADO)
                    if ($employeeGoal['goal_type_id'] == $totalProductQuantityGT->id) {
                        $productId = $employeeGoal['product_id'];
                        $product = Product::where('id', $productId)->first();
                        $totalQuantitySoldByProduct = OrderDetail::whereHas(
                            'order',
                            function ($q) use ($startDate, $finalDate) {
                                $q->where('status', 1)->whereBetween('created_at', [$startDate, $finalDate]);
                            }
                        )
                            ->whereHas(
                                'productDetail.product',
                                function ($query) use ($productId) {
                                    $query->where('id', $productId);
                                }
                            )
                            ->get()
                            ->count('id');
                        $isCompleted = floatval($totalQuantitySoldByProduct) >= floatval($employeeGoal->value);
                        array_push(
                            $product_quantity_goals,
                            [
                                'goal_id' => $employeeGoal->id,
                                'type' => $totalProductQuantityGT->name,
                                'type_code' => $totalProductQuantityGT->code,
                                'name' => $product->name,
                                'current_value' => $totalQuantitySoldByProduct,
                                'goal_value' => $employeeGoal->value,
                                'is_completed' => $isCompleted,
                                'start_date' => $employeeGoal['start_date'],
                                'end_date' => $employeeGoal['end_date']
                            ]
                        );
                    }
                }
                array_push(
                    $responseGoals,
                    [
                        'id' => $uniqueEmployeeGoal->employee_id,
                        'name' => $uniqueEmployeeGoal->employee['name'],
                        'totalOrdersQuantity' => $totalOrdersQuantity,
                        'total_sales_goals' => $total_sales_goals,
                        'product_category_goals' => $product_category_goals,
                        'product_quantity_goals' => $product_quantity_goals
                    ]
                );
            }
            return response()->json([
                'status' => 'Exito',
                'results' => $responseGoals
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LAS METAS DE LOS EMPLEADOS, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener las metas de los empleados',
                    'results' => "null"
                ],
                500
            );
        }
    }

    public function getStoresGoals(Request $request, $storeId)
    {
        $store = $this->authStore;
        if (!$store) {
            return response()->json([
                'status' => 'No permitido',
                'results' => []
            ], 409);
        }
        try {
            $totalSalesGT = GoalType::firstOrCreate(
                ['code' => 'total_sales'],
                ['name' => 'Total en ventas']
            );
            $companyId = $store->company_id;
            $whereCondition = '';
            switch ($storeId) {
                case 'all':
                    $whereCondition = 'id > 0';
                    break;
                case 'self':
                    $whereCondition = 'id = '.$store->id;
                    break;
                default:
                    $whereCondition = 'id = '.$storeId;
                    break;
            }
            $storeIds = Store::where('company_id', $companyId)
                ->whereRaw($whereCondition)
                ->get()
                ->pluck('id');
            $salesGoalsByStore = Goal::where([
                ['status', '=', 1],
                ['scope', '=', 'store']
            ])
            ->whereIn('store_id', $storeIds)
            ->get();

            $results = [];
            /* Se obtiene un array de uniques store_id */
            foreach ($salesGoalsByStore->unique('store_id') as $uniqueStoreGoal) {
                $goalsOfStore = $salesGoalsByStore->where('store_id', $uniqueStoreGoal->store_id);
                $store = Store::where('id', $uniqueStoreGoal->store_id)->first();
                $total_sales_goals = [];
                foreach ($goalsOfStore as $storeGoal) {
                    $timezone = TimezoneHelper::getStoreTimezone($store);
                    $startDate = Carbon::parse($storeGoal['start_date'])->setTimezone($timezone)->startOfDay();
                    $finalDate = Carbon::parse($storeGoal['end_date'])->setTimezone($timezone)->endOfDay();
                    // TOTAL EN VENTAS (STORE)
                    if ($storeGoal['goal_type_id'] == $totalSalesGT->id) {
                        /* Todas las ventas de la tienda en el intervalo de fechas*/
                        // $totalOrders = Order::where([
                        //     ['orders.store_id', '=', $storeGoal['store_id']],
                        //     ['orders.status',   '!=', 0],
                        //     ['orders.preorder', '=', 0]
                        // ])
                        // ->join('invoices','invoices.order_id','=','orders.id')->where('invoices.status','=','Pagado')
                        // ->whereBetween('orders.created_at', [$startDate, $finalDate])
                        // ->get()
                        // ->sum('orders.total');

                        $totalOrders = DB::select("select sum(inv.total) as total from invoices inv
                            join orders o on inv.order_id = o.id and date(o.created_at) between ? and ?
                            join stores s on o.store_id = s.id and s.id=?
                            where inv.status='Pagado'", array($startDate,$finalDate,$storeGoal['store_id']));
                        $totalOrders = $totalOrders[0]->total;

                        $totalOrders = Helper::bankersRounding($totalOrders, 2) / 100;
                        $percentage = floatval($totalOrders) < floatval($storeGoal->value)
                            ? (floatval($totalOrders) / floatval($storeGoal->value)) * 100
                            : 100;
                        $isCompleted = floatval($totalOrders) >= floatval($storeGoal->value);
                        array_push(
                            $total_sales_goals,
                            [
                                'goal_id' => $storeGoal->id,
                                'type' => $totalSalesGT->name,
                                'type_code' => $totalSalesGT->code,
                                'current_value' => $totalOrders,
                                'goal_value' => $storeGoal->value,
                                'percentage' => $percentage,
                                'is_completed' => $isCompleted,
                                'start_date' => $storeGoal['start_date'],
                                'end_date' => $storeGoal['end_date']
                            ]
                        );
                    }
                }

                array_push(
                    $results,
                    [
                        'id' => $store->id,
                        'name' => $store->name,
                        'total_sales_goals' => $total_sales_goals
                    ]
                );
            }
            return response()->json([
                'status' => 'Exito',
                'results' => $results
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LAS METAS DE LA TIENDAS, storeId: " . $store->id,
                'Se solicito: '.$storeId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener las metas de la tienda',
                    'results' => null
                ],
                500
            );
        }
    }

    public function getGoalTypes()
    {
        $store = $this->authStore;
        
        try {
            $types = GoalType::select('id', 'name', 'code')
                ->where('id', '>', 0)
                ->get();
            return response()->json([
                'status' => 'Exito',
                'results' => $types->toArray()
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LOS TIPOS DE METAS, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener los tipos de metas',
                    'results' => []
                ],
                500
            );
        }
    }

    public function getStores(Request $request, $onlySelfStore)
    {
        $store = $this->authStore;
        
        try {
            $companyId = $store->company_id;
            $self = !$onlySelfStore ? true : $onlySelfStore == 'true';
            $whereCondition = $self ? 'id = '.$store->id : 'id > 0';
            $stores = Store::select('id', 'name')
                ->where('company_id', $companyId)
                ->whereRaw($whereCondition)
                ->get();
            return response()->json(
                [
                    'status' => 'Exito',
                    'results' => $stores->toArray()
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LAS TIENDAS DE LA COMPANIA: " . $store->company_id,
                'Proviene del admin: '.$onlySelfStore,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener las tiendas',
                    'results' => []
                ],
                500
            );
        }
    }

    public function getEmployeesByStore(Request $request, $storeId)
    {
        $store = $this->authStore;
        
        $store_id = is_numeric($storeId) ? $storeId : $store->id;
        try {
            $store = Store::where('id', $store_id)->first();
            $employeeWithIndex = [];
            $employees = $store->employees;
            foreach ($employees as $key => $employeeDB) {
                $employeeDB['index'] = $key;
                $employeeWithIndex[] = $employeeDB
                    ->makeHidden(['email', 'location_id', 'type_employee'])
                    ->toArray();
            }
            return response()->json(
                [
                    'status' => 'Exito',
                    'results' => $employeeWithIndex
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LOS EMPLEADOS DE LA TIENDA: ".$storeId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener los empleados de la tienda',
                    'results' => []
                ],
                500
            );
        }
    }

    public function getCategoriesByStore(Request $request, $storeId)
    {
        $store = $this->authStore;
        $store_id = is_numeric($storeId) ? $storeId : $store->id;
        try {
            $categories = [];
            $sectionsMain = Section::where('store_id', $store_id)
                ->where('is_main', 1)->get();
            $categories = collect([]);
            if (!(count($sectionsMain) > 0)) {
                $sectionsMain = Section::where('store_id', $store_id)->get();
            }
            foreach ($sectionsMain as $sectionMain) {
                $categoriesSection = $store->company->categories()
                    ->where('section_id', $sectionMain->id)
                    ->orderBy('priority')
                    ->get();
                foreach ($categoriesSection as $categorySection) {
                    $categories->push($categorySection);
                }
            }
            return response()->json([
                'status' => 'Exito',
                'results' => $categories
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LAS CATEGORIAS DE LA TIENDA: ".$storeId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener las categorias de la tienda',
                    'results' => []
                ],
                500
            );
        }
    }

    public function getProductsByCategory(Request $request, $categoryId)
    {
        $store = $this->authStore;
        try {
            $category = ProductCategory::where('company_id', $store->company_id)
                ->where('id', $categoryId)
                ->first();
            if (!$category) {
                return response()->json([
                    'status' => 'No existe la categoría.',
                    'results' => []
                ], 404);
            }
            $products = Product::where('product_category_id', $categoryId)
                ->where('status', 1)
                ->with(
                    [
                        'specifications' => function ($query) {
                            $query->wherePivot('status', 1)
                                ->orderBy('specifications.priority')
                                ->with('specificationCategory')
                                ->join('specification_categories', function ($join) {
                                    $join->on(
                                        'specification_categories.id',
                                        '=',
                                        'specifications.specification_category_id'
                                    )
                                        ->where('specification_categories.status', 1)
                                        ->whereNull('specification_categories.deleted_at');
                                })
                                ->orderBy('specification_categories.priority', 'ASC');
                        }
                    ]
                )
                ->orderBy('priority')
                ->get();
            return response()->json(
                [
                    'status' => 'Exito',
                    'results' => $products
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER LOS PRODUCTOS DE LA TIENDA: ".$store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo obtener los productos',
                    'results' => []
                ],
                500
            );
        }
    }

    public function updateGoal(Request $request)
    {
        $goal = Goal::where('id', $request['id'])->first();
        if (!$goal) {
            return response()->json([
                'status' => 'La meta no existe',
                'results' => null,
            ], 409);
        }
        try {
            $startDate = Carbon::parse($request['start_date'])->startOfDay();
            $finalDate = Carbon::parse($request['end_date'])->endOfDay();
            $goalValue = $request['value'];

            $goal->start_date = $startDate;
            $goal->end_date = $finalDate;
            $goal->value = $goalValue;
            $goal->save();
            return response()->json(
                [
                    "status" => "Exito",
                    "results" => null,
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL ACTUALIZAR LA META: ".$request['id'],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo actualizar la meta',
                    'results' => "null",
                ],
                500
            );
        };
    }
  
    public function deleteGoal(Request $request, $id)
    {
        $goal = Goal::where('id', $id)->first();
        if (!$goal) {
            return response()->json([
                'status' => 'La meta no existe',
                'results' => null,
            ], 409);
        }
        try {
            $goal->status = 0;
            $goal->save();
            return response()->json(
                [
                    "status" => "Exito",
                    "results" => null,
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "GoalController API Goal: ERROR AL OBTENER ELIMINAR LA META: ".$id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo eliminar la meta',
                    'results' => "null",
                ],
                500
            );
        };
    }
}
