<?php

namespace App\Traits\iFood;

// Libraries
use GuzzleHttp\Client;
use Log;
use Carbon\Carbon;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;

// Models
use App\StoreIntegrationId;
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;
use App\PaymentType;

// Helpers
use App\Traits\iFood\IfoodOrder;
use App\Traits\Logs\Logging;

trait IfoodRequests
{
    use IfoodOrder;

    public static $channelLog = null;
    public static $channelSlackDev = null;
    public static $baseUrl = null;
    public static $client = null;
    public static $browser = null;
    private static $iFoodClientId = null;
    private static $iFoodClientSecret = null;
    private static $iFoodUsername = null;
    private static $iFoodPassword = null;

    public static function initVarsIfoodRequests($channel, $slack, $baseUrl, $browser)
    {
        self::$channelLog = $channel;
        self::$channelSlackDev = $slack;
        self::$baseUrl = $baseUrl;
        self::$browser = $browser;
        self::$iFoodClientId = config('app.ifood_client_id');
        self::$iFoodClientSecret = config('app.ifood_client_secret');
        self::$iFoodUsername = config('app.ifood_username');
        self::$iFoodPassword = config('app.ifood_password');
    }

    /**
     *
     * Verifica la configuración de ifood de la tienda, y devolviendo el token(renovado si es necesario)
     *
     * @return array Data de la integración de iFood
     *
     */
    public static function checkIfoodConfiguration($store)
    {
        $integrationData = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_IFOOD)
            ->first();

        if ($integrationData == null) {
            return ([
                "message" => "myPOS no tiene configurado la integración con iFood",
                "code" => 409,
                "data" => null
            ]);
        }

        $integrationToken = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', $integrationData->code_name)
            ->where('type', 'delivery')
            ->first();

