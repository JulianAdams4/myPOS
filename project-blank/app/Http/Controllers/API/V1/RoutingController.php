<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception as GzException;
use Log;

class RoutingController extends Controller
{

  public $GACELA_PROVIDER;

  public function __construct()
  {
    $this->GACELA_PROVIDER = config('app.gacela_api').'tracking/fare';
    $this->middleware('customer',['only' => ['store']]);
  }

  /**
   * get route value from api GACELA.
   *
   * @param  array  $originLocation
   * @param  array  $destinationLocation
   * @return \Illuminate\Http\Response
   */
   public function makeRouteRequest(Request $request){
     Log::info('makeRouteRequest');
     Log::info(json_encode($request->all()));
     try {
       Log::info($this->GACELA_PROVIDER);
       $client = new Client();
       $res = $client->post($this->GACELA_PROVIDER, [
         'json' => [
           'origin' => ['latitude'=>$request->origin['latitude'],'longitude'=>$request->origin['longitude']],
           'destination_latitude' => $request->destination['latitude'],
           'destination_longitude' => $request->destination['longitude']
         ],
         'http_errors' => false
       ]);
       if($res){
         Log::info($res->getBody());
         $aux = json_decode($res->getBody());
         return response()->json([
           'status' => 'Solicitud procesada.',
           'results' => $aux->results
         ], $res->getStatusCode());
       }
     } catch (GzException $e) {
       Log::info('error '.$e);
       Log::info('##########');
       Log::info($e->getResponse()->getBody()->getContents());
       return response()->json([
         'status' => 'Error en la petición.',
         'results' => []
       ],400);
     }
   }

}
