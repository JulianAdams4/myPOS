<?php

namespace App\Events;

use App\Order;
use App\Helper;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class SimpleOrderFailed implements ShouldBroadcast
{

    public $storeId = null;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function handle($storeId)
    {
        // No hacer nada
    }

    public function broadcastOn()
    {
        if($this->storeId!="No Definido" && $this->storeId != null){
            return new Channel('newOrderIntegrationFailed'.$this->storeId);
        }
    }

    public function broadcastWhen()
    {
        $shouldBCEvent = false;
        if ($this->storeId != null && $this->storeId!="no definido") {
            $shouldBCEvent = true;
        }
        
        return $shouldBCEvent;
    }

    public function broadcastWith()
    {
        return ['data' => ''];
    }
}
