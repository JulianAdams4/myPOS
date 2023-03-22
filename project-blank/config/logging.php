<?php

use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily', 'slack'],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
        ],

        'auto_cashier' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auto_cashier/logs.log'),
            'level' => 'info',
        ],

        'siigo' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/siigo/siigo.log'),
            'level' => 'info',
        ],

        'facturama' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/facturama/facturama.log'),
            'level' => 'info',
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => 'alert',
        ],

        'papertrail' => [
            'driver'  => 'monolog',
            'level' => 'debug',
            'handler' => SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => 'debug',
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => 'debug',
        ],

        'integration_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/logs.log'),
            'level' => 'debug',
        ],

        'didi_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/didi/didi.log'),
            'level' => 'debug',
        ],

        'aloha_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/aloha/aloha.log'),
            'level' => 'debug',
        ],

        'ifood_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/ifood/ifood.log'),
            'level' => 'debug',
        ],

        'ifood_orders_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/ifood/ifood_orders.log'),
            'level' => 'debug',
        ],

        'production_job_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/production/job.log'),
            'level' => 'debug',
        ],

        'uber_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/uber/uber.log'),
            'level' => 'debug',
        ],

        'mercado_pago_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/mercado_pago/mercado_pago.log'),
            'level' => 'debug',
        ],

        'uber_orders_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/uber/uber_orders.log'),
            'level' => 'debug',
        ],

        'mypos_menu_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/menu/import_log.log'),
            'level' => 'debug',
        ],

        'DiDiTokenRefresh' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/didi/didi_token_refresh.log'),
            'level' => 'debug',
        ],

        'UberTokenRefresh' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/uber/uber_token_refresh.log'),
            'level' => 'debug',
        ],

        'dynamic_pricing_enable' => [
            'driver' => 'daily',
            'path' => storage_path('logs/dynamic_pricing/enable_rule.log'),
            'level' => 'debug',
        ],
        'dynamic_pricing_disable' => [
            'driver' => 'daily',
            'path' => storage_path('logs/dynamic_pricing/disable_rule.log'),
            'level' => 'debug',
        ],

        'uber_menu_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/uber/uber_menu.log'),
        ],
        'subscription_billing' => [
            'driver' => 'daily',
            'path' => storage_path('logs/subscriptions/billing.log'),
            'level' => 'debug',
        ],
        'mely_menu_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/mely/mely_menu.log'),
            'level' => 'debug',
        ],
        'mely_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/mely/mely.log'),
            'level' => 'debug',
        ],
        'mely_orders_logs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/integration_logs/mely/mely_orders.log'),
            'level' => 'debug',
        ]
    ],

];
