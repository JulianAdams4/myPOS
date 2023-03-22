<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */
    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],

        'backoffice' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'backoffice',
            'retry_after' => 90,
        ],
        'rappi_1' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappi',
            'retry_after' => 10,
        ],
        'rappi_2' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappi',
            'retry_after' => 10,
        ],
        'rappiv2' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv2',
            'retry_after' => 10,
        ],
        'rappiv3' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv3',
            'retry_after' => 10,
        ],
        'rappiv4' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv4',
            'retry_after' => 10,
        ],
	'rappiv5' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv5',
            'retry_after' => 10,
        ],
	'rappiv6' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv6',
            'retry_after' => 10,
        ],
	'rappiv7' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv7',
            'retry_after' => 10,
        ],
	'rappiv8' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv8',
            'retry_after' => 10,
        ],
	'rappiv9' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv9',
            'retry_after' => 10,
        ],
	'rappiv10' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv10',
            'retry_after' => 10,
        ],
'rappiv11' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv11',
            'retry_after' => 10,
        ],
'rappiv12' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv12',
            'retry_after' => 10,
        ],
'rappiv13' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv13',
            'retry_after' => 10,
        ],
'rappiv14' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv14',
            'retry_after' => 10,
        ],
'rappiv15' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv15',
            'retry_after' => 10,
        ],
'rappiv16' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv16',
            'retry_after' => 10,
        ],
'rappiv17' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv17',
            'retry_after' => 10,
        ],
'rappiv18' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv18',
            'retry_after' => 10,
        ],
'rappiv19' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv19',
            'retry_after' => 10,
        ],
'rappiv20' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv20',
            'retry_after' => 10,
        ],
	'rappiv21' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv21',
            'retry_after' => 10,
        ],
	'rappiv22' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv22',
            'retry_after' => 10,
        ],
'rappiv23' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv23',
            'retry_after' => 10,
        ],
'rappiv24' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv24',
            'retry_after' => 10,
        ],
	'rappiv25' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'rappiv25',
            'retry_after' => 10,
        ],
	'mercadopago' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'mercadopago',
            'retry_after' => 10,
        ],
        'ifood_menu' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'ifood_menu',
            'retry_after' => 10,
        ],
        'printer' => [
            'driver' => 'redis',
            'table' => 'jobs',
	    'queue' => 'printer',
	    'connection' => 'pubsub',
            'retry_after' => 10,
        ],
        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('SQS_KEY', 'your-public-key'),
            'secret' => env('SQS_SECRET', 'your-secret-key'),
            'prefix' => env('SQS_PREFIX', 'https://...'),
            'queue' => env('SQS_QUEUE', 'your-queue-name'),
            'region' => env('SQS_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 60,
            'block_for' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
