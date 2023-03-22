<?php

namespace App\Traits\Aloha;

use Log;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use App\Traits\LocaleHelper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\StoreConfig;
use App\Store;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\AvailableMyposIntegration;
use App\StoreIntegrationId;
use App\Traits\LoggingHelper;
use Carbon\Carbon;

use App\ProductExternalId;
use App\SpecificationExternalId;
use App\SpecificationCategoryExternalId;
use App\OrderExternalId;

trait AlohaOrder
{
    use LocaleHelper, LoggingHelper;

    public function uploadOrder($storeId, $order, $isTesting, $nameIntegration)
    {
        $this->logIntegration(
            "AlohaOrder uploadOrder",
            "info"
        );
        $store = Store::where('id', $storeId)->first();
        if ($store == null) {
            return ([
                "message" => "No se encontró esta tienda",
                "data" => null,
                "success" => false
            ]);
        }

        $integrationAloha = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_ALOHA
        )
        ->first();
        if ($integrationAloha == null) {
            return ([
                "message" => "myPOS no tiene configurado la integración con Aloha",
                "data" => null,
                "success" => false
            ]);
        }

        $integration = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', $integrationAloha->code_name)
            ->where('type', 'pos')
            ->first();
        if ($integration == null) {
            return ([
                "message" => "Esta tienda no tiene token de Aloha",
                "data" => null,
                "success" => false
            ]);
        }

        $configAloha = StoreIntegrationId::where('store_id', $store->id)
                ->where('integration_id', $integrationAloha->id)
                ->first();
        if ($configAloha == null) {
            return ([
                "message" => "Esta tienda no está configurada para usar Aloha",
                "data" => null,
                "success" => false
            ]);
        }

