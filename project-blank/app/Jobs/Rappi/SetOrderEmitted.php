<?php

namespace App\Jobs\Rappi;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Traits\RappiIntegration;
use App\Store;
use App\StoreIntegrationToken;

class SetOrderEmitted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RappiIntegration;

    public $store;
    public $newOrder;
    public $integrationToken;

    public function __construct(Store $store, string $newOrder, StoreIntegrationToken $integrationToken)
    {
        $this->store = $store;
        $this->newOrder = $newOrder;
        $this->integrationToken = $integrationToken;
    }

    public function handle()
    {
        $this->setRappiOrderEmitted($this->store, $this->newOrder, $this->integrationToken);
    }
}
