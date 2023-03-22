<?php
namespace App\Jobs\Integrations\Siigo;

use Log;
use App\Store;
use App\Invoice;
use App\Employee;
use Carbon\Carbon;
use App\StoreConfig;
use GuzzleHttp\Psr7;
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

/**
 * Descripción: Esta clase recibe tres parámetros por medio del constructor
 * @param int cashier si es diferente de null la sincronización se hará para todas las facturas de la caja
 * @param object invoice si es diferetne de null la sincronización se hará únicamente para la factura indicada
 * @param object store objeto de la clase App\Store
 * 
 * @return void
 */
class SiigoSaveInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $cashier;
    public $invoice;
    public $store;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($cashier = null, $invoice = null, Store $store)
    {
        $this->cashier = $cashier;
        $this->invoice = $invoice;
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

        if(!$this->cashier == null){
            $siigoController->syncCashier($this->cashier, $this->store);
        }elseif (!$this->invoice == null) {
            $siigoController->syncNewInvoice($this->invoice, $this->store);
        }
    }
}
