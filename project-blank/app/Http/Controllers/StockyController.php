<?php

namespace App\Http\Controllers;

use Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

use App\Traits\LoggingHelper;
use App\Traits\Logs\Logging;

use App\Component;
use App\ComponentStock;
use App\StockMovement;
use App\InventoryAction;
use App\Traits\Stocky\StockyRequest;


class StockyController extends Controller
{

    use StockyRequest;
    /**
     *
     * Recive el envento de una actualizacion de item
     *
     * @param Request $request    Objeto con los datos principales de la actualizacion del item
     *
     * @return Response           Estado del requerimiento
     *
     */
    public function webhookOrder(Request $request)
    {

        $bodyRequest = $request->all();

        $validator = Validator::make($request->all(), [
            'pos_id' => 'required|string',
            'user_id_tps' => 'required|string',
            'inventory_system_id' => 'required|string',
            'current_stock' => 'required|string',
            'quantity' => 'required|string',
            'date' => 'required|string',
            'movement_type' => 'integer|string',
            'external_id' => 'required|string',
            'now' => 'required|string',
            'store_id' => 'required|string',
        ]);
        

        $store_id = $request->store_id;
        $component_id = $request->external_id;
        $component = Component::where('id', $component_id)->first();
        
        if(! $component) {
            return response()->json([], 409);
        }

        $componentStock = ComponentStock::where('component_id', $component->id)->first();

        if(! $componentStock) {
            return response()->json([], 409);
        }

        $componentStock->stock = $request->current_stock;
        $componentStock->store_id = $request->store_id;
        $componentStock->save();

        $lastStockMovement = StockMovement::where('component_stock_id', $componentStock->id)
        ->orderBy('id', 'desc')
        ->first();

        $initial_stock = isset($lastStockMovement) ? $lastStockMovement->final_stock : 0;
        $final_stock = $request->current_stock;

        $inventoryCode = $request->movement_type == 1? "receive" : "lost";
        $consumptionAction = InventoryAction::where('code', $inventoryCode)->first();

        $stock_movement = new StockMovement();
        $stock_movement->inventory_action_id = $consumptionAction->id;
        $stock_movement->initial_stock = $initial_stock;
        $stock_movement->value = $request->quantity;
        $stock_movement->final_stock = $final_stock;
        $stock_movement->cost = $lastStockMovement->cost; // Costo por unidad de consumo
        $stock_movement->component_stock_id = $componentStock->id;
        $stock_movement->created_by_id = $request->store_id;
        $stock_movement->user_id = $lastStockMovement->user_id;
        $stock_movement->invoice_provider_id = $lastStockMovement->invoice_provider_id;
        $stock_movement->save();
        
        return response()->json([], 204);
    }


    public function createInventorySystem(Request $request)
    {
        $baseUrl = config('app.stocky_url_api');
        try{
            $client = new Client();
            $data = array();

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyRequest::sendRequest("/api/v1/inventory/system/create" , $data, $tokenData);

            if ($response->getStatusCode() === 200) {
                return $response;
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "Saving pos",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }

    public function manageWebHook(Request $request)
    {
        try{
            $data = array();

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );    
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyRequest::sendRequest("/api/v1/inventory/system/webhook/manage", $data, $tokenData);

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "Saving pos",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }

    public function updateStockItem(Request $request)
    {
        try{
            $data = array();

            $request->validate([
                'inventory_system_id' => 'required|string',
                'pos_id' => 'required|string',
                'current_stock' => 'required|string',
                'quantity' => 'required|string',
                'date' => 'required|string',
                'movement_type' => 'integer|string',
                'external_id' => 'required|string',
                'store_id' => 'required|string',
            ]);

            $store_id = $request->store_id;

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );    
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyController::sendRequest(
                                                        "/api/v1/inventory/system/store/{$store_id}/pos/stock/update", 
                                                        $data, 
                                                        $tokenData
                                                    );

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "updating item",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }

    public function syncInventory(Request $request)
    {

        try{
            $data = array();

            $array = [
                'user_id_tps' => 'required|string',
                'items' => 'required|array',
                'inventory_system_id' => 'required|string',
                'units' => 'required|array',
                'suppliers' => 'required|array',
                'store_id' => 'required|string',
            ];

            $store_id = $request->store_id;

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );    
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyRequest::sendRequest(
                                                        "/api/v1/inventory/pos/store/{$store_id}/system/sync", 
                                                        $data, 
                                                        $tokenData
                                                    );

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "updating item sync",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }

    }

    public function updateSyncStockItem(Request $request)
    {
        try{
            $data = array();

            $array = [
                'user_id_tps' => 'required|string',
                'inventory_system_id' => 'required|string',
                'current_stock' => 'required|string',
                'quantity' => 'required|string',
                'date' => 'required|string',
                'movement_type' => 'required|integer',
                'external_id' => 'required|string',
                'store_id' => 'required|string',
            ];

            $store_id = $request->store_id;

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );    
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyRequest::sendRequest(
                                                        "/api/v1/inventory/pos/store/{$store_id}/system/stock/update", 
                                                        $data,
                                                        $tokenData
                                                    );

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return [
                    'message' => 'Error en requerimiento',
                    'success' => false,
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "updating item sync",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }

    public function posWebhook(Request $request)
    {
        try{
            $data = array();

            $requestFields = $request->all();
            foreach ($requestFields as $key => $value) {
                $data[$key] = $value;
            }

            $tokenData = "";
            $password = StockyRequest::getAccessToken();
            if($password["success"]!=true){
                return response()->json(
                    [
                        'status' => false,
                        'message' => "La tienda no tiene configurada la integración con anton"
                    ],
                    409
                );    
            } else {
                $tokenData = $password["data"]['data']['token_type']." ".$password["data"]['data']['token'];
            }

            $response = StockyRequest::sendRequest("/api/v1/inventory/pos/webhook/manage", $data, $tokenData);

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "Webhook pos",
                'stocky_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }

}