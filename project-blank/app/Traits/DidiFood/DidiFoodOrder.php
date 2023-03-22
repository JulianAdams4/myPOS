<?php

namespace App\Traits\DidiFood;

use Log;
use Carbon\Carbon;
use Buzz\Browser;
use Illuminate\Http\Request;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;
use App\StoreIntegrationToken;
use App\StoreConfig;
use App\Store;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\AvailableMyposIntegration;
use App\StoreIntegrationId;
use App\CashierBalance;
use App\Spot;
use App\OrderIntegrationDetail;
use App\Order;
use App\Payment;
use App\PaymentType;
use App\Product;
use App\ProductDetail;
use App\Specification;
use App\OrderDetail;
use App\OrderProductSpecification;
use App\OrderDetailProcessStatus;
use App\ProductIntegrationDetail;
use App\Billing;
use App\Invoice;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\Traits\DidiFood\DidiFoodMenu;
use App\Traits\DidiFood\DidiRequests;
use App\Events\SimpleOrderFailed;
use App\Events\OrderCreated;
use App\Events\SimpleOrderCreated;
use App\Events\OrderUpdatedComanda;
use App\Helpers\PrintService\PrintServiceHelper;
use App\Traits\PushNotification;
use App\Traits\OrderHelper;
use App\Helper;
use Illuminate\Support\Facades\DB;
use App\Employee;

use App\Http\Helpers\QueueHelper;
use App\Jobs\ActionLoggerJob;

trait DidiFoodOrder
{
    use DidiFoodMenu, PushNotification, OrderHelper, DidiRequests {
        DidiRequests::printLogFile insteadof DidiFoodMenu;
        DidiRequests::logIntegration insteadof DidiFoodMenu;
        DidiRequests::sendSlackMessage insteadof DidiFoodMenu;
        DidiRequests::processResponse insteadof DidiFoodMenu;
        DidiRequests::logError insteadof DidiFoodMenu;
        DidiRequests::simpleLogError insteadof DidiFoodMenu;
        DidiRequests::getSlackChannel insteadof DidiFoodMenu;
        DidiRequests::logModelAction insteadof DidiFoodMenu;
        DidiRequests::__construct insteadof DidiFoodMenu;
        DidiRequests::initVarsDidiRequests insteadof DidiFoodMenu;
        DidiRequests::getOrderDetails insteadof DidiFoodMenu;
        DidiRequests::confirmOrder insteadof DidiFoodMenu;
        DidiRequests::getDidiToken insteadof DidiFoodMenu;
        DidiRequests::getToken insteadof DidiFoodMenu;
        DidiRequests::refreshToken insteadof DidiFoodMenu;
        DidiRequests::rejectDidiOrder insteadof DidiFoodMenu;
    }

    public function getEmployeeDidiIntegration($storeId)
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

    public function storeOrder($informationObj)
    {
        $this->logIntegration(
            "DidiFoodOrder storeOrder",
            "info"
        );

        $baseUrl = config('app.didi_url_api');
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());

        $orderJSON = [];
        $orderInfo = null;
        $didiConfiguration = null;
        $didiOrderId = null;

