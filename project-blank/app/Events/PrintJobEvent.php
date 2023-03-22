<?php

namespace App\Events;

use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class PrintJobEvent implements ShouldBroadcast
{
    public $job;

	use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $connection = 'printer';
    public $queue = 'printer';

    public function __construct($job)
    {
        $this->job = $job;
    }

    public function broadcastOn()
    {
    	return new Channel('newPrintJob'.$this->job["store_id"]);
    }

    public function broadcastWhen()
    {   
    	return true;
    }

    public function broadcastWith()
    {
        return $this->job;
    }
}