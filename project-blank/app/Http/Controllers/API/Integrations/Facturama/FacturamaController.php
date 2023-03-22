<?php

namespace App\Http\Controllers\API\Integrations\Facturama;

use Log;
use App\Order;
use App\Store;
use Exception;
use App\Helper;
use App\Invoice;
use App\Payment;
use Carbon\Carbon;
use App\PaymentType;
use Facturama\Client;
use App\Traits\AuthTrait;
use App\StoreIntegrationId;
use App\Traits\Logs\Logging;
use Illuminate\Http\Request;
use App\StoreIntegrationToken;
use App\IntegrationsPaymentMeans;
use App\AvailableMyposIntegration;
use App\InvoiceIntegrationDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use Facturama\Exception\ModelException;
use App\Jobs\Integrations\Facturama\GlobalInvoices;

/**
 * Controller invocado únicamente desde el Job
 */
class FacturamaController extends Controller
{
    use AuthTrait;

    public $facturama;
    public $authUser;
    public $authEmployee;
    public $authStore;
    public $apiEnv;
    private $channelLogs;

    public function __construct()
    {
        $this->channelLogs = '#facturama_logs';
        $facturamaUser = App::environment('local') ? config('app.facturama_dev_user') : config('app.facturama_prod_user');
        $facturamaPass = App::environment('local') ? config('app.facturama_dev_pass') : config('app.facturama_prod_pass');
        $this->apiEnv = App::environment('local') ? config('app.facturama_dev_api') : config('app.facturama_prod_api');
        $this->facturama = new Client($facturamaUser, $facturamaPass);
        $this->facturama->setApiUrl($this->apiEnv);
    }

    public function validateOrigin(Request $request, String $inStore)
    {
        $origin = $request->header('origin');
        $origin = str_replace('https://', '', str_replace('http://', '', $origin));

        if(App::environment('local')){
            $originCheck = true;
        }else{
            $originCheck = str_replace('.xxx.xxx', '', $origin);
        }
        if ($originCheck != $inStore) {
            return [
                'status'  => 'Error',
                'results' => 'El dominio enviado no es el mismo que el origen del request'
            ];
        } else {
            return null;
        }
    }

    public function listFiscalRegimens()
    {
        $lstNameIds = $this->facturama->get('catalogs/FiscalRegimens');
        return response()->json($lstNameIds, 200);
    }

    public function listProductsAndServices(Request $request)
    {
        $keyword = $request->query('keyword');
        try {
            if (!$keyword) {
                return response()->json([], 200);
            }
            $lstProdsAndServs = $this->facturama->get('Catalogs/ProductsOrServices', ['keyword' => $keyword]);
            return response()->json($lstProdsAndServs, 200);
        } catch (\Exception $e) {
            return response()->json([], 200);
        }
    }

    public function getStoresBySubdomain(Request $request)
    {
        // Valida los campos recibidos
        $request->validate(["subdomain" => "bail|required|alpha|max:50"]);
        $hasError = $this->validateOrigin($request, $request->subdomain);
        if ($hasError) {
            return response()->json($hasError, 409);
        }

        // Obtenemos las tiendas
        $stores = StoreIntegrationId::where('external_store_id','like',"%{$request->subdomain}%")
                        ->with('store')
                        ->where('integration_name', 'facturama_wildcard')
                        ->get()
                        ->map(function ($storeInt) {
                            return $storeInt['store'];
                        });

        return response()->json([
            'status'  => 'Success',
            'results' => $stores
        ], 200);
    }

    public function uploadCSD(Request $request)
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();

        $store = $this->authStore;

