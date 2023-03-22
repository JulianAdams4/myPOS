<?php

namespace App\Jobs\Production;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\ProductionOrder;

// Helpers
use App\Traits\Logs\Logging;

// Events
use App\Events\Production\ProductionOrderNeededEvent;

class LaunchEventProductionNeeded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $storeId;
    public $productionOrders;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $storeId,
        $productionOrders
    ) {
        $this->storeId = $storeId;
        $this->productionOrders = $productionOrders;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            Logging::printLogFile(
                "LaunchEventProductionNeeded para el storeId: " . $this->storeId,
                "production_job_logs"
            );
            foreach ($this->productionOrders as $productionOrder) {
                Logging::printLogFile(
                    "Evento enviado para el production order: " . $productionOrder->id,
                    "production_job_logs"
                );
                event(new ProductionOrderNeededEvent($this->storeId, $productionOrder));
                $productionOrder = ProductionOrder::where('id', $productionOrder->id)->first();
                if (!is_null($productionOrder) && $productionOrder->event_launched == false) {
                    $productionOrder->event_launched = true;
                    $productionOrder->save();
                }
            }
            Logging::printLogFile(
                "------------------------------------------------------------------------------------------------",
                "production_job_logs"
            );
        }
    }

    public function failed($exception)
    {
        Log::info("LaunchEventProductionNeeded falló");
        Log::info($exception);
    }
}
