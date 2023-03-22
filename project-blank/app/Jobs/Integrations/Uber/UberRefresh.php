<?php

namespace App\Jobs\Integrations\Uber;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;

use Log;
use Carbon\Carbon;
use Buzz\Browser;
use Illuminate\Http\Request;
use Buzz\Client\FileGetContents;
use Buzz\Message\FormRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

class UberRefresh implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $integration_stores = StoreIntegrationToken::where('integration_name', AvailableMyposIntegration::NAME_EATS)
            ->where('type', 'delivery')
            ->get();

        if (!$integration_stores) {
            return;
        }

        foreach ($integration_stores as $store) {
            if ($this->checkRemainingDaysUber($store)) {
                $response = $this->refreshUberToken();

                if ($response->getStatusCode() !== 200) {
                    Log::channel('UberTokenRefresh')->info($response->getStatusCode());
                    Log::channel('UberTokenRefresh')->info($response->getBody()->__toString());
                    Log::channel('UberTokenRefresh')->info('No se pudo actualizar el token de Uber de tienda con id'.$store->store_id);
                } else {
                    $this->updateTokenData($store->store_id, $response);
                    Log::channel('UberTokenRefresh')->info($response->getStatusCode());
                    Log::channel('UberTokenRefresh')->info($response->getBody()->__toString());
                    Log::channel('UberTokenRefresh')->info('Se actualizo el token de Uber de tienda con id'.$store->store_id);
                    return;
                }
            }
        }
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
     * Funcion que transforma el tiempo en segundos a dias
     *
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

    /**
     * Funcion that updates the registers of the Uber Auth token on the DB
     *
     * @storeId the store_id field
     *
     * @response the Api call response
     *
     * @return void
     *
     */
    public function updateTokenData($storeId, $response)
    {
        try {
            $bodyJSON = json_decode($response->getBody()->__toString(), true);

            $storeIntegrationToken = StoreIntegrationToken::where(
                'integration_name',
                AvailableMyposIntegration::NAME_EATS
            )
                                                            ->update(['token' => $bodyJSON['access_token'],
                                                                      'expires_in' => $bodyJSON['expires_in']]);
        } catch (\Exception $e) {
            Log::channel('UberTokenRefresh')->info('Error al actualizar el token de Uber EN Base');
        }
    }

}
