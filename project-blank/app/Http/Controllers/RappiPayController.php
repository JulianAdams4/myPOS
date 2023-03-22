<?php

namespace App\Http\Controllers;
use App\Employee;
use Illuminate\Http\Request;
use App\StoreIntegrationToken;
use App\PaymentIntegrationDetail;
use App\AvailableMyposIntegration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;


class RappiPayController extends Controller{
    public function __construct(){
        /* Confirma que exista un token autorizado*/
        $this->employee = Auth::guard('employee-api')->user();
        if(!$this->employee){
            return response()->json(
                [
                    'status' => 'Error de autenticación',
                    'results' => null
                ], 401
            );
        }

        /*Guarda información de la tienda*/
        $this->store = $this->employee->store;
    }

    public function getToken($rappiEndpoint){
        /*  Recupera información sobre la integración de la tienda con RappiPay, para 
        *   determinar si se debe solicitar un nuevo token
        */
        $integrationToken = StoreIntegrationToken::where('store_id', $this->store->id)
        ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI_PAY)
        ->first();

        /* Si token en bd no está vencido, ni vacío entonces devuelve $integrationToken->token*/
        if(!empty($integrationToken->expires_in) && time() < $integrationToken->expires_in){
            return $integrationToken->token;
        }

        /* Si token en bd se encuentra vacío o vencido entonces pide un nuevo token*/
        try {

            $client = new Client();

            $response = $client->request('POST', $rappiEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'country' => $this->store->country_code,
                ],
                'json' => [
                    'grantType' => 'refreshToken',
                    'refreshToken' => $integrationToken->refresh_token,
                ]
            ]);
            
            /* Guarda la información de respuesta */
            $data = json_decode($response->getBody());

        } catch (Exception $e) {
            return $e;
        }

        /* Guarda la info del nuevo token en BD*/
        try {

            DB::beginTransaction();

            $integrationToken = StoreIntegrationToken::where('store_id', $this->store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI_PAY)
            ->first();
            
            $integrationToken->token = $data->accessToken;
            $integrationToken->token_type = $data->tokenType;
            $integrationToken->expires_in = time() + ($data->expiresIn / 1000) /* Se divide en mil para pasar de milisegundos a segundos*/;
            $integrationToken->save();

            DB::commit();

        } catch (Exception $e){
            DB::rollBack();
        }

        return $integrationToken->token;

    }

    public function postPurchase(Request $request){
        $employee = $this->employee;
        $store = $this->store;

        /* Comprueba si el país de la tienda es aceptado por RappiPay*/
        if(!in_array($store->country_code, AvailableMyposIntegration::AVAILABLE_RAPPI_PAY_COUNTRIES)){
            return response()->json(
                [
                    'status' => 'El país donde se encuentra esta tienda no puede procesar pagos con RappiPay',
                    'results' => null
                ], 401
            );
        } 

        /* Determina el entorno de ejecución para asignar endpoints*/
        if (config('app.env') == 'production') {

            /* Establece el endpoint de producción según el país de la tienda*/
            switch ($store->country_code) {
                case 'CO':
                    $rappiEndpoint = config('app.rappi_pay_prod_api_co');
                break;
                
                case 'MX':
                    $rappiEndpoint = config('app.rappi_pay_prod_api_mx');
                break;
            }

        } else {

            /* Establece el endpoint de desarrollo según el país de la tienda*/
            switch ($store->country_code) {
                case 'CO':
                    $rappiEndpoint = config('app.rappi_pay_dev_api_co');
                break;
                
                case 'MX':
                    $rappiEndpoint = config('app.rappi_pay_dev_api_mx');
                break;
            }

        }

        /* Recupera el token para conexión con RappiPay*/
        $integrationToken = $this->getToken($rappiEndpoint."/tokens");
        
        /* Inicia cliente HTTP para hacer purchase con rappiPay*/
        try {

            $client = new Client();

            $response = $client->request('POST', $rappiEndpoint."/qr/purchase", [
                'headers' => [
                    'Authorization' => "Bearer ".$integrationToken,
                    'country'       => $store->country_code,
                    'Content-Type'  => 'application/json'
                ],
                'json' => [
                    'CIN'           => $request->cin,
                    'amount'        => $request->amount,
                    'currencyCode'  => $store->currency,
                    'referenceId'   => $request->referenceId,
                    'storeId'       => $request->storeId,
                    'message'       => "Pago de Orden: ".$request->referenceId." | Detalle: ".$request->message
                ]
            ]);
            
            /* si todo sale bien en el request a rappiPay, guarda resultado en $data*/
            $data = json_decode($response->getBody());

        } catch (ClientException $e) {

            /* Si algo sale mal, detiene todo el flujo. Devuelve el body y el code HTTP de rappiPay*/
            $response   = $e->getResponse();
            $status     = $response->getStatusCode();
            $error_body = json_decode($response->getBody());
            return response()->json($error_body, 401);
        }

        /* Crea el registro en BD*/
        try {

            DB::beginTransaction();

            $paymentObj = new PaymentIntegrationDetail();

            $paymentObj->store_id           = $store->id;
            $paymentObj->integration_name   = AvailableMyposIntegration::NAME_RAPPI_PAY;
            $paymentObj->cin                = $request->cin;
            $paymentObj->amount             = $request->amount;
            $paymentObj->currency           = $store->currency;
            $paymentObj->message            = "Pago de Orden: ".$request->referenceId." | Detalle: ".$request->message;
            $paymentObj->reference_id       = $request->referenceId;
            $paymentObj->payment_id         = $data->paymentId;
            $paymentObj->save();

            DB::commit();

        } catch (Exception $e){
            DB::rollBack();
        }

        /* Si llega hasta aquí es porque todo ha salido bien, devuelve exactamente lo que respondió rappiPay*/
        return response()->json($data, 200);

    }

}