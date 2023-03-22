<?php

namespace App\Events\Production;

use App\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class ProductionOrderNeededEvent implements ShouldBroadcast
{

    public $storeId;
    public $productionOrder;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($storeId, $productionOrder)
    {
        $this->storeId = $storeId;
        $this->productionOrder = $productionOrder;
    }

    public function broadcastOn()
    {
        return new Channel('productionOrderNeeded'.$this->storeId);
    }

    public function broadcastWhen()
    {
        return true;
    }

    public function broadcastWith()
    {
        return ['productionOrder' => $this->productionOrder];
    }
}