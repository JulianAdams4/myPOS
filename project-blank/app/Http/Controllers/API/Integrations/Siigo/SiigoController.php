<?php

namespace App\Http\Controllers\API\Integrations\Siigo;

use Log;
use App\Order;
use App\Store;
use Exception;
use App\Cities;
use App\Helper;
use App\Invoice;
use App\Payment;
use App\Product;
use App\Section;
use App\StoreTax;
use App\Component;
use Carbon\Carbon;
use App\MetricUnit;
use App\TaxesTypes;
use App\OrderDetail;
use App\StoreConfig;
use GuzzleHttp\Psr7;
use App\ProductDetail;
use GuzzleHttp\Client;
use App\CashierBalance;
use App\ComponentStock;
use App\ProductCategory;
use App\Traits\AuthTrait;
use App\Traits\TaxHelper;
use App\ComponentCategory;
use GuzzleHttp\Middleware;
use App\IntegrationsCities;
use App\StoreIntegrationId;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use App\StoreIntegrationToken;
use GuzzleHttp\RequestOptions;
use App\ComponentsIntegrations;
use App\StoreTaxesIntegrations;
use GuzzleHttp\MessageFormatter;
use App\IntegrationsPaymentMeans;
use App\ProductIntegrationDetail;
use App\AvailableMyposIntegration;
use App\IntegrationsDocumentTypes;
use App\InvoiceIntegrationDetails;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\ComponentCategoriesIntegrations;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use App\Jobs\Integrations\Siigo\SiigoDeleteProduct;
Use Illuminate\Support\Collection;


class SiigoController extends Controller
{
    use AuthTrait, TaxHelper;

