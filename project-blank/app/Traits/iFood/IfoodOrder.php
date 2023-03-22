<?php

namespace App\Traits\iFood;

// Libraries
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;

// Models
use App\Store;
use App\AvailableMyposIntegration;

// Helpers
use App\Traits\Integrations\IntegrationsHelper;

// Jobs
use App\Jobs\IFood\CreateIfoodOrderJob;
use App\Jobs\MenuMypos\EmptyJob;

trait IfoodOrder
{
    use IntegrationsHelper;

    public static function processOrders($newOrders, $canceledOrders, $token)
    {
        $createOrderJobs = [];
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());

        $iFoodIntegration = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_IFOOD)
            ->first();
        if (is_null($iFoodIntegration)) {
            return;
        }

        foreach ($newOrders as $newOrder) {
            array_push(
                $createOrderJobs,
                (new CreateIfoodOrderJob(
                    $newOrder,
                    $token,
                    $iFoodIntegration,
                    "ifood_orders_logs",
                    "#integration_logs_details",
                    config('app.ifood_url_api'),
                    $browser
                ))->delay(2)
            );
        }
        EmptyJob::withChain($createOrderJobs)->dispatch();
    }
}
