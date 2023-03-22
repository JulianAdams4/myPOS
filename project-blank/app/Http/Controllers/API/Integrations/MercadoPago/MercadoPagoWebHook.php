<?php

namespace App\Http\Controllers\API\Integrations\MercadoPago;

use Log;
use App\Helper;
use App\Events\MercadoPago;
use Illuminate\Http\Request;
use App\PaymentIntegrationDetail;
use App\AvailableMyposIntegration;
use App\OrderHasPaymentIntegration;
use App\Http\Controllers\Controller;
use App\Traits\MercadoPago\MercadoPagoTrait;

class MercadoPagoWebHook extends Controller
{
    use MercadoPagoTrait;

    public function hook(Request $request){
       	Log::channel('mercado_pago_logs')->info(json_encode($request->post()));
	 if(isset($request->topic) && $request->topic === 'merchant_order'){

            //get all open and created orders
            $pendingPayments = OrderHasPaymentIntegration::where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)
                ->where('status', 'created')
                ->orWhere('status', 'opened')
                ->with('store')
                ->get();

            foreach ($pendingPayments as $payment) {
                $this->checkMerchantOrderStatus($request->id, $payment->store, $payment->reference_id, $payment, $request->resource);
            }
        }

        if(isset($request->type) && $request->type === 'payment' && isset($request->data['id'])){
            $paymentId = $request->data['id'];
            $payment = PaymentIntegrationDetail::where('reference_id', $paymentId)->first();

            if (!empty($payment)) {
                $this->updatePayment($payment);
            }
        }
        
    }

    public function changeStatusForAbandonedOrders(){
        $stores = OrderHasPaymentIntegration::distinct('store_id')->with('store')->get();
        
        foreach ($stores as $storeObj) {
            $store = $storeObj->store;
            
            $orders = OrderHasPaymentIntegration::where('created_at', '<', Helper::carbon($store->country_code)->subMinutes(5)->toDateTimeString())
                            ->where('integration_name', AvailableMyposIntegration::NAME_MERCADO_PAGO)
                            ->where('order_id', null)
                            ->where('status','!=','closed')
                            ->get();

            foreach ($orders as $order) {
                $order->status = 'abandoned';
                $order->save();
            }
            
        }

    }
}
