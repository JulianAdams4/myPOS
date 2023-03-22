<?php

namespace App\Http\Controllers;

use App\Store;
use App\StripeCustomerCompany;
use App\Subscription;
use App\SubscriptionDiscount;
use App\SubscriptionInvoiceDetails;
use App\SubscriptionInvoices;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\Logs\Logging;
use App\Traits\LocalImageHelper;
use App\Traits\AWSHelper;

class StripeCompanyController extends Controller
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

  public function switchAutoBilling(Request $request)
  {
    $store = $this->authStore;

    $companyId = $store->company_id;

    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();
    $stripeCompany->is_autobilling_active = !$stripeCompany->is_autobilling_active;
    $stripeCompany->save();

    if (!!$stripeCompany->is_autobilling_active) {
      $this->registerCustomerSubscriptionsByCompany($companyId);
    } else {
      $this->removeCustomerSubscriptionsByCompany($companyId);
    }

    return response()->json([
      'status' => "Stripe autobilling actualizado",
      'stripe_company' => $stripeCompany
    ], 200);
  }

  public function getInfo()
  {
    $store = $this->authStore;
    $companyId = $store->company_id;

    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

    $customer = \Stripe\Customer::retrieve($stripeCompany->stripe_customer_id);

    $cards = \Stripe\Customer::allSources(
      $stripeCompany->stripe_customer_id,
      ['object' => 'card']
    );

    $customer->cards = $cards->data;
    $stripeCompany->is_autobilling_active = !!$stripeCompany->is_autobilling_active;

    return response()->json([
      'status' => "Información de stripe obtenida",
      'stripe_data' => $customer,
      'stripe_company' => $stripeCompany
    ], 200);
  }

  public function createCard(Request $request)
  {

    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "token" => "required|string"
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "Los datos enviados contienen errores como tipos de datos incorrectos o campos obligatorios vacíos",
        'results' => null
      ], 409);
    }

    $companyId = $store->company_id;
    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

    $card = \Stripe\Customer::createSource(
      $stripeCompany->stripe_customer_id,
      [
        'source' => $data['token']
      ]
    );

    return response()->json([
      'status' => "Tarjeta stripe creada",
      'results' => $card
    ], 200);
  }

  public function removeCard(Request $request)
  {
    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "id" => "required|string"
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "Los datos enviados contienen errores como tipos de datos incorrectos o campos obligatorios vacíos",
        'results' => null
      ], 409);
    }

    $companyId = $store->company_id;

    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

    $card = \Stripe\Customer::deleteSource(
      $stripeCompany->stripe_customer_id,
      $data['id']
    );

    return response()->json([
      'status' => "Tarjeta stripe eliminada",
      'results' => $card
    ], 200);
  }

  public function setDefaultCard(Request $request)
  {
    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "id" => "required|string"
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "Los datos enviados contienen errores como tipos de datos incorrectos o campos obligatorios vacíos",
        'results' => null
      ], 409);
    }

    $companyId = $store->company_id;

    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

    $card = \Stripe\Customer::update(
      $stripeCompany->stripe_customer_id,
      ['default_source' => $data['id']]
    );

    return response()->json([
      'status' => "Tarjeta stripe creada",
      'results' => $card
    ], 200);
  }

  public function registerInvoicePayment(Request $request)
  {
    @$id = $request->id;

    $invoice = SubscriptionInvoices::find($id);

    if (!$invoice) {
      return response()->json([
        'status' => "Factura no existente"
      ], 404);
    }

    if ($invoice->status == SubscriptionInvoices::PAID) {
      return response()->json([
        'status' => "La factura ya ha sido pagada"
      ], 404);
    }

    @$stripeCustomerId = $invoice->company->stripeCustomerCompany->stripe_customer_id;

    try {
      $componentJSON = DB::transaction(
        function () use ($request, $invoice, $stripeCustomerId) {

          $stripePaymentIntent = \Stripe\PaymentIntent::create([
            'amount' => (int) $invoice->total,
            'customer' => $stripeCustomerId,
            'currency' => 'mxn',
            'confirm' => true
          ]);

          $invoice->status = SubscriptionInvoices::PAID;
          $invoice->save();

          SubscriptionInvoiceDetails::where('subs_invoice_id', $invoice->id)
            ->update(['status' => SubscriptionInvoiceDetails::PAID]);

          return response()->json([
            'status' => "Pago de factura realizado"
          ], 200);
        }
      );

      return $componentJSON;
    } catch (\Exception $e) {
      Logging::logError(
        "StripeCompanyController API Store: ERROR CREATE, invoice: " . $invoice->id,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        json_encode($request)
      );

      return response()->json([
        'status' => 'No se pudo procesar el pago de la factura: ' .  $e->getMessage(),
        'results' => null,
      ], 409);
    }
  }

  /**
   * 
   * Create Subscriptions when automatic payment is activated
   * 
   * @param integer $companyId
   * 
   */

  public function registerCustomerSubscriptionsByCompany($companyId)
  {
    $stores = Store::where('company_id', $companyId)
      ->whereHas('city.country', function ($country) {
        return $country->where('is_stripe_active', true);
      })
      ->pluck('id')->toArray();

    $subscriptions = Subscription::whereIn('store_id', $stores)->get();

    foreach ($subscriptions as $subscription) {
      $this->syncSubscription($subscription);
    }
  }

  /**
   * 
   * Removes Subscriptions when automatic payment is disabled
   * 
   * @param integer $companyId
   * 
   */

  public function removeCustomerSubscriptionsByCompany($companyId)
  {
    $stores = Store::where('company_id', $companyId)
      ->whereHas('city.country', function ($country) {
        return $country->where('is_stripe_active', true);
      })
      ->pluck('id')->toArray();

    $subscriptions = Subscription::whereIn('store_id', $stores)->get();

    foreach ($subscriptions as $subscription) {
      $this->removeSubscription($subscription);
    }
  }

  /**
   * 
   * Syncs Subscription to stripe 
   * 
   * @param Subscription $subscription orm subscription object to sync
   */

  public static function syncSubscription($subscription)
  {
    // If isn't stripe active or there's not activation date then return false
    if (!$subscription->store->city->country->is_stripe_active || !$subscription->activation_date) return false;

    $activationDate = Carbon::parse($subscription->activation_date);
    $now = Carbon::now()->startOfDay();
    $isActivationAfterToday = $activationDate > $now;

    if ($isActivationAfterToday) {
      $stripeActivationDate = $activationDate;
    } else {
      $monthsToAdd = Carbon::parse($activationDate)->diffInMonths($now) + 1;
      $stripeActivationDate = Carbon::parse($activationDate)->addMonthsNoOverflow($monthsToAdd);;
    }

    $stripePlan = $subscription->subscriptionPlan->stripe_id;
    $stripeCoupon = SubscriptionDiscount::where('store_id', $subscription->store_id)->first();

    if ($stripeCoupon) {
      $couponExpiresAt = Carbon::parse($stripeCoupon->expires_at);
      $now = Carbon::now();

      if ($now > $couponExpiresAt) $stripeCoupon = null;
    }

    if ($subscription->stripe_id) {
      $stripeCanceledSubscription = \Stripe\Subscription::retrieve(
        $subscription->stripe_id
      );
      $stripeCanceledSubscription->delete();
    }

    $stripeTaxes = \Stripe\TaxRate::all();
    $taxes = collect($stripeTaxes['data']);
    $ivaTax = $taxes->where('display_name', 'IVA')->first();
    @$applyTaxes = $subscription->subscriptionPlan->subscriptionProduct->apply_taxes;
    $subscriptionTaxes = $applyTaxes ? [$ivaTax->id] : [];

    $stripeSubscription  = \Stripe\Subscription::create([
      'customer' => $subscription->store->company->stripeCustomerCompany->stripe_customer_id,
      'items' => [
        ['plan' => $stripePlan]
      ],
      'billing_cycle_anchor' => $stripeActivationDate->timestamp,
      'proration_behavior' => 'none',
      'coupon' => isset($stripeCoupon) ? $stripeCoupon->stripe_id :  null,
      'metadata' => [
        'id' => $subscription->id,
        'store' => $subscription->store->name
      ],
      'default_tax_rates' => $subscriptionTaxes
    ]);

    $subscription->stripe_id = $stripeSubscription->id;
    $subscription->save();

    return true;
  }

  /**
   * 
   * Remove a stripe subscription and clean the subscription in myPOS
   * 
   * @param Subscription $subscription orm subscription object to delete
   */

  public static function removeSubscription($subscription)
  {
    if (!$subscription->stripe_id) return false;

    $stripeSubscription  = \Stripe\Subscription::retrieve(
      $subscription->stripe_id
    );

    $stripeSubscription->delete();

    $subscription->stripe_id = null;
    $subscription->save();

    return true;
  }

  /**
   * 
   * Syncs SubscriptionDiscount to stripe as Coupon, Coupon are always deleted and recreated if already
   * exists one, becauso Coupons can't be updated on stripe
   * 
   * @param SubscriptionDiscount $subscriptionDiscount orm subscription discount object to sync
   */

  public static function syncCouponCreated($subscriptionDiscount)
  {
    if (!$subscriptionDiscount->store->city->country->is_stripe_active) return false;

    if ($subscriptionDiscount->stripe_id) {
      $coupon = \Stripe\Coupon::retrieve(
        $subscriptionDiscount->stripe_id
      );
      $coupon->delete();
    }

    $now = Carbon::now();
    $expiresAt = $subscriptionDiscount->expires_at;
    $months = Carbon::parse($expiresAt)->diffInMonths($now) + 1;

    $coupon = \Stripe\Coupon::create([
      'name' => $subscriptionDiscount->store->name . ' ' . ($subscriptionDiscount->discount / 100) . '%',
      'percent_off' => $subscriptionDiscount->discount / 100,
      'duration' => 'repeating',
      'duration_in_months' => $months,
      'redeem_by' => $expiresAt->timestamp
    ]);

    $subscriptionDiscount->stripe_id = $coupon->id;
    $subscriptionDiscount->save();

    $subscription = Subscription::whereHas('subscriptionPlan', function ($subscriptionPlan) use ($subscriptionDiscount) {
      return $subscriptionPlan->where('subscription_product_id', $subscriptionDiscount->subscription_product_id);
    })->first();

    if ($subscription) {
      \Stripe\Subscription::update(
        $subscription->stripe_id,
        ['coupon' => $coupon->id]
      );
    }
  }
}
