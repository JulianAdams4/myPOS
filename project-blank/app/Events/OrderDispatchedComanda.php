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

class OrderDispatchedComanda implements ShouldBroadcast
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
            'orderDetails.processStatus',
    		'billing',
            'spot',
            'invoice' => function ($invoice) {
                $invoice->select('id', 'invoice_number', 'order_id');
            }
    	])->find($order->id);
    	$this->order = json_decode($newOrder);

        $detailsForComanda = [];
        $numOrderDetailsDispatched = 0;
        $groupedDetails = Helper::getDetailsUniqueGroupedByCompoundKey($newOrder->orderDetails);
        foreach ($groupedDetails as $detail) {
            $dispatchedDetail = false;
            $statuses = collect($detail['process_status']);
            if (count($statuses) > 0) {
                $notDispatched = $statuses->pluck('process_status')->all();
                if (in_array(4, $notDispatched)) {
                    $dispatchedDetail = true;
                    $numOrderDetailsDispatched += $detail['quantity'];
                }
            }
            $detail = [
                'id' => $detail['id'],
                'instructions' => $detail['instruction'],
                'product' => $detail['product_detail']['product']['name'],
                'quantity' => $detail['quantity'],
                'dispatched' => $dispatchedDetail
            ];
            array_push($detailsForComanda, $detail);
        }
        $orderDispatched = false;
        if ($numOrderDetailsDispatched === count($newOrder->orderDetails)) {
            $orderDispatched = true;
        }
        $orderForComanda = [
            'id' => $order->id,
            'identifier' => $order->identifier,
            'spot' => $newOrder->spot->name,
            'details' => $detailsForComanda,
            'dispatched' => $orderDispatched,
            'invoice' => $newOrder->invoice
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

    public function broadcastWith()
    {
        return ['order_updated' => $this->order, 'order_updated_comanda' => $this->orderComanda];
    }
}