        if (!$request->cerb64 || !$request->keyb64 || !$request->rfc || !$request->keyFilePass || !$request->fiscalRegimenSelected || !$request->zipCode) {
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Verifique que se ingresaron todos los datos requeridos'
                ],
                409
            );
        }

        $params = [
            'Rfc' => $request->rfc,
            'Certificate' => $request->cerb64,
            'PrivateKey' => $request->keyb64,
            'PrivateKeyPassword' => $request->keyFilePass
        ];

        $this->facturama->post('api-lite/csds', $params);

        try {
            DB::beginTransaction();

            $updateStore = Store::where('id', $store->id)->firstOrFail();
            $updateStore->billing_store_code      = $request->fiscalRegimenSelected;
            $updateStore->billing_code_resolution = $request->rfc;
            $updateStore->zip_code                = $request->zipCode;
            $updateStore->default_product_name    = $request->defaultProductName;
            $updateStore->default_product_code    = $request->defaultProductCode;
            $updateStore->save();

            $defineIntegration = StoreIntegrationToken::updateOrCreate(
                ['store_id' => $store->id, 'integration_name' => 'facturama'],
                ['token' => '-', 'type' => 'billing']
            );
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Ocurrió un error al intentar almacenar en DB. Intente de nuevo.'
                ],
                409
            );
        }

        return response()->json(
            [
                'status' => 'CSD subido exitosamente.',
                'results' => 'CSD subido exitosamente.'
            ],
            200
        );
    }

    public function getInfoAccount()
    {
        $infoAccount = $this->facturama->get('TaxEntity');
        return (object) $infoAccount;
    }

    public function createCFDI(Invoice $invoice, Store $store, $origin=null)
    {
        // Primer catch captura todos los errores de construcción del objeto
        try {
            // Trae el medio de pago que corresponde en Facturama
            $typePayment = Payment::where('order_id', $invoice->order_id)->first()->type;
            $nameIntegration = AvailableMyposIntegration::NAME_FACTURAMA;
            $externalPaymentMean = IntegrationsPaymentMeans::where('name_integration', $nameIntegration)
                ->where('local_payment_mean_code', $typePayment)->first();

            // Organiza  el listado de items
            $items = [];
            $order = $invoice->order;
            $taxDetails = $order->taxDetails;
            $allTaxes = [];
            $sumatoryTaxes = 0;
            $sumatoryBaseTaxes = 0;

            // Verificamos el product code & name por defecto o el configurado en la tienda
            $productCode = 90101800;
            $description = "Servicios de comida para llevar y a domicilio";
            if ($store->default_product_name && $store->default_product_code) {
                $productCode = $store->default_product_code;
                $description = $store->default_product_name;
            }

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

            $newItem = [
                "Quantity"              => 1,
                "ProductCode"           => $productCode,
                "UnitCode"              => "E48",
                "Description"           => $description,
                "Unit"                  => "Unidad",
                "IdentificationNumber"  => $invoice->invoice_number,
                "UnitPrice"             => round($invoice->undiscounted_subtotal / 100, 2, PHP_ROUND_HALF_DOWN),
                "Subtotal"              => round($invoice->undiscounted_subtotal / 100, 2, PHP_ROUND_HALF_DOWN),
                "Discount"              => round($invoice->discount_value / 100, 2, PHP_ROUND_HALF_DOWN),
                "Taxes"                 => empty($allTaxes) ? null : $allTaxes,
                "Total"                 => round($totalItem, 2, PHP_ROUND_HALF_DOWN)
            ];

            array_push($items, $newItem);

            $newBillingInvoiceNumber = $this->formatInvoiceNumber($invoice->invoice_number, $store, [
                'prefix'=>'F',
                'invoice_number' => $invoice->invoice_number
                ]
            );

            // Formamos los request params
            $params = [
                "Issuer" => [
                    "FiscalRegime"  => $store->billing_store_code,
                    "Rfc"           => $store->billing_code_resolution,
                    "Name"          => $store->name
                ],
                "Receiver" => [
                    "Email"     => $invoice->email,
                    "Rfc"       => $invoice->document,
                    "Name"      => $invoice->name,
                    "CfdiUse"   => "P01"
                ],
                "Folio"             => $newBillingInvoiceNumber,
                "CfdiType"          => "I", // I es = Ingreso. También existe: egreso, traslado
                "NameId"            => "1", // Default 1 es = Factura
                "ExpeditionPlace"   => $store->zip_code, // Codigo postal (Seteado en `stores` y vincula Tienda y Lugar de expedicion en facturama)
                "PaymentForm"       => $externalPaymentMean->external_payment_mean_code, // Forma de pago: efectivo, TC, T, etc
                "PaymentMethod"     => "PUE", // Existen 2. PUE: pago en una sola exhibición - PPD: pago en parcialidades o diferido
                "Currency"          => "MXN", // Moneda
                "Items"             => $items // Un unico item
            ];

        }catch (Exception $e) {
            $saveRelation = InvoiceIntegrationDetails::where('invoice_id', $invoice->id)
                        ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                        ->first();
            if(empty($saveRelation)){
                $saveRelation = new InvoiceIntegrationDetails;
            }
            
            $saveRelation->invoice_id = $invoice->id;
            $saveRelation->external_id = null;
            $saveRelation->integration = AvailableMyposIntegration::NAME_FACTURAMA;
            $saveRelation->status = 'error';
            $saveRelation->observations = substr($e, 0, 180);
            $saveRelation->save();

            $errorId = Logging::getLogErrorId();

            $errorMsg = "
            Error Normal Invoice: Failed creating invoice for Facturama.\n".
            "Tienda: {$store->name}.\n".
            "Invoice id: {$invoice->id}.\n".
            "System error: ".$e->getMessage()."\n".
            "Error id: {$errorId}\n".
            "Auto-billing: ".($origin != null ? $origin : "No");

            Logging::sendSlackMessage($this->channelLogs, $errorMsg, true);
            Logging::printLogFile(
                $errorMsg."\n Stack: ".$e,
                'facturama',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ""
            );

            return false;
        }

        // Segundo catch captura todos los errores relacionados con el envío de request hacia facturama
        try{

            Log::channel('facturama')->info('-------------------------------------------------------------------------------------');
            Log::channel('facturama')->info('Invoice For Facturama '. json_encode($params));

            $urlApi = 'api-lite/2/cfdis';
            $issuedType = 'issuedLite';
            
            $storesIntegration = [];
            if($origin != null){
                $storesIntegration = StoreIntegrationId::where('external_store_id', $origin)
                    ->where('integration_name', 'facturama_wildcard')
                    ->where('store_id', $store->id)
                    ->where('username',"!=","")
                    ->where('password',"!=","")
                    ->first();
            }else{
                $storesIntegration = StoreIntegrationId::where('integration_name', 'facturama_wildcard')
                    ->where('store_id', $store->id)
                    ->where('username',"!=","")
                    ->where('password',"!=","")
                    ->first();
            }
            
            //si existe un usuario y contraseña, significa que esta tienda factura con api web
            if (!empty($storesIntegration)){

                $issuedType = 'issued';
                $urlApi = '2/cfdis';
                //Issuer no es necesario para api web 
                unset($params["Issuer"]);

                $params["Folio"] = strval($params["Folio"]);

                $this->facturama = new Client($storesIntegration->username, $storesIntegration->password);
                $this->facturama->setApiUrl($this->apiEnv);

                $this->setReceiver($invoice);

            }

            $result = $this->facturama->post($urlApi, $params);

            $saveRelation = InvoiceIntegrationDetails::where('invoice_id', $invoice->id)
                        ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                        ->first();

            if(empty($saveRelation)){
                $saveRelation = new InvoiceIntegrationDetails;
            }

            $saveRelation->invoice_id = $invoice->id;
            $saveRelation->external_id = $result->Id;
            $saveRelation->integration = AvailableMyposIntegration::NAME_FACTURAMA;
            $saveRelation->status = $result->Status;
            $saveRelation->save();

            // Envía el correo al cliente
            $resultSendEmail = $this->facturama->post('Cfdi?cfdiType='.$issuedType.'&cfdiId='.$result->Id.'&email='.$invoice->email);

            Log::channel('facturama')->info('Facturama Factura creada: '.$invoice->id.', Resultado: '.json_encode($result));
            Log::channel('facturama')->info('Facturama Email enviado: '.$invoice->id.', Resultado: '.json_encode($resultSendEmail));
            Log::channel('facturama')->info('-------------------------------------------------------------------------------------');
            
            return true;
        } catch (Exception $e) {
            $saveRelation = InvoiceIntegrationDetails::where('invoice_id', $invoice->id)
                        ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                        ->first();
            if(empty($saveRelation)){
                $saveRelation = new InvoiceIntegrationDetails;
            }
            
            $saveRelation->invoice_id = $invoice->id;
            $saveRelation->external_id = null;
            $saveRelation->integration = AvailableMyposIntegration::NAME_FACTURAMA;
            $saveRelation->status = substr($e, 0, 180);
            $saveRelation->save();

            $errorId = Logging::getLogErrorId();

            $errorMsg = "
            Error Normal Invoice: Failed sending invoice to Facturama.\n".
            "Tienda: {$store->name}.\n".
            "Invoice id: {$invoice->id}.\n".
            "System error: ".$e->getMessage()."\n".
            "Error id: {$errorId}\n".
            "Auto-billing: ".($origin != null ? $origin : "No")."\n".
            "Obj invoice: `".json_encode($params)."`";

            Logging::sendSlackMessage($this->channelLogs, $errorMsg, true);
            Logging::printLogFile(
                $errorMsg."\n Stack: ".$e,
                'facturama',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                "Object sended: ".json_encode($params)
            );
            
            return false;
        }
    }

    // Not necesary for api-multiemisor
    public function setReceiver($invoice)
    {
        // Buscamos el cliente en facturama por RFC, si existe lo retornamos
        $client = $this->facturama->get('Client?keyword='.$invoice->document);
        if (isset($client->Name)) {
            return [
                "Name" => $client->Name,
                "Rfc" => $client->Rfc,
                "CfdiUse" => $client->CfdiUse
            ];
        }

        // Si no existe, seteamos los datos de un nuevo cliente
        $newClient= [
            "Email"=> $invoice->email,
            "Rfc"=> $invoice->document,
            "Name"=> $invoice->name,
            "CfdiUse"=> "P01"
        ];

        // Creamos el nuevo cliente
        $createClient = $this->facturama->post('Client', $newClient);

        if (!isset($createClient->Id)) {
            throw new Exception("No se pudo crear el nuevo cliente en Facturama");
        }

        return [
            "Name" => $createClient->Name,
            "Rfc" => $createClient->Rfc,
            "CfdiUse" => $createClient->CfdiUse
        ];
    }

    public function cancelCFDI($invoiceExternalId)
    {
        $cancelInvoice = $this->facturama->delete('Cfdi/'.$invoiceExternalId.'?type=issuedLite');

        $updateRelation = InvoiceIntegrationDetails::where('external_id', $invoiceExternalId)->firstOrFail();
        $updateRelation->status = $cancelInvoice->Status;
        $updateRelation->save();

        Log::channel('facturama')->info('RESULT cancelCFDI'. json_encode($cancelInvoice));
    }

    public function searchFolio(Request $request)
    {
        // Valida los campos recibidos
        $request->validate([
            "dateFolio"     => "bail|required|date_format:Y-m-d",
            "numberFolio"   => "bail|required|numeric|digits_between:1,20",
            "totalFolio"    => "bail|required|numeric|digits_between:1,20",
            "inStore"       => "bail|required|alpha|max:50",
            "storeId"       => "bail|required|numeric"
        ]);
        $hasError = $this->validateOrigin($request, $request->inStore);
        if ($hasError) {
            return response()->json($hasError, 409);
        }

        $request->totalFolio = round($request->totalFolio, 2);

        // Verificamos que la tienda exista
        $currentStoreId = $request->storeId;
        $storeInt = StoreIntegrationId::where('external_store_id','like',"%{$request->subdomain}%")
                    ->where('integration_name', 'facturama_wildcard')
                    ->where('store_id', $currentStoreId)
                    ->first();
        if (!$storeInt) {
            return response()->json(
                [
                    'status'  => 'Error',
                    'results' => 'La tienda no existe.'
                ],
                409
            );
        }

        // Buscamos todas las órdenes del día indicado, por tienda y por el total especificado
        $startDate  = $request->dateFolio." 00:00:00";
        $endDate    = $request->dateFolio." 23:59:59";
        $invoice = Invoice::whereBetween('created_at', [$startDate, $endDate])
                        ->whereHas('order', function ($query) use ($storeInt) {
                            $query->where('store_id', $storeInt->store_id);
                        })
                        ->where('total', $request->totalFolio)
                        ->where('invoice_number', $request->numberFolio)->first();
        if (!$invoice) {
            return response()->json(
                [
                    'status'  => 'Error',
                    'results' => 'El folio no existe.'
                ],
                409
            );
        }

        $order = $invoice->order;
        $orderDetail = "Servicios de comida para llevar y a domicilio";
        // Sacamos únicamente el nombre de los productos para no enviar todo el objeto orderDetails
        // foreach ($order->orderDetails as $detail) {
        //     $orderDetail .= "\n ".$detail->quantity."x ".$detail->name_product;
        // }

        // Sacamos el método de pago en string
        $paymentMethod = 'N/A';
        switch ($order->payments->first()->type) {
            case PaymentType::CASH:
                $paymentMethod = 'Efectivo';
                break;
            case PaymentType::DEBIT:
                $paymentMethod = 'T. Débito';
                break;
            case PaymentType::CREDIT:
                $paymentMethod = 'T. Crédito';
                break;
            case PaymentType::TRANSFER:
                $paymentMethod = 'Transferencia';
                break;
            case PaymentType::OTHER:
                $paymentMethod = 'Otros';
                break;
            case PaymentType::RAPPI_PAY:
                $paymentMethod = 'Rappi Pay';
                break;
        }

        $synced = $invoice->integrations->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                    ->where('status', 'active')
                    ->first();

        return response()->json(
            [
                'status'  => 'Success',
                'results' => [
                    "synced" => $synced ? true : false, //indica si esta factura ya fue enviada a facturama
                    "invoice_id" => (string) $invoice->id,
                    "invoice" => (string) $invoice->invoice_number,
                    "date" => $invoice->created_at,
                    "order_details" => $orderDetail,
                    "payment" => (string) $paymentMethod,
                    "total" => (string) $invoice->total,
                    "store" => (string) $storeInt->store->name,
                ]
            ],
            200
        );
    }

    public function sendFolio(Request $request)
    {
        // Valida el formato del token
        $request->validate([
            "invoice_id" => "bail|required|exists:invoices,id",
            "rfc"   => ['regex:/[A-Z&Ñ]{3,4}[0-9]{2}(0[1-9]|1[012])(0[1-9]|[12][0-9]|3[01])[A-Z0-9]{2}[0-9A]/'],
            "name"  => "bail|required|string|max:200",
            "email" => "bail|email"
        ]);

        // Recupera la factura por id
        $invoice = Invoice::where('id', $request->invoice_id)->first();

        // Verificamos si la factura ya fue enviada a Facturama
        $synced = $invoice->integrations->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                    ->where('status', 'active')->first();
        if ($synced) {
            return response()->json(
                [
                    'status'  => 'Error',
                    'results' => 'Esta factura ya se envió a facturama.'
                ],
                409
            );
        }

        // Edita los datos básicos de la factura
        $invoice->name = $request->name;
        $invoice->document = $request->rfc;
        $invoice->email = $request->email;
        $invoice->save();

        // Envía la factura y retorna el resultado
        try {
            $origin = $request->header('origin');
            $origin = str_replace('https://', '', str_replace('http://', '', $origin));

            $isSuccess = $this->createCFDI($invoice, $invoice->order->store, $origin);

            if (!$isSuccess) {
                return response()->json(
                    [
                        'status'  => 'Error',
                        'results' => 'No es posible facturar en este momento.'
                    ],
                    409
                );
            }

            return response()->json(
                [
                    'status'  => 'Success',
                    'results' => 'Se facturó correctamente.'
                ],
                200
            );
        } catch (\Exception $e) {
            Log::channel('facturama')->error("FACTURAMA AUTOBILLING ".$e->getMessage());
            return response()->json(
                [
                    'status'  => 'Error',
                    'results' => 'No es posible facturar en este momento.'
                ],
                409
            );
        }
    }

    public function getGlobalInvoice(Request $request)
    {
        // Valida los campos recibidos
        $request->validate([
            "fromDate"      => "bail|required|date_format:Y-m-d",
            "toDate"        => "bail|required|date_format:Y-m-d",
            "storeId"       => "bail|required|array",
            "sendFolios"    => "bail|required|boolean",
        ]);

        // Buscamos todas las facturas de los días indicados y por tienda
        $startDate  = $request->fromDate." 00:00:00";
        $endDate    = $request->toDate." 23:59:59";
        $storeId    = array_values($request->storeId);
        $sendFolios = $request->sendFolios;
        $integration = AvailableMyposIntegration::NAME_FACTURAMA;

        $invoices = Order::where('invoices.created_at','>=',$startDate)
            ->where('invoices.created_at','<=',$endDate)
            ->where('invoices.invoice_number','<>','')
            ->where('invoice_integration_details.external_id','=',null)
            ->where('invoice_integration_details.status','!=','active')
            ->where('invoice_integration_details.status','!=','creating') //new status while the GlobalInvoices job work in the invoice
            ->where('invoice_integration_details.integration','=',$integration)
            ->whereIn('orders.store_id', $storeId)
            ->leftJoin('invoices','orders.id','=','invoices.order_id')
            ->leftJoin('invoice_integration_details','invoices.id','=','invoice_integration_details.invoice_id')
            ->leftJoin('stores','orders.store_id','=','stores.id')
            ->select(
                'invoices.created_at',
                'invoices.invoice_number',
                'invoices.total',
                'invoices.order_id',
                'invoices.id',
                'stores.name as store_name',
                'stores.default_product_code',
                'stores.default_product_name',
                'stores.id as store_id'
            )
            ->get();
        
        if (count($invoices) < 1){
            return response()->json([
                'status'  => 'success',
                'results' => []
            ], 200);
        }

        //si no se ha solicitado la creación de la factura, solo retornamos el resultado
        if(!$sendFolios){
            return response()->json(['status'  => 'success', "results" => $invoices]);
        }

        $invoicesCollection = collect($invoices);

        //recorremos las facturas de cada una de las tiendas del query
        foreach ($storeId as $sId) {
            $invoices = $invoicesCollection->where('store_id', $sId);

            //si no existen facturas, obviamos esta tienda
            if(empty($invoices)){
                continue;
            }

            $store = Store::where('id', $storeId)->first();
            
            $invoicesIds = [];
            foreach ($invoices as $invoice) {
                array_push($invoicesIds, $invoice->id);
                InvoiceIntegrationDetails::updateOrInsert(
                    [
                        'invoice_id' => $invoice->id,
                        'integration' => AvailableMyposIntegration::NAME_FACTURAMA
                    ],
                    [
                        'status' => 'creating',
                        'observations' => '',
                        'updated_at' => Carbon::now()
                    ]
                );
            }

            dispatch( new GlobalInvoices($invoices, $store, $invoicesIds, json_encode($request->all()) ) )->onConnection('backoffice');
        }
        
        return response()->json([
            'status'  => 'success',
            'results' => []
        ], 200);
    }

    public function formatInvoiceNumber($invoiceNumber = null, Store $store = null, $data = []){
        switch ($store->company_id) {
            case "pendiente_moro274":
                $storeNameExploded = explode(" ", ucwords($store->name));
                $shortenStoreName = null;
                foreach ($storeNameExploded as $value) {
                    $shortenStoreName .= substr($value, 0, 1);
                }

                if(!isset($data['prefix']) || empty($data['prefix'])){
                    throw new Exception("prefix is necesary");
                }

                if($data['prefix'] === 'G'){
                    $nextBillNumber = Helper::getNextBillingOfficialNumber($store->id, true);
                    return "{$data['prefix']}-{$shortenStoreName}-{$nextBillNumber}";

                }else if($data['prefix'] === 'F'){
                    if(!isset($data['invoice_number']) || empty($data['invoice_number'])){
                        throw new Exception("invoice_number is necesary");
                    }
                    return "{$data['prefix']}-{$shortenStoreName}-{$data['invoice_number']}";

                }else{
                    throw new Exception("prefix must be G or F");

                }
            break;
            
            default:
                return $invoiceNumber;
            break;
        }
    }

    public function sendGlobalInvoice(Store $store, $invoiceToSend, $invoicesIds, $initialRequest){
        try {
            $urlApi = 'api-lite/2/cfdis';
            $issuedType = 'issuedLite';

            $storeIntegration = StoreIntegrationId::where('integration_name', 'facturama_wildcard')
                ->where('store_id', $store->id)
                ->where('username',"!=","")
                ->where('password',"!=","")
                ->first();

            // Si tenemos las credenciales de la tienda, facturamos con dichas creds
            if (!empty($storeIntegration)) {
                $issuedType = 'issued';
                $urlApi = '2/cfdis';

                $this->facturama = new Client($storeIntegration->username, $storeIntegration->password);
                $this->facturama->setApiUrl($this->apiEnv);
            }

            // Creamos la factura Global
            $result = $this->facturama->post($urlApi, $invoiceToSend);
            $this->facturama->post('Cfdi?cfdiType='.$issuedType.'&cfdiId='.$result->Id.'&email='.$store->email);

            // Se crea por cada factura del request
            foreach ($invoicesIds as $invId) {
                $saveRelation = InvoiceIntegrationDetails::where('invoice_id', $invId)
                    ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                    ->first();
                if(empty($saveRelation)){
                    $saveRelation = new InvoiceIntegrationDetails;
                }
                
                $saveRelation->invoice_id  = $invId;
                $saveRelation->integration = AvailableMyposIntegration::NAME_FACTURAMA;
                $saveRelation->external_id = $result->Id;
                $saveRelation->status      = $result->Status;
                $saveRelation->save();
            }

            Log::channel('facturama')->info('Facturama Factura Global creada, Resultado: '.json_encode($result));
            return true;

        } catch (Exception $e) {
            // Se crea por cada factura del request
            foreach ($invoicesIds as $invId) {
                $saveRelation = InvoiceIntegrationDetails::where('invoice_id', $invId)
                    ->where('integration', AvailableMyposIntegration::NAME_FACTURAMA)
                    ->first();
                if(empty($saveRelation)){
                    $saveRelation = new InvoiceIntegrationDetails;
                }

                $saveRelation->invoice_id = $invId;
                $saveRelation->external_id = null;
                $saveRelation->integration = AvailableMyposIntegration::NAME_FACTURAMA;
                $saveRelation->status = 'error';
                $saveRelation->observations = substr($e, 0, 191);
                $saveRelation->save();
            }
            
            $errorId = Logging::getLogErrorId();
            $errorMsg = "
            Error Global Invoice: send invoice to Facturama has failed.\n".
            "Tienda: {$store->name}.\n".
            "System error: ".$e->getMessage()."\n".
            "Error id: {$errorId}\n".
            "From Request: `{$initialRequest}`'";

            Logging::sendSlackMessage($this->channelLogs, $errorMsg, true);
            Logging::printLogFile(
                $errorMsg."\n Stack: ".$e,
                'facturama',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                "Object sended: ".json_encode($invoiceToSend)
            );
            return false;
        }
    }
}
