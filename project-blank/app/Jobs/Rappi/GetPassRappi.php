<?php

namespace App\Jobs\Rappi;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Traits\RappiIntegration;
use App\Store;
use Log;

class GetPassRappi implements ShouldQueue{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RappiIntegration;

    public $store;

    public function __construct(Store $store){
        $this->store = $store;
    }

    public function handle(){
        $this->getPassword($this->store);
    }

}