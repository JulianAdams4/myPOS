<?php

namespace App\Http\Controllers;

use App\Order;
use App\Traits\OrderHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Probes extends Controller
{
    use OrderHelper;
    public function index(Request $request){
        $storeId = empty($request->store_id) ? 's.id' : $request->store_id;
        $badOrders = DB::select("select o.id from orders o
        join stores s on o.store_id = {$storeId}
        join order_integration_details oid on o.id=oid.order_id and integration_name='rappi'
        join order_details od on o.id = od.order_id
        join blank_pos.product_details pd on od.product_detail_id = pd.id
        join products p on pd.product_id = p.id
        left join product_taxes pt on p.id = pt.product_id
        join store_taxes st on st.store_id=o.store_id and pt.store_tax_id = st.id
        left join order_tax_details otd on o.id = otd.order_id
        where otd.id is null and date(o.created_at) >= {$request->start_date} group by o.id;");

        //seguro activado muestra el resultado de la consulta 
        if(!isset($request->only_show) || $request->only_show == 1){
            return response()->json(
                [
                    "status" =>"Solo mostrando resultados.",
                    "orders_to_edit" => $badOrders
                ], 
                200
            );
        }

        foreach ($badOrders as $badOrder) {
            $order = Order::where('id', $badOrder->id)->first();

            $this->printBefore($order);

            foreach ($order->orderDetails as $detail) {
                $taxes = $detail->productDetail->product->taxes;
                foreach ($taxes as $tax) {
                    if ($tax->store_id == $order->store->id
                        && $tax->type === 'included'
                        && $tax->enabled
                    ) {
                        $tax->is_main = 1;
                    }
                }
            }
            $orderUpdated = $this->calculateOrderValuesIntegration($order, 'rappi');
            Log::info('After order '.json_encode($orderUpdated));
        }

        return response()->json(
            [
                "status" =>"Ordenes modificadas exitosamente.",
                "orders_updated" => $badOrders
            ], 
            200
        );
    }

    public function printBefore($order){
        Log::info('Before order '.json_encode($order->load('orderDetails')));
    }
}
