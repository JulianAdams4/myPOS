<?php

namespace App\Http\Controllers\API\V2;

use App\Order;
use App\Address;
use App\Customer;
use App\CustomerAddress;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\Traits\AuthTrait;
use Log;

use App\Traits\Logs\Logging;

class CustomerController extends Controller
{

  use AuthTrait, LoggingHelper;
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

  public function create(Request $request)
  {
    $store = $this->authStore;

    try {
      $resultJSON = DB::transaction(
        function () use ($request) {
          $request->validate([
            "name" =>  "bail|required|string",
            "last_name"   =>  "bail|required|string",
            "phone"  => "bail|required|string",
            "address.address" => "bail|required|string",
          ]);

          $customer = new Customer();
          $customer->name = $request->name;
          $customer->last_name = $request->last_name;
          $customer->phone = $request->phone;
          $customer->email = $request->email;
          $customer->save();

          $requestAddress = $request->address;

          if ($requestAddress) {
            $address = new Address();
            $address->address = $requestAddress['address'];
            $address->detail = isset($requestAddress['detail']) ? $requestAddress['detail'] : null;
            $address->post_code = isset($requestAddress['post_code']) ? $requestAddress['post_code'] : null;
            $address->reference = isset($requestAddress['reference']) ? $requestAddress['reference'] : null;
            $address->save();

            $customerAddress = new CustomerAddress();
            $customerAddress->customer_id = $customer->id;
            $customerAddress->address_id = $address->id;
            $customerAddress->save();
          }
          $customer->load(['addresses.address']);

          return response()->json(
            [
              "status" => "El cliente sido creado con éxito",
              "results" => $customer,
            ],
            200
          );
        }
      );

      return $resultJSON;
    } catch (\Exception $e) {
      Logging::logError(
        "CustomerController API Store: ERROR CREAR CLIENTE, storeId: " . $store->id,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        json_encode($request)
      );

      return response()->json([
        'status' => 'No se pudo crear un nuevo cliente',
        'results' => null,
      ], 409);
    }
  }

  public function search(Request $request)
  {
    $store = $this->authStore;

    try {
      $customer = Customer::where('phone', $request->phone)
        ->with(['addresses.address'])
        ->first();

      if (!$customer) {
        return response()->json(
          [
            "status" => "El cliente no existe",
            "results" => $customer,
          ],
          404
        );
      }

      $orders = Order::where('store_id', $store->id)
        ->where('customer_id', $customer->id)
        ->where('preorder', 0);

      $total = $orders->sum('total');

      $lastOrder = $orders->orderBy('created_at', 'desc')->first();

      return response()->json(
        [
          "status" => "El cliente sido encontrado con éxito",
          "results" => $customer,
          "customerResume" => [
            "orders" => $orders->count(),
            "total" => $total,
            "lastOrder" => $lastOrder
          ]
        ],
        200
      );
    } catch (\Exception $e) {
      Logging::logError(
        "CustomerController API Store: ERROR BUSCAR CLIENTE, storeId: " . $store->id,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        json_encode($request)
      );

      return response()->json([
        'status' => 'No se pudo encontrar el cliente',
        'results' => null,
      ], 409);
    }
  }

  public function createAddress(Request $request)
  {
    $store = $this->authStore;

    try {
      $resultJSON = DB::transaction(
        function () use ($request) {

          $request->validate([
            "customer_id" => "bail|required|numeric",
            "address.address" => "bail|required|string",
            "address.detail" => "bail|required|string",
          ]);

          $customer_id = $request->customer_id;

          $requestAddress = $request->address;

          if ($requestAddress) {
            $address = new Address();
            $address->address = $requestAddress['address'];
            $address->detail = $requestAddress['detail'];
            $address->post_code = $requestAddress['post_code'] ?? "";
            $address->reference = $requestAddress['reference'] ?? "";
            $address->save();

            $customerAddress = new CustomerAddress();
            $customerAddress->customer_id = $customer_id;
            $customerAddress->address_id = $address->id;
            $customerAddress->save();
          }

          return response()->json(
            [
              "status" => "El domicilio del cliente sido creado con éxito",
              "results" => $address,
            ],
            200
          );
        }
      );

      return $resultJSON;
    } catch (\Exception $e) {
      Logging::logError(
        "CustomerController API Store: ERROR CREAR DOMICILIO DEL CLIENTE, storeId: " . $store->id,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        json_encode($request)
      );

      return response()->json([
        'status' => 'No se pudo crear un nuevo domicilio del cliente',
        'results' => null,
      ], 409);
    }
  }

  public function deleteAddress(Request $request)
  {
    $store = $this->authStore;

    try {
      $resultJSON = DB::transaction(
        function () use ($request) {

          $request->validate([
            "customer_id" => "bail|required|numeric",
            "address_id" => "bail|required|numeric"
          ]);

          $customer_id = $request->customer_id;
          $address_id = $request->address_id;

          $address = Address::find($address_id);

          $customerAddresses = CustomerAddress::where('customer_id', $customer_id)
            ->where('address_id', $address_id);

          if (!$address) {
            return response()->json(
              [
                "status" => "El domicilio del cliente no existe",
                "results" => $address,
              ],
              404
            );
          }

          $customerAddresses->delete();
          $address->delete();

          return response()->json(
            [
              "status" => "El domicilio del cliente sido eliminado con éxito",
              "results" => $address
            ],
            200
          );
        }
      );

      return $resultJSON;
    } catch (\Exception $e) {
      Logging::logError(
        "CustomerController API Store: ERROR ELIMINAR DOMICILIO DEL CLIENTE, storeId: " . $store->id,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        json_encode($request)
      );

      return response()->json([
        'status' => 'No se pudo crear un nuevo domicilio del cliente',
        'results' => null,
      ], 409);
    }
  }
}
