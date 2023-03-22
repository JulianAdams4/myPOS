<?php

namespace App\Http\Controllers;

use Log;
use Auth;
use App\Employee;
use App\Jobs\Rappi;
use App\Traits\AuthTrait;
use Illuminate\Http\Request;
use App\StoreIntegrationToken;
use App\Traits\RappiIntegration;
use App\Traits\Mely\MelyIntegration;
use App\Traits\Mely\MelyRequest;
use App\AvailableMyposIntegration;
use App\Http\Controllers\Controller;
use App\CashierBalance;
use App\Section;
use App\SectionIntegration;
use App\StoreConfig;


class IntegrationsController extends Controller
{
    use RappiIntegration, AuthTrait, MelyIntegration ;
    use RappiIntegration, MelyIntegration {
        RappiIntegration::calculateOrderValues insteadof MelyIntegration;
        RappiIntegration::calculateOrderValuesIntegration insteadof MelyIntegration;
        RappiIntegration::processConsumptionAndStock insteadof MelyIntegration;
        RappiIntegration::reduceComponentsStock insteadof MelyIntegration;
        RappiIntegration::reduceComponentsStockBySpecification insteadof MelyIntegration;
        RappiIntegration::reduceComponentStockFromSubRecipe insteadof MelyIntegration;
        RappiIntegration::addConsumptionToProductionOrder insteadof MelyIntegration;
        RappiIntegration::dataHumanOrder insteadof MelyIntegration;
        RappiIntegration::populateInvoiceTaxDetails insteadof MelyIntegration;
        RappiIntegration::getConsumptionDetails insteadof MelyIntegration;
        RappiIntegration::prepareToSendForElectronicBilling insteadof MelyIntegration;
        RappiIntegration::totalTips insteadof MelyIntegration;

        RappiIntegration::getTaxValuesFromDetails insteadof MelyIntegration;


        RappiIntegration::logError insteadof MelyIntegration;
        RappiIntegration::simpleLogError insteadof MelyIntegration;
        RappiIntegration::logIntegration insteadof MelyIntegration;
        RappiIntegration::printLogFile insteadof MelyIntegration;
        RappiIntegration::getSlackChannel insteadof MelyIntegration;
        RappiIntegration::sendSlackMessage insteadof MelyIntegration;
        RappiIntegration::logModelAction insteadof MelyIntegration;

        RappiIntegration::uploadOrder insteadof MelyIntegration;

        RappiIntegration::countryToLocale insteadof MelyIntegration;
        RappiIntegration::countryToCurrency insteadof MelyIntegration;
        RappiIntegration::countryToTaxValue insteadof MelyIntegration;
    }
    

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

    /* RAPPI */
    public function getRappiPassEmployee(Request $request)
    {
        $user = Auth::guard('api')->user();
        $employee = Employee::with('store')->where('user_id', $user->id)->first();
        if (!$employee) {
            return response()->json(
                [
                    'status' => 'Error de autenticacion',
                    'results' => null
                ],
                401
            );
        }
        $store = $employee->store;
        dispatch(new Rappi\GetPassRappi($store));
    }

    public function getRappiOrders(Request $request)
    {
        Log::info("Get all Rappi orders");
        Log::info(json_encode($request->all()));
        $user = Auth::guard('api')->user();
        $employee = Employee::with('store')->where('user_id', $user->id)->first();
        if (!$employee) {
            return response()->json(
                [
                    'status' => 'Error de autenticacion',
                    'results' => null
                ],
                401
            );
        }
        $store = $employee->store;
        $tries = 0;
        dispatch(new Rappi\GetOrdersRappi($store, $tries));
    }

    public function getRappiMenu(Request $request)
    {
        $store = $this->authStore;
        return $this->syncMenu($store);
    }

