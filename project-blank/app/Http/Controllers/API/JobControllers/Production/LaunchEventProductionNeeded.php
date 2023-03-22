<?php

namespace App\Http\Controllers\API\JobControllers\Production;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Log;
use App\ProductionOrder;
use App\Traits\Logs\Logging;
use App\Http\Controllers\API\JobControllers\ProductionOrderNeededEvent;

class LaunchEventProductionNeeded extends Controller
{
    public $storeId;
    public $productionOrders;

    public function __construct($storeId, $productionOrders)
    {
        $this->storeId = $storeId;
        $this->productionOrders = $productionOrders;
    }

    public function launchEvent()
    {
        try {
            $this->printMessage("LaunchEventProductionNeeded para el storeId: ".$this->storeId);

            foreach ($this->productionOrders as $productionOrder) {
                // Send event
                event(new ProductionOrderNeededEvent($this->storeId, $productionOrder));
                $this->printMessage("Evento enviado para el production order: " . $productionOrder->id);

                // Update ProductionOrder
                $productionOrder = ProductionOrder::where('id', $productionOrder->id)->first();
                if ($productionOrder && $productionOrder->event_launched == false) {
                    $productionOrder->event_launched = true;
                    $productionOrder->save();
                }
            }

            $this->printMessage("------------------------------------------------------------------------------------------------");
        } catch (\Exception $e) {
            Log::info("LaunchEventProductionNeeded falló");
            Log::info($exception);
        }
    }

    public function printMessage(String $message)
    {
        Logging::printLogFile($message, "production_job_logs");
    }
}