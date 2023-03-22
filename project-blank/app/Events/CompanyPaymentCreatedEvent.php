<?php

namespace App\Events;

use App\Payment;
use App\Card;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CompanyPaymentCreatedEvent implements ShouldBroadcast
{

  public $payment;
  public $singlePayment;


  use Dispatchable;
  use InteractsWithSockets;
  use SerializesModels;

  public function __construct(Payment $payment)
  {
    $this->payment = $payment;
    $spot = $this->payment->order->spot;

    if ($spot != null && $spot->isFromIntegration()) {
      $singlePayment = [
        'type' => 'INTEGRATION',
        'id' => $spot->origin,
        'name' =>  $spot->name,
        'total' => $this->payment->order->payments->sum('total')
      ];
    } else {

      if ($payment->card_id) {

        $card = Card::find($payment->card_id);

        $singlePayment =  [
          'type' => 'CARD',
          'id' => $card->id,
          'name' => $card->name,
          'total' => $payment->sum('total')
        ];
      } else {
        $singlePayment =  [
          'type' => 'PAYMENT',
          'id' => $payment->type,
          'name' => $payment->typeName(),
          'total' => $payment->total
        ];
      }
    }

    $this->singlePayment = $singlePayment;
  }

  public function broadcastOn()
  {
    return new Channel('companyOrder' . $this->payment->order->store->company->id);
  }

  public function broadcastWith()
  {

    return [
      'payment' => $this->singlePayment,
      'store' => $this->payment->order->store
    ];
  }

  public function broadcastWhen()
  {
    return true;
  }
}