    public function setRappiOrderEmitted(Request $request)
    {
        Log::info("Set order from Rappi webhook");
        Log::info(json_encode($request->all()));
        $authorizationToken = $request->headers->get('authtoken');
        $storeToken = StoreIntegrationToken::where('token', $authorizationToken)
                        ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)->first();
        if (!$storeToken) {
            return response()->json(
                [
                    'status' => 'Error de autenticacion',
                    'results' => "No se encuentra el local solicitado"
                ],
                401
            );
        }
        try {
            dispatch(new Rappi\SetOrderEmitted($storeToken->store, json_encode($request->all()), $storeToken));
        } catch (\Exception $e) {
            Log::info($e);
            return response()->json(
                [
                    'status' => 'Formato invalido de orden',
                    'results' => "No se pudo ingresar la orden"
                ],
                400
            );
        }
    }
    public function updateRappiTokens(Request $request)
    {

        $this->updatePassword();
        return response()->json(
            [
                'status' => 'update',
            ],
            200
        );
    }
    

    public function getRappiOrdersJob(Request $request)
    {

        dispatch(new Rappi\FindOrdersPerStore());
        return response()->json(
            [
                'status' => 'get_orders',
            ],
            200
        );
    }

    /* END OF RAPPI */

    public function postMelyOrder(Request $request){

        Log::info("Set order from anton webhook.: ".$request->delivery_name." token: ".$request->token. "external_order_id: ".$request->external_id);
        $customStatus = null;
        switch (strtolower($request->delivery_name)) {
            case AvailableMyposIntegration::NAME_RAPPI:
                $storeToken = StoreIntegrationToken::where('token', $request->token)
                    ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
                    ->where('is_anton', true)
                    ->first();
                $customStatus = [
                    "delivery_id"=> strval($request->delivery_id),
                    "store_id"=> strval($request->internal_store_id)
                ];
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
                }
                break;
            default:
                $storeToken = StoreIntegrationToken::where('token', $request->external_store_id)
                    ->where('integration_name', 'mely')
                    ->where('scope', $request->delivery_id)
                    ->where('token_type', $request->anton_store_id)
                    ->first();
        }
        
        if (!$storeToken) {
            MelyIntegration::rejectOrderMely($request->external_id, $storeToken, 0, "Error de autenticacion",$customStatus);
            return response()->json(
                [
                    'status' => 'Error de autenticacion',
                    'results' => "No se encuentra el local solicitado"
                ],
                401
            );
        }
        
        $cashierBalance = CashierBalance::where('store_id', $storeToken->store->id)
            ->whereNull('date_close')
            ->first();
    
        if(is_null($cashierBalance)){
            //rechazar orden 
            MelyIntegration::rejectOrderMely($request->external_id, $storeToken, 0, "La tienda no tiene aperturada la caja",$customStatus);
            return response()->json(
                [
                    'status' => false,
                    'message' => "La caja esta cerrada"
                ],
                409
            );
            
        }
        $traitDelivery = null;
        switch (strtolower($request->delivery_name)) {
            case AvailableMyposIntegration::NAME_RAPPI:
                //RAPPI ORDER
                $integratedStatus = $this->setRappiOrderFromMely($request->all(), $storeToken->store);
                //Se procede a traer el storeconfig para comprobar si la tienda tiene el automatic true.
                $storeConfiguration = StoreConfig::where('store_id', $storeToken->store->id)
                    ->first();
                $automatic= $storeConfiguration->automatic;
                if($integratedStatus['success']==true ){
                    if($automatic){
                        MelyIntegration::acceptOrderMely($request->external_id, $storeToken, 0,$customStatus);
                    }
                   
                    $traitDelivery = [
                        'message' => $integratedStatus['message'],
                        'results' => "Orden procesada"
                    ];
                }else{
                    MelyIntegration::rejectOrderMely($request->external_id, $storeToken, 0, "No se integró la orden",$customStatus);
                    $traitDelivery = [
                        'message' => "La orden se procesó con un error",
                        'results' => "Orden"
                    ];
                }
                break;
            default:
                //MELY ORDER
                $section = Section::where('store_id',$storeToken->store->id)
                ->where('store_id', $storeToken->store->id)
                ->where('id', $request->menu_identifier)->first();
               
                if(is_null($section)){
                    //rechazar orden 
                    MelyIntegration::rejectOrderMely($request->external_id, $storeToken, 0, "La tienda no cuenta con el menú especificado");
                    $traitDelivery = [
                        'message' => "La tienda no cuenta con el menú especificado",
                        'results' => "Orden"
                    ];
                }else{
                    $availableIntegration = AvailableMyposIntegration::where('code_name','mely_'.$storeToken->scope)
                        ->where('anton_integration', $storeToken->scope)->first();

                    $sectionIntegration = SectionIntegration::where('section_id',$section->id)
                        ->where('integration_id', $availableIntegration->id)->first();
                    if(is_null($sectionIntegration)){
                        //rechazar orden 
                        MelyIntegration::rejectOrderMely($request->external_id, $storeToken, 0, "El menú no tiene la integración habilitada");
                    }else{
                        $orderProccess = MelyIntegration::processtIntegrationMelyOrder($storeToken,$cashierBalance,$sectionIntegration ,$request, $availableIntegration->name);
                        $traitDelivery = [
                            'message' => $orderProccess['message'],
                            'results' => "Orden procesada"
                        ];
                    }
                }
                break;
        }

        return response()->json(
            $traitDelivery,
            201
        );
    }
}
