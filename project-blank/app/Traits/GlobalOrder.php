<?php

namespace App\Traits;

use App\Card;
use App\Spot;
use App\Order;
use App\Store;
use App\Helper;
use App\Billing;
use App\Payment;
use App\Employee;
use Carbon\Carbon;
use App\OrderDetail;
use App\PaymentType;
use App\PendingSync;
use App\CashierBalance;
use App\Events\SpotDeleted;
use App\Traits\OrderHelper;
use App\Events\OrderCreated;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\OrderIntegrationDetail;
use App\AvailableMyposIntegration;
use App\Helpers\PrintService\PrintServiceHelper;
use Illuminate\Support\Facades\DB;
use App\Http\Helpers\InvoiceHelper;
use Illuminate\Support\Facades\Log;
use App\Events\CompanyOrderCreatedEvent;

trait GlobalOrder
{
    use OrderHelper, LoggingHelper;

    public function createSimpleOrder(Request $request, Employee $employee, Store $store)
    {
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    if ($request->has_billing && $store->configs->document_lengths !== '') {
                        $lengths = explode(',', $store->configs->document_lengths);
                        $docLength = strlen($request->billing_document) . '';
                        if (!in_array($docLength, $lengths)) {
                            return response()->json([
                                "status" => "El formato del R.U.C. es incorrecto.",
                                "results" => "null"
                            ], 409);
                        }
                    }

                    $now = Carbon::now()->toDateTimeString();

                    $formattedDate = Helper::formattedDate($now);
                    $request->request->add(['formatted_date' => $formattedDate]);
                    $store->load('currentCashierBalance');
                    $cashierBalance = $store->currentCashierBalance;
                    $identifier = 0;

                    $invoiceNumber = $store->nextInvoiceBillingNumber();
                    $invoiceNumberRequest = $request->invoice_number;
                    if ($invoiceNumber === "") {
                        $invoiceNumber = $request->invoice_number;
                    }

                    $preorder = null;
                    if ($request->order_details) {
                        $orderDetails = $request->order_details;
                        $orderDetailCreated = OrderDetail::where('id', $orderDetails[0]["id"])->first();
                        if ($orderDetailCreated) {
                            $preorder = Order::where('id', $orderDetailCreated->order_id)->first();
                            if ($preorder) {
                                $identifier = $preorder->identifier;
                            }
                        }
                    } else {
                        $identifier = Helper::getNextOrderIdentifier($store->id);
                    }
                    // Retrocompatible with old way of payments
                    $hasCreditCard = false;
                    $hasDebitCard = false;
                    $selectedCard = null;

                    if ($request->card_id) {
                        $selectedCard = Card::find($request->card_id);
                        $hasCreditCard = $selectedCard->type == 1;
                        $hasDebitCard = $selectedCard->type == 0;
                    }
                    $valueCash = null;
                    $alternateBillSequenceSwitch = false;
                    if ($request->cash == 1) {
                        $alternateBillSequenceSwitch = true;
                        if ($request->value_cash != 0) {
                            $valueCash = $request->value_cash;
                        }
                    }

                    if ($request->payments == null) {
                        $request->payments = [];
                        if ($valueCash != null) {
                            $paymentObject = [
                                "total" => $valueCash,
                                "type" => PaymentType::CASH
                            ];

                            array_push($request->payments, $paymentObject);
                        }

                        if (($hasDebitCard || $request->debit_card == 1) && $request->value_debit_card != 0) {
                            $paymentObject = [
                                "total" => $request->value_debit_card,
                                "type" => PaymentType::DEBIT,
                                "card_id" => $selectedCard->id
                            ];

                            if ($selectedCard != null) {
                                $paymentObject["card_id"] = $selectedCard->id;
                            }

                            if (isset($request->card_last_digits)) {
                                $paymentObject["card_last_digits"] = $request->card_last_digits;
                            }

                            array_push($request->payments, $paymentObject);
                        }

                        if (($hasCreditCard || $request->credit_card == 1) && $request->value_credit_card != 0) {
                            $paymentObject = [
                                "total" => $request->value_credit_card,
                                "type" => PaymentType::CREDIT,
                                "card_id" => $selectedCard->id
                            ];

                            if ($selectedCard != null) {
                                $paymentObject["card_id"] = $selectedCard->id;
                            }

                            if (isset($request->card_last_digits)) {
                                $paymentObject["card_last_digits"] = $request->card_last_digits;
                            }

                            array_push($request->payments, $paymentObject);
                        }

                        if ($request->transfer == 1 && $request->value_transfer != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_transfer,
                                    "type" => PaymentType::TRANSFER
                                ]
                            );
                        }

