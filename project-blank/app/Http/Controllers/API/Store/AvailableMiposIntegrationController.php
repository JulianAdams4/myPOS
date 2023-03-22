<?php

namespace App\Http\Controllers\API\Store;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\AvailableMyposIntegration;
use App\Store;
use App\Product;
use App\ProductIntegrationDetail;
use App\StoreIntegrationToken;
use App\Traits\AuthTrait;
use App\Traits\Mely\MelyRequest;
use App\Traits\FranchiseHelper;

use Illuminate\Support\Facades\DB;
use App\Traits\Logs\Logging;
use Exception;

class AvailableMyposIntegrationController extends Controller
{
    use AuthTrait;

    public $authUser;
    public $authEmployee;
    public $authStore;
    private static $channelLogNormalUR = 'mely_logs';

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

    public function getDeliveryIntegrations()
    {
        $deliveryIntegrations = AvailableMyposIntegration::all();

        return response()->json(
            [
                'status' => 'Integraciones',
                'results' => $deliveryIntegrations
            ],
            200
        );
    }

    public function getProductIntegrationsById($id)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $productStore = Store::whereHas('sections.categories.products', function ($query) use ($id) {
            return $query->where('id', $id);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($productStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $product = Product::where('id', $id)
            ->where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($productStore) {
                    $q->where('company_id', $productStore->company_id);
                }
            )
            ->first();
        if ($product == null) {
            return response()->json(
                [
                    'status' => 'El producto no existe',
                    'results' => null
                ],
                409
            );
        }

        $productIntegration
            = ProductIntegrationDetail::where('product_id', $product->id)
            ->with(['toppings', 'ifoodPromotion'])
            ->get();

        return response()->json(
            [
                'status' => 'Producto integration info',
                'results' => $productIntegration,
            ],
            200
        );
    }

    public function updateIntegrationToken(Request $request)
    {
        if (!isset($request->integration_name) || !isset($request->token) || !isset($request->type) || !isset($request->scope)) {
            return response()->json(
                [
                    'status' => 'Faltan parametros en el request'
                ],
                400
            );
        }
        $store = $this->authStore;
        $tokenObj = StoreIntegrationToken::where("store_id", $store->id)
            ->where('integration_name', $request->integration_name)
            ->where('type', $request->type)
            ->where('scope', $request->scope)
            ->first();
        $message = "";
        if ($tokenObj) {
            $tokenObj->token = $request->token;
            $tokenObj->password = null;
            $tokenObj->save();
            $tokenObj->password = null;
            $message = "Se ha actualizado el token correctamente";
        } else {
            $tokenObj = new StoreIntegrationToken();
            $tokenObj->integration_name = $request->integration_name;
            $tokenObj->token = $request->token;
            $tokenObj->store_id = $store->id;
            $tokenObj->type = $request->type;
            $tokenObj->scope = $request->scope;
            $tokenObj->save();
            $message = "Se ha creado el token correctamente";
        }

        return response()->json(
            [
                'status' => $message
            ],
            201
        );
    }

    public function createAntonStore(Request $request)
    {

        try {
            $store = $this->authStore;
            $response = MelyRequest::createStore($store);
            if ($response['success'] == false) throw new Exception($response['message']);
            $anton_store_id = $response['data']['data']['id'];

            $tokenObj = new StoreIntegrationToken();
            $tokenObj->integration_name = "mely";
            $tokenObj->store_id = $store->id;
            $tokenObj->type = 'delivery';
            $tokenObj->token = '';
            $tokenObj->token_type = $anton_store_id;
            $tokenObj->password = $response['token'];
            $tokenObj->save();

            return response()->json(
                [
                    'status' => "Tienda creda exitosamente en Anton",
                    'data' => ['anton_store_id' => $anton_store_id]
                ],
                201
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'status' => $e->getMessage()
                ],
                400
            );
        }
    }

