<?php

namespace App\Traits\Mely;

use Log;
use App\Spot;
use App\Order;
use App\Store;
use App\Helper;
use App\Billing;
use App\Invoice;
use App\Payment;
use App\Product;
use App\Section;
use App\Employee;
use App\StoreTax;
use App\Component;
use Carbon\Carbon;
use App\InvoiceItem;
use App\OrderDetail;
use App\PaymentType;
use App\StoreConfig;
use App\ProductDetail;
use App\Specification;
use App\CashierBalance;
use App\ComponentStock;
use App\ProductCategory;
use App\InvoiceTaxDetail;
use App\ProductComponent;
use App\ComponentCategory;
use App\ComponentVariation;
use App\SectionIntegration;
use App\Traits\OrderHelper;
use App\Events\OrderCreated;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use App\ProductSpecification;
use Illuminate\Support\Facades\DB;
use App\Traits\Aloha\AlohaOrder;
use App\Traits\LoggingHelper;
use App\Traits\Logs\Logging;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\OrderIntegrationDetail;
use App\Events\SimpleOrderFailed;
use App\OrderDetailProcessStatus;
use App\ProductIntegrationDetail;
use App\ToppingIntegrationDetail;
use App\AvailableMyposIntegration;
use App\Events\SimpleOrderCreated;
use App\Events\HubIntegrationOrderCreated;
use App\OrderProductSpecification;
use App\ProductToppingIntegration;
use App\Helpers\PrintService\PrintServiceHelper;
use App\Events\OrderCreatedComanda;
use App\Events\OrderUpdatedComanda;
use App\ProductsConnectionIntegration;
use App\Events\IntegrationOrderCreated;

use App\Http\Helpers\QueueHelper;
use App\Jobs\ActionLoggerJob;

use Illuminate\Http\Request as HttpRequest;

trait MelyIntegration
{

    public static function setIntegrationMelyOrder($order, $externalStoreId, $externalCustomerId, $customerName, $billingId)
    {
        $orderIntegration = new OrderIntegrationDetail();
        $orderIntegration->integration_name = "mely";
        $orderIntegration->external_order_id = $order->external_id;
        $orderIntegration->external_store_id = $externalStoreId;
        $orderIntegration->external_customer_id = $externalCustomerId;
        $orderIntegration->external_created_at = $order->created_at;
        $orderIntegration->billing_id = $billingId;
        $orderIntegration->number_items = 0;
        $orderIntegration->value = $order->total;
        $orderIntegration->customer_name = $customerName;
        $orderIntegration->order_number = $order->order_number;
        $orderIntegration->save();
        return $orderIntegration;
    }


    public static function getEmployeeMely($store)
    {
        $employee = Employee::where('store_id', $store->id)
            ->where('name', "third_party_integration")
            ->first();
        if (!$employee) {
            $employee = new Employee();
            $nameStoreStripped = str_replace(' ', '', $store->name);
            $employee->name = "third_party_integration";
            $employee->store_id = $store->id;
            $employee->email = 'integracion_thirdparty@' . strtolower($nameStoreStripped) . '.com';
            $employee->password = '$2y$10$XBl3VT7NVYSDHnGJVRmlnumOv3jDjZKhfidkcss8GeWt0NIYwFU42';
            $employee->type_employee = 3;
            $employee->save();
        }
        return $employee;
    }


