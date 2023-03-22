<?php

namespace App\Http\Controllers\API\V2;

use App\Http\Controllers\Controller;
use App\Order;
use App\OrderDetail;
use App\OrderDetailProcessStatus;
use App\OrderProductSpecification;
use App\OrderStatus;
use App\OrderCondition;
use App\Instruction;
use App\Address;
use App\Employee;
use App\InvoiceTaxDetail;
use App\InvoiceItem;
use App\ProductDetail;
use App\Company;
use App\Store;
use App\Billing;
use App\Invoice;
use App\CashierBalance;
use App\ExpensesBalance;
use App\ComponentCategory;
use App\AdminStore;
use App\Product;
use App\ProductCategory;
use App\ProductComponent;
use App\Component;
use App\ComponentStock;
use App\MetricUnit;
use App\ProductSpecification;
use App\Specification;
use App\SpecificationCategory;
use App\StoreTax;
use App\Traits\PushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Jobs\Gacela\PostGacelaOrder;
use Carbon\Carbon;
use App\Helper;
use App\Traits\OrderHelper;
use Log;
use Auth;
use App\Events\OrderCustomerCreated;
use Pusher\Pusher;
use App\Traits\ValidateToken;
use Illuminate\Support\Facades\DB;
use App\Jobs\Datil\IssueInvoiceDatil;
use App\ProductDetailStoreLocation;
use App\StoreIntegrationToken;
use App\Traits\AuthTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request as RequestGuzzle;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\BadResponseException;

class ProdServerController extends Controller
{
    use ValidateToken, OrderHelper, PushNotification;
    use AuthTrait;
    public $pusher;

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



    public function getProductsFromProd(Request $request)
    {
        $store = $this->authStore;
        $productsDetails = null;
        $variations = null;
        $myposEndpoint = config('app.prod_api') . 'v2/slave/products/get';
        $client = new \GuzzleHttp\Client();
        try {
            $accessToken = $this->authUser->createToken('project-blank', ['admin'])->accessToken;
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ];
            $request = new RequestGuzzle('GET', $myposEndpoint, $headers);
            $response = $client->send($request, ['timeout' => 60]);
            if ($response->getStatusCode() === 200) {
                $productsDetails = json_decode($response->getBody())->results->products_details;
                $variations = json_decode($response->getBody())->results->variations;
            }
        } catch (ClientException  $e) {
            Log::info("ProdServerController Web getProductsFromProd ClientException: NO SE PUDO REALIZAR LA PETICION GET");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
        } catch (ServerException $e) {
            Log::info("ProdServerController Web getProductsFromProd ServerException: ERROR EN EL SERVIDOR PROD");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
        } catch (BadResponseException $e) {
            Log::info("ProdServerController Web getProductsFromProd BadResponseException: ERROR DE RESPUESTA DEL SERVIDOR PROD");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
        } catch (RequestException $e) {
            Log::info("ProdServerController Web getProductsFromProd RequestException: NO SE PUDO REALIZAR LA PETICION GET");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            return response()->json(
                [
                    'status' => 'Ocurrio un error en el servidor',
                    'results' => "null"
                ],
                500
            );
        }

        if ($productsDetails === null) {
            return response()->json(
                [
                    'status' => 'No se pudieron obtener los productos',
                    'results' => "null"
                ],
                400
            );
        }

