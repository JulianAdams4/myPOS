<?php

namespace App\Http\Controllers\API\V2;

use App\Http\Controllers\Controller;
use App\Order;
use App\OrderDetail;
use App\OrderProductSpecification;
use App\Employee;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\Payment;
use App\PaymentType;
use App\ProductDetail;
use App\Store;
use App\Billing;
use App\Invoice;
use App\CashierBalance;
use App\ExpensesBalance;
use App\ComponentCategory;
use App\ComponentStock;
use App\Component;
use App\Traits\PushNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Helper;
use Log;
use App\Traits\ValidateToken;
use Illuminate\Support\Facades\DB;
use App\Jobs\Datil\IssueInvoiceDatil;
use App\StoreIntegrationToken;
use App\Traits\AuthTrait;
use App\Traits\CashierBalanceHelper;
use Illuminate\Support\Facades\Mail;
use App\Mail\CloseDayHIPOSummary;
use App\AdminStore;

class SlaveServerController extends Controller
{
    use ValidateToken, PushNotification;
    use AuthTrait, CashierBalanceHelper;
    public $pusher;

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


    public function createOrderFromSlave(Request $request)
    {
        $store = $this->authStore;
        if (!$store) {
            return response()->json(
                [
                    'status' => 'Store not found',
                    'results' => "null"
                ],
                404
            );
        }
        Log::info("Store Admin doing request: ");
        $cashierBalance = CashierBalance::find($request->cashier_balance_id);
        if (!$cashierBalance) {
            Log::info("CashierBalance non existent for SlaveSync with ID: ".$request->cashier_balance_id);
            return response()->json(
                [
                    'status' => 'No existe la caja de la orden',
                    'results' => "null"
                ],
                409
            );
        }
        $employee = Employee::find($cashierBalance->employee_id_open);
        try {
            $orderJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    $now = Carbon::now()->toDateTimeString();

                    $cardType = 0;
                    if (!$request->cash) {
                        if ($request->debit_card) {
                            $cardType = 2;
                        } else {
                            $cardType = 1;
                        }
                    }
                    $valueCash = null;
                    if ($request->cash == 1 && $request->value_cash != 0) {
                        $valueCash = $request->value_cash;
                    }
                    $valueDebitCard = null;
                    if ($request->value_debit_card != null && $request->value_debit_card != 0) {
                        $valueDebitCard = $request->value_debit_card;
                    }
                    $valueCreditCard = null;
                    if ($request->value_credit_card != null && $request->value_credit_card != 0) {
                        $valueCreditCard = $request->value_credit_card;
                    }
                    $order = Order::create(
                        array_merge(
                            $request->all(),
                            [
                                'employee_id' => $employee->id,
                                'store_id' => $store->id
                            ]
                        )
                    );
                    if (!$order) {
                        return response()->json(
                            [
                                'status' => 'No se pudo crear la orden',
                                'results' => "null"
                            ],
                            409
                        );
                    }
                    if ($request->order_details) {
                        $orderDetails = $request->order_details;
                        foreach ($orderDetails as $orderDetail) {
                            $instructionsForDetail = "";
                            if ($orderDetail['instruction'] != null) {
                                $instructionsForDetail = $orderDetail['instruction'];
                            }
                            if (isset(($orderDetail["created_at"]))) {
                                $orderDetailCreatedAt = $orderDetail["created_at"];
                            } else {
                                $orderDetailCreatedAt = Carbon::now();
                            }
                            $orderDetailCompoundKey = null;
                            if (isset(($orderDetail['compound_key']))) {
                                $orderDetailCompoundKey = $orderDetail['compound_key'];
                            }
                            $orderDetailCreated = OrderDetail::create(
                                [
                                    'product_detail_id' => $orderDetail['product_detail']['id'],
                                    'quantity' => $orderDetail['quantity'],
                                    'name_product' => $orderDetail['name_product'],
                                    'value' => $orderDetail['value'],
                                    'invoice_name' => $orderDetail['invoice_name'],
                                    'created_at' => $orderDetailCreatedAt,
                                    'order_id' => $order->id,
                                    'instruction' => $instructionsForDetail,
                                    'compound_key' => $orderDetailCompoundKey
                                ]
                            );
                            if ($orderDetailCreated) {
                                foreach ($orderDetail['order_specifications'] as $orderProdSpec) {
                                    if (isset(($orderProdSpec["created_at"]))) {
                                        $orderProductSpecCreatedAt = $orderProdSpec["created_at"];
                                    } else {
                                        $orderProductSpecCreatedAt = Carbon::now();
                                    }
                                    OrderProductSpecification::create(
                                        [
                                        'specification_id' => $orderProdSpec['specification_id'],
                                        'name_specification' => $orderProdSpec['name_specification'],
                                        'value' => $orderProdSpec['value'],
                                        'order_detail_id' => $orderDetailCreated->id,
                                        'quantity' => $orderProdSpec['quantity'],
                                        'created_at' => $orderProductSpecCreatedAt
                                        ]
                                    );
                                }
                            }
                        }
                    }
                    if ($request->has_billing) {
                        $billing = Billing::where('document', $request->billing['document'])->first();
                        if ($billing) {
                            $billing->name = $request->billing['name'];
                            $billing->address = $request->billing['address'] ? $request->billing['address'] : $billing->address;
                            $billing->phone = $request->billing['phone'] ? $request->billing['phone'] : $billing->phone;
                            $billing->email = $request->billing['email'] ? $request->billing['email'] : $billing->email;
                            $billing->save();
                        } else {
                            $billing = new Billing();
                            $billing->document = $request->billing['document'];
                            $billing->name = $request->billing['name'];
                            $billing->address = $request->billing['address'];
                            $billing->phone = $request->billing['phone'];
                            $billing->email = $request->billing['email'];
                            $billing->save();
                        }
                        $order->billing_id = $billing->id;
                        $order->save();
                    } else {
                        $billing = Billing::firstOrCreate(
                            [
                            'document' => '9999999999999',
                            'name'     => 'CONSUMIDOR FINAL'
                            ]
                        );
                    }

                    $order = $this->calculateOrderValues($order);

                    if ($valueCash) {
                        $cashPayment = new Payment();
                        $cashPayment->total = $valueCash;
                        $cashPayment->type = PaymentType::CASH;
                        $cashPayment->created_at = $now;
                        $cashPayment->updated_at = $now;
                        $cashPayment->order_id = $order->id;
                        $cashPayment->save();
                    }

                    if ($valueDebitCard) {
                        $debitPayment = new Payment();
                        $debitPayment->total = $valueDebitCard;
                        $debitPayment->type = PaymentType::DEBIT;
                        $debitPayment->created_at = $now;
                        $debitPayment->updated_at = $now;
                        $debitPayment->order_id = $order->id;
                        $debitPayment->save();
                    }

                    if ($valueCreditCard) {
                        $creditPayment = new Payment();
                        $creditPayment->total = $valueCreditCard;
                        $creditPayment->type = PaymentType::CREDIT;
                        $creditPayment->created_at = $now;
                        $creditPayment->updated_at = $now;
                        $creditPayment->order_id = $order->id;
                        $creditPayment->save();
                    }

                    $invoice = new Invoice();
                    $invoice->order_id = $order->id;
                    $invoice->billing_id = $billing->id;
                    $invoice->status = "Pagado";
                    $invoice->document = $billing->document;
                    $invoice->name = $billing->name;
                    $invoice->address = $billing->address;
                    $invoice->phone = $billing->phone;
                    $invoice->email = $billing->email;
                    $invoice->subtotal = $order->base_value;
                    $invoice->tax = $order->total - $order->base_value;
                    $invoice->total = $order->total;
                    $invoice->food_service = $request->food_service;
                    $invoice->save();
                    foreach ($order->taxDetails as $taxDetail) {
                        $invoiceTaxDetail = new InvoiceTaxDetail();
                        $invoiceTaxDetail->invoice_id = $invoice->id;
                        $invoiceTaxDetail->tax_name = $taxDetail->storeTax->name.' ('.$taxDetail->storeTax->percentage.'%)';
                        $invoiceTaxDetail->tax_percentage = $taxDetail->storeTax->percentage;
                        $invoiceTaxDetail->subtotal = $taxDetail->subtotal;
                        $invoiceTaxDetail->save();
                    }
                    foreach ($order->orderDetails as $orderDetail) {
                        try {
                            $invoiceItem = new InvoiceItem();
                            $invoiceItem->invoice_id = $invoice->id;
                            $invoiceItem->product_name = $orderDetail->invoice_name;
                            $invoiceItem->quantity = $orderDetail->quantity;
                            $invoiceItem->base_value = $orderDetail->base_value;
                            $invoiceItem->total = $orderDetail->total;
                            $invoiceItem->has_iva = $orderDetail->tax_values['has_iva'];
                            $invoiceItem->compound_key = $orderDetail->compound_key;
                            $invoiceItem->order_detail_id = $orderDetail->id;
                            $invoiceItem->save();
                        } catch (\Exception $e) {
                            Log::info("SlaveServerController Web createOrderFromSlave: NO SE PUDO GUARDAR EL ITEM DE INVOICE");
                            Log::info($e->getMessage());
                            Log::info("Archivo");
                            Log::info($e->getFile());
                            Log::info("Línea");
                            Log::info($e->getLine());
                            Log::info("Provocado por");
                            Log::info(json_encode($orderDetail));
                        }
                    }

                    $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');

                    if ($request->has_billing) {
                        Log::info("BEFORE datil");
                        $this->prepareToSendForElectronicBilling($store, $invoice);
                    }
                    $this->reduceComponentsStock($order);
                    $this->reduceComponentsStockBySpecification($order);

                    $detailsGrouped = Helper::getDetailsUniqueGroupedByCompoundKey($invoice->items);
                    $invoiceCollection = collect($invoice);
                    $invoiceCollection->forget('items');
                    $invoiceCollection->put('items', $detailsGrouped);

                    return response()->json(
                        [
                            "status" => "Orden creada con éxito",
                            "results" => $order->id,
                        ],
                        200
                    );
                }
            );
            return $orderJSON;
        } catch (\Exception $e) {
            Log::info("SlaveServerController API V2 createOrderFromSlave: NO SE PUDO GUARDAR LA ORDEN");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            // Log::info("Provocado por");
            // Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'Ocurrio un error al crear la orden',
                    'results' => "null"
                ],
                409
            );
        }
    }

    /*
    issueInvoiceDatil
    Issue Invoice in Datil
    Envia una factura electronica por medio de Datil.
    */
    public function issueInvoiceDatil(Store $store, Invoice $invoice, int $issuanceType)
    {
        Log::info('posting to Datil');
        dispatch(new IssueInvoiceDatil($store, $invoice, $issuanceType));
    }

    /*
    prepareToSendForElectronicBilling
    Encuentra el token del store para las integraciones de facturacion electronica.
    Integraciones: Datil,
    */
    public function prepareToSendForElectronicBilling(Store $store, Invoice $invoice)
    {
        // Log::info('Searching Electronic Billing Integration');
        $integration = StoreIntegrationToken::where("store_id", $store->id)->where('type', "billing")->first();
        if ($integration) {
            switch ($integration->integration_name) {
                case "datil":
                    $this->issueInvoiceDatil($store, $invoice, 1);
                    break;
                default:
                    Log::info("No tiene integracion de facturacion electronica");
                    break;
            }
        }
    }

    public function createComponentCategoryFromSlave(Request $request)
    {
        try {
            $componentCategory = new ComponentCategory();
            $componentCategory->name = $request->name;
            $componentCategory->search_string = $request->search_string;
            $componentCategory->status = $request->status;
            $componentCategory->priority = $request->priority;
            $componentCategory->company_id = $request->company_id;
            $componentCategory->created_at = $request->created_at;
            $componentCategory->updated_at = $request->updated_at;
            $componentCategory->save();
            if (!$componentCategory) {
                return response()->json(
                    [
                        'status' => 'No se pudo crear la categoría',
                        'results' => null
                    ],
                    409
                );
            }
            return response()->json(
                [
                    'status' => 'Categoría creada exitosamente',
                    'results' => $componentCategory->id
                ],
                200
            );
        } catch (\Exception $e) {
            Log::info("SlaveServerController CreateComponentCategory: Error al guardar la categoria");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'No se pudo crear la Categoria',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function openCashierBalanceSlave(Request $request)
    {
        try {
            if ($request->observation == null) {
                $request->observation = "";
            }
            $cashierBalance = new CashierBalance();
            $cashierBalance->employee_id_open = $request->employee_id_open;
            $cashierBalance->employee_id_close = $request->employee_id_close;
            $cashierBalance->date_open = $request->date_open;
            $cashierBalance->hour_open = $request->hour_open;
            $cashierBalance->date_close = $request->date_close;
            $cashierBalance->hour_close = $request->hour_close;
            $cashierBalance->value_previous_close = $request->value_previous_close;
            $cashierBalance->value_open = $request->value_open;
            $cashierBalance->value_close = $request->value_close;
            $cashierBalance->created_at = $request->created_at;
            $cashierBalance->updated_at = $request->updated_at;
            $cashierBalance->store_id = $request->store_id;
            $cashierBalance->observation = $request->observation;
            $cashierBalance->save();
            if (!$cashierBalance) {
                return response()->json(
                    [
                        'status' => 'No se pudo aperturar caja',
                        'results' => null
                    ],
                    409
                );
            }
            return response()->json(
                [
                    'status' => 'Apertura de caja correcta',
                    'results' => $cashierBalance->id
                ],
                200
            );
        } catch (\Exception $e) {
            Log::info("SlaveServerController open cashier balance: ERROR AL GUARDAR CAJA ABIERTA");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'No se pudo abrir caja',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function closeCashierBalanceSlave(Request $request)
    {
        $store = $this->authStore;
        $balance = $request->balance;
        try {
            if ($balance['observation'] == null) {
                $balance['observation'] = "";
            }
            $cashierBalance = CashierBalance::find($balance['synced_id']);
            if (!$cashierBalance) {
                return response()->json(
                    [
                        'status' => 'No se pudo cerrar caja',
                        'results' => null
                    ],
                    409
                );
            }

            $cashierBalance->employee_id_close = $balance['employee_id_close'];
            $cashierBalance->hour_close = $balance['hour_close'];
            $cashierBalance->value_close = $balance['value_close'];
            $cashierBalance->date_close = $balance['date_close'];
            $cashierBalance->updated_at = $balance['updated_at'];
            $cashierBalance->save();
            $expensesCollection = collect([]);
            foreach ($request->expenses as $expense) {
                if ($expense['value'] != 0) {
                    $newExpense = new ExpensesBalance();
                    $newExpense->cashier_balance_id = $cashierBalance->id;
                    $newExpense->name = $expense['name'];
                    $newExpense->value = $expense['value'];
                    $newExpense->save();

                    $collectionExpense = [
                        'formatValue' => $newExpense->value/100,
                        'value' => $newExpense->value,
                        'name' => $newExpense->name,
                        'id' => $newExpense->id
                    ];

                    $expensesCollection->push($collectionExpense);
                }
            }

            $finalValues = $this->getValuesCashierBalance($cashierBalance->id);
            $valueIndexClose =  floatval($finalValues['close']);
            $valueIndexExternalValues = floatval($finalValues['external_values']);
            $valueIndexCard = floatval($finalValues['card']);
            $valueIndexTransfer = floatval($finalValues['transfer']);
            $valueIndexRappiPay = floatval($finalValues['rappi_pay']);
            $valueIndexOthers = floatval($finalValues['others']);
            $valueIndexCardTip = floatval($finalValues['card_tips']);
            $valueSales = $valueIndexClose + $valueIndexCard + $valueIndexExternalValues + $valueIndexRappiPay;
            $valueClose = $cashierBalance->value_open + $cashierBalance->value_close;
            $mailData = [
                'value_open' => round($cashierBalance->value_open/100, 2),
                'value_cash' => round($valueIndexClose/100, 2),
                'value_sales' => round($valueSales/100, 2),
                'value_close' => round($valueClose/100, 2),
                'value_card' => round($valueIndexCard/100, 2),
                'value_transfer' => round($valueIndexTransfer/100, 2),
                'value_rappi_pay' => round($valueIndexRappiPay/100, 2),
                'value_others' => round($valueIndexOthers/100, 2),
                'value_card_tips' => round($valueIndexCardTip/100, 2),
                'date_close' => $cashierBalance->date_close,
                'hour_close' => $cashierBalance->hour_close,
                'expenses' => $expensesCollection,
                'externalValues' => $valueIndexExternalValues,
            ];

            $mail = AdminStore::where('store_id', $store->id)->first();
            if (!$mail || config('app.env') === 'local') {
                $email = config('app.mail_development');
            } else {
                $email = $mail->email;
            }

            try {
                Mail::to($email)
                ->send(new CloseDayHIPOSummary($store, $mailData));
            } catch (\Exception $e) {
                Log::info('Se capturo el ERROR');
                Log::info($e);
            }

            return response()->json(
                [
                    'status' => 'Cierre de caja correcta',
                    'results' => $cashierBalance->id
                ],
                200
            );
        } catch (\Exception $e) {
            Log::info("SlaveServerController close cashier balance: ERROR AL GUARDAR CIERRE DE CAJA");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'No se pudo cerrar caja',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function createHistoricalInventorySlave(Request $request)
    {
        return response()->json(
            [
                'status' => 'Ingreso de inventario correcto',
                'results' => null
            ],
            200
        );
    }

    public function deleteComponentCategoryRequestedSlave(Request $request)
    {
        $storeAdmin = $this->authUser;
        //// TODO:
        /// $store = $storeAdmin->store;
        // $store = $employee->store;
        // if($store){
        //     try{
        //         $category = ComponentCategory::find($request->id);
        //         if($category){
        //             $category->delete();
        //             return response()->json(
        //                 [
        //                     'status' => 'Categoría eliminada exitosamente',
        //                     'results' => "OK"
        //                 ], 200
        //             );
        //         }
        //     }catch(\Exception $e){
        //         Log::info("OrderController API V2 DeleteComponentCategory: Error al eliminar la categoria");
        //         Log::info($e);
        //     }
        //     return response()->json(
        //         [
        //             'status' => 'No se pudo eliminar la Categoria',
        //             'results' => "null"
        //         ], 409
        //     );
        // }else{
        return response()->json(
            [
                'status' => 'Error al acceder el servicio',
                'results' => "null"
            ],
            409
        );
        // }
    }

    public function getProductsFromStore(Request $request)
    {
        $store = $this->authStore;
        $productDetails = ProductDetail::where('store_id', $store->id)
            ->with(['product'])
            ->with('product.taxes', 'product.specifications.specificationCategory')
            ->get();
        if (!$productDetails) {
            return response()->json(
                [
                    'status' => 'No se pudieron obtener los productos del local',
                    'results' => "null"
                ],
                400
            );
        }
        $productDetails->each(
            function ($i, $k) {
                $i->makeVisible(['priority', 'created_at']);
            }
        );

        $componentVariations = Component::with(['componentStocks', 'category', 'unit'])
        ->whereHas(
            'componentStocks',
            function ($q) use ($store) {
                $q->where('store_id', $store->id);
            }
        )
        ->get();

        $response = array(
            'products_details' => $productDetails,
            'variations' => $componentVariations
        );
        return response()->json(
            [
                'status' => 'Productos encontrados',
                'results' => $response
            ],
            200
        );
    }
}