                        if ($request->rappi_pay == 1 && $request->value_rappi_pay != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_rappi_pay,
                                    "type" => PaymentType::RAPPI_PAY
                                ]
                            );
                        }

                        if ($request->other == 1 && $request->value_other != 0) {
                            array_push(
                                $request->payments,
                                [
                                    "total" => $request->value_other,
                                    "type" => PaymentType::OTHER
                                ]
                            );
                        }

                        $request->payments = array_values($request->payments);
                    }

                    $employeeId = $preorder !== null
                        ? $preorder->employee_id
                        : $employee->id;

                    if ($request->employee_id) {
                        $employeeId = $request->employee_id;
                    }

                    $order = Order::create(
                        array_merge(
                            (array) $request,
                            [
                                // 'employee_id' => $employeeId,
                                'store_id' => $store->id,
                                'identifier' => $identifier,
                                'cashier_balance_id' => $cashierBalance->id,
                                'formatted_date' => $formattedDate,
                                // 'discount_percentage' => $request->discount_percentage,
                                // 'discount_value' => $request->discount_value,
                                'undiscounted_base_value' => $request->undiscounted_base_value,
                                // 'tip' => $request->input('tip', 0)
                            ]
                        )
                    );

                    $order->people = $request->people;
                    $order->custom_identifier = $request->custom_identifier;

                    if ($request->has_billing) {
                        $billing = Billing::where('document', $request->billing_document)->first();
                        if ($billing) {
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address ? $request->billing_address :
                            $billing->address;
                            $billing->phone = $request->billing_phone ? $request->billing_phone : $billing->phone;
                            $billing->email = $request->billing_email ? $request->billing_email : $billing->email;

                            $billing->is_company = $request->billing_is_company || $request->billing_is_company == false ? $request->billing_is_company : $billing->is_company;
                            $billing->company_checkdigit = $request->billing_company_checkdigit || $request->billing_company_checkdigit == "0" ? (Int) $request->billing_company_checkdigit : $billing->company_checkdigit;
                            $billing->document_type = $request->billing_document_type;
                            $billing->company_pay_iva = $request->billing_company_pay_iva || $request->billing_company_pay_iva == false ? $request->billing_company_pay_iva : $billing->company_pay_iva;
                            $billing->city = $request->billing_city ? $request->billing_city : $billing->city;
                            $billing->save();
                        } else {
                            $billing = new Billing();
                            $billing->document = $request->billing_document;
                            $billing->name = $request->billing_name;
                            $billing->address = $request->billing_address;
                            $billing->phone = $request->billing_phone;
                            $billing->email = $request->billing_email;
                            $billing->is_company = $request->billing_is_company;
                            $billing->company_checkdigit = (Int) $request->billing_company_checkdigit;
                            $billing->document_type = $request->billing_document_type;
                            $billing->company_pay_iva = $request->billing_company_pay_iva;
                            $billing->city = $request->billing_city;
                            $billing->save();
                        }
                        $order->billing_id = $billing->id;
                        $order->save();
                    } else {
                        $billing = Billing::firstOrCreate([
                            'document' => '9999999999999',
                            'name'     => 'CONSUMIDOR FINAL'
                        ]);
                    }
                    foreach ($request->order_details as $orderDetail) {
                        $orderDetailCreated = OrderDetail::where('id', $orderDetail["id"])->first();
                        if ($orderDetailCreated) {
                            $orderDetailCreated->order_id = $order->id;
                            $orderDetailCreated->save();
                        }
                    }

                    $order = $this->calculateOrderValues($order);
                    $this->calculateOrderValues($preorder);

                    // why is this here?
                    if (count($preorder->orderDetails) === 0) {
                        $preorder->delete();
                    }

                    $invoice = InvoiceHelper::createInvoice(
                        $order,
                        $billing,
                        $request->food_service,
                        $invoiceNumber,
                        true
                    );

                    $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');
                    $officialInvoiceNumber = Helper::getNextBillingOfficialNumber($store->id, true);
                    // $alternateBill = Helper::getAlternatingBillingNumber(
                    //     $store->id,
                    //     $alternateBillSequenceSwitch
                    // );
                    // if ($alternateBill != "") {
                    //     $officialInvoiceNumber = $alternateBill;
                    // }
                    if ($officialInvoiceNumber != "") {
                        $invoice->invoice_number = $officialInvoiceNumber;
                        $invoice->save();
                    }

                    if (!config('app.slave')) {

                        if ($request->has_billing) {
                            /* Ejecuta todas las integraciones mientras se hayan definido los datos del usuario */
                            $this->prepareToSendForElectronicBilling($store, 
                                $invoice, 
                                AvailableMyposIntegration::NAME_NORMAL, 
                                null,
                                AvailableMyposIntegration::NAME_SIIGO);
                        }
                    }

                    $this->reduceComponentsStock($order);
                    $this->reduceComponentsStockBySpecification($order);

                    // Agregando especificaciones dentro del campo instrucciones
                    $newOrders = [];
                    $newOrderDetails = collect([]);
                    foreach ($order->orderDetails as $storedOrderDetail) {
                        $storedOrderDetail->append('spec_fields');
                        $newOrderDetails->push($storedOrderDetail);
                    }

                    $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($invoice->items);

                    $taxValues = $this->getTaxValuesFromDetails($store, $order->orderDetails);
                    $invoice->noTaxSubtotal = $taxValues['no_tax_subtotal'];
                    $invoice->productTaxes = $taxValues['product_taxes'];

                    if (config('app.slave')) {
                        $pendingSyncing = new PendingSync();
                        $pendingSyncing->store_id = $store->id;
                        $pendingSyncing->syncing_id = $order->id;
                        $pendingSyncing->type = "order";
                        $pendingSyncing->action = "insert";
                        $pendingSyncing->save();
                    }

                    
                    
                    $spot = Spot::find($order->spot_id);

                    // Reemplazar a la mesa fija de kiosko (por reportes)
                    if ($spot->isKioskTmp()) {
                        $kioskSpot = Spot::getKioskSpot($store->id);
                        $order->spot_id = $kioskSpot->id;
                        $order->save();
                        
                        // Borrar la mesa
                        event(new SpotDeleted($spot->toArray()));
                        $spot->delete();

                        $spot = $kioskSpot;
                    }

                    // Crear orden integration o actualizar info si viene de una mesa de externos integración
                    if (
                        !$spot->isNormal()
                        && !$spot->isSplit()
                    ) {
                        $existOrderIntegration = OrderIntegrationDetail::where(
                            'order_id',
                            $order->id
                        )->first();
                        if ($existOrderIntegration != null) {
                            $detailsCollection = collect($order->orderDetails);
                            $orderDetailsGroup = $detailsCollection->groupBy('compound_key')->toArray();
                            $existOrderIntegration->number_items = count($orderDetailsGroup);
                            $existOrderIntegration->value = $order->total;
                            $existOrderIntegration->save();
                        } else {
                            $integrationName = Spot::getNameIntegrationByOrigin($spot->origin);

                            if ($integrationName == "") {
                                throw new \Exception("No existe esta mesa de servicio externo");
                            }
                            $orderIntegration = new OrderIntegrationDetail();
                            $orderIntegration->order_id = $order->id;
                            $orderIntegration->integration_name = $integrationName;
                            $orderIntegration->external_order_id = $request->external_order_id;
                            $orderIntegration->number_items = 1;
                            $orderIntegration->value = $order->total;
                            $orderIntegration->save();

                            // Mesas de integración va siempre a crédito
                            $order->change_value = null;

                            $payment = new Payment();
                            $payment->created_at = $now;
                            $payment->updated_at = $now;
                            $payment->total = $order->total;
                            $payment->order_id = $order->id;
                            $payment->type = $spot->isRappiAntojo()
                                ? PaymentType::CASH
                                : PaymentType::CREDIT;

                            $order->save();
                            $payment->save();
                        }
                    } else {
                        foreach ($request->payments as $paymentObject) {
                            $payment = new Payment();
                            $payment->created_at = $now;
                            $payment->updated_at = $now;
                            $payment->total = is_array($paymentObject)
                                ? $paymentObject["total"]
                                : $paymentObject->total;

                            $payment->type = is_array($paymentObject)
                                ? $paymentObject["type"] ?? null
                                : $paymentObject->type;

                            $payment->card_last_digits = is_array($paymentObject)
                                ? $paymentObject["card_last_digits"] ?? null
                                : $paymentObject->card_last_digits;
                            $payment->order_id = $order->id;
                            $payment->card_id = is_array($paymentObject)
                                ? $paymentObject["card_id"] ?? null
                                : $paymentObject->card_id;
                            $payment->save();
                        }
                    }

                    // PrintServiceHelper::printInvoice($invoice, $employee);
                    PrintServiceHelper::printComanda($order, $employee);
                    $invoiceCollection = collect($invoice);
                    $invoiceCollection->forget('items');
                    $invoiceCollection->put('items', $detailsGrouped);
                    
                    event(new OrderCreated($order->id));
                    event(new CompanyOrderCreatedEvent($order));

                    return response()->json([
                        "status" => "Orden creada con éxito",
                        "results" => $newOrderDetails,
                        "identifier" => $order->identifier,
                        "invoice" => $invoiceCollection
                    ], 200);
                }
            );
            // dd($orderJSON);
        } catch (\Exception $e) {

            $this->logError(
                "OrderController API V2 createOrderFromSplitAccount: ERROR GUARDAR ORDEN, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );

            return response()->json([
                'status' => 'No se pudo crear la orden',
                'results' => "null"
            ], 409);
        }
    }
}