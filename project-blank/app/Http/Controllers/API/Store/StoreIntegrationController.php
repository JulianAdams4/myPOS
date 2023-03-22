<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Employee;
use App\StoreIntegrationToken;
use App\Traits\AuthTrait;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\DidiFood\DidiRequests;
use Illuminate\Support\Facades\DB;
use App\StoreIntegrationId;
use App\StoreConfig;
use App\AvailableMyposIntegration;
use Log;
use App\DynamicPricingRule;
use Carbon\Carbon;
use App\Traits\Logs\Logging;
use App\Traits\Uber\UberRequests;

class StoreIntegrationController extends Controller
{

    use AuthTrait, DidiRequests;

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

    public function getStoreIntegrations()
    {
        $store = $this->authStore;
        $integrations = DB::select("select distinct ami.id as available_id, st.*, ami.name as third_party_name, ami.anton_integration 
            from store_integration_tokens st
            left join available_mypos_integrations ami on
            ami.anton_integration = st.scope and ami.anton_integration is not null 
			 where st.store_id=?;",array($store->id));

        $groupsIntegrations = collect($integrations)->groupBy('type');
        // $groupsIntegrations = $integrations->groupBy('type');
        return response()->json(
            [
                'status' => 'Listando integraciones',
                'results' => $groupsIntegrations
            ],
            200
        );
    }

    public function setUpDidi()
    {
        $store = $this->authStore;
        $didiUrl = config('app.didi_url_api');
        $didiAppId = config('app.didi_app_id');
        $didiAppSecret = config('app.didi_app_secret');

        if (!isset($store)) {
            return response()->json([
                'status' => 'No se identificó al usuario que realizó este requerimiento',
                'results' => null
            ], 200);
        }

        $integrationDidi = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_DIDI)->first();

        if ($integrationDidi == null) {
            return ([
                "message" => "myPOS no tiene configurado la integración con Didi Food",
                "code" => 409
            ]);
        }

        $this->initVarsDidiRequests();

        $result = $this->getToken(
            $store->id,
            $store->name
        );

        if ($result["status"] == 0) {
            return response()->json([
                'status' => "Error al obtener el token de Didi,
                    asegurarse que ya le indicaron a Didi que les configure esta tienda",
                'results' => $result["status"]
            ], 409);
        }

        $dataToken = $result["data"];

        try {
            $processJSON = DB::transaction(
                function () use ($dataToken, $store, $integrationDidi) {
                    $storeToken = new StoreIntegrationToken();
                    $storeToken->store_id = $store->id;
                    $storeToken->integration_name = 'didi';
                    $storeToken->token = $dataToken->auth_token;
                    $storeToken->type = 'delivery';
                    $storeToken->expires_in = $dataToken->token_expiration_time;
                    $storeToken->save();

                    $storeIntegrationId = new StoreIntegrationId();
                    $storeIntegrationId->integration_id = $integrationDidi->id;
                    $storeIntegrationId->store_id = $store->id;
                    $storeIntegrationId->external_store_id = $store->id;
                    $storeIntegrationId->save();

                    return response()->json([
                        'status' => 'Se configuró la tienda de Didi exitosamente',
                        'results' => null
                    ], 200);
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            $this->logError(
                "StoreIntegrationController API Store error setUpDidi: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                []
            );
            return response()->json([
                'status' => 'No se pudo obtener el token para Didi',
                'results' => null
            ], 409);
        }
    }

    public function getStoresTokens()
    {
        $integrations = AvailableMyposIntegration::select(
            'id',
            'code_name',
            'type'
        )->where('type', 'delivery')->orWhere('type', 'pos')->get()->pluck('code_name');

        $storeTokens = StoreIntegrationToken::select(
            'id',
            'store_id',
            'integration_name',
            'token',
            'password',
            'type',
            'created_at',
            'updated_at',
            'expires_in',
            'refresh_token',
            'scope'
        )->whereIn('integration_name', $integrations)->get();

        foreach ($storeTokens as $storeToken) {
            $storeToken->makeVisible([
                'created_at',
                'updated_at',
                'expires_in',
                'refresh_token',
                'scopes'
            ]);
        }

        return response()->json([
            'results' => $storeTokens
        ], 200);
    }

    public function getExternalStoreIds()
    {
        $integrations = AvailableMyposIntegration::select(
            'id',
            'code_name',
            'type'
        )->where('type', 'delivery')->orWhere('type', 'pos')->get();

        $storeExternalIds = StoreIntegrationId::select(
            'integration_id',
            'store_id',
            'external_store_id',
            'restaurant_chain_external_id',
            'restaurant_branch_external_id'
        )->whereIn('integration_id', $integrations->pluck('id'))->get();

        $storeConfigs = StoreConfig::select(
            'store_id',
            'eats_store_id'
        )->whereNotNull('eats_store_id')->get();

        $dataExternalIds = [];

        foreach ($storeExternalIds as $externalId) {
            if (isset($dataExternalIds[$integrations->where('id', $externalId->integration_id)->first()->code_name])) {
                array_push(
                    $dataExternalIds[$integrations->where('id', $externalId->integration_id)->first()->code_name],
                    [
                        'store_id' => $externalId->store_id,
                        'external_store_id' => $externalId->external_store_id,
                        'restaurant_chain_external_id' => $externalId->restaurant_chain_external_id,
                        'restaurant_branch_external_id' => $externalId->restaurant_branch_external_id
                    ]
                );
            } else {
                $dataExternalIds[$integrations->where('id', $externalId->integration_id)->first()->code_name] = [
                    [
                        'store_id' => $externalId->store_id,
                        'external_store_id' => $externalId->external_store_id,
                        'restaurant_chain_external_id' => $externalId->restaurant_chain_external_id,
                        'restaurant_branch_external_id' => $externalId->restaurant_branch_external_id
                    ]
                ];
            }
        }

        foreach ($storeConfigs as $config) {
            if (isset($dataExternalIds['uber_eats'])) {
                array_push(
                    $dataExternalIds['uber_eats'],
                    [
                        'store_id' => $config->store_id,
                        'external_store_id' => $config->eats_store_id,
                        'restaurant_chain_external_id' => null,
                        'restaurant_branch_external_id' => null
                    ]
                );
            } else {
                $dataExternalIds['uber_eats'] = [
                    [
                        'store_id' => $config->store_id,
                        'external_store_id' => $config->eats_store_id,
                        'restaurant_chain_external_id' => null,
                        'restaurant_branch_external_id' => null
                    ]
                ];
            }
        }

        return response()->json([
            'results' => $dataExternalIds
        ], 200);
    }

    public function getDynamicPricingRules()
    {
        $store = $this->authStore;
        $rules = DynamicPricingRule::where('store_id', $store->id)
            ->with('timelines')
            ->withTrashed()
            ->get();
        return response()->json([
            'status' => 'Listando reglas',
            'results' => $rules
        ], 200);
    }

    public function storeDynamicPricingRules(Request $request)
    {
        $store = $this->authStore;
        $storeRules = $request->all();
        try {
            $resultJSON = DB::transaction(function () use ($storeRules, $store) {
                if (is_null($store)) {
                    return response()->json([
                        'status' => 'No autorizado',
                        'results' => null
                    ], 401);
                }
                $success = 0;
                foreach ($storeRules as $storeRule) {
                    $rule = null;
                    if (strpos($storeRule['id'], 'new') === false) {
                        $rule = DynamicPricingRule::where('id', $storeRule['id'])
                            ->where('store_id', $store->id)
                            ->withTrashed()
                            ->first();
                    } else {
                        $rule = new DynamicPricingRule();
                    }
                    if (!is_null($rule)) {
                        $rule->store_id = $store->id;
                        $rule->type = $storeRule['type'];
                        $rule->rule = $storeRule['rule'];
                        $rule->deleted_at = $storeRule['deleted_at'] == null ? null : Carbon::now();
                        $rule->save();
                        $success++;
                    }
                }
                if ($success === count($storeRules)) {
                    return response()->json([
                        'status' => 'Reglas guardadas exitosamente',
                        'results' => null
                    ], 200);
                } elseif ($success !== 0) {
                    return response()->json([
                        'status' => 'No todas las reglas se guardaron exitosamente (consulte con el desarrollador)',
                        'results' => null
                    ], 200);
                } else {
                    return response()->json([
                        'status' => 'No se pudo guardar ninguna regla',
                        'results' => null
                    ], 409);
                }
            });
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::logError(
                "StoreIntegrationController storeDynamicPricingRules: ERROR GUARDAR RULES, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo guardar ninguna regla',
                'results' => null
            ], 409);
        }
    }

    public function setUpUber(Request $request)
    {
        $store = $this->authStore;
        
        $result = UberRequests::getToken($store->id);

        if ($result["success"] == 0) {
            return response()->json([
                'status' => $result["message"],
                'results' => null
            ], 409);
        }

        $dataToken = $result["data"];
        $integrationData = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_EATS)->first();

        if ($integrationData == null) {
            return response()->json([
                'status' => "myPOS no tiene configurado la integración con Uber",
                'results' => null
            ], 409);
        }

        try {
            $processJSON = DB::transaction(
                function () use ($dataToken, $store, $request, $integrationData) {
                    $existStoreToken = StoreIntegrationToken::where('store_id', $store->id)
                        ->where('integration_name', $integrationData->code_name)
                        ->first();
                    $storeToken;
                    if (is_null($existStoreToken)) {
                        $storeToken = new StoreIntegrationToken();
                        $storeToken->store_id = $store->id;
                        $storeToken->integration_name = $integrationData->code_name;
                        $storeToken->type = 'delivery';
                    } else {
                        $storeToken = $existStoreToken;
                    }
                    $storeToken->token = $dataToken['access_token'];
                    $storeToken->expires_in = $dataToken['expires_in'];
                    $storeToken->save();

                    // Actualizando todos los tokens de Uber
                    $storeIntegrationTokens = StoreIntegrationToken::where('integration_name', $integrationData->code_name)
                        ->get();
                    foreach ($storeIntegrationTokens as $storeIntegrationToken) {
                        $storeIntegrationToken->token = $dataToken['access_token'];
                        $storeIntegrationToken->expires_in = $dataToken['expires_in'];
                        $storeIntegrationToken->save();
                    }

                    $storeConfig = StoreConfig::where('store_id', $store->id)
                        ->first();
                    if (is_null($storeConfig)) {
                        throw new \Exception("Esta tienda no tiene configuración");
                    }
                    $storeConfig->eats_store_id = $request->uuid;
                    $storeConfig->save();

                    return response()->json([
                        'status' => 'Se configuró la tienda de Uber exitosamente',
                        'results' => null
                    ], 200);
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            $this->logError(
                "StoreIntegrationController API Store error setUpUber: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                []
            );
            return response()->json([
                'status' => 'No se pudo obtener configurar la tienda para Uber',
                'results' => null
            ], 409);
        }
    }
}
