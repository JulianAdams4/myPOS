<?php

namespace App\Http\Controllers\API\Store;

use App\Store;
use App\Helper;
use App\Company;
use App\Country;
use Carbon\Carbon;
use Facturama\Client;
use App\Traits\Logs\Logging;
use Illuminate\Http\Request;
use App\SubscriptionInvoices;
use App\AvailableMyposIntegration;
use App\SubscriptionInvoiceDetails;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class SubscriptionBillingController extends Controller
{
    public $facturama;
    public $channel;

    public function __construct() {
        //datos de conexión quemados para trabajar en dev
        $facturamaUser = App::environment('local') ? config('app.facturama_dev_user') : config('app.facturama_prod_user');
        $facturamaPass = App::environment('local') ? config('app.facturama_dev_pass') : config('app.facturama_prod_pass');
        $this->facturama = new Client($facturamaUser, $facturamaPass);
        $this->facturama->setApiUrl( App::environment('local') ? config('app.facturama_dev_api') : config('app.facturama_prod_api') );
        $this->channel = "#billing-subscriptions-logs";
    }

    public function checkPlansByCompany(){
        $companies = Company::get();
        $itemsForCompany = [];

        foreach ($companies as $company) {

            foreach ($company->stores as $store) {

                //evadimos procesar ecuador
                if(!isset($store->country_code) || $store->country_code == "EC"){
                    continue;
                }
                
                //asigna el rate tax dependiendo del país
                if($store->country_code == "MX"){
                    $rateTax = 0.16;
                }elseif($store->country_code == "CO"){
                    $rateTax = 0.19;
                }

                $now = $this->carbon($store->country_code);
                // $now = $this->carbon($store->country_code, '2020-11-30 22:22:17');

                $plansDetails = $this->getTotalsOfSubsPlanForStore($store, $rateTax);
                //si se retorna un $plansDetails['items'] vacío es porque no se han definido planes para esta tienda
                if(empty($plansDetails['items'])){
                    continue;
                }

                //recorremos todos los planes de la tienda actual
                foreach ($plansDetails['items'] as $plan) {

                    /*si no tenemos una fecha de vencimiento (subs_plan_last_end_date), quiere decir que es la primera
                    facturación que haremos para esta plan por lo que usamos subs_plan_first_activ_date, y $now ahora se convierte
                    en la misma fecha de subs_plan_first_activ_date para poder facturar inmediatamente.
                    Nota: hasta este punto solo llegan los planes que tengan fecha de activación.*/

                    if(empty($plan['subs_plan_last_end_date'])){
                        $endDatePlan = $this->carbon($store->country_code, $plan['subs_plan_first_activ_date']);
                        $now = $this->carbon($store->country_code, $plan['subs_plan_first_activ_date']);
                        $status = 1; //1=pagado - la primera factura de toda compañía queda como "pagado"
                    }else{
                        $endDatePlan = $this->carbon($store->country_code, $plan['subs_plan_last_end_date']);
                        $status = 2; // 2= pendiente - todo plan a renovar queda por default en pendiente
                    }
                    
                    /*Si la fecha actual no supera la de finalización, pasa a la siguiente iteración.
                     Si el total es 0 (decuentos no pueden ser mayores a 100%), pasa a la siguiente iteración*/
                    if($now < $endDatePlan || $plan['total'] <= 0){
                        continue;
                    }

                    if(!isset($itemsForCompany[$plan['store_country']][$company->id])){
                        $itemsForCompany[ $plan['store_country'] ][$company->id]['items'] = [];
                    }

                    //calculamos la fecha de vencimiento de la renovación para detallarla en la factura
                    $newEndDate = $this->calculateEndDateByFrecuency($endDatePlan, $plan['subs_plan_frequency'], $plan['store_country']);
                    $timeZone = $endDatePlan->timezone->getName();
                    $plan['description'] = $plan['description']." - Vigencia: ({$timeZone}) {$endDatePlan} - {$newEndDate}";
                    $plan['status'] = $status;

                    array_push(
                        $itemsForCompany[ $plan['store_country'] ][$company->id]['items'], 
                        $plan
                    );
        
                }

            }
        }

        //agregamos el arreglo 'all' a cada company 
        $itemsForCompany = $this->totalizeSubsPlansForCompanies($itemsForCompany);
        $this->createInvoicesByCountry($itemsForCompany);
    }

    public function carbon($country, $dateHour = null){
        $timeZone = 'America/Mexico_City';
        switch ($country) {
            case 'MX':
                $timeZone = 'America/Mexico_City';
            break;

            case 'CO':
                $timeZone = 'America/Bogota';
            break;
        }

        if(empty($dateHour)){
            $carbon = Carbon::now()->setTimezone($timeZone);
        }else{
            $carbon = Carbon::parse($dateHour)->setTimezone($timeZone);
        }

        return $carbon;
    }

    /**
     * Descripción: Función encargada de traer el detalle de cada plan de subscripción de una tienda
     * @param store object de la clase App\Store
     * @param rateTax float indica la tarifa sobre la que se calcula el impuesto
     * @return array
     */
    public function getTotalsOfSubsPlanForStore(Store $store, $rateTax){
        $subscriptions = $store->subscriptions;
        $allBaseValue = 0; //sumatoria de las base values de todos los planes
        $allBaseValueTaxes = 0; //sumatoria de los base values para impuestos de todos los planes
        $allTotals = 0; //sumatoria de los totales de todos los planes
        $allTaxes = 0; // sumatoria de los impuestos de todos los planes
        $allDiscounts = 0; //sumatoria de los descuentos de todos los planes
        $items = ["items" => [], 'all' => []]; //arreglo con el detalle de cada plan, y totales

        //recorremos todos los planes de subscripción que tenga la tienda
        foreach ($subscriptions as $sub) {

            //Trae el detalle de cada plan
            $subscriptionPlan = $sub->subscriptionPlan;

            //Trae el detalle del producto del plan
            $subscriptionProduct = $subscriptionPlan->subscriptionProduct;

            //crea una descripción general para el producto
            $itemDescription = " Tienda: ".$store->name." - ".
                                $subscriptionPlan->name.": ".$subscriptionProduct->name;

            //si no existe fecha de activación no factura el plan porque se trataría de un plan demo
            if(empty($sub->activation_date)){
                Log::channel('subscription_billing')->info('-----------------------------------');
                Log::channel('subscription_billing')->info("No se factura el plan ID {$sub->id} porque no tiene fecha de activación.");
                Log::channel('subscription_billing')->info("Descripción: ".$itemDescription);
                continue;
            }

            $quantity = 1; //default 1 (por ahora), un plan solo tiene 1 producto.

            //Suma los descuento por porcentaje del producto
            $productDiscPercentage = $store->subscriptionDiscounts->where('subscription_product_id', $subscriptionProduct->id)
                    ->where('is_percentage', 1)
                    ->sum('discount');
            
            //aplica la sumatoria de todos los descuentos por porcentaje
            $productDiscPercentage = $subscriptionProduct->price * ($productDiscPercentage / 100);
            
            //Suma los descuento por valor del producto
            $productDiscValue = $store->subscriptionDiscounts->where('subscription_product_id', $subscriptionProduct->id)
            ->where('is_percentage', 0)
            ->sum('discount');

            //suma el total de descuentos por valor y porcentaje
            $totalDiscounts = $productDiscPercentage + $productDiscValue;
            $allDiscounts += $totalDiscounts;

            $baseValue = $subscriptionProduct->price * $quantity;
            $allBaseValue += $baseValue;
            
            $baseValueTax = $baseValue - $totalDiscounts;
            $allBaseValueTaxes += $baseValueTax;

            $totalTax = $baseValueTax * $rateTax;
            $allTaxes += $totalTax;

            $total = $baseValue - $totalDiscounts + $totalTax;
            $allTotals += $total;
            
            //traemos el registro de facturación más antiguo
            $lastBilling = SubscriptionInvoiceDetails::where('store_id', $store->id)
            ->where('subscription_plan_id', $subscriptionPlan->id)
            ->latest('id')
            ->first();

            array_push($items['items'],
                [
                    "store_id" => $store->id,
                    "store_country" => $store->country_code,
                    "subs_plan_id" => $subscriptionPlan->id,
                    "subs_plan_frequency" => $subscriptionPlan->frequency,
                    "subs_plan_first_activ_date" => $sub->activation_date,
                    "subs_plan_billing_date" => $sub->billing_date,
                    "subs_plan_last_start_date" => isset($lastBilling->subs_start) ? $lastBilling->subs_start : null,
                    "subs_plan_last_end_date" => isset($lastBilling->subs_end) ? $lastBilling->subs_end : null,
                    "description" => $itemDescription,
                    "quantity" => $quantity, 
                    "base_value" => round($baseValue / 100, 2),
                    "base_value_tax" => round($baseValueTax / 100, 2),
                    "rate_tax" => $rateTax,
                    "discount" => round($totalDiscounts / 100, 2),
                    "taxes" => round($totalTax / 100, 2),
                    "total" => round($total / 100, 2)
                ]
            );
        }

        $items['all'] = [
            "base_values" => round($allBaseValue / 100, 2),
            "totals" => round($allTotals / 100, 2),
            "base_values_taxes" => round($allBaseValueTaxes / 100, 2),
            "taxes" => round($allTaxes / 100, 2),
            "discounts" => round($allDiscounts / 100, 2)
        ];
        return $items;
    }

    public function invoiceForFacturama(array $items, Company $company){
        $newItems = [];
        foreach ($items['items'] as $item) {
            $taxes = [
                "Name"  => "Iva",
                "Rate"  => $item['rate_tax'],
                "Total" => round($item['taxes'], 2),
                "Base"  => round($item['base_value_tax'], 2),
                "IsRetention" => false
            ];

            $newitem = [
                "Quantity" => $item['quantity'],
                "ProductCode" => 81112501,
                "UnitCode" => "MON",
                "Description" => $item['description'],
                "UnitPrice" => round($item['base_value'], 2),
                "Subtotal" => round($item['base_value'], 2),
                "Discount" => round($item['discount'], 2),
                "Taxes" => empty($taxes) ? null : [$taxes],
                "Total" => $item['total']
            ];

            array_push($newItems, $newitem);
        }
        
        $zipCode = App::environment('local') ? 86341 : 15970;

        $params = [
            "Receiver" => $this->setReceiver($company),
            "CfdiType" => "I", // I es = Ingreso. También existe: egreso, traslado
            "ExpeditionPlace" => $zipCode, //codigo postal
            "PaymentForm" => "31", // forma de pago: efectivo, TC, T, etc
            "PaymentMethod" => "PUE", // existen 2. PUE: pago en una sola exhibición - PPD: pago en parcialidades o diferido
            "Currency" => "MXN", //moneda 
            "Items" => $newItems
        ];

        try {
            Log::channel('subscription_billing')->info('-------------------------------------------------------------------------------------');
            Log::channel('subscription_billing')->info('Invoice For Facturama '. json_encode($params));
            $result = $this->facturama->post('2/cfdis', $params);

            //Envía el correo al cliente
            $resultSendEmail = $this->facturama->post('Cfdi?cfdiType=issued&cfdiId='.$result->Id.'&email='.$company->email);

        } catch (Exception $e) {
            Log::channel('subscription_billing')->info('Facturama: '.$e->getMessage().' Company: '.$company->name.'No se puedo crear la factura. Request: '.json_encode($params));
            Log::channel('subscription_billing')->info('-------------------------------------------------------------------------------------');
            throw new Exception($e->getMessage());
        }

        return [
            "id" => $result->Id,
            "integration" => AvailableMyposIntegration::NAME_FACTURAMA
        ];
    }

    public function calculateEndDateByFrecuency(String $startDate, $frequency, $timeZone){
        $startDate = $this->carbon($timeZone, $startDate);
        switch ($frequency) {
            case 'day':
                $endDate = $startDate->addDay();
            break;
            
            case 'week': 
                $endDate = $startDate->addWeek();
            break;

            case 'month':
                $endDate = $startDate->addMonth();
            break;

            case 'year':
                $endDate = $startDate->addYear();
            break;
            
            default:
                return false;
            break;
        }

        return $endDate->format('Y-m-d H:i:s');
    }

    public function createInvoicesByCountry($subsPlan){
        $countries = Country::get();
        $companies = Company::get();
        foreach ($countries as $country) {

            if(!isset($subsPlan[$country->code])){
                continue;
            }

            switch($country->code){
                case 'MX':
                    //recorre todas las companías
                    foreach ($companies as $company) {

                        //verifica si la compañía tiene facturas para el país actual 
                        if(isset($subsPlan[$country->code][$company->id])){
                            //trae info de la compañía en iteración
                            $actualCompany = $companies->where('id', $company->id)->first();
                            
                            //crea la factura en la integración
                            try {
                                $externalInvoice = $this->invoiceForFacturama($subsPlan[$country->code][$company->id], $actualCompany);
                            } catch (\Throwable $th) {

                                $slackMessage = "Company:[{$company->id}|{$company->name}] No se pudo enviar a facturama.
                                                        Revise logs del servidor para ver detalles.";
        
                                Log::channel('subscription_billing')->info('-----------------------------------');
                                Log::channel('subscription_billing')->info($slackMessage." Error facturama: {$th}");

                                Logging::sendSlackMessage(
                                    $this->channel,
                                    $slackMessage
                                );
                                
                                continue;
                            }
                            
                            //crea el registro interno de la factura
                            $createInvoice = $this->createSubsInvoice($subsPlan[$country->code][$company->id],
                                                $externalInvoice,
                                                $company->id,
                                                $country->code
                                             );

                            $createDetailsInvoice = $this->createDetailsSubsInvoice($subsPlan[$country->code][$company->id],
                                                        $createInvoice);
                        }
                    }
                break;
    
                case 'CO':
                    #code
                break;
                
                default:
                    return false;
                break;
            }
        }
    }

    public function setReceiver($company){
        // Buscamos el cliente en facturama por RFC, si existe lo retornamos
        $client = $this->facturama->get('Client?keyword='.$company->TIN);
        if(isset($client->Name)){
            return [
                "Name" => $client->Name,
                "Rfc" => $client->Rfc,
                "CfdiUse" => $client->CfdiUse
            ];
        }
        
        //Si no existe, seteamos los datos de un nuevo cliente
        $newClient= [
            "Email"=> $company->email,
            "Rfc"=> $company->TIN,
            "Name"=> $company->name,
            "CfdiUse"=> "G03"
        ];

        //creamos el nuevo cliente
        $createClient = $this->facturama->post('Client', $newClient);

        if(!isset($createClient->Id)){
            throw new Exception("No se pudo crear el nuevo cliente en Facturama");
        }

        return [
            "Name" => $createClient->Name,
            "Rfc" => $createClient->Rfc,
            "CfdiUse" => $createClient->CfdiUse
        ];

    }

    public function createSubsInvoice($plans, $externalInvoice, $companyId, $country){
        $subsInvoice = SubscriptionInvoices::create([
            'external_invoice_id'   => $externalInvoice['id'],
            'integration_name'      => $externalInvoice['integration'],
            'subtotal'              => $plans['all']['base_values'] * 100,
            'discounts'             => $plans['all']['discounts'] * 100,
            'total_taxes'           => $plans['all']['taxes'] * 100,
            'total'                 => $plans['all']['totals'] * 100,
            'status'                => $plans['all']['status'] == 1 ? SubscriptionInvoices::PENDING : SubscriptionInvoices::PENDING,
            'billing_date'          => $plans['all']['status'] == 1 ? Carbon::now() : null,
            'company_id'            => $companyId,
            'country'               => $country
        ]);

        return $subsInvoice->id;
    }

    public function createDetailsSubsInvoice($plans, $subsInvoice){
        foreach ($plans['items'] as $item) {
            //si no existe subs_plan_last_end_date entonces se trata de un plan quese factura por primera vez
            if(empty($item['subs_plan_last_end_date'])){
                $startDate = $item['subs_plan_first_activ_date'];
            }else{
                $startDate = $item['subs_plan_last_end_date'];
            }

            //calculamos la fecha de vencimiento del plan a partir de una fecha y frecuencia dada
            $endDate = $this->calculateEndDateByFrecuency($startDate, $item['subs_plan_frequency'], $item['store_country']);

            SubscriptionInvoiceDetails::create([
                'store_id'              => $item['store_id'],
                'subscription_plan_id'  => $item['subs_plan_id'],
                'subs_invoice_id'       => $subsInvoice,
                'subtotal'              => $item['base_value'] * 100,
                'discounts'             => $item['discount'] * 100,
                'total_taxes'           => $item['taxes'] * 100,
                'total'                 => $item['total'] * 100,
                'subs_start'            => $startDate,
                'subs_end'              => $endDate,
                'description'           => $item['description'],
                'status'                => $item['status'] == 1 ? SubscriptionInvoices::PENDING : SubscriptionInvoices::PENDING,
            ]);
        }
    }

    public function totalizeSubsPlansForCompanies($subsPlan){
        $countries = Country::get();
        $companies = Company::get();
        foreach ($countries as $country) {

            if(!isset($subsPlan[$country->code])){
                continue;
            }

            foreach ($companies as $company) {

                //verifica si la compañía tiene facturas para el país actual 
                if(isset($subsPlan[$country->code][$company->id])){

                    //recorremos todos los items para hacer las sumatorias
                    $allBaseValue = 0; //sumatoria de las base values de todos los planes
                    $allBaseValueTaxes = 0; //sumatoria de los base values para impuestos de todos los planes
                    $allTotals = 0; //sumatoria de los totales de todos los planes
                    $allTaxes = 0; // sumatoria de los impuestos de todos los planes
                    $allDiscounts = 0; //sumatoria de los descuentos de todos los planes
                    $status = 2;

                    foreach ($subsPlan[$country->code][$company->id]['items'] as $plan) {
                        $allBaseValue += $plan['base_value'];
                        $allBaseValueTaxes += $plan['base_value_tax'];
                        $allTotals += $plan['total'];
                        $allTaxes += $plan['taxes'];
                        $allDiscounts += $plan['discount'];
                        $status = $plan['status'];
                    }
                    
                    //crea el nuevo arreglo 'all' que totaliza todos los valores para la compañía
                    $subsPlan[$country->code][$company->id]['all'] = [
                        "base_values" => $allBaseValue,
                        "status" => $status,
                        "totals" => $allTotals,
                        "base_values_taxes" => $allBaseValueTaxes,
                        "taxes" => round($allTaxes, 2),
                        "discounts" => $allDiscounts,
                    ];
                }
            }
                
        }

        return $subsPlan;
    }

    public function getFileInvoiceFromFacturama($idInvoice, $format){
        $invoice = $this->facturama->post("cfdi/{$format}/issued/{$idInvoice}");
        return $invoice->Content;
    }
}

