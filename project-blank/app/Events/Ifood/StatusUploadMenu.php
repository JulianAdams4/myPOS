<?php

namespace App\Events\Ifood;

use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class StatusUploadMenu implements ShouldBroadcast
{
    public $sectionIntegration;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($sectionIntegration)
    {
        $this->sectionIntegration = $sectionIntegration;
    }

    public function broadcastOn()
    {
        return new Channel('statusUploadIfood' . $this->sectionIntegration["section_id"]);
    }

    public function broadcastWhen()
    {
        return true;
    }

    public function broadcastWith()
    {
        return $this->sectionIntegration["status_sync"];
    }
}