    public static function processtIntegrationMelyOrder($storeToken, $cashierBalance, $sectionIntegration, $orderObj, $integrationName = "Mely")
    {
        try {

            $existsOrder = Order::where('status', 1)
                ->where('preorder', 0)
                ->whereHas('orderIntegrationDetail', function ($integrations) use ($orderObj) {
                    $integrations->where('external_order_id', $orderObj->external_id);
                })->first();

            if ($existsOrder) {
                $response = MelyIntegration::acceptOrderMely($orderObj->external_id, $storeToken, 0);
                if (!$response) {
                    return [
                        'status' => false,
                        'message' => "No se pudo aceptar la orden"
                    ];
                }

                return [
                    'status' => true,
                    'message' => "La orden ya había sido creada anteriormente"
                ];
            }

            Log::info("processtIntegrationMelyOrder");
            $integratedOrder = MelyIntegration::createMelyOrder($storeToken, $cashierBalance, $sectionIntegration, $orderObj, $integrationName);
            if (!$integratedOrder) {
                MelyIntegration::rejectOrderMely($orderObj->external_id, $storeToken, 0, "No se pudo crear la orden");
                return [
                    'status' => false,
                    'message' => "No se pudo crear la orden"
                ];
            } else {
                $response = MelyIntegration::acceptOrderMely($orderObj->external_id, $storeToken, 0);
                if (!$response) {
                    return [
                        'status' => false,
                        'message' => "No se pudo aceptar la orden"
                    ];
                }
                OrderHelper::reduceComponentsStockStatic($integratedOrder);
                OrderHelper::reduceComponentsStockBySpecificationStatic($integratedOrder);
                event(new OrderCreatedComanda($integratedOrder));
                $storeConfig = StoreConfig::where('store_id', $storeToken->store->id)->first();
                $employee = MelyIntegration::getEmployeeMely($storeToken->store);
                if ($storeConfig) {
                    if ($storeConfig->uses_print_service) {
                       // Imprimir por microservicio
                       PrintServiceHelper::printComanda($integratedOrder, $employee);
                    } else {
                        // Send firebase push notification
                        MelyIntegration::sendIntegrationOrder($integratedOrder, 'Mely');
                    }
                }
                return [
                    'status' => true,
                    'message' => "Se creo la orden emitida por Rappi correctamente"
                ];
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "MelyIntegration receiveWebhookMelyOrder NO SE PUDO GUARDAR LA ORDER DE Mely",
                'mely_orders_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($orderObj)
            );
            return [
                'status' => false,
                'message' => "No se pudo aceptar la orden"
            ];
        }
    }
    public static function createMelyOrder($storeToken, $cashierBalance, $sectionIntegration, $orderObj, $integrationName = "Mely")
    {
        return DB::transaction(
            function () use ($storeToken, $orderObj, $cashierBalance, $sectionIntegration, $integrationName) {
                $clientObj = json_decode(json_encode($orderObj['customer_client']), FALSE);
                $storeObj = $storeToken->store;
                $products = json_decode(json_encode($orderObj['items']), FALSE);
                $store = $storeToken->store;

                if (!is_null($clientObj) && isset($clientObj->firstName) && isset($clientObj->lastName)) {
                    $customerIdentifier = '9999999999999';
                    if (isset($clientObj->identifier)) {
                        $customerIdentifier = $clientObj->identifier;
                    }
                    $billing = Billing::firstOrCreate([
                        'document' => $customerIdentifier,
                        'name'     => $clientObj->firstName . " " . $clientObj->lastName
                    ]);
                    if (isset($clientObj->email)) {
                        $billing->email = $clientObj->email;
                    }
                    if (isset($clientObj->phone)) {
                        $billing->phone = $clientObj->phone;
                    }
                    if (isset($clientObj->address)) {
                        $billing->address = $clientObj->address;
                    }
                    $billing->save();
                } else {
                    $customerName = 'CONSUMIDOR FINAL';
                    if (isset($orderObj->customer)) {
                        $customerName = $orderObj->customer;
                    }
                    $billing = Billing::firstOrCreate([
                        'document' => '9999999999999',
                        'name'     => $customerName
                    ]);
                }
                $integrationOrder = MelyIntegration::setIntegrationMelyOrder($orderObj, $storeToken->token, $billing->document, $billing->name, $billing->id);
                $identifier = Helper::getNextOrderIdentifier($store->id);
                $order = null;
                $discountedTotal = 0;
                if ($integrationOrder) {
                    $employee = MelyIntegration::getEmployeeMely($store);
                    $spot = Spot::where('store_id', $store->id)
                        ->where('origin', Spot::ORIGIN_MELY)
                        ->first();
                    if (!$spot) {
                        $spot = new Spot();
                        $spot->name = "Third Party";
                        $spot->store_id = $store->id;
                        $spot->origin = Spot::ORIGIN_MELY;
                        $spot->save();
                    }
                    $discountedTotal = $orderObj->discount_value;

                    $discountPercentage = 100.0 - ($orderObj->total_with_discount * 100.0 / $integrationOrder->value);
                
                    $now = Carbon::now()->toDateTimeString();
                    $order = new Order();
                    $order->employee_id = $employee->id;
                    $order->status = 1;
                    $order->food_service = 0;
                    $order->identifier = $identifier;
                    $order->order_value = $integrationOrder->value;
                    $order->cash = 0;
                    $order->spot_id = $spot->id;
                    $order->store_id = $store->id;
                    $order->base_value = $orderObj->total_with_discount;
                    $order->total = $orderObj->total_with_discount;
                    $order->current_status = "Creada";
                    $order->billing_id = $billing->id;
                    $order->cashier_balance_id = $cashierBalance->id;
                    $order->preorder = 0;
                    $order->discount_percentage = $discountPercentage;
                    $order->discount_value = $discountedTotal;
                    $order->undiscounted_base_value = $integrationOrder->value;
                    $order->no_tax_subtotal = 0.00;
                    $order->change_value = 0;
                    $order->save();

                    $payment = new Payment();
                    $payment->total = $integrationOrder->value;
                    $payment->order_id = $order->id;
                    $payment->created_at = $now;
                    $payment->updated_at = $now;
                    $payment->type = PaymentType::CREDIT;
                    $payment->save();

                    $integrationOrder->order_id = $order->id;
                    $counterItems = 0;


                    foreach ($products as $newProduct) {
                        $counterItems = $counterItems + 1;
                        $productMyposResponse = MelyIntegration::insertProductFromObject($newProduct, $sectionIntegration->section_id, $storeToken);
                        $productMypos = $productMyposResponse['product'];
                        $productDetailMypos = $productMyposResponse['detail'];
                        $specificationIdsQuantity = $productMyposResponse['specifications'];
                        if ($productMypos && $productDetailMypos) {
                          
                            $orderDetail = new OrderDetail();
                            $orderDetail->order_id = $order->id;
                            $orderDetail->product_detail_id = $productDetailMypos->id;
                            $orderDetail->quantity = $newProduct->quantity;
                            $orderDetail->status = 1;
                            $orderDetail->value = intval($newProduct->total_unit_value);
                            $orderDetail->name_product = $newProduct->name;
                            $orderDetail->instruction = $newProduct->instructions ? $newProduct->instructions : "";
                            $orderDetail->total = intval($newProduct->total_value);
                            $orderDetail->value = intval($newProduct->total_unit_value);
                            $orderDetail->base_value = intval($newProduct->total_unit_value) ;
                            $orderDetail->compound_key = $productDetailMypos->id;
                            $orderDetail->invoice_name = mb_substr($newProduct->name, 0, 25, "utf-8");
                            $orderDetail->save();
                          
                            $orderProcessStatus = new OrderDetailProcessStatus();
                            $orderProcessStatus->process_status = 1;
                            $orderProcessStatus->order_detail_id = $orderDetail->id;
                            $orderProcessStatus->save();

                            if ($specificationIdsQuantity->contains("priority", 1)) {
                                $sortedBy = 'priority';
                            } else {
                                $sortedBy = 'id';
                            }
                            $sortedById = $specificationIdsQuantity->sortBy($sortedBy);
                            $compoundKey = strval($productDetailMypos->id);
                            $totalOrderDetailValue = 0;
                         
                            foreach ($sortedById as $option) {
                                if ($option['quantity'] > 0) {
                                    OrderProductSpecification::create(
                                        [
                                            'specification_id' => $option['id'],
                                            'name_specification' => $option['name_specification'],
                                            'value' => $option['value'],
                                            'order_detail_id' => $orderDetail->id,
                                            'quantity' => intval($option['quantity']),
                                        ]
                                    );
                                    $compoundKey = $compoundKey . '_' . strval($option['id']) . '_' . strval($option['quantity']);
                                    $totalOrderDetailValue += (intval($option['value']) * intval($option['quantity']));
                                }
                            }
                           
                            if ($totalOrderDetailValue != 0) {
                                $totalOrderDetailValue += (intval($newProduct->unit_value));
                                $orderDetail->value = $totalOrderDetailValue;
                            }
                            
                            $orderDetail->compound_key = $compoundKey;
                            $orderDetail->save();
                        } else {
                            Logging::printLogFile(
                                "MelyIntegration PRODUCT",
                                'mely_orders_logs',
                                'Mo se pudo crear/guardar el producto',
                                'MelyIntegration.php',
                                313,
                                json_encode($newProduct)
                            );
                        }
                    }
          
                    foreach ($order->orderDetails as $detail) {
                        $taxes = $detail->productDetail->product->taxes;
                        foreach ($taxes as $tax) {
                            if (
                                $tax->store_id == $order->store->id
                                && $tax->type === 'included'
                                && $tax->enabled
                            ) {
                                $tax->is_main = 1;
                            }
                        }
                    }
                  
                    $order = OrderHelper::calculateOrderValuesIntegrationStatic($order, 'mely');
                    $invoice = MelyIntegration::setMelyInvoice($order, $billing, $order->store);
                    $integrationOrder->number_items = $counterItems;
                    $integrationOrder->save();
               
                    PrintServiceHelper::printInvoice($invoice, $employee);
                    
                    $order->load('spot','orderDetails.orderSpecifications.specification.specificationCategory','employee','orderIntegrationDetail','invoice','orderConditions','orderStatus');
                    $job = array();
                    $job["store_id"] = $store->id;
                    $job["order"] = $order;

                    QueueHelper::dispatchJobs(array($job));

                    // Enviando orden a Aloha
                    //$result = $this->uploadOrder($order->store->id, $order, 1, "Mely");

                    //Envía a las integraciones de backoffice
                    OrderHelper::prepareToSendForElectronicBillingStatic(
                        $store,
                        $invoice,
                        AvailableMyposIntegration::NAME_MELY,
                        null,
                        null,
                        [
                            'cashier' => null,
                            'invoice' => $invoice
                        ]
                    );
                    
                    event(new OrderCreated($order->id));
                    event(new SimpleOrderCreated($store->id));

                    if ($store->hubs != null && $store->hubs->first() != null) {
                        event(new HubIntegrationOrderCreated($store->hubs->first(), $invoice));
                    }

                    // Log Action on Model
                    $obj = [
                        'action' => "INTEGRAR",
                        'model' => "ORDER",
                        'user_id' => $employee->id,
                        'model_id' => $order->id,
                        'model_data' => [
                            'store_id' => $store->id,
                            'integration' => $integrationName
                        ]
                    ];                    
                    
                    ActionLoggerJob::dispatch($obj);

                    return $order;
                }
                return;
            }
        );
    }

