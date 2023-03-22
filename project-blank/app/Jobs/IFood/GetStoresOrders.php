<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Carbon\Carbon;
use Log;

// Models
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;

// Helpers
use App\Traits\iFood\IfoodRequests;

// Jobs
use App\Jobs\MenuMypos\EmptyJob;

class GetStoresOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $channelLog;
    public $channelSlackDev;
    public $baseUrl;
    public $browser;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        $client = new FileGetContents(new Psr17Factory());
        $this->browser = new Browser($client, new Psr17Factory());
        $this->channelLog = "ifood_orders_logs";
        $this->channelSlackDev = "#integration_logs_details";
        $this->baseUrl = config('app.ifood_url_api');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            $integrationIfood = StoreIntegrationToken::where(
                'integration_name',
                'like',
                AvailableMyposIntegration::NAME_IFOOD
            )
                ->first();

            if (is_null($integrationIfood)) {
                return;
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
                    return;
                } else {
                    $integrationIfood = StoreIntegrationToken::where(
                        'integration_name',
                        'like',
                        AvailableMyposIntegration::NAME_IFOOD
                    )
                        ->first();
                }
            }

            // Obteniendo las órdenes para todas las tiendas
            IfoodRequests::getOrders($integrationIfood->token);
        }
    }

    public function failed($exception)
    {
        Log::info("iFood GetStoresOrders falló");
        Log::info($exception);
    }
}
