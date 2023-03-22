<?php

namespace App\Http\Controllers\API\JobControllers\Integrations;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;

use Log;
use Buzz\Browser;
use Carbon\Carbon;
use Buzz\Client\FileGetContents;
use Buzz\Message\FormRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

class UberRefresh extends Controller
{

    public function __construct()
    {
        // ...
    }

    /**
     * Funcion principal
     * @return Json response
     */
    public function refreshToken(Request $request)
    {
        try {
            $name_eats = AvailableMyposIntegration::NAME_EATS;
            $integration_stores = StoreIntegrationToken::where('integration_name', $name_eats);
            if (config('app.env') == 'production') { // ***
                $integration_stores = $integration_stores->whereNotNull('store_id');
            } else {
                $integration_stores = $integration_stores->where('store_id', 3);
            }
            $integration_stores = $integration_stores->where('type', 'delivery')->get();

            if (!$integration_stores) {
                return response()->json(['status' => 'No hay tiendas'], 409);
            }

            $failedIds = [];
            foreach ($integration_stores as $store) {
                if ($this->checkRemainingDaysUber($store)) {
                    $response = $this->refreshUberToken();

                    if ($response->getStatusCode() !== 200) {
                        $message = "No se pudo actualizar el token de Uber de tienda con id '.$store->store_id";
                        Log::channel('UberTokenRefresh')->info($response->getStatusCode());
                        Log::channel('UberTokenRefresh')->info($response->getBody()->__toString());
                        Log::channel('UberTokenRefresh')->info($message);
                        array_push($failedIds, $store->store_id);
                    } else {
                        $this->updateTokenData($store->store_id, $response);
                        $message = "Se actualizo el token de Uber de tienda con id '.$store->store_id";
                        Log::channel('UberTokenRefresh')->info($response->getStatusCode());
                        Log::channel('UberTokenRefresh')->info($response->getBody()->__toString());
                        Log::channel('UberTokenRefresh')->info($message);
                    }
                }
            }

            if (count($failedIds) > 0) {
                $idsStr = implode(", ", $failedIds);
                return response()->json(['status' => "Falló al actualizar los tokens de Uber de las tiendas: ".$idsStr], 409);
            }

            return response()->json(['status' => "OK"], 200);
        } catch (\Exception $e) {
            $msg = "Falló al actualizar los tokens de Uber";
            Log::error($msg.": ".$e);
            return response()->json(['status' => $msg], 500);
        }
    }

    /**
     * Funcion que transforma el tiempo en segundos a dias
     * @return float
     */
    public function checkRemainingDaysUber($store)
    {
        $last_time = $store->updated_at;
        $now = Carbon::now();
        $emitted = Carbon::parse($last_time);
        $diff = $now->diffInSeconds($emitted);
        #checando si el tiempo es menor a un dia con 12 horas
        return $diff > ($store->expires_in - 129600);
    }

    public function refreshUberToken()
    {
        $clientID = config('app.eats_client_id_v2');
        $clientSecret = config('app.eats_client_secret_v2');
        $redirectUrl = config('app.eats_client_redirect_url');
        $uberEatsEndpoint = config('app.eats_login_api');

        try {
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            $builder = new FormRequestBuilder();
            $builder->addField('client_secret', $clientSecret);
            $builder->addFile('client_id', $clientID);
            $builder->addFile('grant_type', 'client_credentials');
            $builder->addFile('scope', 'eats.order eats.store eats.store.orders.read eats.store.status.write');

            $response = $browser->submitForm(
                $uberEatsEndpoint,
                [
                    'client_secret' => $clientSecret,
                    'client_id' => $clientID,
                    'grant_type' => 'client_credentials',
                    'scope' => 'eats.order eats.store eats.store eats.store.orders.read eats.store.status.write',
                ]
            );

            if ($response->getStatusCode() !== 200) {
                Log::channel('UberTokenRefresh')->info($response->getStatusCode());
                Log::channel('UberTokenRefresh')->info($response->getBody()->__toString());
                throw new \Exception("No se pudo obtener la autorización de Uber Eats");
            }

            return $response;
        } catch (\Exception $e) {
            Log::channel('UberTokenRefresh')->info('Error al tratar de obtener el access token en uber eats');
        }
    }

    /**
     * Funcion that updates the registers of the Uber Auth token on the DB
     * @param storeId  the store_id field
     * @param response the Api call response
     *
     * @return void
     */
    public function updateTokenData($storeId, $response)
    {
        try {
            $bodyJSON = json_decode($response->getBody()->__toString(), true);

            $name_eats = AvailableMyposIntegration::NAME_EATS;
            $token = $bodyJSON['access_token'];
            $expires_in = $bodyJSON['expires_in'];
            $storeIntegrationToken = StoreIntegrationToken::where('integration_name', $name_eats)
                ->update(['token' => $token, 'expires_in' => $expires_in]);
        } catch (\Exception $e) {
            Log::channel('UberTokenRefresh')->info('Error al actualizar el token de Uber EN Base');
        }
    }
}