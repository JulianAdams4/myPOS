<?php

namespace App\Traits;

use Log;
use App\Store;
use App\Address;
use GuzzleHttp\Client;

trait GeoProcedures
{

  #http://104.248.63.67:5000/route/v1/driving/-79.89279270172119,-2.1796593896823655;-79.86459732055664,-2.1390903041196987?overview=false&alternatives=true&steps=true
  public $GACELA_OMAP_PROVIDER = 'http://104.248.63.67:5000/route/v1/driving/';
  public $GACELA_OMAP_PROVIDER_ENDFIX = '?overview=false&alternatives=true&steps=true';

  public function getClosestStoreByLocation($stores,$latitude,$longitude){
    Log::info('getClosestStoreByLocation');
    Log::info($latitude . ',' . $longitude);
    if(count($stores) == 0)
      return null;

    foreach ($stores as $key => $store) {
      $address = $store->address;
      if($address){
        $distance = $this->getDistance($address->latitude,$address->longitude,$latitude,$longitude);
        $store['distance'] = $distance;
      }else{
        $stores->forget($key);
      }
    }
    $stores = $stores->sortBy('distance');
    Log::info($stores);
    Log::info('selected');
    Log::info($stores->sortBy('distance')->first());
    Log::info('$stores');
    Log::info($stores->first());
    return $stores->first();
  }

  function getDistance($latitude1, $longitude1, $latitude2, $longitude2) {

    $earth_radius = 6371;

    $dLat = deg2rad($latitude2 - $latitude1);
    $dLon = deg2rad($longitude2 - $longitude1);

    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * asin(sqrt($a));
    $d = $earth_radius * $c;

    return $d;
  }

  public function calculateGacelaDistance($originLatitude,$originLongitude,$destinationLatitude,$destinationLongitude){
        $dic = null;
        $origin = $originLongitude.','.$originLatitude;
        $destination = $destinationLongitude.','.$destinationLatitude;
        $url = $this->$GACELA_OMAP_PROVIDER.$origin.';'.$destination.$this->$GACELA_OMAP_PROVIDER_ENDFIX;

        try {
          $client = new Client();
          $res = $client->get($url);
          if($res){
            Log::info($res->getBody());
            $aux = json_decode($res->getBody(),true);
            $dic["gmDistance"] = $arr['routes'][0]['legs'][0]["distance"];
            $dic["gmDuration"] = $arr['routes'][0]['legs'][0]["duration"];
          }
        } catch (GzException $e) {
          Log::info('error '.$e);
          Log::info('##########');
          Log::info($e->getResponse()->getBody()->getContents());
        }
        return $dic;
  }

}
