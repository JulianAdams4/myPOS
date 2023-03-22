<?php

use App\Helper;
use App\Order;
use App\OrderTaxDetail;
use Illuminate\Database\Seeder;

class CompleteTaxesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $orders = Order::with('orderDetails', 'store.taxes')->get();
        foreach ($orders as $order) {
            $orderBaseValue = 0;
            $orderTotalValue = 0;
            $details = $order->orderDetails;
            $totalTaxDetails = [];
            $invoiceTaxes = [];
            foreach ($order->store->taxes as $tax) {
                if ($tax->type !== 'invoice') {
                    array_push($totalTaxDetails, [
                        'tax' => $tax->id,
                        'subtotal' => 0
                    ]);
                } else {
                    array_push($invoiceTaxes, $tax);
                }
            }
            foreach ($details as $detail) {
                $taxValues = $detail->tax_values;
                $taxDetails = $taxValues['tax_details'];
                foreach ($taxDetails as $taxOrderDetail) {
                    $taxInfo = $taxOrderDetail['tax'];
                    $taxSubtotal = $taxOrderDetail['subtotal'];
                    foreach ($totalTaxDetails as $index => $totalTaxDetail) {
                        if ($totalTaxDetail['tax'] === $taxInfo['id']) {
                            $totalTaxDetails[$index]['subtotal'] += $taxSubtotal;
                        }
                    }
                }
                $orderBaseValue += $detail->base_value;
                $orderTotalValue += $detail->total;
            }
            foreach ($totalTaxDetails as $totalTaxDetail) {
                if ($totalTaxDetail['subtotal'] > 0) {
                    $orderTaxDetail = new OrderTaxDetail();
                    $orderTaxDetail->order_id = $order->id;
                    $orderTaxDetail->store_tax_id = $totalTaxDetail['tax'];
                    $orderTaxDetail->subtotal = $totalTaxDetail['subtotal'];
                    $orderTaxDetail->save();
                }
            }
            $totalInvoiceTaxes = 0;
            foreach ($invoiceTaxes as $tax) {
                $orderTaxDetail = new OrderTaxDetail();
                $orderTaxDetail->order_id = $order->id;
                $orderTaxDetail->store_tax_id = $tax->id;
                $orderTaxDetail->subtotal = Helper::bankersRounding($orderBaseValue * ($tax->percentage / 100), 0);
                $orderTaxDetail->save();
                $totalInvoiceTaxes += $tax->percentage;
            }
            $order->base_value = $orderBaseValue;
            $order->total = Helper::bankersRounding($orderTotalValue + ($orderBaseValue * ($totalInvoiceTaxes / 100)), 0);
            $order->save();
        }
    }
}
