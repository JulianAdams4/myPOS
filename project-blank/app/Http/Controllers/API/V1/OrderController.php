<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Order;
use App\OrderDetail;
use App\OrderProductSpecification;
use App\OrderStatus;
use App\OrderCondition;
use App\Instruction;
use App\Address;
use App\Employee;
use App\Company;
use App\Store;
use App\Traits\PushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Jobs\Gacela\PostGacelaOrder;
use Carbon\Carbon;
use App\Helper;
use Log;
use Auth;
use App\Events\OrderCustomerCreated;
use Pusher\Pusher;

class OrderController extends Controller
{

    use PushNotification;
    public $pusher;
    public function __construct()
    {
        // $options = array(
        //     'cluster' => 'us2',
        //     'useTLS' => true
        // );
        // $this->pusher = 
        //  new Pusher(
        //     'b16157b01455f1fa54c0',
        //     '4e4533d69065636ab545',
        //     '687192',
        //     $options
        // );
        //$this->middleware('customer',['only' => ['store']]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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
        $employee = Employee::where('email', $request->employee_email)
            ->where('id', $request->employee_id)->first();
        if (!$employee) {
            return response()->json(
                [
                    'status' => 'No se encontró este empleado.',
                    'results' => null,
                ], 404
            );
        }

        $store = Store::find($request->store_id);
        if (!$store) {
            return response()->json(
                [
                    'status' => 'No se encontró esta tienda.',
                    'results' => null,
                ], 404
            );
        }

        if ($employee->store_id != $store->id) {
            return response()->json(
                [
                    'status' => 'El empleado no pertenece a esta tienda.',
                    'results' => null,
                ], 404
            );
        }

        $company = Company::find($store->company_id);
        if (!$company) {
            return response()->json(
                [
                    'status' => 'No se encontró esta compañía.',
                    'results' => null,
                ], 404
            );
        }

        $order = Order::create($request->all());

        if ($order) {
            foreach ($request->orderDetails as $orderDetail) {
                $orderDetailCreated = OrderDetail::create(
                    [
                        'product_detail_id' => $orderDetail['product_detail_id'],
                        'quantity' => $orderDetail['quantity'],
                        'name_product' => $orderDetail['name_product'],
                        'value' => $orderDetail['value'],
                        'order_id' => $order->id,
                    ]
                );
            }
            return response()->json(
                [
                    'status' => 'Exito',
                    'results' => $order
                ], 200
            );
        } else {
            return response()->json(
                [
                'status' => 'Orden no creada',
                'results' => ''
                ], 400
            );
        }
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

    /*getQuantityOrders
    Retorna la cantidad de ordenes pasadas y actuales del customer
    con el fin de mostrar las secciones adecuadas
    Si son 0 ordenes actuales no muestra el tab actuales
    Si son 0 ordenes pasadas no muestra el tab pasadas
    Si tienen ordenes actuales y pasadas se muestran las 2 tabs
    */
    public function getQuantityOrders($employee)
    {
        $orders = Order::where('employee_id', $employee)
            ->where('status', 1)
            ->orderBy('created_at', 'DESC')
            ->with('orderDetails')
            ->get();
        return response()->json(
            [
                'status' => 'Exito',
                'orders' => $orders,
            ], 200
        );
    }

    // public function getQuantityOrdersOLD($customer)
    // {
    //     Log::info($customer);
    //     $quantityPast = Order::where('customer_id', $customer)->where('status', 1)
    //     ->where(function ($query) {
    //         $query->where('current_status', 'Entregada')
    //               /*->orWhere('current_status', 'Cancelada')*/;
    //     })->orderBy('created_at', 'DESC')->count();
    //     $quantityCurrent = Order::where('customer_id', $customer)->where('status', 1)
    //         ->where('current_status', '!=','Entregada')/*->where('current_status', '!=', 'Cancelada')*/
    //         ->orderBy('created_at', 'DESC')->count();
    //     return response()->json([
    //       'status' => 'Exito',
    //       'quantityPast' => $quantityPast,
    //       'quantityCurrent' => $quantityCurrent
    //     ],200);
    // }

    /*
    getPastOrders
    Retorna las ordenes pasadas con su repectivo estado
    */
    public function getPastOrders($customer)
    {
        $orders = Order::where('customer_id', $customer)->where('status', 1)
        ->where(function ($query) {
            $query->where('current_status', 'Entregada')
                  /*->orWhere('current_status', 'Cancelada')*/;
        })->orderBy('created_at', 'DESC')
        ->with(['orderConditions' => function($conds) {
                $conds->where('status', 1)->orderBy('id', 'desc');
        }, 'address'])->get();
        return response()->json([
          'status' => 'Exito',
          'results' => $orders
        ],200);
    }

     /*
     getCurrentOrders
     Retorna las ordenes actuales con su repectivo estado actual
     */
    public function getCurrentOrders($customer)
    {
        $orders = Order::where('customer_id', $customer)->where('status', 1)
        ->where('current_status', '!=','Entregada')/*->where('current_status', '!=', 'Cancelada')*/
        ->orderBy('created_at', 'DESC')
        ->with(['orderConditions' => function($conds) {
                $conds->where('status', 1)->orderBy('id', 'desc');
        }, 'address'])->get();
        return response()->json([
          'status' => 'Exito',
          'results' => $orders
        ],200);
    }

    /*
     cancelOrder
     Actualiza el campo current_status a "Cancelada" de al orden respectiva 
     */
    public function cancelOrder(Request $request)
    {
        $orderFound = Order::find($request->idOrder);
        if($orderFound){
            $userUpdated = $orderFound->update($request->all());
            if($userUpdated){
                return response()->json([
                    'status' => 'Exito',
                    'results' => $orderFound
                ],201);
            }
            else{
                return response()->json([
                    'status' => 'Órden no actualizada',
                    'results' => $orderFound
                ],400);
            }
        }
        else{
            return response()->json([
                'status' => 'No se ha encontrado la órden',
                'results' => ''
            ],404);
        }
    }

    /*
    sendNotificationCreate
    Envia el evento notify-order-created al canal correspondiente 
    cuando se crea la orden por ejm 'store-1', envia notificaciones solo al canal de los admins-stores
    que pertenezan al store con id 1
    NOTA:Cuando el front recibe el evento recarga las ordenes del dashboard y se muestra una notificacion
    */
    public function sendNotificationCreate($storeId)
    {
        $message= "Orden nueva";
        $this->pusher->trigger('store-'.$storeId, 'notify-order-created', $message);  
    }

    /*
    sendNotificationUpdateStatus
    Envia el evento notify-order-updated al canal correspondiente 
    cuando se crea se actualiza la orden
    NOTA:Cuando el front recibe el evento recarga las ordenes del dashboard 
    */
    public function sendNotificationUpdateStatus($storeId)
    {
        $message= "Orden actualizada";
        $this->pusher->trigger('store-'.$storeId, 'notify-order-updated', $message);  
    }

    /*
    getOrder
    Retorna los datos de la orden(productos, categorias, especificaciones, estados)
    */
    public function getOrder($order)
    {
        Log::info("getOrder");
        $orderFound = Order::where('id',$order)->where('status',1)
            ->with([
              'orderDetails' => function($details) {
                $details->where('status', 1)
                ->with([
                    'orderSpecifications' => function($specs) {
                        $specs->where('status', 1);
                    }
                ]);
              },
              'store.address',
              'orderConditions' => function($conds) {
                $conds->where('status', 1)->orderBy('id', 'desc')->first();
              }
            ])->first();
        if($orderFound){
            return response()->json([
                'status' => 'Exito',
                'results' => $orderFound,
            ],200);
        }
        else{
            return response()->json([
                'status' => 'No se ha encontrado la órden',
                'results' => ''
            ],404);
        }
    }

    /*
    updateOrderStatus
    Actualiza la orden segun el token enviado en $request
    */
    public function updateOrderStatus(Request $request)
    {
        $token = $request->json("order_token", null);
        $statuses = $request->json("statuses", null);
        $orderFound = Order::with('customer')->where('order_token', $token)->first();
        if ($orderFound) {
            Log::info("Orden encontrada mi llave: " . $orderFound->id);
            $orderStatusData = $statuses[0];
            $orderUpdated = $orderFound->update(
                ['current_status' => $orderStatusData['name'],]
            );
            $existDuration = isset($orderStatusData['duration']);
            if (!$existDuration) {
                $orderStatusData['duration']=0;
            }
            //se agrega un registro a la tbl orden_statuses para tener un control de los cmabios de estado
            $orderStatus = OrderStatus::create([
                    'order_id' => $orderFound->id,
                    'name' => $orderStatusData['name'],
                    'latitude' => $orderStatusData['lat'],
                    'longitude' => $orderStatusData['long'],
                    'duration' => $orderStatusData['duration'],
                ]);
            /*si el estado de la orden es Con Pedido se agrega a la tbl order_conditions,
            NOTA: en order_conditions se almacenan los estados que se van a mostrar en la app movil*/
            if ($orderStatusData['name'] == 'Con Pedido'){
                $orderCondition = OrderCondition::create([
                    'order_id' => $orderFound->id,
                    'name' => 'Despachada',
                    'formatted_created_at' => Helper::formattedDate(Carbon::now()->toDateTimeString()),
                ]);
                $this->deliveringOrder($orderFound);
            }
            //si el estado de la orden es Entregada se agrega a la tbl order_conditions
            else if ($orderStatusData['name'] == 'Entregada'){
                $orderCondition = OrderCondition::create([
                    'order_id' => $orderFound->id,
                    'name' => 'Entregada',
                    'formatted_created_at' => Helper::formattedDate(Carbon::now()->toDateTimeString()),
                ]);
            }
            //se crea el evento para actualizar el estado de laas ordenes en el dashboard
            $this->sendNotificationUpdateStatus($orderFound->store_id);
            return response()->json([
                'status' => 'Exito',
                'results' => $orderUpdated
            ],201);
        }
        else{
            Log::info("Orden no encontrada pues mi llave");
            return response()->json([
                'status' => 'No se ha encontrado la órden',
                'results' => ''
            ],404);
        }
    }

}
