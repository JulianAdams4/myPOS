<?php

namespace App\Http\Controllers\API\V2;

use App\Invoice;
use App\Order;
use App\Spot;
use App\ProductComponent;
use App\Events\HubOrderDeliveryWaiting;
use App\Events\HubOrderDelivered;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\Traits\AuthTrait;
use App\Traits\OrderHelper;
use Log;

class DeliveryController extends Controller
{

    use AuthTrait, LoggingHelper, OrderHelper;
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

    /*
    getOrders:
    La cantidad de ordenes se especifica en la variable rowsPerPage
    por ejm si es $request->page es 1, obtiene las 12 primeras si es 2
    obtiene las 12 sieguientes
    NOTA: si se cambia la variable rowsPerPage tambien se debe cambiar en el front
    */
    public function getOrders(Request $request)
    {
        $rowsPerPage = 12;
        $store = $this->authStore;
        $store->load('latestCashierBalance');
        $cashierBalance = $store->latestCashierBalance;

        if ($cashierBalance->isClosed()) {
            return response()->json([
                'status' => 'Exito',
                'results' => [
                    'total' => 0,
                    'orders' => [],
                ]
            ], 200);
        }

        $ordersTotal = Order::where('cashier_balance_id', $cashierBalance->id)
            ->where('status', 1)
            ->where('preorder', 1)
            ->whereHas('spot', function ($spot) {
                $spot->where('origin', Spot::ORIGIN_DELIVERY_TMP);
            })
            ->count();

        $orders = Order::where('cashier_balance_id', $cashierBalance->id)
            ->where('status', 1)
            ->where('preorder', 1)
            ->whereHas('spot', function ($spot) {
                $spot->where('origin', Spot::ORIGIN_DELIVERY_TMP);
            })
            ->orderBy('id', 'DESC')
            ->limit(12)
            ->offset(($request->page * $rowsPerPage) - $rowsPerPage)
            ->with(
                [
                    'orderDetails' => function ($detail) {
                        $detail->with([
                            'productDetail',
                            'orderSpecifications'
                        ]);
                    },
                    'billing',
                    'address',
                    'employee',
                    'spot',
                    'customer',
                    'orderStatus' => function ($status) {
                        $status->where('status', 1)->orderBy('id', 'desc');
                    },
                    'orderConditions' => function ($conds) {
                        $conds->where('status', 1)->orderBy('id', 'desc');
                    },
                    'orderIntegrationDetail',
                    'payments'
                ]
            )
            ->get();

        return response()->json([
            'status' => 'Exito',
            'results' => [
                'total' => $ordersTotal,
                'orders' => $orders,
            ]
        ], 200);
    }

