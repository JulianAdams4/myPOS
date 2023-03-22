<?php

namespace App\Events;

use App\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use App\Helper;

class OrderSendedToKitchen implements ShouldBroadcast
{

	public $order;

	use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Order $order)
    {
    	$newOrder = Order::with([
    		'store',
            'orderDetails',
            'orderDetails.processStatus',
    		'billing',
            'spot'
        ])->find($order->id);
    	$this->order = json_decode($newOrder);
    }

    public function handle(Order $order)
    {
    	Log::info("Send to kitchen");
    }

    public function broadcastOn()
    {
    	return new Channel('sendToKitchenOrder'.$this->order->store->id);
    }

    public function broadcastWith()
    {
        return ['order_sended' => $this->order];
    }
}