        $userId = null;
        $baseUrl = config('app.aloha_url_api');
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());

        // Verificando si existe el usuario myPOS en Aloha
        $userCredentials = [
            "email" => "xxx@xxx.xxx",
            "password" => base64_encode("xxxxx"),
        ];

        $jsonObject = json_encode($userCredentials, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response2 = $browser->post(
            $baseUrl . "cliente/verificarLogin/"  . $integration->token . "/" . $configAloha->external_store_id,
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response2->getStatusCode() != 200) {
            // Si no existe el usuario, se lo crea en Aloha
            $newUser = [
                "email" => "xxx@xxx.xxx",
                "password" => "xxxxxxxxx",
                "nombre" => "myPOS",
                "apellido" => "Developer Team",
                "fecha_de_nac" => "1990-01-01",
                "telefono" => "0912345678",
                "token" => $integration->token,
                "dbname" => $configAloha->external_store_id
            ];

            $jsonObject = json_encode($newUser, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
            $response3 = $browser->post(
                $baseUrl . "usuario/create/"  . $integration->token . "/" . $configAloha->external_store_id,
                [
                    'User-Agent' => 'Buzz',
                    'Content-Type' => 'application/json'
                ],
                $jsonObject
            );

            if ($response3->getStatusCode() != 201) {
                // No fue posible crear el cliente de Aloha, por lo tanto no se puede enviar el
                // json de la orden a Aloha.
                $responseBody3 = json_decode($response3->getBody());
                $errorResponse3 = $responseBody3->error;
                $this->logIntegration(
                    $response3->getBody(),
                    "info"
                );
                return ([
                    "message" => "No se pudo crear el usuario con Aloha",
                    "data" => ["error" => $errorResponse3],
                    "success" => false
                ]);
            } else {
                $responseBody = json_decode($response3->getBody());
                $userId = $responseBody->id;
            }
        } else {
            $responseBody = json_decode($response2->getBody());
            $userId = $responseBody->usuario->id;
        }
        
        if ($userId == null) {
            return ([
                "message" => "No se pudo crear obtener el usurio con Aloha",
                "data" => null,
                "success" => false
            ]);
        }

        $countryCode = "-" . $store->country_code;
        $customer = [
            "id_usuario" => $userId,
            "id_direccion_entrega" => 0,
            "cliid" => "0999999999",
            "FirstName" => $nameIntegration . " myPOS",
            "LastName" => "Orden: " . $order->orderIntegrationDetail->order_number . " Cliente: " . $order->orderIntegrationDetail->customer_name,
            "AddressLine1" => "myPOS",
            "City" => "-",
            "State" => "-",
            "PhoneNumber" => "123456789",
            "Note" => $order->instructions == null ? "" : $order->instructions
        ];

        // 100: Cash
        // 101: Uber
        // 102: Rappi
        // 103: Didi
        // 104: iFood
        $tenderType = 100;
        switch ($nameIntegration) {
            case 'Uber':
                $tenderType = 101;
                break;
            case 'Rappi':
                $tenderType = 102;
                break;
            case 'Didi':
                $tenderType = 103;
                break;
            case 'iFood':
                $tenderType = 104;
                break;
            default:
                break;
        }

        $alohaOrder = [
            "Order" => [
                "Customer" => $customer,
                "mode" => 1,
                "orden_app" => "",
                "tenders" => [
                    [
                        "type" => $tenderType,
                        "card_number" => 0,
                        "expiration_date" => Carbon::parse($order->created_at)->addYear()->format('Y-m'),
                        "amount" => $order->invoice->total / 100
                    ]
                ],
                "Items" => []
            ]
        ];


        foreach ($order->orderDetails as $detail) {
            $externalId = ProductExternalId::where('product_id', $detail->productDetail->product->id)->first();
            if (is_null($externalId)) {
                continue;
            }
            $item = [
                "MenuItemId" => (string) $externalId->external_id,
                "Quantity" => $detail->quantity,
                "Price" => $detail->value / 100,
                "SubItems" => []
            ];
            $modifiers = $detail->orderSpecifications;
            foreach ($modifiers as $modifier) {
                $externalId3 = SpecificationCategoryExternalId::where('spec_category_id', $modifier->specification->specification_category_id)->first();
                $externalId2 = SpecificationExternalId::where('specification_id', $modifier->specification_id)->first();
                if (is_null($externalId3) || is_null($externalId2)) {
                    continue;
                }
                for ($i=0; $i < $modifier->quantity; $i++) {
                    $subItem = [
                        "MenuItemId" => (string) $externalId2->external_id,
                        "Quantity" => 1,
                        "Price" => $modifier->value / 100,
                        "ModCodeId" => 1,
                        "ModGroupId" => (string) $externalId3->external_id
                    ];
                    array_push($item['SubItems'], $subItem);
                }
            }
            array_push($alohaOrder['Order']['Items'], $item);
        }

        // Subiendo el JSON de la Orden a Aloha
        $jsonObject = json_encode($alohaOrder, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response4 = $browser->post(
            $baseUrl . "order/create/"  . $integration->token . "/" . $configAloha->external_store_id .
                "/". $configAloha->restaurant_branch_external_id .
                "/". $configAloha->restaurant_chain_external_id . "/11",
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        $this->logIntegration(
            $jsonObject,
            "info"
        );

        $this->logIntegration(
            $baseUrl . "order/create/"  . $integration->token . "/" . $configAloha->external_store_id .
                "/". $configAloha->restaurant_branch_external_id .
                "/". $configAloha->restaurant_chain_external_id . "/11",
            "info"
        );

        $responseBody = json_decode($response4->getBody());
        if ($response4->getStatusCode() != 200) {
            $this->logIntegration(
                $response4->getBody(),
                "info"
            );
            return ([
                "message" => "No se pudo crear la orden en Aloha",
                "data" => null,
                "success" => false
            ]);
        }

        $this->logIntegration(
            $response4->getBody(),
            "info"
        );

        $bodyResponse = $response4->getBody()->__toString();
        $bodyJSON = json_decode($bodyResponse, true);
        $externalId = $bodyJSON["orden"]["id"];

        $orderExternalId = new OrderExternalId();
        $orderExternalId->order_id = $order->id;
        $orderExternalId->integration_id = $integrationAloha->id;
        $orderExternalId->external_id = $externalId;
        $orderExternalId->save();
 
        return ([
            "message" => "Orden creada en Aloha",
            "data" => null,
            "success" => true
        ]);
    }
}
