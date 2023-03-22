<?php

namespace App\Events;

use App\Order;
use Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class OrderDeleted implements ShouldBroadcast
{

    public $order;
    public $orderComanda;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Order $order)
    {
        $order->makeVisible(['store_id']);

        $this->order = json_encode($order);
        $this->orderComanda = $order->id;
    }

    public function broadcastOn()
    {
        $orderObj = json_decode($this->order);
        return new Channel('orderDeleted' . $orderObj->store_id);
    }

    public function broadcastWith()
    {
        $orderObj = json_decode($this->order);
        return ['order_updated' => $orderObj, 'order_deleted_id' => $this->orderComanda];
    }
}
