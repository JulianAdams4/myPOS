<?php

namespace App\Http\Controllers\API\JobControllers\Production;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\ProductionOrder;
use App\Jobs\Production\LaunchEventProductionNeeded;
use App\Jobs\MenuMypos\EmptyJob;

class SearchStoresProductionNeeded extends Controller
{
    public function __construct()
    {
        // ...
    }

    public function searchNeeded(Request $request)
    {
        try {
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
            ->where('event_launched', false);

            if (config('app.env') == 'production') { // ***
                $productionOrders = $productionOrders->whereNotNull('store_id');
            } else {
                $productionOrders = $productionOrders->where('store_id', 3);
            }

            $productionOrders = $productionOrders->whereHas('statuses', function ($statuses) {
                $statuses->where('status', 'finished')->orWhere('status', 'cancelled');
            })
            ->with('component.componentStocks')
            ->orderBy('id', 'ASC')
            ->get();

            $launchEventJob = [];
            $uniqueStores = $productionOrders->groupBy('store_id');
            foreach ($uniqueStores as $storeId => $storeProductionOrders) {
                $alertProductionOrders = [];
                foreach ($storeProductionOrders as $productionOrder) {
                    $lastStock = $productionOrder->component->componentStocks->sortByDesc('id')->first();
                    if (($productionOrder->total_produced - $productionOrder->consumed_stock) < $lastStock->alert_stock) {
                        array_push($alertProductionOrders, $productionOrder);
                    }
                }
                if (count($alertProductionOrders) > 0) {
                    array_push(
                        $launchEventJob,
                        (new LaunchEventProductionNeeded($storeId, $alertProductionOrders))
                    );
                }
            }

            if (count($launchEventJob) > 0) {
                EmptyJob::withChain($launchEventJob)->dispatch();
            }

            return response()->json(['status' => "OK"], 200);
        } catch (\Exception $e) {
            $msg = "Falló al buscar las ordenes de produccion";
            Log::error($msg.": ".$e);
            return response()->json(['status' => $msg], 500);
        }
    }
}