    public static function insertProductFromObject($newProduct, $section_id, $storeToken)
    {
        $store = $storeToken->store;
        $productsRequested = ProductIntegrationDetail::where('product_id', $newProduct->external_id)
            ->where('integration_name', 'mely_' . $storeToken->scope)
            ->get();

        $myposProduct = null;
        $productCreated = null;
        $specificationIdsQuantity = collect([]);
        foreach ($productsRequested as $iteratorProduct) {
            $productDetailFound = ProductDetail::where('product_id', $iteratorProduct->product->id)
                ->where('store_id', $store->id)
                ->where('status', 1)
                ->whereHas('product', function ($product) use ($section_id) {
                    $product->whereHas('category', function ($pCategory) use ($section_id) {
                        $pCategory->where('section_id', $section_id);
                    });
                })
                ->first();
            if ($productDetailFound) {
                $myposProduct = $productDetailFound->product;
                break;
            }
        }
        if (!is_null($myposProduct)) {
            MelyIntegration::assignMelyGlobalTaxes($myposProduct, $store);
            $specificationIdsQuantity = MelyIntegration::setMelyComponentsFromProduct($newProduct, $storeToken, $section_id);
        } else {
            //Se busca por nombre de producto dentro de la sección de la tienda
            $productDetailFound = ProductDetail::where('store_id', $store->id)
                ->where('status', 1)
                ->whereHas('product', function ($product) use ($section_id, $newProduct) {
                    $product->where('name', $newProduct->name)->whereHas('category', function ($pCategory) use ($section_id) {
                        $pCategory->where('section_id', $section_id);
                    });
                })
                ->first();
         
            if ($productDetailFound) {
               
                $myposProduct = $productDetailFound->product;
                MelyIntegration::assignMelyGlobalTaxes($myposProduct, $store);
                $myposProduct->modifiers = $newProduct->modifiers;
                $specificationIdsQuantity = MelyIntegration::setMelyComponentsFromProduct($myposProduct, $storeToken, $section_id);
            } else {
                //el producto no existe
                $category = "Productos";
                if (isset($newProduct->category_name)) {
                    $category = $newProduct->category_name;
                }
                $categoryMypos = ProductCategory::where('name', 'like', $category)
                    ->where('company_id', $store->company->id)
                    ->where('section_id', $section_id)
                    ->withTrashed()
                    ->first();

                if (is_null($categoryMypos)) {
                    $categoryMypos = new ProductCategory();
                    $categoryMypos->name = $category;
                    $categoryMypos->search_string = $category;
                    $categoryMypos->priority = 0;
                    $categoryMypos->status = 1;
                    $categoryMypos->company_id = $store->company->id;
                    $categoryMypos->subtitle = "";
                    $categoryMypos->section_id = $section_id;
                    $categoryMypos->save();
                }
                $myposProductResponse = MelyIntegration::setMelyProduct($newProduct->name, $newProduct->unit_value, $categoryMypos->id, $store);
                $myposProduct = $myposProductResponse['product'];
                $productDetailFound= $myposProductResponse['detail'];
                $newProduct->id= $myposProduct->id;
                $specificationIdsQuantity = MelyIntegration::setMelyComponentsFromProduct($newProduct, $storeToken, $section_id);
             
            }
        }
        return array(
            'product' => $myposProduct,
            'detail' => $productDetailFound,
            'specifications' => $specificationIdsQuantity
        );
    }

