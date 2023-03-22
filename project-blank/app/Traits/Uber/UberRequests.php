<?php

namespace App\Traits\Uber;

// Libraries
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;

// Models
use App\StoreIntegrationId;

// Helpers
use App\Traits\Logs\Logging;

trait UberRequests
{

    private static $channelLogUR = null;
    private static $channelSlackDevUR = null;
    private static $channelLogMenuUR = 'uber_menu_logs';
    private static $channelLogNormalUR = 'uber_logs';
    private static $baseUrlUR = null;
    private static $client = null;
    private static $browserUR = null;

    public static function initVarsUberRequests($channel, $slack, $baseUrlUR, $browserUR)
    {
        self::$channelLogUR = $channel;
        self::$channelSlackDevUR = $slack;
        self::$baseUrlUR = $baseUrlUR;
        self::$browserUR = $browserUR;
    }

    /**
     *
     * Obtiene los detalles de una orden dentro de Uber
     *
     * @param string $token               Token de Uber para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $storeId             Id de la tienda
     * @param string $externalOrderId     Id propio de Uber para la orden
     *
     * @return array Estado del requerimiento
     *
     */
    public static function getOrderDetails($token, $storeName, $storeId, $externalOrderId)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Obteniendo detalle de la orden para la tienda: " . $storeName,
            self::$channelLogUR
        );

        $response = self::$browserUR->get(
            self::$baseUrlUR . 'v2/eats/order/'. $externalOrderId,
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "StatusCode: " . $response->getStatusCode() . " --- Body: " . $response->getBody()->__toString(),
                self::$channelLogUR
            );
            Logging::printLogFile(
                "No pudo obtener los detalles de la orden para la tienda: " . $storeName,
                self::$channelLogUR
            );
            $slackMessage = "Error al obtener los detalles de la orden\n" .
                "StatusCode: " . $response->getStatusCode() . " -- Body: " . $response->getBody()->__toString() . "\n" .
                "UberOrderId: " . $externalOrderId . "\n" .
                "Tienda: " . $storeName;
            Logging::sendSlackMessage(
                self::$channelSlackDevUR,
                $slackMessage
            );
        } else {
            $status = 1;
            $responseBody = $response->getBody()->__toString();
            $bodyJSON = json_decode($responseBody, true);
            Logging::printLogFile(
                "JSONOrder: " . json_encode($bodyJSON),
                self::$channelLogUR
            );
            Logging::printLogFile(
                "OrderId: " . $bodyJSON["display_id"] . " --- Customer: " . $bodyJSON["eater"]["first_name"],
                self::$channelLogUR
            );
            $data = $responseBody;
        }

        return ([
            "status" => $status,
            "data" => $data
        ]);
    }

    /**
     * Informar que la orden se creó correctamente en myPOS
     *
     * @param string $token               Token de Uber para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     Uber id de la orden
     *
     * @return void
     *
     */
    public static function sendConfirmationEvent($token, $storeName, $externalOrderId)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Enviando que la orden fue creada correctamente, para la tienda: " . $storeName,
            self::$channelLogUR
        );

        $response = self::$browserUR->post(
            self::$baseUrlUR . 'v1/eats/orders/'. $externalOrderId .'/accept_pos_order',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            '{ "reason": "Orden creada en myPOS" }'
        );

        if ($response->getStatusCode() !== 204) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "StatusCode: " . $response->getStatusCode() . " --- Body: " . $response->getBody()->__toString(),
                self::$channelLogUR
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de aceptación de la orden: " . $externalOrderId,
                self::$channelLogUR
            );

            $slackMessage = "Error al enviar la aceptación de la orden de Uber\n" .
                "StatusCode: " . $response->getStatusCode() . " -- Body: " . $response->getBody()->__toString() . "\n" .
                "UberOrderId: " . $externalOrderId . "\n" .
                "Tienda: " . $storeName;
            Logging::sendSlackMessage(
                self::$channelSlackDevUR,
                $slackMessage
            );
        } else {
            $status = 1;
            Logging::printLogFile(
                "Orden aceptada correctamente: " . $externalOrderId,
                self::$channelLogUR
            );
        }

        return ([
            "status" => $status,
            "data" => null
        ]);
    }

    /**
     * Informar que la orden se rechazó dentro de myPOS
     *
     * @param string $token               Token de Uber para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     Uber id de la orden
     * @param string $details             Detalles con el motivo de rechazo de la orden
     *
     * @return void
     *
     */
    public static function sendRejectionEvent($token, $storeName, $externalOrderId, $details)
    {
        $status = 0; // 0: Error, 1: Éxito

        Logging::printLogFile(
            "Enviando que la orden fue rechazada, para la tienda: " . $storeName,
            self::$channelLogUR
        );

        Logging::printLogFile(
            "Con el detalle: " . $details,
            self::$channelLogUR
        );

        $response = self::$browserUR->post(
            self::$baseUrlUR . 'v1/eats/orders/'. $externalOrderId .'/deny_pos_order',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $details
        );

        if ($response->getStatusCode() !== 204) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "StatusCode: " . $response->getStatusCode() . " --- Body: " . $response->getBody()->__toString(),
                self::$channelLogUR
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de rechazo de la orden: " . $externalOrderId,
                self::$channelLogUR
            );
            
            $slackMessage = "Error al enviar el rechazo de la orden de Uber\n" .
                "StatusCode: " . $response->getStatusCode() . " -- Body: " . $response->getBody()->__toString() . "\n" .
                "UberOrderId: " . $externalOrderId . "\n" .
                "Tienda: " . $storeName;
            Logging::sendSlackMessage(
                self::$channelSlackDevUR,
                $slackMessage
            );
        } else {
            $status = 1;
            Logging::printLogFile(
                "Orden rechazada correctamente: " . $externalOrderId,
                self::$channelLogUR
            );
        }
        return ([
            "status" => $status,
            "data" => null
        ]);
    }
     /**
     * Informar que la orden se rechazó dentro de myPOS
     *
     * @param string $token               Token de Uber para usar en los endpoints
     * @param string $storeName           Nombre de la tienda
     * @param string $externalOrderId     Uber id de la orden
     * @param string $details             Detalles con el motivo de rechazo de la orden
     *
     * @return void
     *
     */
    public static function sendCancelationEvent($token, $storeName, $externalOrderId, $details)
    {
        $status = 0; // 0: Error, 1: Éxito
        Logging::printLogFile(
            "Enviando que la orden fue cancelada, para la tienda: " . $storeName,
            self::$channelLogUR
        );

        Logging::printLogFile(
            "Con el detalle: " . $details,
            self::$channelLogUR
        );

        $response = self::$browserUR->post(
            self::$baseUrlUR . 'v1/eats/orders/'. $externalOrderId .'/cancel',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            $details
        );

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody();
            Logging::printLogFile(
                "StatusCode: " . $response->getStatusCode() . " --- Body: " . $response->getBody()->__toString(),
                self::$channelLogUR
            );
            Logging::printLogFile(
                "No se pudo enviar el evento de cancelación de la orden: " . $externalOrderId,
                self::$channelLogUR
            );
            
            $slackMessage = "Error al enviar la cancelación de la orden de Uber\n" .
                "StatusCode: " . $response->getStatusCode() . " -- Body: " . $response->getBody()->__toString() . "\n" .
                "UberOrderId: " . $externalOrderId . "\n" .
                "Tienda: " . $storeName;
            Logging::sendSlackMessage(
                self::$channelSlackDevUR,
                $slackMessage
            );
        } else {
            $status = 1;
            Logging::printLogFile(
                "Orden cancelada correctamente: " . $externalOrderId,
                self::$channelLogUR
            );
        }
        return ([
            "status" => $status,
            "data" => null
        ]);
    }

    public static function updateItem($token, $eatsStoreId, $itemId, $newPrice)
    {
        Logging::printLogFile(
            'Actualizando itemId: ' . $itemId . ' con el precio' . ($newPrice / 100),
            self::$channelLogMenuUR
        );

        Logging::printLogFile(
            'Para la tienda: ' . $eatsStoreId,
            self::$channelLogMenuUR
        );

        $baseUrl = config('app.eats_url_api');

        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false
            ];
        }

        $client = new Client();
        $response = $client->request(
            'POST',
            $baseUrl . 'v2/eats/stores/' . $eatsStoreId . '/menus/items/' . $itemId,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ],
                'json' => [
                    'price_info' => [
                        'price' => $newPrice,
                        'overrides' => []
                    ]
                ]
            ]
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 204) {
            Logging::printLogFile(
                'Error al actualizar el item a Uber',
                self::$channelLogMenuUR
            );
            Logging::printLogFile(
                $response->getStatusCode(),
                self::$channelLogMenuUR
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLogMenuUR
            );
            return [
                'message' => 'No se pudo actualizar el item en Uber.',
                'success' => false
            ];
        } else {
            Logging::printLogFile(
                'Item actualizado con éxito.',
                self::$channelLogMenuUR
            );
            return [
                'message' => 'Item actualizado exitosamente.',
                'success' => true
            ];
        }
    }

    public static function getToken($storeId)
    {
        Logging::printLogFile(
            'Obteniendo token para el store: ' . $storeId,
            self::$channelLogNormalUR
        );

        $clientID = config('app.eats_client_id_v2');
        $clientSecret = config('app.eats_client_secret_v2');
        $baseUrl = config('app.eats_login_api');
        $scope = 'eats.order eats.store eats.store.orders.read eats.store.status.write eats.store.orders.cancel';

        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false,
                'data' => null
            ];
        }

        $client = new Client();
        $data = [
            [
                'name' => 'client_id',
                'contents' => $clientID
            ],
            [
                'name' => 'client_secret',
                'contents' => $clientSecret
            ],
            [
                'name' => 'grant_type',
                'contents' => 'client_credentials'
            ],
            [
                'name' => 'scope',
                'contents' => $scope
            ]
        ];
        $response = $client->request(
            'POST',
            $baseUrl,
            [
                'headers' => [],
                'multipart' => $data,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                'Error al obtener el token de Uber',
                self::$channelLogNormalUR
            );
            Logging::printLogFile(
                $response->getStatusCode(),
                self::$channelLogNormalUR
            );
            Logging::printLogFile(
                $responseBody,
                self::$channelLogNormalUR
            );
            return [
                'message' => 'No se pudo obtener el token de Uber.',
                'success' => false,
                'data' => null
            ];
        } else {
            Logging::printLogFile(
                'Token obtenido con éxito.',
                self::$channelLogNormalUR
            );
            return [
                'message' => 'Item actualizado exitosamente.',
                'success' => true,
                'data' => json_decode($responseBody, true)
            ];
        }
    }
}
