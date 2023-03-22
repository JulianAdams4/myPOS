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

// Helpers
use App\Traits\iFood\IfoodRequests;

class UpdateIfoodGroupModifierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $modifierGroup;
    public $token;
    public $modifierGroupId;
    public $storeName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($token, $storeName, $modifierGroup, $modifierGroupId, $channel, $slack, $baseUrl, $browser)
    {
        $this->modifierGroup = $modifierGroup;
        $this->token = $token;
        $this->modifierGroupId = $modifierGroupId;
        $this->storeName = $storeName;

        IfoodRequests::initVarsIfoodRequests(
            $channel,
            $slack,
            $baseUrl,
            $browser
        );
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            IfoodRequests::updateModifierGroup(
                $this->token,
                $this->storeName,
                $this->modifierGroup,
                $this->modifierGroupId
            );
        }
    }

    public function failed(Exception $exception)
    {
        Log::info("UpdateIfoodGroupModifierJob falló");
        Log::info($exception);
    }
}