    public static function setMelyProduct($name, $baseValue, $categoryId, $store)
    {
        $product = new Product();
        $product->product_category_id = $categoryId;
        $product->name = $name;
        $product->search_string = $name;
        $product->priority = 0;
        $product->base_value = $baseValue;
        $product->status = 1;
        $product->ask_instruction = 0;
        $product->invoice_name = mb_substr($name, 0, 25, "utf-8");
        $product->save();

        //Asignamos los impuestos globales de la tienda al producto
        MelyIntegration::assignMelyGlobalTaxes($product, $store);

        if ($product) {
            $productDetail = new ProductDetail();
            $productDetail->product_id = $product->id;
            $productDetail->store_id = $store->id;
            $productDetail->stock = 0;
            $productDetail->value = $baseValue;
            $productDetail->status = 1;
            $productDetail->save();
        }
        $response = array(
            'product' => $product,
            'detail' => $productDetail
        );
        return $response;
    }

    /**
     * Función encargada de asignar los impuestos globales a un producto dado
     */
    public static function assignMelyGlobalTaxes(Product $product, Store $store)
    {
        //Traemos todos los impuestos globales existentes para la tienda
        $storeTaxes = StoreTax::where('store_id', $store->id)
            ->where('is_main', 1)->get();
        //asignamos todos los impuestos globales al producto
        foreach ($storeTaxes as $storeTax) {
            $product->taxes()->syncWithoutDetaching([$storeTax->id]);
        }
    }

