<?php

namespace App\Http\Controllers;

use Log;
use App\Spot;
use App\Helper;
use Buzz\Browser;
use App\StoreConfig;
use App\Traits\OrderHelper;
use App\Traits\Logs\Logging;
use Illuminate\Http\Request;
use App\Traits\TimezoneHelper;
use App\OrderIntegrationDetail;
use App\Traits\Aloha\AlohaOrder;
use Buzz\Client\FileGetContents;
use App\Http\Helpers\QueueHelper;
use App\Traits\Uber\UberRequests;
use App\AvailableMyposIntegration;
use App\Http\Controllers\Controller;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\Integrations\IntegrationsHelper;

class UberEatsControllerV2 extends Controller
{
    use OrderHelper, IntegrationsHelper, AlohaOrder, TimezoneHelper {
        OrderHelper::calculateOrderValues insteadof IntegrationsHelper;
        OrderHelper::calculateOrderValuesIntegration insteadof IntegrationsHelper;
        OrderHelper::processConsumptionAndStock insteadof IntegrationsHelper;
        OrderHelper::reduceComponentsStock insteadof IntegrationsHelper;
        OrderHelper::reduceComponentsStockBySpecification insteadof IntegrationsHelper;
        OrderHelper::reduceComponentStockFromSubRecipe insteadof IntegrationsHelper;
        OrderHelper::addConsumptionToProductionOrder insteadof IntegrationsHelper;
        OrderHelper::dataHumanOrder insteadof IntegrationsHelper;
        OrderHelper::populateInvoiceTaxDetails insteadof IntegrationsHelper;
        OrderHelper::getConsumptionDetails insteadof IntegrationsHelper;
        OrderHelper::prepareToSendForElectronicBilling insteadof IntegrationsHelper;
        OrderHelper::getTaxValuesFromDetails insteadof IntegrationsHelper;
        OrderHelper::totalTips insteadof IntegrationsHelper;
        OrderHelper::calculateOrderValuesIntegrationStatic insteadof IntegrationsHelper;
        OrderHelper::processConsumptionAndStockStatic insteadof IntegrationsHelper;
        OrderHelper::prepareToSendForElectronicBillingStatic insteadof IntegrationsHelper;
        OrderHelper::populateInvoiceTaxDetailsStatic insteadof IntegrationsHelper;
        OrderHelper::reduceComponentsStockStatic insteadof IntegrationsHelper;
        OrderHelper::reduceComponentsStockBySpecificationStatic insteadof IntegrationsHelper;
        OrderHelper::addConsumptionToProductionOrderStatic insteadof IntegrationsHelper;
    }

    private $channelLogUECV2 = "uber_orders_logs";
    private $channelSlackDevUECV2 = "#integration_logs_details";

