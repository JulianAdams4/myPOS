<?php

namespace App\Traits\MercadoPago;

use Log;
use Exception;
use GuzzleHttp\Client;
use App\PaymentIntegrationDetail;
use App\AvailableMyposIntegration;
use App\OrderHasPaymentIntegration;
use GuzzleHttp\Exception\ClientException;
use App\Events\MercadoPago as MercadoPagoEvent;

trait MercadoPagoTrait
{
	public function makeRequest($method, $url, $params = null, $store, $integrationToken){
        $params = !empty($params) ? json_decode(stripslashes($params), true) : $params;

        try {
            $client = new Client();
            $response = $client->request($method, $url."?access_token=".$integrationToken, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'application/json',
                    'Accept-Encoding' => 'gzip'
                ],
                'json' => $params,
                // 'http_errors' => true
            ]);

            $data = json_decode($response->getBody(), true);
        
        } catch (ClientException $e) {

            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            Log::channel('mercado_pago_logs')->error("Error FROM TRAIT in makeRequest: {$exceptionMessage}");
            Log::channel('mercado_pago_logs')->error("in store {$store->id} {$store->name}");
            Log::channel('mercado_pago_logs')->error("From: {$url}");
            Log::channel('mercado_pago_logs')->error("Data: ".json_encode($params));
            Log::channel('mercado_pago_logs')->error("--------------------------------------------------------------");
            throw new Exception($e);
        }

        return $data;
	}
	
	public function checkMerchantOrderStatus($merchantOrder, $store, $externalId, OrderHasPaymentIntegration $payment, $resource){
		$intInfo = $store->integrationTokens()
			->where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)
			->first();

		$accessToken = $intInfo->token;
	try{
		$order = (object) $this->makeRequest('GET', $resource, null,
			$store,
            $accessToken);
	}catch(\Throwable $th){
		return true;
	}
            
        if($order->external_reference !== $externalId){
            return true;
        }

        if(empty($order)){
            return true;
        }
        
        if($order->status == 'opened'){
            
            $payment->status = 'opened';
            $payment->save();

			$status = "opened";
			return true;
        }

        if($order->status == 'closed'){

            $payment->status = 'closed';
            $payment->save();

            $status = "closed";
            
            //saves or updates all the status of payments
            foreach ($order->payments as $paymentMp) {
                PaymentIntegrationDetail::create(
                    [
                        "store_id" => $store->id,
                        "integration_name" => AvailableMyposIntegration::NAME_MERCADO_PAGO,
                        "amount" => $paymentMp['total_paid_amount'] * 100,
                        "reference_id" => $paymentMp['id'],
                        "order_payment_integration" => $payment->id,
                        "status" => $paymentMp['status'],
                    ]
                );
            }

            //for now we only broadcast the 'closed' event
            $eventResponse = [
                "status" => $status,
                "external_id" => $externalId,
                "merchant_order" => $order->id,
                "payment_mp_id" => $order->payments[0]['id']
            ];
		Log::channel('mercado_pago_logs')->info('emitiendo evento');

		    event(new MercadoPagoEvent($eventResponse, $store->id) );
		}
    }

    public function updatePayment($payment){
        $store = $payment->store;
        $intInfo = $store->integrationTokens()
            ->where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)
            ->first();

        $accessToken = $intInfo->token;

        $reqPayment = (object) $this->makeRequest('GET', config('app.mercado_pago_api')."/v1/payments/{$payment->reference_id}", null,
            $store,
            $accessToken);

        $payment->status = $reqPayment->status;
        $payment->save();
    }
}