    public $authUser;
    public $authEmployee;
    public $authStore;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
    }

    /**
	 *  Establish all connections with the Siigo API.
	 *
	 * @param string $method.
	 * @param string $url.
	 * @param string $params.
     * @param object $store.
     *
     * @return Array $data
     */
    public function makeRequest($method, $url, $params = null, $store = null){
        /*Se hace este ajuste en $store pensando en el Job que no puede reconocer la sesión */
        $store = empty($store) ? $this->authStore->id : $store->configs->store_id;
        
        /*Verificamos la vigencia del token */
        try{
            $integrationToken = $this->getToken($store);
        }catch (\Throwable $e){
            throw new Exception($e->getMessage());
        }

        $params = !empty($params) ? json_decode(stripslashes($params), true) : $params;

        try {
            $client = new Client();
            $response = $client->request($method, config('app.siigo_api').$url, [
                'headers' => [
                    'Authorization'             => "Bearer ".$integrationToken['token'],
                    'Ocp-Apim-Subscription-Key' => $integrationToken['password'],
                    'Content-Type'              => 'application/json'
                ],
                'json' => $params,
                'http_errors' => true
            ]);

            $data = json_decode($response->getBody(), true);
        
        } catch (RequestException $e) {

            /*Lines to debug the response in logs Files if fails*/
            $response = $e->getResponse();
            $exceptionMessage = $response->getBody()->getContents();
            $error_body = $response->getBody();

            Log::channel('siigo')->error("--------------------------------------------------------------");
            Log::channel('siigo')->error("Error in makeRequest: {$exceptionMessage}");
            Log::channel('siigo')->error("in store {$store}");
            Log::channel('siigo')->error("From: {$url}");
            Log::channel('siigo')->error("Data: ".json_encode($params));
            Log::channel('siigo')->error("--------------------------------------------------------------");
            throw new Exception($exceptionMessage);

        }

        return $data;
    }

    /**
	 *  Get Siigo token of an store and set in BD.
     * 
     * @param object $store.
     *
     * @return Array
     */
    public function getToken($store = null){
        $store = empty($store) ? $this->authStore->id : $store;
        /*  Recupera información sobre la integración de la tienda con RappiPay, para 
        *   determinar si se debe solicitar un nuevo token
        */
        $integrationToken = StoreIntegrationToken::where('store_id', $store)
        ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)->get();

        $actualToken = $integrationToken->where('type', 'billing')->first();

        /* Si token en bd no está vencido, ni vacío entonces devuelve $integrationToken->token*/
        if(!empty($actualToken->expires_in) && time() < $actualToken->expires_in){
            return [ "token" => $actualToken->token, "password" => $actualToken->password];
        }

        $getNewToken = $integrationToken->where('type', 'login')->first();

        /* Si token en bd se encuentra vacío o vencido entonces pide un nuevo token*/
        try {

            $client = new Client();

            $response = $client->request('POST', 'https://...', [
                'headers' => [
                    'Authorization' => 'Basic xxx',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'grant_type'=> "password",
                    'username'=> $getNewToken->token,
                    'password'=> $getNewToken->password,
                    'scope' => $getNewToken->scope
                ]
            ]);
            
            /* Guarda la información de respuesta */
            $data = json_decode($response->getBody());

        } catch (ClientException $e) {
            /*Lines to debug the response in logs Files if fails*/
            $response   = $e->getResponse();
            $error_body = json_decode($response->getBody());
            Log::channel('siigo')->error("--------------------------------------------------------------");
            Log::channel('siigo')->error("Error: {$error_body->error}");
            Log::channel('siigo')->error("in store {$store}");
            Log::channel('siigo')->error("Trying to get token");
            Log::channel('siigo')->error("--------------------------------------------------------------");
            throw new Exception("Error: {$error_body->error}");
        }

        /* Guarda la info del nuevo token en BD*/
        try {

            DB::beginTransaction();
            
            $newToken = $integrationToken->where('type', 'billing')->first();            
            $newToken->token = $data->access_token;
            $newToken->token_type = 'billing';
            $newToken->expires_in = time() + $data->expires_in;
            $newToken->save();

            DB::commit();

        } catch (Exception $e){
            Log::channel('siigo')->error("Error: ".$e->getMessage());
            DB::rollBack();
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Contacte con soporte.'
                ], 403
            );
        }

        return [ "token" => $newToken->token, "password" => $newToken->password];

    }

    /**
	 *  Get all products from Siigo
     *
     * @return Object
     */
    public function getAllProducts(){
        $allProducts = [];
        $i = 0;
        while (true) {

            try{
                $data = $this->makeRequest('GET','/Products/GetAll?numberPage='.$i.'&namespace='.config('app.siigo_namespace'));
            }catch (\Throwable $e){
                abort(403, $e->getMessage());
            }

            if(is_array($data) && count($data) > 0){
                array_push($allProducts, $data);
            }else{
                break;
            }

            $i = $i + 1;
        }

        return (object) [
            'status'  => 'Exito',
            'pages'   => count($allProducts),
            'data'    => $allProducts           
        ];
    }

    public function deleteAllProducts(){
        $store = $this->authStore;
        $allProducts = $this->getAllProducts();
        
        for ($i=0; $i <= $allProducts->pages - 1; $i++) {
            foreach($allProducts->data[$i] as $product){
                dispatch(new SiigoDeleteProduct($product['Id'], $store))->onConnection('backoffice');
            }
        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Solicitud exitosa'
            ], 200
        );
        
    }

    public function deleteSingleProductByJob($productId, $store){
        try{
            $this->makeRequest('DELETE','/Products/Delete/'.$productId.'?namespace='.config('app.siigo_namespace'), null, $store);
            Log::info('Producto '.$productId.' Eliminado.');
        }catch (\Throwable $e){
            Log::info('Error product '.$productId.' '.$e->getMessage());
            Log::info('Error product '.$productId.' '.$e->getFile());
            Log::info('Error product '.$productId.' '.$e->getLine());
        }
    }

    /**
     * Sync all products from Siigo in DB
     * 
     * @return Http json
     */
    public function syncProducts(){
        $store = $this->authStore;    
        $products = $this->getAllProducts();
        
        $siigoIntegration = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_SIIGO
        )->first();

        for ($i=0; $i <= $products->pages - 1; $i++) {

            foreach ($products->data[$i] as $product) {

                try {
                    
                    DB::beginTransaction();
                    
                    /* Asignamos los productos  al menú más antiguo (asumiendo que es local)*/
                    $olderMenu = Section::where('store_id', $store->id)->min('id');

                    /* Buscamos la categoría por defecto "Siigo" para esta company, si no existe, la crea*/
                    $productCategory = ProductCategory::firstOrCreate(
                        ['name' => 'Siigo' , 'company_id' => $store->store->company_id],
                        ['search_string' => 'Siigo', 'section_id' => $olderMenu, 'subtitle' => 'Siigo']
                    );

                    /* Busca si el producto ya está creado */
                    $findProduct = ProductIntegrationDetail::where('external_id', $product['Id'])->first();
                    
                    /* Convierte el nombre del producto a 25 caracteres*/
                    $invoiceName = $product["Description"];
                    if (strlen($product["Description"]) > 25) {
                        $invoiceName = mb_substr($product["Description"], 0, 22, "utf-8");
                        $invoiceName = $invoiceName . "...";
                    }

                    /* Si se encuentra la relación del producto lo actualiza, si no, lo crea nuevo */
                    if(empty($findProduct)){
                        $newProduct = new Product();
                    }else{
                        $newProduct = Product::where('id', $findProduct->product_id)->first();
                    }

                    $newProduct->name                  = $product["Description"];
                    $newProduct->product_category_id   = $productCategory->id;
                    $newProduct->search_string         = Helper::remove_accents($product["Description"]);
                    $newProduct->description           = $product["Comments"]; 
                    $newProduct->base_value            = Helper::getValueInCents($product["PriceList1"]);
                    $newProduct->status                = $product["State"];
                    $newProduct->invoice_name          = $invoiceName;
                    $newProduct->save();
                    
                    /* Sacamos la relación de los impuestos que recibimos de siigo */
                    $taxesRelations = StoreTaxesIntegrations::where('store_id', $store->id)
                    ->where('integration_id', $siigoIntegration->id)
                    ->whereIn('external_id', [$product['TaxAddID'], $product['TaxDiscID'], $product['TaxAdd2ID']])
                    ->select('id_tax')->get();

                    $taxes = [];
                    foreach ($taxesRelations as $tax) {
                        array_push($taxes, $tax->id_tax);
                    }
                    
                    /* Asociamos los impuestos al nuevo producto */
                    $newProduct->taxes()->sync($taxes);
                    
                    /* Agregamos detalles del producto */
                    if(empty($findProduct)){
                        $productDetail = new ProductDetail();
                        $productDetail->product_id  = $newProduct->id;
                    }else{
                        $productDetail = ProductDetail::where('product_id', $newProduct->id)->first();
                    }

                    $productDetail->store_id        = $store->id;
                    $productDetail->stock           = 0;
                    $productDetail->value           = empty(Helper::getValueInCents($product["PriceList1"])) ?: 0;
                    $productDetail->status          = $product["State"];
                    $productDetail->production_cost = 0;
                    $productDetail->income          = 0;
                    $productDetail->cost_ratio      = 0;
                    $productDetail->tax_by_value    = $product['TaxImpoValue'] ?:$product['TaxImpoValue'];
                    $productDetail->save();

                    /*Registramos la integración del producto */
                    if(empty($findProduct)){
                        $productIntegration = new ProductIntegrationDetail();
                        $productIntegration->product_id  = $newProduct->id;
                    }else{
                        $productIntegration = ProductIntegrationDetail::where('product_id', $newProduct->id)->first();
                    }

                    $productIntegration->integration_name   = AvailableMyposIntegration::NAME_SIIGO;
                    $productIntegration->name               = $product["Description"];
                    $productIntegration->price              = empty(Helper::getValueInCents($product["PriceList1"])) ?: 0;
                    $productIntegration->comments           = $product["Comments"];
                    $productIntegration->external_id        = $product["Id"];
                    $productIntegration->external_code      = $product["Code"];
                    $productIntegration->save();

                    DB::commit();

                } catch (Exception $e){
                    Log::channel('siigo')->error("Error: ".$e->getMessage());
                    DB::rollBack();
                    return response()->json(
                        [
                            'status' => 'Error',
                            'results' => 'Contacte con soporte.'
                        ], 403
                    );
                }

            }

        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Productos actualizados correctamente'
            ], 200
        );
    }

    /**
     * Get available inventory of a product from Siigo
     * 
     * @return Array
     * 
     * FUNCTION NOT USED YET
     */
    public function getProductBalance($id_product){
        try{
            $data = $this->makeRequest('GET', '/Products/GetProductBalance/'.$id_product.'?namespace='.config('app.siigo_namespace'));
        }catch (\Throwable $e){
            abort(403, $e->getMessage());
        }
        return $data;
    }

    /**
     * Get each one inventories from Siigo 
     * 
     * @return Object
     * 
     * FUNCTION NOT USED YET
    */
    public function getAllAccountGroups(){
        $store = $this->authStore;

        $AllAccountGroups = [];
        $i = 0;
        while (true) {

            try{
                $data = $this->makeRequest('GET', '/AccountGroups/GetAll?numberPage='.$i.'&namespace='.config('app.siigo_namespace'));
            }catch (\Throwable $e){
                abort(403, $e->getMessage());
            }

            if(is_array($data) && count($data) > 0){
                array_push($AllAccountGroups, $data);
            }else{
                break;
            }

            $i = $i + 1;
        }

        return (object) [
            'status'  => 'Exito',
            'pages'   => count($AllAccountGroups),
            'data'    => $AllAccountGroups           
        ];
    }

    public function getAllErpDocumentTypes(){
        $store = $this->authStore;

        $allErp = [];
        $i = 0;
        while (true) {

            try{
                $data = $this->makeRequest('GET', '/ERPDocumentTypes/GetAll?numberPage='.$i.'&namespace='.config('app.siigo_namespace'));
            }catch (\Throwable $e){
                abort(403, $e->getMessage());
            }

            if(is_array($data) && count($data) > 0){
                array_push($allErp, $data);
            }else{
                break;
            }

            $i = $i + 1;
        }

        return (object) [
            'pages'   => count($allErp),
            'data'    => $allErp           
        ];
    }

    /**
     * Sync all inventories from Siigo in DB
     * 
     * @return Http json
     * 
     * FUNCTION NOT USED YET
     */
    public function syncInventories(){
        $store = $this->authStore;
        $inventories = $this->getAllAccountGroups();

        $siigoIntegration = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_SIIGO
        )->first();

        for ($i=0; $i <= $inventories->pages - 1; $i++) {

            foreach ($inventories->data[$i] as $inventory) {

                $findInventory = ComponentCategoriesIntegrations::where('external_id', $inventory['Id'])->first();

                if(!empty($findInventory)){
                    $updateInventory = ComponentCategory::where('id', $findInventory->id_component_category)->first();
                    $updateInventory->name            = $inventory['Description'];
                    $updateInventory->search_string   = $inventory['Description'];
                    $updateInventory->save();
                    
                }else{
                    $newInventory = new ComponentCategory();
                    $newInventory->name             = $inventory['Description'];
                    $newInventory->search_string    = $inventory['Description'];
                    $newInventory->company_id       = $store->store->company_id;
                    $newInventory->save();

                    $writeRelations = new ComponentCategoriesIntegrations();
                    $writeRelations->integration_id         = $siigoIntegration->id;
                    $writeRelations->id_component_category  = $newInventory->id;
                    $writeRelations->external_id            = $inventory['Id'];
                    $writeRelations->save();
                }

            }

        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Inventarios actualizados correctamente'
            ], 200
        );
    }

    /**
	 *  Get all taxes from Siigo
     *
     * @return Object
     */
    public function getAllTaxes(){
        $store = $this->authStore;

        $allTaxes = [];
        $i = 0;
        while (true) {
            
            
            try {
                $data = $this->makeRequest('GET', '/Taxes/GetAll?numberPage='.$i.'&namespace='.config('app.siigo_namespace'));
            } catch (\Throwable $e) {
                abort(403, $e->getMessage());
            }

            if(is_array($data) && count($data) > 0){
                array_push($allTaxes, $data);
            }else{
                break;
            }

            $i = $i + 1;
        }

        return (object) [
            'status'  => 'Exito',
            'pages'   => count($allTaxes),
            'data'    => $allTaxes           
        ];
    }

    public function getAllTaxesFormatted(){

        $taxes = $this->getAllTaxes();
        $arrayNewFormattedTaxes = [];

        for ($i=0; $i <= $taxes->pages - 1; $i++) {
            foreach ($taxes->data[$i] as $tax) {
                array_push($arrayNewFormattedTaxes, $tax);
            }
        }

        return response()->json(
            [
                'status' => 'Exito',
                'taxes' => $arrayNewFormattedTaxes
            ], 200
        );
    }

    /**
     * Sync all taxes from Siigo in DB
     * 
     * @return Http json
     */
    public function syncTaxes(){
        $store = $this->authStore;
        $taxes = $this->getAllTaxes();

        $siigoIntegration = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_SIIGO
        )->first();
        
        for ($i=0; $i <= $taxes->pages - 1; $i++) { 

            foreach ($taxes->data[$i] as $tax) {
                // Log::channel('single')->info("Tax ".json_encode($tax));
                /* Determinamos si el impuesto actual es de tipo Cargo o Retención, e ingnoramos
                    los de tipo retención*/
                if(in_array($tax['TaxType'], TaxesTypes::TAXES_TYPE_DIS_CODES)){
                    continue;
                }

                try {
                    DB::beginTransaction();

                    /* Verificamos si existe la relación del impuesto con el impuesto externo*/
                    $findTax = StoreTaxesIntegrations::where('external_id', $tax['Id'])->first();
        
                    if(!empty($findTax)){

                        /* Si existe la relación, actuliza los campos*/
                        $updateTax = StoreTax::where('id', $findTax->id_tax)->first();
                        $updateTax->store_id      = $store->id;
                        $updateTax->name          = $tax['Description'];
                        $updateTax->percentage    = $tax['Percentage'];
                        $updateTax->tax_type      = $tax['TaxType'];
                        $updateTax->save();
                        
                        /* Y vamos al siguiente impuesto (siguiente iteración del foreach)*/
                        continue;

                    }
                        
                        /**
                        * Si no existe la relación:
                        * verificamos si existe un impuesto con los mismos datos para editarlo (evitando duplicación),
                        * si no, lo creamos como nuevo.
                        */

                        $updateOrAdd = StoreTax::where([
                            ['store_id', $store->id],
                            ['name', $tax['Description']],
                            ['percentage', $tax['Percentage']]
                        ])->first();

                        if(empty($updateOrAdd)){
                            $updateOrAdd = new StoreTax();
                        }

                        $updateOrAdd->store_id      = $store->id;
                        $updateOrAdd->name          = $tax['Description'];
                        $updateOrAdd->percentage    = $tax['Percentage'];
                        $updateOrAdd->tax_type      = $tax['TaxType'];
                        $updateOrAdd->save();

                        /*Luego registramos la relación del impuesto local con el impuesto en Siigo*/
                        $writeRelations = new StoreTaxesIntegrations();
                        $writeRelations->store_id       = $store->id;
                        $writeRelations->integration_id = $siigoIntegration->id;
                        $writeRelations->id_tax         = $updateOrAdd->id;
                        $writeRelations->external_id    = $tax['Id'];
                        $writeRelations->save();

                    DB::commit();

                } catch (Exception $e){
                    Log::channel('siigo')->error("Error: ".$e->getMessage());
                    DB::rollBack();
                    return response()->json(
                        [
                            'status' => 'Error',
                            'results' => 'Contacte con soporte.'
                        ], 403
                    );
                }
                    
            }
        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Impuestos actualizados correctamente'
            ], 200
        );
    }

    /**
	 * Get all payment means from Siigo
     *
     * @return Object
     * 
     * FUNCTION NOT USED YET -> USING SEEDER
     */
    public function getAllPaymentMeans(){
        $store = $this->authStore;

        $AllAccountGroups = [];
        $i = 0;
        while (true) {

            try{
                $data = $this->makeRequest('GET', '/AccountGroups/GetAll?numberPage='.$i.'&namespace='.config('app.siigo_namespace'));
            }catch(\Throwable $e) {
                abort(403, $e->getMessage());
            }

            if(is_array($data) && count($data) > 0){
                array_push($AllAccountGroups, $data);
            }else{
                break;
            }

            $i = $i + 1;
        }

        return (object) [
            'status'  => 'Exito',
            'pages'   => count($AllAccountGroups),
            'data'    => $AllAccountGroups           
        ];
    }

    /**
     * Create a new invoice in Siigo
     * 
     * @param object $invoice
     * @param object $store
     * 
     * @return Array
     */
    public function createInvoice($invoice, $store){
        /* Hacemos explode para separar nombres y apellidos*/
        $explodeName = explode(" ", $invoice->name);
        $firstName = null;
        $surname = null;
        
        if (count($explodeName) >= 2) {
            list($firstName, $surname) = $explodeName;
        } else {
            list($firstName) = $explodeName;
        }
        
        /* Verificamos si existen los siguientes objetos, si no, los traemos para procesar la factura */
        if (!isset($invoice->productTaxes) || !isset($invoice->billing) || !isset($invoice->items) || !isset($invoice->tax_details)) { 
            $invoice = $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');
            
            /*Añadimos los el objeto $invoice->productTaxes si no existe*/
            if(!isset($invoice->productTaxes)){
                $taxValues = $this->getTaxValuesFromDetails($store, $invoice->order->orderDetails);
                $invoice->noTaxSubtotal = $taxValues['no_tax_subtotal'];
                $invoice->productTaxes = $taxValues['product_taxes'];
            }
        }

        /* Determinamos la ciudad */
        if($invoice->document == '9999999999999'){
            /* Si es CONSUMIDOR FINAL, como ciudad asignamos la de la tienda */
            $city = Cities::where('id', $store->city_id)->first();
            
            $city = IntegrationsCities::where('name_integration', AvailableMyposIntegration::NAME_SIIGO)
            ->where('city_name', $city->name)->first();
        }else{
            $city = IntegrationsCities::where('name_integration', AvailableMyposIntegration::NAME_SIIGO)
            ->where('city_code', $invoice->billing->city)->first();
        }

        if(is_null($city)){
            Log::channel('siigo')->info("El objecto city es null. invoice object: ".json_encode($invoice));
            return null;
        }

        if(!empty($invoice->billing->document_type)){
            $documentCode = IntegrationsDocumentTypes::where('id', $invoice->billing->document_type)
            ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)->first()->document_code;
        }

        $account = [
            "IsSocialReason" => $invoice->billing->is_company == 1 ? true : false,
            "FullName" => $invoice->billing->is_company == 1 ? $invoice->name : "", //si es empresa, se pone todo el nombre
            "FirstName" => $invoice->billing->is_company == 0 ? $firstName : "",
            "LastName" => $invoice->billing->is_company == 0 ? $surname : "",
            "IdTypeCode" => !empty($invoice->billing->document_type) ? $documentCode : 13,
            "Identification" => $invoice->billing->document,
            "CheckDigit" => $invoice->billing->is_company == 1 ? $invoice->billing->company_checkdigit : 0,
            "BranchOffice" => 0, //no obligatorio
            "IsVATCompanyType" => $invoice->billing->is_company == 1 ? $invoice->billing->company_pay_iva : 0,
            "Address" => $invoice->billing->document == '9999999999999' ? '-' : $invoice->billing->address,
            "Phone" => [
                "Indicative" => 0, //No obligatorio
                "Number" => $invoice->billing->phone,
                "Extention" => 0 //No obligatorio
            ],
            "City" => [
		        "CountryCode" => $city->country_code,
		        "StateCode" => $city->state_code,
		        "CityCode" => $city->city_code
            ]
        ];

        $contact = [
            "Code" => 0,
            "Phone1" => [
                "Indicative" => 0,
                "Number" => 0,
                "Extention" => 0
            ],
            "Mobile" => [
                "Indicative" => 0,
                "Number" => 0,
                "Extention" => 0
            ],
            "EMail" => !$invoice->billing->email ? "consumidor@final.com" : $invoice->billing->email,
            "FirstName" => $firstName,
            "LastName" => $surname,
            "IsPrincipal" => true,
            "Gender" => 0,
            "BirthDate" => ""
        ];

        $totalTaxesByValue = 0; //Totaliza todos los impuestos que están definidos por valor 
        $totalTaxVAT = 0; 
        
        foreach ($invoice->productTaxes as $productTax) {

            foreach($invoice->taxDetails as $tax){

                if($productTax['name'] === $tax['tax_name'] && $productTax['subtotal'] != 0){
                    //Sumamos todos los impuestos cobrados por valor
                    if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "impoconsumo", $productTax['tax_type'], "add")) {
                        $totalTaxesByValue += $tax['subtotal'];
                    }

                    //Sumamos todos los impuestos de tipo IVA
                    if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "iva", $productTax['tax_type'], "add")){
                        $totalTaxVAT += $tax['subtotal'];
                    }
                }
            }
        }

        $items = [];

        $specifications = Helper::getOrderSpecifications($invoice->order);
        $observations = "";
        if(!empty($specifications)){
            foreach($specifications as $specification){
                if(empty($specification['instructions'])){
                    continue;
                }

                $observations .= "\\\n";
                $observations .= str_replace("\n", " \\\n", $specification['product'])."\\\n";
                $observations .= str_replace("\n", " \\\n", $specification['instructions'])."\\\n";
            }
        }

        foreach ($invoice->order->orderDetails as $item) {
            $taxAddValue = 0;
            $taxAdd2Value = 0;
            $externalForTaxAdd2 = -1;
            $externalForTaxAdd = -1;

            $valueDiscountByItem = 0;            
            $baseValue = $item->base_value * $item->quantity;

            if($item->order->discount_percentage > 0){
                // $valueDiscountByItem = $baseValue * $item->order->discount_percentage / 100;
                $valueDiscountByItem = $item->order->discount_value;
            }
            
            $baseValueWithDiscount = $baseValue - $valueDiscountByItem;

            /* Recorremos todos los taxes de este item */
            foreach($item['tax_values']['tax_details'] as $tax){

                $rateTax = $tax['tax']['percentage'] / 100;
                // $baseValueTax = $baseValue - $valueDiscountByItem;
                $totalTax = $baseValueWithDiscount * $rateTax;

                //Sumamos todos los impuestos cobrados por valor
                if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "impoconsumo", $tax['tax']['tax_type'], "add")) {
                    // $taxAdd2Value += Helper::bankersRounding($tax['subtotal']);
                    $taxAdd2Value += $totalTax;

                    /*Conseguimos el ID externo del impuesto en este producto*/
                    $externalForTaxAdd2 = StoreTaxesIntegrations::where('id_tax', $tax['tax']['id'])->first();

                }else{
                    //Valor que debe recibir siigo para indicar que no tiene impuesto
                    $externalForTaxAdd2 = -1;
                }

                //Sumamos todos los impuestos de tipo IVA
                if (Helper::checkTaxType(TaxesTypes::TAXES_CO, "iva", $tax['tax']['tax_type'], "add")){
                    // $taxAddValue += Helper::bankersRounding($tax['subtotal']);
                    $taxAddValue += $totalTax; 

                    /*Conseguimos el ID externo del impuesto en este producto*/
                    $externalForTaxAdd = StoreTaxesIntegrations::where('id_tax', $tax['tax']['id'])->first();
                    
                }else{
                    //Valor que debe recibir siigo para indicar que no tiene impuesto
                    $externalForTaxAdd = -1;
                }
            }

            $grossValue = Helper::bankersRounding($baseValue / 100, 0);
            $discountValue = Helper::bankersRounding($valueDiscountByItem / 100, 0);

            $newItem = [
                "ProductCode"=> (int) $item->productDetail->product->integrations->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)->first()['external_code'],
                "Description"=> $item->name_product,
                "GrossValue"=> $grossValue,
                "BaseValue"=> $grossValue - $discountValue,
                "Quantity"=> $item->quantity,
                "UnitValue"=> Helper::bankersRounding($item->base_value / 100, 0),
                "DiscountValue"=> $discountValue,
                "DiscountPercentage"=> 0.0,
                "TaxAddName"=> !isset($externalForTaxAdd->name) ? "" : $externalForTaxAdd->name, //impuesto IVA
                "TaxAddId"=> !isset($externalForTaxAdd->external_id) ? (int) $externalForTaxAdd : (int) $externalForTaxAdd->external_id,
                "TaxAddValue"=> Helper::bankersRounding($taxAddValue / 100, 0),
                "TaxAddPercentage"=> !isset($externalForTaxAdd->percentage) ? 0 : $externalForTaxAdd->percentage,
                "TaxDiscountName"=> "", //impuestos relacionados con retenciones
                "TaxDiscountId"=> -1,
                "TaxDiscountValue"=> 0.0,
                "TaxDiscountPercentage"=> 0.0,
                "TotalValue"=> Helper::bankersRounding( ($baseValueWithDiscount + $taxAddValue + $taxAdd2Value) / 100, 0),
                "ProductSubType"=> 0,
                "TaxAdd2Name"=> !isset($externalForTaxAdd2->name) ? "" : $externalForTaxAdd2->name, //impoconsumo
                "TaxAdd2Id"=> !isset($externalForTaxAdd2->external_id) ? (int) $externalForTaxAdd2 : (int) $externalForTaxAdd2->external_id,
                "TaxAdd2Value"=> Helper::bankersRounding($taxAdd2Value / 100, 0),
                "TaxAdd2Percentage"=> !isset($externalForTaxAdd2->percentage) ? 0 : $externalForTaxAdd2->percentage,
                "WareHouseCode"=> "",
                "SalesmanIdentification"=> $item->order->employee->identification
            ];

            array_push($items, $newItem);
        }

        //verificamos si hay propina
        if(isset($invoice->tip) && $invoice->tip > 0){
            $productCodeForTip = StoreIntegrationId::where('type', 'ProductForTips')
                                    ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)
                                    ->where('store_id', $store->id)
                                    ->first();
            
            $invoice->total += $invoice->tip;
            $invoice->subtotal += $invoice->tip;

            $newItem = [
                "ProductCode"=> $productCodeForTip->external_store_id,
                "Description"=> "Propina",
                "GrossValue"=> Helper::bankersRounding($invoice->tip / 100 , 0),
                "BaseValue"=> Helper::bankersRounding($invoice->tip / 100 , 0),
                "Quantity"=> 1,
                "UnitValue"=> Helper::bankersRounding($invoice->tip / 100 , 0),
                "DiscountValue"=> 0.0,
                "DiscountPercentage"=> 0.0,
                "TaxAddName"=> "", //impuesto IVA
                "TaxAddId"=> -1,
                "TaxAddValue"=> 0.0,
                "TaxAddPercentage"=> 0.0,
                "TaxDiscountName"=> "", //impuestos relacionados con retenciones
                "TaxDiscountId"=> -1,
                "TaxDiscountValue"=> 0.0,
                "TaxDiscountPercentage"=> 0.0,
                "TotalValue"=> Helper::bankersRounding($invoice->tip / 100 , 0),
                "ProductSubType"=> 0,
                "TaxAdd2Name"=> "", //impoconsumo
                "TaxAdd2Id"=> -1,
                "TaxAdd2Value"=> 0.0,
                "TaxAdd2Percentage"=> 0.0,
                "WareHouseCode"=> "",
                "SalesmanIdentification"=> $item->order->employee->identification
            ];

            array_push($items, $newItem);
        }

        $payments = [];
        $totalPayout = 0;
        foreach ($invoice->order->payments as $payout) {

            $totalPayout += $payout->total + $payout->tip;
            $externalPaymentMean = IntegrationsPaymentMeans::where('name_integration', AvailableMyposIntegration::NAME_SIIGO)
                ->where('local_payment_mean_code', $payout->type)->first();

            $payoutObj = [
                "PaymentMeansCode" => (int) $externalPaymentMean->external_payment_mean_code,		
                "Value" => Helper::bankersRounding(($payout->total + $payout->tip) / 100, 0),		
                "DueDate" => "",		
                "DueQuote" => 0
            ];

            array_push($payments, $payoutObj);
        }

        $object = [
            "Header" => [
                "Id" => (Int) $invoice->id, //por default
                "DocCode" => $store->configs->erp_doctype,
                "Number" => (Int) ($store->bill_sequence - 1), //por default
                "EmailToSend" => $invoice->billing->is_company == 1 ? $invoice->billing->email : "", //No obligatorio
                "DocDate" => Carbon::createFromFormat('Y-m-d H:i:s', $invoice->created_at)->format('Ymd'),
                "MoneyCode" => "", //no obligatorio
                "ExchangeValue" => 0.0, //No obligatorio
                "DiscountValue" => Helper::bankersRounding($invoice->discount_value / 100, 0),
                "VATTotalValue" => Helper::bankersRounding($totalTaxVAT / 100, 0),
                "ConsumptionTaxTotalValue" => Helper::bankersRounding($totalTaxesByValue / 100, 0),
                "TaxDiscTotalValue" => 0.0,
                "RetVATTotalID" => 0.0,
                "RetVATTotalPercentage" => 0.0,
                "RetVATTotalValue" => 0.0,
                "RetICATotalID" => 0.0,
                "RetICATotalValue" => 0.0,
                "RetICATotaPercentage" => 0.0,
                "TotalValue" => Helper::bankersRounding($invoice->total / 100, 0),
                "TotalBase" => Helper::bankersRounding($invoice->subtotal / 100, 0),
                "SalesmanIdentification" => $invoice->order->employee->identification,
                "Observations" => $observations,
                "Account" => $account,
                "Contact" => $contact,
                "CostCenterCode" => "",
                "SubCostCenterCode" => ""
            ],
            "Items" => $items,
            "Payments" => $payments
        ];
        
        return $object;
    }

    /**
     * Called by a Job to set and send a new invoice to Siigo
     * 
     * @param object $invoice
     * @param object $sotre
     * 
     * @return Boolean
     * @see App\Jobs\Integrations\Siigo\SiigoSaveInvoice
     */
    public function syncNewInvoice($invoice, $store){
        $newInvoice = $this->createInvoice($invoice, $store);

        if(is_null($newInvoice)){
            Log::channel('siigo')->info("New Invoice of create invoice is null");
            return null;
        }
        
        $saveInvoiceDetails = InvoiceIntegrationDetails::where('invoice_id', $invoice->id)->first();

        if(!$saveInvoiceDetails){
            $saveInvoiceDetails = new InvoiceIntegrationDetails;
        }

        try{
            $createInSiigo = $this->makeRequest('POST', '/Invoice/Save?namespace='.config('app.siigo_namespace'), json_encode($newInvoice), $store);
            

            $saveInvoiceDetails->invoice_id = $invoice->id;
            $saveInvoiceDetails->external_id = $createInSiigo['Header']['Id'];
            $saveInvoiceDetails->integration = AvailableMyposIntegration::NAME_SIIGO;
            $saveInvoiceDetails->status = 1;
            $saveInvoiceDetails->save();

            Log::channel('siigo')->info("--------------------------------------------------------------");
            Log::channel('siigo')->info("Invoice saved correctly in Siigo");
            Log::channel('siigo')->info("Response Siigo: ".json_encode($createInSiigo));
            Log::channel('siigo')->info("For store {$store->id}");
            Log::channel('siigo')->info("--------------------------------------------------------------");

        }catch(\Throwable $e) {
            Log::channel('siigo')->error("--------------------------------------------------------------");
            Log::channel('siigo')->error("Error trying to save a new invoice in Siigo");
            Log::channel('siigo')->error("Invoice from myPOS: ".$invoice);
            Log::channel('siigo')->error("Error: ".json_encode($e->getMessage()));
            Log::channel('siigo')->error("File: ".json_encode($e->getFile()));
            Log::channel('siigo')->error("Line: ".json_encode($e->getLine()));
            Log::channel('siigo')->error("in store {$store->id}");
            Log::channel('siigo')->error("--------------------------------------------------------------");

            $saveInvoiceDetails->invoice_id = $invoice->id;
            $saveInvoiceDetails->integration = AvailableMyposIntegration::NAME_SIIGO;
            $saveInvoiceDetails->status = 0;
            $saveInvoiceDetails->observations = $e->getMessage();
            $saveInvoiceDetails->save();
        }
    }

    public function syncAll(){
        $this->syncTaxes();
        $this->syncProducts();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Impuestos y productos actualizados correctamente'
            ], 200
        );
    }

    /**
     * Sync all "components" from Siigo in DB
     * 
     * @return Http json
     * 
     * FUNCTION NOT USED YET
     */
    public function syncComponents(){
        $store = $this->authStore;
        $products = $this->getAllProducts();

        $siigoIntegration = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_SIIGO
        )->first();

        for ($i=0; $i <= $products->pages - 1; $i++) {

            foreach ($products->data[$i] as $product) {

                try {
                    
                    DB::beginTransaction();

                    if(!$product['IsInventoryControl']){
                        /*Si el producto no tiene control de inventario, entonces lo ignoramos*/
                        continue;
                    }
                    
                    /* determinamos la categoría a la que pertenece este producto */
                    $productHasComponentCategory = ComponentCategoriesIntegrations::where('external_id', $product['AccountGroupID'])->first();
                    
                    /* Buscamos si existe una relación de componente con este producto*/
                    $findComponent = ComponentsIntegrations::where('external_id', $product['Id'])->first();
                    
                    /* Si la la relación ya existe entonces la editamos, si no, la creamos*/
                    if(!empty($findComponent)){

                        $updateInventory = ComponentCategory::where('id', $findInventory->id_component_category)->first();
                        $updateInventory->name            = $inventory['Description'];
                        $updateInventory->search_string   = $inventory['Description'];
                        $updateInventory->save();

                        continue;
                    }

                    /*Buscamos unidad de medida en la tienda, si no está, la crea*/
                    $metricUnit = MetricUnit::where('company_id', $store->store->company_id)->get();

                    if($product['MeasureUnit'] != null){

                        $findUnit = $metricUnit->where('name', $product['MeasureUnit'])->first();
                        if(empty($findUnit)){
                            $findUnit = new MetricUnit();
                            $findUnit->name         = $product['MeasureUnit'];
                            $findUnit->short_name   = $product['MeasureUnit'];
                            $findUnit->company_id   = $store->store->company_id;
                            $findUnit->save();
                        }

                    }else{

                        $findUnit = $metricUnit->where('name', 'Unidades')->first();
                        if(empty($findUnit)){
                            $findUnit = new MetricUnit();
                            $findUnit->name         = "Unidades";
                            $findUnit->short_name   = "unidades";
                            $findUnit->company_id   = $store->store->company_id;
                            $findUnit->save();
                        }

                    }

                    /* Almacenamos información como componente*/
                    $newComponent = new Component();
                    $newComponent->name                     = $product['Description'];
                    $newComponent->component_category_id    = $productHasComponentCategory->id_component_category;
                    $newComponent->cost              = Helper::getValueInCents(0);
                    $newComponent->SKU               = null;
                    $newComponent->metric_unit_id    = $findUnit->id;
                    $newComponent->save();


                    /* Creamos el stock del componente */
                    $saveStock = new ComponentStock();
                    $saveStock->stock                   = $this->getProductBalance($product['Id']);
                    $saveStock->alert_stock             = 0;
                    $saveStock->store_id                = $store->id;
                    $saveStock->component_id  = $newComponent->id;
                    $saveStock->save();

                    /* Registramos la relación del componente con el producto de Siigo*/
                    $writeRelations = new ComponentsIntegrations();
                    $writeRelations->integration_id = $siigoIntegration->id;
                    $writeRelations->id_component   = $newComponent->id;
                    $writeRelations->external_id    = $product['Id'];
                    $writeRelations->save();

                    DB::commit();

                } catch (Exception $e){
                    Log::channel('siigo')->error("Error: ".$e->getMessage());
                    DB::rollBack();
                    return response()->json(
                        [
                            'status' => 'Error',
                            'results' => 'Contacte con soporte.'
                        ], 403
                    );
                }

            }

        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Inventarios actualizados correctamente'
            ], 200
        );
    }

    public function updateTaxRelation(Request $request){
        $store = $this->authStore;
        try {
            DB::beginTransaction();

            $siigoIntegration = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_SIIGO
            )->first();

            StoreTaxesIntegrations::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'id_tax' => $request->internal, 
                    'integration_name' => AvailableMyposIntegration::NAME_SIIGO,
                ],
                [
                    'external_id' => $request->external['Id'],
                    'integration_id' => $siigoIntegration->id
                ]
            );

            StoreTax::where('id', $request->internal)->update(['tax_type' => $request->external['TaxType']]);

            DB::commit();

        } catch (Exception $e){
            Log::channel('siigo')->error("Error: ".$e->getMessage());
            DB::rollBack();
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Error. Contacte con soporte.'
                ], 403
            );
        }

        return response()->json(
            [
                'status' => 'Exito',
                'results' => 'Relación de impuestos guardada correctamente.'
            ], 200
        );

    }   

    public function prepareProductsToUpload($limitRows, $actualRow){
        $store = $this->authStore;

        $menus = Section::where(
            'store_id',
            $store->id
        )
        ->with([
            'categories' => function ($category) {
                $category->select(
                    'id',
                    'section_id'
                )
                ->where('status', 1)
                ->with([
                    'products' => function ($product) {
                        $product->where('status', 1);
                    }
                ]);
            }
        ])
        ->get();

        $productsToSync = [];
        
        $taxRelations = StoreTaxesIntegrations::where('store_id', $store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)
            ->get();

        foreach ($menus as $menu) {

            // Recorre las categorías de un menú
            foreach ($menu['categories'] as $category) {
                
                // Recorre los productos de una categoría
                foreach ($category['products'] as $product) {

                    $productObject = [
                        'Id' => 0,
                        "Code"=> $product['id'],
                        "Description"=> ucwords($product['search_string'])." - ".ucwords($product['category']['search_string'])." - ". ucwords(Helper::remove_accents($menu['name'])),
                        "ProductTypeKey"=> "ProductType_Product",
                        "State"=> $product['status'],
                        "IsInventoryControl"=> false,
                        "TaxAddID" => 0,
                        "TaxDiscID"=> 0,
                        "IsIncluded"=> true,
                        "PriceList1"=> Helper::bankersRoundingUp($product['base_value'], 0) / 100,
                        "AccountGroupID"=> $store->integrationIds
                                            ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)
                                            ->where('type', 'AccountGroupProducts')
                                            ->first()->external_store_id,
                        "TaxAdd2ID"=> 0,
                        "TaxImpoValue"=> 0.0
                    ];

                    //Verificamos los impuestos
                    foreach ($product['taxes'] as $tax) {

                        if (
                            Helper::checkTaxType(TaxesTypes::TAXES_CO, "iva", $tax['tax_type'], "add") || 
                            Helper::checkTaxType(TaxesTypes::TAXES_CO, "impoconsumo", $tax['tax_type'], "add")
                        ) {

                            $taxExternalId = (Int) $taxRelations->where('id_tax', $tax['id'])->first()['external_id'];

                            if($productObject['TaxAddID'] === 0){
                                $productObject['TaxAddID'] = $taxExternalId;

                                //Determinamos si el impuesto es incluido u opcional y esto se toma encuenta únicamente en el primer impuesto 
                                if($tax['type'] == "included"){
                                    $productObject['IsIncluded'] = true;
                                }elseif ($tax['type'] == "additional") {
                                    $productObject['IsIncluded'] = false;
                                }

                            }else{
                                $productObject['TaxAdd2ID'] = $taxExternalId;
                            }
                        }

                        if (
                            Helper::checkTaxType(TaxesTypes::TAXES_CO, "reteica", $tax['tax_type'], "dis") ||
                            Helper::checkTaxType(TaxesTypes::TAXES_CO, "retefuente", $tax['tax_type'], "dis") ||
                            Helper::checkTaxType(TaxesTypes::TAXES_CO, "reteiva", $tax['tax_type'], "dis")
                        ) {
                            $productObject['TaxDiscID'] = (Int) $taxRelations->where('id_tax', $tax['id'])->first()['external_id'];
                        }
                    }


                    array_push($productsToSync, $productObject);

                }

            }

        }

        $collectProductsToSync = collect($productsToSync);
        $returnProduct = $collectProductsToSync->slice($actualRow, $limitRows);

        return [
                    "products" => $returnProduct,
                    "count_res_products" => count($productsToSync),
                    "total_products" => count($productsToSync),
                    "key_last_index" => $returnProduct->keys()->last()
                ];
    }

    public function checkProductHasTaxes(){
        $store = $this->authStore;

        $menus = Section::where(
            'store_id',
            $store->id
        )
        ->with([
            'categories' => function ($category) {
                $category->select(
                    'id',
                    'section_id'
                )
                ->where('status', 1)
                ->with([
                    'products' => function ($product) {
                        $product->where('status', 1);
                    }
                ]);
            }
        ])
        ->get();

        $taxRelations = StoreTaxesIntegrations::where('store_id', $store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)
            ->get();
        
        $productsNeedTaxes = [];

        foreach ($menus as $menu) {
            
            foreach ($menu['categories'] as $category) {
            
                foreach ($category['products'] as $product) {
                    
                    foreach ($category['products'] as $product) {
                        
                        foreach ($product['taxes'] as $tax) {

                            $taxExternalId = $taxRelations->where('id_tax', $tax['id'])->first()['external_id'];
                            
                            if(!$taxExternalId){

                                $reportTax = [
                                    "tax_id" => $tax['id'],
                                    "tax_name" => $tax['name'],
                                    "product_name" => $product['name'],
                                    "product_category_name" => $product['category']['name'],
                                ];
    
                                array_push($productsNeedTaxes, $reportTax);
                            }
                        }

                    }

                }

            }

        }
        return $productsNeedTaxes;
    }

    public function uploadProducts(Request $request){
        $store = $this->authStore;
        $products = $this->prepareProductsToUpload($limitRows = 10, $request->actualRow);

        $checkProductTaxes = $this->checkProductHasTaxes();

        if(count($checkProductTaxes) > 0){
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'Todos los impuestos involucrados en los productos a sincronizar 
                                    deben estar emparejados con su respectivo impuesto en Siigo.',
                    'products_need_taxes' => $checkProductTaxes
                ], 404
            );
        }

        foreach ($products['products'] as $product) {

            $findProduct = ProductIntegrationDetail::where('product_id', $product['Code'])
                ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)->first();

            try{

                if($findProduct){
                    
                    $product['Id'] = $findProduct->external_id;
                    $reqCreateProduct = $this->makeRequest('POST', '/Products/Update?namespace='.config('app.siigo_namespace'), json_encode($product), $store);
                }else{
                    $reqCreateProduct = $this->makeRequest('POST', '/Products/Create?namespace='.config('app.siigo_namespace'), json_encode($product), $store);
                }

            }catch(\Throwable $e) {
                Log::channel('siigo')->error("--------------------------------------------------------------");
                Log::channel('siigo')->error("Error trying to save a new product in Siigo");
                Log::channel('siigo')->error("Product from myPOS: ".json_encode($product));
                Log::channel('siigo')->error("Error: ".json_encode($e->getMessage()));
                Log::channel('siigo')->error("in store {$store->id}");
                Log::channel('siigo')->error("--------------------------------------------------------------");
                return $e->getMessage();
            }

            ProductIntegrationDetail::updateOrCreate(
                [
                    'product_id' => $product['Code'],
                    'integration_name' => AvailableMyposIntegration::NAME_SIIGO,
                ],
                [
                    'name' => $product['Description'],
                    'price' => $product['PriceList1'],
                    'external_id' => $reqCreateProduct['Id'],
                    'external_code' => $reqCreateProduct['Code'],
                ]
            );
        }

        return response()->json(
            [
                'status' => 'Exito',
                "actual_row" => $request->actualRow,
                "next_row" => $request->actualRow + $limitRows,
                "percent" => round(($products['key_last_index'] *  100) / $products['total_products']) + 1,
                "total_products" => $products['total_products'],
                "products" => $products['products'],
            ], 200
        );

    }

    public function syncCashier($cashier, $store){
        $ordersFromCashier = CashierBalance::where('id', $cashier)->first();

        foreach ($ordersFromCashier->orders as $order) {
            
            $invoice = $order->invoice;
            
            // Verifica si la factura ya fue enviada exitosamente
            $checkInvoice = InvoiceIntegrationDetails::where('invoice_id', $invoice->id)
            ->where('status', 1)
            ->first();

            if($checkInvoice){
                continue;
            }

            $invoice->load('order.orderIntegrationDetail', 'billing', 'items', 'taxDetails');
            
            /*Crea y envía la factura a Siigo*/
            $this->syncNewInvoice($invoice, $store);

        }
    }

    public function retryFailedInvoices(){
    }

    public function setIntegration(Request $request){
        $store = $this->authStore;

        try {
            // DB::beginTransaction();
            //configuamos tipo de comprobante para facturas de ventas
            $storeConfig = StoreConfig::where('id', $store->id)->update([
                "erp_doctype" => $request->ERPDocumentType
            ]);

            $integrationBillingRow = StoreIntegrationToken::where(
                [
                    'integration_name' => 'siigo',
                    'type' => 'billing',
                    'token_type' => 'billing',
                    'store_id' => $store->id,
                    'password' => $request->conexion['subscriptionKey'],
                ]
            );

            if(!$integrationBillingRow->first()){
                $integrationBillingRow = StoreIntegrationToken::create(
                    [
                        'integration_name' => 'siigo',
                        'type' => 'billing',
                        'token_type' => 'billing',
                        'store_id' => $store->id,
                        'token' => '0',
                        'password' => $request->conexion['subscriptionKey'],
                    ]
                );
            }else{
                $integrationBillingRow = StoreIntegrationToken::where(
                    [
                        'integration_name' => 'siigo',
                        'type' => 'billing',
                        'token_type' => 'billing',
                        'store_id' => $store->id
                    ]
                )->update([
                    'password' => $request->conexion['subscriptionKey'],
                    'expires_in' => 0,
                ]);
            }

            $integrationLoginRow = StoreIntegrationToken::updateOrCreate(
                [
                    'integration_name' => 'siigo',
                    'type' => 'login',
                    'token_type' => 'password',
                    'store_id' => $store->id
                ],
                [
                    'token' => $request->conexion['userName'],
                    'password' => $request->conexion['password'],
                    'scope' => $request->conexion['scopes']
                ]
            );
            $this->getAllAccountGroups();

            // DB::commit();
        } catch (\Throwable $th) {
            // DB::rollBack();
            Log::info($th->getMessage());
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'No fue posible conectar con Siigo.'
                ], 409
            );
        }

        if(count($request->accountGroups) > 0){
            foreach ($request->accountGroups as $accountGroup) {

                if(is_null($accountGroup['groupId'])){
                    continue;
                }

                $siigoIntegration = AvailableMyposIntegration::where(
                    'code_name',
                    AvailableMyposIntegration::NAME_SIIGO
                )->first();

                $storeIntegrationId = StoreIntegrationId::updateOrCreate(
                    [
                        'integration_name' => 'siigo',
                        'type' => $accountGroup['name'],
                        'store_id' => $store->id,
                        'integration_id' => $siigoIntegration->id
                    ],
                    [
                        'external_store_id' => $accountGroup['groupId']
                    ]
                );
            }
        }

        return response()->json(
            [
                'status' => 'Success',
                'results' => 'La integración fue exitosa.'
            ], 200
        );
            
    }

    /**
     * retorna toda la información de la integración que esté guardada en BD
     */
    public function integration(Request $request){
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('id', $store->id)->first();
        $integration = StoreIntegrationToken::where('store_id', $store->id)
        ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)->get();

        $conexionObj = $integration->where('type', 'login')->first();
        $billingObj = $integration->where('type', 'billing')->first();

        if(!$conexionObj || !$billingObj){
            return response()->json(
                [
                    'status' => 'No se ha creado la conexión.',
                    'results' => []
                ], 409
            );
        }

        $storeIntegrationId = StoreIntegrationId::where('store_id', $store->id)
                                ->where('integration_name', AvailableMyposIntegration::NAME_SIIGO)
                                ->get();
        $accountGroups = [];
        foreach ($storeIntegrationId as $value) {
            array_push($accountGroups, [
                "name" => $value['type'], 
                "groupId" => $value['external_store_id']
            ]);
        } 

        $infoObj = [
            "ERPDocumentType" => $storeConfig->erp_doctype,
            "conexion" => [
                "userName" => $conexionObj->token,
                "password" => $conexionObj->password,
                "scopes" => $conexionObj->scope, 
                "subscriptionKey" => $billingObj->password
            ],
            "cashiers" => [
                    "myposId" => null,
                    "identification" => null
            ],
            "accountGroups" => $accountGroups,
            "storeCityId" => null
        ];

        return response()->json(
            [
                'status' => 'Success',
                'results' => $infoObj
            ], 200
        );
            
    }

    public function getProductsToSetIntegration(Request $request){
        $store = $this->authStore;
        try {
           $allProducts = $this->getAllProducts();
        } catch (\Throwable $th) {
            return response()->json(
                [
                    'status' => 'No fue posible la comunicación con Siigo.',
                    'results' => []
                ], 200
            );
        }
        $productsFromSiigo = [];

        for ($i=0; $i <= $allProducts->pages - 1; $i++) {
            foreach($allProducts->data[$i] as $product){
                array_push($productsFromSiigo, [
                    "id" => $product['Code'],
                    "name" => $product['Description']
                ]);
            }
        }
        /*$productsFromSiigo = json_decode('[
            {
                "id": 192319,
                "name": "Pizza Explosion De Queso Y Pepperoni - Rappipromo - Menu Rappi"
            },
            {
                "id": 192320,
                "name": "Pizza Personal Hawaiana Caram... - Rappipromo - Menu Rappi"
            },
            {
                "id": 192321,
                "name": "Pizza Explosion De Queso Y Pepperoni - Pizzas - Menu Rappi"
            },
            {
                "id": 192322,
                "name": "Pizza Bendito Pollo Hojaldre. - Pizzas - Menu Rappi"
            },
            {
                "id": 192323,
                "name": "Pizza Del Huerto. - Pizzas - Menu Rappi"
            },
            {
                "id": 192324,
                "name": "Pizza Hawaiana Caramelo - Pizzas - Menu Rappi"
            },
            {
                "id": 192325,
                "name": "Pizza Tex-mex Hojaldre - Pizzas - Menu Rappi"
            },
            {
                "id": 192326,
                "name": "Pizza Colombianisima - Pizzas - Menu Rappi"
            },
            {
                "id": 192327,
                "name": "Pizza La Pinta La Nina Y La Santa Maria - Pizzas - Menu Rappi"
            },
            {
                "id": 192328,
                "name": "Pizza Granjera - Pizzas - Menu Rappi"
            },
            {
                "id": 192329,
                "name": "Pizza Pabellon - Pizzas - Menu Rappi"
            },
            {
                "id": 192330,
                "name": "Pizza Mangastik - Pizzas - Menu Rappi"
            },
            {
                "id": 192331,
                "name": "Pizza Pollo Dulce Pollo - Pizzas - Menu Rappi"
            },
            {
                "id": 192332,
                "name": "Pizza Cuatro Estaciones Hojaldre - Pizzas - Menu Rappi"
            },
            {
                "id": 192333,
                "name": "Pizza Manzana Azul - Pizzas - Menu Rappi"
            },
            {
                "id": 192334,
                "name": "Pizza Margarita - Pizzas - Menu Rappi"
            },
            {
                "id": 192335,
                "name": "Pizza Bota Italica - Pizzas - Menu Rappi"
            },
            {
                "id": 192336,
                "name": "Pizza Cantabrica - Pizzas - Menu Rappi"
            },
            {
                "id": 192337,
                "name": "Pizza De La Sierra - Pizzas - Menu Rappi"
            },
            {
                "id": 192338,
                "name": "Pizza Bufalita - Pizzas - Menu Rappi"
            },
            {
                "id": 192339,
                "name": "Pizza Pollo Con Champinones - Pizzas - Menu Rappi"
            },
            {
                "id": 192340,
                "name": "Lasagna Pollo - Lasagnas - Menu Rappi"
            },
            {
                "id": 192341,
                "name": "Lasagnas Carne - Lasagnas - Menu Rappi"
            },
            {
                "id": 192342,
                "name": "Lasagnas Mixta - Lasagnas - Menu Rappi"
            },
            {
                "id": 192343,
                "name": "Jugo Hit - Bebidas - Menu Rappi"
            },
            {
                "id": 192344,
                "name": "Coca-cola - Bebidas - Menu Rappi"
            },
            {
                "id": 192345,
                "name": "Colombiana Postobon - Bebidas - Menu Rappi"
            },
            {
                "id": 192346,
                "name": "Manzana Postobon - Bebidas - Menu Rappi"
            },
            {
                "id": 192347,
                "name": "Uva Postobon - Bebidas - Menu Rappi"
            },
            {
                "id": 192348,
                "name": "Naranja Postobon 400 Ml - Bebidas - Menu Rappi"
            },
            {
                "id": 192349,
                "name": "Toronjo - Bebidas - Menu Rappi"
            },
            {
                "id": 192350,
                "name": "7 Up - Bebidas - Menu Rappi"
            },
            {
                "id": 192351,
                "name": "Pepsi - Bebidas - Menu Rappi"
            },
            {
                "id": 192352,
                "name": "Agua Cristal - Bebidas - Menu Rappi"
            },
            {
                "id": 192353,
                "name": "H2o - Bebidas - Menu Rappi"
            },
            {
                "id": 192354,
                "name": "Cerveza - Bebidas - Menu Rappi"
            },
            {
                "id": 192355,
                "name": "Pizza Chocolate - Chocolate - Postres - Menu Rappi"
            },
            {
                "id": 153655,
                "name": "Bowl Italiano - Bowl - Menu"
            },
            {
                "id": 153657,
                "name": "Combo Sandwich Sub Pechuga De Pavo (30 Cm)+ Galleta O Papas+ Gaseosa - Sandwich - Menu"
            },
            {
                "id": 192200,
                "name": "Bufalita Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 153638,
                "name": "Hamburgues - Hamburguesas - Menu"
            },
            {
                "id": 192226,
                "name": "Pizza Grande Combinada - Pizza Grande Combinada - Menu Local"
            },
            {
                "id": 192180,
                "name": "Pollo, Dulce Pollo Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192225,
                "name": "Recargo Celebracion - Servicios - Menu Local"
            },
            {
                "id": 192227,
                "name": "Adiciones - Adiciones - Menu Local"
            },
            {
                "id": 153659,
                "name": "Hot Dog Famous - Sandwich - Menu"
            },
            {
                "id": 165349,
                "name": "Sweet Salmon Belly Poke - Poke Go Fest - Menu Local"
            },
            {
                "id": 116075,
                "name": "Leche"
            },
            {
                "id": 91090,
                "name": "Helado"
            },
            {
                "id": 120076,
                "name": "Cheesecake de Limón"
            },
            {
                "id": 120620,
                "name": "Agua con gas"
            },
            {
                "id": 130356,
                "name": "Arma tu cheesecake"
            },
            {
                "id": 191996,
                "name": "Bebidas Calientes - Bebidas - Menu Local"
            },
            {
                "id": 191997,
                "name": "Bebidas - Bebidas - Menu Local"
            },
            {
                "id": 192007,
                "name": "Manzana Azul - Pizza Personal - Menu Local"
            },
            {
                "id": 192008,
                "name": "Margarita - Pizza Personal - Menu Local"
            },
            {
                "id": 192009,
                "name": "Cuatro Estaciones - Pizza Personal - Menu Local"
            },
            {
                "id": 192010,
                "name": "Del Huerto - Pizza Personal - Menu Local"
            },
            {
                "id": 192011,
                "name": "Mangastik - Pizza Personal - Menu Local"
            },
            {
                "id": 192012,
                "name": "Explosion De Queso Y Pepperoni - Pizza Personal - Menu Local"
            },
            {
                "id": 192013,
                "name": "Hawaiiana Caramelo - Pizza Personal - Menu Local"
            },
            {
                "id": 192014,
                "name": "La Pinta La Nina Y La Santa Maria - Pizza Personal - Menu Local"
            },
            {
                "id": 192015,
                "name": "Bendito Pollo Personal - Pizza Personal - Menu Local"
            },
            {
                "id": 192016,
                "name": "Granjera - Pizza Personal - Menu Local"
            },
            {
                "id": 192017,
                "name": "De La Sierra - Pizza Personal - Menu Local"
            },
            {
                "id": 192018,
                "name": "Bufalita - Pizza Personal - Menu Local"
            },
            {
                "id": 192019,
                "name": "Chocolate- Chocolate - Pizza Personal - Menu Local"
            },
            {
                "id": 192020,
                "name": "Pollo, Dulce Pollo - Pizza Personal - Menu Local"
            },
            {
                "id": 192021,
                "name": "Pollo Con Champinones - Pizza Personal - Menu Local"
            },
            {
                "id": 192022,
                "name": "Colombianisima - Pizza Personal - Menu Local"
            },
            {
                "id": 192023,
                "name": "Tex-mex - Pizza Personal - Menu Local"
            },
            {
                "id": 192024,
                "name": "Pabellon - Pizza Personal - Menu Local"
            },
            {
                "id": 192025,
                "name": "Cantabrica - Pizza Personal - Menu Local"
            },
            {
                "id": 192026,
                "name": "Bota Italica - Pizza Personal - Menu Local"
            },
            {
                "id": 192027,
                "name": "Manzana Azul Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192028,
                "name": "Margarita Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192029,
                "name": "Cuatro Estaciones Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192030,
                "name": "Del Huerto Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192031,
                "name": "Mangastik Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192032,
                "name": "Explosion De Queso Y Pepperoni Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192033,
                "name": "Hawaiiana Caramelo Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192034,
                "name": "La Pinta La Nina Y La Santa Maria Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192035,
                "name": "Bendito Pollo Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 153639,
                "name": "Hamburguer 2 - Hamburguesas - Menu"
            },
            {
                "id": 192036,
                "name": "Granjera Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192037,
                "name": "De La Sierra Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192038,
                "name": "Bufalita Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192039,
                "name": "Chocolate- Chocolate Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192040,
                "name": "Pollo Con Champinones Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192181,
                "name": "Manzana Azul Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192182,
                "name": "Margarita Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192183,
                "name": "Cuatro Estaciones Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192184,
                "name": "Del Huerto Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192185,
                "name": "Mangastik Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192186,
                "name": "Explosion De Queso Y Pepperoni Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192187,
                "name": "Hawaiiana Caramelo Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192188,
                "name": "La Pinta La Nina Y La Santa Maria Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192189,
                "name": "Bendito Pollo Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192190,
                "name": "Granjera Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192191,
                "name": "De La Sierra Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192192,
                "name": "Chocolate- Chocolate  Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192193,
                "name": "Pollo, Dulce Pollo Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192194,
                "name": "Pollo Con Champinones Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192195,
                "name": "Colombianisima Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192196,
                "name": "Tex-mex Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192197,
                "name": "Pabellon Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192198,
                "name": "Cantabrica Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192199,
                "name": "Bota Italica Grande - Pizza Grande - Menu Local"
            },
            {
                "id": 192201,
                "name": "Bolognesa - Pasta Personal - Menu Local"
            },
            {
                "id": 192202,
                "name": "3 Quesos Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192203,
                "name": "Pollo Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192204,
                "name": "Vegetales - Pasta Personal - Menu Local"
            },
            {
                "id": 192205,
                "name": "Carbonara Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192206,
                "name": "Aceitunas Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192207,
                "name": "Solomito De Res Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192208,
                "name": "Lomo De Cerdo Personal - Pasta Personal - Menu Local"
            },
            {
                "id": 192209,
                "name": "Pechuga De Pollo - Pasta Personal - Menu Local"
            },
            {
                "id": 192210,
                "name": "Bolognesa Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192211,
                "name": "3 Quesos Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192212,
                "name": "Pollo Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192213,
                "name": "Vegetales Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192214,
                "name": "Carbonara Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192215,
                "name": "Aceitunas Super Personal - Pasta Super Personal - Menu Local"
            },
            {
                "id": 192216,
                "name": "Lasagna Personal Pollo - Lasagna Personal - Menu Local"
            },
            {
                "id": 192217,
                "name": "Carne Personal - Lasagna Personal - Menu Local"
            },
            {
                "id": 192218,
                "name": "Lasagna Mixta Personal - Lasagna Personal - Menu Local"
            },
            {
                "id": 192219,
                "name": "Lasagna Pollo Super Personal - Lasagna Super Personal - Menu Local"
            },
            {
                "id": 192220,
                "name": "Lasagna Carne Super Personal - Lasagna Super Personal - Menu Local"
            },
            {
                "id": 192221,
                "name": "Lasagna Mixta Super Personal - Lasagna Super Personal - Menu Local"
            },
            {
                "id": 191998,
                "name": "Entrada 1: 2 Galletas De Hojaldre Queso De Bufala Tomates Asados Hojitas De Albahaca - Entradas - Me"
            },
            {
                "id": 191999,
                "name": "Entrada 2: 2 Galletas De Hojaldre Queso De Bufala Tomate Cherry Hojitas De Albahaca - Entradas - Men"
            },
            {
                "id": 192000,
                "name": "Entrada 3: 6 Palitos De Hojaldre, Salsa De Chocolate, Salsa Agria O Pomodoro De La Casa - Entradas -"
            },
            {
                "id": 192001,
                "name": "Entrada 4: 2 Galletas Hojaldre Trocitos De Jamon Trocitos De Pina Melado - Entradas - Menu Local"
            },
            {
                "id": 192002,
                "name": "Entrada 5: Sopita De Vegetales Con Quinua Y Tostones De Hojaldre - Entradas - Menu Local"
            },
            {
                "id": 192234,
                "name": "12 Minipizzas De Hojaldre - Rappi Menu - Rappi"
            },
            {
                "id": 192235,
                "name": "2 Pizzas Personales + 2 Gaseosas 250 Ml - Rappi Menu - Rappi"
            },
            {
                "id": 192236,
                "name": "2x1 Hawaiana Caramelo Grande - Rappi Menu - Rappi"
            },
            {
                "id": 192237,
                "name": "2x1 Hawaiana Caramelo Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192238,
                "name": "2x1 Hawaiana Caramelo Personal - Rappi Menu - Rappi"
            },
            {
                "id": 192239,
                "name": "2x1 Pizza Granjera - Rappi Menu - Rappi"
            },
            {
                "id": 192240,
                "name": "2x1 Pizza Personal Peperoni  - Rappi Menu - Rappi"
            },
            {
                "id": 192241,
                "name": "Bendito Pollo Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192242,
                "name": "Colombianisima Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192243,
                "name": "Cuatro Estaciones Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192244,
                "name": "Del Huerto Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192245,
                "name": "Demango Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192246,
                "name": "Demango Super Personal - Rappi Menu - Rappi"
            },
            {
                "id": 192247,
                "name": "Explosion De Queso Y Pepperoni Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192248,
                "name": "Hawaiana Caramelo Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192249,
                "name": "Jamon Serrano - Rappi Menu - Rappi"
            },
            {
                "id": 192250,
                "name": "La Pinta La Nina Y La Santa Maria Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192251,
                "name": "Manzana Azul Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192252,
                "name": "Margarita Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192253,
                "name": "Mazorcada - Rappi Menu - Rappi"
            },
            {
                "id": 192254,
                "name": "Pizza Bendito Pollo Hojaldre Super Personal - Rappi Menu - Rappi"
            },
            {
                "id": 192255,
                "name": "Pizza Grande + Gratis Pizza Personal - Rappi Menu - Rappi"
            },
            {
                "id": 192256,
                "name": "Pizza Personal Colombianisima +coca-cola 400 Ml - Rappi Menu - Rappi"
            },
            {
                "id": 192257,
                "name": "Pizza Personal Hawaiana Caramelo + Coca-cola 400ml - Rappi Menu - Rappi"
            },
            {
                "id": 192258,
                "name": "Pizza Personal Peperoni + Coca-cola 400ml - Rappi Menu - Rappi"
            },
            {
                "id": 192259,
                "name": "Pizza Personal Tex-mex Hojaldre + Coca-cola - Rappi Menu - Rappi"
            },
            {
                "id": 192290,
                "name": "Pollo Dulce Pollo Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192291,
                "name": "Popeye - Rappi Menu - Rappi"
            },
            {
                "id": 192292,
                "name": "Tex-mex Hojaldre Mediana - Rappi Menu - Rappi"
            },
            {
                "id": 192293,
                "name": "?limonada De Coco - Rappi Menu - Rappi"
            },
            {
                "id": 192294,
                "name": "?jugos Naturales - Rappi Menu - Rappi"
            },
            {
                "id": 192295,
                "name": "?miller Lite - Rappi Menu - Rappi"
            },
            {
                "id": 192296,
                "name": "?lasagna Pollo Con Tocineta - Rappi Menu - Rappi"
            },
            {
                "id": 192297,
                "name": "?lasagnas Carne - Rappi Menu - Rappi"
            },
            {
                "id": 192298,
                "name": "?lasagnas Mixta - Rappi Menu - Rappi"
            },
            {
                "id": 192299,
                "name": "?lasagnas Pollo - Rappi Menu - Rappi"
            },
            {
                "id": 192300,
                "name": "?lasagnas Veggie - Rappi Menu - Rappi"
            },
            {
                "id": 192301,
                "name": "?agua Cristal - Rappi Menu - Rappi"
            },
            {
                "id": 192302,
                "name": "?7 Up - Rappi Menu - Rappi"
            },
            {
                "id": 192303,
                "name": "?coca-cola - Rappi Menu - Rappi"
            },
            {
                "id": 192304,
                "name": "?h2o - Rappi Menu - Rappi"
            },
            {
                "id": 192305,
                "name": "?jugo Hit - Rappi Menu - Rappi"
            },
            {
                "id": 192306,
                "name": "?manzana Postobon - Rappi Menu - Rappi"
            },
            {
                "id": 192307,
                "name": "?naranja Postobon 400 Ml - Rappi Menu - Rappi"
            },
            {
                "id": 192308,
                "name": "?pepsi - Rappi Menu - Rappi"
            },
            {
                "id": 192309,
                "name": "?toronjo - Rappi Menu - Rappi"
            },
            {
                "id": 192310,
                "name": "?uva Postobon - Rappi Menu - Rappi"
            },
            {
                "id": 192356,
                "name": "Sopa De Tomate - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192357,
                "name": "Vol Au Vent - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192358,
                "name": "Montaditos - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192359,
                "name": "Ensalada Cesar - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192360,
                "name": "Ensalada De Apio - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192361,
                "name": "Sobre De Hojaldre - Entradas Y Ensaladas - Menu Local Copia"
            },
            {
                "id": 192362,
                "name": "Personal Manzana Azul - Personal - Menu Local Copia"
            },
            {
                "id": 192363,
                "name": "Personal Margarita - Personal - Menu Local Copia"
            },
            {
                "id": 192364,
                "name": "Personal Cuatro Estaciones - Personal - Menu Local Copia"
            },
            {
                "id": 192365,
                "name": "Personal Del Huerto - Personal - Menu Local Copia"
            },
            {
                "id": 192366,
                "name": "Personal Mangastik - Personal - Menu Local Copia"
            },
            {
                "id": 192367,
                "name": "Personal Explosion De Queso Y Pepperoni - Personal - Menu Local Copia"
            },
            {
                "id": 192368,
                "name": "Personal Hawaiiana Caramelo - Personal - Menu Local Copia"
            },
            {
                "id": 192369,
                "name": "Personal La Pinta La Nina La Santa Maria - Personal - Menu Local Copia"
            },
            {
                "id": 192370,
                "name": "Personal Colombianisima - Personal - Menu Local Copia"
            },
            {
                "id": 192371,
                "name": "Personal Pollo Con Champinones - Personal - Menu Local Copia"
            },
            {
                "id": 192372,
                "name": "Personal Pollo Dulce Pollo - Personal - Menu Local Copia"
            },
            {
                "id": 192373,
                "name": "Personal Bufalita - Personal - Menu Local Copia"
            },
            {
                "id": 192374,
                "name": "Personal De La Sierra - Personal - Menu Local Copia"
            },
            {
                "id": 192375,
                "name": "Personal Granjera - Personal - Menu Local Copia"
            },
            {
                "id": 192376,
                "name": "Personal Bendito Pollo - Personal - Menu Local Copia"
            },
            {
                "id": 192377,
                "name": "Perosonal Tex-mex - Personal - Menu Local Copia"
            },
            {
                "id": 192378,
                "name": "Personal Pabellon - Personal - Menu Local Copia"
            },
            {
                "id": 192379,
                "name": "Personal Cantabrica - Personal - Menu Local Copia"
            },
            {
                "id": 192380,
                "name": "Personal Bota Italica - Personal - Menu Local Copia"
            },
            {
                "id": 192381,
                "name": "Mediana Margarita - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192382,
                "name": "Mediana Cuatro Estaciones - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192383,
                "name": "Mediana Del Huerto - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192384,
                "name": "Mediana Explosion De Queso Y Pepperoni - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192385,
                "name": "Mediana Hawaiiana Caramelo - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192386,
                "name": "Mediana La Pinta La Nina La Santa Maria - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192387,
                "name": "Mediana Colombianisima - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192388,
                "name": "Mediana Pollo Con Champinones - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192389,
                "name": "Mediana Pollo Dulce Pollo - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192390,
                "name": "Mediana Bufalita - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192391,
                "name": "Mediana De La Sierra - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192392,
                "name": "Mediana Granjera - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192393,
                "name": "Medeiana Bendito Pollo - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192394,
                "name": "Mediana Tex-mex - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192395,
                "name": "Mediana Pabellon - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192396,
                "name": "Mediana Cantabrica - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192397,
                "name": "Mediana Manzana Azul - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192398,
                "name": "Mediana Mangastik - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192399,
                "name": "Mediana Bota Italica - Superpersonal - Menu Local Copia"
            },
            {
                "id": 192400,
                "name": "Margarita Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192401,
                "name": "Cuatro Estaciones Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192402,
                "name": "Del Huerto Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192403,
                "name": "Mangastik Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192404,
                "name": "Explosion De Queso Y Pepperoni Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192405,
                "name": "Hawaiiana Caramelo Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192406,
                "name": "La Pinta La Nina Y La Santa Maria Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192407,
                "name": "Colombianisima Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192408,
                "name": "Pollo Con Champinones Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192409,
                "name": "Pollo Dulce Pollo Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192410,
                "name": "Bufalita Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192411,
                "name": "De La Sierra Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192412,
                "name": "Granjera Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192413,
                "name": "Bendito Pollo Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192414,
                "name": "Tex-mex Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192415,
                "name": "Pabellon Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192416,
                "name": "Cantabrica Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192417,
                "name": "Bota Italica Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192418,
                "name": "Manzana Azul Grande - Grande - Menu Local Copia"
            },
            {
                "id": 192419,
                "name": "Brownie Con Helado - Postres - Menu Local Copia"
            },
            {
                "id": 192420,
                "name": "Pizza De Chocolate, Fresa Y Banano - Postres - Menu Local Copia"
            },
            {
                "id": 192421,
                "name": "Cheesecake De Temporada - Postres - Menu Local Copia"
            },
            {
                "id": 192422,
                "name": "Vino De Verano - Licores - Menu Local Copia"
            },
            {
                "id": 192423,
                "name": "12 Penicilina - Licores - Menu Local Copia"
            },
            {
                "id": 192424,
                "name": "Caipi Absolut Maracuya - Licores - Menu Local Copia"
            },
            {
                "id": 192425,
                "name": "Olmeca Coco - Licores - Menu Local Copia"
            },
            {
                "id": 192426,
                "name": "Beefeeter Tonic - Licores - Menu Local Copia"
            },
            {
                "id": 192427,
                "name": "Sangria Rosada (6-8 Personas) - Licores - Menu Local Copia"
            },
            {
                "id": 192428,
                "name": "Copa De Vino - Licores - Menu Local Copia"
            },
            {
                "id": 192429,
                "name": "Mora - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192430,
                "name": "Mango - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192431,
                "name": "Maracuya - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192432,
                "name": "Limonada - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192433,
                "name": "Limonada De Coco - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192434,
                "name": "Pina Colada - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192435,
                "name": "Lulo - Jugos Naturales - Menu Local Copia"
            },
            {
                "id": 192436,
                "name": "Coca Cola 400 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192437,
                "name": "Coca Cola Zero 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192438,
                "name": "Colombiana 400 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192439,
                "name": "Manzana 400 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192440,
                "name": "Uva 400 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192441,
                "name": "7up 400 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192442,
                "name": "Hit Naranja Pina 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192443,
                "name": "Hit Tropical 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192444,
                "name": "Hit Mango 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192445,
                "name": "Hit Lulo 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192446,
                "name": "Hit Mora 500 - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192447,
                "name": "Agua Sin Gas 420 Ml - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192448,
                "name": "Agua Con Gas 420ml - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192449,
                "name": "H2o Limonata - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192450,
                "name": "H2o Limonchelo - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192451,
                "name": "H2o Maracuya - Gaseosas - Menu Local Copia"
            },
            {
                "id": 192452,
                "name": "Heineken Botella - Cervezas - Menu Local Copia"
            },
            {
                "id": 192453,
                "name": "Heineken Lata - Cervezas - Menu Local Copia"
            },
            {
                "id": 192454,
                "name": "Miller Lite - Cervezas - Menu Local Copia"
            },
            {
                "id": 192455,
                "name": "Andina - Cervezas - Menu Local Copia"
            },
            {
                "id": 192456,
                "name": "Club Colombia Dorada - Cervezas - Menu Local Copia"
            },
            {
                "id": 192457,
                "name": "Corona - Cervezas - Menu Local Copia"
            },
            {
                "id": 192458,
                "name": "Stella Artois - Cervezas - Menu Local Copia"
            },
            {
                "id": 192459,
                "name": "Americano - Cafeteria - Menu Local Copia"
            },
            {
                "id": 192460,
                "name": "Capuccino - Cafeteria - Menu Local Copia"
            },
            {
                "id": 192461,
                "name": "Pasta Bolognesa - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192462,
                "name": "Pasta Carbonara - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192463,
                "name": "Pasta 3 Quesos - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192464,
                "name": "Personal Lasana De Bolonesa - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192465,
                "name": "Personal Lasana Pollo - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192466,
                "name": "Personal Lasana Mixta - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192467,
                "name": "Mediana Lasana De Bolonesa - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192468,
                "name": "Mediana Lasana Pollo - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192469,
                "name": "Mediana Lasana Mixta - Pastas & Lasanas - Menu Local Copia"
            },
            {
                "id": 192311,
                "name": "Pizza Chocolate - Personal - Rappi Menu - Rappi"
            },
            {
                "id": 153658,
                "name": "Choripan - Sandwich - Menu"
            },
            {
                "id": 192312,
                "name": "Pizza Hawaiiana Caramelo - Rappi Menu - Rappi"
            },
            {
                "id": 192313,
                "name": "Pizza Bendito Pollo  - Rappi Menu - Rappi"
            },
            {
                "id": 192314,
                "name": "Pizza Chocolate - Rappi Menu - Rappi"
            },
            {
                "id": 192315,
                "name": "Pizza Cuatro Estaciones  - Rappi Menu - Rappi"
            },
            {
                "id": 192003,
                "name": "Sopa De Tomates - Entradas - Menu Local"
            },
            {
                "id": 192004,
                "name": "Vol Au Vent - Entradas - Menu Local"
            },
            {
                "id": 192005,
                "name": "Montaditos - Entradas - Menu Local"
            },
            {
                "id": 192006,
                "name": "Sobre De Hojaldre - Entradas - Menu Local"
            },
            {
                "id": 192228,
                "name": "Ensalada Cesar - Ensaladas - Menu Local"
            },
            {
                "id": 192229,
                "name": "Ensalada De Apio - Ensaladas - Menu Local"
            },
            {
                "id": 192230,
                "name": "Brownie Con Helado - Postres - Menu Local"
            },
            {
                "id": 192231,
                "name": "Pizza De Chocolate, Fresa Y Banano - Postres - Menu Local"
            },
            {
                "id": 192232,
                "name": "Cheescake De Temporada - Postres - Menu Local"
            },
            {
                "id": 192233,
                "name": "Vol Au Vent De Queso Con Arequipe - Postres - Menu Local"
            },
            {
                "id": 192316,
                "name": "Lasagnas Pollo - Rappi Menu - Rappi"
            },
            {
                "id": 192041,
                "name": "Colombianisima Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192042,
                "name": "Tex-mex Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192043,
                "name": "Pabellon Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192044,
                "name": "Cantabrica Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 192045,
                "name": "Bota Italica Super Personal - Pizza Super Personal - Menu Local"
            },
            {
                "id": 153637,
                "name": "Hamburguesa De Carne - Hamburguesas - Menu"
            },
            {
                "id": 153603,
                "name": "Alitas (32 Unidades) - Alitas - Menu"
            },
            {
                "id": 165332,
                "name": "Arma Tu Poke - Arma Tu Poke - Menu Local"
            },
            {
                "id": 165333,
                "name": "Egg Rolls - Entradas - Menu Local"
            },
            {
                "id": 165334,
                "name": "Gyozas - Entradas - Menu Local"
            },
            {
                "id": 192222,
                "name": "Domicilio - Servicios - Menu Local"
            },
            {
                "id": 192223,
                "name": "Domicilio. - Servicios - Menu Local"
            },
            {
                "id": 192224,
                "name": "Domicilio.. - Servicios - Menu Local"
            },
            {
                "id": 192317,
                "name": "Pizza Bendito Pollo Hojaldre - Rappipromo - Menu Rappi"
            },
            {
                "id": 192318,
                "name": "Pizza Del Huerto - Rappipromo - Menu Rappi"
            },
            {
                "id": 192179,
                "name": "Propinas"
            }
        ]');*/
        return response()->json(
            [
                $productsFromSiigo
            ], 200
        );
    }

    public function getAccountGroupsToSetIntegration(){
        try {
            $aGFromSiigo = $this->getAllAccountGroups();
        } catch (\Throwable $th) {
            return response()->json(
                [
                    'status' => 'No fue posible la comunicación con Siigo.',
                    'results' => []
                ], 200
            );
        }
        $accountsFromSiigo = [];

        for ($i=0; $i <= $aGFromSiigo->pages - 1; $i++) {
            foreach($aGFromSiigo->data[$i] as $account){
                array_push($accountsFromSiigo, [
                    "id" => $account['Id'],
                    "name" => $account['Description']
                ]);
            }
        }

        return response()->json(
            [
                $accountsFromSiigo
            ], 200
        );
    }

    public function getErpDocumentTypesToSetIntegration(){
        try {
            $erpTypes = $this->getAllErpDocumentTypes();
        } catch (\Throwable $th) {
            return response()->json(
                [
                    'status' => 'No fue posible la comunicación con Siigo.',
                    'results' => []
                ], 200
            );
        }
        
        $erpTypes = $this->getAllErpDocumentTypes();
        $erpsFromSiigo = [];

        for ($i=0; $i <= $erpTypes->pages - 1; $i++) {
            foreach($erpTypes->data[$i] as $erp){
                array_push($erpsFromSiigo, [
                    "id" => $erp['Id'],
                    "name" => $erp['Name']
                ]);
            }
        }

        return response()->json(
            [
                $erpsFromSiigo
            ], 200
        );
    }
}
