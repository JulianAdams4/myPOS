<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Log;

use App\InvoiceProvider;
use App\ComponentStock;
use App\InventoryAction;
use App\StockMovement;
use App\OrderDetail;
use App\Component;
use App\Helper;
use App\Store;
use App\Order;

use App\Traits\TimezoneHelper;
use App\Traits\AuthTrait;

class InvoiceProviderReport extends Controller
{
  use AuthTrait;

  public $authUser;
  public $authStore;
  public $authEmployee;

  public function __construct()
  {
    $this->middleware('api');
    [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
      return response()->json([
        'status' => 'Usuario no autorizado',
        'results' => []
      ], 401);
    }
  }

  public function getInvoicesData($request)
  {
    @$stores = $request->stores ?? [];
    @$max_date = $request->max_date ? Carbon::parse($request->max_date)->endOfDay() : Carbon::now()->endOfDay();
    @$min_date = $request->min_date ? Carbon::parse($request->min_date)->startOfDay() : Carbon::now()->startOfDay();

    $invoices = InvoiceProvider::whereHas('provider', function ($query) use ($stores) {
      $query->whereIn('store_id', $stores);
    })
      ->whereBetween('created_at', [$min_date, $max_date])
      ->with(['details', 'provider', 'details.variation.unit'])
      ->orderBy('created_at', 'desc')
      ->get();

    return $invoices;
  }

  public function getInvoices(Request $request)
  {
    $invoices = $this->getInvoicesData($request);

    return response()->json([
      'status' => 'Facturas de proveedores',
      'results' => $invoices
    ], 200);
  }

