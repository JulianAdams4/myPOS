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

class PreOrderUpdated implements ShouldBroadcast
{

	public $order;
	public $user_id;

	use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Order $order, $user_id)
    {    	
        $this->order = $order;
    	$this->user_id = $user_id;
    }

    public function handle(Order $order)
    {
    	//Log::info("Send to kitchen");
    }

    public function broadcastOn()
    {
    	return new Channel('updatePreOrder'.$this->order->store->id);
    }

    public function broadcastWith()
    {
        $this->order->load('orderDetails.processStatus', 'billing', 'spot');
        return ['order_sended' => json_decode($this->order), 'user_id'=> $this->user_id];
    }
}