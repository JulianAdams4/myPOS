<?php

namespace App\Http\Controllers\API\JobControllers\iFood;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Log;
use Buzz\Browser;
use Carbon\Carbon;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
// Models
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;
// Helpers
use App\Traits\iFood\IfoodRequests;
// Jobs
use App\Jobs\MenuMypos\EmptyJob;

class GetStoresOrders extends Controller
{
    public $channelLog;
    public $channelSlackDev;
    public $baseUrl;
    public $browser;

    public function __construct()
    {
        $client = new FileGetContents(new Psr17Factory());
        $this->browser = new Browser($client, new Psr17Factory());
        $this->channelLog = "ifood_orders_logs";
        $this->channelSlackDev = "#integration_logs_details";
        $this->baseUrl = config('app.ifood_url_api');
    }

    /**
     * Funcion principal
     * @return void
     */
    public function getOrders()
    {
        try {
            $nameIfood = AvailableMyposIntegration::NAME_IFOOD;
            $integrationIfood = StoreIntegrationToken::where('integration_name', 'like', $nameIfood);
            if (config('app.env') == 'production') { // ***
                $integrationIfood = $integrationIfood->whereNotNull('store_id');
            } else {
                $integrationIfood = $integrationIfood->where('store_id', 3);
            }
            $integrationIfood = $integrationIfood->first();

            if (!$integrationIfood) {
                return response()->json(['status' => 'No existe la integracion'], 404);
            }

            $getOrdersJobs = [];
            IfoodRequests::initVarsIfoodRequests(
                $this->channelLog,
                $this->channelSlackDev,
                $this->baseUrl,
                $this->browser
            );

            // Verificar si el token ha caducado
            $now = Carbon::now();
            $emitted = Carbon::parse($integrationIfood->updated_at);
            $diff = $now->diffInSeconds($emitted);

            // El token sólo dura 1 hora(3600 segundos)
            if ($diff > $integrationIfood->expires_in) {
                $resultToken = IfoodRequests::getToken();
                if ($resultToken["status"] != 1) {
                    return response()->json(['status' => 'Fallo al actualizar el token'], 409);
                } else {
                    $integrationIfood = StoreIntegrationToken::where('integration_name', 'like', $nameIfood)->first();
                }
            }

            // Obteniendo las órdenes para todas las tiendas
            IfoodRequests::getOrders($integrationIfood->token);

            return response()->json(['status' => 'OK'], 200);
        } catch (\Exception $e) {
            $msg = "Fallo al obtener las ordenes de iFood";
            Log::error($msg);
            Log::error($e);
            return response()->json(['status' => $msg], 500);
        }
    }
}
