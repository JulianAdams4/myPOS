<?php

namespace App\Http\Controllers;

use App\Subscription;
use App\SubscriptionInvoices;
use App\SubscriptionInvoiceDetails;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use App\Traits\LocalImageHelper;
use App\Traits\AWSHelper;

class StripeHookController extends Controller
{
  use AuthTrait, LoggingHelper, LocalImageHelper, AWSHelper;

  public $authUser;
  public $authEmployee;
  public $authStore;

  public function __construct()
  {
    $this->middleware('api');
    [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
      return response()->json([
        'status' => 'Usuario no autorizado',
      ], 401);
    }
  }

  public function hooks(Request $request)
  {
    @$hookType = $request->type;
    switch ($hookType) {
      case 'invoice.payment_succeeded':
        return $this->paymentSucceeded($request);
        break;
    }
  }

  public function paymentSucceeded($request)
  {
    @$subscription = $request->data['object']['subscription'];

    if ($subscription) {
      return $this->registerSubscriptionPayment($subscription);
    }

    return response()->json([
      'status' => "No existe ruta para esta acción",
    ], 404);
  }

  public function registerSubscriptionPayment($subscriptionStripeId)
  {
    $subscription = Subscription::where('stripe_id', $subscriptionStripeId)->first();

    $subscriptionInvoiceDetail = SubscriptionInvoiceDetails::where('store_id', $subscription->store_id)
      ->where('subscription_plan_id', $subscription->subscription_plan_id)
      ->first();

    if (!$subscriptionInvoiceDetail) {
      return response()->json([
        'status' => "No existe la subscripción",
      ], 404);
    }

    $subscriptionInvoiceDetail->status = SubscriptionInvoiceDetails::PAID;
    $subscriptionInvoiceDetail->save(); 

    $invoice = SubscriptionInvoices::whereHas(
      'subscriptionInvoiceDetails',
      function ($query) use ($subscription) {
        return $query->where('store_id', $subscription->store_id)
          ->where('subscription_plan_id', $subscription->subscription_plan_id);
      }
    )
      ->orderBy('created_at', 'DESC')
      ->first();

    $isPaid = $invoice->areAllDetailsPaid();

    if ($isPaid) {
      $invoice->status = SubscriptionInvoices::PAID;
      $invoice->save();
    }

    return response()->json([
      'status' => "Pago de suscripción registrado",
    ], 200);
  }
}
