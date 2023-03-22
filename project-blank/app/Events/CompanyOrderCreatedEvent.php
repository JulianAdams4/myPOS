<?php

namespace App\Events;

use App\Order;
use Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CompanyOrderCreatedEvent implements ShouldBroadcast
{

  public $order;
  public $company;
  public $type_count;

  use Dispatchable;
  use InteractsWithSockets;
  use SerializesModels;

  public function __construct(Order $order)
  {
    $this->order = $order;
    $this->company = $order->store->company;
    $this->type_count = $order->orderDetails
      ->groupBy(function ($order_detail) {
        $value = $order_detail->productDetail->product->type_product;
        if ($value == "null") {
          $value = 'food';
        }
        return $value;
      })
      ->map(function ($value) {
        return sizeOf($value);
      });
  }

  public function broadcastOn()
  {
    return new Channel('companyOrder' . $this->order->store->company_id);
  }

  public function broadcastWith()
  {
    return [
      'order' => $this->order,
      'company' => $this->company,
      'type_count' => $this->type_count
    ];
  }

  public function broadcastWhen()
  {
    $shouldBCEvent = false;
    if ($this->order->preorder != 1) {
      $shouldBCEvent = true;
    }

    return $shouldBCEvent;
  }
}