        try {
            $didiApp = $informationObj["app_id"];
            $didiShopId = $informationObj["app_shop_id"];
            
            $orderJSON = json_decode($informationObj["data"], true);
            $didiOrderId = $orderJSON["order_id"];
            $this->logIntegration(
                "DidiShopId: " . $didiShopId . "  ---  DidiOrderId: " . $didiOrderId,
                "info"
            );

            $didiConfiguration = $this->checkDidiConfiguration($didiShopId);
            $this->initVarsDidiRequests();
            $resultToken = $this->getDidiToken($didiConfiguration['storeId'], $didiShopId);

            // Obteniendo detalles de la orden
            $resultDetails = $this->getOrderDetails(
                $resultToken['token'],
                $didiOrderId,
                $didiConfiguration["storeName"]
            );
            if ($resultDetails["status"] == 0) {
                // Falló en obtener la info de la orden
                return ([
                    "message" => $resultDetails["data"],
                    "code" => 0
                ]);
            }
            // Información de la orden
            $orderInfo = $resultDetails["data"];

            $orderJSON = DB::transaction(
                function () use (
                    $orderInfo,
                    $didiOrderId,
                    $didiConfiguration,
                    $baseUrl,
                    $browser,
                    $resultToken
                ) {
                    $automatic=$didiConfiguration["storeConfig"]->automatic;
                    $channel = $this->getSlackChannel($didiConfiguration["storeId"]);
                    $datetimeFormat = 'Y-m-d H:i:s';
                    $timestamp = substr($orderInfo->create_time, 0, 10);
                    $date = new \DateTime();
                    $date->setTimestamp($timestamp);
                    $dateOrder = $date->format($datetimeFormat);
                    $cashierBalance = CashierBalance::where('store_id', $didiConfiguration["storeId"])
                        ->whereNull('date_close')
                        ->first();
                    // Buscar employee de integración de ese store
                    $employee = $this->getEmployeeDidiIntegration($didiConfiguration["storeId"]);
                    $deliverySpot = Spot::where('store_id', $didiConfiguration["storeId"])
                        ->where('origin', Spot::ORIGIN_DIDI)
                        ->first();
                    if ($cashierBalance === null) {
                        $slackMessage = "Error al guardar la orden de Didi en myPOS\n" .
                            "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                            "Error: No está abierta la tienda\n" .
                            "OrderIdentifier: " . $orderInfo->order_id;
                        $this->sendSlackMessage(
                            $channel,
                            $slackMessage
                        );
                        throw new \Exception('No está abierta la tienda');
                    }
                    $orderIntegrationExist = OrderIntegrationDetail::where(
                        'external_order_id',
                        $orderInfo->order_id
                    )->first();
                    if ($orderIntegrationExist == null) {
                        if ($deliverySpot === null) {
                            $deliverySpot = new Spot();
                            $deliverySpot->name = "Didi Food";
                            $deliverySpot->store_id = $didiConfiguration["storeId"];
                            $deliverySpot->origin = Spot::ORIGIN_DIDI;
                            $deliverySpot->save();
                        }

                        $orderValue = $orderInfo->price->order_price;
                        $paymentMethod = $orderInfo->pay_type;

                        $order = new Order();
                        $order->store_id = $didiConfiguration["storeId"];
                        $order->spot_id = $deliverySpot->id;
                        $order->order_value = $orderValue;
                        $order->current_status = 'Creada';
                        $order->status = $automatic?1:3;
                        $order->employee_id = $employee->id;
                        $order->created_at = $dateOrder;
                        $order->updated_at = $dateOrder;
                        $order->cash = 0;
                        $order->identifier = Helper::getNextOrderIdentifier($didiConfiguration["storeId"]);
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
                        $order->instructions = isset($orderInfo->remark) ? $orderInfo->remark : null;

                        //$order->save();

                        $payment = new Payment();
                        // Para pagos en efectivo se lo toma de otro lugar el valor a pagar
                        if ($paymentMethod == 2) {
                            $payment->type = PaymentType::CASH;
                            $orderValue = $orderInfo->price->shop_paid_money;
			   //if($orderInfo->price->order_price > $orderInfo->price->shop_paid_money){
                                //lleva descuento
                            //    $discountValue = $orderInfo->price->order_price - $orderInfo->price->shop_paid_money;
                              //  $discountPercentage = $discountValue * 100 / $orderInfo->price->order_price;
                               // $order->discount_percentage = $discountPercentage;
                               // $order->discount_value = $discountValue;
                            //}
                        } else {
                            $payment->type = PaymentType::CREDIT;
                        }
			$order->save();
                        $payment->total = $orderValue;
                        $payment->order_id = $order->id;
                        $payment->save();

                        $orderIntegration = new OrderIntegrationDetail();
                        $orderIntegration->order_id = $order->id;
                        $orderIntegration->integration_name = AvailableMyposIntegration::NAME_DIDI;
                        $orderIntegration->external_order_id = $orderInfo->order_id;
                        $orderIntegration->external_store_id = $orderInfo->shop->shop_id;
                        $orderIntegration->number_items = count($orderInfo->order_items);
                        $orderIntegration->value = $orderValue;
                        $customerName = "";
                        if (!isset($orderInfo->receive_address->name)) {
                            $customerName = $orderInfo->receive_address->name;
                        }
                        $orderIntegration->customer_name = $customerName;
                        $orderIntegration->order_number = $orderInfo->order_id;
                        $orderIntegration->save();

                        // Guardando los orderDetails de la orden
                        foreach ($orderInfo->order_items as $orderItem) {
                            if (!isset($orderItem->app_item_id)) {
                                $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                    "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                    "Error: Se tiene un producto subido a Didi Food no sincronizado\n" .
                                    "Producto: " . $orderItem->name . "\n" .
                                    "OrderIdentifier: " . $orderInfo->order_id;
                                $this->sendSlackMessage(
                                    $channel,
                                    $slackMessage
                                );
                                throw new \Exception("Este producto no tiene referencia a un producto en myPOS");
                            }
                            $product = Product::where('id', $orderItem->app_item_id)->first();
                            if ($product === null) {
                                $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                    "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                    "Error: Se tiene un producto sincronizado que no existe en myPOS\n" .
                                    "Producto: " . $orderItem->name . "\n" .
                                    "OrderIdentifier: " . $orderInfo->order_id;
                                $this->sendSlackMessage(
                                    $channel,
                                    $slackMessage
                                );
                                throw new \Exception(
                                    "Este producto no tiene ninguna referencia a un producto válido en myPOS"
                                );
                            }
                            $productDetail = ProductDetail::where('product_id', $product->id)->first();
                            if ($productDetail === null) {
                                $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                    "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                    "Error 2: Se tiene un producto sincronizado que no existe en myPOS\n" .
                                    "Producto: " . $orderItem->name . "\n" .
                                    "OrderIdentifier: " . $orderInfo->order_id;
                                $this->sendSlackMessage(
                                    $channel,
                                    $slackMessage
                                );
                                throw new \Exception(
                                    "Este producto no tiene ninguna referencia a un producto válido en myPOS 2"
                                );
                            }

                            $section = $product->category->section;
                            if ($section == null) {
                                $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                    "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                    "Error: No se encontró el menú de myPOS que contiene este producto\n" .
                                    "Producto: " . $orderItem->name . "\n" .
                                    "OrderIdentifier: " . $orderInfo->order_id;
                                $this->sendSlackMessage(
                                    $channel,
                                    $slackMessage
                                );
                                throw new \Exception(
                                    "No se ha encontrado el menú de Didi Food para esta tienda"
                                );
                            }

                            $orderDetail = new OrderDetail();
                            $orderDetail->order_id = $order->id;
                            $orderDetail->product_detail_id = $productDetail->id;
                            $orderDetail->quantity = $orderItem->amount;
                            $orderDetail->status = 1;
                            $orderDetail->created_at = $dateOrder;
                            $orderDetail->updated_at = $dateOrder;
                            $orderDetail->value = ($orderItem->total_price / $orderItem->amount);
                            $orderDetail->name_product = $product->name;
                            $orderDetail->instruction = isset($orderItem->remark) ? $orderItem->remark : "";
                            $orderDetail->invoice_name = $product->invoice_name;
                            $orderDetail->total = $orderItem->total_price;
                            $orderDetail->base_value = ($orderItem->total_price) / 100;
                            $orderDetail->compound_key = strval($productDetail->id);
                            $orderDetail->save();
                            $processStatus = new OrderDetailProcessStatus();
                            $processStatus->process_status = 1;
                            $processStatus->order_detail_id = $orderDetail->id;
                            $processStatus->save();

                            // Crear OrderProductSpecification
                            $specificationIdsQuantity = collect([]);
                            if (isset($orderItem->sub_item_list)) {
                                // Verificando que existe la integración del producto
                                $productInt = ProductIntegrationDetail::where("product_id", $product->id)
                                ->where('integration_name', AvailableMyposIntegration::NAME_DIDI)
                                ->first();
                                // Opciones seleccionadas en la orden
                                foreach ($orderItem->sub_item_list as $specificationDidi) {
                                    if (!isset($specificationDidi->app_item_id)) {
                                        $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                            "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                            "Error: Se tiene una especificación subido a Didi Food no utilizando
                                                myPOS(no sincronizado)\n" .
                                            "Especificación: " . $specificationDidi->name . "\n" .
                                            "OrderIdentifier: " . $orderInfo->order_id;
                                        $this->sendSlackMessage(
                                            $channel,
                                            $slackMessage
                                        );
                                        throw new \Exception(
                                            "Este grupo de especificación no tiene referencia en myPOS"
                                        );
                                    }

                                    $specIdDidiComplex = explode("_", $specificationDidi->app_item_id);
                                    // Verificando que existe la especificación
                                    $specification = Specification::where(
                                        'id',
                                        $specIdDidiComplex[0]
                                    )
                                    ->first();
                                    if (!$specification) {
                                        $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                            "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                            "Error: Se tiene una especificación sincronizada que no existe en myPOS\n" .
                                            "Especificación: " . $specification["name"] . "\n" .
                                            "OrderIdentifier: " . $orderInfo->order_id;
                                        $this->sendSlackMessage(
                                            $channel,
                                            $slackMessage
                                        );
                                        throw new \Exception("Esta especificación no existe en myPOS");
                                    }
                                    // Verificando que existe la integración de la especificación
                                    $specificationInt = ToppingIntegrationDetail::where(
                                        "specification_id",
                                        $specification->id
                                    )
                                    ->where("integration_name", AvailableMyposIntegration::NAME_DIDI)
                                    ->first();

                                    if ($productInt == null || $specificationInt == null) {
                                        $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                            "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                            "Error: Se tiene un producto o especificación sin el switch
                                                de Didi Food activo\n" .
                                            "Producto: " . $orderItem->name . "\n" .
                                            "Especificación: " . $specificationDidi->name . "\n" .
                                            "OrderIdentifier: " . $orderInfo->order_id;
                                        $this->sendSlackMessage(
                                            $channel,
                                            $slackMessage
                                        );
                                        throw new \Exception(
                                            "Este producto o especificación no tiene integración Didi Food"
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
                                        $slackMessage = "Error al guardar la orden de Didi Food en myPOS\n" .
                                            "Tienda: " . $didiConfiguration["storeName"] . "\n" .
                                            "Error: Este producto no contiene esta especificación\n" .
                                            "Producto: " . $orderItem->name . "\n" .
                                            "Especificación: " . $specificationDidi->name . "\n" .
                                            "OrderIdentifier: " . $orderInfo->order_id;
                                        $this->sendSlackMessage(
                                            $channel,
                                            $slackMessage
                                        );
                                        throw new \Exception(
                                            "No hay relación entre el producto y la especificación enviada"
                                        );
                                    }
                                    OrderProductSpecification::create(
                                        [
                                        'specification_id' => $specification->id,
                                        'name_specification' => $specificationDidi->name,
                                        'value' => $specificationDidi->total_price,
                                        'order_detail_id' => $orderDetail->id,
                                        'quantity' => $specificationDidi->amount,
                                        ]
                                    );

                                    // Array para armar el compound key
                                    $specIdQuantity = [
                                        'id' => $specification->id,
                                        'quantity' => $specificationDidi->amount
                                    ];
                                    $specificationIdsQuantity->push($specIdQuantity);
                                }
                                // Asignando el compound key al order detail
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

                        if($automatic){
                            $billing = Billing::firstOrCreate(
                                [
                                    'document' => '9999999999999',
                                    'name'     => 'CONSUMIDOR FINAL'
                                ]
                            );
                            $invoiceNumber = Helper::getNextBillingOfficialNumber($didiConfiguration["storeId"], true);
    
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
                        
                        }

                        

                        if($automatic){
                            event(new OrderUpdatedComanda($order));  
                            $this->reduceComponentsStock($order);
                            $this->reduceComponentsStockBySpecification($order);
                        }

                        event(new OrderCreated($order->id));
                        event(new SimpleOrderCreated($didiConfiguration["storeId"]));
                        
                        // Enviando Respuesta OK a Didi Food de que se creó la orden en myPOS
                        if($automatic){
                            $resultConfirm = $this->confirmOrder(
                                $resultToken['token'],
                                $didiOrderId,
                                $didiConfiguration["storeName"]
                            );
                            if ($resultConfirm["status"] == 0) {
                                // Falló en confirmar la orden
                                throw new \Exception(
                                    $resultConfirm["data"]
                                );
                            }
                            $this->logIntegration(
                                "Aceptación de la orden enviado exitosamente a Didi Food",
                                "info"
                            );
                        }
                        // Log Action on Model
                        $obj = [
                            'action' => "INTEGRAR",
                            'model' => "ORDER",
                            'user_id' => $employee->id,
                            'model_id' => $order->id,
                            'model_data' => [
                                'store_id' => $didiConfiguration == null ? "no definido" : $didiConfiguration["storeId"],
                                'integration' => "Didi Food"
                            ]
                        ];                    
                        
                        ActionLoggerJob::dispatch($obj);
                        $order->load('spot','orderDetails.orderSpecifications.specification.specificationCategory','employee','orderIntegrationDetail','invoice','orderConditions','orderStatus');
                        foreach ($order->orderDetails as $detail) {
                            if(!$automatic){
                                $detail->append('spec_fields');
                            }
                        }
                        $job = array();
                        $job["store_id"] = $didiConfiguration["storeId"];
                        $job["order"] = $order;

                        QueueHelper::dispatchJobs(array($job));

                        if($automatic){
                            
                            if ($didiConfiguration["usePrintService"] ) {
                                // Imprimir por microservicio
                                PrintServiceHelper::printComanda($order, $employee);
                                PrintServiceHelper::printInvoice($invoice, $employee);
                            } else {
                                // Send firebase push notification
                                $this->sendIntegrationOrder($order, 'Didi Food');
                            }
                        }
                        
                    
                        $this->logIntegration(
                            "Retornando a la función principal",
                            "info"
                        );
                        return ([
                            "message" => "Orden creada en myPOS",
                            "code" => 0
                        ]);
                    }
                },
                5 // Veces para reintento cuando un deadlock ocurre
            );
            return $orderJSON;
        } catch (\Exception $e) {
            $storeId = $didiConfiguration == null ? "no definido" : $didiConfiguration["storeId"];
            $storeName = $didiConfiguration == null ? "no definido" : $didiConfiguration["storeName"];
            $this->logIntegration(
                "ERROR GUARDAR ORDEN DIDI FOOD, storeId: " . $storeId,
                "error",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($orderJSON)
            );

            event(new SimpleOrderFailed($storeId));

            $this->logIntegration(
                "*************************",
                "info"
            );
            $errorMessage = addslashes($e->getMessage());
            if (strpos($errorMessage, 'SQLSTATE') !== false) {
                $errorMessage = "Error con un tipo de dato incorrecto";
            }

            // Log Action on Model
            $obj = [
                'action' => "ERROR_INTEGRAR",
                'model' => "ORDER",
                'user_id' => "1",
                'model_id' => $didiOrderId,
                'model_data' => [
                    'store_id' => $storeId,
                    'integration' => "Didi Food",
                ]
            ];                    
            
            ActionLoggerJob::dispatch($obj);

            return ([
                "message" => $errorMessage,
                "code" => 409
            ]);
        }
    }

    public function cancelOrder($informationObj){
        $this->logIntegration(
            "DidiFoodOrder cancelOrder",
            "info"
        );

        $orderJSON = [];
        $didiConfiguration = null;
        $didiOrderId = null;

        try {
            $didiShopId = $informationObj["app_shop_id"];
            $orderJSON = json_decode($informationObj["data"], true);
            $didiOrderId = $orderJSON["order_id"];
            $this->logIntegration(
                "DidiShopId: " . $didiShopId . "  ---  DidiOrderId: " . $didiOrderId,
                "info"
            );

            $didiConfiguration = $this->checkDidiConfiguration($didiShopId);

            $orderInt = OrderIntegrationDetail::where('external_order_id', $didiOrderId)
                ->where('integration_name', AvailableMyposIntegration::NAME_DIDI)
                ->first();

            if(empty($orderInt)){
                throw new \Exception("no se encuentra la orden {$didiOrderId} en myPOS");
            }

            $order = $orderInt->order;
            $order->status = 0;
            $order->save();

            $job = array();
            $job["store_id"] = $order->store->id;
            $job["order"] = $order;
            QueueHelper::dispatchJobs(array($job));

            return ([
                "message" => "Orden cancela en myPOS",
                "code" => 0
            ]);

        } catch (\Exception $e) {
            $storeId = $didiConfiguration == null ? "no definido" : $didiConfiguration["storeId"];
            $this->logIntegration(
                "ERROR GUARDAR ORDEN DIDI FOOD, storeId: " . $storeId,
                "error",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($orderJSON)
            );

            return null;
        }
    }

    /**
     * Verificar configuración para Didi
     *
     * Función que verifica si la tienda tiene todos los datos seteados en la BD para usar
     * la integración con Didi.
     *
     * @param string $didiShopId id externo de la tienda de Didi
     *
     * @return array Arreglo con la info de la integración
     *
     */
    public function checkDidiConfiguration($didiShopId)
    {
        $store = null;

        $integrationDidi = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_DIDI)
            ->first();
        if ($integrationDidi == null) {
            throw new \Exception("myPOS no tiene configurado la integración con Didi Food");
        }

        $configDidi = StoreIntegrationId::where('external_store_id', $didiShopId)
            ->where('integration_id', $integrationDidi->id)
            ->first();
        if ($configDidi == null) {
            throw new \Exception("Esta tienda no está configurada para usar Didi");
        }

        $storeConfig = StoreConfig::where('store_id', $configDidi->store_id)->first();
        if ($storeConfig === null) {
            throw new \Exception("Esta tienda no tiene store config en myPOS");
        }

        $integration = StoreIntegrationToken::where('store_id', $configDidi->store_id)
            ->where('integration_name', $integrationDidi->code_name)
            ->where('type', 'delivery')
            ->first();
        if ($integration === null) {
            throw new \Exception("Esta tienda no tiene token de Didi Food");
        }

        $store = Store::where('id', $configDidi->store_id)->first();

        return ([
            "storeId" => $configDidi->store_id,
            "usePrintService" => $storeConfig->uses_print_service,
            "token" => $integration->token,
            "storeName" => $store == null ? $configDidi->store_id : $store->name,
            "storeConfig"=>$storeConfig
        ]);
    }
}
