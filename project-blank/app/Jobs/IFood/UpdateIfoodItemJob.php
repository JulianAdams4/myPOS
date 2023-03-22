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

class UpdateIfoodItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $product;
    public $token;
    public $ifoodStoreId;
    public $storeName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $token,
        $ifoodStoreId,
        $storeName,
        $product,
        $channel,
        $slack,
        $baseUrl,
        $browser
    ) {
        $this->product = $product;
        $this->token = $token;
        $this->ifoodStoreId = $ifoodStoreId;
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
            IfoodRequests::updateItem(
                $this->token,
                $this->ifoodStoreId,
                $this->storeName,
                $this->product
            );
        }
    }

    public function failed(Exception $exception)
    {
        Log::info("UpdateIfoodItemJob falló");
        Log::info($exception);
    }
}
