<?php

namespace App\Http\Controllers\API\V2;

use Log;
use Auth;
use App\Card;
use App\Spot;
use App\Order;
use App\Store;
use App\Helper;
use App\Billing;
use App\Invoice;
use App\Payment;
use App\Product;
use App\Employee;
use App\Component;
use Carbon\Carbon;
use App\OrderDetail;
use App\PaymentType;
use App\PendingSync;
use App\StoreConfig;
use App\ProductDetail;
use App\StockMovement;
use App\CashierBalance;
use App\InventoryAction;
use App\Traits\AuthTrait;
use App\Events\SpotDeleted;
use App\ComponentStock;
use App\ComponentVariationComponent;
use App\Traits\OrderHelper;
use App\Events\OrderCreated;
use Illuminate\Http\Request;
use App\Traits\Inventory\ComponentHelper;
use App\Traits\LoggingHelper;
use App\Traits\ValidateToken;
use App\StoreIntegrationToken;
use App\StoreIntegrationId;
use App\Traits\TimezoneHelper;
use App\Events\PreOrderUpdated;
use App\OrderIntegrationDetail;
use App\Traits\PushNotification;
use App\OrderDetailProcessStatus;
use App\PaymentIntegrationDetail;
use App\AvailableMyposIntegration;
use App\Events\HubOrderDispatched;
use App\OrderProductSpecification;
use App\Http\Helpers\QueueHelper;
use App\Helpers\PrintService\PrintServiceHelper;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreatedOffline;
use App\Events\OrderUpdatedComanda;
use App\Http\Helpers\InvoiceHelper;
use App\OrderHasPaymentIntegration;
use App\Events\OrderSendedToKitchen;
use App\Http\Controllers\Controller;
use App\Jobs\Gacela\PostGacelaOrder;
use App\Jobs\Datil\IssueInvoiceDatil;
use App\Events\OrderDispatchedComanda;
use App\Events\CompanyOrderCreatedEvent;
use Illuminate\Support\Facades\Validator;
use App\Events\HubIntegrationOrderCreated;
use App\Jobs\Integrations\Siigo\SiigoSaveInvoice;
use App\Http\Controllers\API\Integrations\Facturama\FacturamaController;
use App\Traits\DidiFood\DidiFoodOrder;
use App\Traits\Uber\UberRequests;
use App\Traits\DidiFood\DidiRequests;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Browser;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\Traits\Mely\MelyRequest;
use App\Traits\Mely\MelyIntegration;

//Job
use App\Jobs\ActionLoggerJob;

