<?php

namespace App\Events;

use App\Order;
use App\Helper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class OrderUpdatedComanda implements ShouldBroadcast
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
    		'orderDetails.processStatus',
    		'billing',
            'spot'
    	])->find($order->id);
    	$this->order = json_decode($newOrder);

        $detailsForComanda = [];

        foreach ($newOrder->orderDetails as $ODetail) {
            $ODetail->append('spec_fields');
        }

        $groupedDetails = Helper::getDetailsUniqueGroupedByCompoundKey($newOrder->orderDetails);

        foreach($groupedDetails as $detail){
            $dispatchedDetail = false;
            $statuses = $detail['process_status'];
            $statuses = collect($statuses);
            if (count($statuses) > 0) {
                $notDispatched = $statuses->pluck('process_status')->all();
                if (in_array(4, $notDispatched)) {
                    $dispatchedDetail = true;
                }
            }

            $detail = [
                'id' => $detail['id'],
                'instructions' => $detail['spec_fields']['instructions'],
                'product' => $detail['spec_fields']['name'],
                'quantity' => $detail['quantity'],
                'dispatched' => $dispatchedDetail
            ];
            array_push($detailsForComanda, $detail);
        }

        $secondsFromCreated = Carbon::now()->diffInSeconds(new Carbon($newOrder->created_at));
        $milisecondsFromCreated = $secondsFromCreated * 1000;
        $orderForComanda = [
            'id' => $order->id,
            'identifier' => $order->identifier,
            'spot' => $newOrder->spot->name,
            'details' => $detailsForComanda,
            'created_at' => $milisecondsFromCreated
        ];

        $this->orderComanda = $orderForComanda;

        // $this->handle($newOrder);
    }

    public function handle(Order $order)
    {
    	Log::info("PRINTING ORDER updated");
        Log::info($order);
    }

    public function broadcastOn()
    {
    	return new Channel('orderUpdateComanda'.$this->order->store->id);
    }

    public function broadcastWhen()
    {
        $shouldBCEvent = false;
        if(!$this->order->store->order_app_sync){
            if($this->order->current_status === 'Creada'){
                $shouldBCEvent = true;
            }
        }else{
            if($this->order->current_status !== 'Creada'){
                $shouldBCEvent = true;
            }
        }

    	return $shouldBCEvent;
    }

    public function broadcastWith()
    {
        return ['order_updated' => $this->order, 'order_updated_comanda' => $this->orderComanda];
    }
}