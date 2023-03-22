<?php

namespace App\Traits\DidiFood;

// Libraries
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Browser;
use Carbon\Carbon;
use Log;

// Models
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;

// Helpers
use App\Traits\LoggingHelper;

trait DidiRequests
{
    use LoggingHelper;

    public $baseUrl = null;
    public $client = null;
    public $browser = null;
    public $channelLog = null;
    public $channelSlackDev = null;

    public function __construct()
    {
        $this->baseUrl = config('app.didi_url_api');
        $this->client = new FileGetContents(new Psr17Factory());
        $this->browser = new Browser($this->client, new Psr17Factory());
        $this->channelLog = "didi_logs";
        $this->channelSlackDev = "#integration_logs_details";
    }

    public function initVarsDidiRequests()
    {
        $this->baseUrl = config('app.didi_url_api');
        $this->client = new FileGetContents(new Psr17Factory());
        $this->browser = new Browser($this->client, new Psr17Factory());
        $this->channelLog = "didi_logs";
        $this->channelSlackDev = "#integration_logs_details";
    }

    /**
     * Información de la orden de Didi
     *
     * Obtiene la información de la orden usando el endpoint ofrecido por didi.
     *
     * @param string $token   Token de didi para usar en los endpoints
     * @param string $orderId Didi order id de la que se quiere obtener los detalles
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public function getOrderDetails($token, $orderId, $storeName)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        $this->printLogFile(
            "Obteniendo información de didi para el orderId: " . $orderId,
            $this->channelLog
        );

        $bodyParams = [
            "auth_token" => $token,
            "order_id" => $orderId
        ];
        $response = $this->browser->post(
            $this->baseUrl . 'v1/order/order/detail',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );

        $responseBody = $response->getBody();
        $responseObject = json_decode($responseBody);
        $this->printLogFile(
            "Response api detalles orden: " . $responseBody,
            $this->channelLog
        );
        $result = $this->processResponse($responseObject);

        if ($result['hasError']) {
            $this->printLogFile(
                "Error al obtener la información de una orden de Didi: " . $result['message'],
                $this->channelLog
            );
            $slackMessage = "Error al obtener la información de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $result['message'];
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $result['message'];
        } elseif ($responseObject->errmsg != "ok") {
            $this->printLogFile(
                "Error al obtener la información de una orden de Didi: " . $responseObject->errmsg,
                $this->channelLog
            );
            $slackMessage = "Error desconocido al obtener la información de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $responseObject->errmsg;
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $responseObject->errmsg;
        } else {
            $status = 1;
            $data = $responseObject->data;
        }
        
        return ([
            "status" => $status,
            "data" => $data
        ]);
    }

    /**
     * Acepta/confirma de la orden en Didi
     *
     * Acepta la nueva orden en Didi para seguir con el flujo normal.
     *
     * @param string $token   Token de didi para usar en los endpoints
     * @param string $orderId Didi order id de la que se quiere obtener los detalles
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public function confirmOrder($token, $orderId, $storeName)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        $this->printLogFile(
            "Enviando confirmación para el orderId: " . $orderId,
            $this->channelLog
        );

        $bodyParams = [
            "auth_token" => $token,
            "order_id" => $orderId
        ];
        $response = $this->browser->post(
            $this->baseUrl . 'v1/order/order/confirm',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );

        $responseBody = $response->getBody();
        $responseObject = json_decode($responseBody);
        $this->printLogFile(
            "Response confirmación de la orden: " . $responseBody,
            $this->channelLog
        );
        $result = $this->processResponse($responseObject);

        if ($result['hasError']) {
            $slackMessage = "Error al enviar la confirmación de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $result['message'];
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $result['message'];
        } elseif ($responseObject->errmsg != "ok") {
            $slackMessage = "Error desconocido al enviar la confirmación de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $responseObject->errmsg;
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $responseObject->errmsg;
        } else {
            $this->printLogFile(
                "Aceptación de la orden enviado exitosamente a Didi Food",
                $this->channelLog
            );
            $status = 1;
            $data = $responseObject->data;
        }
        
        return ([
            "status" => $status,
            "data" => $data
        ]);
    }
    public function rejectDidiOrder($token, $orderId, $storeName)
    {

        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        $this->printLogFile(
            "Enviando reachazo para el orderId: " . $orderId,
            $this->channelLog
        );

        $bodyParams = [
            "auth_token" => $token,
            "order_id" => $orderId,
            "reason_id"=>1080
        ];
        $response = $this->browser->post(
            $this->baseUrl . 'v1/order/order/cancel',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );

        $responseBody = $response->getBody();
        $responseObject = json_decode($responseBody);
        $this->printLogFile(
            "Response rechazo de la orden: " . $responseBody,
            $this->channelLog
        );
        $result = $this->processResponse($responseObject);

        if ($result['hasError']) {
            $slackMessage = "Error al enviar el rechazo de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $result['message'];
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $result['message'];
        } elseif ($responseObject->errmsg != "ok") {
            $slackMessage = "Error desconocido al enviar el rechazo de una orden de Didi\n" .
                "Tienda: " . $storeName . "\n" .
                "OrderId: " . $orderId . "\n" .
                "Error: " . $responseObject->errmsg;
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $responseObject->errmsg;
        } else {
            $this->printLogFile(
                "Rechazo de la orden enviado exitosamente a Didi Food",
                $this->channelLog
            );
            $status = 1;
            $data = $responseObject->data;
        }
        
        return ([
            "status" => $status,
            "data" => $data
        ]);
    }
    public function getDidiToken($storeId, $externalStoreId)
    {
        $integrationDidi = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_DIDI)->first();

        $integrationToken = StoreIntegrationToken::where('store_id', $storeId)
            ->where('integration_name', $integrationDidi->code_name)
            ->where('type', 'delivery')
            ->first();
        if (!is_null($integrationToken)) {
            $now = Carbon::now();
            $emitted = Carbon::parse($integrationToken->updated_at);
            $diff = $now->diffInSeconds($emitted);
            // Verificar si el token no está caducado(por lo menos de diferencia de 15 días)
            if ($diff > 1296000) {
                $resultToken2 = $this->refreshToken($externalStoreId);
                if (!$resultToken2['success']) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo hacer el refresh del token, comunicarse con los desarrolladores',
                        'token' => null
                    ];
                } else {
                    $resultToken3 = $this->getToken($externalStoreId, $integrationToken->store->name);
                    if ($resultToken3['code'] == 0) {
                        $bodyJSON = json_decode(json_encode($resultToken3['data']), true);
                        Log::info($bodyJSON);
                        $integrationToken->token = $bodyJSON['auth_token'];
                        $integrationToken->expires_in = $bodyJSON['token_expiration_time'];
                        $integrationToken->save();
                        return [
                            'success' => true,
                            'message' => 'Didi token.',
                            'token' => $integrationToken->token
                        ];
                    } else {
                        return [
                            'success' => false,
                            'message' => 'No se pudo hacer obtener el token luego de hacer el refresh.',
                            'token' => null
                        ];
                    }
                }
            } else {
                return [
                    'success' => true,
                    'message' => 'Didi token.',
                    'token' => $integrationToken->token
                ];
            }
        } else {
            $resultToken = $this->getToken($externalStoreId, 'Tienda con storeId: ' . $storeId);
            if ($resultToken['code'] != 0 && $resultToken['code'] != 10102) {
                return [
                    'success' => false,
                    'message' => 'No se pudo obtener el token nuevo de Didi',
                    'token' => null
                ];
            } elseif ($resultToken['code'] == 10102) {
                $resultToken2 = $this->refreshToken($externalStoreId);
                if (!$resultToken2['success']) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo hacer el refresh del token 2',
                        'token' => null
                    ];
                } else {
                    $resultToken3 = $this->getToken($externalStoreId, 'Tienda con storeId: ' . $storeId);
                    if ($resultToken3['code'] == 0) {
                        $bodyJSON = json_decode(json_encode($resultToken3['data']), true);
                        $integrationToken = new StoreIntegrationToken();
                        $integrationToken->store_id = $storeId;
                        $integrationToken->integration_name = $integrationDidi->code_name;
                        $integrationToken->token = $bodyJSON['auth_token'];
                        $integrationToken->expires_in = $bodyJSON['token_expiration_time'];
                        $integrationToken->type = 'delivery';
                        $integrationToken->save();
                        return [
                            'success' => true,
                            'message' => 'Didi token.',
                            'token' => $integrationToken->token
                        ];
                    } else {
                        return [
                            'success' => false,
                            'message' => $resultToken3['message'],
                            'token' => null
                        ];
                    }
                }
            } else {
                $bodyJSON = json_decode(json_encode($resultToken['data']), true);
                $integrationToken = new StoreIntegrationToken();
                $integrationToken->store_id = $storeId;
                $integrationToken->integration_name = $integrationDidi->code_name;
                $integrationToken->token = $bodyJSON['auth_token'];
                $integrationToken->expires_in = $bodyJSON['token_expiration_time'];
                $integrationToken->type = 'delivery';
                $integrationToken->save();
                return [
                    'success' => true,
                    'message' => 'Didi token.',
                    'token' => $integrationToken->token
                ];
            }
        }
    }

    /**
     *
     * Obtiene el token de Didi usado para la integración
     *
     * @param integer $storeId     id de la tienda de Didi en myPOS
     * @param string  $storeName   nombre de la tienda de Didi en myPOS
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public function getToken($storeId, $storeName)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito
        $code = null;

        $this->printLogFile(
            "Obteniendo el token para el store: " . $storeName,
            $this->channelLog
        );

        $didiAppId = config('app.didi_app_id');
        $didiAppSecret = config('app.didi_app_secret');

        $bodyParams = [
            "app_id" => $didiAppId,
            "app_secret" => $didiAppSecret,
            "app_shop_id" => $storeId
        ];
        $response = $this->browser->post(
            $this->baseUrl . 'v1/auth/authtoken/get',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );

        $responseBody = $response->getBody();
        $responseObject = json_decode($responseBody);
        $this->printLogFile(
            "Response del getToken: " . $responseBody,
            $this->channelLog
        );
        $result = $this->processResponse($responseObject);
        $code = $responseObject->errno;
        if ($result['hasError']) {
            $slackMessage = "Error al enviar el requerimiento de getToken\n" .
                "Tienda: " . $storeName . "\n" .
                "Error: " . $result['message'];
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $result['message'];
        } elseif ($responseObject->errmsg != "ok") {
            $slackMessage = "Error desconocido al enviar el requerimiento de getToken\n" .
                "Tienda: " . $storeName . "\n" .
                "Error: " . $responseObject->errmsg;
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $responseObject->errmsg;
        } else {
            $this->printLogFile(
                "Éxito: Se obtuvo el token de Didi!!!",
                $this->channelLog
            );
            $status = 1;
            $data = $responseObject->data;
        }
        
        return ([
            "status" => $status,
            "data" => $data,
            "code" => $code
        ]);
    }

    public function refreshToken($storeId)
    {
        $data = null;
        $success = false; // 0: Error, 1: Éxito
        $code = null;

        $this->printLogFile(
            'Refrescar el token de Didi desde el endpoint',
            $this->channelLog
        );

        $didiAppId = config('app.didi_app_id');
        $didiAppSecret = config('app.didi_app_secret');

        $bodyParams = [
            "app_id" => $didiAppId,
            "app_secret" => $didiAppSecret,
            "app_shop_id" => $storeId
        ];
        $response = $this->browser->post(
            $this->baseUrl . 'v1/auth/authtoken/refresh',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );

        $responseBody = $response->getBody();
        $responseObject = json_decode($responseBody);
        $this->printLogFile(
            "Response del refreshToken: " . $responseBody,
            $this->channelLog
        );
        $result = $this->processResponse($responseObject);
        $code = $responseObject->errno;

        if ($result['hasError']) {
            $slackMessage = "Error al enviar el requerimiento de refreshToken\n" .
                "Tienda: " . $storeName . "\n" .
                "Error: " . $result['message'];
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $result['message'];
        } elseif ($responseObject->errmsg != "ok") {
            $slackMessage = "Error desconocido al enviar el requerimiento de refreshToken\n" .
                "Tienda: " . $storeName . "\n" .
                "Error: " . $responseObject->errmsg;
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );

            $data = $responseObject->errmsg;
        } else {
            $this->printLogFile(
                "Éxito: Se hizo el refresh del token de Didi!!!",
                $this->channelLog
            );
            $success = true;
            $data = $responseObject->data;
        }
        
        return ([
            "success" => $success,
            "data" => $data,
            "code" => $code
        ]);
    }

    /**
     * Procesa la respuesta enviada por Didi
     *
     * Obtiene la información a partir de la respuesta enviada por didi(errno)
     *
     * @param object $response Objeto con el response de Didi
     *
     * @return array Información con el mensaje y status del request
     *
     */
    public function processResponse($response)
    {
        $hasError = true;
        $message = "";
        $errorCode = $response->errno;
        switch ($errorCode) {
            case 0:
                $hasError = false;
                $message = "Requerimiento exitoso";
                break;
            case 10002:
                $message = "Error con un parámetro del JSON";
                break;
            case 10100:
                $message = "No se pudo obtener el token para la tienda";
                break;
            case 10101:
                $message = "Esta tienda no tiene token o no es un token válido";
                break;
            case 10102:
                $message = "Token expirado";
                break;
            case 14103:
                $message = "No se pudo identificar a la aplicación";
                break;
            case 14105:
                $message = "La aplicación no existe";
                break;
            case 14106:
                $message = "El secret para la aplicación no es correcto";
                break;
            default:
                $message = "Error desconocido";
                break;
        }
        return [
            "hasError" => $hasError,
            "message" => $message
        ];
    }
}
