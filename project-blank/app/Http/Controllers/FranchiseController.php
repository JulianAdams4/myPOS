<?php

namespace App\Http\Controllers;

// Libraries
use Auth;
use Carbon\Carbon;
use App\CompanyTax;
use App\StoreConfig;
use App\StoreLocations;
use App\StoreTax;
use App\Traits\AuthTrait;
use App\Traits\LocaleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\LoggingHelper;
use App\Http\Controllers\Controller;
use Ramsey\Uuid\Uuid;

// Cache
use Illuminate\Support\Facades\Cache;

// Models
use App\Company;
use App\Country;
use App\Franchise;
use App\Store;

// Helpers
use App\Traits\StoreConfigHelper;

class FranchiseController extends Controller
{

  use LoggingHelper;
  use AuthTrait;
  use LocaleHelper;
  use StoreConfigHelper;
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

  public function getAll(Request $request)
  {
    $originCompany = $this->authStore->company;
    $franchises = Franchise::where('origin_company_id', $originCompany->id)
      ->with(['company'])
      ->get();
    return response()->json(['franchises' => $franchises]);
  }

  public function getById($id)
  {
    $franchise = Franchise::where('id', $id)
      ->with(['company.stores'])
      ->first();

    return response()->json($franchise);
  }

  public function createFranchiseWithStore(Request $request)
  {

    $user = $this->authUser;
    $originCompany = $this->authStore->company;

    $validator = Validator::make($request->all(), [
      "company.name" => "required|string",
      "company.contact" => "required|string",
      "company.tin" => "required|string",
      "company.email" => "required|string",

      "store.name" => "required|string",
      "store.country_id" => "required|integer",
      "store.city_id" => "required|integer",
      "store.phone" => "required|string",
      "store.address" => "required|string",
      "store.contact" => "required|string",
      "store.email" => "required|string"

      // "bill_sequence" => "required",
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
        function () use ($data, $originCompany) {

          $company = new Company();
          $company->name = $data['company']['name'];
          $company->identifier = Uuid::uuid5(Uuid::NAMESPACE_DNS, $data['company']['name']);
          $company->contact = $data['company']['contact'];
          $company->tin = $data['company']['tin'];
          $company->email = $data['company']['email'];
          $company->save();

          $franchise = new Franchise();
          $franchise->origin_company_id = $originCompany->id;
          $franchise->company_id = $company->id;
          $franchise->save();

          $store = new Store();
          $store->name = $data['store']['name'];
          $store->phone = $data['store']['phone'];
          $store->contact = $data['store']['contact'];
          $country = Country::where('id', $data['store']['country_id'])->first();
          $currency = $this->countryToCurrency(strtoupper($country->code));
          $store->currency = $currency;
          // $store->issuance_point = isset($data['issuance_point']) ? $data['issuance_point'] : null;
          // $store->code = isset($data['code']) ? $data['code'] : null;
          $store->address = $data['store']['address'];
          $store->country_code = $country->code;
          // $store->bill_sequence = $data['bill_sequence'];
          $store->order_app_sync = 1;
          $store->button_bill_prints = 1;
          $store->city_id = $data['store']['city_id'];
          $store->max_sequence = 1;
          $store->email =  $data['store']['email'];
          $store->company_id = $company->id;
          $store->save();

          $dataTax = $this->countryToTaxValue(strtoupper($country->code));

          // Creando el CompanyTax por si no existe
          $companyTax = CompanyTax::where('company_id', $company->id)->first();

          if ($companyTax == null) {
              $newCompanyTax = new CompanyTax();
              $newCompanyTax->company_id = $company->id;
              $newCompanyTax->name = $dataTax["name"];
              $newCompanyTax->percentage = $dataTax["value"];
              $newCompanyTax->type = "included";
              $newCompanyTax->enabled = 1;
              $newCompanyTax->save();
          }

          // Creando el StoreTax
          $storeTax = new StoreTax();
          $storeTax->store_id = $store->id;
          $storeTax->name = $dataTax["name"];
          $storeTax->percentage = $dataTax["value"];
          $storeTax->type = "included";
          $storeTax->enabled = 1;
          $storeTax->is_main = 1;
          $storeTax->save();

          // Creando el StoreConfig
          $storeConfig = new StoreConfig();
          $storeConfig->store_id = $store->id;
          $storeConfig->show_taxes = 1;
          $storeConfig->document_lengths = "";
          $storeConfig->uses_print_service = 1;
          $storeConfig->employee_digital_comanda = 0;
          $storeConfig->show_invoice_specs = 0;
          $storeConfig->alternate_bill_sequence = 0;
          $storeConfig->show_search_name_comanda = 0;
          $storeConfig->is_dark_kitchen = 0;
          $storeConfig->auto_open_close_cashier = 0;
          $storeConfig->allow_modify_order_payment = 0;
          $storeConfig->currency_symbol = "$";

          $configByCountry = $this->getDataConfigByCountryCode(strtoupper($country->code));
          $storeConfig->comanda = $configByCountry["comanda"];
          $storeConfig->precuenta = $configByCountry["precuenta"];
          $storeConfig->factura = $configByCountry["factura"];
          $storeConfig->cierre = $configByCountry["cierre"];
          $storeConfig->common_bills = $configByCountry["common_bills"];
          $storeConfig->time_zone = $configByCountry["timezone"];
          $storeConfig->xz_format = $configByCountry["xz_format"];
          $storeConfig->credit_format = $configByCountry["credit_format"];
          $storeConfig->store_money_format = $configByCountry["store_money_format"];
          $storeConfig->save();

          Cache::forever("store:{$store->id}:configs:timezone", $configByCountry["timezone"]);

          // Creando el location default
          $location = new StoreLocations();
          $location->name = "Piso 1";
          $location->priority = 1;
          $location->store_id = $store->id;
          $location->save();

          return response()->json([
            "status" => "Franquicia creada con éxito!",
            "results" => null,
          ], 200);
        }
      );

      return $operationJSON;
    } catch (\Exception $e) {
      $this->printLogFile(
        "StoreController: ERROR CREAR FRANQUICIA, userId: " . $user->id,
        "daily",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $data
      );
      $slackMessage = "StoreController: CREAR FRANQUICIA, userId: " . $user->id .
        "Provocado por: " . $data;

      $this->sendSlackMessage(
        $this->channel,
        $slackMessage
      );

      return response()->json([
        'status' => 'No se pudo crear la franquicia',
        'results' => null,
      ], 409);
    }
  }
}
