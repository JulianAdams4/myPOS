<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class BalanceStatusUpdate implements ShouldBroadcast
{
	public $store;
	public $status;
	public $balance = null;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($data)
    {
		$this->store = $data['store'];
        $this->status = $data['status'];
        if(isset($data['balance'])){
            $this->balance = $data['balance'];
            unset($this->balance->store);
        }
    }

    public function broadcastOn()
    {
        return new Channel('balanceStatusUpdate'.$this->store['id']);
    }

    public function broadcastWhen()
    {
        return true;
    }

    public function broadcastWith()
    {
        return [
			'id_store' => $this->store['id'],
			'isOpened' => $this->status === 'opened',
			'balance' => $this->balance,
		];
    }
}
