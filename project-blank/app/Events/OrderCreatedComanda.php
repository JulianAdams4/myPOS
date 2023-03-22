<?php

namespace App\Events;

use App\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class OrderCreatedComanda implements ShouldBroadcast
{

	public $order;
	public $orderComanda;

	use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Order $order)
    {
    	$newOrder = Order::with([
    		'store',
    		'orderDetails',
    		'billing'
    	])->find($order->id);
    	$this->order = json_decode($newOrder);

    	$orderForComanda = [
            'id' => $order->id,
            'identifier' => $order->identifier,
            'spot' => $newOrder->spot->name,
            'details' => []
        ];

        $this->orderComanda = $orderForComanda;
        // $this->handle($newOrder);
    }

    public function handle(Order $order)
    {
    	Log::info("PRINTING ORDER");
        Log::info($order);
    }

    public function broadcastOn()
    {
    	return new Channel('newOrderComanda'.$this->order->store->id);
    }

    public function broadcastWhen()
    {
    	$shouldBCEvent = false;
        if($this->order->store->order_app_sync){
            $shouldBCEvent = true;
        }
        
    	return $shouldBCEvent;
    }

    public function broadcastWith()
    {
        return ['new_order' => $this->order, 'new_order_comanda' => $this->orderComanda];
    }
}