    public function tpIntegrationConfig(Request $request)
    {
        if (!isset($request->integration_id) || !isset($request->external_store_id)) {
            return response()->json(
                [
                    'status' => 'Faltan parametros en el request'
                ],
                400
            );
        }
        $store = $this->authStore;

        $tokenObj = StoreIntegrationToken::where("store_id", $store->id)
            ->where('integration_name', "mely")
            ->where('type', 'delivery')
            ->first();
        if (is_null($tokenObj)) {
            return response()->json(
                [
                    'status' => 'La tienda no se encuentra configurada en Anton'
                ],
                400
            );
        }

        //si se trata de una configuración para rappi, entonces configuramos desde otra función
        if($request->integration_id == 2){
            return $this->tpRappiIntegrationConfig($request, $tokenObj);
        }

        try{
            $message = "";
            $componentJSON = DB::transaction(
                function () use ($store, $request, $tokenObj, $message) {
                    //Configuración ANTON

                    $token =  $tokenObj->password;
                    $melyConfig = MelyRequest::setupStore(
                        $request->external_store_id,
                        $request->integration_id,
                        $request->anton_store_id,
                        $token
                    );
                    if ($melyConfig["success"] != true) {
                        throw new Exception($melyConfig["message"]);
                    }


                    //crear o actualiza el registro de integración en myPOS availible integrations
                    $availableInt = AvailableMyposIntegration::where('code_name', 'mely_' . $request->integration_id)
                        ->where('type', 'delivery')
                        ->where('anton_integration', $request->integration_id)->first();
                    if (is_null($availableInt)) {
                        $availableInt = new AvailableMyposIntegration();
                        $availableInt->code_name = "mely_" . $request->integration_id;
                        $availableInt->type = "delivery";
                        $availableInt->anton_integration = $request->integration_id;
                    }
                    $availableInt->name = $melyConfig['data']['data']['integration_name'];
                    $availableInt->save();

                    if ($tokenObj->token == "" || $tokenObj->scope == null) {
                        $tokenObj->token = $request->external_store_id;
                        $tokenObj->scope = $request->integration_id;
                        $tokenObj->save();
                        $message = "Se ha actualizado la integración correctamente correctamente";
                    } else {
                        $tokenObjFind = StoreIntegrationToken::where("store_id", $store->id)
                            ->where('integration_name', "mely")
                            ->where('scope', $request->integration_id)
                            ->where('type', 'delivery')
                            ->first();

                        if (!is_null($tokenObjFind)) {
                            $tokenObjFind->token = $request->external_store_id;
                            $tokenObjFind->save();
                            $message = "Se ha actualizado la integración correctamente correctamente";
                        } else {
                            $newTokenObj = new StoreIntegrationToken();
                            $newTokenObj->integration_name = "mely";
                            $newTokenObj->token = $request->external_store_id;
                            $newTokenObj->store_id = $store->id;
                            $newTokenObj->type = 'delivery';
                            $newTokenObj->token_type = $tokenObj->token_type;
                            $newTokenObj->scope = $request->integration_id;
                            $newTokenObj->password = $tokenObj->password;
                            $newTokenObj->save();
                            $message = "Se ha creado la configuración correctamente";
                        }
                    }
                }
            );
            return response()->json(
                [
                    'status' => $message,
                    'success' => true
                ],
                201
            );
        } catch (\Exception $e) {
            Logging::printLogFile(
                "ERROR en setupMely: " . $store->name,
                self::$channelLogNormalUR
            );
            return response()->json(
                [
                    'status' => 'No se ha podido realizar la configuración. ' . $e->getMessage(),
                    'results' => null
                ],
                409
            );
        }
    }

    public function tpRappiIntegrationConfig($request, $tokenObj){
        $store = $this->authStore;

        $rappiInt = StoreIntegrationToken::where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->where('store_id', $store->id)
            ->whereNotNull('token')
            ->first();

        if (is_null($rappiInt)) {
            return response()->json(
                [
                    'status' => 'Primero debe configurar la integración con Rappi.'
                ],
                400
            );
        }

        try{
            $message = "";
            $componentJSON = DB::transaction(
                function () use ($store, $request, $tokenObj, $message, $rappiInt) {
                    //Configuración ANTON
                    $token =  $tokenObj->password;
                    $melyConfig = MelyRequest::setupStore(
                        $request->external_store_id, 
                        $request->integration_id, 
                        $request->anton_store_id, 
                        $token
                    );

                    if($melyConfig["success"]!=true){
                        throw new Exception($melyConfig["message"]);
                    }

                    $rappiInt->external_store_id = $request->external_store_id;
                    $rappiInt->save();
                }
            );
            return response()->json(
                [
                    'status' => $message,
                    'success' =>true
                ],
                201
            );
        } catch (\Exception $e) {
            Logging::printLogFile(
                "ERROR en setupMelyRappi: " . $store->name,
                self::$channelLogNormalUR
            );
            return response()->json(
                [
                    'status' => 'No se ha podido realizar la configuración. '.$e->getMessage(),
                    'results' => null
                ],
                409
            );
        }
    }

    public function tpIntegrationDelete(Request $request){
        if(!isset($request->store_id) || !isset($request->integration_name) || !isset($request->id)){
            return response()->json(
                [
                    'status' => 'Faltan parametros en el request'
                ],
                400
            );
        }
        $store = $this->authStore;
        if ($request->store_id != $store->id) {
            return response()->json(
                [
                    'status' => 'El store ID es inválido'
                ],
                400
            );
        }
        if ($request->integration_name != "mely") {
            return response()->json(
                [
                    'status' => 'Nombre de integración inválido'
                ],
                400
            );
        }

        $tokenObj = StoreIntegrationToken::where("store_id", $store->id)
            ->where('integration_name', "mely")
            ->where('id', $request->id)
            ->first();
        if (is_null($tokenObj)) {
            return response()->json(
                [
                    'status' => 'La tienda no se encuentra configurada en Anton'
                ],
                400
            );
        }
        try {
            $tokenObj->forceDelete();
            return response()->json(
                [
                    'status' => "Integración eliminada correctamente.",
                    'success' => true
                ],
                201
            );
        } catch (\Exception $e) {
            Logging::printLogFile(
                "ERROR en delete Third Party: " . $store->name,
                self::$channelLogNormalUR
            );
            return response()->json(
                [
                    'status' => 'No se ha podido eliminar.',
                    'results' => null
                ],
                409
            );
        }
    }

    public function getIntegrationsOnlyDelivery(Request $request)
    {
        $integrations = AvailableMyposIntegration::select(
            'id',
            'name',
            'code_name',
            'type'
        )->where('type', 'delivery')->get();

        return response()->json([
            'results' => $integrations
        ], 200);
    }
}
