<?php

namespace App\Events;

use App\Order;
use App\Helper;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class OrderCreated implements ShouldBroadcast
{

    public $order = null;
    public $orderComanda;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct($orderId)
    {

        $order = Order::select(
            'id',
            'identifier',
            'total',
            'order_value',
            'cash',
            'spot_id',
            'created_at',
            'store_id'
        )->with(
            [
                'orderDetails' => function ($orderDetails) {
                    $orderDetails->select(
                        'id',
                        'order_id',
                        'product_detail_id',
                        'value',
                        'quantity',
                        'total',
                        'base_value',
                        'name_product',
                        'instruction',
                        'invoice_name',
                        'change_reason'
                    );
                },
                'invoice' => function ($invoice) {
                    $invoice->select(
                        'id',
                        'billing_id',
                        'order_id',
                        'total',
                        'document',
                        'name',
                        'address',
                        'phone',
                        'email',
                        'subtotal',
                        'tax',
                        'created_at',
                        'discount_percentage',
                        'discount_value',
                        'undiscounted_subtotal',
                        'tip'
                    )->with('order.orderIntegrationDetail', 'billing', 'taxDetails');
                },
                'orderIntegrationDetail' => function ($orderIntegration) {
                    $orderIntegration->select(
                        'id',
                        'order_id',
                        'customer_name',
                        'integration_name',
                        'order_number'
                    );
                },
                'spot'
            ]
        )
        ->where('id', $orderId)
        ->where('status', 1)
        ->where('preorder', 0)
        ->first();

        if ($order) {
            // Agregando detalles de la orden formateado
            foreach ($order->orderDetails as $detail) {
                $detail->append('spec_fields');
            }
            $invoice = collect($order["invoice"]);
            if ($order->invoice) {
                $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($order->invoice->items);
                $invoice->forget('items');
                $invoice->put('items', $detailsGrouped);
            }
            $order = collect($order);
            $order->forget('invoice');
            $order->put('invoice', $invoice);

            // Agregando especificaciones dentro del campo instrucciones dependiendo de los tipos de especificaciones
            $newOrderDetail = collect($order['order_details']);
            $orderDetailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($newOrderDetail);
            $cleanDetails = collect([]);
            foreach ($orderDetailsGrouped as $orderDetailGrouped) {
                unset($orderDetailGrouped["order"]);
                unset($orderDetailGrouped["order_specifications"]);
                unset($orderDetailGrouped["product_detail"]);
                unset($orderDetailGrouped["tax_values"]);
                $cleanDetails->push($orderDetailGrouped);
            }
            $order['order_details'] = $cleanDetails;
            $this->order = $order;
        }
    }

    public function handle($orderId)
    {
        // No hacer nada
    }

    public function broadcastOn()
    {
        return new Channel('newIncomingOrder'.$this->order["store_id"]);
    }

    public function broadcastWhen()
    {
        $shouldBCEvent = false;
        if ($this->order != null) {
            $shouldBCEvent = true;
        }
        
        return $shouldBCEvent;
    }

    public function broadcastWith()
    {
        return ['order' => json_encode($this->order)];
    }
}
