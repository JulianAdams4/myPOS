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

// Events
use App\Events\Ifood\StatusUploadMenu;

class UploadIfoodCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $store;
    public $category;
    public $ifoodStoreId;
    public $storeName;
    public $sectionIntegration;
    public $channel;
    public $slack;
    public $baseUrl;
    public $browser;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($store, $ifoodStoreId, $storeName, $category, $channel, $slack, $baseUrl, $browser, $sectionIntegration = null)
    {
        $this->store = $store;
        $this->category = $category;
        $this->ifoodStoreId = $ifoodStoreId;
        $this->storeName = $storeName;
        $this->sectionIntegration = $sectionIntegration;
        $this->channel = $channel;
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
            $dataConfig = IfoodRequests::checkIfoodConfiguration($this->store);
            if ($dataConfig["code"] == 200) {
                $status = IfoodRequests::uploadCategory(
                    $dataConfig["data"]["integrationToken"]->token,
                    $this->ifoodStoreId,
                    $this->storeName,
                    $this->category
                );
                if ($status["status"] == 1 && !is_null($this->sectionIntegration)) {
                    $newCount = $this->sectionIntegration->status_sync["categories_current"] + 1;
                    $sectionInt = SectionIntegration::where('id', $this->sectionIntegration->id)->first();
                    if (!is_null($sectionInt)) {
                        $statusSync = $sectionInt->status_sync;
                        $statusSync['categories_current'] = $newCount;
                        $sectionInt->status_sync = $statusSync;
                        $sectionInt->save();
                        event(new StatusUploadMenu($sectionInt->toArray()));
                    }
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("UploadIfoodCategoryJob falló");
        Log::info($exception);
    }
}
