<?php

namespace App\Traits;

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
use GuzzleHttp\Psr7;
use App\ProductDetail;
use App\Specification;
use GuzzleHttp\Client;
use App\CashierBalance;
use App\ComponentStock;
use App\ProductCategory;
use App\InvoiceTaxDetail;
use App\ProductComponent;
use App\ComponentCategory;
use App\SectionIntegration;
use App\Traits\OrderHelper;
use App\Events\OrderCreated;
use GuzzleHttp\Psr7\Request;
use App\ProductSpecification;
use App\Traits\LoggingHelper;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\OrderIntegrationDetail;
use App\Traits\PushNotification;
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
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request as HttpRequest;
use App\Traits\Aloha\AlohaOrder;
use Illuminate\Support\Facades\DB;
use App\Http\Helpers\QueueHelper;

trait RappiIntegration
{
    use OrderHelper, PushNotification;
    use LoggingHelper, AlohaOrder {
        LoggingHelper::logError insteadof AlohaOrder;
        LoggingHelper::simpleLogError insteadof AlohaOrder;
        LoggingHelper::logIntegration insteadof AlohaOrder;
        LoggingHelper::printLogFile insteadof AlohaOrder;
        LoggingHelper::getSlackChannel insteadof AlohaOrder;
        LoggingHelper::sendSlackMessage insteadof AlohaOrder;
        LoggingHelper::logModelAction insteadof AlohaOrder;
    }

