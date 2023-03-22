<?php

namespace App\Jobs\Datil;

use Log;
use App\Store;
use Exception;
use App\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Traits\ElectronicBillingIntegrations;

class IssueInvoiceDatil implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ElectronicBillingIntegrations;

    public $store;
    public $invoice;
    public $issuanceType;

    //public $tries = 5;
    //public $timeout = 90;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Store $store, Invoice $invoice, int $issuanceType)
    {
        $this->store = $store;
        $this->invoice = $invoice;
        $this->issuanceType = $issuanceType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
      $bundle = $this->createDatilBundle($this->store, $this->invoice, $this->issuanceType);
      $this->postElectronicBillingDatil($this->store,$bundle);
    }

}
