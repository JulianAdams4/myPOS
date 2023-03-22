<?php

namespace App\Traits\Aloha;

// Libraries
use Log;

// Models
use App\StoreIntegrationId;

// Helpers
use App\Traits\LoggingHelper;

trait AlohaRequests
{
    use LoggingHelper;

    public $channelLog = null;
    public $channelSlackDev = null;
    public $baseUrl = null;
    public $client = null;
    public $browser = null;

    public function initVarsAlohaRequests($channel, $slack, $baseUrl, $browser)
    {
        $this->channelLog = $channel;
        $this->channelSlackDev = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;
    }

    /**
     * Obtener Menú de Aloha
     *
     * Obtiene la información del menú de Aloha por medio del endpoint ofrecido por PCnub.
     *
     * @param string $token               Token de Aloha para usar en los endpoints
     * @param string $storeIntregrationId StoreIntegrationId con la data para los endpoints de PCnub
     * @param string $storeName           Nombre de la tienda
     *
     * @return array Información con el estado del request y sus detalles
     *
     */
    public function getMenuStore($token, StoreIntegrationId $storeIntregrationId, $storeName,$excelMenu)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        $this->printLogFile(
            "Obteniendo menú de Aloha para la tienda: " . $storeName,
            $this->channelLog
        );
        $bodyParams = [
            "url" => $this->baseUrl . "menu/"  . $token . "/" . $storeIntregrationId->external_store_id . "/".
            $storeIntregrationId->restaurant_chain_external_id . "/" .
            $storeIntregrationId->restaurant_branch_external_id,
            "menu" => $excelMenu,
        ];
        $response = $this->browser->post(
            "https://...",
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            json_encode($bodyParams)
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 200) {
            $this->printLogFile(
                "Error al obtener el menú de Aloha para la tienda: " . $storeName,
                $this->channelLog
            );
            $this->printLogFile(
                $responseBody,
                $this->channelLog
            );
            $slackMessage = "Error al obtener el menú de Aloha\n" .
                "Tienda: " . $storeName . "\n";
            $this->sendSlackMessage(
                $this->channelSlackDev,
                $slackMessage
            );
        } else {
            $this->printLogFile(
                "Se obtuvo el menú de Aloha. Procesando....",
                $this->channelLog
            );
            $status = 1;
            $data = json_decode($responseBody, true);
        }
        
        return ([
            "status" => $status,
            "data" => $data
        ]);
    }
}
