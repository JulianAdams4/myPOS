<?php

namespace App\Traits;

use App\FcmToken;
use FCM;
use LaravelFCM\Message\Topics;
use LaravelFCM\Message\OptionsBuilder;
use LaravelFCM\Message\PayloadDataBuilder;
use LaravelFCM\Message\PayloadNotificationBuilder;
use LaravelFCM\Message\OptionsPriorities;
use Log;

trait PushNotification
{

    public function sendIntegrationOrder($order, $provider)
    {
        $order->load('store.employees.fcmTokens');
        $fcmTokens = [];
        $employees = $order->store->employees;
        foreach ($employees as $employee) {
            $employeeTokens = $employee->fcmTokens->pluck('token')->toArray();
            foreach ($employeeTokens as $employeeToken) {
                array_push($fcmTokens, $employeeToken);
            }
        }

        $title = "Nueva Orden de ".$provider;
        $message = "Presiona aquí para imprimir comanda";
            
        $optionsBuilder = new OptionsBuilder();
        $optionsBuilder->setContentAvailable(true);
        $optionsBuilder->setTimeToLive(60);
        $optionsBuilder->setPriority(OptionsPriorities::high);
        $options = $optionsBuilder->build();

        $dataBuilder = new PayloadDataBuilder();
        $dataBuilder->addData([
            'title' => $title,
            'message' => $message,
            'action' => 'new_order_delivery',
            'id' => $order->id,
            'sound' => 'default',
        ]);
        $data = $dataBuilder->build();

        $notificationBuilder = new PayloadNotificationBuilder($title);
        $notificationBuilder->setBody($message)
                            ->setSound('default')
                            ->setTag($order->id);
        $notification = $notificationBuilder->build();

        if (count($fcmTokens) > 0) {
            $androidDownstreamResponse = FCM::sendTo($fcmTokens, $options, $notification, $data);
            $fcm_data_android = [
                'platform' => 'Android',
                'success' => $androidDownstreamResponse->numberSuccess(),
                'failure' => $androidDownstreamResponse->numberFailure(),
                'modify'  => $androidDownstreamResponse->numberModification(),
                'delete_tokens' => $androidDownstreamResponse->tokensToDelete(),
                'modify_tokens' => $androidDownstreamResponse->tokensToModify(),
                'retry_tokens'  => $androidDownstreamResponse->tokensToRetry(),
                'error_tokens'  => $androidDownstreamResponse->tokensWithError()
            ];
            Log::info(json_encode($fcm_data_android));
            foreach ($androidDownstreamResponse->tokensToDelete() as $invalidToken) {
                FcmToken::where('token', $invalidToken)->delete();
            }
        }
    }
}
