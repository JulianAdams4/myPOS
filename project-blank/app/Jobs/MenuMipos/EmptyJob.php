<?php

namespace App\Jobs\MenuMypos;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\Store;
use App\StoreIntegrationId;
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;
use App\ProductExternalId;
use App\ProductCategoryExternalId;
use App\SpecificationExternalId;
use App\SpecificationCategoryExternalId;

class EmptyJob implements ShouldQueue
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
        //
    }

    public function failed($exception)
    {
        Log::info("EmptyJob falló");
        Log::info($exception);
    }
}
