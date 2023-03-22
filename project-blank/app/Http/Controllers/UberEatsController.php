<?php

namespace App\Http\Controllers;

use App\Company;
use App\Customer;
use App\FirebaseToken;
use App\Http\Controllers\Controller;
use App\User;
use App\Spot;
use App\Employee;
use App\Product;
use App\ProductDetail;
use App\OrderDetailProcessStatus;
use App\CashierBalance;
use App\Order;
use App\OrderDetail;
use App\StoreIntegrationToken;
use App\AdminStore;
use App\StoreConfig;
use App\Store;
use App\OrderIntegrationDetail;
use App\Billing;
use App\Invoice;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\SpecificationCategory;
use App\Specification;
use App\OrderProductSpecification;
use App\SectionDiscount;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Log;
use Socialite;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;
use Illuminate\Support\Facades\DB;
use App\Helper;
use App\Traits\OrderHelper;
use App\Traits\PushNotification;
use App\Events\OrderUpdatedComanda;
use App\Events\IntegrationOrderCreated;
use App\Events\HubIntegrationOrderCreated;
use App\Helpers\PrintService\PrintServiceHelper;
use App\AvailableMyposIntegration;
use App\ToppingIntegrationDetail;
use App\ProductIntegrationDetail;
use App\ProductToppingIntegration;
use App\Traits\LoggingHelper;
use App\Events\OrderCreated;
use App\Events\SimpleOrderCreated;
use App\Events\SimpleOrderFailed;
use App\PaymentType;
use App\Payment;
use App\PaymentIntegrationDetail;

class UberEatsController extends Controller
{

    use OrderHelper, PushNotification, IntegrationsHelper, LoggingHelper;

