<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\Store;
use App\SectionIntegration;

// Helpers
use App\Traits\iFood\IfoodRequests;

class LinkIfoodModifierGroupToItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $store;
    public $ifoodStoreId;
    public $storeName;
    public $productId;
    public $data;
    public $channel;
    public $slack;
    public $baseUrl;
    public $browser;
    public $sectionIntegration;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $store,
        $ifoodStoreId,
        $storeName,
        $data,
        $productId,
        $channel,
        $slack,
        $baseUrl,
        $browser,
        $sectionIntegration
    ) {
        $this->store = $store;
        $this->ifoodStoreId = $ifoodStoreId;
        $this->storeName = $storeName;
        $this->data = $data;
        $this->productId = $productId;
        $this->channel = $channel;
        $this->slack = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;
        $this->sectionIntegration = $sectionIntegration;
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
            $dataConfig = IfoodRequests::checkIfoodConfiguration($this->store);
            if ($dataConfig["code"] == 200) {
                $status = IfoodRequests::linkModifierGroupToItem(
                    $dataConfig["data"]["integrationToken"]->token,
                    $this->ifoodStoreId,
                    $this->storeName,
                    $this->productId,
                    $this->data
                );
                if ($status["status"] == 1) {
                    $newCount = $this->sectionIntegration->status_sync["links_modifiers_products_current"] + 1;
                    $sectionInt = SectionIntegration::where('id', $this->sectionIntegration->id)->first();
                    if (!is_null($sectionInt)) {
                        $statusSync = $sectionInt->status_sync;
                        $statusSync['links_modifiers_products_current'] = $newCount;
                        $sectionInt->status_sync = $statusSync;
                        $sectionInt->save();
                    }
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("LinkIfoodModifierGroupToItemJob falló");
        Log::info($exception);
    }
}
