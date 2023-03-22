<?php

namespace App\Events;

use App\Hub;
use App\Invoice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class HubOrderDispatched implements ShouldBroadcast
{
    public $hub;
    public $invoice;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Hub $hub, Invoice $invoice)
    {
        $this->hub = $hub;
        $this->invoice = $invoice;
    }

    public function broadcastOn()
    {
        return new Channel('ordersFromHub'.$this->hub->id);
    }

    public function broadcastWhen()
    {
        return true;
    }

    public function broadcastWith()
    {
        return ['hub' => $this->hub, 'invoice' => $this->invoice];
    }
}