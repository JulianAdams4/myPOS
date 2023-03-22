<?php

namespace App\Http\Controllers;

use Log;
use App\Order;
use App\Employee;
use App\Store;
use Carbon\Carbon;
use App\Traits\TimezoneHelper;
use App\OrderDetail;
use App\StockMovement;
use App\InventoryAction;
use App\ProductComponent;
use App\Traits\AuthTrait;
use App\Traits\OrderHelper;
use App\Traits\Stocky\StockyRequest;
use Illuminate\Http\Request;
use App\Helpers\PrintService\PrintServiceHelper;
use App\Traits\ReportHelperTrait;
use App\AvailableMyposIntegration;
use App\InvoiceIntegrationDetails;
use Illuminate\Support\Facades\DB;
use App\Payment;
use App\PaymentType;
use App\Jobs\Gacela\PostGacelaOrder;
use App\Jobs\Integrations\Facturama\FacturamaInvoices;
use App\CreditNote;
use App\Helper;
use App\Traits\LoggingHelper;
use App\Jobs\ActionLoggerJob;
use App\StoreConfig;


class OrderController extends Controller
{
    use AuthTrait, ReportHelperTrait, OrderHelper, LoggingHelper;

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

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
    }

    public function getPaymentTypes()
    {
        $user = $this->authUser;
        if (!$user) {
            return response()->json([
                'status' => 'No permitido',
                'results' => 'null'
            ], 403);
        }
        try {
            $types = [
                ['type' => PaymentType::CASH, 'name' => 'Efectivo'],
                ['type' => PaymentType::DEBIT, 'name' => 'Tarjeta de débito'],
                ['type' => PaymentType::CREDIT, 'name' => 'Tarjeta de crédito'],
                ['type' => PaymentType::RAPPI_PAY, 'name' => 'RappiPay'],
                ['type' => PaymentType::TRANSFER, 'name' => 'Transferencia'],
                ['type' => PaymentType::OTHER, 'name' => 'Otro'],
		[ 'type' => PaymentType::MERCADO_PAGO, 'name' => 'Mercado_pago' ]
            ];
            return response()->json([
                'status' => 'Exito',
                'results' => $types
            ], 200);
        } catch (\Exception $e) {
            Log::info("OrderController@getPaymentTypes: No se pudo obtener los tipos de pago");
            Log::info($e);
            return response()->json([
                'status' => 'Fallo al obtener los tipos de pago',
                'results' => []
            ], 500);
        }
    }

    public function updatePayment(Request $request)
    {
        try {
            $user = $this->authUser;
            DB::transaction(
                function () use ($request, $user) {
                    $orderId = $request['orderId'];
                    $now = Carbon::now()->toDateTimeString();
                    foreach ($request['changes'] as $update) {
                        $payment = Payment::where('order_id', $orderId)
                            ->where('type', $update['old_type'])
                            ->where('card_id', $update['old_card_id'])
                            ->where('card_last_digits', $update['old_card_last_digits'])
                            ->first();
                        if ($payment) {
                            $json_models = ['payment_original' => $payment->replicate()];

                            $payment->type = $update['new_type'];
                            $payment->card_id = $update['new_card_id'];
                            $payment->card_last_digits = $update['new_card_last_digits'];
                            $payment->updated_at = $now;
                            $payment->save();

                            $json_models['payment_changed'] = $payment;

                            /**
                             * Job dispatch payments changes
                             */

                            $obj = [
                                'action' => "CAMBIAR",
                                'model' => "PAYMENT",
                                'user_id' => $this->authEmployee->id,
                                'model_id' => $payment->id,
                                'model_data' => $json_models
                            ];                    
                            
                            ActionLoggerJob::dispatch($obj);

                            $isCashToOther = $update['old_type'] === PaymentType::CASH &&
                                $update['new_type'] !== PaymentType::CASH;
                            $isOtherToCash = $update['old_type'] !== PaymentType::CASH &&
                                $update['new_type'] === PaymentType::CASH;
                            // Changes in Cash payment
                            if ($isCashToOther) {
                                $orderCash = Order::where('id', $orderId)->first();
                                if ($orderCash) {
                                    $orderCash->cash = 0; // Dejo de ser cash
                                    $orderCash->save();
                                }
                            }
                            if ($isOtherToCash) {
                                $orderNoCash = Order::where('id', $orderId)->first();
                                if ($orderNoCash) {
                                    $orderNoCash->cash = 1; // Ahora es cash
                                    $orderNoCash->save();
                                }
                            }
                        }
                    }
                    return response()->json([
                        'status' => 'Exito',
                        'results' => 'null'
                    ], 200);
                }
            );
        } catch (\Exception $e) {
            Log::info("OrderController@updatePayment: No se pudo actualizar el pago de la orden");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al actualizar el pago',
                'results' => 'null'
            ], 500);
        }
    }

    /*
    getOrdersPaginate:
    Obtiene las ordenes pertenecientes al store del admin loggeado
    La cantidad de ordenes se especifica en la variable rowsPerPage
    por ejm si es $request->page es 1, obtiene las 12 primeras si es 2
    obtiene las 12 sieguientes
    NOTA: si se cambia la variable rowsPerPage tambien se debe cambiar en el front
    */
    public function getOrdersPaginate(Request $request) //
    {
        $rowsPerPage = 12;
        $store = $this->authStore;
        $store_config=  StoreConfig::where('store_id', $store->id)->first();
        $store_id = $request->store_id;
        $stores = Store::where('id', $store->id)
            ->orWhere('virtual_of', $store->id)
            ->get()
            ->pluck('id');

        if ($store_id && !$stores->some($store_id)) {
            return response()->json([
                'status' => 'La tienda no pertenece a este hub virtual.'
            ], 404);
        }

        $queryStores = $store_id ? [$store_id] : $stores;

        $timezone = TimezoneHelper::getStoreTimezone($store);

        if (!$request->startDate) {
            $startDate = Carbon::now()->setTimezone($timezone)->startOfDay();
        } else {
            $startDate = TimezoneHelper::localizedDateForStore($request->startDate, $store);
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->setTimezone($timezone)->endOfDay();
        } else {
            $endDate = TimezoneHelper::localizedDateForStore($request->endDate, $store);
        }

        $statusArray= [1,3];
        if(!$store_config->automatic){
            $statusArray=[0,1,3];
        }

        $ordersTotal = Order::whereIn('store_id', $queryStores)
            ->whereIn('status', $statusArray)
            ->where('preorder', 0)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $orders = Order::whereIn('store_id', $queryStores)
            ->whereIn('status', $statusArray)
            ->where('preorder', 0)
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
                    'spot' => function ($query) {
                        $query->withTrashed();
                    },
                    'orderStatus' => function ($status) {
                        $status->where('status', 1)->orderBy('id', 'desc');
                    },
                    'orderConditions' => function ($conds) {
                        $conds->where('status', 1)->orderBy('id', 'desc');
                    },
                    'invoice' => function ($invoice) {
                        $invoice->with('items', 'taxDetails');
                    },
                    'orderIntegrationDetail',
                    'payments'
                ]
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        foreach ($orders as &$order) {
            foreach ($order->orderDetails as &$detail) {
                $detail->append('spec_fields');
                $detailConsumption = "";
                $prodCompConsumptions = ProductComponent::where(
                    'product_id',
                    $detail->productDetail->product_id
                )
                    ->with([
                        'variation' => function ($variation) {
                            $variation->with(['unit']);
                        }
                    ])
                    ->where('status', 1)
                    ->get();
                foreach ($prodCompConsumptions as $prodCompConsumption) {
                    if (
                        $prodCompConsumption->variation->unit != null
                        && $prodCompConsumption->consumption > 0
                    ) {
                        $detailConsumption = $detailConsumption .
                            "    Por Producto:           " . $prodCompConsumption->variation->name
                            . "  " . ($prodCompConsumption->consumption * $detail->quantity)
                            . "(" . $prodCompConsumption->variation->unit->short_name . ")"
                            . "\n";
                    }
                }
                foreach ($detail->orderSpecifications as $orderSpec) {
                    $detailConsumption = $detailConsumption .
                        $this->getConsumptionDetails(
                            $orderSpec,
                            $detail->productDetail->product_id
                        );
                }
                $detail['consumption'] = $detailConsumption;
            }
        }
        return response()->json([
            'status' => 'Exito',
            'results' => [
                'total' => $ordersTotal,
                'orders' => $orders,
            ]
        ], 200);
    }

    /**
     * --- Anula una orden desde el admin ---
     * revert_inventory:
     *    Si es 'true', restaura el consumo del
     *    inventario al valor original.
     *
     * Nota: Solo si el admin anula una orden
     *   se genera una nota de crédito
     */
    public function revokeOrder(Request $request)
    {
        $store = $this->authStore;
        try {
            DB::transaction(
                function () use ($request, $store) {
                    $orderId = $request->id_order;
                    $order = Order::where('store_id', $store->id)
                        ->with(['orderDetails.processStatus', 'invoice'])
                        ->where('preorder', 0)
                        ->where('id', $orderId)
                        ->first();
                    if (!$order) {
                        return response()->json(
                            [
                                'status' => 'La orden no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }


                    $revertInventoryConsumption = $request->revert_inventory;
                    // Revert consumption movement
                    if ($revertInventoryConsumption == true) {
                        $consumptionMovements = StockMovement::where('order_id', $orderId)->get(); // Array
                        if (count($consumptionMovements) > 0) {
                            $revokeMovementsArray = [];
                            $now = Carbon::now()->toDateTimeString();
                            $revokeActionInventory = InventoryAction::firstOrCreate(
                                ['code' => 'revoked_order'],
                                ['name' => 'Anulación de orden', 'action' => 1]
                            );
                            foreach ($consumptionMovements as $consumptionMov) {
                                $componentStockId = $consumptionMov->component_stock_id;
                                $lastComponentMovement = StockMovement::where('component_stock_id', $componentStockId)
                                    ->orderBy('id', 'desc')
                                    ->first();
                                $lastComponentStock = $lastComponentMovement->final_stock;
                                $consumptionValue = $consumptionMov->value;
                                $finalStock = $lastComponentStock + $consumptionValue;
                                array_push(
                                    $revokeMovementsArray,
                                    [
                                        'inventory_action_id' => $revokeActionInventory->id,
                                        'initial_stock' => $lastComponentStock,
                                        'value' => $consumptionValue,
                                        'final_stock' => $finalStock, // Se repone el consumo
                                        'cost' => $consumptionMov->cost, // El mismo costo de la orden
                                        'component_stock_id' => $componentStockId,
                                        'order_id' => $orderId,
                                        'created_by_id' => $store->id,
                                        'created_at' => $now,
                                        'updated_at' => $now
                                    ]
                                );

                                $component_id = $consumptionMov->componentStock->component->id;
                                $unitComponent = $consumptionMov->componentStock->component;
                                $unitPurchase = empty($unitComponent->unit) ? 0 : $unitComponent->unit->id;
                                $unitConsume = empty($unitComponent->unitConsume) ? 0 : $unitComponent->unitConsume->id;
                                $stocky_array = array();
                                $item_stocky = array();
                                $item_stocky['name'] = $consumptionMov->componentStock->component->name;
                                $item_stocky['external_id'] = $component_id.'';
                                $item_stocky['purchase_unit_external_id'] = $unitPurchase.'';
                                $item_stocky['consumption_unit_external_id'] = $unitConsume.'';
                                $item_stocky['supplier_external_id'] = '';
                                $item_stocky['sku'] = $consumptionMov->componentStock->component->SKU;
                                $item_stocky['cost'] = $lastComponentMovement->cost.'';
                                $item_stocky['stock'] = $finalStock.'';
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

                                StockyRequest::syncInventory($store->id, $items);
                            }
                            StockMovement::insert($revokeMovementsArray);
                        }
                    }

                    $order->status = 2;
                    $order->current_status = 'Anulada';
                    $order->observations = $request->observations;
                    $order->updated_at = Carbon::now()->toDateTimeString();
                    $order->save();

                    $companyId = $store->company_id;
                    $creditNote = new CreditNote();
                    $creditNote->credit_sequence = Helper::getNextCreditNoteNumber($companyId);
                    $creditNote->order_id = $orderId;
                    $creditNote->company_id = $companyId;
                    $creditNote->value = $order->order_value; // **
                    $creditNote->type = 'total'; // **
                    $creditNote->consume_inventory = !$revertInventoryConsumption;
                    $creditNote->observations = $request->observations;
                    $creditNote->save();
                    // ** = Puede ser parcial

                    $order->load('creditNote');
                    PrintServiceHelper::printInvoice($order->invoice, $this->authEmployee, true);

                    $facturamaOrder = InvoiceIntegrationDetails::where('invoice_id', $order->invoice->id)
                        ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                        ->first();
                    if ($facturamaOrder != null) {
                        $externalId = $facturamaOrder->external_id;
                        dispatch(new FacturamaInvoices('cancel', null, null, $externalId))->onConnection('backoffice');
                    }


                    /**
                     * Job dispatch
                     */
                    $obj = [
                        'action' => "ANULAR",
                        'model' => "ORDER",
                        'user_id' => $this->authEmployee->id,
                        'model_id' => $order->id,
                        'model_data' => [
                            "order" => $order
                        ]
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);

                    return response()->json(
                        [
                            "status" => "Orden eliminada con éxito",
                            "results" => null,
                        ],
                        200
                    );
                }
            );
        } catch (\Exception $e) {
            Log::info("OrderController API revoke: No se pudo eliminar la orden");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo eliminar la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function getReportOrders(Request $request)
    {
        $store = $this->authStore;
        $finalDate = Carbon::parse($request->date['to'])->endOfDay();
        $ordersIds = Order::where('store_id', $store->id)
            ->where('status', 1)
            ->where('preorder', 0)
            ->whereBetween('created_at', [$request->date['from'], $finalDate])
            ->pluck('id')->toArray();
        $ordersDetail = OrderDetail::select('product_detail_id', 'quantity', 'value', 'name_product')
            ->whereIn('order_id', $ordersIds)
            ->orderBy('name_product', 'ASC')
            ->get();
        $ordersDetailGroups = $ordersDetail->groupBy(['product_detail_id']);
        $ordersDetailGroupsQuantity = $ordersDetail->groupBy(['product_detail_id'])
            ->map(
                function ($row) {
                    return $row->sum('quantity');
                }
            );
        $ordersDetailGroupsValue = $ordersDetail->groupBy(['product_detail_id'])
            ->map(
                function ($row) {
                    return $row->sum(
                        function ($entry) {
                            return $entry->value * $entry->quantity;
                        }
                    );
                }
            );
        $totalValue = 0;
        $dataReturn = collect([]);
        foreach ($ordersDetailGroups as $orderDetailGroup) {
            $totalValue = $totalValue + $ordersDetailGroupsValue[$orderDetailGroup[0]->product_detail_id];
            $itemToAdd = [
                'id' => $orderDetailGroup[0]["product_detail_id"],
                'name' => $orderDetailGroup[0]["name_product"],
                'quantity' => $ordersDetailGroupsQuantity[$orderDetailGroup[0]->product_detail_id],
                'value' => $ordersDetailGroupsValue[$orderDetailGroup[0]->product_detail_id]
            ];
            $dataReturn->push(
                $itemToAdd
            );
        }

        $dataReturn2 = $dataReturn->sortByDesc('value');
        $dataReturn3 = $dataReturn2->values()->all();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'total' => count($dataReturn3),
                    'countOrders' => count($ordersIds),
                    'data' => $dataReturn3,
                    'total_value' => $totalValue,
                ]
            ],
            200
        );
    }

    public function getReportInvoices(Request $request)
    {
        $store = $this->authStore;
        Log::info($store);
        $pageConfig = null;
        if (isset($request->pageNumber)) {
            $pageConfig = [
                'pageNumber' => $request->pageNumber['page'],
                'pageSize' => 12
            ];
        }
        $data = ReportHelperTrait::invoiceData($request->date, $store, $pageConfig);

        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $data['data'],
                    'total' => $data['total'],
                ]
            ],
            200
        );
    }

    public function getReportHourly(Request $request)
    {
        $store = $this->authStore;
        $orders = ReportHelperTrait::hourlyData($request->date, $store->id);

        $total_fact = 0;
        $total_monto = 0;

        foreach ($orders as $o) {
            $total_fact += $o->num_fact;
            $total_monto += $o->monto;
        }
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $orders,
                    'total_fact' => $total_fact,
                    'total_monto' => $total_monto,
                ]
            ],
            200
        );
    }

    public function getReportWeekDay(Request $request)
    {
        $store = $this->authStore;
        $orders = ReportHelperTrait::weekDayData($request->date, $store->id);

        $total_fact = 0;
        $total_monto = 0;

        foreach ($orders as $o) {
            $total_fact += $o->num_fact;
            $total_monto += $o->monto;
        }
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $orders,
                    'total_fact' => $total_fact,
                    'total_monto' => $total_monto,
                ]
            ],
            200
        );
    }

    public function getReportCategorySales(Request $request)
    {
        $store = $this->authStore;
        $data = ReportHelperTrait::categorySalesData($request->date, $store->id);

        $total_fact = count($data);
        $total_monto = 0;

        foreach ($data as $o) {
            $total_monto += $o->category_value;
        }
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $data,
                    'total_fact' => $total_fact,
                    'total_monto' => $total_monto,
                ]
            ],
            200
        );
    }

    public function getReportInvoicesMultiStore(Request $request)
    {
        $data = ReportHelperTrait::invoiceDataMultiStore($request->date, $request->company_id);
        if (!$data) {
            return response()->json(
                [
                    'status' => 'No tiene permisos para acceder a este recurso.',
                    'results' => null
                ],
                403
            );
        }
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $data,
                    'total' => count($data),
                ]
            ],
            200
        );
    }

    public function getReportTransactions(Request $request)
    {
        $store = $this->authStore;
        $data = ReportHelperTrait::transactionDetails($request->date, $store->id);

        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $data,
                    'total' => count($data),
                ]
            ],
            200
        );
    }
    public function getReportTransactionsSQL(Request $request)
    {
        $store = $this->authStore;
        $data = ReportHelperTrait::transactionDetailsRefact($request->date, $store);

        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $data,
                    'total' => count($data),
                ]
            ],
            200
        );
    }

    public function getReportInventory(Request $request)
    {
        $store = $this->authStore;
        $rowsPerPage = 12;

        $offset = ($request->pageNumber['page'] * $rowsPerPage) - $rowsPerPage;
        $data = ReportHelperTrait::inventoryStockData(
            $request->date,
            $store,
            $request->filters,
            $offset,
            $rowsPerPage
        );

        return response()->json([
            'status' => 'Exito',
            'results' => [
                'data' => $data['data'],
                'total' => count($data['list']),
            ]
        ], 200);
    }
    public function getReportInventoryExcel(Request $request)
    {
        $store = $this->authStore;
        $data = ReportHelperTrait::inventoryStockDataSQL(
            $request->date,
            $store
        );
        return response()->json([
            'status' => 'Exito',
            'results' => [
                'data' => $data,
                'total' => count($data),
            ]
        ], 200);
    }

    public function getReportOrdersByEmployee(Request $request)
    {
        $store = $this->authStore;

        $ordersStore = ReportHelperTrait::ordersByEmployee($store->id, $request->date, $request->company_id);

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $ordersStore
            ],
            200
        );
    }

    /*
    postGacelaOrder
    Envia a GACELA la orden cuando el admin-store verifica la orden.
    */
    public function postGacelaOrder(Request $request)
    {
        $orderFinded = Order::find($request->id);
        if ($orderFinded) {
            $orderFinded->load('customer', 'address', 'billing', 'store');
            Log::info('posting to Gacela');
            dispatch(new PostGacelaOrder($orderFinded)); #->onQueue('orders');
            return response()->json([
                'status' => 'Exito',
                'results' => $orderFinded
            ], 200);
        } else {
            return response()->json([
                'status' => 'Orden no encontrada',
            ], 404);
        }
    }
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $order)
    {
        //
    }

    public function getCardsByOrderId(Request $request)
    {
        $user = $this->authUser;
        if (!$user) {
            return response()->json([
                'status' => 'No permitido',
                'results' => 'null'
            ], 403);
        }
        try {
            $orderId = $request['order_id'];
            $order = Order::select('store_id')->where('id', $orderId)->first();
            $store = Store::where('id', $order->store_id)->first();
            return response()->json([
                'status' => 'Exito',
                'results' => $store->cards
            ], 200);
        } catch (\Exception $e) {
            Log::info("OrderController@getCardsByOrderId: No se pudo obtener las tarjetas");
            Log::info($e);
            return response()->json([
                'status' => 'Fallo al obtener las tarjetas',
                'results' => []
            ], 500);
        }
    }
}
