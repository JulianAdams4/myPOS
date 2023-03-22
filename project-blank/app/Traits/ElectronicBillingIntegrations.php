<?php

namespace App\Traits;

use Log;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use App\OrderStatus;
use App\Order;
use App\Store;
use App\Invoice;
use App\Company;
use App\InvoiceItem;
use App\StoreIntegrationToken;
use App\CompanyElectronicBillingDetail;
use Carbon\Carbon;
use App\Traits\TimezoneHelper;
use App\Helper;
use Illuminate\Http\Request as HttpRequest;

trait ElectronicBillingIntegrations
{

  public function createDatilBundle($store, $invoice, $issuanceType){
    $company = $store->company;
    $companyBillingDetail = CompanyElectronicBillingDetail::where('company_id', $company->id)->where('data_for', 'like', 'datil')->first();
    if($companyBillingDetail){
    	$billing = $invoice->billing;
    	$order = $invoice->order;
    	// $orderDetails = $order->orderDetails;
    	$invoiceItems = $invoice->items;
    	//// CODIGO: 3 numeros que representan al store (ej: 001)
    	//// PUNTO EMISION: 3 numeros que identifican el punto de emision (ej: 001)
    	$stablishmentData = [
	    	'punto_emision' => $store->issuance_point,
	    	'codigo' => $store->code,
	    	'direccion' => $store->address
	    ];
	    if($companyBillingDetail->accounting_needed == 1){
	    	$contabilidad = true;
	    }else{
	    	$contabilidad = false;
	    }
	    //// RAZON SOCIAL (business name)
	    //// NOMBRE COMERCIAL (tradename)
	    $issuerData = [
	    	'ruc' => $company->TIN,
	    	'obligado_contabilidad' => $contabilidad,
	    	'contribuyente_especial' => $companyBillingDetail->special_contributor,
	    	'nombre_comercial' => $companyBillingDetail->tradename,
	    	'razon_social' => $companyBillingDetail->business_name,
	    	'direccion' => $companyBillingDetail->address,
	    	'establecimiento' => $stablishmentData
	    ];

	    $taxesData = [];
	    $items = [];
	    $totalSinImpuestos = 0.00;
	    $baseImponible12 = 0;
	    $baseImponible0 = 0;
	    $baseImponibleFoodService = 0;
	    $totalIva12 = 0;
	    foreach($invoiceItems as $product){
	    	$taxesDataWithRate = [];
	    	$productFoodService = false;
	    	if($product->has_iva){
	    		//// BaseImponible: subtotal
	    		//// 112 para transformar 1.12 (valor sin iva) y 100 (centavos)
	    		$baseImponible = Helper::bankersRounding($product->total / 1.12, 0);
	    		//// TotalIva: total sin base imponible
	    		$totalIva = Helper::bankersRounding(($product->total - $baseImponible), 0);
	    		$valorUnitario = Helper::bankersRounding($baseImponible / $product->quantity, 0);
	    		$tarifa = 12.00;
	    		$codigoPorcentaje = 2;

	    		$baseImponible12 = $baseImponible12 + $baseImponible;
	    		$totalIva12 = $totalIva12 + $totalIva;
	    	}else{
	    		if($invoice->food_service){
	    			$productFoodService = true;
	    			$baseImponible = Helper::bankersRounding($product->total, 0);
	    			$baseImponibleFoodService = $baseImponibleFoodService + $baseImponible;
	    			$baseImponible0 = $baseImponible0 + $baseImponible;
	    		}else{
		    		$baseImponible = Helper::bankersRounding($product->total, 0);
		    		$totalIva = 0;
		    		$valorUnitario = Helper::bankersRounding($baseImponible / $product->quantity, 0);
		    		$tarifa = 0.00;
		    		$codigoPorcentaje = 0;
		    		$baseImponible0 = $baseImponible0 + $baseImponible;
	    		}
	    	}
	    	//// VALOR: valor del total del impuesto (impuesto sobre el subtotal)
	    	//// BASE IMPONIBLE: valor del subtotal del producto (incluyendo cantidad)
	    	//// CODIGO (tipos de impuesto): 2: IVA, 3: ICE, 5: IRBPNR
	    	//// CODIGO PORCENTAJE (porcentaje del IVA): 0: 0%, 2: 12%, 3: 14%, 6: No objeto de impuesto, 7: Exento de IVA
	    	// $productTax = [
	    	// 	'base_imponible' => $baseImponible / 100,
	    	// 	'valor' => $totalIva / 100,
	    	// 	'codigo' => "". 2 ."",
	    	// 	'codigo_porcentaje' => "". $codigoPorcentaje .""
	    	// ];

	    	//// TARIFA: Porcentaje actual del impuesto expresado por un numero entre 0.00 y 100.00
	    	if(!$productFoodService){
		    	$productTaxWithRate = [
		    		'base_imponible' => $baseImponible / 100,
		    		'valor' => $totalIva / 100,
		    		'codigo' => "". 2 ."",
		    		'tarifa' => $tarifa,
		    		'codigo_porcentaje' => "". $codigoPorcentaje .""
		    	];
		    	//Hay un impuesto con tarifa en items
		    	array_push($taxesDataWithRate, $productTaxWithRate);
		    	$itemListed = [
			    	'cantidad' => $product->quantity,
			    	'precio_unitario' => $valorUnitario / 100,
			    	'descripcion' => $product->product_name,
			    	'precio_total_sin_impuestos' => $baseImponible / 100,
			    	'impuestos' => $taxesDataWithRate,
			    	'descuento' => 0.0
			    ];
			    array_push($items, $itemListed);
			}
	    	//Hay un impuesto sin tarifa en totales
	    	// array_push($taxesData, $productTax);

	    	//// Los items pueden tener IVA y otro impuesto, pero para facturacion electronica se necesita solo el IVA en el producto, por lo tanto se calcula el subtotal (no se usa el subtotal de INVOICE)
	    	$totalSinImpuestos = $totalSinImpuestos + $baseImponible;
	    }

	    if($invoice->food_service){
	    	$taxesDataWithRateFoodService = [];
	    	$productTaxWithRateFoodService = [
	    		'base_imponible' => $baseImponibleFoodService / 100,
	    		'valor' => 0,
	    		'codigo' => "2",
	    		'tarifa' => 0.00,
	    		'codigo_porcentaje' => "0"
	    	];
	    	//Hay un impuesto con tarifa en items
	    	array_push($taxesDataWithRateFoodService, $productTaxWithRateFoodService);
	    	$itemListed = [
		    	'cantidad' => 1,
		    	'precio_unitario' => $baseImponibleFoodService / 100,
		    	'descripcion' => "Servicio Alimenticio",
		    	'precio_total_sin_impuestos' => $baseImponibleFoodService / 100,
		    	'impuestos' => $taxesDataWithRateFoodService,
		    	'descuento' => 0.0
		    ];
		    array_push($items, $itemListed);
	    }

	    if($baseImponible12 > 0){
	    	$productTax = [
	    		'base_imponible' => $baseImponible12 / 100,
	    		'valor' => $totalIva12 / 100,
	    		'codigo' => "". 2 ."",
	    		'codigo_porcentaje' => "". 2 .""
	    	];
	    	array_push($taxesData, $productTax);
	    }
	    if($baseImponible0 > 0){
	    	$productTax = [
	    		'base_imponible' => $baseImponible0 / 100,
	    		'valor' => 0,
	    		'codigo' => "". 2 ."",
	    		'codigo_porcentaje' => "". 0 .""
	    	];
	    	array_push($taxesData, $productTax);
	    }
	    

	    $totalsData = [
	    	'total_sin_impuestos' => $totalSinImpuestos / 100,
	    	'impuestos' => $taxesData,
	    	'importe_total' => $invoice->total / 100,
	    	'propina' => 0.0,
	    	'descuento' => 0.0
	    ];
	    //// TIPO IDENTIFICACION: 04: RUC, 05: cedula, 06: pasaporte, 07: Venta consumidor final, 08: Identificacion del exterior, 09: placa
	    if(strlen($invoice->document) === 10){
	    	$IDType = "05";
	    }elseif(strlen($invoice->document) === 13){
	    	$IDType = "04";
	    }else{
	    	$IDType = "08";
	    }
	    $buyerData = [
	    	'email' => $invoice->email,
	    	'identificacion' => $invoice->document,
	    	'tipo_identificacion' => $IDType,
	    	'razon_social' => $invoice->name,
	    	'direccion' => $invoice->address,
	    	'telefono' => $invoice->phone
	    ];
	    //// MEDIO (formas de pago): efectivo, cheque, debito_cuenta_bancaria, transferencia, deposito_cuenta_bancaria, tarjeta_debito, dinero_electronico_ec, tarjeta_prepago, tarjeta_credito, otros, endoso_titulo
	    $paymentData = [];

	    $paymentItem = [
	    	'medio' => 'efectivo',
	    	'total' => $invoice->total / 100
	    ];

	    array_push($paymentData, $paymentItem);
	    //// AMBIENTE: 1 Pruebas, 2 Produccion
	    //// TIPO EMISION: 1 Normal, 2 emision por indisponibilidad
	    $bundleDatil = [
	  		'ambiente' => $companyBillingDetail->env_prod,
		  	'secuencial' => $store->bill_sequence,
		  	'emisor' => $issuerData,
		  	'moneda' => $store->currency,
		  	'totales' => $totalsData,
		  	'comprador' => $buyerData,
		  	'items' => $items,
		  	'fecha_emision' => TimezoneHelper::localizedNowDateForStore($store)->format('Y-m-d\TH:i:s.u\Z'),
		  	'tipo_emision' => $issuanceType,
		  	'pagos' => $paymentData
	    ];
	    return [
	    	'electronic_billing' => $bundleDatil
	    ];
    }else{

    }
    
  }

