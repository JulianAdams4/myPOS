<?php
namespace App\Jobs\Integrations\Siigo;

use Log;
use App\Store;
use App\Employee;
use Carbon\Carbon;
use GuzzleHttp\Psr7;
use App\StoreConfig;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Http\Controllers\API\Integrations\Siigo\SiigoController;

class SiigoDeleteProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $product;
    public $store;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Int $product, Store $store)
    {
        $this->product = $product;
        $this->store = $store;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $siigoController = new SiigoController();
        $siigoController->deleteSingleProductByJob($this->product, $this->store);
    }
}