        try {
            foreach ($productsDetails as $productDetail) {
                $product = $productDetail->product;

                $category = ProductCategory::find($product->category->id);
                if (!$category) {
                    $category = new ProductCategory();
                    $category->id = $product->category->id;
                    $category->company_id = $store->company_id;
                    $category->name = $product->category->name;
                    $category->search_string = $product->category->search_string;
                    $category->status = 1;
                    $category->section_id = $product->category->section_id;
                } else {
                    $category->name = $product->category->name;
                    $category->search_string = $product->category->search_string;
                }
                $category->save();

                $productDB = Product::find($product->id);
                if (!$productDB) {
                    $productDB = new Product();
                    $productDB->id = $product->id;
                    $productDB->product_category_id = $category->id;
                }
                $productDB->status = 1;
                if (isset($product->status)) {
                    $productDB->status = $product->status;
                }
                $productDB->name = $product->name;
                $productDB->search_string = $product->search_string;
                $productDB->description = $product->description;
                $productDB->base_value = $product->base_value;
                $productDB->invoice_name = $product->invoice_name;
                $productDB->ask_instruction = $product->ask_instruction;
                $productDB->sku = $product->sku;
                $productDB->save();

                $productDetailDB = ProductDetail::find($productDetail->id);
                if (!$productDetailDB) {
                    $productDetailDB = new ProductDetail();
                    $productDetailDB->id = $productDetail->id;
                    $productDetailDB->product_id = $productDetail->product_id;
                    $productDetailDB->store_id = $productDetail->store_id;
                    $productDetailDB->created_at = $productDetail->created_at;
                }
                $productDetailDB->status = 1;
                if (isset($productDetail->status)) {
                    $productDetailDB->status = $productDetail->status;
                }
                $productDetailDB->stock = $productDetail->stock;
                $productDetailDB->value = $productDetail->value;
                $productDetailDB->save();

                foreach ($product->taxes as $prodTax) {
                    $storeTaxDB = StoreTax::find($prodTax->id);
                    if (!$storeTaxDB) {
                        $storeTaxDB = new StoreTax();
                        $storeTaxDB->id = $prodTax->id;
                        $storeTaxDB->store_id = $prodTax->store_id;
                        $storeTaxDB->created_at = $prodTax->created_at;
                    }
                    $storeTaxDB->name = $prodTax->name;
                    $storeTaxDB->percentage = $prodTax->percentage;
                    $storeTaxDB->type = $prodTax->type;
                    $storeTaxDB->enabled = $prodTax->enabled;
                    $storeTaxDB->is_main = $prodTax->is_main;
                    $storeTaxDB->save();

                    $productDB->taxes()->syncWithoutDetaching([$storeTaxDB->id]);
                }

                foreach ($product->specifications as $specification) {
                    $specCategory = SpecificationCategory::find($specification->specification_category->id);
                    if (!$specCategory) {
                        $specCategory = new SpecificationCategory();
                        $specCategory->id = $specification->specification_category->id;
                        $specCategory->company_id = $specification->specification_category->company_id;
                    }
                    $specCategory->name = $specification->specification_category->name;
                    $specCategory->priority = $specification->specification_category->priority;
                    $specCategory->required = $specification->specification_category->required;
                    $specCategory->max = $specification->specification_category->max;
                    $specCategory->status = $specification->specification_category->status;
                    $specCategory->show_quantity = $specification->specification_category->show_quantity;
                    $specCategory->type = $specification->specification_category->type;
                    $specCategory->save();

                    $specificationDB = Specification::find($specification->id);
                    if (!$specificationDB) {
                        $specificationDB = new Specification();
                        $specificationDB->id = $specification->id;
                        $specificationDB->specification_category_id = $specification->specification_category_id;
                    }
                    $specificationDB->name = $specification->name;
                    $specificationDB->status = $specification->status;
                    $specificationDB->value = $specification->value;
                    $specificationDB->save();

                    $productSpecificationDB = ProductSpecification::where('product_id', $specification->pivot->product_id)->where('specification_id', $specification->pivot->specification_id)->orderBy('id', 'desc')->first();
                    if (!$productSpecificationDB) {
                        $productSpecificationDB = new ProductSpecification();
                        $productSpecificationDB->product_id = $specification->pivot->product_id;
                        $productSpecificationDB->specification_id = $specification->pivot->specification_id;
                    }
                    $productSpecificationDB->status = $specification->pivot->status;
                    $productSpecificationDB->value = $specification->pivot->value;
                    $productSpecificationDB->save();
                }
            }

            foreach ($variations as $variation) {
                /// Busco la unidad
                if (isset($variation->unit)) {
                    $unitDB = MetricUnit::find($variation->unit->id);
                    if (!$unitDB) {
                        $unitDB = new MetricUnit();
                        $unitDB->id = $variation->unit->id;
                    }
                    $unitDB->name = $variation->unit->name;
                    $unitDB->short_name = $variation->unit->short_name;
                    $unitDB->save();
                }

                /// Busco el Componente
                if (isset($variation->component)) {
                    /// Busco categoria del componente
                    $componentCat = ComponentCategory::find($variation->component->category->id);
                    if (!$componentCat) {
                        $componentCat = new ComponentCategory();
                        $componentCat->id = $variation->component->category->id;
                        $componentCat->company_id = $store->company->id;
                    }
                    $componentCat->search_string = Helper::remove_accents($variation->component->category->name);
                    $componentCat->name = $variation->component->category->name;
                    $componentCat->save();

                    $componentDB = Component::find($variation->component->id);
                    if (!$componentDB) {
                        $componentDB = new Component();
                        $componentDB->id = $variation->component->id;
                        $componentDB->component_category_id = $variation->component->category->id;
                        $componentDB->name = $variation->name;
                        $componentDB->cost = $variation->cost;
                        $componentDB->value = $variation->value;
                        $componentDB->SKU = $variation->SKU;

                        $componentDB->status = $variation->status;
                        $componentDB->metric_unit_id = $variation->metric_unit_id;
                    }
                    $componentDB->name = $variation->component->name;
                    $componentDB->save();
                }

                /// Ya se que existe el componente y la unidad, ya puedo crear la variacion
                $variationDB = Component::find($variation->id);
                if (!$variationDB) {
                    $variationDB = new Component();
                    $variationDB->id = $variation->id;
                    $variationDB->component_id = $variation->component_id;
                    $variationDB->created_at = $variation->created_at;
                }
                $variationDB->name = $variation->name;
                $variationDB->cost = $variation->cost;
                $variationDB->value = $variation->value;
                $variationDB->SKU = $variation->SKU;

                $variationDB->status = $variation->status;
                $variationDB->metric_unit_id = $variation->metric_unit_id;
                $variationDB->save();

                $cStockRequested = null;
                if (isset($variation->component_stocks)) {
                    $CStocks = $variation->component_stocks;
                    foreach ($CStocks as $cStock) {
                        if ($cStock->store_id = $store->id) {
                            $cStockRequested = $cStock;
                            break;
                        }
                    }

                    $stockDB = ComponentStock::find($cStockRequested->id);
                    if (!$stockDB) {
                        $stockDB = new ComponentStock();
                        $stockDB->id = $cStockRequested->id;
                        $stockDB->store_id = $store->id;
                        $stockDB->component_id = $variationDB->id;
                        $stockDB->created_at = $cStockRequested->created_at;
                    }

                    $stockDB->alert_stock = $cStockRequested->alert_stock;
                    $stockDB->stock = $cStockRequested->stock;

                    $stockDB->save();
                }

                /// ProductComponents
                foreach ($variation->product_components as $productComponent) {
                    $productComponentDB = ProductComponent::find($productComponent->id);
                    if (!$productComponentDB) {
                        $productComponentDB = new ProductComponent();
                        $productComponentDB->id = $productComponent->id;
                        $productComponentDB->product_id = $productComponent->product_id;
                        $productComponentDB->created_at = $productComponent->created_at;
                        $productComponentDB->component_id = $productComponent->component_id;
                    }
                    $productComponentDB->consumption = $productComponent->consumption;
                    $productComponentDB->status = $productComponent->status;
                    $productComponentDB->save();
                }
            }
        } catch (\Exception $e) {
            Log::info("ProdServerController Web getProductsFromProd: NO SE PUDIERON GUARDAR LOS PRODUCTOS");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request));
            return response()->json(
                [
                    'status' => 'Ocurrio un error al sincronizar los productos',
                    'results' => "null"
                ],
                400
            );
        }

        return response()->json(
            [
                'status' => 'Menu Sincronizado con exito',
                'results' => "null"
            ],
            200
        );
    }
}