  public function postElectronicBillingDatil($store, $bundleDatil){
  	Log::info("Post Electronic Billing DATIL");
  	$datilToken = StoreIntegrationToken::where('store_id', $store->id)->where('integration_name', 'datil')->first();
  	$datilEndpoint = config('app.datil_api') . 'invoices/issue';
  	if($datilToken){
  		$client = new \GuzzleHttp\Client();
	    try {
	      $payload = $bundleDatil;
	      $headers = [
	        'X-Key' => $datilToken->token,
	        'X-Password' => $datilToken->password,
	        'Content-Type' => 'application/json'
	      ];
	      // Log::info(json_encode($payload['electronic_billing']));
	      $request = new Request('POST', $datilEndpoint, $headers, json_encode($payload['electronic_billing']));
	      // Log::info("Printing request");
	      // Log::info($bundleDatil);
	      $response = $client->send($request, ['timeout' => 5]);
	      Log::info("printing status code");
	      Log::info($response->getStatusCode());
	      Log::info($response->getBody());
	      // $promise = $client->sendAsync($request);
	      // $promise->then( function($value){
	      	$store->bill_sequence = $store->bill_sequence + 1;
	      	$store->save();
	      // 	Log::info("Success posting electronic billing to Datil by ".$store->name);
	      // 	Log::info($value);
	      // }, function ($exception) {
       //        Log::info('on error posting electronic billing to Datil');
       //        Log::info($exception->getMessage());
       //        Log::info($exception->getResponse()->getBody()->getContents());
       //        return $exception->getMessage();
       //    });
	  	}catch(\Exception $e) {
	     Log::info('error al enviar la factura electronica');
	     Log::info($e);
	    }
  	}
    
  }

  public function forceSendBillingsToDatil(HttpRequest $request){
  	if($request->password == "passingthrough"){
  		$store = Store::find($request->store_id);
	  	$secuencial = $request->secuencial;
	  	$idInvoices = $request->ids_invoices;
	  	$StoreIntegrationToken = StoreIntegrationToken::where('store_id', $store->id)->where('integration_name', 'datil')->first();
	  	if($StoreIntegrationToken){
	  		foreach($idInvoices as $idInvoice){
	  			$invoice = Invoice::find($idInvoice);
				if($invoice){
					$date_created = Carbon::parse($invoice->created_at);
	  				$issuanceType = 1;
					$bundleDatil = $this->createDatilBundle($store, $invoice, $issuanceType);
					$bundleDatil['electronic_billing']['fecha_emision'] = $date_created->format('Y-m-d\TH:i:s.u\Z');
	  				$bundleDatil['electronic_billing']['secuencial'] = $secuencial;
	  				$this->postElectronicBillingDatil($store, $bundleDatil);
	  				$secuencial = $secuencial + 1;
	  			}
	  		}
	  	}
  	}
  	
  }

}
