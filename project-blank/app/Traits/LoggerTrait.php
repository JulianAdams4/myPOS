<?php

namespace App\Traits;

use Log;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

trait LoggerTrait
{
    public function saveLog($auth, $url, $body)
    {
        if (config('app.env') != 'production') {
            return;
        }

        $endpoint = 'https://...';
        $client = new \GuzzleHttp\Client();
        try {
            $payload = [
                'auth' => $auth,
                'url' => $url,
                'payload' => $body,
            ];
            $headers = [
                'Content-Type' => 'application/json'
            ];
            $request = new Request('POST', $endpoint, $headers, json_encode($payload));
            $promise = $client->sendAsync($request);
            $promise->then(
                function ($response) {
                    return $response->getBody();
                },
                function ($exception) {
                    return $exception->getMessage();
                }
            );
            $response = $promise->wait();
        } catch (\Exception $e) {
            Log::info('error logger');
        }
    }

    public function saveInteractionLog($action, $model, $user_id, $model_id, $model_data)
    {
        if (config('app.env') != 'production') {
            return;
        }

        $client = new \GuzzleHttp\Client();
        $endpoint = 'https://...';
        try {
            $encoded = base64_encode(
                config('app.log_action_username').':'.config('app.log_action_password')
            );
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $encoded
            ];
            $body = json_encode([
                "action" => $action,
                "model" => $model,
                "user_id" => $user_id,
                "model_id" => $model_id,
                "model_data" => $model_data
            ]);
            $request = new Request('POST', $endpoint, $headers, $body);
            $promise = $client->sendAsync($request);
            $promise->then(
                function ($response) {
                    return $response->getBody();
                },
                function ($exception) {
                    return $exception->getMessage();
                }
            );
            $response = $promise->wait();
        } catch (\Exception $e) {
            Log::info('[Failed to Log Action => '.$action.' in '.$model.' with id: '.$model_id.']');
            Log::info($e);
        }
    }
}
