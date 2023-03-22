<?php

namespace App\Traits\Integrations;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Log;

// Models
use App\CashierBalance;
use App\Employee;
use App\Store;
use App\Spot;
use App\Order;
use App\Payment;
use App\PaymentType;
use App\OrderIntegrationDetail;
use App\Product;
use App\ProductDetail;
use App\OrderDetail;
use App\SectionIntegration;
use App\OrderDetailProcessStatus;
use App\ProductIntegrationDetail;
use App\Specification;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\OrderProductSpecification;
use App\Billing;
use App\Invoice;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;
use App\StoreIntegrationId;
use App\StoreConfig;
use App\IfoodProductPromotion;
use App\Events\HubIntegrationOrderCreated;

// Helpers
use App\Helper;
use App\Helpers\PrintService\PrintServiceHelper;
use App\Traits\OrderHelper;
use App\Traits\Logs\Logging;

// Events
use App\Events\OrderCreated;
use App\Events\SimpleOrderCreated;
use App\Events\SimpleOrderFailed;
use App\Events\OrderUpdatedComanda;

use App\Http\Helpers\QueueHelper;
use App\Jobs\ActionLoggerJob;

trait IntegrationsHelper
{
use OrderHelper;
    /**
     * Crear la orden para la integración
     *
     * Función que crea la order, details, invoice, payments y todo lo necesarios para una nueva orden de integración.
     *
     * @param array        $orderInfo        Información de la orden que viene de integración
     * @param string       $integration      Data de la integración para la cual se va a crear la orden
     * @param integer      $storeId          Id de la tienda en la cual se va a crear la orden
     * @param string       $storeName        Nombre de la tienda en la cual se va a crear la orden
     * @param StoreConfig  $storeConfig      Datos de configuración de la tienda en la cual se va a crear la orden
     * @param string       $channelLog       Nombre del canal de log donde se va a imprimir los logs
     * @param integer      $spotIntegration  Número de origen de la mesa de integración
     *
     * @return array Información con el estado de la creación de la orden
     *
     */
    public function createIntegrationOrder(
        $orderInfo,
        $integration,
        $storeId,
        $storeName,
        $storeConfig,
        $channelLog,
        $spotIntegration,
        $hub = null
    ) {
        // Status
        // 0: Error
        // 1: Éxito
        // 2: Sin cambios
        // 3: Exception launch
        
        Logging::printLogFile(
            "Creando orden para la tienda: " . $storeName,
            $channelLog
        );
        Logging::printLogFile(
            "De la integración: " . $integration->name,
            $channelLog
        );

        $slackMessage = "";
        $status = 1;

        try {
            Logging::printLogFile(
                "Order id: " . $orderInfo["external_id"],
                $channelLog
            );
            // Transacción para crear la orden, reintenta por 3 ocasiones si falla la transacción
            $resultTransaction = DB::transaction(
                function () use (
                    $orderInfo,
                    $integration,
                    $storeId,
                    $storeName,
                    $storeConfig,
                    $spotIntegration,
                    $hub,
                    &$status,
                    &$slackMessage
                ) {
                    // Verificación si la caja está abierta
                    $cashierBalance = CashierBalance::where('store_id', $storeId)
                        ->whereNull('date_close')
                        ->first();
                    if ($cashierBalance === null) {
                        $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                            "Tienda: " . $storeName . "\n" .
                            "Error: No está abierta la tienda\n" .
                            "OrderIdentifier: " . $orderInfo["order_number"] . "\n" .
                            "Customer: " . $orderInfo["customer"];
                        $status = 0;
                        throw new \Exception("No está abierta la tienda");
                    }

                    // Confirmamos que esta orden ya no se encuentra creado en myPOS
                    $orderIntegrationExist = OrderIntegrationDetail::where(
                        'external_order_id',
                        $orderInfo["external_id"]
                    )->first();
                    if ($orderIntegrationExist != null) {
                        $slackMessage = "";
                        $status = 2;
                        throw new \Exception("Esta orden ya existe");
                    }

                    // Buscar employee de integración de ese store y la mesa de la integración
                    $employee = $this->getEmployeeIntegration($storeId);
                    $deliverySpot = Spot::where('store_id', $storeId)
                        ->where('origin', $spotIntegration)
                        ->first();
                    if ($deliverySpot === null) {
                        $deliverySpot = new Spot();
                        $deliverySpot->name = $integration->name;
                        $deliverySpot->store_id = $storeId;
                        $deliverySpot->origin = $spotIntegration;
                        $deliverySpot->save();
                    }

                    // Creando la información básica de la orden
                    $order = new Order();
                    $order->store_id = $storeId;
                    $order->spot_id = $deliverySpot->id;
                    $order->order_value = $orderInfo["total"];
                    $order->current_status = 'Creada';
                    $order->status = $orderInfo["automatic"]?1:3;//El status 3 representa que la orden esta esperando confirmación
                    $order->employee_id = $employee->id;
                    $order->created_at = $orderInfo["created_at"];
                    $order->updated_at = $orderInfo["created_at"];
                    $order->cash = 0;
                    $order->identifier = Helper::getNextOrderIdentifier($storeId);
                    $order->preorder = 0;
                    $order->cashier_balance_id = $cashierBalance->id;
                    $order->total = $orderInfo["total"];
                    $order->base_value = $orderInfo["total"];
                    $order->food_service = 0;
                    $discountValue = 0;
                    $discountPercentage = 0;
                    if (isset($orderInfo["discount_value"])) {
                        $discountValue = $orderInfo["discount_value"];
                        $discountPercentage = $discountValue * 100 / $orderInfo["total"];
                    }
                    $order->discount_percentage = $discountPercentage;
                    $order->discount_value = $discountValue;
                    $order->undiscounted_base_value = $orderInfo["total"];
                    $order->change_value = 0;
                    $order->no_tax_subtotal = 0.00;
                    if (isset($orderInfo["disposable_items"])) {
                        $order->disposable_items = $orderInfo["disposable_items"];
                    }
                    $order->save();

                    // Creando el payment de esta orden
                    if (!isset($orderInfo["payments"])) {
                        $payment = new Payment();
                        $payment->total = $orderInfo["total"] - $discountValue;
                        $payment->order_id = $order->id;
                        $payment->type = PaymentType::CREDIT;
                        $payment->created_at = $orderInfo["created_at"];
                        $payment->updated_at = $orderInfo["created_at"];
                        $payment->save();
                    } elseif (count($orderInfo["payments"]) == 0) {
                        $payment = new Payment();
                        $payment->total = $orderInfo["total"] - $discountValue;
                        $payment->order_id = $order->id;
                        $payment->type = PaymentType::CREDIT;
                        $payment->created_at = $orderInfo["created_at"];
                        $payment->updated_at = $orderInfo["created_at"];
                        $payment->save();
                    } else {
                        foreach ($orderInfo["payments"] as $paymentOrder) {
                            $payment = new Payment();
                            $payment->total = $paymentOrder["value"];
                            $payment->order_id = $order->id;
                            $payment->type = $paymentOrder["type"];
                            $payment->created_at = $orderInfo["created_at"];
                            $payment->updated_at = $orderInfo["created_at"];
                            $payment->save();
                        }
                    }
                    

                    // Creando el order integration que tienen los detalles de la integración
                    $orderIntegration = new OrderIntegrationDetail();
                    $orderIntegration->order_id = $order->id;
                    $orderIntegration->integration_name = $integration->code_name;
                    $orderIntegration->external_order_id = $orderInfo["external_id"];
                    $orderIntegration->external_store_id = $orderInfo["external_store_id"];
                    $orderIntegration->number_items = count($orderInfo["items"]);
                    $orderIntegration->value = $orderInfo["total"];
                    $orderIntegration->customer_name = $orderInfo["customer"];
                    $orderIntegration->order_number = $orderInfo["order_number"];
                    $orderIntegration->created_at = $orderInfo["created_at"];
                    $orderIntegration->updated_at = $orderInfo["created_at"];
                    $orderIntegration->save();

                    $newOrderDetailsStatus = [];
                    // Guardando los detalles del contenido de la orden
                    foreach ($orderInfo["items"] as $orderItem) {
                        // Caso donde el producto no contiene el id de sincronización
                        if ($orderItem["external_id"] == null) {
                            $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: El producto no contiene el id de sincronización\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                            $status = 0;
                            throw new \Exception("Producto sin el id de sincronización");
                        }

                        // Verificar si el producto sincronizado existe en myPOS. Parte 1
                        $product = Product::where('id', $orderItem["external_id"])->first();
                        if ($product === null) {
                            $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: Producto sincronizado que no existe en myPOS\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                            $status = 0;
                            throw new \Exception("El producto no tiene ninguna referencia a un producto en myPOS");
                        }
                        // Verificar si el producto sincronizado existe en myPOS. Parte 2
                        $productDetail = ProductDetail::where('product_id', $product->id)
                            ->where('store_id', $storeId)
                            ->first();
                        if ($productDetail === null) {
                            $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error 2: Producto sincronizado que no existe en myPOS\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                            $status = 0;
                            throw new \Exception("El producto no tiene ninguna referencia a un producto en myPOS 2");
                        }

                        // Verificar si el producto pertenece a un menú de integración
                        $sectionId = $product->category->section_id;
                        if ($sectionId == null) {
                            // El producto no pertenece a ningún menú
                            $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: No se encontró el menú de myPOS que contiene este producto\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                            $status = 0;
                            throw new \Exception("No se encontró el menú de myPOS que contiene este producto");
                        } else {
                            // El producto pertenece a un menú pero no es de la integración de la orden
                            $sectionIntegration = SectionIntegration::where('section_id', $sectionId)
                                ->where('integration_id', $integration->id)
                                ->first();
                            if ($sectionIntegration == null) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error 2: No se encontró el menú de myPOS que contiene este producto\n" .
                                    "Producto: " . $orderItem["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("No se encontró el menú de myPOS que contiene este producto 2");
                            }
                        }

                        // Verificando que existe la integración del producto
                        $productInt = ProductIntegrationDetail::where("product_id", $product->id)
                            ->where('integration_name', $integration->code_name)
                            ->first();
                        if ($productInt === null) {
                            $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: Producto no sincronizado\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                            $status = 0;
                            throw new \Exception("Producto no sincronizado");
                        } else {
                            // Comparando información adicional para ver si coinciden precios
                            $unitValue = $productInt->price;
                            // Verificación de promociones
                            $promotion = IfoodProductPromotion::where(
                                'product_integration_id',
                                $productInt->id
                            )->first();
                            if (!is_null($promotion)) {
                                $unitValue = $promotion->value;
                            }
                            if ($orderItem["unit_value"] != $unitValue) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: El precio del producto no coincide con el precio guardado en myPOS\n" .
                                "Producto: " . $orderItem["name"] . "\n" .
                                "Precio en Delivery App: " . $orderItem["unit_value"] / 100 . "\n" .
                                "Precio en myPOS: " . $unitValue / 100 . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("El precio del producto no coincide con el precio guardado en myPOS");
                            }
                        }

                        // Creando el detalle de la orden
                        $orderDetail = new OrderDetail();
                        $orderDetail->order_id = $order->id;
                        $orderDetail->product_detail_id = $productDetail->id;
                        $orderDetail->quantity = $orderItem["quantity"];
                        $orderDetail->status = 1;
                        $orderDetail->created_at = $orderInfo["created_at"];
                        $orderDetail->updated_at = $orderInfo["created_at"];
                        $orderDetail->value = isset($orderItem["total_unit_value"]) ? $orderItem["total_unit_value"] : $orderItem["unit_value"];
                        $orderDetail->name_product = $product->name;
                        $orderDetail->instruction = $orderItem["instructions"];
                        $orderDetail->invoice_name = $product->invoice_name;
                        $orderDetail->total = $orderItem["total_value"];
                        $orderDetail->base_value = $orderItem["total_value"];
                        $orderDetail->compound_key = strval($productDetail->id);
                        $orderDetail->save();
                        // Data del nuevo estado del detalle de la orden a crear
                        array_push(
                            $newOrderDetailsStatus,
                            [
                                "process_status" => 1,
                                "order_detail_id" => $orderDetail->id,
                                "created_at" => $orderInfo["created_at"],
                                "updated_at" => $orderInfo["created_at"]
                            ]
                        );
                        
                        // Creando los modificadores seleccionados para ese producto
                        $specificationIdsQuantity = collect([]);
                        $newOrderProdSpecs = [];
                        foreach ($orderItem["modifiers"] as $modifier) {
                            // Caso donde el modificador no contiene el id de sincronización
                            if ($modifier["external_id"] == null) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: El modificador no contiene el id de sincronización\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("Modificador sin el id de sincronización");
                            }

                            // Verificando que existe la especificación en myPOS
                            $complexId = explode("_", $modifier["external_id"]);
                            $specification = Specification::where('id', $complexId[0])->first();
                            if ($specification == null) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: Modificador sincronizado que no existe en myPOS\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("El modificador no tiene ninguna referencia a un producto en myPOS");
                            }

                            //Verificar si la categoria de especificacion no existe en el menu uber myPOS
                            if ($specification->specificationCategory == null ) {
                                // No se encuentra la categoria de especificacion en el menu uber myPOS
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                "Tienda: " . $storeName . "\n" .
                                "Error: No se encontró la categoria de especificacion que contiene la especificacion \n" .
                                "NoMmbre de Especificacion: " . $modifier["name"] . "\n" .
                                "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("La categoria de especificacion no existe en myPOS");
                            }

                            // Verificar si la especificación pertenece a un menú de integración
                            $sectionId = $specification->specificationCategory->section_id;
                            if ($sectionId == null) {
                                // La especificación no pertenece a ningún menú
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: No se encontró el menú de myPOS que contiene este modificador\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("No se encontró el menú de myPOS que contiene este modificador");
                            } else {
                                // La especificación pertenece a un menú pero no es de la integración de la orden
                                $sectionIntegration = SectionIntegration::where('section_id', $sectionId)
                                    ->where('integration_id', $integration->id)
                                    ->first();
                                if ($sectionIntegration == null) {
                                    $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                        "Tienda: " . $storeName . "\n" .
                                        "Error 2: No se encontró el menú de myPOS que contiene este modificador\n" .
                                        "Modificador: " . $modifier["name"] . "\n" .
                                        "OrderIdentifier: " . $orderInfo["order_number"];
                                    $status = 0;
                                    throw new \Exception("No se encontró el menú de myPOS que contiene este modificador 2");
                                }
                            }

                            // Verificando que existe la integración de la especificación
                            $specificationInt = ToppingIntegrationDetail::where(
                                "specification_id",
                                $specification->id
                            )
                            ->where("integration_name", $integration->code_name)
                            ->first();
                            if ($specificationInt == null) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: Modificador no sincronizado\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("Modificador no sincronizado");
                            }

                            // Verificando relación entre el producto y el modificador
                            $productSpecificationInt = ProductToppingIntegration::where(
                                "product_integration_id",
                                $productInt->id
                            )
                            ->where("topping_integration_id", $specificationInt->id)
                            ->first();
                            if ($productSpecificationInt == null) {
                                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: Este producto no contiene este modificador\n" .
                                    "Producto: " . $orderItem["name"] . "\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                $status = 0;
                                throw new \Exception("No hay relación entre el producto y el modificador enviado");
                            } else {
                                 // Comparando información adicional para ver si coinciden precios
                                if ($modifier["unit_value"] != $productSpecificationInt->value) {
                                    $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                                    "Tienda: " . $storeName . "\n" .
                                    "Error: El precio del modificador no coincide con el precio guardado en myPOS\n" .
                                    "Modificador: " . $modifier["name"] . "\n" .
                                    "Precio en Delivery App: " . $modifier["unit_value"] / 100 . "\n" .
                                    "Precio en myPOS: " . $productSpecificationInt->value / 100 . "\n" .
                                    "OrderIdentifier: " . $orderInfo["order_number"];
                                    $status = 0;
                                    throw new \Exception("El precio del modificador no coincide con el precio guardado en myPOS");
                                }
                            }
                            // Data del modificador seleccionado en un producto de la orden que se va a crear
                            array_push(
                                $newOrderProdSpecs,
                                [
                                    "specification_id" => $specification->id,
                                    "name_specification" => $modifier["name"],
                                    "value" => $modifier["unit_value"],
                                    "order_detail_id" => $orderDetail->id,
                                    "quantity" => $modifier["quantity"],
                                    "created_at" => $orderInfo["created_at"],
                                    "updated_at" => $orderInfo["created_at"]
                                ]
                            );

                            // Array para armar el compound key
                            $specIdQuantity = [
                                'id' => $specification->id,
                                'quantity' => $modifier["quantity"]
                            ];
                            $specificationIdsQuantity->push($specIdQuantity);
                        }

                        // Creando nuevos modificadores seleccionados en la orden
                        OrderProductSpecification::insert($newOrderProdSpecs);

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

                    // Creando nuevos estados de los detalles de la orden
                    OrderDetailProcessStatus::insert($newOrderDetailsStatus);
                    // Recalculando los valores de la orden(Para crear los valores de taxes y valores sin taxes)
                    $order = $this->calculateOrderValuesIntegration($order);

                    //Inicio de Invoice
                    if($orderInfo["automatic"]){
                        
                        // Como las integraciones no manejan clientes, obteniendo el cliente de comsumidor final
                        $billing = Billing::firstOrCreate(
                            [
                                'document' => '9999999999999',
                                'name'     => 'CONSUMIDOR FINAL'
                            ]
                        );
                        // Obteniendo el número de la factura para esta orden
                        $invoiceNumber = Helper::getNextBillingOfficialNumber($storeId, true);

                        // Creando la factura
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
                        $invoice->created_at = $orderInfo["created_at"];
                        $invoice->updated_at = $orderInfo["created_at"];
                        $invoice->save();

                        // Agregando detalles de los valores que no cobran impuestos
                        if ($order->no_tax_subtotal > 0) {
                            $invoiceTaxDetail = new InvoiceTaxDetail();
                            $invoiceTaxDetail->invoice_id = $invoice->id;
                            $invoiceTaxDetail->tax_name = 'Sin impuestos (0%)';
                            $invoiceTaxDetail->tax_percentage = 0;
                            $invoiceTaxDetail->subtotal = 0;
                            $invoiceTaxDetail->tax_subtotal = Helper::bankersRounding($order->no_tax_subtotal, 0);
                            $invoiceTaxDetail->print = 1;
                            $invoiceTaxDetail->created_at = $orderInfo["created_at"];
                            $invoiceTaxDetail->updated_at = $orderInfo["created_at"];
                            $invoiceTaxDetail->save();
                        }

                        // Agregando los detalles de los valores que cobran impuestos
                        $newInvoiceTaxDetails = [];
                        foreach ($order->taxDetails as $taxDetail) {
                            // Data del impuesto para el detalle de la orden
                            array_push(
                                $newInvoiceTaxDetails,
                                [
                                    "invoice_id" => $invoice->id,
                                    "tax_name" => $taxDetail->storeTax->name,
                                    "tax_percentage" => $taxDetail->storeTax->percentage,
                                    "tax_subtotal" => Helper::bankersRounding($taxDetail->tax_subtotal, 0),
                                    "subtotal" => Helper::bankersRounding($taxDetail->subtotal, 0),
                                    "print" => ($taxDetail->storeTax->type === 'invoice') ? 0 : 1,
                                    "created_at" => $orderInfo["created_at"],
                                    "updated_at" => $orderInfo["created_at"]
                                ]
                            );
                        }

                        // Creando nuevos impuestos del detalle de la orden
                        InvoiceTaxDetail::insert($newInvoiceTaxDetails);

                        // Creación de los items para la factura
                        $orderCollection = collect($order);
                        $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey(
                            $order->orderDetails->load('orderSpecifications.specification.specificationCategory')
                        );
                        $orderCollection->forget('orderDetails');
                        $orderCollection->put('orderDetails', $groupedOrderDetails);
                        $newInvoiceItems = [];
                        foreach ($orderCollection['orderDetails'] as $orderDetail) {
                            $productName = $orderDetail['invoice_name'];
                            foreach ($orderDetail['order_specifications'] as $specification) {
                                if ($specification['specification']['specification_category']['type'] == 2) {
                                    $productName = $productName . " " . $specification['name_specification'];
                                    break;
                                }
                            }
                            // Data del item de la factura
                            array_push(
                                $newInvoiceItems,
                                [
                                    "invoice_id" => $invoice->id,
                                    "product_name" => $productName,
                                    "quantity" => $orderDetail['quantity'],
                                    "base_value" => Helper::bankersRounding($orderDetail['base_value'], 0),
                                    "total" => Helper::bankersRounding($orderDetail['total'], 0),
                                    "has_iva" => $orderDetail['tax_values']['has_iva'],
                                    "compound_key" => $orderDetail['compound_key'],
                                    "order_detail_id" => $orderDetail['id'],
                                    "created_at" => $orderInfo["created_at"],
                                    "updated_at" => $orderInfo["created_at"]
                                ]
                            );
                        }

                        // Creando los nuevos items de la factura
                        InvoiceItem::insert($newInvoiceItems);
                         // Consumo de stock de inventario a partir del contenido de la orden
                        $this->reduceComponentsStock($order);
                        $this->reduceComponentsStockBySpecification($order);
                    }
                    
                   

                    // Envío de enventos websockets
                
                    event(new OrderCreated($order->id));
                    event(new SimpleOrderCreated($storeId));
                    
                    if($orderInfo["automatic"]){
                        event(new OrderUpdatedComanda($order));
                        if ($hub != null) {
                            event(new HubIntegrationOrderCreated($hub, $invoice));
                        }
                        // Impresión de la orden
                        if ($storeConfig->uses_print_service) {
                            // Imprimir por microservicio
                            PrintServiceHelper::printComanda($order, $employee);
                            PrintServiceHelper::printInvoice($invoice, $employee);
                        }

                        // Send firebase ppush notification. Refactorizar esta función para no usar esto para impresiones
                        // sino para notificaciones de las nuevas órdenes, cambios de estados(cancelaciones, cambios, etc)
                        // $this->sendIntegrationOrder($order, 'Didi Food');
                    }
                    
                    $order->load('spot','orderDetails.orderSpecifications.specification.specificationCategory','employee','orderIntegrationDetail','invoice','orderConditions','orderStatus');
                    foreach ($order->orderDetails as $detail) {
                        $detail->consumption="";
                        if(!$orderInfo["automatic"]){
                            $detail->append('spec_fields');
                        }
                    }
                    $job = array();
                    $job["store_id"] = $storeId;
                    $job["order"] = $order;

                    QueueHelper::dispatchJobs(array($job));

                    // Send firebase push notification. Refactorizar esta función para no usar esto para impresiones
                    // sino para notificaciones de las nuevas órdenes, cambios de estados(cancelaciones, cambios, etc)
                    // $this->sendIntegrationOrder($order, 'Didi Food');

                    // Log Action on Model
                    $obj = [
                        'action' => "INTEGRAR",
                        'model' => "ORDER",
                        'user_id' => $employee->id,
                        'model_id' => $order->id,
                        'model_data' => [
                            'store_id' => $storeId,
                            'integration' => $integration->name
                        ]
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);

                    return ([
                        "message" => "Orden creada en myPOS",
                        "slackMessage" => $slackMessage,
                        "status" => $status,
                        "data" => [
                            "order" => $order,
                            "invoice" => $orderInfo["automatic"] ? $invoice:''
                        ]
                    ]);
                },
                10 // Veces para reintento cuando un deadlock ocurre
            );
            return $resultTransaction;
        } catch (\Exception $e) {
            Logging::printLogFile(
                "ERROR GUARDAR ORDEN " . $integration->name . ", para el store: " . $storeName,
                $channelLog,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($orderInfo, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE)
            );

            event(new SimpleOrderFailed($storeId));

            $errorMessage = addslashes($e->getMessage());
            if (strpos($errorMessage, 'SQLSTATE') !== false) {
                $errorMessage = "Error al intentar guardar un dato no correcto en la BD";
            }

            // Log Action on Model
            $obj = [
                'action' => "ERROR_INTEGRAR",
                'model' => "ORDER",
                'user_id' => "1",
                'model_id' => $orderInfo["external_id"],
                'model_data' => [
                    'integration' => $integration->name,
                    'external_store_id' => $orderInfo["external_store_id"]
                ]
            ];                    
            
            ActionLoggerJob::dispatch($obj);

            if ($slackMessage == "") {
                $slackMessage = "Error al guardar la orden de " . $integration->name . " en myPOS\n" .
                    "Tienda: " . $storeName . "\n" .
                    "Error: " . $errorMessage;
            }
            return ([
                "message" => $errorMessage,
                "slackMessage" => $slackMessage,
                "status" => $status == 1 ? 0 : $status,
                "data" => null
            ]);
        }
    }

    /**
     * Obtiene el empleado de integración de una tienda
     *
     * @param integer $storeId  Id de la tienda en la cual se va a crear la orden
     *
     * @return Employee  Empleado de integración de la tienda
     *
     */
    public function getEmployeeIntegration($storeId)
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

    /**
     * Obtiene la data de la integración para la tienda
     *
     * @param integer $storeId   Id de la tienda en la cual se va a obtener la data de integración
     * @param string  $codeName  Nombre en código de la integración
     *
     * @return array  Contiene la data de la integración
     *
     */
    public function getIntegrationConfiguration($storeId, $codeName)
    {
        // Status
        // 0: Error
        // 1: Éxito
        $integrationData = AvailableMyposIntegration::where('code_name', $codeName)->first();

        if ($integrationData == null) {
            return ([
                "message" => "myPOS no tiene configurado esta integración",
                "status" => 0,
                "data" => null
            ]);
        }

        $integrationToken = StoreIntegrationToken::where('store_id', $storeId)
            ->where('integration_name', $integrationData->code_name)
            ->where('type', 'delivery')
            ->first();

        if ($integrationToken == null) {
            return ([
                "message" => "Esta tienda no tiene token",
                "status" => 0,
                "data" => null
            ]);
        }

        $externalDataStore = StoreIntegrationId::where('store_id', $storeId)
                ->where('integration_id', $integrationData->id)
                ->first();

        if ($externalDataStore == null) {
            return ([
                "message" => "Esta tienda no está configurada para usar esta integración",
                "status" => 0,
                "data" => null
            ]);
        }

        $config = StoreConfig::where('store_id', $storeId)
                ->first();

        if ($config == null) {
            return ([
                "message" => "Esta tienda no está configurada dentro de myPOS",
                "status" => 0,
                "data" => null
            ]);
        }

        return ([
            "status" => 1,
            "data" => [
                "integrationData" => $integrationData,
                "integrationToken" => $integrationToken,
                "integrationExternalStore" => $externalDataStore,
                "storeConfig" => $config
            ],
            "message" => null
        ]);
    }
}