    public function checkin(Request $request)
    {
        $order_id = $request->order_id;

        if (!$order_id || strlen($order_id) < 5) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Id de la orden requerido"
                ],
                409
            );
        }

        $user = $this->authUser;

        if (!$user->hub) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }

        $storeIds = $user->hub->stores->modelKeys();

        $invoice = Invoice::where('invoice_number', 'like', '%' . $order_id)
            ->with(['order' => function ($order) {
                $order->select(
                    'id',
                    'current_status',
                    'order_duration',
                    'order_value',
                    'delivery_waiting',
                    'cashier_balance_id',
                    'created_at',
                    'updated_at'
                );
            }, 'order.cashierBalance' => function ($cashierBalance) {
                $cashierBalance->select(
                    'id',
                    'date_close'
                )
                    ->orderBy('id', 'DESC')
                    ->take(1);
            }, 'order.orderDetails' => function ($orderDetails) {
                $orderDetails->select(
                    'id',
                    'order_id',
                    'value',
                    'quantity'
                );
            }])
            ->whereHas('order', function ($order) use ($storeIds) {
                $order->whereIn('store_id', $storeIds);
            })
            ->whereHas('order.cashierBalance', function ($cashierBalance) {
                $cashierBalance->whereNull('date_close');
            })
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$invoice) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Orden no existe"
                ],
                409
            );
        }

        $order = Order::find($invoice->order->id);

        $order->delivery_waiting = 1;
        $order->save();

        $invoice->order->dispatched = $invoice->order->isDispatched();
        event(new HubOrderDeliveryWaiting($user->hub, $invoice));

        return response()->json(
            [
                "status" => "Exito",
                "orders" => $order,
            ],
            200
        );
    }

    public function markAsDelivered(Request $request)
    {
        $invoice_id = $request->invoice_id;

        if (!$invoice_id) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Id de la orden requerido"
                ],
                409
            );
        }

        $employee = $this->authEmployee;

        if (!$employee->store->hubs) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Configuración no válida para la tienda"
                ],
                409
            );
        }

        $hub = $employee->store->hubs->first();

        $invoice = Invoice::find($invoice_id);

        if (!$invoice) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Orden no existe"
                ],
                409
            );
        }

        Log::info($invoice);
        $order = Order::find($invoice->order->id);
        $order->delivery_waiting = 0;
        $order->current_status = "Entregada";
        $order->save();

        event(new HubOrderDelivered($hub, $invoice));

        return response()->json(
            [
                "status" => "Exito",
            ],
            200
        );
    }

    public function getKitchenOrders(Request $request)
    {
        $employee = $this->authEmployee;

        if (!$employee->store->hubs) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Configuración no válida para la tienda"
                ],
                409
            );
        }

        $hub = $employee->store->hubs->first();

        $stores = $employee->store->company->stores;

        $storeIds = $request->store_ids != null
            ? $request->store_ids
            : $stores->modelKeys();

        try {
            $invoices = Invoice::select(
                'id',
                'invoice_number',
                'order_id'
            )
                ->with(['order' => function ($order) {
                    $order->select(
                        'id',
                        'spot_id',
                        'order_duration',
                        'order_value',
                        'delivery_waiting',
                        'cashier_balance_id',
                        'created_at',
                        'updated_at'
                    );
                }, 'order.orderDetails' => function ($orderDetails) {
                    $orderDetails->select(
                        'id',
                        'order_id',
                        'value',
                        'quantity'
                    );
                }, 'order.orderDetails.processStatus' => function ($processStatus) {
                    $processStatus->select(
                        'id',
                        'process_status',
                        'order_detail_id'
                    )
                        ->orderBy('id', 'DESC')
                        ->take(1);
                }, 'order.cashierBalance' => function ($cashierBalance) {
                    $cashierBalance->select(
                        'id',
                        'date_close'
                    )
                        ->orderBy('id', 'DESC')
                        ->take(1);
                }, 'order.spot' => function ($spot) {
                    $spot->select(
                        'id',
                        'origin'
                    );
                }])
                ->whereHas('order', function ($order) use ($storeIds) {
                    $order->where('status', 1)
                        ->where('preorder', 0)
                        ->where('current_status', '!=', 'Entregada')
                        ->whereIn('store_id', $storeIds);
                })
                ->whereHas('order.cashierBalance', function ($cashierBalance) {
                    $cashierBalance->whereNull('date_close');
                })
                ->whereHas('order.spot', function ($spot) use ($hub) {
                    if ($hub->spot_origin == null) {
                        return;
                    }
                    $spot->where('origin', $hub->spot_origin);
                })
                ->get();

            foreach ($invoices as $invoice) {
                $invoice->order->dispatched = $invoice->order->isDispatched();
            }

            return response()->json(
                [
                    "status" => "Lista de Facturas",
                    "results" => $invoices,
                    "company"
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "DeliveryController getKitchenOrders: ERROR LISTAR FACTURAS, storeId: " . $employee->store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json([
                'status' => 'No se pudo listar las facturas',
                'results' => "null"
            ], 409);
        }
    }

    public function getHubOrders(Request $request)
    {
        $hub = $this->authUser->hub;

        if (!$hub) {
            return response()->json(
                [
                    "success" => false,
                    "status" => "Configuración del usuario no válido"
                ],
                409
            );
        }

        $storeIds = $hub->stores->modelKeys();

        try {
            $invoices = Invoice::select(
                'id',
                'invoice_number',
                'order_id'
            )
                ->with(['order' => function ($order) {
                    $order->select(
                        'id',
                        'spot_id',
                        'order_duration',
                        'order_value',
                        'cashier_balance_id',
                        'store_id',
                        'created_at',
                        'updated_at'
                    );
                }, 'order.orderDetails' => function ($orderDetails) {
                    $orderDetails->select(
                        'id',
                        'order_id',
                        'value',
                        'quantity'
                    );
                }, 'order.orderDetails.processStatus' => function ($processStatus) {
                    $processStatus->select(
                        'id',
                        'process_status',
                        'order_detail_id'
                    )
                        ->orderBy('id', 'DESC')
                        ->take(1);
                }, 'order.cashierBalance' => function ($cashierBalance) {
                    $cashierBalance->select(
                        'id',
                        'date_close'
                    )
                        ->orderBy('id', 'DESC')
                        ->take(1);
                }, 'order.spot' => function ($spot) {
                    $spot->select(
                        'id',
                        'origin'
                    );
                }, 'order.store.company' => function ($company) {
                    $company->select(
                        'id',
                        'name'
                    );
                }])
                ->whereHas('order', function ($order) use ($storeIds) {
                    $order->where('delivery_waiting', 1)
                        ->where('status', 1)
                        ->where('preorder', 0)
                        ->where('current_status', '!=', 'Entregada')
                        ->whereIn('store_id', $storeIds);
                })
                ->whereHas('order.cashierBalance', function ($cashierBalance) {
                    $cashierBalance->whereNull('date_close');
                })
                ->whereHas('order.spot', function ($spot) use ($hub) {
                    if ($hub->spot_origin == null) {
                        return;
                    }
                    $spot->where('origin', $hub->spot_origin);
                })
                ->get();

            foreach ($invoices as $invoice) {
                $invoice->order->dispatched = $invoice->order->isDispatched();
            }

            return response()->json(
                [
                    "status" => "Lista de Facturas",
                    "results" => $invoices,
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "DeliveryController getDeliveryOrders: ERROR LISTAR FACTURAS, hubId: " . $hub->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json([
                'status' => 'No se pudo listar las facturas',
                'results' => "null"
            ], 409);
        }
    }
}
