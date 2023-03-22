<?php

namespace App\Events;

use Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MercadoPago implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
	
	public $connection = 'mercadopago';
	public $queue = 'mercadopago';    
    public $storeId;
    public $info;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($info, $storeId)
    {
        $this->info = $info;
        $this->storeId = $storeId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('MercadoPago'.$this->storeId);
    }

    public function broadcastWhen()
    {   
    	return true;
    }

    public function broadcastWith()
    {
        return $this->info;
    }
}
