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

// Jobs
use App\Jobs\Production\LaunchEventProductionNeeded;
use App\Jobs\MenuMypos\EmptyJob;

class SearchStoresProductionNeeded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            $productionOrders = ProductionOrder::select(
                'id',
                'component_id',
                'code',
                'original_content',
                'quantity_produce',
                'consumed_stock',
                'total_produced',
                'ullage',
                'cost',
                'observations',
                'created_at',
                'store_id'
            )
            ->where('event_launched', false)
            ->whereHas(
                'statuses',
                function ($statuses) {
                    $statuses->where('status', 'finished')
                        ->orWhere('status', 'cancelled');
                }
            )
            ->with('component.componentStocks')
            ->orderBy('id', 'ASC')
            ->get();

            $uniqueStores = $productionOrders->groupBy('store_id');
            $launchEventJob = [];
            foreach ($uniqueStores as $storeId => $storeProductionOrders) {
                $alertProductionOrders = [];
                foreach ($storeProductionOrders as $productionOrder) {
                    $lastStock = $productionOrder->component->componentStocks->sortByDesc('id')->first();
                    if (($productionOrder->total_produced - $productionOrder->consumed_stock)
                        < $lastStock->alert_stock) {
                            array_push($alertProductionOrders, $productionOrder);
                    }
                }
                if (count($alertProductionOrders) > 0) {
                    array_push(
                        $launchEventJob,
                        (new LaunchEventProductionNeeded(
                            $storeId,
                            $alertProductionOrders
                        ))
                    );
                }
            }
            EmptyJob::withChain($launchEventJob)->dispatch();
        }
    }

    public function failed($exception)
    {
        Log::info("SearchStoresProductionNeeded falló");
        Log::info($exception);
    }
}
