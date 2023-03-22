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

class UploadIfoodGroupModifierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $store;
    public $modifierGroup;
    public $ifoodStoreId;
    public $storeName;
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
    public function __construct($store, $ifoodStoreId, $storeName, $modifierGroup, $channel, $slack, $baseUrl, $browser, $sectionIntegration = null)
    {
        $this->store = $store;
        $this->modifierGroup = $modifierGroup;
        $this->ifoodStoreId = $ifoodStoreId;
        $this->storeName = $storeName;
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
                $status = IfoodRequests::uploadModifierGroup(
                    $dataConfig["data"]["integrationToken"]->token,
                    $this->ifoodStoreId,
                    $this->storeName,
                    $this->modifierGroup
                );
                if ($status["status"] == 1 && !is_null($this->sectionIntegration)) {
                    $newCount = $this->sectionIntegration->status_sync["modifier_groups_current"] + 1;
                    $sectionInt = SectionIntegration::where('id', $this->sectionIntegration->id)->first();
                    if (!is_null($sectionInt)) {
                        $statusSync = $sectionInt->status_sync;
                        $statusSync['modifier_groups_current'] = $newCount;
                        $sectionInt->status_sync = $statusSync;
                        $sectionInt->save();
                    }
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("UploadIfoodGroupModifierJob falló");
        Log::info($exception);
    }
}
