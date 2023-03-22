<?php

namespace App\Http\Helpers;

use Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// @codingStandardsIgnoreLine
class QueueHelper
{

    public static function dispatchJobs($jobs)
    {
        if ($jobs == null || empty($jobs)) {
            return;
        }

        $rabbitHost = config('app.rabbitmq_host');
        $rabbitPort = config('app.rabbitmq_port');
        $rabbitUser = config('app.rabbitmq_username');
        $rabbitPswd = config('app.rabbitmq_password');
        $rabbitVhost = config('app.rabbitmq_vhost');

        $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPswd, $rabbitVhost);
        $channel = $connection->channel();
        $channel->exchange_declare('order-created', 'fanout', false, false, false);

        foreach ($jobs as $job) {
            $msg = new AMQPMessage(json_encode($job));
            $channel->batch_basic_publish($msg, 'order-created');
        }

        $channel->publish_batch();
        $channel->close();
        $connection->close();
    }
}