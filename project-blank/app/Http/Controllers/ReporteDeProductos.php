<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Component;
use App\Order;
use App\Helper;
use App\OrderDetail;
use App\Traits\AuthTrait;
use App\Traits\TimezoneHelper;
use App\Traits\Inventory\ComponentHelper;
use Log;

class ReporteDeProductos extends Controller
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

    public function reportePorDia(Request $request)
    {
        try {
            $store = $this->authStore;

            $storeIds = $request->ids;
            // Chart dates (real + dummy data)
            $minDate = TimezoneHelper::convertToServerDateTime($request->min_date, $store);
            $maxDate = TimezoneHelper::convertToServerDateTime($request->max_date, $store);
            // Querying
            $storeIdsStr = implode(",", $storeIds);
            $results = collect(DB::select(DB::raw("SELECT DATE(created_at) AS date, count(*) AS count FROM orders WHERE store_id in ($storeIdsStr) AND status = '1' AND preorder = '0' AND created_at BETWEEN '$minDate' AND '$maxDate' GROUP BY date")));
            // Only real selected range
            $startDate = TimezoneHelper::convertToServerDateTime($request->start_date, $store)->format('Y-m-d');
            $endDate = TimezoneHelper::convertToServerDateTime($request->end_date, $store)->format('Y-m-d');

            $selectedResults = $results->whereBetween('date', [$startDate, $endDate]);
            // Best day of real selected range
            $best_day = $results->where('count', $selectedResults->max('count'))->toArray();
            $bestData = [
                'count' => 0,
                'total' => 0,
                'date' => ''
            ];
            foreach ($best_day as $best) {
                $bestData['date'] = $best->date;
                $bestData['count'] = $best->count;
                // Get total orders of best day
                $bestStart = TimezoneHelper::convertToServerDateTime($best->date . " 00:00:00", $store);
                $bestEnd   = TimezoneHelper::convertToServerDateTime($best->date . " 23:59:59", $store);
                $totalSoldInOrders = Order::whereIn('store_id', $storeIds)
                    ->where('status', 1)->where('preorder', 0)
                    ->whereBetween('created_at', [$bestStart, $bestEnd])
                    ->get()->sum('total');
                $bestData['total'] = $totalSoldInOrders;
            }
            return response()->json([
                'status' => 'Success',
                'results' => [
                    'data' => $results,
                    'best' => $bestData
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'No se pudo generar el reporte',
            ], 500);
        }
    }

    public function productCost(&$cost, $components, &$list)
    {

        $comp = Component::whereIn('id', $components)
                ->with(['lastComponentStock.lastCost', 'subrecipe', 
                'productComponents', 'subrecipe.variationSubrecipe'])
                ->get();

        $list = array_merge($list, $components);

        foreach($comp as $details)
        {
            if(count($details->subrecipe)>0){
                foreach($details->subrecipe as $subrecipe)
                {
                    $value = $subrecipe->variationSubrecipe->lastComponentStock ? $subrecipe->variationSubrecipe->lastComponentStock->cost : 0;
                    $cost = $cost +  $value * $subrecipe->consumption;
                    $new_subrecipe = $subrecipe->variationSubrecipe->subrecipe->pluck('component_destination_id');
                    if(count($new_subrecipe)>0)
                    {
                        if(!empty(array_intersect($list, $new_subrecipe->toArray()))){
                            $this->productCost($cost, $components, $list);
                        }
                    }
                }   
            }else {
                $value = $details->lastComponentStock->cost ? $details->lastComponentStock->cost : 0;
                $cost = $cost +  $value * $details->productComponents[0]->consumption;
            }
        }

        return $cost;
    }

    public function getReportData($store, $options, $shouldPaginate = true)
    {
        $storeIds = $options->ids ?: [];
        $startDate = TimezoneHelper::convertToServerDateTime($options->start_date, $store);
        $endDate = TimezoneHelper::convertToServerDateTime($options->end_date, $store);
        $currentPage = $options->current_page;
        $pageSize = $options->page_size;
        $sortBy = $options->sort_by ?: 'product';
        $sortOrder = $options->sort_order ?: 'ascend';
        $strLike = $options->searchStr ?: '';
        // Pagination
        $offset = ($currentPage * $pageSize) - $pageSize;

        $data = OrderDetail::whereHas(
            'order',
            function ($order) use ($startDate, $endDate, $storeIds) {
                $order
                    ->whereIn('store_id', $storeIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', 1)
                    ->where('preorder', 0);
            }
        )
        ->with([
            'orderSpecifications.specification',
            'productDetail.product.category',
            'productDetail.product.components' => function ($query) {
                $query->where('status', 1);
            }
        ])
        ->get()
        ->groupBy(function ($orderDetail) {
            return $orderDetail->name_product;
        })
        ->map(function ($group, $key) use($startDate, $endDate){
            $ordDet = $group[0];
            $components = $ordDet->productDetail->product->components->pluck('component_id')->toArray();
            $consumptions = $ordDet->productDetail->product->components->pluck('consumption', 'component_id')->toArray();
            $store_id = $ordDet->productDetail->store_id;
            $cost_recipe = 0;
            $component_list = [];
            foreach($components as $comp)
            {
                $consumption = $consumptions[$comp] ? (float) $consumptions[$comp] : 0;
                $value = ComponentHelper::getPromValue ($comp, $store_id, $startDate, $endDate, false) * $consumption;
                $cost_recipe += $value;
            }
            // Caso en que no se haya registrado una receta pero si un costo de produccion del producto
            if(count($components) == 0) {
                $cost_recipe = $ordDet->productDetail->production_cost;
            }
            $quantity = $group->sum('quantity');
            $id = $ordDet->product_detail_id;
            $product = $ordDet->name_product;
            $base_value = $ordDet->base_value;
            $category = $ordDet->productDetail->product->statelessCategory->name;
            $specification = count($ordDet->orderSpecifications) > 0
                ? $ordDet->orderSpecifications[0]->specification->name : '';
            $cost = (float) $cost_recipe * $quantity;
            $ventas = $group->sum('total');
            $gain = $ventas - $cost;
            $groupKey = strtolower($product).strtolower($category).strtolower($specification);
            return [
                'id' => $id,
                'product' => $product,
                'category' => $category,
                'baseValue' => $base_value,
                'specification' => $specification,
                'quantity' => $quantity,
                'unit_cost' => $cost_recipe,
                'cost' => $cost_recipe * $quantity,
                'gain' => round($gain, 2),
                'ventas' => round($ventas),
                'groupkey' => $groupKey
            ];
        }); // [{"<number>":{"id":<number>,"product":"Agua","category":"Bebidas","specification"...}}
        // Sort
        if ($sortOrder === 'ascend') {
            $data = $data->sortBy($sortBy);
        } elseif ($sortOrder === 'descend') {
            $data = $data->sortByDesc($sortBy);
        }
        // WhereLike in collection
        if ($strLike) {
            $offset = 0; // Search results in first page
            $data = $data->filter(function ($item) use ($strLike) {
                $p = false !== stristr($item['product'], $strLike);
                $c = false !== stristr($item['category'], $strLike);
                $s = false !== stristr($item['specification'], $strLike);
                return $p || $c || $s;
            });
        }
        // Group by 'group_key' to avoid repeated elemens
        $data = $data->groupBy('groupkey')->map(function ($prodGroup, $key) {
            $totalQuantity = $totalCost = $totalGain = $totalVentas = 0;
            foreach ($prodGroup as $groupItem) {
                $totalQuantity += $groupItem['quantity'];
                $totalCost += $groupItem['cost'];
                $totalGain += $groupItem['gain'];
                $totalVentas += $groupItem['ventas'];
            }
            return [
                'id' => $prodGroup[0]['id'],
                'product' => $prodGroup[0]['product'],
                'category' => $prodGroup[0]['category'],
                'specification' => $prodGroup[0]['specification'],
                'unit_cost' => $prodGroup[0]['unit_cost'],
                'unit_price' => $prodGroup[0]['baseValue'],
                'quantity' => $totalQuantity,
                'cost' => $totalCost,
                'gain' => $totalGain,
                'ventas' => $totalVentas,
            ];
        })->toArray(); // [{"agua":{"id":<number>,"product":"Agua","category":"Bebidas","specification"...}}
        $data = array_values($data); // [{"id":<number>,"product":"Agua","category":"Bebidas","specification"...}]
        // Pagination
        $sliced = $shouldPaginate
            ? array_slice($data, $offset, $pageSize)
            : $data;
        return [
            'data' => $sliced,
            'total' => count($data)
        ];
    }

    public function reportePorProducto(Request $request)
    {
        try {
            $store = $this->authStore;
            $reportResults = $this->getReportData($store, $request, true);
            return response()->json([
                'status' => 'Success',
                'results' => $reportResults
            ], 200);
        } catch (\Exception $e) {
            Log::info("NO SE PUDO OBTENER LA DATA DEL REPORTE DE PRODUCTOS (TABLA)");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte',
                'results' => []
            ], 500);
        }
    }

    public function exportarReporte(Request $request)
    {
        try {
            $store = $this->authStore;

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
            $nombreEmpresa = ['titulo' => '', 'titulo2' => '', 'titulo3' => 'myPOS'];
            $num_fila = 5; // Ubicar los datos desde la fila 5
            array_push($lineaSheet, $nombreEmpresa);
            array_push($lineaSheet, []);
            array_push($lineaSheet, []);
            array_push($lineaSheet, []);
    
            $columnas = array(
                'Cantidad', // A5
                'Producto', // B5
                'Categoría', // C5
                'Especificación', // D5
                'Costo Unitario', //E5
                'Cantidad', //F5
                'Ventas', //G5
                'Costo', // H5
                'Ganancia' // I5
            );
            $campos = array();
            foreach ($columnas as $col) {
                $campos[$col] = $col;
            }
            array_push($lineaSheet, $campos);
            // Format column headers
            $sheet->getStyle('A5:H5')->getFont()->setBold(true)->setSize(12);
            $sheet->getColumnDimension('a')->setWidth(10);
            $sheet->getColumnDimension('b')->setWidth(40);
            $sheet->getColumnDimension('c')->setWidth(25);
            $sheet->getColumnDimension('d')->setWidth(30);
            $sheet->getColumnDimension('e')->setWidth(20);
            $sheet->getColumnDimension('f')->setWidth(20);
            $sheet->getColumnDimension('g')->setWidth(30);
            $sheet->getColumnDimension('h')->setWidth(20);
    
            $reportResults = $this->getReportData($store, $request, false);
            $data = $reportResults['data'];
            foreach ($data as $d) {
                array_push($lineaSheet, [
                    'Cantidad' => $d['quantity'],
                    'Producto' => $d['product'],
                    'Categoría' => $d['category'],
                    'Especificación' => $d['specification'] ?: 'Sin especificación',
                    'CostoUnitario' => round($d['unit_cost']/100 , 2) ?: '0.00',
                    'Ventas' => round($d['cost'] /100 + $d['gain']/100, 2) ?: '0.00', 
                    'Costo' => round($d['cost'] /100, 2) ?: '0.00',
                    'Ganancia' => round($d['gain']/100, 2) ?: '0.00'
                ]);
                $num_fila++;
            }
    
            $sheet->mergeCells('a1:H4');
            $sheet->getStyle('a1:H4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
    
            $sheet->getStyle('b1:H1')->getFont()->setBold(true)->setSize(28);
            $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
            $sheet->getStyle('b1:H1')->applyFromArray($st);
            // Format as currency 'Costo' and 'Ganancia' columns
            $sheet->getStyle('E6:F'.$num_fila)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->getStyle('G6:H'.$num_fila)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->getStyle('I6:H'.$num_fila)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->freezePane('A6');
            // Format 'Cantidad' column
            $sheet->getStyle('A6:A'.$num_fila)
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
            $sheet->getStyle('A5:H5')->applyFromArray($estilob);
    
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
            $nombreArchivo = 'Reporte de Productos vendidos ' . Carbon::today()->format("d-m-Y");
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
            Log::info("NO SE PUDO GENERAR EL EXCEL DEL REPORTE DE PRODUCTOS");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte'
            ], 500);
        }
    }
}
