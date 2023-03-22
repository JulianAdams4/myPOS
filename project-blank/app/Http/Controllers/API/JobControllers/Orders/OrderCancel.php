<?php

namespace App\Http\Controllers\Api\JobControllers\Orders;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\OrderIntegrationDetail;
use App\Store;
use App\AvailableMyposIntegration;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Browser;
use App\Traits\Uber\UberRequests;
use App\StoreIntegrationToken;
use App\StoreIntegrationId;
use App\Traits\Mely\MelyRequest;
use App\Traits\Mely\MelyIntegration;
use App\Traits\DidiFood\DidiRequests;
use Illuminate\Support\Facades\Log;

class OrderCancel extends Controller
{
    use DidiRequests;
    public function cancelOrder(){
        //Se procede a traer todas las ordenes que tengan status 3
        $orders = Order::where('status',3)->get();
        $poseeErrores=false;
        //Se procede a recorrer las ordenes con status 3 para compararlas con la hora actual y comprobar que no hayan pasado 5 minutos.
        foreach ($orders as $key => $order) {
            $now = Carbon::now();
            $emitted = Carbon::parse($order->updated_at);
            $diff = $now->diffInSeconds($emitted);
            // La orden puede estar con stauts 3 sólo dura 5 minutos(300 segundos)

            if ($diff >= 120) {
                Log::info('Se encontro ordenes que no han sido aceptadas ni rechazadas en un tiempo mayor o igual a 120 segundos');
                //Se procede a cancelar la orden dependiendo de la integración que posea.
                $order_id=  $order->id;
                $store_id= $order->store_id;
                Log::info('Order Id '.$order_id);
                Log::info('Store Id '.$store_id);
                $orderJSON = DB::transaction(
                    function () use ($order_id, $store_id,&$poseeErrores) {
                        //Se procede a recuperar la orderintegration
                        $order_integration= OrderIntegrationDetail::where('order_id',$order_id)
                            ->first();
                        if($order_integration==null){
                            $poseeErrores=true;
                            Log::info('Error: La orden no posee un registro de integración '.$order_id);
                            return;
                        }

                        $order = Order::where('id',$order_id)->first();
                        
                        $order->status=0;
                        $order->save();
                        $store= Store::with('eatsIntegrationToken','configs')
                            ->where('id',$store_id)
                            ->first();
                        Log::info('Iniciando proceso de rechazo de orden para la integración '.$order_integration->integration_name);
                        //Se rechaza la orden dependiendo de la integración que posea.
                        switch ($order_integration->integration_name) {
                            case AvailableMyposIntegration::NAME_EATS:
                                    $integration=$store->eatsIntegrationToken;
                                    if (is_null($integration)) {
                                        $poseeErrores=true;
                                        Log::info('Error: La tienda no posee un token para uber eats. '.$store_id);
                                        return;
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
                                    $msg = '{ "reason": "OTHER" ,"details":"order cancel from restaurant"}';
                                    $resultDetails = UberRequests::sendCancelationEvent(
                                        $integration->token,
                                        $store->name,
                                        (string) $order_integration->external_order_id,
                                        $msg
                                    );
                                    if ($resultDetails["status"] == 0) {
                                        $poseeErrores=true;
                                        Log::info(json_encode($resultDetails));
                                        Log::info('Error: No se pudo rechazar el pedido integración uber');
                                        return;
                                    }
                                break;
                            case AvailableMyposIntegration::NAME_DIDI:
                                //Se procede a traer el external store_id
                                $store_integration = StoreIntegrationId::where('store_id',$store_id)
                                    ->where('integration_id', 7)
                                    ->first();
                                if ($store_integration == null) {
                                    $poseeErrores=true;
                                    Log::info('Error: La tienda no posee una integreación habilitada.');
                                    return;
                                }
                                $integration = StoreIntegrationToken::where('store_id',$store_id)
                                    ->where('integration_name', 'didi')
                                    ->where('type', 'delivery')
                                    ->first();
                                if ($integration === null) {
                                    $poseeErrores=true;
                                    Log::info('Error: Esta tienda no tiene tokende didi.');
                                    return;
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
                                    $poseeErrores=true;
                                    Log::info('Error: No se pudo enviar el rechazo del pedido integración didi.');
                                    return;
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
                                            $poseeErrores=true;
                                            Log::info('Error: La tienda no tiene configurada la integración con anton.');
                                            return;
                                        }
                                        $token =  $accessToken["data"]['data']['token_type']." ".$accessToken["data"]['data']['token'];
                                        $storeToken->anton_password = $token;
                                        $storeToken->save();
                                        $storeToken->password = $token;
                                    }
                                }else{
                                    $poseeErrores=true;
                                    Log::info('Error: La tienda no posee un token mely.');
                                    return;
                                }

                                $customStatus=[
                                    "delivery_id"=> "2",
                                    "store_id"=> $storeToken->token_type
                                ];
                                $responseMely=MelyIntegration::rejectOrderMely($order_integration->external_order_id, $storeToken, 0, "La tienda rechazo la orden",$customStatus);
                                if (!$responseMely) {
                                    $poseeErrores=true;
                                    Log::info('Error: No se pudo rechazar la orden mely.');
                                    return;
                                }
                                break;
                            default:
                                break;
                        }

                        Log::info('Orden rechazada correctamente.');
                    }
                );
            }
        }
        if(!$poseeErrores){
            response()->json(['sucess' => true], 200);
        }else{
            response()->json(['sucess' => false], 409);
        }
    }
}
