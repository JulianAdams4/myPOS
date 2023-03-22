<?php

namespace App\Http\Controllers\API\V2;

use App\Events\SpotCreated;
use App\Helper;
use App\CashierBalance;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\Traits\OrderHelper;
use App\Spot;
use App\Order;
use App\OrderDetail;
use App\Employee;
use App\Traits\AuthTrait;
use Log;
use App\StoreIntegrationToken;

class SpotController extends Controller
{
    use OrderHelper, LoggingHelper, AuthTrait;

    use AuthTrait;

    public $authUser;
    public $authEmployee;
    public $authStore;
    public $cashierBalance;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
        $this->authStore->load('currentCashierBalance');
        $cashierBalance = $this->authStore->currentCashierBalance;

        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }

        $this->cashierBalance = $cashierBalance;
    }

    /**
     * !Deprecated: use transferItemsBetweenSpots
     * Transfer preorders from a spot to another
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * Si la mesa destino no tiene preordenes existentes, la mesa origen pasará todo
     */
    public function transferSpotContents(Request $request)
    {
        $employee = $this->authEmployee;
        $cashierBalance = $this->cashierBalance;
        $store = $employee->store;

        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }


        try {
            $orderJSON = DB::transaction(
                function () use ($request, $cashierBalance, $store) {
                    $preorderToTransfer = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->origin_spot_id)
                        ->first();

                    // si mesa origen no tiene preorden, retornar error
                    if (!$preorderToTransfer) {
                        return response()->json(
                            [
                                'status' => 'La mesa origen no contiene una preorden',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $storedPreorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->destination_spot_id)
                        ->first();

                    // si mesa destino tiene preorden, retornar error
                    if ($storedPreorder) {
                        return response()->json(
                            [
                                'status' => 'La mesa destino no se encuentra vacía',
                                'results' => "null"
                            ],
                            409
                        );
                    }

                    $preorderToTransfer->spot_id = $request->destination_spot_id;

                    $preorderToTransfer->save();

                    return response()->json(
                        [
                            "status" => "El contenido de la mesa fue transferido con éxito",
                            "results" => null,
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("SpotController API V2: NO SE PUDO TRANSFERIR CONTENIDO DE LA MESA");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'Error al transferir el contenido de la mesa',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function transferItemsBetweenSpots(Request $request)
    {
        $employee = $this->authEmployee;
        $cashierBalance = $this->cashierBalance;
        $store = $employee->store;
        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }

        try {
            $orderJSON = DB::transaction(
                function () use ($request, $cashierBalance, $store, $employee) {
                    $originPreorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->origin_spot['id'])
                        ->with(['orderDetails'])
                        ->first();

                    // si mesa origen no tiene preorden, retornar error
                    if (!$originPreorder) {
                        return response()->json(
                            [
                                'status' => 'La mesa origen no contiene una preorden',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $destinationPreorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->destination_spot['id'])
                        ->with(['orderDetails'])
                        ->first();

                    if (!$destinationPreorder) {
                        $destinationPreorder = new Order();
                        $destinationPreorder->store_id = $store->id;
                        $destinationPreorder->spot_id = $request->destination_spot['id'];
                        $destinationPreorder->status = 1;
                        $destinationPreorder->employee_id = $employee->id;
                        $destinationPreorder->cash = 1;
                        $destinationPreorder->identifier = Helper::getNextOrderIdentifier($store->id);
                        $destinationPreorder->preorder = 1;
                        $destinationPreorder->cashier_balance_id = $cashierBalance->id;
                        $destinationPreorder->save();
                    }


                    $originDetails = collect([]);
                    $destinationDetails = collect([]);
                    foreach ($originPreorder->orderDetails as $detail) {
                        if (in_array($detail->id, $request->origin_spot['details'])) {
                            $originDetails->push($detail);
                        } elseif (in_array($detail->id, $request->destination_spot['details'])) {
                            $destinationDetails->push($detail);
                        }
                    }


                    foreach ($destinationPreorder->orderDetails as $detail) {
                        if (in_array($detail->id, $request->origin_spot['details'])) {
                            $originDetails->push($detail);
                        } elseif (in_array($detail->id, $request->destination_spot['details'])) {
                            $destinationDetails->push($detail);
                        }
                    }

                    $expectedCount = $originDetails->count() + $destinationDetails->count();

                    $totalCount = $originPreorder->orderDetails->count();

                    if ($destinationPreorder->orderDetails) {
                        $totalCount += $destinationPreorder->orderDetails->count();
                    }

                    if ($expectedCount != $totalCount) {
                        return response()->json(
                            [
                                'status' => 'Faltan detalles de la orden',
                                'results' => null
                            ],
                            409
                        );
                    }

                    foreach ($originDetails as $detail) {
                        $detail->order_id = $originPreorder->id;
                        $detail->save();
                    }

                    foreach ($destinationDetails as $detail) {
                        $detail->order_id = $destinationPreorder->id;
                        $detail->save();
                    }

                    $originPreorder = Order::where('id', $originPreorder->id)
                                        ->with(['orderDetails'])
                                        ->first();
                    if (count($originPreorder->orderDetails) === 0) {
                        $originPreorder->delete();
                    } else {
                        $originPreorder = $this->calculateOrderValues($originPreorder);
                    }

                    $destinationPreorder = Order::where('id', $destinationPreorder->id)
                                                ->with(['orderDetails'])
                                                ->first();
                    if (count($destinationPreorder->orderDetails) === 0) {
                        $destinationPreorder->delete();
                    } else {
                        $destinationPreorder = $this->calculateOrderValues($destinationPreorder);
                    }

                    return response()->json(
                        [
                            "status" => "Los items fueron transferido con éxito",
                            "results" => null,
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SpotController API V2: NO SE PUDO TRANSFERIR ITEMS DE LA MESA",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'Error al transferir los items de la mesa',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /**
     * Create random kiosk spot
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     *
     */
    public function createKioskSpot(Request $request)
    {
        $employee = $this->authEmployee;

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }
        }

        try {
            $spot = new Spot();
            $spot->name = "Kiosko - " . Helper::randomString(6);
            $spot->store_id = $employee->store->id;
            $spot->origin = Spot::ORIGIN_MYPOS_KIOSK_TMP;
            $spot->save();

            event(new SpotCreated($spot->toArray()));
            return response()->json(
                [
                    "status" => "La mesa ha sido creada con éxito",
                    "results" => $spot,
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "SpotController API V2: NO SE PUDO CREAR LA MESA",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'Error al crear la mesa del kiosko',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /**
     * Get order details from a spot
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     *
     */
    public function getPreorderSpot(Request $request, $idSpot)
    {
        $employee = $this->authEmployee;
        $cashierBalance = $this->cashierBalance;

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }

            $cashierBalance = CashierBalance::where('store_id', $employee->store->id)
                ->whereNull('date_close')
                ->first();
        }

        if (!$cashierBalance) {
            return response()->json(
                [
                    'status' => 'No se ha abierto caja',
                    'results' => null
                ],
                404
            );
        }

        // Log::info($employee);
        // Log::info($employee->store);
        $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
            ->where('store_id', $employee->store->id)
            ->where('preorder', 1)
            ->where('spot_id', $idSpot)
            ->with('taxDetails.storeTax')
            ->first();
        if (!$preorder) {
            return response()->json(
                [
                    "status" => "No hay preorden para esta mesa",
                    "results" => null
                ],
                404
            );
        }

        $orderDetails = OrderDetail::where('order_id', $preorder->id)
            ->where('status', 1)
            ->with(
                [
                    'productDetail.product',
                    'orderSpecifications.specification.specificationCategory',
                    'processStatus' => function ($process) {
                        $process->orderBy('created_at', 'DESC');
                    }
                ]
            )
            ->get();

        foreach ($orderDetails as $orderDetail) {
            $orderDetail->append('spec_fields');
        }

        return response()->json(
            [
                "status" => "Preorder data",
                "results" => $preorder,
                "details" => Helper::getDetailsUniqueGroupedByCompoundKey($orderDetails)
            ],
            200
        );
    }

    public function getPreorderSplitSpot(Request $request, $idSpot)
    {
        $employee = $this->authEmployee;
        $cashierBalance = $this->cashierBalance;

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }

            $cashierBalance = CashierBalance::where('store_id', $employee->store->id)
                ->whereNull('date_close')
                ->first();
        }

        $store = $employee->store;
        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }

        $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
            ->where('store_id', $store->id)
            ->where('preorder', 1)
            ->where('spot_id', $idSpot)
            ->with('taxDetails.storeTax')
            ->first();
        if (!$preorder) {
            return response()->json(
                [
                    "status" => "No hay preorden para esta mesa",
                    "results" => null
                ],
                404
            );
        }

        $orderDetails = OrderDetail::where('order_id', $preorder->id)
            ->where('status', 1)
            ->with(
                [
                    'productDetail.product.taxes'  => function ($taxes) use ($store) {
                        $taxes->where("store_id", $store->id);
                    },
                    'orderSpecifications.specification.specificationCategory'
                ]
            )
            ->get();

        foreach ($orderDetails as $orderDetail) {
            $orderDetail->append('spec_fields');
        }

        return response()->json(
            [
                "status" => "Preorder data",
                "results" => $preorder,
                "details" => $orderDetails
            ],
            200
        );
    }

    public function activeSpots(Request $request)
    {
        $employee = $this->authEmployee;
        $cashierBalance = $this->cashierBalance;
        if (!$cashierBalance) {
            return response()->json(
                [
                    'status' => 'No se ha abierto caja',
                    'results' => null
                ],
                409
            );
        }
        $activeOrders = Order::where('store_id', $employee->store_id)
            ->where('preorder', 1)
            ->where('cashier_balance_id', $cashierBalance->id)
            ->where('status', 1)->get();
        $activeSpots = [];
        foreach ($activeOrders as $activeOrder) {
            $activeSpots[] = $activeOrder->spot->toArray();
        }
        $spots = $activeSpots;
        $spotsWithIndex = [];
        foreach ($spots as $key => $value) {
            $value["index"] = $key;
            array_push($spotsWithIndex, $value);
        }
        return response()->json(
            [
                'status' => 'Success',
                'results' => $spotsWithIndex
            ],
            200
        );
    }
}
