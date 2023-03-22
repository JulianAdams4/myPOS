<?php

namespace App\Events;

use App\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class IntegrationOrderCreated implements ShouldBroadcast
{

    public $order;
    public $orderComanda;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(Order $order)
    {
        $newOrder = Order::with(
            [
                'store',
                'orderDetails.processStatus',
                'billing',
                'spot',
                'orderDetails.orderSpecifications.specification.specificationCategory',
                'invoice' => function ($invoice) {
                    $invoice->select('id', 'invoice_number', 'order_id');
                }
            ]
        )->find($order->id);
        $this->order = json_decode($newOrder);

        $detailsForComanda = [];
        foreach ($newOrder->orderDetails as $detail) {
            $detail->append('spec_fields');
            $detail = [
                'id' => $detail->id,
                'instructions' => $detail->specFields['instructions'],
                'product' => $detail->specFields['name'],
                'quantity' => $detail->quantity,
                // 'dispatched' => true,
            ];
            array_push($detailsForComanda, $detail);
        }
        $orderForComanda = [
            'id' => $order->id,
            'identifier' => $order->identifier,
            'spot' => $newOrder->spot->name,
            'details' => $detailsForComanda,
            'invoice' => $newOrder->invoice
        ];
        $this->orderComanda = $orderForComanda;
    }

    public function handle(Order $order)
    {
    }

    public function broadcastOn()
    {
        return new Channel('integrationOrderCreated'.$this->order->store->id);
    }

    public function broadcastWhen()
    {
        $shouldBCEvent = false;
        return true;
    }

    public function broadcastWith()
    {
        return ['order' => $this->order, 'comanda' => $this->orderComanda];
    }
}