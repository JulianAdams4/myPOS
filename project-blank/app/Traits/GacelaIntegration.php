<?php

namespace App\Traits;

use Log;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use App\OrderStatus;
use App\Order;

trait GacelaIntegration
{

  public function createOrderBundle($order){
    $bundleOrder = [
      'order_id' => $order->id,
      'route_value' => "5.50",
      'order_value' => $order->order_value,
      'order_duration' => "12",
      'order_distance' => "12",
      'gacela_token' => $order->store->gacela_token
    ];
    $bundleAddress = [
      'address' => $order->address->address,
      'latitude' => $order->address->latitude,
      'longitude' => $order->address->longitude
    ];
    $bundleCustomer = [
      'phone' => $order->billing->phone,
      'name' => $order->billing->name,
      'lastname' => '',
      'document' => ($order->billing) ? $order->billing->document : ''
    ];

    return [
      'order' => $bundleOrder,
      'address' => $bundleAddress,
      'customer' => $bundleCustomer,
    ];
  }


  public function postOrderToGacela($bundleOrder,$bundleCustomer,$bundleAddress){
    Log::info('postOrderToGacela');
    Log::info($bundleOrder);
    Log::info($bundleCustomer);
    Log::info($bundleAddress);
    $gacelaEndpoint = config('app.gacela_api') . 'v2/application/orders/set';
    $client = new \GuzzleHttp\Client();
    try {
      $payload = [
        'document' => $bundleCustomer['document'],
        'api_token_gacela' => $bundleOrder['gacela_token'],
        'address' => $bundleAddress['address'],
        'latitude' => $bundleAddress['latitude'],
        'longitude' => $bundleAddress['longitude'],
        'name' => $bundleCustomer['name'],
        'lastname' => $bundleCustomer['lastname'],
        'phone' => $bundleCustomer['phone'],
        'routeValue' => number_format(($bundleOrder['route_value'] /100), 2, '.', ' '),
        'order_value' => number_format(($bundleOrder['order_value'] /100), 2, '.', ' '),
        'orderDuration' => $bundleOrder['order_duration'],
        'distance' => $bundleOrder['order_distance']
      ];
      $headers = [
        'Authorization' => 'Bearer ' . config('app.gacela_tere_company_token'),
        'Content-Type' => 'application/json'
      ];
      $request = new Request('POST', $gacelaEndpoint, $headers, json_encode($payload));
      $promise = $client->sendAsync($request);
      Log::info('here');
      $promise->then(
          function ($response) use ($bundleOrder){
              Log::info('on success');
              $order = $response->getBody()->rewind();
              $order = $response->getBody()->getContents();
              Log::info($order);
              Log::info($response->getStatusCode());
              if($response->getStatusCode() == 201){
                Log::info('creating order status');
                $order = json_decode($order);
                $statusOrder = $order->results->status_orders[0];
                $status = OrderStatus::create([
                  'order_id' => $bundleOrder['order_id'],
                  'name' => $statusOrder->name,
                ]);
                $orderUpdated = Order::find($bundleOrder['order_id']);
                Log::info('$orderUpdated');
                Log::info($orderUpdated);
                if($orderUpdated){
                  $orderUpdated->order_token = $order->results->order_token;
                  $orderUpdated->save();
                  Log::info('$orderUpdated');
                }
                Log::info('$status');
                Log::info($status);
              }
              return $response->getBody();
          }, function ($exception) {
              Log::info('on error');
              Log::info($exception->getMessage());
              Log::info($exception->getResponse()->getBody()->getContents());
              return $exception->getMessage();
          }
      );
      // $response = $client->postAsync($gacelaEndpoint,
      //   [
      //     'headers' => $headers
      //   ],[
      //     'json' => json_encode($payload)
      //   ])->then(
      //     function ($response) {
      //         Log::info('on success');
      //         Log::info($response->getBody());
      //         return $response->getBody();
      //     }, function ($exception) {
      //         Log::info('on error');
      //         Log::info($exception->getMessage());
      //         Log::info($exception->getResponse()->getBody()->getContents());
      //         return $exception->getMessage();
      //     }
      // );
      $response = $promise->wait();

    } catch (\Exception $e) {
     Log::info('error al postear Orden a Gacela');
     Log::info($e);
    }
  }

}