     /**
     *
     * Recive el envento de una nueva orden de Uber
     *
     * @param Request $request    Objeto con los datos principales de la nueva orden de Uber
     *
     * @return Response           Estado del requerimiento
     *
     */
    public function receiveWebhookUberOrder(Request $request)
    {
        Logging::printLogFile(
            "UberEatsControllerV2 receiveWebhookUberOrder ".json_encode($request->getContent()),
            $this->channelLogUECV2
        );
        $bodyRequest = $request->getContent();
        $headerSignatureRequest = $request->header('x-uber-signature');
        $secret = config('app.eats_client_secret_v2');
        $signature = hash_hmac('SHA256', $bodyRequest, $secret);
        try {
            if ($headerSignatureRequest !== null) {
                if (hash_equals($headerSignatureRequest, $signature)) {

                    switch ($request->event_type) {
                        case 'orders.notification':
                            $this->storeOrder($bodyRequest);
                            break;

                        case 'orders.cancel':
                            $this->cancelOrder($bodyRequest);
                            break;
                        
                        default:
                            throw new \Exception("event_type ({$request->event_type}) no esperado");
                            break;
                    }
                } else {
                    throw new \Exception("La firma del request no coincide");
                }
            } else {
                throw new \Exception("La firma del request es null");
            }
        } catch (\Exception $e) {
            Logging::printLogFile(
                "UberEatsControllerV2 receiveWebhookUberOrder NO SE PUDO GUARDAR LA ORDER DE UBER EATS",
                $this->channelLogUECV2,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
        }
        return response()->json([], 200);
    }

    /**
     *
     * Guarda la nueva orden de Uber en myPOS
     *
     * @param object $information   Objeto con los datos principales de la nueva orden de Uber
     *
     * @return void
     *
     */
    public function storeOrder($information)
    {
        Logging::printLogFile(
            "UberEatsControllerV2 storeOrder",
            $this->channelLogUECV2
        );
        $informationObj = json_decode($information);
        $eatsStoreId = $informationObj->meta->user_id;
        $eatsOrderId = $informationObj->meta->resource_id;
        Logging::printLogFile(
            "UberStoreId: " . $eatsStoreId . "  ---  UberOrderId: " . $eatsOrderId,
            $this->channelLogUECV2
        );

        $integrationData = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_EATS)
            ->first();

        if ($integrationData == null) {
            throw new \Exception("myPOS no tiene configurado esta integración");
        }

        $storeConfig = StoreConfig::with(['store' => function ($store) {
            $store->with('eatsIntegrationToken');
        }])->where('eats_store_id', (string) $eatsStoreId)->first();
        if (is_null($storeConfig)) {
            throw new \Exception("Esta tienda no tiene store config con ese id de store de uber eats");
        }

        $integration = $storeConfig->store->eatsIntegrationToken;
        if (is_null($integration)) {
            throw new \Exception("Esta tienda no tiene token de uber eats");
        }

        $baseUrl = config('app.eats_url_api');
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $orderUber = '';

        UberRequests::initVarsUberRequests(
            $this->channelLogUECV2,
            $this->channelSlackDevUECV2,
            $baseUrl,
            $browser
        );

        $store = $storeConfig->store;

        $resultDetails = UberRequests::getOrderDetails(
            $integration->token,
            $store->name,
            $store->id,
            (string) $eatsOrderId
        );

        if ($resultDetails["status"] == 0) {
            // Falló en obtener la info de la orden
            throw new \Exception("No se pudo obtener la información de la orden en Uber Eats");
        }
        // Información de la orden
        $orderUber = $resultDetails["data"];
        $bodyJSON = json_decode($orderUber, true);
        
        $channel = Logging::getSlackChannel($storeConfig->store_id);
        $datetimeFormat = 'Y-m-d H:i:s';
        $dateCreated = $this->localizedNowDateForStore($store);
        $createdDateFormat = $dateCreated->format($datetimeFormat);

        // Creando array de objetos con la data de la orden
        $itemsOrder = [];
        $sumTotalOrder = 0;
        if (isset($bodyJSON["cart"]["items"])) {
            foreach ($bodyJSON["cart"]["items"] as $item) {
                $modifiers = [];
                if (isset($item["selected_modifier_groups"])) {
                    foreach ($item["selected_modifier_groups"] as $groupModifier) {
                        foreach ($groupModifier["selected_items"] as $modiferSelected) {
                            $modifier = [
                                "external_id" => isset($modiferSelected["external_data"]) ? $modiferSelected["external_data"] : null,
                                "name" => $modiferSelected["title"],
                                "quantity" => $modiferSelected["quantity"],
                                "unit_value" => $modiferSelected["price"]["unit_price"]["amount"],
                                "total_value" => $modiferSelected["price"]["total_price"]["amount"]
                            ];
                            array_push($modifiers, $modifier);
                        }
                    }
                }
                $sumTotalOrder += $item["price"]["total_price"]["amount"];
                $itemInstructions = "";
                if (isset($item["special_instructions"])) {
                    $itemInstructions = $item["special_instructions"];
                }
                $item = [
                    "external_id" => isset($item["external_data"]) ? $item["external_data"] : null,
                    "name" => $item["title"],
                    "quantity" => $item["quantity"],
                    "unit_value" => $item["price"]["base_unit_price"]["amount"],
                    "total_unit_value" => $item["price"]["unit_price"]["amount"],
                    "total_value" => $item["price"]["total_price"]["amount"],
                    "modifiers" => $modifiers,
                    "instructions" => $itemInstructions,
                ];
                array_push($itemsOrder, $item);
            }
        }

        $orderValue = 0;
        $totalValue = 0;
        $feeValue = 0;
        foreach ($bodyJSON["payment"]["charges"] as $key => $charge) {
            switch ($key) {
                case "sub_total":
                    $orderValue += ((int) $charge["amount"]);
                    break;
                case "total":
                    $totalValue += ((int) $charge["amount"]);
                    break;
                case "total_fee":
                    $feeValue += ((int) $charge["amount"]);
                    break;
                default:
                    break;
            }
        }
        // Cálculo de descuento
        $discountValue = $sumTotalOrder - $orderValue;

        $intructions = "";
        if (isset($bodyJSON["cart"]["special_instructions"])) {
            $intructions = "Instrucciones de la orden: " .
                $bodyJSON["cart"]["special_instructions"] .
                "\n";
        }

        // Utencilios desechables
        $hasDisposableItems = false;
        if (isset($bodyJSON["packaging"]) && isset($bodyJSON["packaging"]["disposable_items"])) {
            $hasDisposableItems = $bodyJSON["packaging"]["disposable_items"]["should_include"];
        }

        $orderData = [
            "automatic"=>$storeConfig->automatic,
            "external_id" => (string) $eatsOrderId,
            "created_at" => $createdDateFormat,
            "total" => $sumTotalOrder,
            "customer" => $bodyJSON["eater"]["first_name"],
            "external_store_id" => $eatsStoreId,
            "order_number" => $bodyJSON["display_id"],
            "instructions" => $intructions,
            "items" => $itemsOrder,
            "discount_value" => $discountValue,
            "disposable_items" => $hasDisposableItems
        ];

        $store->load("hubs");
        $hub = null;

        if ($store->hubs != null && $store->hubs->first() != null) {
            $hub = $store->hubs->first();
        }

        $resultCreateOrder = $this->createIntegrationOrder(
            $orderData,
            $integrationData,
            $store->id,
            $store->name,
            $storeConfig,
            $this->channelLogUECV2,
            Spot::ORIGIN_EATS,
            $hub
        );

        if ($resultCreateOrder["status"] == 1 || $resultCreateOrder["status"] == 2) {
            if($storeConfig->automatic){
                // Enviando Respuesta OK a Uber Eats de que se creó la orden en myPOS
                $resultDetails = UberRequests::sendConfirmationEvent(
                    $integration->token,
                    $store->name,
                    (string) $eatsOrderId
                );
                if ($resultDetails["status"] == 0) {
                    // Falló en obtener la info de la orden
                    throw new \Exception("No se pudo enviar la aceptación del pedido");
                }

                if ($resultCreateOrder["status"] == 1) {
                    // Enviando orden a Aloha
                    $result = $this->uploadOrder($storeConfig->store_id, $resultCreateOrder["data"]["order"], 1, "Uber");
                    Logging::printLogFile(
                        "ResultAlohaIntegration:  " . json_encode($result),
                        $this->channelLogUECV2
                    );
                    //Envía a las integraciones de backoffice
                    $this->prepareToSendForElectronicBilling(
                        $storeConfig->store,
                        $resultCreateOrder["data"]["invoice"],
                        AvailableMyposIntegration::NAME_EATS,
                        null,
                        null,
                        [
                            'cashier' => null,
                            'invoice' => $resultCreateOrder["data"]["invoice"]
                        ]
                    );
                }
            }
            return;
        } elseif ($resultCreateOrder["status"] == 0) {
            Logging::sendSlackMessage(
                $channel,
                $resultCreateOrder["slackMessage"]
            );
            // Enviando Respuesta DENY a Uber Eats de que se no se pudo crear la orden en myPOS
            $msg = '{ "reason": { "explanation": "' . $resultCreateOrder["message"] . '" } }';
            $resultDetails = UberRequests::sendRejectionEvent(
                $integration->token,
                $store->name,
                (string) $eatsOrderId,
                $msg
            );
        }
    }

    public function cancelOrder($event){
        try {
            $informationObj = json_decode($event);
            $uberOrderId = $informationObj->meta->resource_id;
            $orderInt = OrderIntegrationDetail::where('external_order_id', $uberOrderId)
                ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                ->first();
            if(empty($orderInt)){
                throw new \Exception("no se encuentra la orden {$uberOrderId} en myPOS");
            }

            $order = $orderInt->order;
            $order->status = 0;
            $order->save();

            $job = array();
            $job["store_id"] = $order->store->id;
            $job["order"] = $order;
            QueueHelper::dispatchJobs(array($job));

        } catch (\Throwable $e) {
            Logging::printLogFile(
                "UberEatsControllerV2 receiveWebhookUberOrder NO SE PUDO CANCELAR LA ORDEN",
                $this->channelLogUECV2,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($event)
            );
        }
    }
}
