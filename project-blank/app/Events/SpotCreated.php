<?php

namespace App\Events;

use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class SpotCreated implements ShouldBroadcast
{
    public $spot;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($spot)
    {
        $this->spot = $spot;
    }

    public function broadcastOn()
    {
        return new Channel('spotCreated'.$this->spot['store_id']);
    }

    public function broadcastWhen()
    {
        return true;
    }

    public function broadcastWith()
    {
        return $this->spot;
    }
}