    public function getEmployeeRappiIntegration($storeId)
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
            $employee->email = 'integracion@' . strtolower($nameStoreStripped) . '.com';
            $employee->password = '$2y$10$XBl3VT7NVYSDHnGJVRmlnumOv3jDjZKhfidkcss8GeWt0NIYwFU42';
            $employee->type_employee = 3;
            $employee->save();
        }
        return $employee;
    }

    public function setRappiAPIURL($store)
    {
        if (config('app.env') !== 'production') {
            $rappiEndpoint = config('app.rappi_dev_api');
        } else {
            switch ($store->country_code) {
                case 'AR':
                    $rappiEndpoint = config('app.rappi_prod_api_ar');
                    break;
                case 'MX':
                    $rappiEndpoint = config('app.rappi_prod_api_mx');
                    break;
                case 'CO':
                    $rappiEndpoint = config('app.rappi_prod_api_co');
                    break;
                case 'CL':
                    $rappiEndpoint = config('app.rappi_prod_api_cl');
                    break;
                case 'BR':
                    $rappiEndpoint = config('app.rappi_prod_api_br');
                    break;
                case 'UY':
                    $rappiEndpoint = config('app.rappi_prod_api_uy');
                    break;
                case 'EC':
                    $rappiEndpoint = config('app.rappi_prod_api_ec');
                    break;
                case 'PE':
                    $rappiEndpoint = config('app.rappi_prod_api_pe');
                    break;
            }
        }
        return $rappiEndpoint;
    }

    public function updatePassword()
    {
        $integrationToken = StoreIntegrationToken::where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->get();
        foreach ($integrationToken as $integration) {
            try {
                //Seteo el URL del API correspondiente de Rappi
                $this->getPassword($integration->store);
            } catch (\Exception $e) {
                $this->logError(
                    "RappiIntegration update",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    json_encode($request)
                );
            }
        }
    }

    public function getPassword($store)
    {
        Log::info("Store changing Bearer token in Rappi: " . $store->name);
        $integrationToken = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->first();
        if ($integrationToken) {
            try {
                //Seteo el URL del API correspondiente de Rappi
                $rappiEndpoint = $this->setRappiAPIURL($store) . 'login';

                $client = new \GuzzleHttp\Client();
                $payload = [
                    'token' => $integrationToken->token
                ];
                $headers = [
                    'Content-Type' => 'application/json'
                ];

                $request = new Request('POST', $rappiEndpoint, $headers, json_encode($payload));
                $response = $client->send($request, ['timeout' => 60]);
                $passwordAuthorization = $response->getHeaders()['X-Auth-Int'][0];
                $integrationToken->password = $passwordAuthorization;
                $integrationToken->save();
            } catch (\Exception $e) {
                $this->printLogFile(
                    "RappiIntegration Web getPassword: NO SE PUDO GUARDAR EL PASSWORD PARA TOKEN",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    json_encode($request)
                );
            }
        }
    }

    public function setRappiOrderEmitted($store, $request, $integrationToken)
    {
        $newOrder = json_decode($request, false);
        $integratedOrder = $this->insertOrderFromRappiObject($newOrder, $store);
        $response = false;
        if (!$integratedOrder) {
            $this->rejectOrderRappi($newOrder->order->id, $integrationToken, $store, 0);
            // $channel = $this->getSlackChannel($store->id);
            // $slackMessage = "Error al guardar la orden de Rappi en myPOS\n" .
            //     "Tienda: " . $store->name . "\n" .
            //     "Error: No está abierta la tienda\n";
            // $this->sendSlackMessage(
            //     $channel,
            //     $slackMessage
            // );
            return response()->json(
                [
                    'status' => 'No se pudo crear la orden',
                    'results' => "El local no esta abierto"
                ],
                403
            );
        } else {
            $response = $this->acceptOrderRappi($newOrder->order->id, $integrationToken, $store, 0);
            if (!$response) {
                return response()->json(
                    [
                        'status' => 'No se pudo aceptar la orden',
                        'results' => "Ocurrio un error al aceptar la orden"
                    ],
                    404
                );
            }
            $this->reduceComponentsStock($integratedOrder);
            $this->reduceComponentsStockBySpecification($integratedOrder);
            event(new OrderCreatedComanda($integratedOrder));
            $storeConfig = StoreConfig::where('store_id', $store->id)->first();
            $employee = $this->getEmployeeRappiIntegration($store->id);
            if ($storeConfig) {
                if ($storeConfig->uses_print_service) {
                    // Imprimir por microservicio
                    // PrintServiceHelper::printComanda($integratedOrder, $employee);
                } else {
                    // Send firebase push notification
                    $this->sendIntegrationOrder($integratedOrder, 'Rappi');
                }
            }
        }
        return response()->json(
            [
                'status' => 'Se creo la orden emitida por Rappi correctamente',
                'results' => null
            ],
            200
        );
    }

    public function setRappiOrderFromMely($newOrder, $store)
    {
        $order = json_decode(json_encode($newOrder));

        $existsOrder = Order::where('status', 1)
            ->where('preorder', 0)
            ->whereHas('orderIntegrationDetail', function ($integrations) use ($order) {
                $integrations->where('external_order_id', $order->external_id)
                    ->where('external_store_id', $order->store->id);
            })->first();

        if ($existsOrder) {
            return [
                "success" => true,
                "message" => "La orden ya había sido creada anteriormente"
            ];
        }

        Log::info("rappi mely " . json_encode($order));
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();

        $integratedOrder = $this->insertOrderFromRappiObject($order, $store);
        if ($integratedOrder) {
            if($storeConfig->automatic){
                $this->reduceComponentsStock($integratedOrder);
                $this->reduceComponentsStockBySpecification($integratedOrder);
                event(new OrderCreatedComanda($integratedOrder));
            }
            
           
            if ($storeConfig) {
                if ($storeConfig->uses_print_service) {
                    if($storeConfig->automatic){
                        $employee = $this->getEmployeeRappiIntegration($store->id);
                        // PrintServiceHelper::printComanda($integratedOrder, $employee);
                    }
                    
                } else {
                    if($storeConfig->automatic){
                        $this->sendIntegrationOrder($integratedOrder, 'Rappi');
                    }
                }
                event(new IntegrationOrderCreated($integratedOrder));
            }
            return [
                "success" => true,
                "message" => "Orden procesada"
            ];
        } else {
            return [
                "success" => false,
                "message" => "La orden se procesó con un error"
            ];
        }
    }

    public function getOrders($store, $tries)
    {
        if ($tries < 1) {
            //Log::info("RAPPI ORDERS");
            $integrationToken = StoreIntegrationToken::where('store_id', $store->id)
                ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
                ->first();
            if ($integrationToken) {
                if ($integrationToken->password !== null) {
                    //Seteo el URL del API correspondiente de Rappi
                    $rappiEndpoint = $this->setRappiAPIURL($store) . 'orders';
                    $headers = [
                        'Content-Type' => 'application/json',
                        'x-auth-int' => $integrationToken->password
                    ];
                    $employee = $this->getEmployeeRappiIntegration($store->id);
                    try {
                        $client = new \GuzzleHttp\Client();
                        $request = new Request('GET', $rappiEndpoint, $headers);
                        $response = $client->send($request, ['timeout' => 15]);
                        //if($store->id==575){
                        //	Log::info("masa request rappi: ".$response->getStatusCode());
                        //}
                        if ($response->getStatusCode() === 200) {
                            $newOrders = json_decode($response->getBody());
                            // Log::info("Store getting Rappi Orders: ".$store->name);
                            foreach ($newOrders as $newOrder) {
                                Log::info("Rappi Orders obtained for store: " . $store->name);
                                Log::info(json_encode($newOrder));
                                $integratedOrder = $this->insertOrderFromRappiObject($newOrder, $store);
                                if ($integratedOrder) {
                                    $response = $this->acceptOrderRappi($newOrder->order->id, $integrationToken, $store, 0);
                                    $this->reduceComponentsStock($integratedOrder);
                                    $this->reduceComponentsStockBySpecification($integratedOrder);
                                    event(new OrderCreatedComanda($integratedOrder));
                                    $storeConfig = StoreConfig::where('store_id', $store->id)->first();
                                    if ($storeConfig) {
                                        if ($storeConfig->uses_print_service) {
                                            // Imprimir por microservicio
                                            // PrintServiceHelper::printComanda($integratedOrder, $employee);
                                        } else {
                                            // Send firebase push notification
                                            $this->sendIntegrationOrder($integratedOrder, 'Rappi');
                                        }
                                        event(new IntegrationOrderCreated($integratedOrder));
                                    }
                                } else {
                                    $this->rejectOrderRappi($newOrder->order->id, $integrationToken, $store, 0);
                                }
                            }
                        }
                    } catch (ClientException  $e) {
                        $this->logError(
                            "RappiIntegration Web getOrders: ERROR DE ENVIO DE REQUEST",
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode($store)
                        );
                        $this->getPassword($store);
                        $tries = $tries + 1;
                    } catch (ServerException $e) {
                        $this->logError(
                            "RappiIntegration Web getOrders: ERROR DE SERVIDOR POR REQUEST",
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode($store)
                        );
                        $this->getPassword($store);
                        $tries = $tries + 1;
                    } catch (BadResponseException $e) {
                        $this->logError(
                            "RappiIntegration Web getOrders: ERROR DE FORMATO EN REQUEST",
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode($store)
                        );
                        $this->getPassword($store);
                        $tries = $tries + 1;
                    } catch (RequestException $e) {
                        $this->logError(
                            "RappiIntegration Web getOrders: ERROR DE REQUEST",
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode($store)
                        );
                        $this->getPassword($store);
                        $tries = $tries + 1;
                    } catch (\Exception $e) {
                        Log::info($e->getTraceAsString());
                        $this->logError(
                            "RappiIntegration ERROR GENERICO EN GETORDERS",
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode($store)
                        );
                    }
                } else {
                    $this->getPassword($store);
                    $tries = $tries + 1;
                }
            }
        }
    }

    public function setIntegrationOrder($order, $integrationName, $externalStoreId, $externalCustomerId, $customerName, $billingId)
    {
        $orderIntegration = new OrderIntegrationDetail();
        $orderIntegration->integration_name = $integrationName;
        $orderIntegration->external_order_id = $order->id;
        $orderIntegration->external_store_id = $externalStoreId;
        $orderIntegration->external_customer_id = $externalCustomerId;
        $orderIntegration->external_created_at = $order->createdAt;
        $orderIntegration->billing_id = $billingId;
        $orderIntegration->number_items = 0;
        $orderIntegration->value = $order->totalValue * 100;
        $orderIntegration->customer_name = $customerName;
        $orderIntegration->order_number = $order->id;
        $orderIntegration->save();

        //// TODO: MANDAR A SERVIDOR SLAVE

        return $orderIntegration;
    }

    public function setBilling($customerName, $customerData)
    {
        $billing = new Billing();
        $billing->name = $customerName;
        $billing->email = $customerData->email;
        $billing->phone = $customerData->phone;
        $billing->address = $customerData->address;
        $billing->status = 1;
        $billing->document = "rappiCustomer";
        $billing->save();

        //// TODO: MANDAR A SERVIDOR SLAVE

        return $billing;
    }

    public function setOrderFromIntegration($employeeId, $identifier, $spotId, $storeId, $billingId, $integrationOrder, $discountedTotal = null,$automatic=true)
    {
        $cashierBalance = CashierBalance::where('store_id', $storeId)
            ->whereNull('date_close')
            ->first();

        $order = null;

        $discountPercentage = 0;
        $discountValue = 0;
        $orderTotal = $integrationOrder->value;
        if ($discountedTotal && $discountedTotal !== $integrationOrder->value) {
            $discountPercentage = 100 - ($discountedTotal * 100 / $integrationOrder->value);
            $discountValue = $integrationOrder->value - $discountedTotal;
            $orderTotal = $discountedTotal;
        }
        if ($cashierBalance) {
            $now = Carbon::now()->toDateTimeString();
            $order = new Order();
            $order->employee_id = $employeeId;
            $order->status = $automatic?1:3;
            $order->food_service = 0;
            $order->identifier = $identifier;
            $order->order_value = $integrationOrder->value;
            $order->cash = 0;
            $order->spot_id = $spotId;
            $order->store_id = $storeId;
            $order->base_value = $orderTotal;
            $order->total = $orderTotal;
            $order->current_status = "Creada";
            $order->billing_id = $billingId;
            $order->cashier_balance_id = $cashierBalance->id;
            $order->preorder = 0;
            $order->discount_percentage = $discountPercentage;
            $order->discount_value = $discountValue;
            $order->undiscounted_base_value = $integrationOrder->value;
            $order->no_tax_subtotal = 0.00;
            $order->change_value = 0;
            $order->save();

            $payment = new Payment();
            $payment->total = $orderTotal;
            $payment->order_id = $order->id;
            $payment->created_at = $now;
            $payment->updated_at = $now;
            $payment->type = PaymentType::CREDIT;
            $payment->save();

            //// TODO: MANDAR A SERVIDOR SLAVE
        }
        return $order;
    }

    public function acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries)
    {
        if ($tries < 2) {
            $gotAccepted = false;
            if ($integrationToken->password !== null) {
                //Seteo el URL del API correspondiente de Rappi
                $rappiEndpoint = $this->setRappiAPIURL($store) . 'orders/take/' . $rappiOrderID;

                $headers = [
                    'Content-Type' => 'application/json',
                    'x-auth-int' => $integrationToken->password
                ];

                $response = null;

                try {
                    $client = new \GuzzleHttp\Client();
                    $request = new Request('GET', $rappiEndpoint, $headers);
                    $response = $client->send($request, ['timeout' => 10]);
                    if ($response->getStatusCode() === 200) {
                        $gotAccepted = true;
                        Log::info("Orden Rappi Aceptada: " . $rappiOrderID);
                    }
                } catch (ClientException  $e) {
                    $this->logError(
                        "RappiIntegration Web acceptOrderRappi: ERROR DE ENVIO DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($rappiOrderID)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (ServerException $e) {
                    $this->logError(
                        "RappiIntegration Web acceptOrderRappi: ERROR DE SERVIDOR POR REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($rappiOrderID)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (BadResponseException $e) {
                    $this->logError(
                        "RappiIntegration Web acceptOrderRappi: ERROR DE FORMATO DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($rappiOrderID)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (RequestException $e) {
                    $this->logError(
                        "RappiIntegration Web acceptOrderRappi: ERROR DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($rappiOrderID)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration ERROR GENERICO EN ACEPTAR ORDEN",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($rappiOrderID)
                    );

                    if ($response !== 400) {
                        Log::info("Reintento: con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                }

                /// si es erronea verificar por los codigos 400 y obtener el body
                /// errorCode 1000 no existe la orden
                /// errorCode 2000 ya fue procesada
            } else {
                $this->getPassword($store);
                $tries = $tries + 1;
                $gotAccepted = $this->acceptOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
            }
        } else {
            $gotAccepted = false;
        }
        return $gotAccepted;
    }

    public function rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries)
    {
        if ($tries < 3) {
            $gotAccepted = false;
            if ($integrationToken->password !== null) {
                //Seteo el URL del API correspondiente de Rappi
                $rappiEndpoint = $this->setRappiAPIURL($store) . 'orders/reject';
                $request = $rappiEndpoint;
                $headers = [
                    'Content-Type' => 'application/json',
                    'x-auth-int' => $integrationToken->password
                ];
                $payload = [
                    'order_id' => $rappiOrderID,
                    'reason' => "La tienda no está recibiendo pedidos aun"
                ];
                $response = null;

                try {
                    $client = new \GuzzleHttp\Client();
                    $request = new Request('POST', $rappiEndpoint, $headers, json_encode($payload));
                    $response = $client->send($request, ['timeout' => 5]);

                    if ($response->getStatusCode() === 200) {
                        $gotAccepted = true;
                        Log::info("Orden Rappi RECHAZADA: " . $rappiOrderID);
                    }
                } catch (ClientException  $e) {
                    $this->logError(
                        "RappiIntegration Web rejectOrderRappi: ERROR DE ENVIO DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($request)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (ServerException $e) {
                    $this->logError(
                        "RappiIntegration Web rejectOrderRappi: ERROR DE SERVIDOR POR REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($request)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (BadResponseException $e) {
                    $this->logError(
                        "RappiIntegration Web rejectOrderRappi: ERROR DE FORMATO DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($request)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (RequestException $e) {
                    $this->logError(
                        "RappiIntegration Web rejectOrderRappi: ERROR DE REQUEST",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($request)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: " . $tries . " con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration ERROR GENERICO EN RECHAZAR ORDEN",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($request)
                    );
                    if ($response !== 400) {
                        Log::info("Reintento: con nuevo password");
                        $this->getPassword($store);
                        $tries = $tries + 1;
                        $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
                    } else {
                        $gotAccepted = false;
                    }
                }

                /// si es erronea verificar por los codigos 400 y obtener el body
                /// errorCode 1000 no existe la orden
                /// errorCode 2000 ya fue procesada
            } else {
                $this->getPassword($store);
                $tries = $tries + 1;
                $gotAccepted = $this->rejectOrderRappi($rappiOrderID, $integrationToken, $store, $tries);
            }
        }
        return $gotAccepted;
    }

    public function insertOrderFromRappiObject($newOrder, $store)
    {
        $orderObj = $newOrder->order;
        $clientObj = $newOrder->client;
        $storeObj = $newOrder->store;
        $products = [];
        $searchBy = 'sku';

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();

        if (isset($orderObj->items)) {
            $products = $orderObj->items;
        } else if (isset($orderObj->products)) {
            $products = $orderObj->products;
            $searchBy = 'id';
        }

        $clientName = "CONSUMIDOR FINAL";

        if ($clientObj) {
            $clientName = $clientObj->firstName . " " . $clientObj->lastName;
            //// TODO: REVISAR QUE NO SE VAYAN A USAR LOS DATOS DEL CLIENTE
            // $billing = Billing::where('email', $clientObj->email)->where('phone', $clientObj->phone)->first();
            // if (!$billing || $billing == null) {
            //     $billing = $this->setBilling($clientName, $clientObj);
            // }
        }
        $billing=null;
        if($storeConfig->automatic){
            $billing = Billing::firstOrCreate([
                'document' => '9999999999999',
                'name'     => 'CONSUMIDOR FINAL'
            ]);
        }
        $billing_id = $billing==null?$billing:$billing->id;

        $integrationOrder = $this->setIntegrationOrder($orderObj, AvailableMyposIntegration::NAME_RAPPI, $storeObj->id, $clientObj->id, $clientName,$billing_id);

        $order = null;
        if ($integrationOrder) {
            //// Buscar employee
            $employeeIntegrationName = "RappiDeliverer";
            $employee = $this->getEmployeeRappiIntegration($store->id);
            $employeeId = $employee->id;

            $identifier = Helper::getNextOrderIdentifier($store->id);

            //// Buscar spot que tenga origen: RAPPI
            $spot = Spot::where('store_id', $store->id)
                ->where('origin', Spot::ORIGIN_RAPPI)
                ->first();
            if (!$spot) {
                $spot = new Spot();
                $spot->name = "Rappi";
                $spot->store_id = $store->id;
                $spot->origin = Spot::ORIGIN_RAPPI;
                $spot->save();
                //// TODO: MANDAR A SERVIDOR PRODUCCION/SLAVE
            }
            $spotId = $spot->id;

            //Descuento
            $discountedTotal = null;
            if (isset($orderObj->totalValueWithDiscount) && $orderObj->totalValueWithDiscount > 0) {
                $discountedTotal = $orderObj->totalValueWithDiscount * 100;
            }
            if (isset($newOrder->growth_global_discounts)) {
                $growthDiscounts = $newOrder->growth_global_discounts;
                $totalDiscount = 0;
                foreach ($growthDiscounts as $discount) {
                    if (isset($discount->productValueDiscount)) {
                        $totalDiscount += $discount->productValueDiscount;
                    }
                }
                $discountedTotal = ($orderObj->totalValue - $totalDiscount) * 100;
            } else if (isset($newOrder->growthGlobalDiscounts)) {
                $growthDiscounts = $newOrder->growthGlobalDiscounts;
                $totalDiscount = 0;
                foreach ($growthDiscounts as $discount) {
                    if (isset($discount->productValueDiscount)) {
                        $totalDiscount += $discount->productValueDiscount;
                    }
                }
                $discountedTotal = ($orderObj->totalValue - $totalDiscount) * 100;
            }
            $order = $this->setOrderFromIntegration($employeeId, $identifier, $spotId, $store->id,$billing_id, $integrationOrder, $discountedTotal,$storeConfig->automatic);
        }
        if (!$order) {
            event(new SimpleOrderFailed($store->id));
            return null;
        }

        $integrationOrder->order_id = $order->id;
        $counterItems = 0;
        foreach ($products as $newProduct) {
            $counterItems = $counterItems + 1;
            if (!isset($newProduct->price)) {
                $newProduct->price = $newProduct->unitPrice;
            }
            if (!isset($newProduct->sku) && isset($newProduct->id)) {
                $newProduct->external_id = $newProduct->id;
            }
            if (!isset($newProduct->subtype)) {
                $newProduct->subtype = 'Rappi Menu';
            }
            if (!isset($newProduct->type)) {
                $newProduct->type = 'product';
            }

            $productMyposResponse = $this->insertProductFromRappiObject($newProduct, $store, $searchBy);
            $productMypos = $productMyposResponse['product'];
            $productDetailMypos = $productMyposResponse['detail'];
            $specificationIdsQuantity = $productMyposResponse['specifications'];
            if ($productMypos && $productDetailMypos) {
                if ($newProduct->comments) {
                    $instructions = $newProduct->comments;
                } else {
                    $instructions = "";
                }
                $orderDetail = new OrderDetail();
                $orderDetail->order_id = $order->id;
                $orderDetail->product_detail_id = $productDetailMypos->id;
                $orderDetail->quantity = $newProduct->units;
                $orderDetail->status = 1;
                $orderDetail->value = intval($newProduct->price * 100);
                $orderDetail->name_product = $newProduct->name;
                $orderDetail->instruction = $instructions;
                $orderDetail->total = intval($newProduct->price * $newProduct->units * 100);
                $orderDetail->base_value = intval($newProduct->price * 100);
                $orderDetail->compound_key = $productDetailMypos->id;
                $orderDetail->invoice_name = mb_substr($newProduct->name, 0, 25, "utf-8");
                $orderDetail->save();
                if ($orderDetail) {
                    $orderProcessStatus = new OrderDetailProcessStatus();
                    $orderProcessStatus->process_status = 1;
                    $orderProcessStatus->order_detail_id = $orderDetail->id;
                    $orderProcessStatus->save();

                    //confirma si los toppings se imprimirán con una acomodación especial o por defecto
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
                        $totalOrderDetailValue += (intval($newProduct->price * 100));
                        $orderDetail->value = $totalOrderDetailValue;
                    }
                    $orderDetail->compound_key = $compoundKey;
                    $orderDetail->save();
                }
            } else {
                $this->logError(
                    "RappiIntegration Web insertOrderFromRappiObject",
                    ' NO SE PUDO GUARDAR EL NUEVO PRODUCTO',
                    'RappiIntegration.php',
                    829,
                    json_encode($products)
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
            if(!$storeConfig->automatic){
                $detail->append('spec_fields');
            }
        }


        $order = $this->calculateOrderValuesIntegration($order, 'rappi');
        //$invoice = $this->setInvoice($order, $billing, $store);
        if($storeConfig->automatic){
            try {
                $invoice = DB::transaction(
                    function () use ($order, $billing, $store) {
                        $invoice = $this->setInvoice($order, $billing, $store);
                        return $invoice;
                    },
                    10
                );
                $order->payment->total = $invoice->total;
                $order->payment->save();
            } catch (\Exception $e) {
                //
            }
        }
        

        $integrationOrder->number_items = $counterItems;
        $integrationOrder->save();
        if($storeConfig->automatic){
            PrintServiceHelper::printComanda($order, $employee);
            PrintServiceHelper::printInvoice($invoice, $employee);
        }
        
        // Enviando orden a Aloha
        $result = $this->uploadOrder($store->id, $order, 1, "Rappi");
        $this->printLogFile(
            "ResultAlohaIntegration:  " . json_encode($result),
            'aloha_logs'
        );

        if($storeConfig->automatic){
            //Envía a las integraciones de backoffice
            $this->prepareToSendForElectronicBilling(
                $store,
                $invoice,
                AvailableMyposIntegration::NAME_RAPPI,
                null,
                null,
                [
                    'cashier' => null,
                    'invoice' => $invoice
                ]
            );
        }

        $job = array();
        $order->load('spot','orderDetails.orderSpecifications.specification.specificationCategory','employee','orderIntegrationDetail','invoice','orderConditions','orderStatus');
        $job["store_id"] = $store->id;
        $job["order"] = $order;

        QueueHelper::dispatchJobs(array($job));

        event(new OrderCreated($order->id));
        event(new SimpleOrderCreated($store->id));
        if($storeConfig->automatic){
            if ($store->hubs != null && $store->hubs->first() != null) {
                event(new HubIntegrationOrderCreated($store->hubs->first(), $invoice));
            }
        }
        

        return $order;
    }

    /**
     * Función encargada de asignar los impuestos globales a un producto dado
     */
    public function assignGlobalTaxes(Product $product, Store $store)
    {
        //Traemos todos los impuestos globales existentes para la tienda
        $storeTaxes = StoreTax::where('store_id', $store->id)
            ->where('is_main', 1)->get();

        //asignamos todos los impuestos globales al producto
        foreach ($storeTaxes as $storeTax) {
            $product->taxes()->syncWithoutDetaching([$storeTax->id]);
        }
    }

    public function insertProductFromRappiObject($newProduct, $store, String $searchBy = 'sku')
    {
        // Consultamos si existe el menu, si no, lo creamos
        $section_id = Section::where([
            'name'      => 'Rappi',
            'store_id'  => $store->id
        ])->withTrashed()->first();


        //Si el menú existe, pero tiene softdelete, entonces lo creamos
        if (isset($section_id->deleted_at) && $section_id->deleted_at != null) {
            $section_id->restore();

            //Si el menú no existe, lo creamos
        } elseif (!$section_id) {

            $section_id = Section::create([
                'name'      => 'Rappi',
                'store_id'  => $store->id,
                'subtitle'  => ' ',
                'is_main'   => 0
            ]);
        }

        //Linkeamos el menú a la integración
        $sectionIntegration = SectionIntegration::firstOrCreate([
            'section_id' => $section_id->id,
            'integration_id'  => 2,
        ]);

        /// Busco los productos con los mismos skus que rappi (en integraciones pasadas de rappi)
        $productsRequested = ProductIntegrationDetail::where($searchBy, $newProduct->$searchBy)
            ->orWhere('name', $newProduct->name)
            ->get();

        $productFound = null;
        $productCreated = null;
        $specificationIdsQuantity = collect([]);

        /// Si encuentro algunos (diferentes stores), busco el de este store
        foreach ($productsRequested as $iteratorProduct) {
            /// Si existe un producto de este store, lo retorno
            $productRequested = ProductDetail::where('product_id', $iteratorProduct->product->id)
                ->where('store_id', $store->id)
                ->where('status', 1)
                ->whereHas('product', function ($product) use ($section_id) {
                    $product->whereHas('category', function ($pCategory) use ($section_id) {
                        $pCategory->where('section_id', $section_id->id);
                    });
                })
                ->first();

            if ($productRequested) {
                $productFound = $productRequested->product;
                $productDetailFound = $productRequested;
                break;
            }
        }

        if ($productFound) {
            $myposProduct = $productFound;
            $myposProductDetail = $productDetailFound;

            //Asignamos los impuestos globales de la tienda al producto
            $this->assignGlobalTaxes($myposProduct, $store);

            $specificationIdsQuantity = $this->setRappiComponentsFromProduct($newProduct, $productFound, $store, $searchBy, $section_id->id);
            /// Si no existe un producto con el sku de rappi
        } else {
            /// Busco un producto con nombre igual
            $productsRequested = Product::where('name', $newProduct->name)
                ->where('status', 1)->get();

            /// Si encuentro algunos (diferentes stores), busco el de este store
            foreach ($productsRequested as $iteratorProduct) {

                /// Si existe un producto de este store, lo retorno
                $productRequested = ProductDetail::where('product_id', $iteratorProduct->id)
                    ->where('store_id', $store->id)
                    ->where('status', 1)
                    ->whereHas('product', function ($product) use ($section_id) {
                        $product->whereHas('category', function ($pCategory) use ($section_id) {
                            $pCategory->where('section_id', $section_id->id);
                        });
                    })
                    ->first();

                if ($productRequested) {
                    $productFound = $productRequested->product;
                    $productDetailFound = $productRequested;
                    break;
                }
            }

            if ($productFound) {
                $myposProduct = $productFound;
                $myposProductDetail = $productDetailFound;
                $specificationIdsQuantity = $this->setRappiComponentsFromProduct($newProduct, $productFound, $store, $searchBy, $section_id->id);
            } else {
                $myposProduct = null;
                $myposProductDetail = null;
                $specificationIdsQuantity = null;

                /// Buscar la categoria o crearla
                if (isset($newProduct->subtype)) {
                    $category = $newProduct->subtype;
                } else {
                    $category = "Productos";
                    $newProduct->subtype = $category;
                }


                $categoryMypos = ProductCategory::where('name', 'like', $category)
                    ->where('company_id', $store->company->id)
                    ->where('section_id', $section_id->id)
                    ->withTrashed()
                    ->first();

                if ($categoryMypos) {
                    $productCategoryMypos = $categoryMypos;
                } else {
                    /// Crear nueva categoria
                    $productCategoryMypos = $this->setProductCategory($newProduct->subtype, $store->company->id, $searchBy, $section_id->id);
                }

                $myposProductResponse = $this->setProduct($newProduct->name, $newProduct->$searchBy, intval($newProduct->price * 100), $productCategoryMypos->id, $store);

                $myposProduct = $myposProductResponse['product'];
                $myposProductDetail = $myposProductResponse['detail'];

                if ($myposProduct && $myposProductDetail) {
                    $specificationIdsQuantity = $this->setRappiComponentsFromProduct($newProduct, $myposProduct, $store, $searchBy, $section_id->id);
                }
            }
        }

        $responseProduct = array(
            'product' => $myposProduct,
            'detail' => $myposProductDetail,
            'specifications' => $specificationIdsQuantity
        );
        return $responseProduct;
    }

    public function getMenu($store)
    {
        //$store = $this->authStore;

        $integrationToken = $store->integrationTokens
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->first();

        if ($integrationToken->password == null) {
            $this->getPassword($store);
        }

        try {
            $tokenPassword = $integrationToken->password;
            $urlRappi = $this->setRappiAPIURL($store) . 'stores/menu';
            Log::info("urlRappi for getMenu: " . $urlRappi);
            if (config('app.env') !== 'production') {
                // Se coloca un token quemado porque a rappi no le funciona este servicio en ambiente de desarrollo de modo. Para pruebas
                // se utiliza este menú que es de producción de una tienda. Puede ser remplazado por temas de testing con cualquiera.
                // $tokenPassword = "Bearer eyJhbGciOiJIUzUxMiJ9.eyJzdG9yZXMiOlsiOTAwMDE3MTEzIl0sImV4cCI6MTU4NjU1NzYwN30.hosSDWk4tJT7WVP0y64J3YF7yMMlNbSgch14XYpJoVwXXyAeeg7HLQtradAzTa7fv1wY_SP003FAJQC3kGHiSA";
                // $urlRappi = 'http://services.rappi.com/api/restaurants-integrations-public-api/stores/menu';
            }
            $client = new Client();
            $response = $client->request('GET', $urlRappi, [
                'headers' => [
                    'Content-Type'  =>  'application/json',
                    'x-auth-int'    =>  $tokenPassword
                ]
            ]);

            $responseRequest = json_decode($response->getBody(), true);
            return $responseRequest;
        } catch (RequestException $e) {

            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::error("--------------------------------------------------------------");
            Log::error("Error in getMenu: {$exceptionMessage}");
            Log::error("in store {$store->id}");
            Log::error("--------------------------------------------------------------");
            return null;
        }
    }

    public function syncMenu($store)
    {

        $menuItems = $this->getMenu($store);

        if ($menuItems !== null) {
            //es necesario eliminar productos anteriores en caso de existir
            $section_id = Section::where([
                'name'      => 'Rappi',
                'store_id'  => $store->id,
                'subtitle'  => ' ',
                'is_main'   => 0
            ])->withTrashed()->first();
            if ($section_id != null) {
                try {
                    $componentJSON = DB::transaction(
                        function () use ($section_id) {
                            $productsRappiMenu = Product::where('status', 1)
                                ->whereHas('category', function ($pCategory) use ($section_id) {
                                    $pCategory->where('section_id', $section_id->id);
                                })->get();
                            foreach ($productsRappiMenu as $product) {
                                //$product->status = 0;
                                //$product->save();
                                $productsIntegration = ProductIntegrationDetail::where('product_id', $product->id)->get();
                                foreach ($productsIntegration as $productIntegration) {
                                    //  $productIntegration->delete();

                                }
                            }
                        }
                    );
                } catch (\Exception $e) {
                    $this->logError(
                        "Rappi Menu ERROR ELIMINAR PRODUCTOS MENU VIEJO, storeId: " . $store->id,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode([])
                    );
                    return response()->json(
                        [
                            'status' => 'El menú de Rappi existente generó conflictos al importar',
                            'results' => null
                        ],
                        409
                    );
                }
            }

            $productsToCreate = [];

            foreach ($menuItems as $item) {

                $reFormatItemToppings = [];
                $toppingsToAdd = [];

                foreach ($item['toppings'] as $topping) {

                    $reFormatItemToppings = [
                        "sku" => null,
                        "id" => $topping['id'],
                        "external_topping_category_id" => $topping['id'],
                        "name" => $topping['name'],
                        "price" => isset($topping['price']) ? $topping['price'] : 0,
                        "type" => "topping",
                        "subtype" => $topping['category']['name'],
                        "units" => null
                    ];

                    array_push($toppingsToAdd, $reFormatItemToppings);
                }

                $reFormatItemProduct = [
                    "sku" => null,
                    "id" => $item['id'],
                    "external_id" => $item['id'],
                    // "id"=> 1406,
                    "name" => $item['name'],
                    "price" => isset($item['price']) ? $item['price'] : 0,
                    "type" => "product",
                    "subtype" => "Rappi Menu",
                    "toppings" => $toppingsToAdd
                ];

                $productProcesed = $this->insertProductFromRappiObject((object) $reFormatItemProduct, $store, "id");

                array_push($productsToCreate, $reFormatItemProduct);
            }

            return $productsToCreate;
        } else {
            return [];
        }
    }

    public function setRappiComponentsFromProduct($newProduct, $myposProduct, $store, String $searchProductBy = 'sku', $section_id = null)
    {

        /*Si estamos buscando un producto por id, entonces envía como argumento a setIntegrationProduct() un string 'external_id' para que
        use ese campo en la función, en vez de sku o id*/
        $searchByForSetIntegrationProduct = $searchProductBy == "id" ? "external_id" : "sku";
        $integrationProduct = $this->setIntegrationProduct($myposProduct, $newProduct, $searchByForSetIntegrationProduct);

        $specificationIdsQuantity = collect([]);

        switch ($newProduct->type) {
            case 'combo':
                try {
                    $componentProducts = $newProduct->products;
                    $newComponents = [];
                    foreach ($componentProducts as $productCombo) {
                        $toppings = $productCombo->toppings;
                        foreach ($toppings as $topping) {
                            if ($topping->subtype) {
                                $toppingCategory = $topping->subtype;
                            } else {
                                $toppingCategory = "Topping";
                            }
                            $toppingIntegration = ToppingIntegrationDetail::where('sku', $topping->sku)->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)->first();
                            $myposSpecification = null;
                            if ($toppingIntegration && isset($toppingIntegration)) {
                                $myposSpecification = Specification::find($toppingIntegration->specification_id);
                            }
                            if (!$myposSpecification || !isset($myposSpecification)) {

                                $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $toppingCategory)
                                    ->where('company_id', $store->company->id)
                                    ->where('section_id', $section_id)
                                    ->first();

                                if (!$myposSpecificationCategory) {
                                    $myposSpecificationCategory = $this->setSpecificationCategory($toppingCategory, $store->company->id, $section_id);
                                }
                                $myposSpecification = Specification::where('specification_category_id', $myposSpecificationCategory->id)->where('name', 'like', $topping->name)->first();
                                if (!$myposSpecification) {
                                    $myposSpecification = $this->setSpecification($topping->name, $topping->price, $myposSpecificationCategory->id);
                                    if ($myposSpecification) {
                                        $productSpecification = new ProductSpecification();
                                        $productSpecification->product_id = $myposProduct->id;
                                        $productSpecification->specification_id = $myposSpecification->id;
                                        $productSpecification->status = 1;
                                        $productSpecification->value = intval($topping->price * 100);
                                        $productSpecification->save();
                                    }
                                }
                                $toppingIntegration = $this->insertToppingFromRappiObject($topping, $myposSpecification);
                            } else {
                                $pes = ProductSpecification::where('product_id', $myposProduct->id)
                                    ->where('specification_id', $myposSpecification->id)->where("status", 1)->first();
                                if ($pes == null) {
                                    $productSpecification = new ProductSpecification();
                                    $productSpecification->product_id = $myposProduct->id;
                                    $productSpecification->specification_id = $myposSpecification->id;
                                    $productSpecification->status = 1;
                                    $productSpecification->value = intval($topping->price * 100);
                                    $productSpecification->save();
                                    $toppingIntegration = $this->insertToppingFromRappiObject($topping, $myposSpecification);
                                }
                            }
                            $specIdQuantity = [
                                'id' => $myposSpecification->id,
                                'quantity' => $topping->units,
                                'name_specification' => $topping->name,
                                'priority' => $myposSpecification->specificationCategory->priority,
                                'value' => intval($topping->price * 100)
                            ];
                            $specificationIdsQuantity->push($specIdQuantity);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration Web setRappiComponentsFromProduct: ERROR AL GUARDAR PRODUCTO POR COMBO",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($newProduct)
                    );
                }

                try {
                    if (property_exists($newProduct, 'extras')) {
                        $extrasRappi = $newProduct->extras;
                        foreach ($extrasRappi as $extraFeature) {
                            if ($extraFeature->subtype) {
                                $extraFeatureCategory = $extraFeature->subtype;
                            } else {
                                $extraFeatureCategory = "Extra";
                            }
                            $extraFeatureIntegration = ToppingIntegrationDetail::where('sku', $extraFeature->sku)->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)->first();
                            $myposSpecification = null;
                            if ($extraFeatureIntegration && isset($extraFeatureIntegration)) {
                                $myposSpecification = Specification::find($extraFeatureIntegration->specification_id);
                            }
                            if (!$myposSpecification || !isset($myposSpecification)) {
                                $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $extraFeatureCategory)->where('company_id', $store->company->id)->first();
                                if (!$myposSpecificationCategory) {
                                    $myposSpecificationCategory = $this->setSpecificationCategory($extraFeaturegCategory, $store->company->id, $section_id);
                                }
                                $myposSpecification = Specification::where('specification_category_id', $myposSpecificationCategory->id)->where('name', 'like', $extraFeature->name)->first();
                                if (!$myposSpecification) {
                                    $myposSpecification = $this->setSpecification($extraFeature->name, $extraFeature->price, $myposSpecificationCategory->id);
                                }

                                $extraFeatureIntegration = $this->insertToppingFromRappiObject($extraFeature, $myposSpecification);
                            }
                            $specIdQuantity = [
                                'id' => $myposSpecification->id,
                                'quantity' => $extraFeature->units,
                                'name_specification' => $extraFeature->name,
                                'value' => intval($extraFeature->price * 100)
                            ];
                            $specificationIdsQuantity->push($specIdQuantity);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration Web setRappiComponentsFromProduct: ERROR AL GUARDAR EXTRA DE PRODUCTO POR COMBO",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($newProduct)
                    );
                }
                break;
            case 'product':
                try {
                    $toppings = $newProduct->toppings;

                    foreach ($toppings as $topping) {

                        /*Cuando se trabaja descargando el menú de rappi, aquí los toppings
                        llegan como array, por eso se convierten en object si aún no lo son*/
                        if (!is_object($topping)) {
                            $topping = (object) $topping;
                        }



                        if (isset($topping->subtype)) {
                            $toppingCategory = $topping->subtype;
                        } elseif (isset($topping->category)) {
                            $toppingCategory = $topping->category->name;
                        } else {
                            $toppingCategory = "Topping";
                        }

                        /* en $searchByFortoppingIntegration seteamos el nombre de la columna en bd por la que queremos buscar,
                        también el nombre del item del obejeto $topping->xxxx */
                        $searchByForToppingIntegration = $searchProductBy == "id" ? "external_topping_category_id" : "sku";
                        if (!isset($topping->$searchByForToppingIntegration) && $searchProductBy == "id") {
                            $topping->$searchByForToppingIntegration = $topping->id;
                        }

                        $toppingIntegration = ToppingIntegrationDetail::where($searchByForToppingIntegration, $topping->$searchByForToppingIntegration)
                            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
                            ->whereHas(
                                'specification',
                                function ($specification) use ($section_id) {
                                    $specification->where('status', 1)->whereHas(
                                        'specificationCategory',
                                        function ($specCat) use ($section_id) {
                                            $specCat->where('section_id', $section_id);
                                        }
                                    );
                                }
                            )->get();

                        $myposSpecification = null;

                        if (count($toppingIntegration) > 0) {
                            $myposSpecification = Specification::find($toppingIntegration[0]->specification_id);
                        }
                        //Log::info($myposSpecification->specificationCategory);

                        if (!$myposSpecification) {
                            $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $toppingCategory)
                                ->where('company_id', $store->company->id)
                                ->where('section_id', $section_id)
                                ->first();

                            if (!$myposSpecificationCategory) {
                                $myposSpecificationCategory = $this->setSpecificationCategory($toppingCategory, $store->company->id, $section_id);
                            }

                            $myposSpecification = Specification::where('specification_category_id', $myposSpecificationCategory->id)
                                ->where('name', 'like', $topping->name)
                                ->first();

                            if (!$myposSpecification) {
                                $myposSpecification = $this->setSpecification($topping->name, $topping->price, $myposSpecificationCategory->id);

                                if ($myposSpecification) {
                                    $productSpecification = new ProductSpecification();
                                    $productSpecification->product_id = $myposProduct->id;
                                    $productSpecification->specification_id = $myposSpecification->id;
                                    $productSpecification->status = 1;
                                    $productSpecification->value = intval($topping->price * 100);
                                    $productSpecification->save();
                                }
                            }
                            $toppingIntegration = $this->insertToppingFromRappiObject($topping, $myposSpecification, $searchByForToppingIntegration);
                        } else {
                            if ($myposSpecification->specificationCategory->name != $toppingCategory && $toppingCategory != 'Topping') {
                                $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $toppingCategory)
                                    ->where('company_id', $store->company->id)
                                    ->where('section_id', $section_id)
                                    ->first();

                                if (!$myposSpecificationCategory) {
                                    $myposSpecificationCategory = $this->setSpecificationCategory($toppingCategory, $store->company->id, $section_id);
                                }
                                $myposSpecification->specification_category_id = $myposSpecificationCategory->id;
                                $myposSpecification->save();
                            }
                            $pes = ProductSpecification::where('product_id', $myposProduct->id)
                                ->where('specification_id', $myposSpecification->id)->where("status", 1)->first();
                            if ($pes == null) {
                                $productSpecification = new ProductSpecification();
                                $productSpecification->product_id = $myposProduct->id;
                                $productSpecification->specification_id = $myposSpecification->id;
                                $productSpecification->status = 1;
                                $productSpecification->value = intval($topping->price * 100);
                                $productSpecification->save();
                                $toppingIntegration = $this->insertToppingFromRappiObject($topping, $myposSpecification, $searchByForToppingIntegration);
                            }
                        }

                        $specIdQuantity = [
                            'id' => $myposSpecification->id,
                            'quantity' => $topping->units,
                            'name_specification' => $topping->name,
                            'priority' => $myposSpecification->specificationCategory->priority,
                            'value' => intval($topping->price * 100)
                        ];
                        $specificationIdsQuantity->push($specIdQuantity);
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration Web setRappiComponentsFromProduct: ERROR AL GUARDAR PRODUCTO SIN COMBO",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($newProduct)
                    );
                }

                try {
                    if (property_exists($newProduct, 'extras')) {
                        $extrasRappi = $newProduct->extras;
                        foreach ($extrasRappi as $extraFeature) {
                            if ($extraFeature->subtype) {
                                $extraFeatureCategory = $extraFeature->subtype;
                            } else {
                                $extraFeatureCategory = "Extra";
                            }
                            $extraFeatureIntegration = ToppingIntegrationDetail::where('sku', $extraFeature->sku)->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)->first();
                            $myposSpecification = null;
                            if ($extraFeatureIntegration && isset($extraFeatureIntegration)) {
                                $myposSpecification = Specification::find($extraFeatureIntegration->specification_id);
                            }
                            if (!$myposSpecification || !isset($myposSpecification)) {

                                $myposSpecificationCategory = SpecificationCategory::where('name', 'like', $extraFeatureCategory)
                                    ->where('company_id', $store->company->id)
                                    ->where('section_id', $section_id)
                                    ->first();

                                if (!$myposSpecificationCategory) {
                                    $myposSpecificationCategory = $this->setSpecificationCategory($extraFeaturegCategory, $store->company->id, $section_id);
                                }

                                $myposSpecification = Specification::where('specification_category_id', $myposSpecificationCategory->id)->where('name', 'like', $extraFeature->name)->first();
                                if (!$myposSpecification) {
                                    $myposSpecification = $this->setSpecification($extraFeature->name, $extraFeature->price, $myposSpecificationCategory->id);
                                }
                                $extraFeatureIntegration = $this->insertToppingFromRappiObject($extraFeature, $myposSpecification);
                            }
                            $specIdQuantity = [
                                'id' => $myposSpecification->id,
                                'quantity' => $extraFeature->units,
                                'name_specification' => $extraFeature->name,
                                'value' => intval($extraFeature->price * 100)
                            ];
                            $specificationIdsQuantity->push($specIdQuantity);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logError(
                        "RappiIntegration Web setRappiComponentsFromProduct: ERROR AL GUARDAR EXTRA DE PRODUCTO SIN COMBO",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        json_encode($newProduct)
                    );
                }
                break;
        }
        return $specificationIdsQuantity;
    }

    public function insertComponentFromRappiObject($productMypos, $productRappi, $componentRappi, $store)
    {
        if ($componentRappi->subtype) {
            $category = $componentRappi->subtype;
        } else {
            $category = "General";
        }
        $categoryMypos = ComponentCategory::where('name', 'like', $category)->where('company_id', $store->company->id)->first();
        if ($categoryMypos) {
            $componentCategoryMypos = $categoryMypos;
        } else {
            $componentCategoryMypos = $this->setComponentCategory($category, $store->company->id);
        }
        $componentMypos = Component::where('name', 'like', $componentRappi->name)->where('component_category_id', $componentCategoryMypos->id)->first();
        if (!$componentMypos) {
            $componentMypos = $this->setComponent($componentRappi->name, $componentCategoryMypos->id);
        }
        $integrationProduct = $this->setIntegrationProduct($productMypos, $componentRappi);

        $component = Component::where('id', $componentMypos->id)
            ->with(['componentStocks'])
            ->whereHas(
                'componentStocks',
                function ($q) use ($store) {
                    $q->where('store_id', $store->id);
                }
            )->first();

        $component->SKU = $componentRappi->sku;
        $component->save();

        $stockDB = ComponentStock::where('component_id', $component->id)
            ->where('store_id', $store->id)->first();
        if (!$stockDB) {
            $stockDB = new ComponentStock();
            $stockDB->store_id = $store->id;
            $stockDB->component_id = $component->id;
            $stockDB->cost = intval($componentRappi->price * 100);
            $stockDB->save();
        }

        $connectedProducts = ProductsConnectionIntegration::where("main_product_id", $productRappi->id)->where("component_product_id", $integrationProduct->id)->where('connection_type', $productRappi->type)->first();
        if (!$connectedProducts) {
            $connectedProducts = new ProductsConnectionIntegration();
            $connectedProducts->main_product_id = $productRappi->id;
            $connectedProducts->component_product_id = $integrationProduct->id;
            $connectedProducts->connection_type =  $productRappi->type;
            $connectedProducts->save();
        }

        return $component;
    }

    /**
     * Por defecto esta función buscará por sku, a no ser que se envíe otro nombre como parámetro en $searchIntegrationBy
     */
    public function setIntegrationProduct($productMypos, $productRappi, String $searchIntegrationBy = 'sku')
    {
        $productIntegrationDetail = ProductIntegrationDetail::where($searchIntegrationBy, $productRappi->$searchIntegrationBy)
            ->where('product_id', $productMypos->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->first();

        if (!$productIntegrationDetail) {
            $productIntegrationDetail = new ProductIntegrationDetail();
            $productIntegrationDetail->product_id = $productMypos->id;
            $productIntegrationDetail->integration_name = AvailableMyposIntegration::NAME_RAPPI;
            $productIntegrationDetail->$searchIntegrationBy = $productRappi->$searchIntegrationBy;
            $productIntegrationDetail->name = $productRappi->name;
            $productIntegrationDetail->price = intval($productRappi->price * 100);

            if (!isset($productRappi->type)) {
                $productRappi->type = "product";
            }
            if (!isset($productRappi->subtype)) {
                $productRappi->subtype = "Rappi Menu";
            }

            $productIntegrationDetail->type = $productRappi->type;
            $productIntegrationDetail->subtype = $productRappi->subtype;
            $productIntegrationDetail->save();
        }

        //// TODO: MANDAR A SERVIDOR SLAVE

        return $productIntegrationDetail;
    }

    public function setProduct($name, $sku, $baseValue, $categoryId, $store)
    {
        $product = new Product();
        $product->product_category_id = $categoryId;
        $product->name = $name;
        // $product->search_string = Helper::remove_accents($name);
        $product->search_string = $name;
        $product->priority = 0;
        $product->base_value = $baseValue;
        $product->status = 1;
        $product->sku = $sku;
        $product->ask_instruction = 0;
        $product->invoice_name = mb_substr($name, 0, 25, "utf-8");
        $product->save();

        //Asignamos los impuestos globales de la tienda al producto
        $this->assignGlobalTaxes($product, $store);

        if ($product) {
            $productDetail = new ProductDetail();
            $productDetail->product_id = $product->id;
            $productDetail->store_id = $store->id;
            $productDetail->stock = 0;
            $productDetail->value = $baseValue;
            $productDetail->status = 1;
            $productDetail->save();
        }

        //// TODO: MANDAR A SERVIDOR SLAVE
        $response = array(
            'product' => $product,
            'detail' => $productDetail
        );
        return $response;
    }

    public function setProductCategory($categoryName, $companyId, String $creatingBy = 'sku', $section_id)
    {
        $productCategory = new ProductCategory();
        $productCategory->name = $categoryName;
        // $productCategory->search_string = Helper::remove_accents($categoryName);
        $productCategory->search_string = $categoryName;
        $productCategory->priority = 0;
        // $productCategory->status = $creatingBy != 'sku' ? 1 : 0;
        $productCategory->status = 1;
        $productCategory->company_id = $companyId;
        // $productCategory->deleted_at = $creatingBy != 'sku' ? null : Carbon::now()->toDateTimeString();
        $productCategory->subtitle = "";
        $productCategory->section_id = $section_id;
        $productCategory->save();

        //// TODO: MANDAR A SERVIDOR SLAVE

        return $productCategory;
    }

    public function setComponentCategory($categoryName, $companyId)
    {
        $componentCategory = new ComponentCategory();
        $componentCategory->name = $categoryName;
        // $componentCategory->search_string = Helper::remove_accents($categoryName);
        $componentCategory->search_string = $categoryName;
        $componentCategory->priority = 0;
        $componentCategory->status = 1;
        $componentCategory->company_id = $companyId;
        $componentCategory->save();

        //// TODO: MANDAR A SERVIDOR SLAVE

        return $componentCategory;
    }

    public function setComponent($name, $componentCategoryId)
    {
        $component = new Component();
        $component->name = $name;
        $component->component_category_id = $componentCategoryId;
        $component->status = 1;
        $component->save();
        //// TODO: MANDAR A SERVIDOR SLAVE

        return $component;
    }

    public function setSpecificationCategory($categoryName, $companyId, $section_id = null)
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

        if ($section_id !== null) {
            $category->section_id = $section_id;
        }
        $category->save();
        //// TODO: MANDAR A SERVIDOR SLAVE
        return $category;
    }

    public function setSpecification($name, $value, $specificationCategoryId)
    {
        $specification = new Specification();
        $specification->name = $name;
        $specification->specification_category_id = $specificationCategoryId;
        $specification->status = 1;
        $specification->value = intval($value * 100);
        $specification->save();

        //// TODO: MANDAR A SERVIDOR SLAVE
        return $specification;
    }

    public function insertToppingFromRappiObject($topping, $myposSpecification, String $searchToppingBy = 'sku')
    {

        $toppingIntegration = ToppingIntegrationDetail::where($searchToppingBy, $topping->$searchToppingBy)
            ->where('specification_id', $myposSpecification->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->first();

        if (!$toppingIntegration) {
            $toppingIntegration = new ToppingIntegrationDetail();
            $toppingIntegration->specification_id = $myposSpecification->id;
            $toppingIntegration->integration_name = AvailableMyposIntegration::NAME_RAPPI;
            if ($searchToppingBy == 'sku') {
                $toppingIntegration->$searchToppingBy = $topping->$searchToppingBy;
            }
            $toppingIntegration->name = $topping->name;
            $toppingIntegration->price = intval($topping->price * 100);
            $toppingIntegration->type = !isset($topping->type) ? 'Topping' : $topping->type;
            $toppingIntegration->subtype = !isset($topping->subtype) ? 'Topping' : $topping->subtype;
            $toppingIntegration->external_topping_category_id = $searchToppingBy != 'sku' ? $topping->$searchToppingBy : $topping->toppingCategoryId;
            $toppingIntegration->save();

            /// TODO: MANDAR A SERVIDOR SLAVE
        }

        return $toppingIntegration;
    }

    public function setInvoice($order, $billing, $store)
    {
        $invoiceNumber = Helper::getNextBillingOfficialNumber($store->id, true);
        /// Si maneja alternate Billing Sequence, se usa el official bill_sequence dentro
        ///// de store. Para esto el switch debe ser false (para no usar el alternate)
        // $alternateBill = Helper::getAlternatingBillingNumber($store->id, false);
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

        $this->populateInvoiceTaxDetails($order, $invoice);

        $invoice->load('order', 'billing', 'items', 'taxDetails');

        return $invoice;
    }
}
