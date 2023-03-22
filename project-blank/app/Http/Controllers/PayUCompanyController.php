<?php


namespace App\Http\Controllers;

use App\Store;
use App\Subscription;
use App\SubscriptionDiscount;
use App\SubscriptionPlan;
use App\Traits\TimezoneHelper;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\LocalImageHelper;
use App\Traits\AWSHelper;
use Exception;
use Log;

class PayUCompanyController extends Controller
{
  use AuthTrait, LoggingHelper, LocalImageHelper, AWSHelper;

  public $authUser;

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
  }

  public function getInfo()
  {
    $store = $this->authStore;
    $companyId = $store->company_id;

    Log::info(__DIR__ . '/../../lib/PayU.php');

    PayU::$apiKey = "xxxxxxxxxxxx";
    Log::info(PayU);
  }

  public function createCard(Request $request)
  {

    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "name" => "required|string",
      "number" => "required|string|size:16",
      "exp_month" => "required|integer",
      "exp_year" => "required|integer",
      "cvc" => "required|integer"
    ]);
  }

  public function removeCard(Request $request)
  {
    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "id" => "required|string"
    ]);
  }

  public function setDefaultCard(Request $request)
  {
    $store = $this->authStore;
    $data = $request->all();

    $validator = Validator::make($data, [
      "id" => "required|string"
    ]);
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
        return $country->where('is_payu_active', true);
      })
      ->pluck('id')->toArray();

    $subscriptions = Subscription::whereIn('store_id', $stores)->get();
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
        return $country->where('is_payu_active', true);
      })
      ->pluck('id')->toArray();

    $subscriptions = Subscription::whereIn('store_id', $stores)->get();
  }

  /**
   * 
   * Syncs Subscription to payu 
   * 
   * @param Subscription $subscription orm subscription object to sync
   */

  public static function syncSubscription($subscription)
  {
  }

  /**
   * 
   * Remove a payu subscription and clean the subscription in mypos
   * 
   * @param Subscription $subscription orm subscription object to delete
   */

  public static function removeSubscription($subscription)
  {
  }

  /**
   * 
   * Syncs SubscriptionDiscount to payu as Coupon, Coupon are always deleted and recreated if already
   * exists one, becauso Coupons can't be updated on payu
   * 
   * @param SubscriptionDiscount $subscriptionDiscount orm subscription discount object to sync
   */

  public static function syncCouponCreated($subscriptionDiscount)
  {
  }
}
