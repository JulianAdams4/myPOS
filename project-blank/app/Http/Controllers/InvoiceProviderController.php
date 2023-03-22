<?php

namespace App\Http\Controllers;

use Log;
use App\Component;
use App\InvoiceProvider;
use App\InvoiceProviderDetail;
use App\StockMovement;
use App\ComponentStock;
use App\Traits\TimezoneHelper;
use App\InventoryAction;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\LocalImageHelper;
use App\Traits\AWSHelper;
use App\Traits\Stocky\StockyRequest;
use Exception;

class InvoiceProviderController extends Controller
{
    use AuthTrait, LoggingHelper, LocalImageHelper, AWSHelper, StockyRequest;

    public $authUser;

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

    public function createInvoiceProvider(Request $request)
    {
        $store = $this->authStore;
        $user = $this->authUser;

        try {
            $invoiceProviderJSON = DB::transaction(function () use ($request, $store, $user) {

                $store_id = $store->id;

                if (!$request->invoice_date) {
                    $invoice_date = Carbon::now()->startOfDay();
                } else {
                    $invoice_date = TimezoneHelper::localizedDateForStore($request->invoice_date, $store)->startOfDay();
                }

                if (!$request->reception_date) {
                    $reception_date = Carbon::now()->startOfDay();
                } else {
                    $reception_date = TimezoneHelper::localizedDateForStore($request->reception_date, $store)->startOfDay();
                }

                $existsInvoiceNumber = InvoiceProvider::where('invoice_number', $request->invoice_number)->first();

                if ($existsInvoiceNumber) {
                    return response()->json(
                        [
                            'status' => 'No se pudo crear la factura, el número de factura ya existe.',
                            'results' => null
                        ],
                        402
                    );
                }

                $invoice_provider = new InvoiceProvider();
                $invoice_provider->provider_id = $request->provider_id;
                $invoice_provider->invoice_number = $request->invoice_number;
                $invoice_provider->invoice_date = $invoice_date;
                $invoice_provider->reception_date = $reception_date;
                $invoice_provider->credit_days = $request->credit_days;

                if ($request["image_data"] != null) {
                    $fileData = $request["image_data"]['file_data'];
                    $fileUName = $request["image_data"]['file_uname'];
                    $fileType = $request["image_data"]['file_type'];
                    $fullpath = $this->storeBillingFileOnLocalServer($fileData, $fileUName);
                    if ($fullpath) {
                        $subfolderName = 'billings';
                        $this->uploadLocalFileToS3(
                            $fullpath,
                            $store_id,
                            $fileUName,
                            null,
                            $subfolderName
                        );
                        $this->saveInvoiceProviderFileAWSUrlDB(
                            $invoice_provider,
                            $store_id,
                            $subfolderName,
                            $fileUName,
                            $fileType
                        );
                        $this->deleteBillingFileOnLocalServer($fileUName);
                    }
                }

                $invoice_provider->save();
                $stocky_array = array();
                $units_array = array();
                $units_check = array();

                foreach ($request->details as $invoice_detail) {
                    $component_id = $invoice_detail['component_variation_id'];
                    $detail_quantity = $invoice_detail['quantity'];
                    $detail_price = $invoice_detail['price'];
                    $detail_price_type = array_key_exists('price_type', $invoice_detail) ?: 'unit';
                    $detail_discount = $invoice_detail['discount'];
                    $detail_tax = $invoice_detail['tax'];
                    $invoice_detail_created = InvoiceProviderDetail::create([
                        'invoice_provider_id' => $invoice_provider->id,
                        'component_id' => $component_id,
                        'quantity' => $detail_quantity,
                        'unit_price' => $detail_price,
                        'discount' => $detail_discount,
                        'tax' => $detail_tax
                    ]);

                    $component_stock = ComponentStock::where('store_id', $store_id)
                        ->where('component_id', $component_id)
                        ->first();
                    if (!$component_stock) {
                        throw new Exception("No existe stock para los productos agregados.");
                    }

                    // Tomamos el Costo total o Hallamos el total (unitario * cantidad)
                    $detail_cost = $detail_price_type === 'total' ? $detail_price : ($detail_quantity * $detail_price);
                    $newTotalCost = $detail_cost - $detail_discount + $detail_tax;
                    if (isset($invoice_detail['include_tax']) && $invoice_detail['include_tax'] == false) {
                        // 'stockTotalCost 'es precio total de compra
                        $stockTotalCost = $newTotalCost - $detail_tax;
                    } else {
                        $stockTotalCost = $newTotalCost;
                    }

                    // Se hace la conversión de compra a consumo
                    $component = Component::where('id', $component_id)->first();
                    $factor_compra = $component->conversion_metric_factor;
                    $factor_consumo = $component->metric_unit_factor;
                    if ($factor_compra && $factor_consumo) {
                        $consume_quantity = $detail_quantity * $factor_compra / $factor_consumo;
                    } else {
                        // Si no tiene alguna de las unidades, se ingresa la cantidad como Unid. de consumo
                        $consume_quantity = $detail_quantity;
                    }
                    // Se divide el costo entre las unidades convertidas o la cantidad ingresada
                    $converted_cost = $stockTotalCost / $consume_quantity; // 'converted_cost' es costo por unidad de consumo

                    // Merma de Compra
                    if ($component_stock->merma !== null) {
                        $factor_merma = 1.00 - round($component_stock->merma / 100, 2);
                        $mermed_quantity = $consume_quantity * $factor_merma;
                        $mermed_cost = $stockTotalCost / $mermed_quantity;
                        // Sobreescribimos el costo y la cantidad ingresada
                        $converted_cost = $mermed_cost;
                        $consume_quantity = $mermed_quantity;
                    }

                    // Actualizamos el costo y añadimos existencias del item en el inventario
                    $component_stock->cost = $converted_cost;
                    $component_stock->increment('stock', $consume_quantity);
                    $component_stock->save();

                    // Registramos el movimiento del inventario
                    $inventory_action = InventoryAction::where('code', 'invoice_provider')->first();

                    $lastStockMovement = StockMovement::where('component_stock_id', $component_stock->id)
                        ->orderBy('id', 'desc')
                        ->first();
                    $initial_stock = isset($lastStockMovement) ? $lastStockMovement->final_stock : 0;
                    $final_stock = $initial_stock + $consume_quantity;

                    $stock_movement = new StockMovement();
                    $stock_movement->inventory_action_id = $inventory_action->id;
                    $stock_movement->initial_stock = $initial_stock;
                    $stock_movement->value = $consume_quantity;
                    $stock_movement->final_stock = $final_stock;
                    $stock_movement->cost = $converted_cost; // Costo por unidad de consumo
                    $stock_movement->component_stock_id = $component_stock->id;
                    $stock_movement->created_by_id = $store->id;
                    $stock_movement->user_id = $user->id;
                    $stock_movement->invoice_provider_id = $invoice_provider->id;
                    $stock_movement->save();

                    $unitConsume = empty($component->unitConsume) ? 0 : $component->unitConsume->id;
                    $unitPurchase = empty($component->unit) ? 0 : $component->unit->id;
                    $item_stocky = array();
                    $item_stocky['name'] = $component->name;
                    $item_stocky['external_id'] = $component_id.'';
                    $item_stocky['sku'] = $component->SKU;
                    $item_stocky['purchase_unit_external_id'] = $unitPurchase.'';
                    $item_stocky['consumption_unit_external_id'] = $unitConsume.'';
                    $item_stocky['supplier_external_id'] = $invoice_provider->provider->id.'';
                    $item_stocky['cost'] = $converted_cost.'';
                    $item_stocky['stock'] = $final_stock.'';

                    array_push($stocky_array, $item_stocky);

                    if(!array_key_exists($unitConsume, $units_check) && $unitConsume>0)
                    {
                        $unit_stock_consumption = array();  
                        $unit_stock_consumption['name'] = $component->unitConsume->name;
                        $unit_stock_consumption['short_name'] = $component->unitConsume->short_name;
                        $unit_stock_consumption['external_id'] = $component->unitConsume->id.'';
                        $units_check[$component->unit->id] = $component->unit->id.'';
                        array_push($units_array, $unit_stock_consumption);
                    }
                    
                    if(!array_key_exists($unitPurchase, $units_check) && $unitPurchase>0)
                    {
                        $unit_stock_purchase = array();
                        $unit_stock_purchase['name'] = $component->unit->name;
                        $unit_stock_purchase['short_name'] = $component->unit->short_name;
                        $unit_stock_purchase['external_id'] = $component->unit->id.'';
                        $units_check[$component->unit->id] = $component->unit->id.'';
                        array_push($units_array, $unit_stock_purchase);
                    }
                    
                }

                $provider_new = array();
                $provider_new['name'] = $invoice_provider->provider->name;
                $provider_new['external_id'] = $invoice_provider->provider->id.'';
                $provider_array = array();
                array_push($provider_array, $provider_new);

                $items = array();

                $items['items'] = $stocky_array;
                $items['units'] = $units_array;
                $items['suppliers'] = $provider_array;

                StockyRequest::syncInventory($store->id, $items);

                return response()->json([
                    'status' => 'Exito',
                    'results' => $invoice_provider->id
                ], 200);
            });

            return $invoiceProviderJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProviderController API Provider: ERROR AL CREAR FACTURA DE PROVEEDOR, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );

            return response()->json(
                [
                    'status' => 'No se pudo crear la factura',
                    'results' => null
                ],
                500
            );
        }
    }
}
