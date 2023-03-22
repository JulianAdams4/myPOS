<?php

namespace App\Traits\Stocky;

// Libraries
use Log;

use GuzzleHttp\Psr7;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

// Models
use App\Component;
use App\ComponentStock;
use App\StockMovement;
use App\InventoryAction;
use App\StoreIntegrationToken;

// Helpers
use App\Traits\Logs\Logging;
use App\Traits\LocaleHelper;
use App\Traits\Utility;


trait StockyRequest
{
    use LocaleHelper;
    private static $channelLogStocky = 'stocky_logs';
    private static $baseUrlUR = null;
    private static $client = null;
    private static $browserUR = null;

    public static function getAccessToken()
    {
        $clientID = config('app.stocky_client_id');
        $clientSecret = config('app.stocky_client_secret');
        $baseUrl = config('app.stocky_url_api');
        $mail = config('app.stocky_user');
        $grantType = config('app.stocky_grant_type');
        $password = config('app.stocky_password');
        $baseUrl = config('app.stocky_url_api');

        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para este servicio',
                'success' => false,
                'data' => null
            ];
        }

        $client = new Client();
        $data = [
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'grant_type' => $grantType,
            'email' => $mail,
            'password' => $password,
        ];
        
        $response = $client->request(
            'POST',
            $baseUrl."/api/oauth/token",
            [
                'headers' => ['Content-type'=>'application/json'],
                'json' => $data,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();
        if ($response->getStatusCode() !== 200) {
            Log::info('Error al obtener el token access de stocky');
            return [
                'message' => 'No se pudo obtener el token de stocky.',
                'success' => false,
                'data' => null
            ];
        } else {
            return [
                'message' => 'Configuración realizada exitosamente.',
                'success' => true,
                'data' => json_decode($responseBody, true)
            ];
        }
    }


    public static function sendRequest($url, $data, $authentication)
    {
        try{
            $client = new Client();
            $baseUrl = config('app.stocky_url_api');

            $response = $client->request(
                'POST',
                $baseUrl.$url,
                [
                    'headers' => [
                        'Content-type'=>'application/json', 
                        'Authorization'=> $authentication
                    ],
                    'json' => $data,
                    'http_errors' => false
                ]
            );
            if ($response->getStatusCode() === 200) {
                return $response;
            }

        }  catch (\Exception $e) {
            Log::info($e->getMessage());
        }
    }


    public static function syncUpdateStock($storeId, $data)
    {

        try{
            $token = StockyRequest::getStockyToken($storeId);

            if(! $token)
            {
                return;
            }

            $data['user_id_tps'] = $token->token;
            $data['inventory_system_id'] = $token->anton_password;
            $data['pos_id'] = $token->token;

            $password = $token->password;
            $store_id = $token->external_store_id;

            $response = StockyRequest::sendRequest(
                "/api/v1/inventory/pos/store/{$store_id}/system/stock/update", 
                $data, 
                $password
            );
            if(!$response){
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ]; 
            }

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


    public static function getStockyToken($store_id)
    {
        $tokenObjFind = StoreIntegrationToken::where("store_id", $store_id)
                            ->where('integration_name', "inventory")
                            ->where('type', 'inventory')
                            ->first();
        
        if(! $tokenObjFind) {
            return;
        }

        return $tokenObjFind;
    }

    public static function syncInventory($storeId, $data)
    {

        try{
            $token = StockyRequest::getStockyToken($storeId);

            if(! $token)
            {
                return;
            }

            $data['user_id_tps'] = $token->token;
            $data['inventory_system_id'] = $token->anton_password;

            $password = $token->password;
            $store_id = $token->external_store_id;

            $response = StockyRequest::sendRequest(
                                                        "/api/v1/inventory/pos/store/{$store_id}/system/sync", 
                                                        $data, 
                                                        $password
                                                    );

            if(!$response){
                return [
                    'message' => 'Fallo el requerimiento',
                    'success' => false,
                    'data' => null
                ]; 
            }

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


    public static function getStockyInventory($storeId)
    {
        $items = array();
        $stocky_array = array();
        $units_array = array();
        $provider_array = array();

        $components = ComponentStock::where("store_id", $storeId)
                                    ->with(["component", "component.provider.invoiceProvider.provider"])->get();
        
        $units_check = array();
        $suppliers_check = array();

        foreach($components as $componentStock)
        {
            $component = $componentStock->component;
            $unitConsume = $component->unitConsume? $component->unitConsume->id : 0; 
            $unitPurchase = $component->unit? $component->unit->id : 0;
            $converted_cost = $componentStock->cost? $componentStock->cost : 0;
            $final_stock = $componentStock->stock? $componentStock->stock: 0;

            $providers = $componentStock->component->provider?
                        $componentStock->component->provider:
                        0 ;
            $size = count($providers);
            $provider_id = $size > 0 ? $providers[$size-1]->invoice_provider_id: "0";

            $item_stocky = array();

            $item_stocky['name'] = $component->name;
            $item_stocky['external_id'] = $component->id.'';
            $item_stocky['sku'] = $component->SKU;
            $item_stocky['purchase_unit_external_id'] = $unitPurchase.'';
            $item_stocky['consumption_unit_external_id'] = $unitConsume.'';
            $item_stocky['supplier_external_id'] = $provider_id.'';
            $item_stocky['cost'] = $converted_cost.'';
            $item_stocky['stock'] = $final_stock.'';

            array_push($stocky_array, $item_stocky);
            
            #units
            if(!array_key_exists($unitConsume, $units_check) && $unitConsume>0)
            {
                $unit_stock_consumption = array();  
                $unit_stock_consumption['name'] = $component->unitConsume->name;
                $unit_stock_consumption['short_name'] = $component->unitConsume->short_name;
                $unit_stock_consumption['external_id'] = $component->unitConsume->id.'';
                $units_check[$component->unit->id] = $component->unit->id.'';
                array_push($units_array, $unit_stock_consumption);
            }
            
            if(!array_key_exists($unitPurchase, $units_check) && $unitPurchase>0)
            {
                $unit_stock_purchase = array();
                $unit_stock_purchase['name'] = $component->unit->name;
                $unit_stock_purchase['short_name'] = $component->unit->short_name;
                $unit_stock_purchase['external_id'] = $component->unit->id.'';
                $units_check[$component->unit->id] = $component->unit->id.'';
                array_push($units_array, $unit_stock_purchase);
            }

            #suppliers
            if($providers !== "0")
            {
                foreach($providers as $provider)
                if(!array_key_exists($provider->id, $suppliers_check))
                {
                    $provider_new = array();
                    $provider_new['name'] = $provider->invoiceProvider->provider->name;
                    $provider_new['external_id'] = $provider->invoice_provider_id.'';
                    array_push($provider_array, $provider_new);
                    $suppliers_check[$provider->id] = $provider->invoiceProvider->provider->name;
                }
            }
        }

        $items['items'] = $stocky_array;
        $items['units'] = $units_array;
        $items['suppliers'] = $provider_array;

        return $items;
    }

}
