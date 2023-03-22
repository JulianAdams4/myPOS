<?php

namespace App\Traits\Logs;

use Log;

use App\Store;

use Buzz\Browser;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Buzz\Client\FileGetContents;
use Buzz\Message\FormRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

trait Logging
{
    public static function logError($message, $eMessage, $eFile, $eLine, $launchBy)
    {
        Log::error($message);
        Log::error($eMessage);
        Log::error("Archivo");
        Log::error($eFile);
        Log::error("Línea");
        Log::error($eLine);
        Log::error("Provocado por");
        Log::error(json_encode($launchBy));
        if (config('app.slack_env') != null) {
            $slackMessage =
            $message . "\n" .
            "Archivo" . "\n" .
            $eFile . "\n" .
            "Línea" . "\n" .
            $eLine;
            Log::channel('slack')->alert($slackMessage);
            $slackMessage2 =
                "Provocado por" . "\n" .
                json_encode($launchBy);
            Log::channel('slack')->alert($slackMessage2);
        }
        Log::error("----------------------------------------------------------------------------");
    }

    public static function simpleLogError($message, $launchBy)
    {
        Log::error($message);
        Log::error("Provocado por");
        Log::error(json_encode($launchBy));
        if (config('app.slack_env') != null) {
            Log::channel('slack')->alert($message);
            $slackMessage2 =
                "Provocado por" . "\n" .
                json_encode($launchBy);
            Log::channel('slack')->alert($slackMessage2);
        }
    }

    // Imprime los logs dependiendo del caso y manda el mensaje a slack
    public static function logIntegration(
        $message,
        $type,
        $eMessage = null,
        $eFile = null,
        $eLine = null,
        $launchBy = null,
        $channel = null,
        $slackMessage = null
    ) {
        if ($type == "info") {
            // Sólo es un log normal
            Log::channel('integration_logs')
                ->info($message);
        } else {
            // Log de error imprimiendo todos los detalles
            Log::channel('integration_logs')
                ->error($message);
            if ($eMessage != null && $eFile != null && $eLine != null
                && $launchBy != null
            ) {
                Log::channel('integration_logs')
                    ->error("Mensaje de error");
                Log::channel('integration_logs')
                    ->error($eMessage);
                Log::channel('integration_logs')
                    ->error("Archivo");
                Log::channel('integration_logs')
                    ->error($eFile);
                Log::channel('integration_logs')
                    ->error("Línea");
                Log::channel('integration_logs')
                    ->error($eLine);
                Log::channel('integration_logs')
                    ->error("Provocado por");
                Log::channel('integration_logs')
                    ->error(json_encode($launchBy));
            }
        }
    }

    // Guarda los logs
    public static function printLogFile(
        $message,
        $channel = "daily",
        $eMessage = null,
        $eFile = null,
        $eLine = null,
        $launchBy = null
    ) {
        Log::channel($channel)
                ->info($message);
        if ($eMessage != null && $eFile != null && $eLine != null
            && $launchBy != null
        ) {
            Log::channel($channel)
                ->error("Mensaje de error");
            Log::channel($channel)
                ->error($eMessage);
            Log::channel($channel)
                ->error("Archivo");
            Log::channel($channel)
                ->error($eFile);
            Log::channel($channel)
                ->error("Línea");
            Log::channel($channel)
                ->error($eLine);
            Log::channel($channel)
                ->error("Provocado por");
            Log::channel($channel)
                ->error(json_encode($launchBy));
            Log::channel($channel)
                ->info("");
        }
    }

    // Imprime los logs dependiendo del caso y manda el mensaje a slack
    public static function getSlackChannel($storeId)
    {
        $store = Store::where('id', $storeId)->first();
        $channel;
        switch ($store->country_code) {
            case "CO":
                $channel = "#errores_integraciones_co_";
                break;
            case "EC":
                $channel = "#errores_integraciones_ec";
                break;
            case "MX":
                $channel = "#errores_integraciones_mx";
                break;
            default:
                $channel = "#laravel_logs";
                break;
        }
        return $channel;
    }

    // Imprime los logs dependiendo del caso y manda el mensaje a slack
    public static function sendSlackMessage(
        $channel = null,
        $slackMessage = null,
        $isUserBot = false
    ) {
        $baseUrl = !$isUserBot ? config('app.slack_env') : config('app.slack_base_url_api')."/chat.postMessage";
        // Mandando mensaje de slack si es un error conocido
        if(!$isUserBot){
            if ($slackMessage != null && $channel != null && $baseUrl != null) { 
                $client = new FileGetContents(new Psr17Factory());
                $browser = new Browser($client, new Psr17Factory());
                $payload = [
                    "channel" => $channel,
                    "username" => "mypos_ bot",
                    "text" => $slackMessage
                ];
                $response = $browser->post(
                    $baseUrl,
                    [
                        'User-Agent' => 'Buzz',
                        'Content-Type' => 'application/json'
                    ],
                    json_encode($payload, JSON_FORCE_OBJECT)
                );
            }
        }else if($isUserBot){
            if ($slackMessage != null && $channel != null && $baseUrl != null) {         
                $client = new Client();
                $response = $client->request('POST', $baseUrl, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        "channel" => $channel,
                        "token" => config('app.slack_botuser_token'),
                        "text" => $slackMessage
                    ],
                    'http_errors' => true,
                ]);
            }
        }
    }

    public static function getLogErrorId(){
        return (string) Carbon::now()->timestamp."_".bin2hex(random_bytes(5)).bin2hex(random_bytes(5));
    }
}
