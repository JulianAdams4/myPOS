<?php

namespace App\Jobs\Integrations\Facturama;

use App\Order;
use Exception;
use App\Helper;
use App\Invoice;
use App\Payment;
use Carbon\Carbon;
use App\Traits\Logs\Logging;
use Illuminate\Bus\Queueable;
use App\IntegrationsPaymentMeans;
use App\AvailableMyposIntegration;
use App\InvoiceIntegrationDetails;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Http\Controllers\API\Integrations\Facturama\FacturamaController;

class GlobalInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $invoices;
    public $store;
    public $invoicesIds;
    public $request;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($invoices, $store, $invoicesIds, $request)
    {
        $this->invoices = $invoices;
        $this->store = $store;
        $this->invoicesIds = $invoicesIds;
        $this->request = $request;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // throw new Exception("algo falló");
            $facturamaController = new FacturamaController();
            $items = [];

            foreach ($this->invoices as $invoice) {
                
                $invoice = Invoice::where('id', $invoice->id)->first();
                // Trae el medio de pago que corresponde en Facturama
                $typePayment = Payment::where('order_id', $invoice->order_id)->first();
                $nameIntegration = AvailableMyposIntegration::NAME_FACTURAMA;
                $externalPaymentMean = IntegrationsPaymentMeans::where('name_integration', $nameIntegration)
                    ->where('local_payment_mean_code', $typePayment['type'])->first();

                $order = Order::where('id', $invoice->order_id)->first();
                $taxDetails = $order->taxDetails;
                $allTaxes = [];
                $sumatoryTaxes = 0;
                $sumatoryBaseTaxes = 0;

                foreach ($taxDetails as $taxDetail) {
                    $taxInfo = $taxDetail->storeTax;
                    $rateTax = $taxInfo->percentage / 100;
                    $baseTax = $invoice->undiscounted_subtotal - $invoice->discount_value;
                    $totalTax = $baseTax * $rateTax;

                    $newTax = [
                        "Name"  => $taxInfo->name,
                        "Rate"  => round($rateTax, 2, PHP_ROUND_HALF_DOWN),
                        "Total" => round($totalTax / 100, 2, PHP_ROUND_HALF_DOWN),
                        "Base"  => round($baseTax / 100, 2, PHP_ROUND_HALF_DOWN),
                        "IsRetention" => "false"
                    ];

                    $sumatoryTaxes += round($totalTax / 100, 2, PHP_ROUND_HALF_DOWN);
                    $sumatoryBaseTaxes += round($baseTax / 100, 2, PHP_ROUND_HALF_DOWN);
                    array_push($allTaxes, $newTax);
                }

                $totalItem = $sumatoryBaseTaxes + $sumatoryTaxes;

                if($totalItem <= 0){
                    InvoiceIntegrationDetails::updateOrInsert(
                        [
                            'invoice_id' => $invoice->id,
                            'integration' => AvailableMyposIntegration::NAME_FACTURAMA
                        ],
                        [
                            'status' => 'error',
                            'observations' => "No se envía a facturama porque el total = 0",
                            'updated_at' => Carbon::now()
                        ]
                    );
                    //busca la factura en el arreglo de facturas y la elimina pues ya se le denfinió un status
                    $itemKey = array_search($invoice->id, $this->invoicesIds);
                    unset($this->invoicesIds[$itemKey]);
                    continue;
                }
                
                $preTotalForComparation = round($invoice->total / 100, 2, PHP_ROUND_HALF_DOWN);
                $nowTotalForComparation = round($totalItem / 100, 2, PHP_ROUND_HALF_DOWN);
                if($nowTotalForComparation !== $preTotalForComparation){
                    InvoiceIntegrationDetails::updateOrInsert(
                        [
                            'invoice_id' => $invoice->id,
                            'integration' => AvailableMyposIntegration::NAME_FACTURAMA
                        ],
                        [
                            'status' => 'creating',
                            'observations' => "Diferencia en total del pos y total calculado para facturama, ajustado. Invoice {$preTotalForComparation} | System {$nowTotalForComparation}",
                            'updated_at' => Carbon::now()
                        ]
                    );
                }

                $newBillingInvoiceNumber = $facturamaController->formatInvoiceNumber($invoice->invoice_number, $this->store, [
                    'prefix'=>'F',
                    'invoice_number' => $invoice->invoice_number
                    ]
                );

                // Creamos un Item con los totales de la factura
                $newItem = [
                    "Quantity"              => 1,
                    "ProductCode"           => "01010101",
                    "UnitCode"              => "Act",
                    "Description"           => "FOLIO ".$newBillingInvoiceNumber,
                    "UnitPrice"             => round($invoice->undiscounted_subtotal / 100, 2, PHP_ROUND_HALF_DOWN),
                    "Subtotal"              => round($invoice->undiscounted_subtotal / 100, 2, PHP_ROUND_HALF_DOWN),
                    "Discount"              => round($invoice->discount_value / 100, 2, PHP_ROUND_HALF_DOWN),
                    "Taxes"                 => empty($allTaxes) ? null : $allTaxes,
                    "Total"                 => round($totalItem, 2, PHP_ROUND_HALF_DOWN)
                ];

                array_push($items, $newItem);
            }
            
            $newBillingInvoiceNumber = Helper::getNextBillingOfficialNumber($this->store->id, true);
            
            $params = [
                "Issuer" => [
                    "FiscalRegime"  => $this->store->billing_store_code,
                    "Rfc"           => $this->store->billing_code_resolution,
                    "Name"          => $this->store->name
                ],
                "Receiver" => [
                    "Rfc"       => "XAXX010101000",
                    "CfdiUse"   => "P01"
                ],
                "Folio"             => $newBillingInvoiceNumber,
                "CfdiType"          => "I",
                "NameId"            => "1",
                "ExpeditionPlace"   => $this->store->zip_code,
                "PaymentForm"       => $externalPaymentMean->external_payment_mean_code,
                "PaymentMethod"     => "PUE",
                "Currency"          => "MXN",
                "Items"             => $items
            ];

            Log::info(json_encode($params));
        } catch (Exception $e) {
            $errorId = Logging::getLogErrorId();
            //if the invoice object construction failed, we change the status for enable the invoice again
            foreach ($this->invoicesIds as $invoiceId) {

                InvoiceIntegrationDetails::updateOrInsert(
                    [
                        'invoice_id' => $invoiceId,
                        'integration' => AvailableMyposIntegration::NAME_FACTURAMA
                    ],
                    [
                        'status' => 'error',
                        'observations' => "myPOS job error: The invoice object construction has failed.",
                        'updated_at' => Carbon::now()
                    ]
                );

            }

            $errorMsg = "
            Error Global Invoice: The invoice object construction has failed.\n".
            "Tienda: {$this->store->name}.\n".
            "System error: ".$e->getMessage()."\n".
            "Error id: {$errorId}\n".
            "From Request: `{$this->request}`'";

            Logging::sendSlackMessage('#facturama_logs', $errorMsg, true);

            return Logging::printLogFile(
                $errorMsg,
                'facturama',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                "App\Jobs\Integrations\Facturama"
            );
            
        }
        
        $facturamaController->sendGlobalInvoice($this->store, $params, $this->invoicesIds, $this->request);
    }
}