    public static function setMelyComponentsFromProduct($newProduct, $storeToken, $section_id)
    {   
        $specificationIdsQuantity = collect([]);
        try {
            $toppings = [];
            if (isset($newProduct->modifiers)) {
                $toppings = $newProduct->modifiers;
            }
            foreach ($toppings as $topping) {
                $toppingIntegration = ToppingIntegrationDetail::where('integration_name', 'mely_' . $storeToken->scope)
                    ->whereHas(
                        'specification',
                        function ($specification) use ($section_id, $topping) {
                            $specID = isset($topping->specification_id) ? $topping->specification_id : $topping->external_id;
                            $specID = str_replace('_spec', '', $specID);
                            $specification->where('id', $specID)->where('status', 1)->whereHas(
                                'specificationCategory',
                                function ($specCat) use ($section_id) {
                                    $specCat->where('section_id', $section_id);
                                }
                            );
                        }
                    )->first();
                if (!is_null($toppingIntegration)) {
                    $myposSpecification = Specification::find($toppingIntegration->specification_id);
                    $specIdQuantity = [
                        'id' => $myposSpecification->id,
                        'quantity' => $topping->quantity,
                        'name_specification' => $topping->name,
                        'priority' => $myposSpecification->specificationCategory->priority,
                        'value' => intval($topping->unit_value)
                    ];
                    $specificationIdsQuantity->push($specIdQuantity);
                } else {
                    if (!isset($topping->category_name)) {
                        $topping->category_name = "General";
                    }
                    $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $topping->category_name)
                        ->where('company_id', $storeToken->store->id)
                        ->where('section_id', $section_id)
                        ->first();
                    if (!$myposSpecificationCategory) {
                        $myposSpecificationCategory = MelyIntegration::setMelySpecificationCategory(
                            $topping->category_name,
                            $storeToken->store->company->id,
                            $section_id
                        );
                    }
                    $myposSpecification = Specification::where('specification_category_id', $myposSpecificationCategory->id)
                        ->where('name', 'like', $topping->name)
                        ->where('id', $topping->external_id)
                        ->first();
                    if (is_null($myposSpecification)) {
                        $myposSpecification = new Specification();
                        $myposSpecification->name = $topping->name;
                        $myposSpecification->specification_category_id = $myposSpecificationCategory->id;
                        $myposSpecification->status = 1;
                        $myposSpecification->value = intval($topping->unit_value);
                        $myposSpecification->save();

                        $productSpecification = new ProductSpecification();
                        $productSpecification->product_id = $newProduct->id;
                        $productSpecification->specification_id = $myposSpecification->id;
                        $productSpecification->status = 1;
                        $productSpecification->value = intval($topping->unit_value);
                        $productSpecification->save();

                        $toppingIntegration = MelyIntegration::insertToppingFromMelyObject(
                            $topping,
                            $myposSpecification,
                            'mely_' . $storeToken->scope
                        );
                    } else {
                        $pes = ProductSpecification::where('product_id', $newProduct->external_id)
                            ->where('specification_id', $myposSpecification->id)->where("status", 1)->first();
                        if ($pes == null) {
                            $productSpecification = new ProductSpecification();
                            $productSpecification->product_id = $newProduct->external_id;
                            $productSpecification->specification_id = $myposSpecification->id;
                            $productSpecification->status = 1;
                            $productSpecification->value = intval($topping->unit_value);
                            $productSpecification->save();
                        }
                        $toppingIntegration = MelyIntegration::insertToppingFromMelyObject(
                            $topping,
                            $myposSpecification,
                            'mely_' . $storeToken->scope
                        );
                    }
                    $specIdQuantity = [
                        'id' => $myposSpecification->id,
                        'quantity' => $topping->quantity,
                        'name_specification' => $topping->name,
                        'priority' => $myposSpecification->specificationCategory->priority,
                        'value' => intval($topping->unit_value)
                    ];
                    $specificationIdsQuantity->push($specIdQuantity);
                }
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "MelyIntegration setMelyComponentsFromProduct",
                'mely_orders_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($newProduct)
            );
        }
        return $specificationIdsQuantity;
    }

    public static function insertToppingFromMelyObject($topping, $myposSpecification, $name)
    {
        $toppingIntegration = ToppingIntegrationDetail::where('specification_id', $myposSpecification->id)
            ->where('integration_name', $name)
            ->first();
        if (!$toppingIntegration) {
            $toppingIntegration = new ToppingIntegrationDetail();
            $toppingIntegration->specification_id = $myposSpecification->id;
            $toppingIntegration->integration_name = $name;
            $toppingIntegration->name = $topping->name;
            $toppingIntegration->price = intval($topping->unit_value) ;
            $toppingIntegration->type = $topping->type?? null;
            $toppingIntegration->subtype = !isset($topping->category_name) ? 'Topping' : $topping->category_name;
            $toppingIntegration->external_topping_category_id = isset($topping->external_category_id) ? $topping->external_category_id : null;
            $toppingIntegration->save();
        }
        return $toppingIntegration;
    }

    public static function setMelySpecificationCategory($categoryName, $companyId, $section_id = null)
    {
        $category = new SpecificationCategory();
        $category->company_id = $companyId;
        $category->name = $categoryName;
        $category->priority = 0;
        $category->required = 1;
        $category->status = 1;
        $category->max = 1;
        $category->show_quantity = 1;
        $category->type = 1;
        $category->subtitle = "";
        $category->section_id = $section_id;
        $category->save();
        return $category;
    }

    public static function setMelyInvoice($order, $billing, $store)
    {
        $invoiceNumber = Helper::getNextBillingOfficialNumber($store->id, true);
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

        OrderHelper::populateInvoiceTaxDetailsStatic($order, $invoice);
        $invoice->load('order', 'billing', 'items', 'taxDetails');
        return $invoice;
    }

    public static function acceptOrderMely($melyOrderID, $integrationToken, $tries, $customBody = null)
    {
        if ($tries < 2) {
            $gotAccepted = false;
            if ($integrationToken->password !== null || $integrationToken->anton_password !== null) {
                $password = $integrationToken->password;
                if ($integrationToken->anton_password !== null && $integrationToken->is_anton == true) {
                    $password = $integrationToken->anton_password;
                }
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => $password
                ];

                $response = null;
                try {
                    $baseUrl = config('app.mely_url_api');
                    $client = new Client();
                    Log::info("____________________________" . json_encode($customBody));
                    if ($customBody != null) {
                        $data = [
                            'delivery_id' => $customBody['delivery_id'],
                            'store_id' => $customBody['store_id'],
                            'order_external_id' => $melyOrderID,
                        ];
                    } else {
                        $data = [
                            'delivery_id' => $integrationToken->scope,
                            'store_id' => strval($integrationToken->token_type),
                            'order_external_id' => $melyOrderID,
                        ];
                    }
                    $response = $client->request(
                        'POST',
                        $baseUrl . "/api/v1/integration/order/accept",
                        [
                            'headers' => ['Content-type' => 'application/json', 'Authorization' => $password],
                            'json' => $data,
                            'http_errors' => false
                        ]
                    );
                    if ($response->getStatusCode() === 200) {
                        $gotAccepted = true;
                        Log::info("Orden Mely Aceptada: " . $melyOrderID);
                    } else if ($response->getStatusCode() !== 409) {
                        Logging::printLogFile(
                            "MelyIntegration ERROR En aceptar orden !=409",
                            'mely_orders_logs',
                            json_encode($response->getBody()->getContents()),
                            'MeliIntegration.php',
                            702,
                            json_encode($melyOrderID)
                        );
                        $tries = $tries + 1;
                        $gotAccepted = MelyIntegration::acceptOrderMely($melyOrderID, $integrationToken, $tries, $customBody);
                    } else {
                        Logging::printLogFile(
                            "MelyIntegration ERROR En aceptar orden !=409",
                            'mely_orders_logs',
                            json_encode($response->getBody()->getContents()),
                            'MeliIntegration.php',
                            702,
                            json_encode($melyOrderID)
                        );
                        $gotAccepted = false;
                    }
                } catch (\Exception $e) {
                    Logging::printLogFile(
                        "MelyIntegration ERROR En aceptar orden",
                        'mely_orders_logs',
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($melyOrderID)
                    );
                    if ($response !== 409) {
                        $tries = $tries + 1;
                        $gotAccepted = MelyIntegration::acceptOrderMely($melyOrderID, $integrationToken, $tries, $customBody);
                    } else {
                        $gotAccepted = false;
                    }
                }
            }
        } else {
            $gotAccepted = false;
        }
        return $gotAccepted;
    }

    public static function rejectOrderMely($melyOrderID, $integrationToken, $tries, $msg, $customBody = null)
    {
        if ($tries < 2) {
            $gotAccepted = false;
            if ($integrationToken->password !== null || $integrationToken->anton_password !== null) {
                $password = $integrationToken->password;
                if ($integrationToken->anton_password !== null && $integrationToken->is_anton == true) {
                    $password = $integrationToken->anton_password;
                }
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => $password
                ];

                $response = null;
                try {
                    $baseUrl = config('app.mely_url_api');
                    $client = new Client();
                    if ($customBody != null) {
                        $data = [
                            'delivery_id' => $customBody['delivery_id'],
                            'store_id' => $customBody['store_id'],
                            'order_external_id' => $melyOrderID,
                            'message' => $msg
                        ];
                    } else {
                        $data = [
                            'delivery_id' => $integrationToken->scope,
                            'store_id' => strval($integrationToken->token_type),
                            'order_external_id' => $melyOrderID,
                            'message' => $msg
                        ];
                    }

                    $response = $client->request(
                        'POST',
                        $baseUrl . "/api/v1/integration/order/deny",
                        [
                            'headers' => ['Content-type' => 'application/json', 'Authorization' => $password],
                            'json' => $data,
                            'http_errors' => false
                        ]
                    );
                    if ($response->getStatusCode() === 200) {
                        $gotAccepted = true;
                        Log::info("Orden Mely RECHAZAR: " . $melyOrderID . " MESSAGE: " . $msg);
                        // Log Action on Model
                        $obj = [
                            'action' => "ERROR_INTEGRAR",
                            'model' => "ORDER",
                            'user_id' => $data['store_id'],
                            'model_id' => $melyOrderID,
                            'model_data' => [
                                'integration' => "Mely",
                            ]
                        ];                    
                        
                        ActionLoggerJob::dispatch($obj);
                    } else if ($response->getStatusCode() !== 409) {
                        Logging::printLogFile(
                            "MelyIntegration ERROR En RECHAZAR orden. !=409 " . $msg,
                            'mely_orders_logs',
                            json_encode($response->getBody()->getContents()),
                            'MeliIntegration.php',
                            702,
                            json_encode($melyOrderID)
                        );

                        $tries = $tries + 1;
                        $gotAccepted = MelyIntegration::rejectOrderMely($melyOrderID, $integrationToken, $tries, $msg, $customBody);
                    } else {
                        Logging::printLogFile(
                            "MelyIntegration ERROR En RECHAZAR orden 409 " . $msg,
                            'mely_orders_logs',
                            json_encode($response->getBody()->getContents()),
                            'MeliIntegration.php',
                            702,
                            json_encode($melyOrderID)
                        );
                        $gotAccepted = false;
                    }
                } catch (\Exception $e) {
                    Logging::printLogFile(
                        "MelyIntegration ERROR En RECHAZAR orden",
                        'mely_orders_logs',
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($melyOrderID)
                    );
                    if ($response !== 409) {
                        $tries = $tries + 1;
                        $gotAccepted = MelyIntegration::rejectOrderMely($melyOrderID, $integrationToken, $tries, $msg, $customBody);
                    } else {
                        $gotAccepted = false;
                    }
                }
            }
        } else {
            $gotAccepted = false;
        }
        return $gotAccepted;
    }
}