        if ($integrationToken == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id de la tienda en la sección de iFood. Acércate a tu ejecutivo de cuenta de iFood para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409,
                "data" => null
            ]);
        }

        // Verificar si el token ha caducado
        $now = Carbon::now();
        $emitted = Carbon::parse($integrationToken->updated_at);
        $diff = $now->diffInSeconds($emitted);
        // El token sólo dura 1 hora(3600 segundos)
        if ($diff > 3599) {
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            $channelLog = "ifood_logs";
            $channelSlackDev = "#integration_logs_details";
            $baseUrl = config('app.ifood_url_api');

            self::initVarsIfoodRequests(
                $channelLog,
                $channelSlackDev,
                $baseUrl,
                $browser
            );
            $resultToken = self::getToken();
            if ($resultToken["status"] != 1) {
                return ([
                    "message" => "No se pudo actualizar el token de iFood",
                    "code" => 409,
                    "data" => null
                ]);
            }
            $integrationToken = StoreIntegrationToken::where('store_id', $store->id)
                ->where('integration_name', $integrationData->code_name)
                ->where('type', 'delivery')
                ->first();
        }

        $config = StoreIntegrationId::where('store_id', $store->id)
                ->where('integration_id', $integrationData->id)
                ->first();

        if ($config == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id de la tienda en la sección de iFood. Acércate a tu ejecutivo de cuenta de iFood para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409,
                "data" => null
            ]);
        }
        return ([
            "code" => 200,
            "data" => [
                "integrationData" => $integrationData,
                "integrationToken" => $integrationToken,
                "integrationConfig" => $config
            ],
            "message" => null
        ]);
    }

    /**
     *
     * Obtener el token de iFood para usarse en los endpoints
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function getToken()
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Obteniendo el token de iFood",
            self::$channelLog
        );

        $data = [
            [
                "name" => "client_id",
                "contents" => self::$iFoodClientId
            ],
            [
                "name" => "client_secret",
                "contents" => self::$iFoodClientSecret
            ],
            [
                "name" => "grant_type",
                "contents" => "password"
            ],
            [
                "name" => "username",
                "contents" => self::$iFoodUsername
            ],
            [
                "name" => "password",
                "contents" => self::$iFoodPassword
            ]
        ];

        $client = new Client();
        $response = $client->request(
            'POST',
            self::$baseUrl . 'oauth/token',
            [
                'multipart' => $data,
                'headers' => [
                ],
                'http_errors' => false
            ]
        );

        $responseBody = $response->getBody();
        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                "Error al obtener el token de iFood",
                self::$channelLog
            );
            Logging::printLogFile(
                $response->getStatusCode(),
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al obtener el token de iFood\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            $status = 1;
            Logging::printLogFile(
                "Token obtenido con éxito",
                self::$channelLog
            );
            $dataJSON = json_decode($responseBody, true);

            // Actualizando los tokens de todas las tiendas
            $storeIntegrationTokens = StoreIntegrationToken::where(
                'integration_name',
                AvailableMyposIntegration::NAME_IFOOD
            )
                ->get();
            foreach ($storeIntegrationTokens as $storeIntegrationToken) {
                $storeIntegrationToken->token = $dataJSON["access_token"];
                $storeIntegrationToken->expires_in = $dataJSON["expires_in"];
                $storeIntegrationToken->save();
            }
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Subir categoría
     *
     * Crear una categoría en iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId        Id de la tienda de iFood
     * @param string $storeName           Nombre de la tienda
     * @param string $categoryData        Data de la categoría a subir a iFood
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function uploadCategory($token, $ifoodStoreId, $storeName, $categoryData)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Subiendo categoría: " . $categoryData["externalCode"],
            self::$channelLog
        );

        $jsonObject = json_encode($categoryData, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response = self::$browser->post(
            self::$baseUrl . 'v1.0/categories',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response->getStatusCode() !== 201) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al crear la categoría en iFood",
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al crear la categoría en iFood: " . $categoryData["externalCode"] . "\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Categoría creada con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Subir un item
     *
     * Crear un item en iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId        Id de la tienda de iFood
     * @param string $storeName           Nombre de la tienda
     * @param string $itemData            Data del item a subir a iFood
     * @param string $categoryId          Id de la categoría a la que pertenece el item
     * @param string $isModifier          Indica si el item a crear es modificador
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function uploadItem($token, $ifoodStoreId, $storeName, $itemData, $categoryId, $isModifier)
    {
        $status = 0; // 0: Error, 1: Éxito

        $dataJSON = json_decode($itemData[0]["contents"]);
        Logging::printLogFile(
            "Subiendo item: " . $dataJSON->externalCode,
            self::$channelLog
        );
    
        try {
            $client = new Client();
            $response = $client->request(
                'POST',
                self::$baseUrl . 'v1.0/skus',
                [
                    'multipart' => [$itemData[0]],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token
                    ],
                    'http_errors' => false
                ]
            );

            if ($response->getStatusCode() !== 201 && $response->getStatusCode() !== 409) {
                $responseBody = $response->getBody();
                Logging::printLogFile(
                    "Error al crear el producto en iFood status code ". $response->getStatusCode(),
                    self::$channelLog
                );
                Logging::printLogFile(
                    $responseBody,
                    self::$channelLog
                );
                $slackMessage = "Error al crear el producto: " . $dataJSON->externalCode . "\n" .
                    "Tienda: " . $storeName . "\n" .
                    "Consulte con el desarrollador para ver más detalles";
                Logging::sendSlackMessage(
                    self::$channelSlackDev,
                    $slackMessage
                );
            } elseif (!$isModifier) {
                Logging::printLogFile(
                    "Producto creado con éxito, procediendo a asignar a la categoría",
                    self::$channelLog
                );
                
                $jsonObject2 = json_encode(
                    [
                        "externalCode" => $dataJSON->externalCode,
                        "merchantId" => $dataJSON->merchantId,
                        "order" => $dataJSON->order
                    ],
                    JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE
                );
                $response2 = self::$browser->post(
                    self::$baseUrl . 'v1.0/categories/' . $categoryId .'/skus:link',
                    [
                        'User-Agent' => 'Buzz',
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json'
                    ],
                    $jsonObject2
                );
                if ($response2->getStatusCode() !== 201 && $response2->getStatusCode() !== 409) {
                    $responseBody = $response2->getBody();
                    Logging::printLogFile(
                        "Error al asignar el producto a la categoría en iFood status code ".$response2->getStatusCode(),
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        $responseBody,
                        self::$channelLog
                    );
                    $slackMessage = "Error al asignar el producto a la categoría en iFood\n" .
                        "Tienda: " . $storeName . "\n" .
                        "Consulte con el desarrollador para ver más detalles";
                    Logging::sendSlackMessage(
                        self::$channelSlackDev,
                        $slackMessage
                    );
                } else {
                    Logging::printLogFile(
                        "Producto asignado a la categoría con éxito",
                        self::$channelLog
                    );
                    $status = 1;
                }
            } else {
                Logging::printLogFile(
                    "Modificador creado con éxito, procediendo a asignar al grupo",
                    self::$channelLog
                );
                $jsonObject3 = json_encode(
                    [
                        "externalCode" => $dataJSON->externalCode,
                        "merchantId" => $dataJSON->merchantId,
                        "sequence" => $dataJSON->order
                    ],
                    JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE
                );
                $response3 = self::$browser->post(
                    self::$baseUrl . 'v1.0/option-groups/' . $categoryId .'/skus:link',
                    [
                        'User-Agent' => 'Buzz',
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json'
                    ],
                    $jsonObject3
                );
                if ($response3->getStatusCode() !== 201 && $response3->getStatusCode() !== 409) {
                    $responseBody = $response3->getBody();
                    Logging::printLogFile(
                        "Error al asignar el modificador al grupo en iFood status code ".$response3->getStatusCode(),
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        $responseBody,
                        self::$channelLog
                    );
                    $slackMessage = "Error al asignar el modificador al grupo en iFood\n" .
                        "Tienda: " . $storeName . "\n" .
                        "Consulte con el desarrollador para ver más detalles";
                    Logging::sendSlackMessage(
                        self::$channelSlackDev,
                        $slackMessage
                    );
                } else {
                    Logging::printLogFile(
                        "Modificador asignado al grupo con éxito",
                        self::$channelLog
                    );
                    $status = 1;
                }
            }
            //Se procede a actualizar el producto en caso de que posea imagen
            if(count($itemData)>1){//Si es mayor a 1 es porque se le asigno una imagen
                //Se procede a actualizar el producto para envíar la imagen
                Logging::printLogFile(
                    "actualizando el producto para asignar imagen ".json_encode($itemData),
                    self::$channelLog
                );     
                $client2 = new Client();
                $responseFinal = $client2->request(
                    'PATCH',
                    self::$baseUrl . 'v1.0/skus/' . $dataJSON->externalCode,
                    [
                        'multipart' => $itemData,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token
                        ],
                        'http_errors' => false
                    ]
                    
                );
    
                if ($responseFinal->getStatusCode() !== 200) {
                    $responseBody = $responseFinal->getBody();
                    Logging::printLogFile(
                        "Error al actualizar el item en iFood status code ".$responseFinal->getStatusCode(),
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        $responseBody,
                        self::$channelLog
                    );
                    $slackMessage = "Error al actualizar el item: " . $dataJSON->externalCode . "\n" .
                        "Tienda: " . $storeName . "\n" .
                        "Consulte con el desarrollador para ver más detalles";
                    Logging::sendSlackMessage(
                        self::$channelSlackDev,
                        $slackMessage
                    );
                } else {
                    Logging::printLogFile(
                        "Item actualizado con éxito",
                        self::$channelLog
                    );
                }
            }
            
            return ([
                "status" => $status
            ]);
        } catch (\Exception $e) {
            Logging::printLogFile(
                "Error al crear el item en iFood, para el store: " . $storeName,
                self::$channelLog,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $itemData
            );
            $slackMessage = "Error al crear el item en iFood\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
            return ([
                "status" => 0
            ]);
        }
    }

    /**
     * Subir grupo de modificador
     *
     * Crear un grupo de modificador en iFood
     *
     * @param string $token                    Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId             Id de la tienda de iFood
     * @param string $storeName                Nombre de la tienda
     * @param string $modifierGroupData        Data del grupo de modificador a subir a iFood
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function uploadModifierGroup($token, $ifoodStoreId, $storeName, $modifierGroupData)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Subiendo grupo de modificador: " . $modifierGroupData["externalCode"],
            self::$channelLog
        );

        $jsonObject = json_encode($modifierGroupData, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response = self::$browser->post(
            self::$baseUrl . 'v1.0/option-groups',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response->getStatusCode() !== 201) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al crear el grupo de modificadores en iFood",
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al crear el grupo de modificadores: " . $modifierGroupData["externalCode"] . "\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Grupo de modificadores creado con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Linkear grupo modificador a un item
     *
     * Linkea un grupo modificador a un item en iFood
     *
     * @param string $token                    Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId             Id de la tienda de iFood
     * @param string $storeName                Nombre de la tienda
     * @param string $productId                Product id para al que se le hará el link
     * @param string $data                     Data para hacer el link
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function linkModifierGroupToItem($token, $ifoodStoreId, $storeName, $productId, $data)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Linkeando grupo modificador a un producto",
            self::$channelLog
        );

        $jsonObject = json_encode($data, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response = self::$browser->post(
            self::$baseUrl . 'v1.0/skus/'. $productId . '/option-groups:link',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response->getStatusCode() !== 201 && $response->getStatusCode() !== 409) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al linkear el grupo de modificadores al producto en iFood: " . $storeName,
                self::$channelLog
            );
            Logging::printLogFile(
                "Product id: " . $productId,
                self::$channelLog
            );
            Logging::printLogFile(
                "Data Link: " . $jsonObject,
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al linkear el grupo de modificadores al producto en iFood\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Grupo de modificadores linkeado al producto con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Obtener órdenes
     *
     * Obtiene todas las órdenes pendientes y sus estados dentro de iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     *
     * @return void
     *
     */
    public static function getOrders($token)
    {
        Logging::printLogFile(
            "Obteniendo órdenes de iFood",
            self::$channelLog
        );

        $response = self::$browser->get(
            self::$baseUrl . 'v3.0/events:polling',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se encontraron órdenes de iFood".$response->getStatusCode(),
                self::$channelLog
            );
        } else {
            $responseBody = $response->getBody();
            $ordersJSON = json_decode($responseBody, true);
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $newOrders = [];
            $canceledOrders = [];
            $notNewOrders = [];
            $eventList = [];
           
            foreach ($ordersJSON as $orderJSON) {
                if ($orderJSON["code"] == "PLACED") {
                    array_push($newOrders, $orderJSON["correlationId"]);
                } elseif ($orderJSON["code"] == "CANCELLED") {
                    // Orden no es nueva ya que tiene otro código de estado
                    if (in_array($orderJSON["correlationId"], $newOrders)) {
                        array_push($notNewOrders, $orderJSON["correlationId"]);
                    }
                    array_push($canceledOrders, $orderJSON["correlationId"]);
                } else {
                    // Orden no es nueva ya que tiene otro código de estado
                    array_push($notNewOrders, $orderJSON["correlationId"]);
                }
                array_push(
                    $eventList,
                    [
                        "id" => $orderJSON["id"]
                    ]
                );
            }
            // Filtrando sólo las órdenes que si son nuevas
            $newOrders = array_diff($newOrders, $notNewOrders);
            
            Logging::printLogFile(
                "Ordenes recibidas: ".json_encode($ordersJSON),
                self::$channelLog
            );
            if (count($eventList) > 0) {
                Logging::printLogFile(
                    "Enviando acknowledgment de los eventos recibidos",
                    self::$channelLog
                );
                $jsonObject = json_encode($eventList, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
                $response2 = self::$browser->post(
                    self::$baseUrl . 'v1.0/events/acknowledgment',
                    [
                        'User-Agent' => 'Buzz',
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json'
                    ],
                    $jsonObject
                );
                if ($response->getStatusCode() !== 200) {
                    $responseBody = $response->getBody();
                    Logging::printLogFile(
                        "No se pudo enviar el acknowledgment de los eventos",
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        $responseBody,
                        self::$channelLog
                    );
                } else {
                    Logging::printLogFile(
                        "acknowledgment de eventos enviado con éxito",
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        "Se encontraron " . count($newOrders) . " nuevas órdenes de iFood",
                        self::$channelLog
                    );
                    Logging::printLogFile(
                        "Creando órdenes en myPOS",
                        self::$channelLog
                    );
                    IfoodOrder::processOrders(
                        $newOrders,
                        $canceledOrders,
                        $token
                    );
                }
            }
        }
    }

    /**
     * Obtener la información de la orden
     *
     * Obtiene todos los detalles de la orden realizada en ifood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $externalOrderId     iFood id de la orden
     *
     * @return void
     *
     */
    public static function getOrderInformation($token, $externalOrderId)
    {
        Logging::printLogFile(
            "Obteniendo la información de la orden: " . $externalOrderId,
            self::$channelLog
        );

        $response = self::$browser->get(
            self::$baseUrl . 'v2.0/orders/' . $externalOrderId,
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo obtener la información de la orden",
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            $responseBody = $response->getBody();
            $ordersJSON = json_decode($responseBody, true);
            Logging::printLogFile(
                "JSON con la información de la orden ",
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );

            $itemsOrder = [];
            if (isset($ordersJSON["items"])) {
                foreach ($ordersJSON["items"] as $itemOrder) {
                    $modifiers = [];
                    if (!isset($itemOrder["externalCode"])) {
                        Logging::printLogFile(
                            "Orden ignorada porque el item no tiene externalCode",
                            self::$channelLog
                        );
                        return ([
                            "status" => 0,
                            "data" => null
                        ]);
                    }
                    if (isset($itemOrder["subItems"])) {
                        foreach ($itemOrder["subItems"] as $modiferSelected) {
                            if (!isset($modiferSelected["externalCode"])) {
                                Logging::printLogFile(
                                    "Orden ignorada porque el modifier no tiene externalCode",
                                    self::$channelLog
                                );
                                return ([
                                    "status" => 0,
                                    "data" => null
                                ]);
                            }
                            $modifier = [
                                "external_id" => $modiferSelected["externalCode"],
                                "name" => $modiferSelected["name"],
                                "quantity" => $modiferSelected["quantity"],
                                "unit_value" => $modiferSelected["price"] * 100,
                                "total_value" => $modiferSelected["totalPrice"] * 100
                            ];
                            array_push($modifiers, $modifier);
                        }
                    }
                    $item = [
                        "external_id" => $itemOrder["externalCode"],
                        "name" => $itemOrder["name"],
                        "quantity" => $itemOrder["quantity"],
                        "unit_value" => $itemOrder["price"] * 100,
                        "total_value" => $itemOrder["totalPrice"] * 100,
                        "instructions" => isset($itemOrder["observations"]) ? $itemOrder["observations"] : "",
                        "modifiers" => $modifiers
                    ];
                    array_push($itemsOrder, $item);
                }
            }

            $datetimeFormat = 'Y-m-d H:i:s';
            $createdDate = \DateTime::createFromFormat("Y-m-d\TH:i:s.uP", $ordersJSON["createdAt"]);
            $createdDateFormat = $createdDate->format($datetimeFormat);

            $payments = [];
            if (isset($ordersJSON["payments"])) {
                foreach ($ordersJSON["payments"] as $payment) {
                    $code = $payment["code"];
                    $pType = PaymentType::CREDIT;
                    if ($code == "DIN") {
                        $pType = PaymentType::CASH;
                    }
                    array_push(
                        $payments,
                        [
                            "type" => $pType,
                            "value" => ($payment["value"] * 100) - ($ordersJSON["deliveryFee"] * 100)
                        ]
                    );
                }
            }

            $orderData = [
                "external_id" => $externalOrderId,
                "created_at" => $createdDateFormat,
                "total" => $ordersJSON["subTotal"] * 100,
                "customer" => $ordersJSON["customer"]["name"],
                "external_store_id" => $ordersJSON["merchant"]["shortId"],
                "order_number" => $ordersJSON["shortReference"],
                "instructions" => "",
                "items" => $itemsOrder,
                "payments" => $payments
            ];

            return ([
                "status" => 1,
                "data" => $orderData
            ]);
        }
    }

    /**
     * Informar que la orden se integró correctamente
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     iFood id de la orden
     *
     * @return void
     *
     */
    public static function sendIntegrationEvent($token, $storeName, $externalOrderId)
    {
        // Status
        // 0: Error
        // 1: Éxito
        Logging::printLogFile(
            "Enviando que la orden fue integrada correctamente, para la tienda: " . $storeName,
            self::$channelLog
        );

        $response = self::$browser->post(
            self::$baseUrl . 'v2.0/orders/' . $externalOrderId . '/statuses/integration',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        );

        if ($response->getStatusCode() !== 202) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de integración de la orden: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            Logging::printLogFile(
                "Orden integrada correctamente: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 1,
                "data" => null
            ]);
        }
    }

    /**
     * Informar que la orden se aceptó correctamente
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     iFood id de la orden
     *
     * @return void
     *
     */
    public static function sendConfirmationEvent($token, $storeName, $externalOrderId)
    {
        // Status
        // 0: Error
        // 1: Éxito
        Logging::printLogFile(
            "Enviando que la orden fue aceptada correctamente, para la tienda: " . $storeName,
            self::$channelLog
        );

        $response = self::$browser->post(
            self::$baseUrl . 'v2.0/orders/' . $externalOrderId . '/statuses/confirmation',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        );

        if ($response->getStatusCode() !== 202) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de aceptación de la orden: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            Logging::printLogFile(
                "Orden aceptada correctamente: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 1,
                "data" => null
            ]);
        }
    }

    /**
     * Informar que la orden se rechazó dentro de myPOS
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     iFood id de la orden
     * @param string $details             Detalles con el motivo de rechazo de la orden
     *
     * @return void
     *
     */
    public static function sendRejectionEvent($token, $storeName, $externalOrderId, $details)
    {
        // Status
        // 0: Error
        // 1: Éxito
        Logging::printLogFile(
            "Enviando que la orden fue rechazada, para la tienda: " . $storeName,
            self::$channelLog
        );

        $jsonObject = json_encode(
            [
                "cancellationCode" => 801,
                "details" => "myPOS: " . $details
            ],
            JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE
        );
        $response = self::$browser->post(
            self::$baseUrl . 'v3.0/orders/' . $externalOrderId . '/statuses/cancellationRequested',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response->getStatusCode() !== 202) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de rechazo de la orden: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            Logging::printLogFile(
                "Orden rechazada correctamente: " . $externalOrderId,
                self::$channelLog
            );
            return ([
                "status" => 1,
                "data" => null
            ]);
        }
    }

    /**
     * Obtener todas las categorías del menú de iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $ifoodStoreId        Id de la tienda de iFood
     *
     * @return void
     *
     */
    public static function getAllCategories($token, $storeName, $iFoodStoreId)
    {
        Logging::printLogFile(
            "Obteniendo las categorías de la tienda: " . $storeName,
            self::$channelLog
        );

        $response = self::$browser->get(
            self::$baseUrl . 'v1.0/merchants/' . $iFoodStoreId . '/menus/categories',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo obtener las categorías",
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            $responseBody = $response->getBody();
            $categoriesIFood = json_decode($responseBody, true);
            Logging::printLogFile(
                "Se encontraron " . count($categoriesIFood) . " categorías de esta tienda",
                self::$channelLog
            );

            return ([
                "status" => 1,
                "data" => $categoriesIFood
            ]);
        }
    }

    /**
     * Obtener todos los productos de una categoría
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $ifoodStoreId        Id de la tienda de iFood
     * @param string $ifoodCategoryId     Id de la categoría de iFood
     *
     * @return void
     *
     */
    public static function getProductsCategory($token, $storeName, $iFoodStoreId, $ifoodCategoryId)
    {
        Logging::printLogFile(
            "Obteniendo los productos de la categoría: " . $ifoodCategoryId,
            self::$channelLog
        );

        $response = self::$browser->get(
            self::$baseUrl . 'v1.0/merchants/' . $iFoodStoreId . '/menus/categories/' . $ifoodCategoryId,
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo obtener los productos",
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            $responseBody = $response->getBody();
            $productsIFood = json_decode($responseBody, true);
            $count = isset($productsIFood["skus"]) ? count($productsIFood["skus"]) : 0;
            Logging::printLogFile(
                "Se encontraron " . $count . " productos de esta categoría",
                self::$channelLog
            );

            return ([
                "status" => 1,
                "data" => $productsIFood
            ]);
        }
    }

    /**
     * Obtener todos los modificadores de un producto
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $ifoodStoreId        Id de la tienda de iFood
     * @param string $ifoodProductId      Id del producto de iFood
     *
     * @return void
     *
     */
    public static function getModifiersProduct($token, $storeName, $iFoodStoreId, $ifoodProductId)
    {
        Logging::printLogFile(
            "Obteniendo los modificadores del producto: " . $ifoodProductId,
            self::$channelLog
        );

        $response = self::$browser->get(
            self::$baseUrl . 'v1.0/merchants/' . $iFoodStoreId . '/skus/' . $ifoodProductId . '/option_groups',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            Logging::printLogFile(
                "No se pudo obtener los modificadores",
                self::$channelLog
            );
            return ([
                "status" => 0,
                "data" => null
            ]);
        } else {
            $responseBody = $response->getBody();
            $modifiersIFood = json_decode($responseBody, true);
            Logging::printLogFile(
                "Se encontraron " . count($modifiersIFood) . " modificadores de este producto",
                self::$channelLog
            );

            return ([
                "status" => 1,
                "data" => $modifiersIFood
            ]);
        }
    }

    /**
     * Actualizar categoría
     *
     * Actualizar una categoría en iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $categoryData        Data de la categoría a subir a iFood
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function updateCategory($token, $storeName, $categoryData)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Actualizando categoría: " . $categoryData["externalCode"],
            self::$channelLog
        );

        $client = new Client();
        $response = $client->request(
            'PUT',
            self::$baseUrl . 'v1.0/categories',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ],
                'json' => $categoryData
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al actualizar la categoría en iFood",
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al actualizar la categoría en iFood: " . $categoryData["externalCode"] . "\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Categoría actualizada con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Actualizar grupo de modificador
     *
     * Actualiza un grupo de modificador en iFood
     *
     * @param string $token                    Token de iFood para usar en los endpoints
     * @param string $storeName                Nombre de la tienda
     * @param string $modifierGroupData        Data del grupo de modificador a actualizar a iFood
     * @param string $modifierGroupData        Id del grupo de modificador a actualizar a iFood
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function updateModifierGroup($token, $storeName, $modifierGroupData, $modifierGroupId)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Actualizando grupo de modificador: " . $modifierGroupId,
            self::$channelLog
        );

        $client = new Client();
        $response = $client->request(
            'PUT',
            self::$baseUrl . 'v1.0/option-groups/' . $modifierGroupId,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ],
                'json' => $modifierGroupData
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al actualizar el grupo de modificadores en iFood",
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al actualizar el grupo de modificadores: " . $modifierGroupId . "\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Grupo de modificadores actualizado con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }

    /**
     * Actualizar un item
     *
     * Actualiza un item en iFood
     *
     * @param string $token               Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId        Id de la tienda de iFood
     * @param string $storeName           Nombre de la tienda
     * @param string $itemData            Data del item a actualizar a iFood
     * @param string $isModifier          Indica si el item a crear es modificador
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function updateItem($token, $ifoodStoreId, $storeName, $itemData)
    {
        $status = 0; // 0: Error, 1: Éxito

        $dataJSON = json_decode($itemData[0]["contents"]);
        Logging::printLogFile(
            "Actualizando item: " . $dataJSON->externalCode,
            self::$channelLog
        );

        try {
            $client = new Client();
            $response = $client->request(
                'PATCH',
                self::$baseUrl . 'v1.0/skus/' . $dataJSON->externalCode,
                [
                    'multipart' => $itemData,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token
                    ],
                    'http_errors' => false
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $responseBody = $response->getBody();
                Logging::printLogFile(
                    "Error al actualizar el item en iFood",
                    self::$channelLog
                );
                Logging::printLogFile(
                    $responseBody,
                    self::$channelLog
                );
                $slackMessage = "Error al actualizar el item: " . $dataJSON->externalCode . "\n" .
                    "Tienda: " . $storeName . "\n" .
                    "Consulte con el desarrollador para ver más detalles";
                Logging::sendSlackMessage(
                    self::$channelSlackDev,
                    $slackMessage
                );
            } else {
                Logging::printLogFile(
                    "Item actualizado con éxito",
                    self::$channelLog
                );
                $status = 1;
            }
            
            return ([
                "status" => $status
            ]);
        } catch (\Exception $e) {
            Logging::printLogFile(
                "Error al actualizar el item en iFood, para el store: " . $storeName,
                self::$channelLog,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $itemData
            );
            $slackMessage = "Error al actualizar el item en iFood\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
            return ([
                "status" => 0
            ]);
        }
    }

    /**
     * Desvincula grupo modificador a un item
     *
     * Desvincula un grupo modificador a un item en iFood
     *
     * @param string $token                    Token de iFood para usar en los endpoints
     * @param string $ifoodStoreId             Id de la tienda de iFood
     * @param string $storeName                Nombre de la tienda
     * @param string $productId                Product id para al que se le hará el link
     * @param string $data                     Data para hacer el link
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public static function unlinkModifierGroupToItem($token, $ifoodStoreId, $storeName, $productId, $data)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Desvinculando grupo modificador del producto" . $productId,
            self::$channelLog
        );

        $jsonObject = json_encode($data, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        $response = self::$browser->post(
            self::$baseUrl . 'v1.0/skus/'. $productId . '/option-groups:unlink',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        if ($response->getStatusCode() !== 201 && $response->getStatusCode() !== 409) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "Error al desvincular el grupo de modificadores al producto en iFood: " . $storeName,
                self::$channelLog
            );
            Logging::printLogFile(
                "Data Unlink: " . $jsonObject,
                self::$channelLog
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLog
            );
            $slackMessage = "Error al desvincular el grupo de modificadores al producto en iFood\n" .
                "Tienda: " . $storeName . "\n" .
                "Consulte con el desarrollador para ver más detalles";
            Logging::sendSlackMessage(
                self::$channelSlackDev,
                $slackMessage
            );
        } else {
            Logging::printLogFile(
                "Grupo de modificadores desvinculado del producto con éxito",
                self::$channelLog
            );
            $status = 1;
        }
        
        return ([
            "status" => $status
        ]);
    }
}
