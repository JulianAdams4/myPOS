<?php

namespace App\Http\Controllers\API\Integrations\MercadoPago;

use App\Store;
use Exception;
use App\Helper;
use App\TaxesTypes;
use GuzzleHttp\Client;
use App\Traits\AuthTrait;
use App\Events\MercadoPago;
use App\StoreIntegrationId;
use Illuminate\Http\Request;
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;
use Illuminate\Support\Facades\DB;
use App\OrderHasPaymentIntegration;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

class MercadoPagoController extends Controller
{
    use AuthTrait;

    public $authUser;
    public $authEmployee;
    public $authStore;
    
    public function __construct(){
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
    }

    public function makeRequest($method, $url, $params = null){
        /*Se hace este ajuste en $store pensando en el Job que no puede reconocer la sesión */
        $store = $this->authStore;
        $integrationToken = $this->getToken($store);

        $params = !empty($params) ? json_decode(stripslashes($params), true) : $params;

        try {
            $client = new Client();
            $response = $client->request($method, config('app.mercado_pago_api').$url."?access_token=".$integrationToken, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'application/json',
                    'Accept-Encoding' => 'gzip'
                ],
                'json' => $params,
                // 'http_errors' => true
            ]);

            $data = json_decode($response->getBody(), true);
        
        } catch (ClientException $e) {

            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("Error in makeRequest: {$exceptionMessage}");
            Log::channel('mercado_pago_logs')->error("in store {$store->id} {$store->name}");
            Log::channel('mercado_pago_logs')->error("From: {$method} {$url}");
            Log::channel('mercado_pago_logs')->error("Data: ".json_encode($params));
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            throw new Exception($e);

        }

        return $data;
    }

    public function getToken(Store $store){
        return $store->integrationTokens->where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)->first()->token;
    }

    public function getUserIdForStoreInMp(Store $store){
        return $store->integrationTokens->where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)->first()->password;
    }

    public function createOrder(Request $request){
        $store = $this->authStore;
        $orderDetails = $request->orderDetails;
        $externalId = $request->externalId;
        $toMpCashierId = $request->mpCashierId;
        $totalTip = $request->totalTip;
        $total = $request->total;
        $typeOrder = $request->type;

        $totalIva = 0;
        $totalOrder = 0;

        $itemsToPay = [];
        
        if($typeOrder == 'normal'){
            foreach ($orderDetails as $detail) {

                $totalOrder += $detail['total'];

                array_push($itemsToPay, [
                    "title" => $detail['name_product'], 
                    "currency_id" => $store->currency, 
                    "unit_price" => $detail['total'] / 100,
                    "quantity" => $detail['quantity']
                ]);
                
                $noTax = $detail['tax_values']['no_tax'];
                foreach($detail['tax_values']['tax_details'] as $taxDetail){
                    if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "iva", $taxDetail['tax']['tax_type'], "add")){
                        $totalIva += $noTax * ($taxDetail['tax']['percentage'] / 100);
                    }
                }

            }
        }

        if($typeOrder == 'split'){
            array_push($itemsToPay, [
                "title" => "Pago dividido", 
                "currency_id" => $store->currency, 
                "unit_price" => Helper::bankersRounding($total / 100, 0),
                "quantity" => 1
            ]);

            $totalOrderTotals = 0;

            foreach ($orderDetails as $detail) {
                $totalOrderTotals += $detail['total'];
            }
            
            $finalTotalBaseValue = 0;
            
            $totalOrderValue = $totalOrderTotals;
            
            $totalSplitInTotalOrder = $total / $totalOrderValue;

            foreach ($orderDetails as $detail) {
                $noTax = $detail['tax_values']['no_tax'];

                foreach($detail['tax_values']['tax_details'] as $taxDetail){
                    if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "iva", $taxDetail['tax']['tax_type'], "add")){
                        
                        $baseValueForSplit = $noTax * $totalSplitInTotalOrder;
                        $finalTotalBaseValue += $baseValueForSplit; 
                        $totalIva += $baseValueForSplit * ($taxDetail['tax']['percentage'] / 100);

                    }
                }
            }
        }

        if($totalTip > 0){
            array_push($itemsToPay, [
                "title" => "Propina", 
                "currency_id" => $store->currency, 
                "unit_price" => $totalTip / 100,
                "quantity" => 1
            ]);
        }

        $newOrder = [
            "external_reference" => $externalId,
            "notification_url"=> config('app.url_api')."mp-webhook",
	    "sponsor_id"=> null,
            "items" => $itemsToPay,
            "taxes" => [
                [
                    "type" => "IVA",
                    "value" => Helper::bankersRounding($totalIva / 100, 0)
                ]
            ]
        ];
       Log::channel('mercado_pago_logs')->info('url '.config('app.url_api').'mp-webhook');
        
        $userId = $this->getUserIdForStoreInMp($store);

        //clean cashier before to send the new order
        try {
            $this->cleanCashier($userId, $toMpCashierId);
        } catch (\Throwable $th) {
            /*A catch is necesary if the DELETE request response http 400 (not exist orders for delete) because
            the program needs conitnue*/
        }

        $createOrder = $this->makeRequest('POST', "/mpmobile/instore/qr/{$userId}/{$toMpCashierId}", json_encode($newOrder));

        $this->savePaymentRelation($externalId, $store);

        return response()->json([
            "status" => "order_created",
            "external_reference" => $externalId
        ], 200);
    }

    public function cleanCashier($userId, $mpCashierId){
        $clean = $this->makeRequest('DELETE', "/mpmobile/instore/qr/{$userId}/{$mpCashierId}");
    }

    public function deleteOrder(Request $request){
        $store = $this->authStore;
        $mpCashierId = $request->mpCashierId;

        $userId = $this->getUserIdForStoreInMp($store);
        $createOrder = $this->makeRequest('DELETE', "/mpmobile/instore/qr/{$userId}/{$mpCashierId}");

        return response()->json([
            "status" => "order_deleted",
            "cashier_external_reference" => $mpCashierId
        ], 200);
    }

    public function checkOrderStatus(Request $request){
        $externalId = $request->externalId;

        $orders = $this->makeRequest('GET', 
        "/merchant_orders/search", 
        json_encode(['external_reference' => $externalId]));

        $collectOrders = collect($orders['elements']);

        $isOpened = $collectOrders->where('external_reference', $externalId)
            ->first();

        if(!$isOpened){
            return response()->json(
                [
                    "status" => "not_opened"
                ], 
                200
            );
        }

        $isClosed = $collectOrders->where('external_reference', $externalId)
            ->where('status', 'closed')
            ->first();
        
        if(!$isClosed){
            return response()->json(
                [
                    "status" => "not_closed"
                ], 
                200
            );
        }

        return response()->json(
            [
                "status" => "closed"
            ], 
            200
        );
    }

    public function savePaymentRelation($externalId, $store){
        $payment = new OrderHasPaymentIntegration;
        $payment->store_id = $store->id;
        $payment->integration_name = AvailableMyposIntegration::NAME_MERCADO_PAGO;
        $payment->status = 'created'; //created, opened, closed
        $payment->reference_id = $externalId;
        $payment->save();
    }

    public function setIntegration(Request $request){
        $store = $this->authStore;
        $accessToken = $request->mpAccessToken;

        try {

            $client = new Client();
            $getCashiers = $client->request('GET', config('app.mercado_pago_api')."/pos?access_token={$accessToken}", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'application/json',
                    'Accept-Encoding' => 'gzip'
                ]
            ]);

            /* Guarda la información de respuesta */
           $cashiers = (array) json_decode($getCashiers->getBody());

           

        } catch (ClientException $e) {
            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("Error in makeRequest: {$exceptionMessage}");
            Log::channel('mercado_pago_logs')->error("in store {$store->id} {$store->name}");
            Log::channel('mercado_pago_logs')->error("From: /pos");
            Log::channel('mercado_pago_logs')->error("Data: null");
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");

            return response()->json(
                [
                    "status" => "Error",
                    "results" => "No es posible conectar con este Access Token. Si el error persiste por favor contacte con soporte.",
                ],
                409
            );

        }

        // return response()->json($cashiers->results);

        $collectCashiersResults = collect($cashiers['results']);
        
        // Si obtenemos una respuesta positiva de rappi, pero no nos envían los datos que necesitamos para bd
        if($collectCashiersResults->count() === 0){
            return response()->json(
                [
                    "status" => "Error",
                    "results" => "No es posible completar la integración porque no existen cajas en Mercado Pago. Cree cajas e intente de nuevo.",
                ],
                409
            );
        }

        $userId = $collectCashiersResults->first()->user_id;

        try {
            DB::beginTransaction();

            //REGISTRA LA NUEVA INTEGRACIÓN
            $newIntegration = StoreIntegrationToken::firstOrNew([
                'type' => 'wallet',
                'store_id' => $store->id,
                'integration_name' => AvailableMyposIntegration::NAME_MERCADO_PAGO
            ]);

            $newIntegration->token = $accessToken;
            $newIntegration->password = $userId;
            $newIntegration->type = 'wallet';
            $newIntegration->save();

            DB::commit();
        } catch (Exception $e) {
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("ERROR FROM setIntegration");
            Log::channel('mercado_pago_logs')->error("NO SE PUDO REGISTRAR LA INTEGRACIÓN EN BD");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getMessage()}");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getFile()}");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getLine()}");
            Log::channel('mercado_pago_logs')->error("in store {$store->id}");
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");

            DB::rollBack();

            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Ocurrió un error al tratar de guardar el token en BD. Contacte con soporte.'
                ],
                409
            );
        }

        try {
            DB::beginTransaction();

            $getIntegration = AvailableMyposIntegration::firstOrCreate(
                ['code_name' => AvailableMyposIntegration::NAME_MERCADO_PAGO],
                [
                    'type' => 'wallet',
                    'name' => 'Mercado Pago'
                ]
            );
            foreach ($collectCashiersResults as $cashier) {
		if(!isset($cashier->external_id)){
			continue;
		}
                $setCashier = StoreIntegrationId::firstOrNew([
                    'integration_id' => $getIntegration->id,
                    'store_id' => $store->id,
                    'integration_name' => AvailableMyposIntegration::NAME_MERCADO_PAGO,
                    'type' => 'cashier',
                    'external_store_id' => $cashier->external_id
                ]);
                $setCashier->description = $cashier->name;
                $setCashier->save();
            }

            DB::commit();
        } catch (Exception $e) {
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("ERROR FROM setIntegration");
            Log::channel('mercado_pago_logs')->error("FAILED CREATING CASHIERS");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getMessage()}");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getFile()}");
            Log::channel('mercado_pago_logs')->error("ERROR : {$e->getLine()}");
            Log::channel('mercado_pago_logs')->error("in store {$store->id}");
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");

            DB::rollBack();

            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Ocurrió un error al tratar de asociar las cajas. Contacte con soporte.'
                ],
                409
            );
        }

        return response()->json(
            [
                "status" => "Success",
                "results" => "La integración se registró correctamente.",
            ],
            200
        );

    }

    public function getAllCashiers(Request $request){
        $store = $this->authStore;

        $cashiers = StoreIntegrationId::where([
            ['store_id', $store->id],
            ['integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO],
            ['type', 'cashier']
        ])->get();

        return response()->json($cashiers, 200);
    }

    public function refundCompletePayment(Request $request){
        $store = $this->authStore;
        $paymentId = $request->paymentId;

        try {
            $refund = $this->makeRequest('POST', "/v1/payments/{$paymentId}/refunds");
        } catch (\Throwable $e) {

            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("Error in refundCompletePayment: Payment {$paymentId} not found ");
            Log::channel('mercado_pago_logs')->error("in store {$store->id} {$store->name}");
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");

            return response()->json([
                "status" => "not_refunded",
                "message" => "No se encuentra el pago solicitado. Intente de nuevo o contacte con soporte."
            ], 409);

        }

        return response()->json([
            "status" => "refunded",
            "message" => "Pago devuelto correctamente."
        ], 200);
    }
}
