<?php

namespace App\Jobs\Integrations\Facturama;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Http\Controllers\API\Integrations\Facturama\FacturamaController;

class FacturamaInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $invoice;
    public $store;
    public $type;
    public $facturamaId;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($type, $invoice = null, $store = null, $facturamaId = null)
    {
        $this->invoice = $invoice;
        $this->store = $store;
        $this->type = $type;
        $this->facturamaId = $facturamaId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $facturamaController = new FacturamaController();

        switch ($this->type) {
            case 'save':
                $facturamaController->createCFDI($this->invoice, $this->store);
            break;

            case 'cancel':
                $facturamaController->cancelCFDI($this->facturamaId);
            break;
        }

    }
}
