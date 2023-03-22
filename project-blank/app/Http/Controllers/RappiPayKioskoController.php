<?php

namespace App\Http\Controllers;

use App\Store;
use Exception;
use App\Employee;
use App\AdminStore;
use GuzzleHttp\Client;
use App\Traits\AuthTrait;
use GuzzleHttp\Middleware;
use App\StoreIntegrationId;
use App\Traits\GlobalOrder;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\StoreIntegrationToken;
use App\OrderIntegrationDetail;
use GuzzleHttp\MessageFormatter;
use App\AvailableMyposIntegration;
use Illuminate\Support\Facades\DB;
use App\EmployeeIntegrationDetails;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Exception\ClientException;
use App\Http\Controllers\API\V2\OrderController;

class RappiPayKioskoController extends Controller
{
    use AuthTrait, GlobalOrder;

    public $store;
    public $storeFranchiseId;
    public $url;

    public $authUser;
    public $authEmployee;
    public $authStore;

    public function __construct(Request $request)
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();

        $this->url = App::environment('local') ? config('app.url_api') : config('app.prod_api');

        //Seguridad aplicada según el endpoint solicitado
        switch ($request->getPathInfo()) {
            case "/api/rappi_pay_kiosko/webhook":
                # Sin validación
                break;

            case "/api/rappi_pay_kiosko/get_qr":

                if (!$this->authEmployee) {
                    return response()->json([
                        'status' => 'Usuario no autorizado',
                    ], 401);
                }

                break;

            case "/api/rappi_pay_kiosko/set_cashier":

                if (!$this->authEmployee) {
                    return response()->json([
                        'status' => 'Usuario no autorizado',
                    ], 401);
                }

                break;

            case "/api/rappi_pay_kiosko/cancel_order":

                if (!$this->authEmployee) {
                    return response()->json([
                        'status' => 'Usuario no autorizado',
                    ], 401);
                }

                break;

            case "/api/rappi_pay_kiosko/check_order_status":

                if (!$this->authEmployee) {
                    return response()->json([
                        'status' => 'Usuario no autorizado',
                    ], 401);
                }

                break;

            default:
                if (!$this->authStore) {
                    return response()->json([
                        'status' => 'Usuario no autorizado',
                    ], 401);
                }
                break;
        }
    }

    public function disableIntegration(){
        $store = $this->authStore;

        $integrationDetailsLogin = StoreIntegrationToken::withTrashed()
            ->where('store_id', $store->id)
            ->where('integration_name', 'rappi_pay_kiosko')
            ->where('type', 'login')
            ->first();

        $integrationDetailsKiosko = StoreIntegrationToken::withTrashed()
            ->where('store_id', $store->id)
            ->where('integration_name', 'rappi_pay_kiosko')
            ->where('type', 'kiosko')
            ->first();

        // Se reactiva o elimina el detalle de la integración de tipo login
        if($integrationDetailsLogin->trashed()){
            $integrationDetailsLogin->restore();
        }else {
            $integrationDetailsLogin->delete();
        }

        // Se reactiva o elimina el detalle de la integración de tipo kiosko
        if($integrationDetailsKiosko->trashed()){
            $integrationDetailsKiosko->restore();
        }else{
            $integrationDetailsKiosko->delete();
        }

        return response()->json(
            [
                "status"    => "Success",
                "results"   => "Acción completada correctamente",
            ],
            200
        );

    }

    public function getDetailsRappiPayKioskoIntegration(){
        $store = $this->authStore;

        $integrationDetailsLogin = $store->integrationTokens->where('integration_name', 'rappi_pay_kiosko')->where('type', 'login')->first();
        // return $integrationDetailsLogin;
        
        if(!$integrationDetailsLogin){
            return response()->json(
                [
                    "status"    => "Success",
                    "results"   => "Información no disponible.",
                ],
                200
            );
        }

        return response()->json(
            [
                "status"    => "Success",
                "results"   => [
                    "rappiPKFranchiseId" => $integrationDetailsLogin->refresh_token,
                    "rappiPKUserName" => $integrationDetailsLogin->token,
                    "rappiPKPass" => $integrationDetailsLogin->password,
                    "rappiPKClientId" => $integrationDetailsLogin->scope
                ],
            ],
            200
        );
    }

    public function setRappiPayKioskoIntegration(Request $request){
        $store = $this->authStore;

        try {

            $loginUrlByEnvironment = App::environment('local') ? config('app.rappi_pay_kiosko_login_dev') : config('app.rappi_pay_kiosko_login_prod');

            $client = new Client();
            $response = $client->request('POST', $loginUrlByEnvironment, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'grant_type' => "password",
                    'username' => $request->rappiPKUserName,
                    'password' => $request->rappiPKPass,
                    'client_id' => $request->rappiPKClientId
                ]
            ]);

            /* Guarda la información de respuesta */
            $responseFromLogin = json_decode($response->getBody());

        } catch (ClientException $e) {
            /*Lines to debug the response in logs Files if fails*/
            $response   = $e->getResponse();
            $error_body = json_decode($response->getBody());
            Log::error("--------------------------------------------------------------");
            Log::error("ERROR FROM setRappiPayKioskoIntegration");
            Log::error("REQUEST TO: POST / {$loginUrlByEnvironment}");
            Log::error("RESPONSE: {$error_body->error}");
            Log::error("in store {$store->id}");
            Log::error("--------------------------------------------------------------");

            return response()->json(
                [
                    "status" => "Error",
                    "results" => "No es posible obtener el token con estas credenciales. Si el error persiste, contacte con soporte.",
                ],
                409
            );

        }

        // Si obtenemos una respuesta positiva de rappi, pero no nos envían los datos que necesitamos para bd
        if(!isset($responseFromLogin->access_token) && !isset($responseFromLogin->expires_in)){
            return response()->json(
                [
                    "status" => "Error",
                    "results"    => "No es posible obtener el token con estas credenciales. Si el error persiste, contacte con soporte.",
                ],
                409
            );
        }

        /* Si llega hasta acá es porque ya tenemos el token, entonces
        Guarda la info del nuevo token en BD*/
        try {
            DB::beginTransaction();

            //REGISTRA LA NUEVA INTEGRACIÓN
            $newIntegration = StoreIntegrationToken::firstOrNew([
                'type' => 'login',
                'store_id' => $store->id,
                'integration_name' => AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO
            ]);

            $newIntegration->token = $request->rappiPKUserName;
            $newIntegration->password = $request->rappiPKPass;
            $newIntegration->token_type = 'password';
            $newIntegration->refresh_token = $request->rappiPKFranchiseId;
            $newIntegration->scope = $request->rappiPKClientId;
            $newIntegration->save();

            //REGISTRA EL TOKEN
            $newToken = StoreIntegrationToken::firstOrNew([
                'type' => 'kiosko',
                'store_id' => $store->id,
                'integration_name' => AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO
            ]);
            $newToken->token = $responseFromLogin->access_token;
            $newToken->token_type = 'kiosko';
            $newToken->expires_in = time() + $responseFromLogin->expires_in;
            $newToken->save();

            DB::commit();
        } catch (Exception $e) {
            Log::error("--------------------------------------------------------------");
            Log::error("ERROR FROM setRappiPayKioskoIntegration");
            Log::error("NO SE PUDO REGISTRAR LA INTEGRACIÓN EN BD");
            Log::error("ERROR : {$e->getMessage()}");
            Log::error("ERROR : {$e->getFile()}");
            Log::error("ERROR : {$e->getFile()}");
            Log::error("in store {$store->id}");
            Log::error("--------------------------------------------------------------");

            DB::rollBack();

            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Ocurrió un error al tratar de guardar el token en BD. Contacte con soporte.'
                ],
                409
            );
        }

        //Si llega a este token es porque la recuperación del mismo, y el registro de la integración en BD fue existoso
        return response()->json(
            [
                "status" => "Success",
                "results" => "La integración se registró correctamente.",
            ],
            200
        );

    }

    public function makeRequest($method, $url, $params = null, $store = null)
    {

        /*Se hace este ajuste en $store pensando en el Job que no puede reconocer la sesión */
        if (!is_int($store)) {
            $store = empty($store) ? $this->authStore->id : $store->configs->store_id;
        }

        /*Verificamos la vigencia del token */
        try {
            $integrationToken = $this->getToken($store);
        } catch (\Throwable $e) {

            Log::error("--------------------------------------------------------------");
            Log::error("ERROR FROM makeRequest");
            Log::error("NO SE PUDO RECUPERAR EL TOKEN");
            Log::error("ERROR : {$e->getMessage()}");
            Log::error("in store {$store->id}");
            Log::error("--------------------------------------------------------------");

            throw new Exception($e->getMessage());
        }

        try {
            // LÍNEAS PARA DEBUG
            $stack = HandlerStack::create();
            $stack->push(
                Middleware::log(
                    Log::channel('single'),
                    new MessageFormatter('Req Body: {req_body}'/*'Response SIIGO: {res_body}'*/)
                )
            );

            $reqUrlByEnvironment = App::environment('local') ? config('app.rappi_pay_kiosko_req_dev') : config('app.rappi_pay_kiosko_req_prod');

            $client = new Client();
            $response = $client->request($method, $reqUrlByEnvironment . $url, [
                'headers' => [
                    'Authorization'             => "Bearer " . $integrationToken['token'],
                    'Content-Type'              => 'application/json'
                ],
                'json' => $params,
                'handler' => $stack
            ]);
            $data = json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::error("--------------------------------------------------------------");
            Log::error("ERROR FROM makeRequest");
            Log::error("REQUEST TO: {$method} / {$url}");
            Log::error("WITH PARAMS: ".json_encode($params));
            Log::error("RESPONSE: {$exceptionMessage}");
            Log::error("in store {$store->id}");
            Log::error("--------------------------------------------------------------");

            throw new Exception($exceptionMessage);
        }

        return $data;
    }

    public function getToken($store = null)
    {

        $store = empty($store) ? $this->authStore->id : $store;

        /*  Recupera información sobre la integración de la tienda con RappiPay, para 
        *   determinar si se debe solicitar un nuevo token
        */
        $integrationToken = StoreIntegrationToken::where('store_id', $store)
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO)->get();

        // Si no existe la itntegración rompe el flujo
        if(!$integrationToken){
            throw new Exception("No se ha registrado la integración.");
        }

        $actualToken = $integrationToken->where('type', 'kiosko')->first();

        /* Si token en bd no está vencido, ni vacío entonces devuelve $integrationToken->token*/
        if (!empty($actualToken->expires_in) && time() < $actualToken->expires_in) {

            $this->storeFranchiseId = $integrationToken->where('type', 'login')->first()->refresh_token;
            return ["token" => $actualToken->token];
        }

        $getNewToken = $integrationToken->where('type', 'login')->first();

        $this->storeFranchiseId = $getNewToken->refresh_token;

        /* Si token en bd se encuentra vacío o vencido entonces pide un nuevo token*/
        try {

            $loginUrlByEnvironment = App::environment('local') ? config('app.rappi_pay_kiosko_login_dev') : config('app.rappi_pay_kiosko_login_prod');

            $client = new Client();
            $response = $client->request('POST', $loginUrlByEnvironment, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'grant_type' => $getNewToken->token_type,
                    'username' => $getNewToken->token,
                    'password' => $getNewToken->password,
                    'client_id' => $getNewToken->scope
                ]
            ]);

            /* Guarda la información de respuesta */
            $data = json_decode($response->getBody());
        } catch (ClientException $e) {

            /*Lines to debug the response in logs Files if fails*/
            $response   = $e->getResponse();
            $error_body = json_decode($response->getBody());

            $this->logError(
                "ERROR FROM getToken - Store: ".$store,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            throw new Exception($error_body->error);
        }

        /* Guarda la info del nuevo token en BD*/
        try {
            DB::beginTransaction();

            $newToken = StoreIntegrationToken::firstOrNew([
                'type' => 'kiosko',
                'store_id' => $store,
                'integration_name' => AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO
            ]);
            $newToken->token = $data->access_token;
            $newToken->token_type = 'kiosko';
            $newToken->expires_in = time() + $data->expires_in;
            $newToken->save();

            DB::commit();
        } catch (Exception $e) {

            $this->logError(
                "ERROR FROM getToken - NO SE PUDO REGISTRAR EL TOKEN EN BD. Store: ".$store,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            DB::rollBack();

            throw new Exception($error_body->error);
        }

        return ["token" => $newToken->token];
    }

    public function setCommerce($store = null)
    {
        if (!is_int($store)) {
            $store = $this->authStore;
        } else {
            $store = Store::find($store);
        }

        $this->getToken($store->id);

        $findIdIntegration = AvailableMyposIntegration::firstOrCreate([
            'type' => 'kiosko',
            'code_name' => AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO,
            'name' => 'RappiPay Kiosko'
        ]);

        $findExternalCommerce = StoreIntegrationId::firstOrNew([
            'integration_id' => $findIdIntegration->id,
            'integration_name' => 'rappi_pay_kiosko',
            'store_id' => $store->id
        ]);

        if ($findExternalCommerce->external_store_id) {
            return $findExternalCommerce->external_store_id;
        }

	$adminCommerce = Employee::where('store_id', $store->id)->where('type_employee', 1)->first();

        $params = [
            "category" => "RESTAURANT",
            "name" => "T. ".$store->name,
            "phone" => $store->phone,
            "email" => "tienda_".$adminCommerce->email,
            "password" => "123456",
            "notificationUrl" => $this->url . 'rappi_pay_kiosko/webhook',
            "location" => [
                "zipCode" => "1234",
                "address" => $store->address,
                "city" => $store->city->name,
                "latitude" => -75.5635900,
                "longitude" => 6.2518400
            ]
        ];
        
        $res = $this->makeRequest('POST', '/' . $this->storeFranchiseId . '/stores', $params);

        $findExternalCommerce->external_store_id = $res['code'];
        $findExternalCommerce->save();

        return $findExternalCommerce->external_store_id;
    }

    public function setCashier()
    {
        $employee = $this->authEmployee;
        $store = $this->authStore;

        $commerceQr = $this->setCommerce($employee->store_id);

        $findEmployee = EmployeeIntegrationDetails::firstOrNew([
            'employee_id' => $employee->id,
            'integration_name' => AvailableMyposIntegration::NAME_RAPPI_PAY_KIOSKO,
            'integration_type' => 'cashier_rappi_kiosko'
        ]);

        if ($findEmployee->external_id) {
            return $findEmployee;
        }

        $params = [
            "name" => "Cajero ".$employee->name,
            "email" => "cajero_".$employee->email,
            "password" => "123456"
        ];

        $res = $this->makeRequest('POST', '/stores/' . $commerceQr . '/till/', $params, $employee->store_id);

        $findEmployee->external_id = $res['code'];
        $findEmployee->save();

        return $findEmployee;
    }

    public function webhook(Request $request)
    {
        if ($request->payment['status'] == "APPROVED") {
            return $this->createOrder($request);
        } else {
            Log::info('No se puede crear la orden entrante de RappiPay en mypOS: ' . json_encode($request->payment));
        }
    }

    public function createOrder(Request $request)
    {
        $detailOrderFromRappi = $request->payment;

        $employeeId =  $detailOrderFromRappi['data']['order']['results']['employee_id'];

        $request = new Request;
        $request->current_status = "Creada";
        $request->name_status = "En proceso";
        $request->billing_address = null;
        $request->billing_phone = null;
        $request->billing_email = null;
        $request->billing_name = null;
        $request->billing_document = null;
        $request->order_value = $detailOrderFromRappi['data']['order']['results']['total'];
        $request->spot_id = $detailOrderFromRappi['data']['order']['results']['spot_id'];
        $request->date_time = $detailOrderFromRappi['data']['order']['results']['created_at'];
        $request->cash = false;
        $request->change_value = 0;
        $request->payments = [
            [
                "total" => $detailOrderFromRappi['data']['order']['results']['total'],
                "type" => 5
            ]
        ];
        $request->has_billing = false;
        $request->direccion = "";
        $request->phone = "";
        $request->email = "";
        $request->nombre = "CONSUMIDOR FINAL";
        $request->ruc = "9999999999";
        $request->address = "";
        $request->food_service = false;
        $request->order_details = $detailOrderFromRappi['data']['order']['splittedProducts'];
        $request->discount_value = 0;
        $request->discount_percentage = 0;
        $request->invoice_number = null;
        $request->tip = 0;
        $request->people = 1;
        $request->employee_id = $employeeId;
        $request->external_order_id = $detailOrderFromRappi['data']['referenceId'];

        $storeId = $detailOrderFromRappi['data']['order']['results']['store_id'];
        return $this->createSimpleOrder($request, Employee::where("id", $employeeId)->first(), Store::where("id", $storeId)->first());
    }

    public function getQR(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $this->authStore;

        try {
            $setCashier = $this->setCashier();
            $qrCashier = $setCashier->external_id;
        } catch (\Throwable $e) {

            $this->logError(
                "ERROR TRYING TO setCashier - NO SE PUDO RECUPERAR LA CAJA. Store: {$store->name} - {$store->id}",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            return response()->json(
                [
			"status"  => "Error",
			"message" => "Contacte con soporte.",
                ],
                409
            );
        }

        /*Verifica si existe alguna orden pendiente para la caja y la elimina*/
        try {
            $this->cleanCashier();
        } catch (\Throwable $e) {

            $this->logError(
                "ERROR TRYING TO cleanCashier - NO SE PUDO ELIMINAR LA ORDEN PENDIENTE. Store: {$store->name} - {$store->id}",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            return response()->json(
                [
                    "status"  => "Error",
                    "message" => "Imposible configurar la caja en RappiPay (órden pendiente en caja). Contacte con soporte.",
                ],
                409
            );
        }

        /*Creamos la orden para la caja obtenida*/
        try {
            $referenceId = $this->newOrderForRappi($qrCashier, $request->order, $employee->store_id, $request->headers->get('authorization'));
        } catch (\Throwable $e) {
            $this->logError(
                "ERROR FROM getQR IN newOrderForRappi - NO SE PUDO CREAR LA ÓRDEN EN RAPPI. Store: {$store->name} - {$store->id}",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            return response()->json(
                [
                    "status"  => "Error",
                    "message" => "Imposible crear la órden en RappiPay. Contacte con soporte.",
                ],
                409
            );
        }

        /* verificamos si ya está guardada el b64 de la imágen del qr en BD, si no, la pedimos al endpoint*/
        if($setCashier->observations){
            return response()->json(
                [
                    "status"        => "Exito",
                    "b64_image"     => $setCashier->observations,
                    "referenceId"   => $referenceId
                ],
                200
            );
        }

        /* Desde acá solicitamos al endpoint */

        try {
            $client = new Client();
            $response = $client->request('GET', 'https://...' . $qrCashier);
            $data = $response->getBody();
        } catch (ClientException  $e) {
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            $this->logError(
                "ERROR FROM getQR - NO SE PUDO OBTENER EL CÓDIGO QR. Store: ".$store,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                null
            );

            return response()->json(
                [
                    "status"  => "Error",
                    "message" => "No se pudo obtener el código QR desde RappiPay. Contacte con soporte.",
                ],
                409
            );
        }

        $dom = new \DOMDocument();
        $dom->loadHTML($data);
        $body = $this->elementToObject($dom->documentElement);
        $QrB64 = $body['children'][0]['children'][0]['src'];

        /* Primero verificamos que sí exista (Por si cambian la forma de enviar el código en el html) */
        if($QrB64 == null){
            return response()->json(
                [
                    "status"  => "Error",
                    "message" => "No se pudo obtener el código QR desde RappiPay. Contacte con soporte.",
                ],
                409
            );
        }

        /*Guardamos el nuevo código para el empleado*/
        $setCashier->observations = $QrB64;
        $setCashier->save();
        
        Log::info('ENTREGA QR DESDE ENDPOINT');
        return response()->json(
            [
                "status"        => "Exito",
                "b64_image"     => $QrB64,
                "referenceId"   => $referenceId
            ],
            200
        );
    }

    /**
     * Verifica si existe una orden en estado pending y la cancela
     */
    public function cleanCashier()
    {
        $employee = $this->authEmployee;
        $qrCashier = $this->setCashier()->external_id;
        $orderPending = $this->makeRequest('GET', '/qrs/' . $qrCashier . '/orders/PENDING', null, $employee->store_id);

        if (count($orderPending) == 0) {
            // Log::info("No existe orden para cancelar ");
            return true;
        }

        foreach ($orderPending as $order) {
            $cancelOrder = $this->makeRequest('POST', '/qrs/' . $qrCashier . '/orders/' . $order['referenceId'], null, $employee->store_id);

            if ($cancelOrder['status'] == "CANCELED") {
                // Log::info('orden '.$order['referenceId']." cancelada");
            }
        }
    }

    /**
     * Función encargada de crear 
     */
    public function newOrderForRappi($qrCashier, $order, $storeId, $bearer)
    {
        $referenceId = md5($order['results']['id'] . rand($order['results']['id'], $order['results']['id'] * 2));
        $params = [
            "referenceId" => (string) $referenceId,
            "amount" => (string) $order['results']['total'],
            "amountInCents" => "true",
            "notificationUrl" => $this->url.'rappi_pay_kiosko/webhook',
            "data" => [
                "referenceId" => $referenceId,
                "order" => $order
                // "token" => $bearer
            ]
        ];

        $this->makeRequest('POST', '/qrs/' . $qrCashier, $params, $storeId);

        return $referenceId;
    }

    /**
     * Ayuda en la creación de un objeto a partir de HTML
     */
    public function elementToObject($element)
    {
        $obj = array("tag" => $element->tagName);

        foreach ($element->attributes as $attribute) {
            $obj[$attribute->name] = $attribute->value;
        }

        foreach ($element->childNodes as $subElement) {

            if ($subElement->nodeType == XML_TEXT_NODE) {
                $obj["html"] = $subElement->wholeText;
            } else {
                $obj["children"][] = $this->elementToObject($subElement);
            }
        }

        return $obj;
    }

    public function checkOrderStatus(Request $request)
    {
        $employee = $this->authEmployee;
        $qrCashier = $this->setCashier()->external_id;

        $response = $this->makeRequest('GET', '/qrs/' . $qrCashier . '/orders/' . $request->type, null, $employee->store_id);

        foreach ($response as $order) {
            $orderExternalId = OrderIntegrationDetail::where('external_order_id', $order['referenceId'])->first();
            if ($order['qr'] == $qrCashier && $order['referenceId'] == $request->order && isset($orderExternalId->order->identifier)) {
                
                return response()->json(
                    [
                        "status"    => "success",
                        "result"    => $order['referenceId'],
                        "identifier" => isset($orderExternalId->order->identifier) ? $orderExternalId->order->identifier : '',
                    ],
                    200
                );
            }
        }

        return response()->json(
            [
                "status"    => "not_found",
                "result"    => $order['referenceId'],
                "identifier" => isset($orderExternalId->order->identifier) ? $orderExternalId->order->identifier : '',
            ],
            200
        );
    }

    public function cancelOrderInRappi(Request $request)
    {
        $employee = $this->authEmployee;
        $qrCashier = $this->setCashier()->external_id;

        $response = $this->makeRequest('POST', '/qrs/' . $qrCashier . '/orders/' . $request->order, null, $employee->store_id);


        if ($response['status'] == "CANCELED") {
            return response()->json(
                [
                    "status"    => "order_canceled",
                    "result"    => $response['transactionId'],
                ],
                200
            );
        }

        return response()->json(
            [
                "status"    => "order_not_found"
            ],
            200
        );
    }
    
}
