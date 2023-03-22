<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;
use Carbon\Carbon;

// Models
use App\Spot;
use App\StoreIntegrationId;

// Helpers
use App\Traits\Integrations\IntegrationsHelper;
use App\Traits\iFood\IfoodMenu;
use App\Traits\iFood\IfoodRequests;

class CreateIfoodOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IfoodMenu, IntegrationsHelper;

    public $externalOrder;
    public $token;
    public $channel;
    public $integration;
    public $slack;
    public $baseUrl;
    public $browser;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $externalOrder,
        $token,
        $integration,
        $channel,
        $slack,
        $baseUrl,
        $browser
    ) {
        $this->externalOrder = $externalOrder;
        $this->token = $token;
        $this->channel = $channel;
        $this->integration = $integration;
        $this->slack = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            IfoodRequests::initVarsIfoodRequests(
                $this->channel,
                $this->slack,
                $this->baseUrl,
                $this->browser
            );
            $resultOrderInfo = IfoodRequests::getOrderInformation(
                $this->token,
                $this->externalOrder
            );
            if ($resultOrderInfo["status"] == 1) {
                $timezone = config('app.timezone');
                $data = $resultOrderInfo["data"];
                $data["created_at"] = Carbon::parse($data["created_at"])
                    ->setTimezone($timezone)
                    ->toDateTimeString();

                // Relacionando orden a la tienda correspondiente
                $storeIntegrationId = StoreIntegrationId::where(
                    'external_store_id',
                    $data["external_store_id"]
                )
                    ->where('integration_id', $this->integration->id)
                    ->with('store.configs')
                    ->first();
                if (is_null($storeIntegrationId)) {
                    return;
                }

                $dataConfig = IfoodRequests::checkIfoodConfiguration($storeIntegrationId->store);
                if ($dataConfig["code"] != 200) {
                    return;
                }

                $storeIntegrationId->store->load("hubs");
                $hub = null;
                if ($storeIntegrationId->store->hubs != null && $storeIntegrationId->store->hubs->first() != null) {
                    $hub = $storeIntegrationId->store->hubs->first();
                }
                
                $data["automatic"] = true;
                $resultCreateOrder = $this->createIntegrationOrder(
                    $data,
                    $this->integration,
                    $storeIntegrationId->store->id,
                    $storeIntegrationId->store->name,
                    $storeIntegrationId->store->configs,
                    $this->channel,
                    Spot::ORIGIN_IFOOD,
                    $hub
                );
                if ($resultCreateOrder["status"] == 1 || $resultCreateOrder["status"] == 2) {
                    // Enviando evento orden integrada y de aceptación a iFood
                    $resultIntegrateOrder = IfoodRequests::sendIntegrationEvent(
                        $this->token,
                        $storeIntegrationId->store->name,
                        $this->externalOrder
                    );
                    if ($resultIntegrateOrder["status"] == 1) {
                        $resultConfirmationOrder = IfoodRequests::sendConfirmationEvent(
                            $this->token,
                            $storeIntegrationId->store->name,
                            $this->externalOrder
                        );
                    }
                } else {
                    // Enviando evento de rechazo
                    $resultRejectionOrder = IfoodRequests::sendRejectionEvent(
                        $this->token,
                        $storeIntegrationId->store->name,
                        $this->externalOrder,
                        $resultCreateOrder["message"]
                    );
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("CreateIfoodOrderJob falló");
        Log::info($exception);
    }
}
