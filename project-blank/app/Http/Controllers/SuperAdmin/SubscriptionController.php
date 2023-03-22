<?php

namespace App\Http\Controllers\SuperAdmin;

// Libraries
use Auth;
use App\Store;
use App\Company;
use Carbon\Carbon;
use App\Subscription;
use App\SubscriptionPlan;

// Models
use App\Traits\AuthTrait;
use App\SubscriptionProduct;
use App\Traits\LocaleHelper;
use Illuminate\Http\Request;
use App\SubscriptionDiscount;
use App\SubscriptionInvoices;
use App\Traits\LoggingHelper;

// Helpers
use App\StripeCustomerCompany;
use App\AvailableMyposIntegration;
use Illuminate\Support\Facades\DB;

// Controllers
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\StripeCompanyController;
use App\Http\Controllers\API\Store\SubscriptionBillingController;

class SubscriptionController extends Controller
{

  use LoggingHelper;
  use AuthTrait;
  use LocaleHelper;
  public $authUser;
  public $authStore;
  public $authEmployee;
  public $channel;

  public function __construct()
  {
    $this->middleware('api');
    [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
      return response()->json([
        'status' => 'Usuario no autorizado',
      ], 401);
    }
    $this->channel = "#laravel_logs";
  }

  public function getProducts(Request $request)
  {
    $products = SubscriptionProduct::all()->load(['country']);
    return response()->json($products);
  }

  public function getPlans(Request $request)
  {
    $plans = SubscriptionPlan::all();
    return response()->json($plans);
  }

  public function createProduct(Request $request)
  {
    $user = $this->authUser;

    $validator = Validator::make($request->all(), [
      "country_id" => "required|integer",
      "name" => "required|string",
      "frequency" => "required|in:day,week,month,year",
      "price" => "required|integer",
      "apply_taxes" => "required|boolean",
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "Los datos enviados contienen errores como tipos de datos incorrectos o campos obligatorios vacíos",
        'results' => null
      ], 409);
    }

    $data = $request->all();

    try {
      $operationJSON = DB::transaction(
        function () use ($data) {
          $subscriptionProduct = new SubscriptionProduct();
          $subscriptionProduct->country_id = $data['country_id'];
          $subscriptionProduct->name = $data['name'];
          $subscriptionProduct->price = $data['price'];
          $subscriptionProduct->apply_taxes = $data['apply_taxes'];
          $subscriptionProduct->save();
          $subscriptionProduct->load(['country']);

          $subscriptionPlan = new SubscriptionPlan();
          $subscriptionPlan->name = $subscriptionProduct->name;
          $subscriptionPlan->subscription_product_id = $subscriptionProduct->id;
          $subscriptionPlan->frequency = $data['frequency'];
          $subscriptionPlan->save();


          return response()->json([
            "status" => "Producto de suscripción creado con éxito!",
            "results" => $subscriptionProduct,
          ], 200);
        }
      );

      return $operationJSON;
    } catch (\Exception $e) {
      $this->printLogFile(
        "StoreController: ERROR CREAR PRODUCTO DE SUSCRIPCIÓN, userId: " . $user->id,
        "daily",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $data
      );
      $slackMessage = "StoreController: CREAR PRODUCTO DE SUSCRIPCIÓN, userId: " . $user->id .
        "Provocado por: " . $data;
      $this->sendSlackMessage(
        $this->channel,
        $slackMessage
      );
      return response()->json([
        'status' => 'No se pudo crear el producto de suscripción',
        'results' => null,
      ], 409);
    }
  }

  public function createDiscount(Request $request)
  {
    $user = $this->authUser;

    $validator = Validator::make($request->all(), [
      "store_id" => "nullable|integer",
      "company_id" => "nullable|integer",
      "subscription_product_id" => "required|integer",
      "discount" => "required|integer",
      "expires_at" => "required|string"
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "Los datos enviados contienen errores como tipos de datos incorrectos o campos obligatorios vacíos",
        'results' => null
      ], 409);
    }

    $data = $request->all();

    try {
      $operationJSON = DB::transaction(
        function () use ($data) {

          $storeId = isset($data['store_id']) ? $data['store_id'] : null;
          $companyId = isset($data['company_id']) ? $data['company_id'] : null;
          $discount = isset($data['discount']) ? $data['discount'] : null;
          $subscriptionProductId = isset($data['subscription_product_id']) ? $data['subscription_product_id'] : null;
          $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

          $now = Carbon::now();

          if ($now > $expiresAt) {
            return response()->json([
              "status" => "La fecha de expiración del descuento es anterior a la fecha actual",
              "results" => null
            ], 409);
          }

          if ($storeId) {
            $stores = [$storeId];
          } else {
            $stores = Store::where('company_id', $companyId)
              ->get()
              ->pluck('id')
              ->toArray();
          }

          $this->createStoresDiscounts($companyId, $stores, $subscriptionProductId, $discount, $expiresAt);

          return response()->json([
            "status" => "Descuento de suscripción creado con éxito!",
            "results" => null
          ], 200);
        }
      );


      return $operationJSON;
    } catch (\Exception $e) {
      $this->printLogFile(
        "StoreController: ERROR CREAR DESCUENTO DE SUSCRIPCIÓN, userId: " . $user->id,
        "daily",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $data
      );
      $slackMessage = "StoreController: CREAR DESCUENTO DE SUSCRIPCIÓN, userId: " . $user->id .
        "Provocado por: " . $data;
      $this->sendSlackMessage(
        $this->channel,
        $slackMessage
      );
      return response()->json([
        'status' => 'No se pudo crear el descuento de suscripción',
        'results' => null,
      ], 409);
    }
  }

  public function createStoresDiscounts($companyId, $stores, $subscriptionProductId, $discount, $expiresAt)
  {

    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

    foreach ($stores as $store) {

      $storeData = Store::find($store);

      if (!$storeData) continue;

      $newSubscriptionDiscount = SubscriptionDiscount::updateOrCreate(
        ['store_id' => $store, 'subscription_product_id' => $subscriptionProductId],
        ['discount' => $discount, 'expires_at' => $expiresAt]
      );

      StripeCompanyController::syncCouponCreated($newSubscriptionDiscount);
    }
  }

  public function getDiscounts(Request $request)
  {
    $companies = Company::whereHas('stores', function ($store) {
      $store->has('subscriptionDiscounts');
    })
      ->with('stores.subscriptionDiscounts.subscriptionProduct')
      ->get();

    return response()->json($companies);
  }

  public function getPdfInvoice(Request $request){

    $validator = Validator::make($request->all(), [
      "invoice_id" => "required|integer",
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => "invoice_id es requerido y debe ser int",
        'results' => null
      ], 409);
    }

    $invoice = SubscriptionInvoices::where('id', $request->invoice_id)->first();

    switch ($invoice->integration_name) {
        case AvailableMyposIntegration::NAME_FACTURAMA:
            $subsController = new SubscriptionBillingController;
            $invoiceFile = $subsController->getFileInvoiceFromFacturama($invoice->external_invoice_id, 'pdf');
        break;
        
        default:
            return response()->json([
                "status" => "error",
                "results" => "Isn´t possible process invoice with {$invoice->integration_name} integration"
            ], 409);
        break;
    }

    return response()->streamDownload(function () use ($invoiceFile) {
        base64_decode($invoiceFile);
      }, 'invoice-'.$invoice->id.'.pdf');
  }
}