  public function getInvoicesExcel(Request $request)
  {
    try {
      $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
      $excel->getProperties()->setTitle("myPOS");

      // Primera hoja donde apracerán detalles del objetivo
      $sheet = $excel->getActiveSheet();
      $excel->getActiveSheet()->setTitle("Reporte de productos");

      $excel->getDefaultStyle()
        ->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

      $excel->getDefaultStyle()
        ->getAlignment()
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

      $lineaSheet = array();
      $nombreEmpresa = ['titulo' => '', 'titulo2' => '', 'titulo3' => 'mypOS'];
      $num_fila = 5; // Ubicar los datos desde la fila 5

      array_push($lineaSheet, $nombreEmpresa);
      array_push($lineaSheet, []);
      array_push($lineaSheet, []);
      array_push($lineaSheet, []);

      $columnas = array(
        'Fecha', // A5
        'Proveedor', // B5
        'Factura', // C5
        'Recibido', // D5
        'Detalles', //E5
        'Unidad', //F5
        'Precio Unidad', //G5
        'Impuesto', //H5
        'Descuento', //I5
        'Total' //J5
      );

      $campos = array();

      foreach ($columnas as $col) {
        $campos[$col] = $col;
      }

      array_push($lineaSheet, $campos);

      // Format column headers
      $sheet->getStyle('A5:J5')->getFont()->setBold(true)->setSize(12);
      $sheet->getColumnDimension('a')->setWidth(30);
      $sheet->getColumnDimension('b')->setWidth(20);
      $sheet->getColumnDimension('c')->setWidth(25);
      $sheet->getColumnDimension('d')->setWidth(30);
      $sheet->getColumnDimension('e')->setWidth(20);
      $sheet->getColumnDimension('f')->setWidth(20);
      $sheet->getColumnDimension('g')->setWidth(25);
      $sheet->getColumnDimension('h')->setWidth(30);
      $sheet->getColumnDimension('i')->setWidth(20);
      $sheet->getColumnDimension('j')->setWidth(20);

      $data = $this->getInvoicesData($request);

      foreach ($data as $row) {

        foreach($row->details as $details) {
          $total_sub = (($details->quantity * $details->unit_price) + $details->tax - $details->discount)/100;
          array_push($lineaSheet, [
            'Fecha' => $row->created_at,
            'Proveedor' => $row->provider->name,
            'Factura' => $row->invoice_number,
            'Recibido' => $row->reception_date,
            'Detalles' => $details->variation->name, //E5
            'Unidad' => $details->quantity, //F5
            'Precio Unidad' => $details->unit_price/100, //G5
            'Impuesto' => $details->tax == 0 ? '0.00' : $details->tax/100, //H5
            'Descuento' => $details->discount == 0 ? '0.00': $details->discount/100, //I5
            'Total' =>$total_sub ? $total_sub : '0.00'
          ]);
          $num_fila++;
        }
      }

      $sheet->mergeCells('A1:A4');
      $sheet->getStyle('A1:E4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

      $sheet->getStyle('B1:e1')->getFont()->setBold(true)->setSize(28);
      $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
      $sheet->getStyle('B1:E1')->applyFromArray($st);
      // Format as currency 'Costo' and 'Ganancia' columns
      $sheet->getStyle('G6:J' . $num_fila)
        ->getNumberFormat()
        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
      // $sheet->getStyle('F6:F' . $num_fila)
      //   ->getNumberFormat()
      //   ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
      $sheet->freezePane('A6');
      // Format 'Cantidad' column
      $sheet->getStyle('A6:A' . $num_fila)
        ->getNumberFormat()
        ->setFormatCode(
          \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
        );

      // Format headers borders
      $estilob = array(
        'borders' => array(
          'allBorders' => array(
            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK
          )
        ),
        'alignment' => array(
          'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
        )
      );
      $sheet->getStyle('A5:J5')->applyFromArray($estilob);

      $sheet->fromArray($lineaSheet);
      $excel->setActiveSheetIndex(0);

      // Set logo at header
      $imagen = public_path() . '/images/logo.png';
      $obj = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
      $obj->setName('Logo');
      $obj->setDescription('Logo');
      $obj->setPath($imagen);
      $obj->setWidthAndHeight(160, 75);
      $obj->setCoordinates('A1');
      $obj->setWorksheet($excel->getActiveSheet());

      $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Xls');
      $nombreArchivo = 'Reporte de proveedores ' . Carbon::today()->format("d-m-Y");
      $response = response()->streamDownload(function () use ($objWriter) {
        $objWriter->save('php://output');
      });
      $response->setStatusCode(200);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
      $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
      $response->send();
    } catch (\Exception $e) {
      Log::info("NO SE PUDO GENERAR EL EXCEL DEL REPORTE DE PROVEEDORES");
      Log::info($e);
      return response()->json([
        'status' => 'No se pudo generar el reporte'
      ], 500);
    }
  }


  public function getComponents(Request $request)
  {
    @$names = $request->name ?? "";
    @$stores = $request->stores ?? [];

    $resultsIds = Component::where("name", "LIKE", $names."%")
            ->where('status', 1)
            ->get()
            ->pluck('id');

        // Buscando la data ya usando queries complejos, ya que scout sólo admite where simple
        $results = Component::select("id", "name")->whereIn('id', $resultsIds)
            ->where('status', 1)
            ->whereHas(
                'lastComponentStock',
                function ($componentStock) use ($stores) {
                    $componentStock->whereIn('store_id', $stores);
                }
            )
            ->limit(10)->get()->keyBy('id');

    return response()->json([
      'status' => 'Facturas de proveedores',
      'results' => $results
    ], 200);
    
  }

  /**
   * Function that gives data back the data ordered by categories
   */
  public function getDataCategories(Request $request)
  {
    @$stores = $request->stores ?? [];
    @$max_date = $request->max_date ? Carbon::parse($request->max_date)->endOfDay() : Carbon::now()->endOfDay();
    @$min_date = $request->min_date ? Carbon::parse($request->min_date)->startOfDay() : Carbon::now()->startOfDay();

    $invoices = InvoiceProvider::whereHas('provider', function ($query) use ($stores) {
      $query->whereIn('store_id', $stores);
    })
    ->whereBetween('created_at', [$min_date, $max_date])
    ->with(['details.variation.category'])
    ->orderBy('created_at', 'desc')
    ->get();
    
    $total_results = array();
    
    foreach($invoices as $inv)
    {
      foreach($inv->details as $details)
      {
        if($details->variation->category!=NULL){
          $name = $details->variation->category->name;
          if(array_key_exists($name, $total_results))
          {
            $total_results[$name] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                    floatval($details->tax) - floatval($details->discount) + $total_results[$name];
          }else
          {
            $total_results[$name] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                    floatval($details->tax) - floatval($details->discount);
          }
        }
      }
    }
    
    return response()->json([
      'status' => 'Facturas de proveedores',
      'results' => $total_results
    ], 200);
  }


  /**
   * Function that gives the data from the different providers
   */
  public function getDataProveedores(Request $request)
  {
    @$stores = $request->stores ?? [];
    @$max_date = $request->max_date ? Carbon::parse($request->max_date)->endOfDay() : Carbon::now()->endOfDay();
    @$min_date = $request->min_date ? Carbon::parse($request->min_date)->startOfDay() : Carbon::now()->startOfDay();
    
    $invoices = InvoiceProvider::whereHas('provider', function ($query) use ($stores) {
      $query->whereIn('store_id', $stores);
    })
      ->whereBetween('created_at', [$min_date, $max_date])
      ->with(['details', 'provider', 'details.variation.unit'])
      ->orderBy('created_at', 'desc')
      ->get();
    
    $total_results = array();

    foreach($invoices as $inv)
    {
      $name = $inv->provider->name;
      foreach($inv->details as $details)
      {
        if(array_key_exists($name, $total_results))
        {
          $total_results[$name] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                  floatval($details->tax) - floatval($details->discount) + $total_results[$name];
        }else
        {
          $total_results[$name] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                  floatval($details->tax) - floatval($details->discount);
        }
      }
    }

    return response()->json([
      'status' => 'Facturas de proveedores',
      'results' => $total_results
    ], 200);
  }


  /**
   * Function that gives the data back from the cost of inventory movements
   * Formula es 
   * Inventario inicial = Inventario Inicial + (Compras +Traslados de otros Puntos) - Food Cost - Mermas y Bajas - Ajustes - Traslados a otros puntos
   */
  public function getDetailsCost(Request $request)
  {
    @$stores = $request->stores ?? [];
    @$max_date = $request->max_date ? Carbon::parse($request->max_date)->endOfDay() : Carbon::now()->endOfDay();
    @$min_date = $request->min_date ? Carbon::parse($request->min_date)->startOfDay() : Carbon::now()->startOfDay();

    @$type_calc = $request->type; // true->calculation with last value, false->calculation with avg value

    // INVENTARIO INICIAL -> ordenando por tiendas
    $initial_inventory = $this->storesInitialInventory($min_date, $stores);

    //CALCULANDO COMPRAS

    $purchases_stores = $this->storesPurchase($stores, $min_date, $max_date, $type_calc);

    //FOOD COST POR TIENDA

    $foodC_stores = array();

    $foodCost = $this->storesFoodCost($stores, $min_date, $max_date);

    // Calculando mermas y bajas y Ajustes

    $adjust_stores = $this->storesAdjustements($stores, $min_date, $max_date);

    // Calculando Ajustes con compras, ajustes, traslados a otros y traslados de otros puntos
    $movements_stores = $this->getTrasladosInOut($max_date, $min_date, $stores, $type_calc);
    
    $total_results = array();

    // get Stores name//
    $group_stores = $this->authStore;

    $store_names = Store::select('id', 'name')->whereIn('id', $stores)->get()->keyBy('id');

    foreach ($stores as $store) {
      $total_results[$store] = array(
                                    "inventorio inicial" =>  floatval($initial_inventory[$store]),
                                    "compras" => $purchases_stores[$store],
                                    "movimientos" => $movements_stores[$store],
                                    "comida" => $foodCost[$store],
                                    "reajuste" => $adjust_stores[$store],
                                    "name" => $store_names[$store]['name']
                                    );
    }

    //$initial_inventory[$store] +  $purchases_stores[$store] + $movements_stores[$store] - $foodC_stores[$stores] - $adjust_stores[$store]

    return response()->json([
      'status' => 'Facturas de proveedores',
      'results' => $total_results
    ], 200);
  }

  /**
   * Get the initial inventory from a certain date
   */
  public function storesInitialInventory($min_date, $stores)
  {
    $components = ComponentStock::whereIn("store_id", $stores)->get()->pluck("id");
    
    $initial_stock = StockMovement::whereIn("component_stock_id", $components)
    ->orderBy('updated_at', 'DESC')
    ->where('updated_at', '<=', $min_date)
    ->whereRaw('id IN (select max(`id`) from stock_movements GROUP BY component_stock_id)')
    ->with(['componentStock'])
    ->get();
    
    $initial_sum = 0;

    $store_initial_inventory = array();

    foreach($initial_stock as $historical)
    {
      $component = $historical->componentStock->store_id;
      if(in_array($component, $stores)){
        $initial_sum = $historical->cost + $initial_sum;
      
        if(array_key_exists($component, $store_initial_inventory))
        {
          $store_initial_inventory[$component] = $store_initial_inventory[$component] + 
                                                $historical->cost == NULL? 0 : $historical->cost;
        } else
        {
          $store_initial_inventory[$component] = $historical->cost == NULL? 0 : $historical->cost;
        }
      }
      
    }

    foreach ($stores as $str) {
      if(!array_key_exists($str, $store_initial_inventory)){
        $store_initial_inventory[$str] = 0;
      }
    }

    return $store_initial_inventory;
  }

  /**
   * Get the purchases ordered by stores from date to date
   */
  public function storesPurchase($stores, $min_date, $max_date, $type_calc)
  {

    $invoices = InvoiceProvider::whereHas('provider', function ($query) use ($stores) {
      $query->whereIn('store_id', $stores);
    })
      ->whereBetween('created_at', [$min_date, $max_date])
      ->with(['details', 'provider', 'details.variation.unit'])
      ->orderBy('created_at', 'desc')      
      ->get();

    $purchase_comp = array();

    $stores_purchases = array();

    foreach ($invoices as $inv) {
      foreach ($inv->details as $detail) {
        $component = $detail->variation->name . ".". $inv->provider->store_id;
        if(array_key_exists($component, $purchase_comp))
        {
          $purchase_comp[$component] = array(
            "value" => $detail->unit_price + $purchase_comp[$component]['value'],
            "quantity" => $purchase_comp[$component]['quantity'] + $detail->quantity,
            "last_value" => $detail->unit_price,
            "store_id" => $inv->provider->store_id,
            "purchases" => $purchase_comp[$component]['purchases'] + 1
          );
        } else
        { 
          $purchase_comp[$component] = array(
            "value" => $detail->unit_price,
            "quantity" => $detail->quantity,
            "last_value" => $detail->unit_price,
            "store_id" => $inv->provider->store_id,
            "purchases" => 1
          );
        }
      }
    }

    foreach ($purchase_comp as $detail) {
      $single_store = $detail['store_id'];
      if(array_key_exists($single_store, $stores_purchases)){
        if($type_calc){ // last value
          $stores_purchases[$single_store] = $stores_purchases[$single_store] +
                                   $detail["quantity"] * $detail["last_value"]; 
        } else { // avg value
          $stores_purchases[$single_store] = $stores_purchases[$single_store] +
                 $detail["quantity"] * $detail["value"]/$detail["purchases"] ;
        }
      }else {
        if($type_calc){ // last value
          $stores_purchases[$single_store] = $detail["quantity"] * 
                                              $detail["last_value"]; 
        } else { // avg value
          $stores_purchases[$single_store] = $detail["quantity"] * 
                                        $detail["value"]/$detail["purchases"] ;
        }
      }
    }

    foreach ($stores as $str) {
      if(!array_key_exists($str, $stores_purchases)){
        $stores_purchases[$str] = 0;
      }
    }

    return $stores_purchases;
  }

  /**
   * Calculate the cost from orders from certain dates
   */
  public function storesFoodCost($storeIds, $startDate, $endDate)
  {
        $ordersIds = Order::whereIn('store_id', $storeIds)
            ->where('status', 1)
            ->where('preorder', 0)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->pluck('id')->toArray();

        $registers = OrderDetail::whereIn('order_id', $ordersIds)
            ->with(['productDetail', 'productDetail.product', 'productDetail.product.category'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('updated_at');

        $registers = $registers->get()->groupBy('order.store.id');
        

        $registers = collect($registers);

        $data = array();

        foreach ($registers as $orders) {

          $ventas = 0;
          foreach ($orders as $product) {
              $ventas =  $ventas + $product['quantity']*$product['value'];
          }

          $store_id = $orders[0]['order']['store']['id'];
          
          if(array_key_exists($store_id,$data))
          {
            $data[$store_id . ""] = $data[$store_id . ""] + $ventas; 
          }else
          {
            $data[$store_id . ""] = $ventas;           
          }            
        
        }

        foreach ($storeIds as $str) {
          if(!array_key_exists($str, $data)){
            $data[$str] = 0;
          }
        }

        return $data;
  }


  public function getRealValueofComponent($componentStock)
  {
      $last_code = $componentStock->lastCost;

      $p_total = $last_code? 
      $last_code->action->code == "invoice_provider"?
      $last_code->cost/$last_code->value : 
      $last_code->cost :0;

      $cost = ($p_total);
      
      return $cost;
  }

  /**
   * Calculate the total movements from the stores from date to date 
   */
  public function storesAdjustements($stores, $min_date, $max_date)
  {
    
    $allowedInventoryActionCodes = [ 'count' ];
    
    $allowedActionIds = InventoryAction::whereIn('code', $allowedInventoryActionCodes)->get()->pluck('id');

    $Movements = StockMovement::whereIn('inventory_action_id', $allowedActionIds)
            ->whereHas(
                'componentStock',
                function ($query) use ($stores) {
                    $query->whereIn('store_id', $stores);
                }
            )
            ->whereBetween('created_at', [$min_date, $max_date])
            ->with([
                'action',
                'componentStock.lastCost',
            ])
            ->get();

    $type_movements = array();

    foreach ($Movements as $mov) {
      $component = $mov->componentStock->store_id;
      $mov_value = $mov->final_stock - $mov->initial_stock;
      $cost = $this->getRealValueofComponent($mov->componentStock);
      if(array_key_exists($component,$type_movements))
      {
        $type_movements[$component] = $type_movements[$component] + ($mov_value) * $cost; 
      }else
      {
        $type_movements[$component] = $mov_value * $cost;           
      } 
    }

    foreach ($stores as $str) {
      if(!array_key_exists($str, $type_movements)){
        $type_movements[$str] = 0;
      }
    }

    return $type_movements;
  }

  /**
   *  Traslados de otros Puntos - Traslados a otros puntos
   */
  public function getTrasladosInOut($max_date, $min_date, $storeIds, $type)
  {
        // Params from request
        $startDate = $max_date;
        $endDate = $min_date;
        $stores = $storeIds;
        // Allowed actions
        $allowedInventoryActionCodes = [
            'send_transfer', 'receive_transfer'
        ];
        
        $allowedActionIds = InventoryAction::whereIn('code', $allowedInventoryActionCodes)->get()->pluck('id');
        
        /** ----------------------------------------------
         *  Get data from StockMovements (Allowed actions)
         */
        
         $send_id = $allowedActionIds = InventoryAction::
                            where('code', "send_transfer")
                            ->get()
                            ->pluck('id');
        
        $receive_id = $allowedActionIds = InventoryAction::
                            where('code', "receive_transfer")
                            ->get()
                            ->pluck('id');

        $movements_results = 0;
        $type_movements = [];

        $Movements = StockMovement::whereIn('inventory_action_id', $allowedActionIds)
            ->whereHas(
                'componentStock',
                function ($query) use ($storeIds) {
                    $query->whereIn('store_id', $storeIds);
                }
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'action',
                'componentStock'
            ])
            ->get();

        foreach ($Movements as $mov) {
          $component = $mov->componentStock->store_id;
          $type_ = $mov->inventory_action_id;
          $cost = $mov->cost;

          if($type == $send_id){
            $cost = (-1)*$cost; 
          }

          if(array_key_exists($component,$type_movements))
          {
            $type_movements[$component] = $mov;
          }else
          {
            $type_movements[$component] = $type_movements[$component] + $cost;            
          } 
        }

        foreach ($stores as $str) {
          if(!array_key_exists($str, $type_movements)){
            $type_movements[$str] = 0;
          }
        }

        return $type_movements;
  }


  /**
   * Function that returns the daily expenses of a single product
   */
  public function getConsumeProvidersOrProduct(Request $request)
  {
    @$stores = $request->stores ?? [];
    @$max_date = $request->max_date ? Carbon::parse($request->max_date)->endOfDay() : Carbon::now()->endOfDay();
    @$min_date = $request->min_date ? Carbon::parse($request->min_date)->startOfDay() : Carbon::now()->startOfDay();
    
    @$product_id = $request->product_id;
    
    $invoices = InvoiceProvider::whereHas('provider', function ($query) use ($stores) {
      $query->whereIn('store_id', $stores);
    })
    ->whereHas('details', function ($query) use ($product_id) {
      $query->where('component_id', "=", $product_id);
    })
      ->whereBetween('created_at', [$min_date, $max_date])
      ->with(['details'])
      ->orderBy('created_at', 'desc')
      ->get();

    $total_results = array();
    
    foreach ($invoices as $iv) {
      foreach ($iv->details as $details) {
        $date = Carbon::parse($details->updated_at)->endOfDay()->toDateString();
        if(array_key_exists($date, $total_results))
        {
          $total_results[$date] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                  floatval($details->tax) - floatval($details->discount) + $total_results[$date];
        }else
        {
          $total_results[$date] = (floatval($details->quantity)*floatval($details->unit_price)) +
                                  floatval($details->tax) - floatval($details->discount);
        }
      }
    }

    return response()->json([
      'status' => 'Resultados diarios de un producto',
      'results' => [
        'data' => $total_results,
      ]
    ], 200);
  }

}