class OrderController extends Controller
{
    use AuthTrait, ValidateToken, OrderHelper, PushNotification, LoggingHelper,MelyIntegration, DidiRequests{
        DidiRequests::printLogFile insteadof LoggingHelper;
        DidiRequests::logIntegration insteadof LoggingHelper;
        DidiRequests::logError insteadof LoggingHelper;
        DidiRequests::simpleLogError insteadof LoggingHelper;
        DidiRequests::logModelAction insteadof LoggingHelper;
        DidiRequests::getSlackChannel insteadof LoggingHelper;
        DidiRequests::sendSlackMessage insteadof LoggingHelper;
    }
    public $pusher;
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
    public function index(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;

        try {
            $store->load('latestCashierBalance', 'configs');
            $cashierBalance = $store->latestCashierBalance;
            $config = $store->configs;
            $allowChangePayment = $config->allow_modify_order_payment;
            $allowRevoke = $config->employees_edit;
            $orders = [];
            $valuesCash = [];
            $valuesCard = [];
            $newOrdersGrouped = collect([]);
            if ($cashierBalance) {
                $orders = Order::select(
                    'id',
                    'identifier',
                    'total',
                    'order_value',
                    'cash',
                    'spot_id',
                    'created_at',
                    'updated_at'
                )->with(
                    [
                        'orderDetails' => function ($orderDetails) {
                            $orderDetails->select(
                                'id',
                                'order_id',
                                'product_detail_id',
                                'value',
                                'quantity',
                                'total',
                                'base_value',
                                'name_product',
                                'instruction',
                                'compound_key',
                                'invoice_name'
                            );
                        },
                        'invoice' => function ($invoice) {
                            $invoice->select(
                                'id',
                                'billing_id',
                                'order_id',
                                'total',
                                'document',
                                'name',
                                'address',
                                'phone',
                                'email',
                                'subtotal',
                                'tax',
                                'created_at',
                                'discount_percentage',
                                'discount_value',
                                'undiscounted_subtotal',
                                'tip'
                            )->with('order.orderIntegrationDetail', 'billing', 'taxDetails');
                        },
                        'orderIntegrationDetail' => function ($orderIntegration) {
                            $orderIntegration->select(
                                'id',
                                'order_id',
                                'customer_name',
                                'integration_name',
                                'order_number'
                            );
                        },
                        'spot',
                        'payments'
                    ]
                )
                    ->where('store_id', $store->id)
                    ->where('cashier_balance_id', $cashierBalance->id)
                    ->where('status', 1)
                    ->where('preorder', 0)
                    ->orderBy('created_at', "DESC");
                if (isset($request->order_id)) {
                    $orders = $orders->where('id', $request->order_id);
                }
                $orders = $orders->get();

                foreach ($orders as $order) {
                    foreach ($order->orderDetails as $detail) {
                        $detail->append('spec_fields');
                    }
                    $invoice = collect($order["invoice"]);
                    if ($order->invoice) {
                        $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($order->invoice->items);
                        $invoice->forget('items');
                        $invoice->put('items', $detailsGrouped);
                    }
                    $order = collect($order);
                    $order->forget('invoice');
                    $order->put('invoice', $invoice);
                    $newOrdersGrouped->push($order);
                }
                $cash = $newOrdersGrouped->filter(
                    function ($order, $key) {
                        return $order["cash"] == 1;
                    }
                );
                $valuesCash = $cash->pluck('total');
                $card = $newOrdersGrouped->filter(
                    function ($order, $key) {
                        return $order["cash"] == 0;
                    }
                );
                $valuesCard = $card->pluck('total');
                $newOrdersGrouped = $newOrdersGrouped->toArray();
            } else {
                $orders = Order::select(
                    'id',
                    'identifier',
                    'total',
                    'order_value',
                    'cash',
                    'spot_id',
                    'created_at',
                    'updated_at'
                )->with(
                    [
                        'orderDetails' => function ($orderDetails) {
                            $orderDetails->select(
                                'id',
                                'order_id',
                                'product_detail_id',
                                'value',
                                'quantity',
                                'total',
                                'base_value',
                                'name_product',
                                'instruction',
                                'invoice_name'
                            );
                        },
                        'invoice' => function ($invoice) {
                            $invoice->select(
                                'id',
                                'billing_id',
                                'order_id',
                                'total',
                                'document',
                                'name',
                                'address',
                                'phone',
                                'email',
                                'subtotal',
                                'tax',
                                'created_at',
                                'discount_percentage',
                                'discount_value',
                                'undiscounted_subtotal',
                                'tip'
                            )->with('order.orderIntegrationDetail', 'billing', 'taxDetails');
                        },
                        'orderIntegrationDetail' => function ($orderIntegration) {
                            $orderIntegration->select(
                                'id',
                                'order_id',
                                'customer_name',
                                'integration_name',
                                'order_number'
                            );
                        },
                        'spot'
                    ]
                )
                    ->where('store_id', $store->id)
                    ->where('status', 1)
                    ->orderBy('created_at', "DESC");
                if (isset($request->order_id)) {
                    $orders = $orders->where('id', $request->order_id);
                }
                $orders = $orders->get();
                foreach ($orders as $order) {
                    foreach ($order->orderDetails as $detail) {
                        $detail->append('spec_fields');
                    }
                    $invoice = collect($order["invoice"]);
                    if ($order->invoice) {
                        $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($order->invoice->items);
                        $invoice->forget('items');
                        $invoice->put('items', $detailsGrouped);
                    }
                    $order = collect($order);
                    $order->forget('invoice');
                    $order->put('invoice', $invoice);
                    $newOrdersGrouped->push($order);
                }
                $cash = $newOrdersGrouped->filter(
                    function ($order, $key) {
                        return $order["cash"] == 1;
                    }
                );
                $valuesCash = $cash->pluck('total');
                $card = $newOrdersGrouped->filter(
                    function ($order, $key) {
                        return $order["cash"] == 0;
                    }
                );
                $valuesCard = $card->pluck('total');
                $newOrdersGrouped = $newOrdersGrouped->toArray();
            }
            $totalCash = 0;
            $totalCard = 0;
            foreach ($valuesCash as $value) {
                $totalCash += $value;
            }
            foreach ($valuesCard as $value) {
                $totalCard += $value;
            }

            // Agregando especificaciones dentro del campo instrucciones dependiendo de los tipos de especificaciones
            $newOrders = [];
            foreach ($newOrdersGrouped as $order) {
                $newOrderDetail = collect($order['order_details']);
                $orderDetailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($newOrderDetail);
                $cleanDetails = collect([]);
                foreach ($orderDetailsGrouped as $orderDetailGrouped) {
                    unset($orderDetailGrouped["order"]);
                    unset($orderDetailGrouped["order_specifications"]);
                    unset($orderDetailGrouped["product_detail"]);
                    unset($orderDetailGrouped["tax_values"]);
                    $cleanDetails->push($orderDetailGrouped);
                }
                $order['order_details'] = $cleanDetails;
                array_push($newOrders, $order);
            }
            return response()->json(
                [
                    'status' => 'Success',
                    'results' => $newOrders,
                    'totalCash' => $totalCash,
                    'totalCard' => $totalCard,
                    'allowChangePayment' => $allowChangePayment,
                    'allowRevoke' => $allowRevoke
                ],
                200
            );
        } catch (\Exception $e) {
            $this->logError(
                "OrderController API V2 index: ERROR OBTENER LISTA ORDENES, employee: " . $employee,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
        }
    }

    public function getEmployeeOrders(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        $store->load('latestCashierBalance', 'configs');
        $cashierBalance = $store->latestCashierBalance;
        $ordersPaginated = [];
        $count = ['total' => 0];
        if ($cashierBalance) {
            $limit = $request->pageSize;
            $offset = $limit * ($request->pageNumber - 1);
            // $orders = DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
            $query = "
            from orders o
            join invoices i on o.id = i.order_id
            join spots s on o.spot_id = s.id
            where o.cashier_balance_id=? and o.preorder=0 and o.status=1 order by o.id desc ";
            $count = DB::select("select count(*) as total " . $query, array($cashierBalance->id))[0];
            $ordersPaginated = DB::select("select
            o.id,
            o.identifier,
            i.total,
            o.order_value,
            o.cash,
            o.spot_id,
            s.name as mesa,
            o.created_at,
            (select JSON_OBJECT(
                'integration_name',od.integration_name,
                'order_number', od.order_number,
                'customer_name',od.customer_name,
                'color',
                case
                    when od.integration_name='uber_eats' then '#57B83A'
                    when od.integration_name='ifood' then '#EA2F3C'
                    when od.integration_name='didi' then '#FF7C41'
                    when od.integration_name='rappi' then '#FF7175'
                    ELSE '#000000'
                end
            ) 
            from order_integration_details od where od.order_id=o.id limit 1) as integracion,
            (select json_object(
                'document',b.document,
                'name', b.name,
                'address', b.address,
                'phone', b.phone,
                'email', b.email,
                'id',b.id
             ) from billings b where b.id=i.billing_id and b.status=1 limit 1) as billing " . $query . " limit ? offset ?;", array($cashierBalance->id, $limit, $offset));
        }

        foreach ($ordersPaginated as $order) {
            $order->created_at = TimezoneHelper::localizedDateForStore($order->created_at, $store)->format('d/M/Y g:i A');
            $order->integracion = json_decode($order->integracion);
            $order->billing = json_decode($order->billing);
        }
        return response()->json(
            [
                'status' => 'Success',
                'orders' => $ordersPaginated,
                'totalOrders' => $count->total,
                'allowChangePayment' => $store->configs->allow_modify_order_payment
            ],
            200
        );
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
     * Crea la orden, los productos que contienen la orden,
     * las especificaiones de cada producto, una vez creada
     * la orden se envia una notificacion al admin-store
     * correspondiente, por ejm si se crea una orden del store1
     * solo le llega la notificaon al/los admins del store con id 1
     */
    public function store(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    if ($request->has_billing && $store->configs->document_lengths !== '') {
                        $lengths = explode(',', $store->configs->document_lengths);
                        $docLength = strlen($request->billing_document) . '';
                        if (!in_array($docLength, $lengths)) {
                            return response()->json(
                                [
                                    "status" => "El formato del R.U.C. es incorrecto.",
                                    "results" => "null"
                                ],
                                409
                            );
                        }
                    }
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    $order = Order::create(
                        array_merge(
                            $request->all(),
                            [
                                'employee_id' => $employee->id,
                                'store_id' => $store->id,
                                'identifier' => Helper::getNextOrderIdentifier($store->id),
                                'cashier_balance_id' => $cashierBalance->id,
                                'tip' => $request->input('tip', 0),
                            ]
                        )
                    );
                    if ($order) {
                        if ($request->has_billing) {
                            $billing = Billing::where('document', $request->billing_document)->first();
                            if ($billing) {
                                $billing->name = $request->billing_name;
                                $billing->address = $request->billing_address ? $request->billing_address :
                                    $billing->address;
                                $billing->phone = $request->billing_phone ? $request->billing_phone : $billing->phone;
                                $billing->email = $request->billing_email ? $request->billing_email : $billing->email;
                                $billing->save();
                                $order->billing_id = $billing->id;
                                $order->save();
                            } else {
                                $billing = new Billing();
                                $billing->document = $request->billing_document;
                                $billing->name = $request->billing_name;
                                $billing->address = $request->billing_address;
                                $billing->phone = $request->billing_phone;
                                $billing->email = $request->billing_email;
                                $billing->save();
                                $order->billing_id = $billing->id;
                                $order->save();
                            }
                        } else {
                            $billing = Billing::firstOrCreate([
                                'document' => '9999999999999',
                                'name'     => 'CONSUMIDOR FINAL'
                            ]);
                        }
                        foreach ($request->orderDetails as $orderDetail) {
                            $instructionsOrderDetail = "";
                            if ($orderDetail['instruction'] != null) {
                                $instructionsOrderDetail = $orderDetail['instruction'];
                            }
                            $productDetail = ProductDetail::where('product_id', $orderDetail['product_detail_id'])
                                ->with('product.taxes')
                                ->where('status', 1)
                                ->first();
                            $orderDetailCreated = OrderDetail::create(
                                [
                                    'product_detail_id' => $productDetail->id,
                                    'quantity' => $orderDetail['quantity'],
                                    'name_product' => $orderDetail['name_product'],
                                    'value' => $orderDetail['value'],
                                    'invoice_name' => $orderDetail['invoice_name'],
                                    'order_id' => $order->id,
                                    'instruction' => $instructionsOrderDetail,
                                ]
                            );
                        }

                        $order = $this->calculateOrderValues($order);

                        $invoice = InvoiceHelper::createInvoice(
                            $order,
                            $billing,
                            $request->food_service
                        );

                        event(new OrderUpdatedComanda($order));
                        if (!config('app.slave')) {
                            if ($request->has_billing) {
                                // $this->prepareToSendForElectronicBilling($store, $invoice, AvailableMyposIntegration::NAME_NORMAL);
                            }
                        }
                        $this->reduceComponentsStock($order);
                        $this->reduceComponentsStockBySpecification($order);
                        // $this->postGacelaOrder($order);

                        if (config('app.slave')) {
                            $pendingSyncing = new PendingSync();
                            $pendingSyncing->store_id = $store->id;
                            $pendingSyncing->syncing_id = $order->id;
                            $pendingSyncing->type = "order";
                            $pendingSyncing->action = "insert";
                            $pendingSyncing->save();
                        }

                        return response()->json(
                            [
                                "status" => "Orden creada con éxito",
                                "results" => $order->identifier
                            ],
                            200
                        );
                    }
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2: NO SE PUDO GUARDAR LA ORDEN");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo crear la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /*
    postGacelaOrder
    Envia a GACELA la orden cuando el admin-store verifica la orden.
    */
    public function postGacelaOrder(Order $order)
    {
        $order->load('address', 'billing', 'store');
        Log::info('posting to Gacela');
        dispatch(new PostGacelaOrder($order));
    }


    /*
    issueInvoiceDatil
    Issue Invoice in Datil
    Envia una factura electronica por medio de Datil.
    */
    public function issueInvoiceDatil(Store $store, Invoice $invoice, int $issuanceType)
    {
        Log::info('posting to Datil');
        dispatch(new IssueInvoiceDatil($store, $invoice, $issuanceType))->onConnection('backoffice');
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

    public function updateInstruction(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;

        $orderDetail = OrderDetail::where('order_details.id', $request->id_order_detail)
            ->where('order_details.status', 1)
            ->join('orders', "orders.id", "=", "order_details.order_id")
            ->where("orders.id", $request->order_id)
            ->where('orders.store_id', $store->id)
            ->first();
        if (!$orderDetail) {
            return response()->json(
                [
                    'status' => 'Este producto no está en la orden',
                    'results' => "null"
                ],
                404
            );
        } else {
            $orderDetailValid = OrderDetail::where('id', $request->id_order_detail)->first();
            $orderDetailValid->instruction = $request->instruction ? $request->instruction : "";
            $orderDetailValid->save();

            $orderProp = Order::where('id', $orderDetailValid->order_id)->first();
            event(new PreOrderUpdated($orderProp, $employee->id));

            return response()->json(
                [
                    'status' => 'Se ha actualizado la observación',
                    'results' => "null"
                ],
                201
            );
        }
    }

    public function createPreorder(Request $request)
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

        $store = $employee->store;

        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $orderEmployeeId = $request->employeeUnlocker
                        ? $request->employeeUnlocker['id']
                        : $employee->id;
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json(
                            [
                                "status" => "No se ha abierto caja",
                                "results" => null
                            ],
                            409
                        );
                    }

                    // Handle delivery orders
                    if (isset($request->is_delivery) && $request->is_delivery) {
                        $spot = new Spot();
                        $spot->name = "Delivery - " . Helper::randomString(6);
                        $spot->store_id = $store->id;
                        $spot->origin = Spot::ORIGIN_DELIVERY_TMP;
                        $spot->save();

                        $request->id_spot = $spot->id;
                    }

                    $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->id_spot)
                        ->first();

                    if (!$preorder) {
                        $customerId = $request->customer_id;
                        $addressId = $request->address_id;

                        $preorder = new Order();
                        $preorder->store_id = $store->id;
                        $preorder->spot_id = $request->id_spot;
                        $preorder->order_value = $request->value;
                        $preorder->people = $request->people;
                        $preorder->status = 1;
                        $preorder->employee_id = $orderEmployeeId;

                        if ($customerId) {
                            $preorder->customer_id = $customerId;
                        }

                        if ($addressId) {
                            $preorder->address_id = $addressId;
                        }

                        $preorder->cash = 1;
                        if (isset($request->is_delivery) && $request->is_delivery) {
                            $preorder->identifier = Helper::getNextOrderIdentifier($store->id);
                        }
                        $preorder->preorder = 1;
                        $preorder->cashier_balance_id = $cashierBalance->id;
                        $preorder->save();
                    } else {
                        $preorder->people = $request->people;
                        $preorder->order_value = $preorder->order_value + $request->value;
                        $preorder->save();
                    }                    
                                        
                    $obj = [
                        'action' => "CREAR",
                        'model' => "ORDER",
                        'user_id' => $orderEmployeeId,
                        'model_id' => $preorder->id,
                        'model_data' => [
                            "order" => $preorder,
                            "user" => $request->employeeUnlocker ?: $employee
                        ]
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);                    
                                        
                    $productDetail = ProductDetail::where('product_id', $request->id_product)
                        ->where('status', 1)
                        ->first();

                    // Check (Restrictive Stock)
                    if ($store->configs->restrictive_stock_sales) {
                        $prod = Product::where('id', $request->id_product)->with(['components'])->first();
                        foreach ($prod->components as $component) {
                            $componentStock = ComponentStock::where('store_id', $store->id)
                                ->where('component_id', $component->component_id)
                                ->first();
                            if ($componentStock->stock < $request->quantity) {
                                throw new \Exception("Restriccion: No hay suficiente stock para agregar el producto");
                            }
                        }
                    }
                    
                    for ($i = 0; $i < $request->quantity; $i++) {
                        $valueOrderDetail = 0;
                        if ($i == ($request->quantity - 1)) {
                            $unitValue = floor($request->value / $request->quantity);
                            $valueOrderDetail = $request->value - ($unitValue * $i);
                        } else {
                            $valueOrderDetail = floor($request->value / $request->quantity);
                        }

                        $orderDetailCreated = OrderDetail::create(
                            [
                                'product_detail_id' => $productDetail->id,
                                'quantity' => 1,
                                'name_product' => $request->name,
                                'value' => $valueOrderDetail,
                                'invoice_name' => $request->invoice_name,
                                'order_id' => $preorder->id,
                                'instruction' => is_null($request->instruction) ? "" : $request->instruction,
                            ]
                        );

                        $orderDetailCreated->processStatus()->create([
                            'process_status' => 1,
                        ]);                        

                        $specificationIdsQuantity = collect([]);
                        if ($request->specifications) {
                            foreach ($request->specifications as $specification) {
                                foreach ($specification['options'] as $option) {
                                    if ($option['checked'] == 1 && $option['quantity'] > 0) {
                                        $orderDetailCreated->orderSpecifications()->create([
                                            'specification_id' => $option['id'],
                                            'name_specification' => $option['name'],
                                            'value' => $option['value'],
                                            'quantity' => $option['quantity'],
                                        ]);
                                        
                                        $specificationIdsQuantity->push([
                                            'id' => $option['id'],
                                            'quantity' => $option['quantity']
                                        ]);
                                    }
                                }
                            }

                            $sortedById = $specificationIdsQuantity->sortBy('id');
                            $compoundKey = strval($productDetail->id);
                            foreach ($sortedById as $specInfo) {
                                $compoundKey = $compoundKey . '_' . strval($specInfo['id']) . '_' .
                                    strval($specInfo['quantity']);
                            }
                            $orderDetailCreated->compound_key = $compoundKey;
                            $orderDetailCreated->save();
                        } else {
                            $compoundKey = strval($productDetail->id);
                            $orderDetailCreated->compound_key = $compoundKey;
                            $orderDetailCreated->save();
                        }
                    }                    
                    
                    $preorder = $this->calculateOrderValues($preorder);
                    
                    event(new PreOrderUpdated($preorder, $employee->id));

                    // Log Action on Model
                    $obj = [
                        'action' => "AGREGAR",
                        'model' => "ORDER",
                        'user_id' => $orderEmployeeId,
                        'model_id' => $preorder->id,
                        'model_data' => [
                            'order' => $preorder,
                            'product' => [
                                'quantity' => $request->quantity,
                                'instruction' => is_null($request->instruction) ? "" : $request->instruction,
                            ]
                        ]
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);
                    // ------------------

                    return response()->json(
                        [
                            "status" => "Orden creada con éxito",
                            "results" => null,
                            "preorder_id" => $preorder->id,
                            "detail_id" => isset($orderDetailCreated) ? $orderDetailCreated->id : null,
                            "spot" => isset($spot) ? $spot : null                                                      
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2: NO SE PUDO GUARDAR LA PREORDEN");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            if (strpos($e->getMessage(), "Restriccion") !== false) {
                return response()->json([
                    'status' => $e->getMessage(),
                    'results' => "null"
                ], 422);
            } else {
                return response()->json([
                    'status' => 'No se pudo crear la preorden',
                    'results' => "null"
                ], 409);
            }
        }
    }

    public function changeContentPreorder(Request $request)
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

        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de hacer órdenes',
                                'results' => "null"
                            ],
                            409
                        );
                    }
                    $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->id_spot)
                        ->first();
                    if (!$preorder) {
                        return response()->json(
                            [
                                'status' => 'Esta orden no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                    $productDetail = ProductDetail::where('product_id', $request->id_product)
                        ->where('status', 1)
                        ->first();
                    if (!$productDetail) {
                        return response()->json(
                            [
                                'status' => 'Este producto no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                    $orderDetail = OrderDetail::where('id', $request->id_order_detail)
                        ->where('status', 1)
                        ->with('orderSpecifications')
                        ->first();
                    if (!$orderDetail) {
                        return response()->json(
                            [
                                'status' => 'Este producto no está en la orden',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $json_models = array();

                    if ($request->action === 1) {

                        $json_models['order_details_old'] = OrderDetail::where('order_id', $orderDetail->order_id)
                            ->where(DB::raw('concat(instruction," ",compound_key)'), '=', $orderDetail->group)
                            ->where('status', 1)
                            ->get();

                        $orderDetailsByCompoundKey = OrderDetail::where('order_id', $orderDetail->order_id)
                            ->where(DB::raw('concat(instruction," ",compound_key)'), '=', $orderDetail->group)
                            ->where('status', 1)
                            ->orderBy('id', 'desc')
                            ->get();

                        if (isset($request->instruction)) {
                            foreach ($orderDetailsByCompoundKey as &$orderDetail) {
                                $orderDetail->instruction = $request->instruction;
                                $orderDetail->save();
                            }
                        }

                        $actualCountOrderDetails = $orderDetailsByCompoundKey->count();
                        if ($request->quantity > $actualCountOrderDetails) {
                            $newCountOrderDetails = $request->quantity - $actualCountOrderDetails;
                            for ($i = 0; $i < $newCountOrderDetails; $i++) {
                                $newOrderDetail = new OrderDetail();
                                $newOrderDetail->order_id = $orderDetail->order_id;
                                $newOrderDetail->product_detail_id = $orderDetail->product_detail_id;
                                $newOrderDetail->quantity = 1;
                                $newOrderDetail->status = 1;
                                $newOrderDetail->created_at = Carbon::now()->toDateTimeString();
                                $newOrderDetail->updated_at = Carbon::now()->toDateTimeString();
                                $newOrderDetail->value = $orderDetail->value;
                                $newOrderDetail->name_product = $orderDetail->name_product;
                                $newOrderDetail->instruction = $request->instruction ?? $orderDetail->instruction;
                                $newOrderDetail->invoice_name = $orderDetail->invoice_name;
                                $newOrderDetail->total = $orderDetail->total;
                                $newOrderDetail->base_value = $orderDetail->base_value;
                                // Si el producto original ya fue impreso, quitar el simbolo de impresion del nuevo compound_key
                                $newCompountKey = (strpos($orderDetail->compound_key, '*') !== false) ?
                                    str_replace('*', '', $orderDetail->compound_key) : $orderDetail->compound_key;
                                $newOrderDetail->compound_key = $newCompountKey;
                                $newOrderDetail->save();
                                // El producto nuevo nunca se debe considegetOrdersrar impreso, por lo tanto se crea el estado ORDENADO
                                $createdStatus = new OrderDetailProcessStatus();
                                $createdStatus->process_status = 1;
                                $createdStatus->order_detail_id = $newOrderDetail->id;
                                $createdStatus->save();
                                foreach ($orderDetail->orderSpecifications as $spec) {
                                    OrderProductSpecification::create([
                                        'specification_id' => $spec->specification_id,
                                        'name_specification' => $spec->name_specification,
                                        'value' => $spec->value,
                                        'order_detail_id' => $newOrderDetail->id,
                                        'quantity' => $spec->quantity,
                                    ]);
                                }
                            }
                        } elseif ($request->quantity < $actualCountOrderDetails) {
                            $deleteCountOrderDetails = $actualCountOrderDetails - $request->quantity;
                            foreach ($orderDetailsByCompoundKey as $orderDetail) {
                                if ($deleteCountOrderDetails !== 0) {
                                    $orderDetail->delete();
                                    $deleteCountOrderDetails--;
                                }
                            }
                        }
                    } elseif ($request->action == 2) {

                        $json_models['order_details_old'] = OrderDetail::where('order_id', $orderDetail->order_id)
                            ->where(DB::raw('concat(instruction," ",compound_key)'), '=', $orderDetail->group)
                            ->where('status', 1)
                            ->get();

                        $orderDetailsByCompoundKey = OrderDetail::where('order_id', $orderDetail->order_id)
                            ->where(DB::raw('concat(instruction," ",compound_key)'), '=', $orderDetail->group)
                            ->where('status', 1)
                            ->get();                

                        foreach ($orderDetailsByCompoundKey as $detailToDelete) {
                            //array_push($arrayProducts, $detailToDelete->replicate());
                            $detailToDelete->delete();
                        }
                    } else {
                        return response()->json(
                            [
                                'status' => 'Esta operación no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                    $orderDetails = OrderDetail::where('order_id', $preorder->id)
                        ->where('status', 1)
                        ->with([
                            'productDetail.product',
                            'orderSpecifications',
                            'processStatus' => function ($status) {
                                $status->orderBy('created_at', 'DESC');
                            }
                        ])->get();
                    $newTotal = 0;
                    foreach ($orderDetails as $detail) {
                        $detail->append('spec_fields');
                        $newTotal += $detail->quantity * $detail->value;
                    }
                    $preorder->people = $request->people;
                    $preorder->order_value = $newTotal;
                    $preorder = $this->calculateOrderValues($preorder);
                    event(new PreOrderUpdated($preorder, $employee->id));

                    $json_models['order_new'] = $preorder;

                    /**
                     * Jobs updating order detail, after get the new total order
                     */
                    $action = "ELIMINAR PRODUCTO";
                    if ($request->action == 1) {
                        $action = "ACTUALIZAR PRODUCTO";
                    }

                    $obj = [
                        'action' => $action,
                        'model' => "ORDER",
                        'user_id' => $employee->id,
                        'model_id' => $preorder->id,
                        'model_data' => $json_models
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);

                    return response()->json(
                        [
                            "status" => "Orden modificada con éxito",
                            "results" => $preorder,
                            "details" => Helper::getDetailsUniqueGroupedByCompoundKey($orderDetails)
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController changeContentPreorder API V2: NO SE PUDO GUARDAR LA PREORDEN");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo crear la preorden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /**
     * Deprecado, usar @createOrderFromSplitAccount
     */
    public function convertPreorderToOrder(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;

        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    if ($request->has_billing && $store->configs->document_lengths !== '') {
                        $lengths = explode(',', $store->configs->document_lengths);
                        $docLength = strlen($request->billing_document) . '';
                        if (!in_array($docLength, $lengths)) {
                            return response()->json([
                                "status" => "El formato del R.U.C. es incorrecto.",
                                "results" => "null"
                            ], 409);
                        }
                    }

                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json([
                            'status' => 'Se tiene que abrir caja antes de hacer órdenes',
                            'results' => "null"
                        ], 404);
                    }

                    $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('store_id', $store->id)
                        ->where('preorder', 1)
                        ->where('spot_id', $request->spot_id)
                        ->first();
                    if (!$preorder) {
                        return response()->json([
                            'status' => 'Esta orden no existe',
                            'results' => "null"
                        ], 404);
                    }

                    $invoiceNumber = $store->nextInvoiceBillingNumber();
                    $invoiceNumberRequest = $request->invoice_number;
                    if ($invoiceNumber === "") {
                        $invoiceNumber = $request->invoice_number;
                    }

                    $device_id = $store->company_id . $store->id . $request->ip();

                    $now = Carbon::now()->toDateTimeString();
                    $preorder->current_status = "Creada";
                    $preorder->updated_at = Carbon::now()->toDateTimeString();
                    $preorder->cash = $request->cash;
                    $preorder->device_id = $device_id;
                    $alternateBillSequenceSwitch = false;
                    if ($request->cash == 1) {
                        $alternateBillSequenceSwitch = true;
                    }

                    $preorder->food_service = $request->food_service;
                    $preorder->change_value = $request->change_value;
                    $preorder->preorder = 0;
                    $preorder->discount_percentage = $request->discount_percentage;
                    $preorder->discount_value = $request->discount_value;
                    $preorder->undiscounted_base_value = $request->undiscounted_base_value;
                    $preorder->tip = $request->input('tip', 0);

                    $preorder = $this->calculateOrderValues($preorder);

                    $billing = null;
                    if ($request->has_billing) {
                        $billing = Billing::where('document', $request->billing_document)->first();
                        if ($billing) {
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address ? $request->billing_address :
                                $billing->address;
                            $billing->phone = $request->billing_phone ? $request->billing_phone : $billing->phone;
                            $billing->email = $request->billing_email ? $request->billing_email : $billing->email;
                            $billing->save();
                        } else {
                            $billing = new Billing();
                            $billing->document = $request->billing_document;
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address;
                            $billing->phone = $request->billing_phone;
                            $billing->email = $request->billing_email;
                            $billing->save();
                        }
                        $preorder->billing_id = $billing->id;
                        $preorder->save();
                    } else {
                        $billing = Billing::firstOrCreate([
                            'document' => '9999999999999',
                            'name'     => 'CONSUMIDOR FINAL'
                        ]);
                    }

                    $invoice = InvoiceHelper::createInvoice(
                        $preorder,
                        $billing,
                        $request->food_service,
                        $invoiceNumber,
                        true
                    );

                    $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');
                    if (!config('app.slave')) {
                        if ($request->has_billing) {
                            // $this->prepareToSendForElectronicBilling($store, $invoice, AvailableMyposIntegration::NAME_NORMAL);
                        }
                    }
                    $this->reduceComponentsStock($preorder);
                    $this->reduceComponentsStockBySpecification($preorder);
                    // $this->postGacelaOrder($order);

                    // Agregando especificaciones dentro del campo instrucciones
                    $newOrders = [];
                    foreach ($preorder->orderDetails as $storedOrderDetail) {
                        $storedOrderDetail->append('spec_fields');
                    }

                    if (config('app.slave')) {
                        $pendingSyncing = new PendingSync();
                        $pendingSyncing->store_id = $store->id;
                        $pendingSyncing->syncing_id = $preorder->id;
                        $pendingSyncing->type = "order";
                        $pendingSyncing->action = "insert";
                        $pendingSyncing->save();
                    }

                    $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($invoice->items);

                    $taxValues = $this->getTaxValuesFromDetails($store, $preorder->orderDetails);
                    $invoice->noTaxSubtotal = $taxValues['no_tax_subtotal'];
                    $invoice->productTaxes = $taxValues['product_taxes'];

                    PrintServiceHelper::printInvoice($invoice, $employee);
                    //unset extra properties
                    unset($invoice->noTaxSubtotal);
                    unset($invoice->productTaxes);

                    $officialInvoiceNumber = Helper::getNextBillingOfficialNumber($store->id, true);
                    // $alternateBill = Helper::getAlternatingBillingNumber(
                    //     $store->id,
                    //     $alternateBillSequenceSwitch
                    // );
                    // if ($alternateBill != "") {
                    //     $officialInvoiceNumber = $alternateBill;
                    // }
                    if ($officialInvoiceNumber != "") {
                        $invoice->invoice_number = $officialInvoiceNumber;
                        $invoice->save();
                    }

                    $invoiceCollection = collect($invoice);
                    $invoiceCollection->forget('items');
                    $invoiceCollection->put('items', $detailsGrouped);

                    $spot = Spot::find($preorder->spot_id);

                    // Reemplazar a la mesa fija de kiosko (por reportes)
                    if ($spot->isTmp()) {
                        $realSpot = Spot::getSpotFromTmp($spot);
                        $preorder->spot_id = $realSpot->id;
                        $preorder->save();
                        // Borrar la mesa
                        event(new SpotDeleted($spot->toArray()));
                        $spot->delete();

                        $spot = $realSpot;
                    }

                    // Crear orden integration o actualizar info si viene de una mesa de externos integración
                    if (!$spot->isNormal() && !$spot->isSplit() && !$spot->isKiosk()) {
                        $existOrderIntegration = OrderIntegrationDetail::where(
                            'order_id',
                            $preorder->id
                        )->first();
                        if ($existOrderIntegration != null) {
                            $detailsCollection = collect($preorder->orderDetails);
                            $orderDetailsGroup = $detailsCollection->groupBy('compound_key')->toArray();
                            $existOrderIntegration->number_items = count($orderDetailsGroup);
                            $existOrderIntegration->value = $preorder->total;
                            $existOrderIntegration->save();
                        } else {
                            $integrationName = Spot::getNameIntegrationByOrigin($spot->origin);

                            if ($integrationName == "") {
                                throw new \Exception("No existe esta mesa de servicio externo");
                            }
                            $orderIntegration = new OrderIntegrationDetail();
                            $orderIntegration->order_id = $preorder->id;
                            $orderIntegration->integration_name = $integrationName;
                            $orderIntegration->number_items = 1;
                            $orderIntegration->value = $preorder->total;
                            $orderIntegration->save();

                            // Mesas de integración va siempre a crédito
                            $preorder->change_value = null;

                            $payment = new Payment();
                            $payment->created_at = $now;
                            $payment->updated_at = $now;
                            $payment->total = $preorder->total;
                            $payment->order_id = $preorder->id;
                            $payment->type = $spot->isRappiAntojo()
                                ? PaymentType::CASH
                                : PaymentType::CREDIT;

                            $preorder->save();
                            $payment->save();
                        }
                    } else {
                        foreach ($request->payments as $paymentObject) {
                            $payment = new Payment();
                            $payment->created_at = $now;
                            $payment->updated_at = $now;
                            $payment->total = $paymentObject->total;
                            $payment->type = $paymentObject->type;
                            $payment->card_last_digits = $paymentObject->card_last_digits;
                            $payment->order_id = $preorder->id;
                            $payment->card_id = $paymentObject->card_id;
                            $payment->save();
                        }
                    }

                    event(new OrderCreated($preorder->id));
                    event(new OrderUpdatedComanda($preorder));

                    return response()->json([
                        "status" => "Orden creada con éxito",
                        "results" => Helper::getDetailsUniqueGroupedByCompoundKey($preorder->orderDetails),
                        "identifier" => $preorder->identifier,
                        "invoice" => $invoiceCollection,
                    ], 200);
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            $this->logError(
                "OrderController API V2 convertPreorderToOrder: ERROR GUARDAR ORDEN, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json([
                'status' => 'No se pudo crear la orden',
                'results' => "null"
            ], 409);
        }
    }

    public function deletePreorder(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if ($cashierBalance) {
                        $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                            ->with('orderDetails.processStatus')
                            ->where('store_id', $store->id)
                            ->where('preorder', 1)
                            ->where('spot_id', $request->spot_id)
                            ->first();
                        if (!$preorder) {
                            return response()->json(
                                [
                                    'status' => 'Esta orden no existe',
                                    'results' => "null"
                                ],
                                404
                            );
                        }

                        if ($employee->isCashier() && !$request->input('force_delete', false)) {
                            foreach ($preorder->orderDetails as $orderDetail) {
                                $statuses = $orderDetail->processStatus->pluck("process_status")->toArray();
                                if (in_array(2, $statuses)) {
                                    return response()->json(
                                        [
                                            'status' => '¡La orden ya se está preparando, acérquese a caja!',
                                            'results' => "null"
                                        ],
                                        409
                                    );
                                }
                            }
                        }

                        foreach ($preorder->orderDetails as $orderDetail) {
                            $id = $orderDetail->id;
                            $orderDetail = OrderDetail::where('id', $id)->first();
                            $reason = isset($request->change_reason) ? $request->change_reason : "eliminado";
                            $orderDetail->change_reason = $reason;
                            $orderDetail->save();
                            $orderDetail->delete();
                        }

                        $preorder->delete();

                        return response()->json(
                            [
                                "status" => "Orden eliminada con éxito",
                                "results" => null,
                            ],
                            200
                        );
                    } else {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de eliminar órdenes',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2: NO SE PUDO ELIMINAR LA ORDEN");
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

    public function getPrintDataPreorder(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if ($cashierBalance) {
                        $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                            ->where('store_id', $store->id)
                            ->where('preorder', 1)
                            ->with(
                                [
                                    'orderDetails.processStatus',
                                    'orderDetails.orderSpecifications.specification.specificationCategory',
                                    'orderIntegrationDetail'
                                ]
                            )
                            ->where('spot_id', $request->spot_id)
                            ->first();
                        if (!$preorder) {
                            return response()->json(
                                [
                                    'status' => 'Esta orden no existe',
                                    'results' => "null"
                                ],
                                404
                            );
                        }

                        $newOrders = [];
                        $newOrderDetails = collect([]);
                        $allOrderDetails = collect([]);
                        foreach ($preorder->orderDetails as $storedOrderDetail) {
                            $storedOrderDetail->append('spec_fields');
                            $statuses = $storedOrderDetail->processStatus->pluck("process_status")->toArray();
                            if (!in_array(2, $statuses)) {
                                $newOrderDetails->push($storedOrderDetail);
                            } else {
                                $allOrderDetails->push($storedOrderDetail);
                            }
                        }

                        if (count($newOrderDetails) === 0) {
                            $newOrderDetails = $allOrderDetails;
                        }

                        $config = StoreConfig::where('store_id', $store->id)
                            ->first();

                        $statusCode = 200;

                        if ($config->uses_print_service) {
                            PrintServiceHelper::printComanda($preorder, $employee);
                            $statusCode = 202;
                        }

                        return response()->json(
                            [
                                "status" => "Orden creada con éxito",
                                "results" => Helper::getDetailsUniqueGroupedByCompoundKey($newOrderDetails),
                                "identifier" => $preorder->identifier,
                                "order_info" => $preorder,
                            ],
                            $statusCode
                        );
                    } else {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de hacer pedir la información de la orden',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2 getPrintDataPreorder: NO SE PUDO OBTENER LA INFO DE ORDEN");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo obtener la info de la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function getPrintDataOrderById(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if ($cashierBalance) {
                        $order = Order::where('store_id', $store->id)
                            ->where('preorder', 0)
                            ->with(
                                [
                                    'orderDetails',
                                    'orderDetails.orderSpecifications.specification.specificationCategory',
                                    'invoice',
                                    'orderIntegrationDetail'
                                ]
                            )
                            ->where('id', $request->id)
                            ->first();
                        if (!$order) {
                            return response()->json(
                                [
                                    'status' => 'Esta orden no existe',
                                    'results' => "null"
                                ],
                                404
                            );
                        }

                        // Agregando especificaciones dentro del campo instrucciones
                        $newOrders = [];
                        $newOrderDetails = collect([]);
                        $allOrderDetails = collect([]);
                        foreach ($order->orderDetails as $storedOrderDetail) {
                            $storedOrderDetail->append('spec_fields');
                            $statuses = $storedOrderDetail->processStatus->pluck("process_status")->toArray();
                            if (!in_array(2, $statuses)) {
                                $newOrderDetails->push($storedOrderDetail);
                            } else {
                                $allOrderDetails->push($storedOrderDetail);
                            }
                        }

                        if (count($newOrderDetails) === 0) {
                            $newOrderDetails = $allOrderDetails;
                        }
                        return response()->json(
                            [
                                "status" => "Orden creada con éxito",
                                "results" => Helper::getDetailsUniqueGroupedByCompoundKey($newOrderDetails),
                                "order_info" => $order
                            ],
                            200
                        );
                    } else {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de recibir la información de la orden',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2 getPrintDataOrderById: NO SE PUDO OBTENER LA INFO DE ORDEN");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'No se pudo obtener la info de la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function changeOrderDetailAsPrinted(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if ($cashierBalance) {
                        $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                            ->where('spot_id', $request->spot_id)
                            ->where('preorder', 1)
                            ->with(
                                [
                                    'orderDetails',
                                ]
                            )
                            ->first();
                        if (!$preorder) {
                            return response()->json(
                                [
                                    'status' => 'No existe la preorden',
                                    'results' => "null"
                                ],
                                404
                            );
                        }
                        foreach ($preorder->orderDetails as $orderDetail) {
                            $statuses = $orderDetail->processStatus->pluck("process_status")->toArray();
                            if (!in_array(2, $statuses)) {
                                $processStatus = new OrderDetailProcessStatus();
                                $processStatus->process_status = 2;
                                $processStatus->order_detail_id = $orderDetail->id;
                                $processStatus->save();
                                $newCompoundKey = (strpos($orderDetail->compound_key, '*') !== false) ?
                                    $orderDetail->compound_key : '*' . $orderDetail->compound_key;
                                $orderDetail->compound_key = $newCompoundKey;
                                $orderDetail->save();
                            }
                        }
                        return response()->json(
                            [
                                "status" => "Cambiado estado a impreso",
                                "results" => null
                            ],
                            200
                        );
                    } else {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de cambiar de estado al detalle',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2 changeOrderDetailAsPrinted: NO SE PUDO CAMBIAR EL ESTADO DEL DETAIL");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo cambiar de estado al detalle de la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function processPrintPreInvoice(Request $request)
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

        $store = $employee->store;

        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de imprimir pre-cuentas',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('spot_id', $request->spot_id)
                        ->where('preorder', 1)
                        ->with(
                            [
                                'orderDetails.processStatus' => function ($process) {
                                    $process->orderBy('created_at', 'DESC');
                                },
                                'spot'
                            ]
                        )
                        ->first();

                    if (!$preorder) {
                        return response()->json(
                            [
                                'status' => 'Esta orden no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $products = array_map(function ($product) {
                        return $product['id'];
                    }, $request->products);

                    $filteredDetails = collect([]);

                    $iva = 0;

                    foreach ($preorder->orderDetails as $orderDetail) {
                        if (!in_array($orderDetail->id, $products)) {
                            continue;
                        }
                        $filteredDetails->push($orderDetail);
                    }

                    $taxValues = $this->getTaxValuesFromDetails($store, $preorder->orderDetails);
                    $request->taxes = $taxValues['product_taxes'];
                    // $preorder->orderDetails = $filteredDetails;

                    $request->subtotal = ($taxValues['subtotal'] / 100);
                    $request->discountValue = ($request->discount_percentage / 100) * $request->subtotal;

                    $request->print_browser = isset($request->print_browser) ? $request->print_browser : false;
                    $printerJob = null;
                    if ($request->print_browser == true) {
                        $printerJob = PrintServiceHelper::getPreInvoiceJobs($preorder, $employee, $request);
                    } else {
                        PrintServiceHelper::printPreInvoice($preorder, $employee, $request);
                    }

                    return response()->json(
                        [
                            "status" => "Imprimiendo pre-cuenta",
                            "results" => null,
                            "printerJob" => $printerJob
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2: NO SE PUDO IMPRIMIR LA PRE-CUENTA");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo imprimir la precuenta',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function processPrintComanda(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;

        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de imprimir comandas',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    $preorder = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->where('spot_id', $request->spot_id)
                        ->where('preorder', 1)
                        ->with(
                            [
                                'orderDetails.processStatus' => function ($process) {
                                    $process->orderBy('created_at', 'DESC');
                                },
                                'spot'
                            ]
                        )
                        ->first();

                    if (!$preorder) {
                        return response()->json(
                            [
                                'status' => 'Esta orden no existe',
                                'results' => "null"
                            ],
                            404
                        );
                    }

                    if ($preorder->identifier == 0) {
                        $preorder->identifier = Helper::getNextOrderIdentifier($store->id);
                        $preorder->save();
                    }

                    $request->print_browser = isset($request->print_browser) ? $request->print_browser : false;
                    $printerJob = null;
                    if ($request->print_browser == true) {
                        $printerJob = PrintServiceHelper::getComandaJobs($preorder, $employee);
                    } else {
                        PrintServiceHelper::printComanda($preorder, $employee);
                    }

                    event(new OrderSendedToKitchen($preorder));
                    event(new OrderUpdatedComanda($preorder));

                    foreach ($preorder->orderDetails as $detail) {
                        // Log Action on Model
                        $obj = [
                            'action' => "COCINAR",
                            'model' => "ORDER",
                            'user_id' => $employee->id,
                            'model_id' => $preorder->id,
                            'model_data' => [
                                'order' => $preorder,
                                'product' => [
                                    'product_detail_id' => $detail->product_detail_id,
                                    'instruction' => is_null($detail->instruction) ? "" : $detail->instruction,
                                ]
                            ]
                        ];                    
                        
                        ActionLoggerJob::dispatch($obj);
                        // ------------------
                    }

                    return response()->json(
                        [
                            "status" => "Imprimiendo comanda",
                            "results" => null,
                            'printerJob' => $printerJob
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("OrderController API V2: NO SE PUDO IMPRIMIR LA COMANDA");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo imprimir la comanda',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /**
     * Pendiente eliminar código de productos despachados
     */

    public function getOrdersInfoComandaDigital(Request $request)
    {
        //IF rowsPerPage IS CHANGED, CHANGE VALUE IN SAME VAR FROM loadUserStore IN DigitalComanda.js
        if ($request->group_number) {
            $rowsPerPage = $request->group_number;
            if ($rowsPerPage != 5) {
                $getDispatched = true;
            } else {
                $getDispatched = false;
            }
        } else {
            $rowsPerPage = 5;
            $getDispatched = false;
        }

        $getDispatched = false;

        $employee = $this->authEmployee;
        $store = $employee->store;
        $store->load('currentCashierBalance', 'configs');
        $cashierBalance = $store->currentCashierBalance;
        $config = $store->configs;
        if ($cashierBalance) {
            $offset = ($request->page['page'] * $rowsPerPage) - $rowsPerPage;
            $pendingOrders = Order::where('cashier_balance_id', $cashierBalance->id)
                ->where('store_id', $store->id)
                ->with(
                    [
                        'orderDetails.processStatus',
                        'spot',
                        'employee'
                    ]
                )
                ->get();
            $pendingOrdersNotDispatched = collect([]);
            $pendingOrdersIDs = [];
            $completeDispatchedOffsets = [];
            $countingConsecutiveDispatched = 0;
            $firstDispatchedIndex = -1;
            $counter = 0;
            $showEmployee = false;
            $showSkuAsName = false;
            if ($config !== null) {
                $showEmployee = $config->employee_digital_comanda;
                $showSkuAsName = $config->show_search_name_comanda;
            }
            $numberNotDispatched = 0;
            $arrayNotDispatchedTrue = [];
            foreach ($pendingOrders as $pendingOrder) {
                $employeeName = "";
                if ($showEmployee) {
                    $employeeName = $pendingOrder->employee->name;
                }
                $orderDetails = $pendingOrder->orderDetails;
                $newOrderDetails = [];
                $numOrderDetailsDispatched = 0;
                //// NOTA: se usa doble foreach por el collection que retorna getDetailsUniqueGroupedByCompoundKey
                foreach ($orderDetails as $ODetail) {
                    $ODetail->append('spec_fields');
                }
                $groupedDetails = Helper::getDetailsUniqueGroupedByCompoundKey($orderDetails);
                foreach ($groupedDetails as $orderDetail) {
                    $statuses = $orderDetail['process_status'];
                    $statuses = collect($statuses);
                    if (count($statuses) > 0) {
                        $notDispatched = $statuses->pluck('process_status')->all();
                        if ($showSkuAsName) {
                            $nameProduct = $orderDetail['product_detail']['product']['search_string'];
                        } else {
                            $nameProduct = $orderDetail['product_detail']['product']['name'];
                        }
                        if (!in_array(4, $notDispatched)) {
                            array_push(
                                $newOrderDetails,
                                [
                                    "id" => $orderDetail['id'],
                                    "product" => $nameProduct,
                                    "quantity" => $orderDetail['quantity'],
                                    "instructions" => $orderDetail['spec_fields']['instructions'],
                                    "dispatched" => false,
                                ]
                            );
                        } else {
                            $numOrderDetailsDispatched += $orderDetail['quantity'];
                            array_push(
                                $newOrderDetails,
                                [
                                    "id" => $orderDetail['id'],
                                    "product" => $nameProduct,
                                    "quantity" => $orderDetail['quantity'],
                                    "instructions" => $orderDetail['instruction'],
                                    "dispatched" => true,
                                ]
                            );
                        }
                    } else {
                        $numOrderDetailsDispatched = count($orderDetails);
                    }
                }
                $secondsFromCreated = Carbon::now()->diffInSeconds(new Carbon($pendingOrder->created_at));
                $milisecondsFromCreated = $secondsFromCreated * 1000;
                if ($numOrderDetailsDispatched !== count($orderDetails)) {
                    $orderToAdd = [
                        "id" => $pendingOrder->id,
                        "identifier" => $pendingOrder->identifier,
                        "spot" => $pendingOrder->spot->name,
                        "details" => $newOrderDetails,
                        "dispatched" => false,
                        "employee" => $employeeName,
                        'created_at' => $milisecondsFromCreated
                    ];
                    $pendingOrdersNotDispatched->push(
                        $orderToAdd
                    );
                    $pendingOrdersIDs[] = $pendingOrder->id;
                    $countingConsecutiveDispatched = 0;
                    $firstDispatchedIndex = -1;
                    $numberNotDispatched += 1;
                    $arrayNotDispatchedTrue[] = 1;
                } elseif ($getDispatched) {
                    $orderToAdd = [
                        "id" => $pendingOrder->id,
                        "identifier" => $pendingOrder->identifier,
                        "spot" => $pendingOrder->spot->name,
                        "details" => $newOrderDetails,
                        "dispatched" => true,
                        "employee" => $employeeName
                    ];
                    if ($firstDispatchedIndex < 0) {
                        $firstDispatchedIndex = count($pendingOrdersNotDispatched);
                    }
                    $pendingOrdersNotDispatched->push(
                        $orderToAdd
                    );
                    $pendingOrdersIDs[] = $pendingOrder->id;
                    $countingConsecutiveDispatched = $countingConsecutiveDispatched + 1;
                    //Si existen ordenes despachadas consecutivas igual al numero de
                    // ordenes presentadas en la pagina Y
                    // la primera orden (obteniendo su indice) es multiplo de la
                    // cantidad de ordenes a presentar, se comienza a contar el siguiente grupo
                    if (
                        $countingConsecutiveDispatched === $rowsPerPage
                        && $firstDispatchedIndex % $rowsPerPage === 0
                    ) {
                        array_push($completeDispatchedOffsets, $firstDispatchedIndex);
                        $firstDispatchedIndex = -1;
                        $countingConsecutiveDispatched = 0;
                    }
                    $arrayNotDispatchedTrue[] = 0;
                }
            }
            if (count($completeDispatchedOffsets) > 0) {
                // El ultimo elemento ingresado sera el primero en ser eliminado
                rsort($completeDispatchedOffsets);
            }
            foreach ($completeDispatchedOffsets as $index) {
                // $pendingOrdersNotDispatched->slice($index, $rowsPerPage);
                $endIndex = $index + $rowsPerPage;
                $iter = $index;
                while ($iter < $endIndex) {
                    //Se eliminan los elementos que ya no estaran en la paginacion
                    //por tener la pagina llena de despachados
                    $pendingOrdersNotDispatched->pull($iter);
                    $iter = $iter + 1;
                }
            }
            $pendingOrdersNotDispatchedPage = $pendingOrdersNotDispatched->slice($offset, $rowsPerPage);
            $currentPageNotDispatched = array_slice($arrayNotDispatchedTrue, $offset, $rowsPerPage);
            $currentPageNotDispatchedCount = array_key_exists('1', $currentPageNotDispatched) ?
                array_count_values($currentPageNotDispatched)['1'] : 0;
            $nextPagesNotdispatchedCount = $numberNotDispatched - $currentPageNotDispatchedCount;
            return response()->json(
                [
                    "status" => "Órdenes sin despachar",
                    "results" => $pendingOrdersNotDispatchedPage->values()->all(),
                    "count" => count($pendingOrdersNotDispatched),
                    "ids" => $pendingOrdersIDs,
                    "total_pendings" => $numberNotDispatched,
                    "next_pages_pendings" => $nextPagesNotdispatchedCount
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'No existe apertura de caja para pedir la información de órdenes sin despachar',
                    'results' => "null"
                ],
                404
            );
        }
    }

    public function getOrdersInfoComandaDigitalSQL(Request $request)
    {
        @$rowsPerPage = $request->group_number;
        @$getDispatched = $request->dispatched;
        @$page = $request->page;
        $offset = ($page * $rowsPerPage) - $rowsPerPage;

        $employee = $this->authEmployee;
        $store = $employee->store;

        if (!$store) {
            return response()->json(
                [
                    'status' => 'No existe apertura de caja para pedir la información de órdenes sin despachar',
                    'results' => "null"
                ],
                404
            );
        }

        $detailStatus = $getDispatched ? OrderDetailProcessStatus::NONE : OrderDetailProcessStatus::DISPATCHED;

        $cashierBalance = CashierBalance::whereNull('date_close')->where('store_id', $store->id)->get()->pluck('id');

        $orders = Order::where('status', 1)->where('preorder', 0)
            ->where('store_id', $store->id)
            ->whereHas('cashierBalance', function ($query) use ($cashierBalance) {
                $query->where('id', $cashierBalance);
            })
            ->whereHas(
                'orderDetails',
                function ($query) use ($detailStatus) {
                    $query->whereDoesntHave('processStatus', function ($query) use ($detailStatus) {
                        $query->where('process_status', $detailStatus);
                    });
                }
            )
            ->orderBy('created_at', "DESC");

        $total = $orders->count();
        $pages = ceil($total / $rowsPerPage);

        $ordersData = $orders->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        $ordersCollection = array();

        foreach ($ordersData as $orden) {
            $singleOrder = array();
            $isOrderDispatched = $orden->isDispatched();
            $id = $orden->id;
            $singleOrder['id'] = $id;
            $singleOrder['employee'] = $orden->employee->id;
            $singleOrder['spot'] = $orden->spot->name;
            $singleOrder['identifier'] = $orden->identifier;
            $singleOrder['dispatched'] = $isOrderDispatched;
            $singleOrder['created_at'] = $orden->created_at;
            $newDetails = array();

            $id_details = [];

            foreach ($orden->orderDetails as $details) {
                if (!array_key_exists($details->product_detail_id . '' . $orden->id . '' . $details->lastProcessStatus()->process_status, $id_details)) {
                    $id_details[$details->product_detail_id . '' . $orden->id . '' . $details->lastProcessStatus()->process_status] = $details->product_detail_id . '' . $orden->id;
                    $singleDetail = array();
                    $isDetailDispatched = $details->lastProcessStatus()->isDispatched();
                    $singleDetail['id'] = $details->id;
                    $specs = $details->getSpecFieldsAttribute();
                    $singleDetail['product'] = $specs['name'];
                    $sumValue = $orden->sumValue($details->product_detail_id, $details->lastProcessStatus()->process_status);
                    $singleDetail['quantity'] = $sumValue['quantity'];
                    $singleDetail['base_value'] = $sumValue['base_value'];
                    $singleDetail['value'] = $sumValue['value'];
                    $singleDetail['compound_key'] = $details->compund_key;
                    $singleDetail['dispatched'] = $isDetailDispatched;
                    $instructions = $details->getSpecFieldsAttribute()['instructions'];
                    $singleDetail['instructions'] = $instructions;

                    array_push($newDetails, $singleDetail);
                }
            }

            $singleOrder['details'] = $newDetails;
            array_push($ordersCollection, $singleOrder);
        }

        return response()->json(
            [
                "status" => "Órdenes comanda digital",
                "results" => $ordersCollection,
                "orders" => $ordersData,
                'total' => $total,
                'pages' => $pages
            ],
            200
        );
    }

    public function distpatchProductDetail(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        $store->load('currentCashierBalance');
        $cashierBalance = $store->currentCashierBalance;
        if ($cashierBalance) {
            $pendingOrder = Order::where('cashier_balance_id', $cashierBalance->id)
                ->where('id', $request->id_order)
                ->where('store_id', $store->id)
                ->where('status', 1)
                ->first();
            if ($pendingOrder) {
                $orderDetail = OrderDetail::where('id', $request->id_detail)
                    ->where('order_id', $pendingOrder->id)
                    ->first();
                if ($orderDetail) {
                    try {
                        $processJSON = DB::transaction(
                            function () use ($orderDetail, $pendingOrder) {
                                $processStatusDispatched = OrderDetailProcessStatus::where('order_detail_id', $orderDetail->id)
                                    ->where('process_status', 4)
                                    ->first();
                                if ($processStatusDispatched) {
                                    return response()->json(
                                        [
                                            'status' => 'Este producto ya estaba despachado',
                                            'results' => null
                                        ],
                                        200
                                    );
                                } else {
                                    $processStatus = new OrderDetailProcessStatus();
                                    $processStatus->process_status = 4;
                                    $processStatus->order_detail_id = $orderDetail->id;
                                    $processStatus->created_at = Carbon::now()->toDateTimeString();
                                    $processStatus->updated_at = Carbon::now()->toDateTimeString();
                                    $processStatus->save();
                                    event(new OrderDispatchedComanda($pendingOrder));

                                    $hub = $pendingOrder->store->hubs->first();
                                    if ($hub != null && $pendingOrder->invoice != null) {
                                        event(new HubOrderDispatched($hub, $pendingOrder->invoice));
                                    }

                                    return response()->json(
                                        [
                                            "status" => "Producto despachado",
                                            "results" => null
                                        ],
                                        200
                                    );
                                }
                            }
                        );
                        return $processJSON;
                    } catch (\Exception $e) {
                        Log::info("OrderController API V2 distpatchProductDetail: NO SE PUDO DESPACHAR EL PRODUCTO DE LA ORDEN");
                        Log::info($e);
                        return response()->json(
                            [
                                'status' => 'No se pudo despachar el producto de la orden',
                                'results' => null
                            ],
                            409
                        );
                    }
                } else {
                    return response()->json(
                        [
                            'status' => 'Este producto no coincide con la la orden asignada',
                            'results' => null
                        ],
                        409
                    );
                }
            } else {
                return response()->json(
                    [
                        'status' => 'La orden no coincide con la apertura de caja asignada',
                        'results' => null
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'Se tiene que abrir caja antes de despachar productos',
                    'results' => null
                ],
                409
            );
        }
    }

    public function distpatchOrder(Request $request)
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

        $store = $employee->store;
        $store->load('currentCashierBalance');
        $cashierBalance = $store->currentCashierBalance;
        if ($cashierBalance) {
            $pendingOrder = Order::where('cashier_balance_id', $cashierBalance->id)
                ->where('id', $request->id_order)
                ->where('store_id', $store->id)
                ->where('status', 1)
                ->first();
            if ($pendingOrder) {
                try {
                    $processJSON = DB::transaction(
                        function () use ($pendingOrder) {
                            $orderDetails = OrderDetail::where('order_id', $pendingOrder->id)
                                ->where('status', 1)
                                ->get();
                            foreach ($orderDetails as $orderDetail) {
                                $processStatusDispatched = OrderDetailProcessStatus::where('order_detail_id', $orderDetail->id)
                                    ->where('process_status', 4)
                                    ->first();
                                if ($processStatusDispatched) {
                                    // No hacer nada ya que el producto ya se despachó anteriormente
                                } else {
                                    $processStatus = new OrderDetailProcessStatus();
                                    $processStatus->process_status = 4;
                                    $processStatus->order_detail_id = $orderDetail->id;
                                    $processStatus->created_at = Carbon::now()->toDateTimeString();
                                    $processStatus->updated_at = Carbon::now()->toDateTimeString();
                                    $processStatus->save();
                                }
                            }
                            event(new OrderDispatchedComanda($pendingOrder));

                            $hub = $pendingOrder->store->hubs->first();
                            if ($hub != null && $pendingOrder->invoice != null) {
                                event(new HubOrderDispatched($hub, $pendingOrder->invoice));
                            }

                            return response()->json(
                                [
                                    "status" => "Orden despachada",
                                    "results" => null
                                ],
                                200
                            );
                        }
                    );
                    return $processJSON;
                } catch (\Exception $e) {
                    Log::info("OrderController API V2 distpatchOrder: NO SE PUDO DESPACHAR LA ORDEN");
                    Log::info($e);
                    return response()->json(
                        [
                            'status' => 'No se pudo despachar la orden',
                            'results' => null
                        ],
                        409
                    );
                }
            } else {
                return response()->json(
                    [
                        'status' => 'La orden no coincide con la apertura de caja asignada',
                        'results' => null
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'Se tiene que abrir caja antes de despachar de órdenes',
                    'results' => null
                ],
                409
            );
        }
    }

    /**
     * Store a new order from Split Account Screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * Crea una nueva orden, cambia los order_details de la preorden original,
     * a esta nueva orden, devuelve los details e invoices de la orden para imprimir
     */
    public function createOrderFromSplitAccount(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;

        try {

            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    if ($request->has_billing && $store->configs->document_lengths !== '') {
                        $lengths = explode(',', $store->configs->document_lengths);
                        $docLength = strlen($request->billing_document) . '';
                        if (!in_array($docLength, $lengths)) {
                            return response()->json([
                                "status" => "El formato del R.U.C. es incorrecto.",
                                "results" => "null"
                            ], 409);
                        }
                    }
                    //En caso de ser cortesia se procede a comprobar que el codigo ingresado exista.
                    if ($request->isCourtesy) {
                        $isValidCourtesyCod = false;
                        $courtesyCod = $request->courtesyCode;
                        //Se obtiene todos los empleados que sean de tipo administrador
                        $administradores = Employee::where('store_id', $store->id)->where('type_employee', 1)->get();
                        foreach ($administradores as  $administrador) {
                            if ($administrador->pin_code == $courtesyCod) {
                                $isValidCourtesyCod = true;
                                break;
                            }
                        }

                        if (!$isValidCourtesyCod) {
                            return response()->json([
                                "status" => "El código de administrador ingresado es incorrecto.",
                                "results" => null
                            ], 408);
                        }
                    }

                    $now = Carbon::now()->toDateTimeString();

                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    $identifier = 0;

                    $invoiceNumber = $store->nextInvoiceBillingNumber();
                    $invoiceNumberRequest = $request->invoice_number;
                    if ($invoiceNumber === "") {
                        $invoiceNumber = $request->invoice_number;
                    }

                    $preorder = null;
                    $orderOld = null;
                    if ($request->order_details) {
                        $orderDetails = $request->order_details;

                        if (isset($orderDetails[0])) {
                            try {
                                $orderOld = isset($orderDetails[0]["order_id"]) ? $orderDetails[0]["order_id"] : null;
                            } catch (\Exception $e) {
                                $orderOld = null;
                            }
                        }

                        $orderDetailCreated = OrderDetail::where('id', $orderDetails[0]["id"])->first();
                        if ($orderDetailCreated) {
                            $preorder = Order::where('id', $orderDetailCreated->order_id)->first();
                            if ($preorder) {
                                if ($preorder->identifier == 0) {
                                    $identifier = Helper::getNextOrderIdentifier($store->id);
                                } else {
                                    $identifier = $preorder->identifier;
                                }
                            }
                        }
                    } else {
                        $identifier = Helper::getNextOrderIdentifier($store->id);
                    }

                    // Retrocompatible with old way of payments
                    $hasCreditCard = false;
                    $hasDebitCard = false;
                    $selectedCard = null;

                    if ($request->card_id) {
                        $selectedCard = Card::find($request->card_id);
                        $hasCreditCard = $selectedCard->type == 1;
                        $hasDebitCard = $selectedCard->type == 0;
                    }
                    $valueCash = null;
                    $alternateBillSequenceSwitch = false;
                    if ($request->cash == 1) {
                        $alternateBillSequenceSwitch = true;
                        if ($request->value_cash != 0) {
                            $valueCash = $request->value_cash;
                        }
                    }

                    if ($request->payments == null) {
                        $request->payments = [];
                        if ($valueCash != null) {
                            $paymentObject = [
                                "total" => $valueCash,
                                "type" => PaymentType::CASH,
                                "tip" =>  $request->tip
                            ];

                            array_push($request->payments, $paymentObject);
                        }

                        if (($hasDebitCard || $request->debit_card == 1) && $request->value_debit_card != 0) {
                            $paymentObject = [
                                "total" => $request->value_debit_card,
                                "type" => PaymentType::DEBIT,
                                "tip" =>  $request->tip
                            ];

                            if ($selectedCard != null) {
                                $paymentObject["card_selected"] = [
                                    "card_id" => $selectedCard->id,
                                    "card_last_digits" => $request->card_last_digits ?? ""
                                ];
                            }

                            array_push($request->payments, $paymentObject);
                        }

                        if (($hasCreditCard || $request->credit_card == 1) && $request->value_credit_card != 0) {
                            $paymentObject = [
                                "total" => $request->value_credit_card,
                                "type" => PaymentType::CREDIT,
                                "tip" =>  $request->tip
                            ];

                            if ($selectedCard != null) {
                                $paymentObject["card_selected"] = [
                                    "card_id" => $selectedCard->id,
                                    "card_last_digits" => $request->card_last_digits ?? ""
                                ];
                            }

                            array_push($request->payments, $paymentObject);
                        }

                        if ($request->transfer == 1 && $request->value_transfer != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_transfer,
                                    "type" => PaymentType::TRANSFER,
                                    "tip" =>  $request->tip
                                ]
                            );
                        }

                        if ($request->rappi_pay == 1 && $request->value_rappi_pay != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_rappi_pay,
                                    "type" => PaymentType::RAPPI_PAY,
                                    "tip" =>  $request->tip
                                ]
                            );
                        }

                        if ($request->other == 1 && $request->value_other != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_other,
                                    "type" => PaymentType::OTHER,
                                    "tip" =>  $request->tip
                                ]
                            );
                        }

                        $request->payments = array_values($request->payments);
                    }

                    $employeeId = $preorder !== null
                        ? $preorder->employee_id
                        : $employee->id;

                    if ($request->employee_id) {
                        $employeeId = $request->employee_id;
                    }

                    $device_id = $store->company_id . $store->id . $request->ip();

                    //Rescatamos el total de propinas
                    $request->tip = $this->totalTips($request->payments);

                    $order = Order::create(
                        array_merge(
                            $request->all(),
                            [
                                'employee_id' => $employeeId,
                                'store_id' => $store->id,
                                'identifier' => $identifier,
                                'cashier_balance_id' => $cashierBalance->id,
                                'discount_percentage' => $request->discount_percentage,
                                'discount_value' => $request->discount_value,
                                'undiscounted_base_value' => $request->undiscounted_base_value,
                                'device_id' => $device_id,
                                'tip' => $request->tip,
                                'is_courtesy' => $request->isCourtesy != null ? $request->isCourtesy : false
                            ]
                        )
                    );

                    $order->people = $request->people;
                    $order->custom_identifier = $request->custom_identifier;

                    if ($request->has_billing) {
                        $billing = Billing::where('document', $request->billing_document)->first();
                        if ($billing) {
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address ? $request->billing_address :
                                $billing->address;
                            $billing->phone = $request->billing_phone ? $request->billing_phone : $billing->phone;
                            $billing->email = $request->billing_email ? $request->billing_email : $billing->email;

                            if (!preg_match('/[^9]/', $request->billing_document)) {
                                $billing->address = $request->billing_address;
                                $billing->phone = $request->billing_phone;
                                $billing->email = $request->billing_email;
                            }

                            $billing->is_company = $request->billing_is_company || $request->billing_is_company == false ? $request->billing_is_company : $billing->is_company;
                            $billing->company_checkdigit = $request->billing_company_checkdigit || $request->billing_company_checkdigit == "0" ? (int) $request->billing_company_checkdigit : $billing->company_checkdigit;
                            $billing->document_type = $request->billing_document_type;
                            $billing->company_pay_iva = $request->billing_company_pay_iva || $request->billing_company_pay_iva == false ? $request->billing_company_pay_iva : $billing->company_pay_iva;
                            $billing->city = $request->billing_city ? $request->billing_city : $billing->city;
                            $billing->save();
                        } else {
                            $billing = new Billing();
                            $billing->document = $request->billing_document;
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address;
                            $billing->phone = $request->billing_phone;
                            $billing->email = $request->billing_email;
                            $billing->is_company = $request->billing_is_company;
                            $billing->company_checkdigit = (int) $request->billing_company_checkdigit;
                            $billing->document_type = $request->billing_document_type;
                            $billing->company_pay_iva = $request->billing_company_pay_iva;
                            $billing->city = $request->billing_city;
                            $billing->save();
                        }
                        $order->billing_id = $billing->id;
                        $order->save();
                    } else {
                        $billing = Billing::firstOrCreate([
                            'document' => '9999999999999',
                            'name'     => 'CONSUMIDOR FINAL'
                        ]);
                        $billing->address = "";
                        $billing->phone = "";
                        $billing->email = "";
                    }
                    foreach ($request->order_details as $orderDetail) {
                        $orderDetailCreated = OrderDetail::where('id', $orderDetail["id"])->first();
                        if ($orderDetailCreated) {
                            $orderDetailCreated->order_id = $order->id;
                            if ($request->isCourtesy) {
                                $orderDetailCreated->value = 0;
                                $orderDetailCreated->total = 0;
                                $orderDetailCreated->base_value = 0;
                            }
                            $orderDetailCreated->save();
                        }
                    }
                    //Si posee habilitada la bandera de cortesia se procede a encerar los totales de orden
                    if ($request->isCourtesy) {
                        $order->order_value = 0;
                        $order->total = 0;
                        $order->base_value = 0;
                        //$order->food_service=0;
                        $order->discount_percentage = 0;
                        $order->discount_value = 0;
                        $order->undiscounted_base_value = 0;
                        $order->change_value = 0;
                        $order->no_tax_subtotal = 0;
                        $order->tip = 0;
                        $order->save();
                    }

                    $order = $this->calculateOrderValues($order);
                    $this->calculateOrderValues($preorder);

                    event(new OrderUpdatedComanda($preorder));
                    // why is this here?
                    if (count($preorder->orderDetails) === 0) {
                        $preorder->delete();
                    }

                    $invoice = InvoiceHelper::createInvoice(
                        $order,
                        $billing,
                        $request->food_service,
                        $invoiceNumber,
                        true
                    );

                    $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');
                    $officialInvoiceNumber = Helper::getNextBillingOfficialNumber($store->id, true);
                    // $alternateBill = Helper::getAlternatingBillingNumber(
                    //     $store->id,
                    //     $alternateBillSequenceSwitch
                    // );
                    // if ($alternateBill != "") {
                    //     $officialInvoiceNumber = $alternateBill;
                    // }
                    if ($officialInvoiceNumber != "") {
                        $invoice->invoice_number = $officialInvoiceNumber;
                        $invoice->save();
                    }

                    //ejecuta las integraciones de billing activas para la tienda
                    OrderHelper::prepareToSendForElectronicBillingStatic(
                        $store,
                        $invoice,
                        AvailableMyposIntegration::NAME_NORMAL,
                        null,
                        AvailableMyposIntegration::NAME_SIIGO,
                        [
                            'cashier' => null,
                            'invoice' => $invoice
                        ]
                    );

                    $this->reduceComponentsStock($order);
                    $this->reduceComponentsStockBySpecification($order);

                    // Agregando especificaciones dentro del campo instrucciones
                    $newOrders = [];
                    $newOrderDetails = collect([]);
                    foreach ($order->orderDetails as $storedOrderDetail) {
                        $storedOrderDetail->append('spec_fields');
                        $newOrderDetails->push($storedOrderDetail);
                    }

                    $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($invoice->items);

                    $taxValues = $this->getTaxValuesFromDetails($store, $order->orderDetails);
                    $invoice->noTaxSubtotal = $taxValues['no_tax_subtotal'];
                    $invoice->productTaxes = $taxValues['product_taxes'];

                    if (config('app.slave')) {
                        $pendingSyncing = new PendingSync();
                        $pendingSyncing->store_id = $store->id;
                        $pendingSyncing->syncing_id = $order->id;
                        $pendingSyncing->type = "order";
                        $pendingSyncing->action = "insert";
                        $pendingSyncing->save();
                    }

                    $spot = Spot::find($order->spot_id);

                    // Reemplazar a la mesa fija de kiosko (por reportes)
                    if ($spot->isTmp()) {
                        $realSpot = Spot::getSpotFromTmp($spot);
                        $order->spot_id = $realSpot->id;
                        $order->save();

                        // Borrar la mesa
                        event(new SpotDeleted($spot->toArray()));
                        $spot->delete();

                        $spot = $realSpot;
                    }

                    // Crear orden integration o actualizar info si viene de una mesa de externos integración
                    if (
                        !$spot->isNormal()
                        && !$spot->isSplit()
                        && !$spot->isTmp()
                        && !$spot->isDelivery()
                    ) {
                        $existOrderIntegration = OrderIntegrationDetail::where(
                            'order_id',
                            $order->id
                        )->first();
                        if ($existOrderIntegration != null) {
                            $detailsCollection = collect($order->orderDetails);
                            $orderDetailsGroup = $detailsCollection->groupBy('compound_key')->toArray();
                            $existOrderIntegration->number_items = count($orderDetailsGroup);
                            $existOrderIntegration->value = $order->total;
                            $existOrderIntegration->save();
                        } else {
                            $integrationName = Spot::getNameIntegrationByOrigin($spot->origin);

                            if ($integrationName == "") {
                                throw new \Exception("No existe esta mesa de servicio externo");
                            }
                            $orderIntegration = new OrderIntegrationDetail();
                            $orderIntegration->order_id = $order->id;
                            $orderIntegration->integration_name = $integrationName;
                            $orderIntegration->external_order_id = $request->external_order_id;
                            $orderIntegration->number_items = 1;
                            $orderIntegration->value = $order->total;
                            $orderIntegration->save();

                            // Mesas de integración va siempre a crédito
                            $order->change_value = null;

                            $order->save();

                            //Set and save the payments.
                            $typeToSetPayments = $spot->isRappiAntojo() ? PaymentType::CASH : PaymentType::CREDIT;
                            $this->setPayments($request, $order, $now, $typeToSetPayments);
                        }

                        if ($store->hubs != null && $store->hubs->first() != null) {
                            event(new HubIntegrationOrderCreated($store->hubs->first(), $invoice));
                        }
                    } else {
                        //Organiza los pagos de la orden y los guarda
                        $this->setPayments($request, $order, $now);
                    }

                    $job = array();
                    $order->load('spot','employee','orderIntegrationDetail','invoice','orderConditions','orderStatus');
                    $job["store_id"] = $store->id;
                    $job["order"] = $order;
    
                    QueueHelper::dispatchJobs(array($job));

                    if ($store->id == 556 || $store->id == 611 || $store->id == 374 || $store->id == 389 || $store->id == 573) {
                       PrintServiceHelper::printComanda($order, $employee);
                    }
                    $request->print_factura = isset($request->print_factura) ? $request->print_factura : true;
                    $request->print_browser = isset($request->print_browser) ? $request->print_browser : false;
                    $printerJob = [];
                    if ($request->print_factura == false || $request->print_browser == true) {
                        $printerJob = PrintServiceHelper::getInvoiceJobs($invoice, $employee, false);
                    } else {
                        PrintServiceHelper::printInvoice($invoice, $employee);
                    }

                    $invoiceCollection = collect($invoice);
                    $invoiceCollection->forget('items');
                    $invoiceCollection->put('items', $detailsGrouped);

                    event(new OrderCreated($order->id));
                    event(new CompanyOrderCreatedEvent($order));

                    event(new OrderCreatedOffline($order->id, $orderOld));

                    return response()->json([
                        "status" => "Orden creada con éxito",
                        "results" => $newOrderDetails,
                        "identifier" => $order->identifier,
                        "invoice" => $invoiceCollection,
                        "printerJob" => $printerJob
                    ], 200);
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            $this->logError(
                "OrderController API V2 createOrderFromSplitAccount: ERROR GUARDAR ORDEN, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json([
                'status' => 'No se pudo crear la orden',
                'results' => "null"
            ], 409);
        }
    }

    /**
     * Set and save the payments.
     * @param Illuminate\Http\Request $request
     * @param App\Order $order
     * @param timestamp $now - same timestamp from Order
     * @param integer $type - predefined type
     *
     * @return Boolean
     */
    public function setPayments(Request $request, Order $order, $now, $type = null)
    {
        $now = Carbon::now()->toDateTimeString();
        $payments = $request->payments;

        foreach ($payments as $paymentObject) {
            $paymentObject = (object) $paymentObject;

            $payment = new Payment();
            $payment->created_at = $now;
            $payment->updated_at = $now;
            $payment->order_id = $order->id;
            $payment->total = $paymentObject->total;
            $payment->type = $type != null ? $type : $paymentObject->type;
            $payment->tip = isset($paymentObject->tip) ? $paymentObject->tip : 0;
            $payment->change_value = isset($paymentObject->change_value) ? $paymentObject->change_value : 0;
            $payment->received = isset($paymentObject->received) ? $paymentObject->received : 0;

            //Determina si se trata de un pago con tarjeta
            $isCard = in_array($paymentObject->type, [PaymentType::DEBIT, PaymentType::CREDIT]);
            if ($isCard) {
                $payment->card_last_digits = $paymentObject->card_selected['card_last_digits'];
                $payment->card_id = $paymentObject->card_selected['card_id'];
            }

            $payment->save();

            if (!empty($paymentObject->integration)) {
                $paymetId = $paymentObject->integration['externalId'];
                $paymentIntegration = PaymentIntegrationDetail::where('reference_id', $paymetId)->first();

                if (!empty($paymentIntegration)) {
                    $paymentIntegration->payment_id = $payment->id;
                    $paymentIntegration->save();

                    $orderPaymentInt = OrderHasPaymentIntegration::where('id', $paymentIntegration->order_payment_integration)->first();
                    $orderPaymentInt->order_id = $order->id;
                    $orderPaymentInt->save();
                }
            }
        }
    }

    public function getNextBillingNumber()
    {
        $employee = $this->authEmployee;
        if ($employee === null) {
            return response()->json(
                [
                    'status' => 'Error al obtener el recurso',
                    'results' => null
                ],
                409
            );
        }
        $nextBillingNumber = "";
        if ($employee->store->country_code == "CO") {
            $nextBillingNumber = Helper::getNextBillingOfficialNumber($employee->store->id);
        } else {
            $nextBillingNumber = $employee->store->nextInvoiceBillingNumber();
        }
        return response()->json(
            [
                "status" => "Número de factura",
                "results" => $nextBillingNumber
            ],
            200
        );
    }

    public function reprint(Request $request)
    {
        $employee = $this->authEmployee;

        $printComanda = $request->print_comanda;
        $orderId = $request->id_order;
        $dataInvoice = $request->data;

        $order = Order::where('id', $orderId)->where('preorder', 0)->first();
        if (!$order) {
            $this->simpleLogError(
                "OrderController API V2 reprint order: NO SE PUDO REIMPRIMIR LA ORDEN",
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'Esta orden no existe',
                    'results' => null
                ],
                409
            );
        }
        $printerJob = null;
        $request->print_browser = isset($request->print_browser) ? $request->print_browser : false;
        if ($printComanda) {


            if ($request->print_browser == true) {
                $printerJob =  PrintServiceHelper::getComandaJobs($order, $employee);
            } else {
                PrintServiceHelper::printComanda($order, $employee);
            }
        } else {
            $invoice = Invoice::where('order_id', $orderId)->first();
            if ($dataInvoice["name"] == "" || $dataInvoice["document"] == "") {
                $this->simpleLogError(
                    "OrderController API V2 reprint invoice1: NO SE PUDO REIMPRIMIR LA ORDEN",
                    $request->all()
                );
                return response()->json(
                    [
                        'status' => 'El nombre y el documento de identificación no pueden ser vacíos',
                        'results' => null
                    ],
                    409
                );
            }
            $billing = null;
            if ($dataInvoice["id"] == null) {
                $billing = new Billing();
                $billing->document = $dataInvoice["document"];
                $billing->name = $dataInvoice["name"];
                $billing->address = $dataInvoice["address"] ?? "";
                $billing->phone = $dataInvoice["phone"] ?? "";
                $billing->email = $dataInvoice["email"] ?? "";
                $billing->save();
                $order->billing_id = $billing->id;
            } else {
                $billing = Billing::where('id', $dataInvoice["id"])->first();
            }
            $invoice->name = $dataInvoice["name"];
            $invoice->document = $dataInvoice["document"];
            $invoice->address = $dataInvoice["address"] ?? "";
            $invoice->email = $dataInvoice["email"] ?? "";
            $invoice->phone = $dataInvoice["phone"] ?? "";
            if ($billing != null) {
                $invoice->billing_id = $billing->id;
            }
            $invoice->save();
            if (!$invoice) {
                $this->simpleLogError(
                    "OrderController API V2 reprint invoice2: NO SE PUDO REIMPRIMIR LA ORDEN",
                    $request->all()
                );
                return response()->json(
                    [
                        'status' => 'Esta factura no existe',
                        'results' => null
                    ],
                    409
                );
            }
            $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');

            $taxValues = $this->getTaxValuesFromDetails($employee->store, $order->orderDetails);
            $invoice->noTaxSubtotal = $taxValues['no_tax_subtotal'];
            $invoice->productTaxes = $taxValues['product_taxes'];


            if ($request->print_browser == true) {
                $printerJob = PrintServiceHelper::getInvoiceJobs($invoice, $employee, false);
            } else {
                PrintServiceHelper::printInvoice($invoice, $employee);
            }
        }

        /**
         * Job dispatch                     
         */
        $obj = [
            'action' => "REPRINT",
            'model' => "ORDER",
            'user_id' => $employee->id,
            'model_id' => $order->id,
            'model_data' => [
                "order" => $order,
                "user" => $employee
            ]
        ];                    
        
        ActionLoggerJob::dispatch($obj);

        return response()->json(
            [
                "status" => "Imprimiendo",
                "results" => null,
                "printerJob" => $printerJob
            ],
            200
        );
    }

    public function changeOrderSpotEmployee(Request $request)
    {
        $employee = $this->authEmployee;

        $spot = Spot::find($request->spot_id);
        if (!$spot) {
            $this->simpleLogError(
                "OrderController API V2 changeOrderSpotEmployee spot: NO SE PUDO OBTENER LA MESA",
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'La mesa seleccionada no existe',
                    'results' => null
                ],
                404
            );
        }
        $toEmployee = Employee::find($request->employee_id);
        if (!$toEmployee) {
            $this->simpleLogError(
                "OrderController API V2 changeOrderSpotEmployee employee2: NO SE PUDO OBTENER EL EMPLEADO CAMBIANTE",
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'El empleado seleccionado no existe',
                    'results' => null
                ],
                404
            );
        }
        $lastOpenedOrder = Order::where('preorder', 1)->where('spot_id', $spot->id)
            ->where('status', 1)->orderBy('id', 'desc')->first();
        if (!$lastOpenedOrder) {
            $this->simpleLogError(
                "OrderController API V2 changeOrderSpotEmployee order: NO SE PUDO OBTENER LA ORDEN",
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'La mesa no tiene orden abierta',
                    'results' => null
                ],
                404
            );
        }
        $lastOpenedOrder->employee_id = $toEmployee->id;
        $lastOpenedOrder->save();
        return response()->json(
            [
                'status' => 'Empleado cambiado exitosamente',
                'results' => null
            ],
            200
        );
    }

    public function deleteOrder(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        if ($store) {
            try {
                $orderJSON = DB::transaction(
                    function () use ($request, $employee, $store) {
                        $store->load('currentCashierBalance');
                        $cashierBalance = $store->currentCashierBalance;
                        if ($cashierBalance) {
                            $order = Order::where('cashier_balance_id', $cashierBalance->id)
                                ->with('orderDetails.processStatus')
                                ->where('store_id', $store->id)
                                ->where('preorder', 0)
                                ->where('id', $request->id_order)
                                ->first();
                            if (!$order) {
                                return response()->json(
                                    [
                                        'status' => 'Esta orden no existe',
                                        'results' => "null"
                                    ],
                                    404
                                );
                            }

                            if ($employee->isCashier() && !$request->input('force_delete', false)) {
                                foreach ($order->orderDetails as $orderDetail) {
                                    $statuses = $orderDetail->processStatus->pluck("process_status")->toArray();
                                    if (in_array(2, $statuses)) {
                                        return response()->json(
                                            [
                                                'status' => '¡La orden ya se está preparando, acérquese a caja!',
                                                'results' => "null"
                                            ],
                                            409
                                        );
                                    }
                                }
                            }
                            $order->status = 2;
                            $order->observations = $request->observations;
                            $order->updated_at = Carbon::now()->toDateTimeString();
                            $order->save();

                            return response()->json(
                                [
                                    "status" => "Orden eliminada con éxito",
                                    "results" => null,
                                ],
                                200
                            );
                        } else {
                            return response()->json(
                                [
                                    'status' => 'Se tiene que abrir caja antes de eliminar órdenes',
                                    'results' => "null"
                                ],
                                404
                            );
                        }
                    }
                );
                return $orderJSON;
            } catch (\Exception $e) {
                Log::info("OrderController API V2: No se pudo eliminar la orden");
                Log::info($e);
                return response()->json(
                    [
                        'status' => 'No se pudo eliminar la orden',
                        'results' => "null"
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'Error al anular la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /**
     *  Deshabilitado por desuso:
     *  Funcionalidad solo disponible para el admin:
     *  OrderControler -> revokeOrder
     */
    public function revoke(Request $request)
    {
        $employee = $this->authEmployee;
        try {
            $store = $employee->store;
            $store->load('currentCashierBalance', 'configs');
            $config = $store->configs;
            if (!$config->allow_revoke_orders) {
                return response()->json(
                    [
                        'status' => 'Operación no permitida',
                        'results' => "null"
                    ],
                    403
                );
            }

            DB::transaction(
                function () use ($request, $employee, $store) {
                    $cashierBalance = $store->currentCashierBalance;
                    if (!$cashierBalance) {
                        return response()->json(
                            [
                                'status' => 'Se tiene que abrir caja antes de eliminar órdenes',
                                'results' => "null"
                            ],
                            404
                        );
                    }
                    $order = Order::where('cashier_balance_id', $cashierBalance->id)
                        ->with('orderDetails.processStatus')
                        ->where('store_id', $store->id)
                        ->where('preorder', 0)
                        ->where('id', $request->id_order)
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
                    if ($employee->isCashier() && !$request->input('force_delete', false)) {
                        foreach ($order->orderDetails as $orderDetail) {
                            $statuses = $orderDetail->processStatus->pluck("process_status")->toArray();
                            if (in_array(2, $statuses)) {
                                return response()->json(
                                    [
                                        'status' => '¡La orden ya se está preparando, acérquese a caja!',
                                        'results' => "null"
                                    ],
                                    409
                                );
                            }
                        }
                    }

                    // Revert consumption movement
                    if ($request->modifies_inventory == true) {
                        $consumptionMovements = StockMovement::where('order_id', $request->id_order)->get(); // Array
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
                                array_push(
                                    $revokeMovementsArray,
                                    [
                                        'inventory_action_id' => $revokeActionInventory->id,
                                        'initial_stock' => $lastComponentStock,
                                        'value' => $consumptionValue,
                                        'final_stock' => $lastComponentStock + $consumptionValue, // Se repone el consumo
                                        'cost' => $consumptionMov->cost, // El mismo costo de la orden
                                        'component_stock_id' => $componentStockId,
                                        'order_id' => $request->id_order,
                                        'created_by_id' => $store->id,
                                        'created_at' => $now,
                                        'updated_at' => $now
                                    ]
                                );
                            }
                            StockMovement::insert($revokeMovementsArray);
                        }
                    }

                    $order->status = 2;
                    $order->current_status = 'Anulada';
                    $order->observations = $request->observations;
                    $order->updated_at = Carbon::now()->toDateTimeString();
                    $order->save();
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
            Log::info("OrderController API V2 revoke (employee): No se pudo eliminar la orden");
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

    /**
     * Function that returns a group of products according to the
     * filter = 1 productos
     * filter = 2 categorias
     * filter = 3 tiendas/productos
     */
    public function foodCost(Request $request)
    {
        $stores = $request->stores;
        $filter = $request->filter; // if this is productos, categorias o tiendas
        $is_avg = $request->avg ? true : $request->avg; // true ->promedio : false -> ultimo costo

        $store = $this->authStore;
        $company = $store->company;
        $company_id = $company->id;
        $store_id = $store->id;
        $storeIds = $stores; //Store::where('company_id', $company_id)->pluck('id')->toArray();
        $timezone = $store->configs->time_zone ? $store->configs->time_zone : 'America/Guayaquil';
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

        $data = $this->returnMargenData($stores, $filter, $startDate, $endDate, $storeIds, $is_avg);

        return response()->json(
            [
                "status" => "Busqueda completa con exito",
                "results" => $data,
            ],
            200
        );
    }


    /**
     * Function that returns the cost, margen of contribution, income from selling 
     * classified by stores, product or category of product
     */
    public function returnMargenDataFilter($startDate, $endDate, $storeIds, $grouping, $filter, $avg)
    {
        Log::info("returnMargenDataFilter");
        $orderIds = Order::select('id')
            ->whereIn('store_id', $storeIds)
            ->where('status', 1)
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->has('invoice')->get()->pluck('id');

        $component_price = array();

        $query = OrderDetail::whereIn('order_id', $orderIds)
            ->where('status', 1)
            ->with([
                'order', 'productDetail', 'productDetail.product',
                'productDetail.product.variations',
                'productDetail.product.category'
            ])
            ->with(array('productDetail.product.components' => function ($query) {
                $query->where('status', 1);
            }))
            ->get()
            ->groupBy($grouping)
            ->map(function ($details) use ($storeIds, &$component_price, $filter, $startDate, $endDate, $avg) {
                $payment = $details->unique(function ($detail) {
                    return $detail->order->id;
                })->map(function ($orderDets) {
                    $payments = $orderDets->order->payments->sum('total');
                    return [
                        'total' => $payments,
                    ];
                });

                $single_store = $details[0]->order->store->id;

                $order_costs = $details
                    ->groupBy(function ($value) {
                        return $value->product_detail_id;
                    })
                    ->map(function ($product) use ($single_store, &$component_price, $startDate, $endDate, $avg) {
                        $result = 0;
                        $quantity = $product->sum('quantity');
                        $limit_components = $product[0]['productDetail']['product']['components']->pluck('component_id');
                        $consumptions = $product[0]->productDetail->product->components->pluck('consumption', 'component_id')->toArray();
                        $value_product = 0;
                        foreach ($limit_components as $componentId) {
                            $key = $componentId . '' . $single_store;
                            $consumption = $consumptions[$componentId] ? (float) $consumptions[$componentId] : 0;
                            if ($avg) {
                                $component_val = array_key_exists($key, $component_price) ?
                                    $component_price[$key] :
                                    ComponentHelper::getPromValue($componentId, $single_store, $startDate, $endDate, false)
                                    * $consumption;
                            } else {
                                $component_val = array_key_exists($key, $component_price) ?
                                    $component_price[$key] :
                                    ComponentHelper::getPromValue($componentId, $single_store, $startDate, $endDate, true)
                                    * $consumption;
                            }

                            $component_price[$key] = $component_val;
                            $value_product += $component_val == null ? 0 : $component_val;
                        }
                        $result = $value_product * $quantity;

                        return [
                            'cost' => $result,
                            'quantity' => $quantity,
                            'price' => $product->sum('total')
                        ];
                    });

                $paymentsTotal = $payment->reduce(function ($carry, $item) {
                    return $carry + $item['total'];
                }, 0);

                $cost = $order_costs->reduce(function ($carry, $item) {
                    return $carry + $item['cost'];
                }, 0);

                $quantity = $order_costs->reduce(function ($carry, $item) {
                    return $carry + $item['quantity'];
                }, 0);

                $price_product = $order_costs->reduce(function ($carry, $item) {
                    return $carry + $item['price'];
                }, 0);

                $total_q = $filter > 2 ? $paymentsTotal == 0 ? 1 : $paymentsTotal : $price_product;

                $total_p = ($total_q - $cost);

                //agregar un group by que sea por el product detail

                $ordersTotal = $details->pluck('order')->pluck('id')->unique()->values()->all();

                $quantity_v =  $filter > 2 ? sizeof($ordersTotal) : $quantity;

                $total_d = $total_q == 0 ? 1 : $total_q;

                return [
                    'id' => $details[0]->productDetail->product->id,
                    'name' => $details[0]->name_product,
                    'category' => $details[0]->productDetail->product->category['name'],
                    'ventas' => round($total_q, 2),
                    'cost' => $cost,
                    'margen_percentage' => ($total_p / $total_d),
                    'margen_contrib' => $total_p,
                    'quantity' => $quantity_v,
                    'store_id' => $details[0]->order->store->id,
                    'store_name' => $details[0]->order->store->name,
                ];
            })
            ->toArray();

        return array_values($query);
    }


    public function returnMargenData($stores, $filter, $startDate, $endDate, $storeIds, $avg)
    {
        $data = [];
        $grouping = "order.store_id";

        if ($filter == 1) {
            $grouping = "productDetail.product.name";
        } elseif ($filter == 2) {
            $grouping = "productDetail.product.category.name";
        } else {
            $grouping = "order.store_id";
        }

        $data = $this->returnMargenDataFilter($startDate, $endDate, $storeIds, $grouping, $filter, $avg);

        return $data;
    }

    public function exportExcel(Request $request)
    {
        try {
            $stores = $request->stores;
            $filter = $request->filter; // if this is productos, categorias o tiendas
            $avg = $request->avg ? true : $request->avg; // true ->promedio : false -> ultimo costo
            $store = $this->authStore;
            $company = $store->company;
            $company_id = $company->id;
            $store_id = $store->id;
            $storeIds = Store::where('company_id', $company_id)->pluck('id')->toArray();
            $timezone = $store->configs->time_zone ? $store->configs->time_zone : 'America/Guayaquil';
            if (!$request->startDate) {
                $startDate = Carbon::now()->startOfDay();
            } else {
                $startDate = TimezoneHelper::localizedDateForStore($request->startDate, $store);
            }

            if (!$request->endDate) {
                $endDate = Carbon::now()->endOfDay();
            } else {
                $endDate = TimezoneHelper::localizedDateForStore($request->endDate, $store);
            }

            $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $excel->getProperties()->setTitle("myPOS");

            // Primera hoja donde apracerán detalles del objetivo
            $sheet = $excel->getActiveSheet();
            $excel->getActiveSheet()->setTitle("Reporte de Food Cost Margenes"); // Max 31 chars
            $excel->getDefaultStyle()
                ->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $excel->getDefaultStyle()
                ->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $lineaSheet = array();
            $nombreEmpresa = ['titulo' => '', 'titulo2' => '', 'titulo3' => 'myPOS'];
            $num_fila = 5; // Ubicar los datos desde la fila 5
            array_push($lineaSheet, $nombreEmpresa);
            array_push($lineaSheet, []);
            array_push($lineaSheet, []);
            array_push($lineaSheet, []);

            $columnas = array(
                'Productos', // A5
                'Ventas', // B5
                'Costo', // C5
                'Margen', //D5
                'Contribución' //E5
            );

            $columnas[0] = $filter == 2 ? 'Categorias' : $columnas[0];
            $columnas[0] = $filter == 3 ? 'Tiendas' : $columnas[0];

            $campos = array();
            foreach ($columnas as $col) {
                $campos[$col] = $col;
            }
            array_push($lineaSheet, $campos);
            // Format column headers
            $sheet->getStyle('A5:F5')->getFont()->setBold(true)->setSize(12);
            $sheet->getColumnDimension('a')->setWidth(50);
            $sheet->getColumnDimension('b')->setWidth(25);
            $sheet->getColumnDimension('c')->setWidth(15);
            $sheet->getColumnDimension('d')->setWidth(15);
            $sheet->getColumnDimension('e')->setWidth(15);

            $reportResults = $this->returnMargenData($stores, $filter, $startDate, $endDate, $storeIds, $avg);
            $data = $reportResults;
            foreach ($data as $d) {
                $name = $filter == 2 ? $d['category'] : $d['name'];
                $name = $filter == 3 ? $d['store_name'] : $name;
                array_push($lineaSheet, [
                    'Productos' => $name,
                    'Ventas' => round($d['ventas'] / 100, 2),
                    'Costo' => $d['cost'] == "" ? "0" : round($d['cost'] / 100, 2),
                    'Margen' => round($d['margen_percentage'], 2),
                    'Contribucion' => round($d['margen_contrib'] / 100, 2),
                ]);
                $num_fila++;
                $sheet->getStyle('B' . $num_fila)
                    ->getNumberFormat()
                    ->setFormatCode(
                        \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
                    );
                $sheet->getStyle('C' . $num_fila)
                    ->getNumberFormat()
                    ->setFormatCode(
                        \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
                    );
                $sheet->getStyle('E' . $num_fila)
                    ->getNumberFormat()
                    ->setFormatCode(
                        \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
                    );
            }

            $sheet->mergeCells('a1:e4');
            $sheet->getStyle('a1:e4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

            $sheet->getStyle('b1:c1')->getFont()->setBold(true)->setSize(28);
            $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
            $sheet->getStyle('b1:c1')->applyFromArray($st);
            $sheet->freezePane('A6');
            // Format headers borders
            $estilob = array(
                'borders' => array(
                    'allBorders' => array(
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK
                    )
                ),
                'alignment' => array(
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                )
            );
            $sheet->getStyle('A5:E5')->applyFromArray($estilob);

            $sheet->fromArray($lineaSheet);
            $excel->setActiveSheetIndex(0);

            // Set logo at header
            $imagen = public_path() . '/images/logo.png';
            $obj = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $obj->setName('Logo');
            $obj->setDescription('Logo');
            $obj->setPath($imagen);
            $obj->setWidthAndHeight(160, 75);
            $obj->setCoordinates('A1');
            $obj->setWorksheet($excel->getActiveSheet());

            $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Xls');
            $nombreArchivo = 'Reporte de Food Cost ' . Carbon::today()->format("d-m-Y");
            $response = response()->streamDownload(function () use ($objWriter) {
                $objWriter->save('php://output');
            });
            $response->setStatusCode(200);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
            $response->send();
        } catch (\Exception $e) {
            Log::info("NO SE PUDO GENERAR EL EXCEL DEL REPORTE DE FOOD COST");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte'
            ], 500);
        }
    }
    public function acceptOrder(Request $request)
    {
        
        $order_id=  $request->id;
        $store_id= $request->store_id;
        $orderJSON = DB::transaction(
            function () use ($order_id, $store_id) {
                //Se procede a recuperar la orderintegration
                $order_integration= OrderIntegrationDetail::where('order_id',$order_id)
                ->first();

                $order = Order::where('id',$order_id)->first();

                if($order_integration==null){
                    return response()->json(
                        [
                            "status" => "La orden no posee un registro de integración",
                            "results" =>"",
                        ],
                        409
                    );
                }
                $store= Store::with('eatsIntegrationToken','configs')
                    ->where('id',$store_id)
                    ->first();
                //Se acepta la orden dependiendo de la integración que posea.
                switch ($order_integration->integration_name) {
                    case AvailableMyposIntegration::NAME_EATS:
                        $integration=$store->eatsIntegrationToken;
                        if (is_null($integration)) {
                            return response()->json(
                                [
                                    "status" => "La tienda no posee un token para uber eats.",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        //Se acepta la orden Uber.
                        $baseUrl = config('app.eats_url_api');
                        $client = new FileGetContents(new Psr17Factory());
                        $browser = new Browser($client, new Psr17Factory());
                    

                        UberRequests::initVarsUberRequests(
                            "uber_orders_logs",
                            "#integration_logs_details",
                            $baseUrl,
                            $browser
                        );
                        // Enviando Respuesta OK a Uber Eats de que se creó la orden en myPOS
                        $resultDetails = UberRequests::sendConfirmationEvent(
                            $integration->token,
                            $store->name,
                            (string) $order_integration->external_order_id
                        );
                        if ($resultDetails["status"] == 0) {
                            // Falló en confirmar la orden
                            return response()->json(
                                [
                                    "status" => "No se pudo enviar la aceptación del pedido",
                                    "results" =>"",
                                ],
                                409
                            );
                        }

                        break;
                    case AvailableMyposIntegration::NAME_RAPPI:
                 
                        $storeToken = StoreIntegrationToken::where('store_id', $store_id)
                            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
                            ->where('is_anton', true)
                            ->first();
                            if(!is_null($storeToken)){
                                if($storeToken->anton_password==null || $storeToken->anton_password==""){
                                    $accessToken = MelyRequest::getAccessToken();
                                    if($accessToken["success"]!=true){
                                        return response()->json(
                                            [
                                                'status' => false,
                                                'message' => "La tienda no tiene configurada la integración con anton"
                                            ],
                                            409
                                        );
                                    }
                                    $token =  $accessToken["data"]['data']['token_type']." ".$accessToken["data"]['data']['token'];
                                    $storeToken->anton_password = $token;
                                    $storeToken->save();
                                    $storeToken->password = $token;
                                }
                            }else{
                                return response()->json(
                                    [
                                        'status' => false,
                                        'message' => "La tienda no posee un token."
                                    ],
                                    409
                                );
                            }
                        
                            $customStatus=[
                                "delivery_id"=> "2",
                                "store_id"=> $storeToken->token_type
                            ];
                            $responseMely = MelyIntegration::acceptOrderMely($order_integration->external_order_id, $storeToken, 0,$customStatus);
                            if (!$responseMely) {
                                return response()->json(
                                    [
                                        "status" => "No se pudo aceptar la orden.",
                                        "results" =>"",
                                    ],
                                    409
                                );
                            }
                        break;
                    case  AvailableMyposIntegration::NAME_DIDI:
                        //Se procede a traer el external store_id 
                        
                        $store_integration = StoreIntegrationId::where('store_id',$store_id)
                            ->where('integration_id', 7)
                            ->first();
                        if ($store_integration == null) {
                            return response()->json(
                                [
                                    "status" => "La tienda no posee una integreación habilitada.",
                                    "results" =>"",
                                ],
                                409
                            );
                        } 
                        $integration = StoreIntegrationToken::where('store_id',$store_id)
                            ->where('integration_name', 'didi')
                            ->where('type', 'delivery')
                            ->first();  
                        if ($integration === null) {
                            return response()->json(
                                [
                                    "status" => "Esta tienda no tiene tokende didi.",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        $this->initVarsDidiRequests();
                        $resultToken = $this->getDidiToken($store_id, $store_integration->external_store_id);
                        $resultConfirm = $this->confirmOrder(
                            $resultToken['token'],
                            $order_integration->external_order_id,
                            $store->name
                        );
                        if ($resultConfirm["status"] == 0) {
                            // Falló en confirmar la orden
                            return response()->json(
                                [
                                    "status" => "No se pudo enviar la aceptación del pedido",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        break;
                    default:     
                        break;
                }
                //Despues de que se acepta la orden se le procede a cambiar el status.
                $order->status=1;
                $order->save();
                // Como las integraciones no manejan clientes, obteniendo el cliente de comsumidor final
                $billing = Billing::firstOrCreate(
                    [
                        'document' => '9999999999999',
                        'name'     => 'CONSUMIDOR FINAL'
                    ]
                );
                // Obteniendo el número de la factura para esta orden
                $invoiceNumber = Helper::getNextBillingOfficialNumber($store_id, true);

                // Creando la factura
                $invoice = new Invoice();
                $invoice->order_id = $order->id;
                $invoice->billing_id = $billing->id;
                $invoice->status = "Pagado";
                $invoice->document = $billing->document;
                $invoice->name = $billing->name;
                $invoice->address = $billing->address;
                $invoice->phone = $billing->phone;
                $invoice->email = $billing->email;
                $invoice->subtotal = Helper::bankersRounding($order->base_value, 0);
                $invoice->tax = Helper::bankersRounding($order->total - $order->base_value, 0);
                $invoice->total = Helper::bankersRounding($order->total, 0);
                $invoice->food_service = 0;
                $invoice->discount_percentage = $order->discount_percentage;
                $invoice->discount_value = Helper::bankersRounding($order->discount_value, 0);
                $invoice->undiscounted_subtotal = Helper::bankersRounding($order->undiscounted_base_value, 0);
                $invoice->invoice_number = $invoiceNumber;
                //$invoice->created_at = $orderInfo["created_at"];
                //$invoice->updated_at = $orderInfo["created_at"];
                $invoice->save();
                // Agregando detalles de los valores que no cobran impuestos
                if ($order->no_tax_subtotal > 0) {
                    $invoiceTaxDetail = new InvoiceTaxDetail();
                    $invoiceTaxDetail->invoice_id = $invoice->id;
                    $invoiceTaxDetail->tax_name = 'Sin impuestos (0%)';
                    $invoiceTaxDetail->tax_percentage = 0;
                    $invoiceTaxDetail->subtotal = 0;
                    $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($order->no_tax_subtotal, 0);
                    $invoiceTaxDetail->print = 1;
                    //$invoiceTaxDetail->created_at = $orderInfo["created_at"];
                    //$invoiceTaxDetail->updated_at = $orderInfo["created_at"];
                    $invoiceTaxDetail->save();
                }

                // Agregando los detalles de los valores que cobran impuestos
                $newInvoiceTaxDetails = [];
                foreach ($order->taxDetails as $taxDetail) {
                    // Data del impuesto para el detalle de la orden
                    array_push(
                        $newInvoiceTaxDetails,
                        [
                            "invoice_id" => $invoice->id,
                            "tax_name" => $taxDetail->storeTax->name,
                            "tax_percentage" => $taxDetail->storeTax->percentage,
                            "tax_subtotal" => Helper::bankersRounding($taxDetail->tax_subtotal, 0),
                            "subtotal" => Helper::bankersRounding($taxDetail->subtotal, 0),
                            "print" => ($taxDetail->storeTax->type === 'invoice') ? 0 : 1,
                            //"created_at" => $orderInfo["created_at"],
                            //"updated_at" => $orderInfo["created_at"]
                        ]
                    );
                }
                // Creando nuevos impuestos del detalle de la orden
                InvoiceTaxDetail::insert($newInvoiceTaxDetails);
                // Creación de los items para la factura
                $orderCollection = collect($order);
                $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey(
                    $order->orderDetails->load('orderSpecifications.specification.specificationCategory')
                );
                $orderCollection->forget('orderDetails');
                $orderCollection->put('orderDetails', $groupedOrderDetails);
                $newInvoiceItems = [];
                foreach ($orderCollection['orderDetails'] as $orderDetail) {
                    $productName = $orderDetail['invoice_name'];
                    foreach ($orderDetail['order_specifications'] as $specification) {
                        if ($specification['specification']['specification_category']['type'] == 2) {
                            $productName = $productName . " " . $specification['name_specification'];
                            break;
                        }
                    }
                    // Data del item de la factura
                    array_push(
                        $newInvoiceItems,
                        [
                            "invoice_id" => $invoice->id,
                            "product_name" => $productName,
                            "quantity" => $orderDetail['quantity'],
                            "base_value" => Helper::bankersRounding($orderDetail['base_value'], 0),
                            "total" => Helper::bankersRounding($orderDetail['total'], 0),
                            "has_iva" => $orderDetail['tax_values']['has_iva'],
                            "compound_key" => $orderDetail['compound_key'],
                            "order_detail_id" => $orderDetail['id'],
                            //"created_at" => $orderInfo["created_at"],
                            //"updated_at" => $orderInfo["created_at"]
                        ]
                    );
                }

                // Creando los nuevos items de la factura
                InvoiceItem::insert($newInvoiceItems);
                $store->load("hubs");
                $hub = null;
                if ($store->hubs != null && $store->hubs->first() != null) {
                    $hub = $store->hubs->first();
                }
                // Consumo de stock de inventario a partir del contenido de la orden
                $this->reduceComponentsStock($order);
                $this->reduceComponentsStockBySpecification($order);
                event(new OrderUpdatedComanda($order));
                if ($hub != null) {
                    event(new HubIntegrationOrderCreated($hub, $invoice));
                }
                // Impresión de la orden
                if ($store->configs->uses_print_service) {
                    $employee = $this->authEmployee;
                    // Imprimir por microservicio
                     PrintServiceHelper::printComanda($order, $employee);
                     PrintServiceHelper::printInvoice($invoice, $employee);
                }elseif($order_integration->integration_name=='didi'){
                    $this->sendIntegrationOrder($order, 'Didi Food');
                }
                return response()->json(
                    [
                        "status" => "Orden aceptada correctamente",
                        "results" =>$order_integration,
                    ],
                    200
                );
            }
            
        );
        return $orderJSON;
        
    }
    public function rejectOrder(Request $request)
    {
        $order_id=  $request->id;
        $store_id= $request->store_id;
        $orderJSON = DB::transaction(
            function () use ($order_id, $store_id) {
                //Se procede a recuperar la orderintegration
                $order_integration= OrderIntegrationDetail::where('order_id',$order_id)
                    ->first();
                if($order_integration==null){
                    return response()->json(
                        [
                            "status" => "La orden no posee un registro de integración",
                            "results" =>$order_id,
                        ],
                        409
                    );
                }
                
                $order = Order::where('id',$order_id)->first();

                
                $store= Store::with('eatsIntegrationToken','configs')
                    ->where('id',$store_id)
                    ->first();
                //Se rechaza la orden dependiendo de la integración que posea.
                switch ($order_integration->integration_name) {
                    case AvailableMyposIntegration::NAME_EATS:
                            $integration=$store->eatsIntegrationToken;
                            if (is_null($integration)) {
                                return response()->json(
                                    [
                                        "status" => "La tienda no posee un token para uber eats.",
                                        "results" =>"",
                                    ],
                                    409
                                );
                            }
                            //Se acepta la orden Uber.
                            $baseUrl = config('app.eats_url_api');
                            $client = new FileGetContents(new Psr17Factory());
                            $browser = new Browser($client, new Psr17Factory());
                        
                            UberRequests::initVarsUberRequests(
                                "uber_orders_logs",
                                "#integration_logs_details",
                                $baseUrl,
                                $browser
                            );
                            // Enviando Respuesta DENY a Uber Eats de que se no se pudo crear la orden en myPOS
                            $msg = '{ "reason": { "explanation": "Orden deny for store" } }';
                            
                            $resultDetails = UberRequests::sendRejectionEvent(
                                $integration->token,
                                $store->name,
                                (string) $order_integration->external_order_id,
                                $msg
                            );
                            if ($resultDetails["status"] == 0) {
                                // Falló en confirmar la orden
                                return response()->json(
                                    [
                                        "status" => "No se pudo rechazar el pedido",
                                        "results" =>"",
                                    ],
                                    409
                                );
                            }
                        break;
                    case AvailableMyposIntegration::NAME_DIDI:
                        //Se procede a traer el external store_id     
                        $store_integration = StoreIntegrationId::where('store_id',$store_id)
                            ->where('integration_id', 7)
                            ->first();
                        if ($store_integration == null) {
                            return response()->json(
                                [
                                    "status" => "La tienda no posee una integreación habilitada.",
                                    "results" =>"",
                                ],
                                409
                            );
                        } 
                        $integration = StoreIntegrationToken::where('store_id',$store_id)
                            ->where('integration_name', 'didi')
                            ->where('type', 'delivery')
                            ->first();  
                        if ($integration === null) {
                            return response()->json(
                                [
                                    "status" => "Esta tienda no tiene tokende didi.",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        $this->initVarsDidiRequests();
                        $resultToken = $this->getDidiToken($store_id, $store_integration->external_store_id);
                        $resultConfirm = $this->rejectDidiOrder(
                            $resultToken['token'],
                            $order_integration->external_order_id,
                            $store->name
                        );
                        if ($resultConfirm["status"] == 0) {
                            // Falló en rechazar la orden
                            return response()->json(
                                [
                                    "status" => "No se pudo enviar el rechazo del pedido",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        break;
                    case AvailableMyposIntegration::NAME_RAPPI:
                        $storeToken = StoreIntegrationToken::where('store_id', $store_id)
                        ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
                        ->where('is_anton', true)
                        ->first();
                        if(!is_null($storeToken)){
                            if($storeToken->anton_password==null || $storeToken->anton_password==""){
                                $accessToken = MelyRequest::getAccessToken();
                                if($accessToken["success"]!=true){
                                    return response()->json(
                                        [
                                            'status' => false,
                                            'message' => "La tienda no tiene configurada la integración con anton"
                                        ],
                                        409
                                    );
                                }
                                $token =  $accessToken["data"]['data']['token_type']." ".$accessToken["data"]['data']['token'];
                                $storeToken->anton_password = $token;
                                $storeToken->save();
                                $storeToken->password = $token;
                            }
                        }else{
                            return response()->json(
                                [
                                    'status' => false,
                                    'message' => "La tienda no posee un token."
                                ],
                                409
                            );
                        }
                       
                        $customStatus=[
                            "delivery_id"=> "2",
                            "store_id"=> $storeToken->token_type
                        ];
                        $responseMely=MelyIntegration::rejectOrderMely($order_integration->external_order_id, $storeToken, 0, "La tienda rechazo la orden",$customStatus);
                        if (!$responseMely) {
                            return response()->json(
                                [
                                    "status" => "No se pudo rechazar la orden.",
                                    "results" =>"",
                                ],
                                409
                            );
                        }
                        break;
                    default:
                        break;
                
                }

                $order->status=0;
                $order->save();
                return response()->json(
                    [
                        "status" => "Orden Rechazada correctamente",
                        "results" => $order_id,
                    ],
                    200
                );
            } 
        );
        return $orderJSON;
        
    }
}