    public function receiveWebhookUberOrder(Request $request)
    {
        Log::info("receiveWebhookUberOrder V1");
        // Log::info(json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $bodyRequest = $request->getContent();
        $headerSignatureRequest = $request->header('x-uber-signature');
        $secret = config('app.eats_client_secret');
        $signature = hash_hmac('SHA256', $bodyRequest, $secret);
        try {
            if ($headerSignatureRequest !== null) {
                if (hash_equals($headerSignatureRequest, $signature)) {
                    $this->storeOrder($bodyRequest);
                } else {
                    throw new \Exception("La firma del request no coincide");
                }
            } else {
                throw new \Exception("La firma del request es null");
            }
            return response()->json(
                [],
                200
            );
        } catch (\Exception $e) {
            Log::info("UberEatsController Web receiveWebhookUberOrder: NO SE PUDO GUARDAR LA ORDER DE UBER EATS");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request));
        }
    }

    public function getEmployeeUberEatsIntegration($storeId)
    {
        $employee = Employee::where('store_id', $storeId)
                        ->where('name', "Integración")
                        ->first();
        if (!$employee) {
            $store = Store::where('id', $storeId)->first();
            $nameStoreStripped = str_replace(' ', '', $store->name);
            $employee = new Employee();
            $employee->name = "Integración";
            $employee->store_id = $storeId;
            $employee->email = 'integracion@' .strtolower($nameStoreStripped). '.com';
            $employee->password = '$2y$10$XBl3VT7NVYSDHnGJVRmlnumOv3jDjZKhfidkcss8GeWt0NIYwFU42';
            $employee->type_employee = 3;
            $employee->save();
        }
        return $employee;
    }

    public function storeOrder($information)
    {
        $informationObj = json_decode($information);
        $eatsStoreId = $informationObj->meta->user_id;
        $eatsOrderId = $informationObj->meta->resource_id;
        Log::info("Uber Eats Store and Order ids");
        Log::info($eatsStoreId);
        Log::info($eatsOrderId);
        $storeConfig = StoreConfig::with(['store' => function ($store) {
            $store->with('currentCashierBalance', 'eatsSpot', 'hubs');
        }])->where('eats_store_id', (string) $eatsStoreId)->first();
        if ($storeConfig === null) {
            Log::info("Esta tienda no tiene store config con ese id de store de uber eats");
            return;
        }

        Log::info("Webhook V1 ignorado para store_id: " . $storeConfig->store_id);
        return response()->json([], 204);

        Log::info("Orden de uber para la tienda: " . $storeConfig->store_id);

        $integration = StoreIntegrationToken::where('store_id', $storeConfig->store_id)
                            ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                            ->where('type', 'delivery')
                            ->first();

        if ($integration === null) {
            Log::info("Esta tienda no tiene token de uber eats");
            return;
        }

        $baseUrl = config('app.eats_url_api');
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $orderUber = '';

        try {
            $response = $browser->get(
                $informationObj->resource_href,
                [
                    'User-Agent' => 'Buzz',
                    'Authorization' => 'Bearer ' . $integration->token,
                    'Content-Type' => 'application/json'
                ]
            );

            if ($response->getStatusCode() !== 200) {
                Log::info('Error al obtener la info de la orden de uber eats');
                Log::info($response->getStatusCode());
                Log::info($response->getBody()->__toString());
                throw new \Exception("No se pudo obtener la información de la orden en Uber Eats");
            }

            $orderUber = $response->getBody()->__toString();
            $bodyJSON = json_decode($orderUber, true);

            Log::info($bodyJSON["order_num"]);
            Log::info($bodyJSON["eater_info"]["first_name"]);

            $orderJSON = DB::transaction(
                function () use (
                    $bodyJSON,
                    $storeConfig,
                    $eatsOrderId,
                    $baseUrl,
                    $integration,
                    $browser,
                    $eatsStoreId
                ) {
                    if (!$storeConfig->store) {
                        throw new \Exception("No hay tienda configurada en myPOS con el ID de Uber Eats");
                    }
                    $timestamp = substr($bodyJSON["created_time"], 0, 10);
                    $cashierBalance = $storeConfig->store->currentCashierBalance;
                    if (!$cashierBalance) {
                        throw new \Exception('No está abierta la tienda');
                    }
                    // Buscar employee de integración de ese store
                    $employee = $this->getEmployeeUberEatsIntegration($storeConfig->store_id);
                    $deliverySpot = $storeConfig->store->eatsSpot;
                    $orderIntegrationExist = OrderIntegrationDetail::where(
                        'external_order_id',
                        $eatsOrderId
                    )->where('order_number', $bodyJSON["order_num"])
                    ->first();
                    if ($orderIntegrationExist != null) {
                        // Enviando Respuesta OK a Uber Eats de que se creó la orden en myPOS
                        $response3 = $browser->post(
                            $baseUrl . 'v1/eats/orders/'. (string) $eatsOrderId .'/accept_pos_order',
                            [
                                'User-Agent' => 'Buzz',
                                'Authorization' => 'Bearer ' . $integration->token,
                                'Content-Type' => 'application/json'
                            ],
                            '{ "reason": "Orden creada en myPOS" }'
                        );
                        if ($response3->getStatusCode() !== 204) {
                            Log::info("No se pudo enviar la aceptación del pedido");
                            Log::info($response3->getStatusCode());
                            throw new \Exception('No se pudo enviar la aceptación del pedido');
                        } else {
                            Log::info("Response a uber enviado exitosamente");
                        }
                        return;
                    } else {
                        if ($deliverySpot === null) {
                            $deliverySpot = new Spot();
                            $deliverySpot->name = "Uber Eats";
                            $deliverySpot->store_id = $storeConfig->store_id;
                            $deliverySpot->origin = Spot::ORIGIN_EATS;
                            $deliverySpot->save();
                        }
                        $order = new Order();
                        $order->store_id = $storeConfig->store_id;
                        $order->spot_id = $deliverySpot->id;
                        $orderValue = 0;
                        foreach ($bodyJSON["charges"] as $charge) {
                            if ($charge["charge_type"] === "subtotal") {
                                $orderValue += ((int) $charge["price"]);
                            }
                        }
                        $order->order_value = $orderValue;
                        $order->current_status = 'Creada';
                        $order->status = 1;
                        $order->employee_id = $employee->id;
                        $datetimeFormat = 'Y-m-d H:i:s';
                        $date = new \DateTime();
                        $date->setTimestamp($timestamp);
                        $dateOrder = $date->format($datetimeFormat);
                        $order->created_at = $dateOrder;
                        $order->updated_at = $dateOrder;
                        $order->cash = 0;
                        $order->identifier = Helper::getNextOrderIdentifier($storeConfig->store_id);
                        $order->preorder = 0;
                        $order->cashier_balance_id = $cashierBalance->id;
                        $order->total = $orderValue / 100;
                        $order->base_value = $orderValue / 100;
                        $order->food_service = 0;
                        $order->discount_percentage = 0;
                        $order->discount_value = 0;
                        $order->undiscounted_base_value = $orderValue / 100;
                        $order->change_value = 0;
                        $order->no_tax_subtotal = 0.00;
                        $order->save();
                        $orderIntegration = new OrderIntegrationDetail();
                        $orderIntegration->order_id = $order->id;
                        $orderIntegration->integration_name = AvailableMyposIntegration::NAME_EATS;
                        $orderIntegration->external_order_id = $eatsOrderId;
                        $orderIntegration->external_store_id = $eatsStoreId;
                        $orderIntegration->number_items = count($bodyJSON["order_items"]);
                        $orderIntegration->value = $orderValue;
                        $orderIntegration->customer_name = $bodyJSON["eater_info"]["first_name"];
                        $orderIntegration->order_number = $bodyJSON["order_num"];
                        $orderIntegration->save();

                        $payment = new Payment();
                        $payment->total = $orderValue / 100;
                        $payment->order_id = $order->id;
                        $payment->type = PaymentType::CREDIT;
                        $payment->save();

                        // $instructionsOrderDetail = "";
                        // if ($request->instruction != null) {
                        //     $instructionsOrderDetail = $request->instruction;
                        // }

                        $menus = [];
                        $discountUsed = false;
                        // Guardando los orderDetails de la orden
                        foreach ($bodyJSON["order_items"] as $orderItem) {
                            if (!isset($orderItem["external_data"])) {
                                throw new \Exception("Este producto no tiene referencia a un producto en myPOS");
                            }
                            $product = Product::where('id', $orderItem["external_data"])->first();
                            if ($product === null) {
                                throw new \Exception(
                                    "Este producto no tiene ninguna referencia a un producto válido en myPOS"
                                );
                            }
                            $productDetail = ProductDetail::where('product_id', $product->id)->first();
                            if ($productDetail === null) {
                                throw new \Exception(
                                    "Este producto no tiene ninguna referencia a un producto válido en myPOS"
                                );
                            }

                            $section = $product->category->section;
                            if ($section == null) {
                                throw new \Exception(
                                    "No se ha encontrado el menú de uber eats para esta tienda"
                                );
                            }
                            if (!in_array($section->id, $menus)) {
                                array_push($menus, $section->id);
                            }

                            $orderDetail = new OrderDetail();
                            $orderDetail->order_id = $order->id;
                            $orderDetail->product_detail_id = $productDetail->id;
                            $orderDetail->quantity = $orderItem["quantity"];
                            $orderDetail->status = 1;
                            $orderDetail->created_at = $dateOrder;
                            $orderDetail->updated_at = $dateOrder;
                            $orderDetail->value = (int) $orderItem["price"];
                            $orderDetail->name_product = $product->name;
                            $orderDetail->instruction = "";
                            $orderDetail->invoice_name = $product->invoice_name;
                            $orderDetail->total = (int) $orderItem["price"];
                            $orderDetail->base_value = ((int) $orderItem["price"]) / 100;
                            $orderDetail->compound_key = strval($productDetail->id);
                            $orderDetail->save();
                            $processStatus = new OrderDetailProcessStatus();
                            $processStatus->process_status = 1;
                            $processStatus->order_detail_id = $orderDetail->id;
                            $processStatus->save();
                            $totalOrderDetailValue = (int) $orderItem["price"];

                            // Crear OrderProductSpecification
                            $specificationIdsQuantity = collect([]);
                            if (isset($orderItem["selected_options"])) {
                                foreach ($orderItem["selected_options"] as $specificationEats) {
                                    if (!isset($specificationEats["external_data"])) {
                                        throw new \Exception("Esta opción para el producto no tiene referencia en myPOS");
                                    }
                                    // Verificando que existe la integración del producto
                                    $productInt = ProductIntegrationDetail::where("product_id", $product->id)
                                        ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                                        ->first();
                                    // Verificando que existe la especificación
                                    $specification = Specification::where(
                                        'id',
                                        $specificationEats["external_data"]
                                    )
                                    ->first();
                                    if (!$specification) {
                                        throw new \Exception("Esta especificación no existe en myPOS");
                                    }
                                    // Verificando que existe la integración de la especificación
                                    $specificationInt = ToppingIntegrationDetail::where(
                                        "specification_id",
                                        $specification->id
                                    )
                                    ->where("integration_name", AvailableMyposIntegration::NAME_EATS)
                                    ->first();
                                    if ($productInt == null || $specificationInt == null) {
                                        throw new \Exception(
                                            "Este producto o especificación no tiene integración Uber Eats"
                                        );
                                    }
                                    // Verificando que existe una relación de integración
                                    // entre ese producto y la especificación
                                    $productSpecificationInt = ProductToppingIntegration::where(
                                        "product_integration_id",
                                        $productInt->id
                                    )
                                    ->where("topping_integration_id", $specificationInt->id)
                                    ->first();
                                    if (!$productSpecificationInt) {
                                        throw new \Exception(
                                            "No hay relación entre el producto y la especificación enviada"
                                        );
                                    }
                                    OrderProductSpecification::create(
                                        [
                                        'specification_id' => $specification->id,
                                        'name_specification' => $specificationInt->name,
                                        'value' => $productSpecificationInt->value,
                                        'order_detail_id' => $orderDetail->id,
                                        'quantity' => $specificationEats["quantity"],
                                        ]
                                    );
                                    // Sumando el valor del order detail con esta especificación
                                    $totalOrderDetailValue += (int) $specificationEats["price"];
                                    $specIdQuantity = [
                                        'id' => $specification->id,
                                        'quantity' => $specificationEats["quantity"]
                                    ];
                                    $specificationIdsQuantity->push($specIdQuantity);
                                }
                                // Asignando el valor final del order detail
                                $orderDetail->value = (int) $totalOrderDetailValue;
                                $sortedById = $specificationIdsQuantity->sortBy('id');
                                $compoundKey = strval($productDetail->id);
                                foreach ($sortedById as $specInfo) {
                                    $compoundKey = $compoundKey . '_' . strval($specInfo['id']) . '_' .
                                        strval($specInfo['quantity']);
                                }
                                $orderDetail->compound_key = $compoundKey;
                                $orderDetail->save();
                            }
                        }
                        $order = $this->calculateOrderValuesIntegration($order);

                        // Aplicando descuentos
                        if (count($menus) > 0 && !$discountUsed) {
                            foreach ($menus as $menuId) {
                                $discounts = SectionDiscount::where('section_id', $menuId)
                                    ->orderBy('base_value', 'DESC')
                                    ->get();
                                if (count($discounts) > 0) {
                                    foreach ($discounts as $discount) {
                                        if ($orderValue >= $discount->base_value && !$discountUsed) {
                                            $valueDiscounted = $orderValue - $discount->discount_value;
                                            $order->order_value = $valueDiscounted;
                                            $order->total = $valueDiscounted;
                                            $order->base_value = $valueDiscounted;
                                            $order->undiscounted_base_value = $valueDiscounted;
                                            $order->save();
                                            $orderIntegration->value = $valueDiscounted;
                                            $orderIntegration->save();
                                            $discountUsed = true;
                                        }
                                    }
                                }
                            }
                        }

                        $billing = Billing::firstOrCreate(
                            [
                                'document' => '9999999999999',
                                'name'     => 'CONSUMIDOR FINAL'
                            ]
                        );
                        $invoiceNumber = Helper::getNextBillingOfficialNumber($storeConfig->store_id, true);
                        /// Si maneja alternate Billing Sequence, se usa el official bill_sequence dentro
                        ///// de store. Para esto el switch debe ser false (para no usar el alternate)
                        // $alternateBill = Helper::getAlternatingBillingNumber($storeConfig->store_id, false);
                        // if ($alternateBill != "") {
                        //     $invoiceNumber = $alternateBill;
                        // }
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
                        $invoice->food_service = 0;
                        $invoice->discount_percentage = $order->discount_percentage;
                        $invoice->discount_value = Helper::bankersRounding($order->discount_value, 0);
                        $invoice->undiscounted_subtotal = Helper::bankersRounding($order->undiscounted_base_value, 0);
                        $invoice->invoice_number = $invoiceNumber;
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
                            foreach ($orderDetail['order_specifications'] as $specification) {
                                if ($specification['specification']['specification_category']['type'] == 2) {
                                    $invoiceItem->product_name = $orderDetail['name_product'] . " " .
                                        $specification['name_specification'];
                                    break;
                                }
                            }
                            $invoiceItem->quantity = $orderDetail['quantity'];
                            $invoiceItem->base_value = Helper::bankersRounding($orderDetail['base_value'], 0);
                            $invoiceItem->total = Helper::bankersRounding($orderDetail['total'], 0);
                            $invoiceItem->has_iva = $orderDetail['tax_values']['has_iva'];
                            $invoiceItem->compound_key = $orderDetail['compound_key'];
                            $invoiceItem->order_detail_id = $orderDetail['id'];
                            $invoiceItem->save();
                        }

                        $this->reduceComponentsStock($order);
                        $this->reduceComponentsStockBySpecification($order);

                        event(new OrderUpdatedComanda($order));

                        event(new OrderCreated($order->id));
                        event(new SimpleOrderCreated($storeConfig->store_id));

                        // Enviando Respuesta OK a Uber Eats de que se creó la orden en myPOS
                        $response2 = $browser->post(
                            $baseUrl . 'v1/eats/orders/'. (string) $eatsOrderId .'/accept_pos_order',
                            [
                                'User-Agent' => 'Buzz',
                                'Authorization' => 'Bearer ' . $integration->token,
                                'Content-Type' => 'application/json'
                            ],
                            '{ "reason": "Orden creada en myPOS" }'
                        );
                        if ($response2->getStatusCode() !== 204) {
                            Log::info("No se pudo enviar la aceptación del pedido");
                            Log::info($response2->getStatusCode());
                            throw new \Exception('No se pudo enviar la aceptación del pedido');
                        }

                        Log::info("Response a uber enviado exitosamente");

                        if ($storeConfig->uses_print_service) {
                            // Imprimir por microservicio
                            PrintServiceHelper::printComanda($order, $employee);
                            PrintServiceHelper::printInvoice($invoice, $employee);
                        } else {
                            // Send firebase push notification
                            $this->sendIntegrationOrder($order, 'Uber Eats');
                        }
                    }
                },
                5 // Veces para reintento cuando un deadlock ocurre
            );
            return;
        } catch (\Exception $e) {
            $this->logError(
                "UberEatsController Web storeOrder: ERROR GUARDAR ORDEN UBER EATS, storeId: " . $storeConfig->store_id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $orderUber
            );

            event(new SimpleOrderFailed($storeConfig->store_id));

            Log::info("*************************");
            $errorMessage = addslashes($e->getMessage());
            if (strpos($errorMessage, 'SQLSTATE') !== false) {
                $errorMessage = "Error con un tipo de dato incorrecto";
            }
            $msg = '{ "reason": { "explanation": "' . $errorMessage . '" } }';
            Log::info("Mensaje de rechazo");
            Log::info($msg);
            Log::info("URL de rechazo");
            Log::info($baseUrl . 'v1/eats/orders/'. (string) $eatsOrderId .'/deny_pos_order');
            // Enviando Respuesta DENY a Uber Eats de que se no se pudo crear la orden en myPOS
            $response3 = $browser->post(
                $baseUrl . 'v1/eats/orders/'. (string) $eatsOrderId .'/deny_pos_order',
                [
                    'User-Agent' => 'Buzz',
                    'Authorization' => 'Bearer ' . $integration->token,
                    'Content-Type' => 'application/json'
                ],
                $msg
            );
            if ($response3->getStatusCode() !== 204) {
                Log::info("No se pudo enviar el rechazo del pedido");
                Log::info($response3->getStatusCode());
            }
            return;
        }
    }
}
