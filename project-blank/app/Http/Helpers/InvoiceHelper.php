<?php

namespace App\Http\Helpers;

use App\Billing;
use App\Helper;
use App\Invoice;
use App\InvoiceItem;
use App\InvoiceTaxDetail;
use Log;

class InvoiceHelper
{
    public static function createInvoice(
        $order,
        $billing,
        $isFoodService,
        $invoiceNumber = null,
        $shouldSetDetailId = false
    )
    {
        $invoice = new Invoice();
        $invoice->order_id = $order->id;
        $invoice->billing_id = $billing->id;
        $invoice->status = "Pagado";
        $invoice->document = $billing->document;
        $invoice->name = $billing->name;
        $invoice->address = $billing->address;
        $invoice->phone = $billing->phone;
        $invoice->email = $billing->email;
        $invoice->subtotal = Helper::bankersRounding($order->base_value, 0);
        $invoice->tax = Helper::bankersRounding($order->total - $order->base_value, 0);
        $invoice->total = Helper::bankersRounding($order->total, 0);
        $invoice->food_service = $isFoodService;
        $invoice->discount_percentage = $order->discount_percentage;
        $invoice->discount_value = Helper::bankersRounding($order->discount_value, 0);
        $invoice->undiscounted_subtotal = Helper::bankersRounding($order->undiscounted_base_value, 0);
        $invoice->tip = Helper::bankersRounding($order->tip, 0);

        // Delivery
        if(isset($order->customer) && isset($order->address) && $billing->name == "CONSUMIDOR FINAL") {
            $invoice->name = $order->customer->getFullName();
            $invoice->email = $order->customer->mail;
            $invoice->phone = $order->customer->phone;
            $invoice->address = $order->address->getFullAddress();
        }

        if ($invoiceNumber) {
            $invoice->invoice_number = $invoiceNumber;
        }
        $invoice->save();
        if ($order->no_tax_subtotal > 0) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = 'Sin impuestos (0%)';
            $invoiceTaxDetail->tax_percentage = 0;
            $invoiceTaxDetail->subtotal = 0;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($order->no_tax_subtotal, 0);
            $invoiceTaxDetail->print = 1;
            $invoiceTaxDetail->save();
        }
        foreach ($order->taxDetails as $taxDetail) {
            $invoiceTaxDetail = new InvoiceTaxDetail();
            $invoiceTaxDetail->invoice_id = $invoice->id;
            $invoiceTaxDetail->tax_name = $taxDetail->storeTax->name;
            $invoiceTaxDetail->tax_percentage = $taxDetail->storeTax->percentage;
            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($taxDetail->tax_subtotal, 0);
            $invoiceTaxDetail->subtotal = Helper::bankersRounding($taxDetail->subtotal, 0);
            $invoiceTaxDetail->print = ($taxDetail->storeTax->type === 'invoice') ? 0 : 1;
            $invoiceTaxDetail->save();
        }
        $orderCollection = collect($order);
        $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey(
            $order->orderDetails->load('orderSpecifications.specification.specificationCategory')
        );
        $orderCollection->forget('orderDetails');
        $orderCollection->put('orderDetails', $groupedOrderDetails);

        foreach ($orderCollection['orderDetails'] as $orderDetail) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->invoice_id = $invoice->id;
            $invoiceItem->product_name = $orderDetail['name_product'];
            $invoiceItem->quantity = $orderDetail['quantity'];
            $invoiceItem->base_value = Helper::bankersRounding($orderDetail['base_value'], 0);
            $invoiceItem->total = Helper::bankersRounding($orderDetail['total'], 0);
            $invoiceItem->has_iva = $orderDetail['tax_values']['has_iva'];
            $invoiceItem->compound_key = $orderDetail['compound_key'];
            if ($shouldSetDetailId) {
                $invoiceItem->order_detail_id = $orderDetail['id'];
            }
            $invoiceItem->save();
        }

        return $invoice;
    }
}