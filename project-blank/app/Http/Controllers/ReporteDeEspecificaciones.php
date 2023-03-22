<?php

namespace App\Http\Controllers;

ini_set('max_execution_time', 300);

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\TimezoneHelper;
use App\Order;
use App\OrderDetail;
use App\OrderProductSpecification;
use Illuminate\Support\Facades\DB;
use Log;

class ReporteDeEspecificaciones extends Controller
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

    /**
     *  Nota:
     */
    public function getReportData($options, $shouldPaginate = true)
    {
        $store = $this->authStore;
        // Params from request
        $storeIds = $options->ids ? implode(',', $options->ids) : "$store->id"; // 1, 2, 3, ...
        $startDate = TimezoneHelper::convertToServerDateTime($options->start_date, $store);
        $endDate = TimezoneHelper::convertToServerDateTime($options->end_date, $store);
        $currentPage = $options->current_page;
        $pageSize = $options->page_size;
        $sortBy = $options->sort_by ?: 'date';
        $sortOrder = $options->sort_order ?: 'ascend';
        $strLike = $options->searchStr ?: '';
        $offset = ($currentPage * $pageSize) - $pageSize; // Pagination

        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
        $data = collect(
            DB::select(
                DB::raw("SELECT ops.name_specification specification,
                    sc.name category,
                    COUNT(ops.id) quantity,
                    ops.value single_value,
                    COUNT(ops.id)*ops.value total_value
                FROM order_product_specifications ops
                LEFT JOIN order_details od on ops.order_detail_id = od.id
                LEFT JOIN orders o on o.id = od.order_id
                LEFT JOIN specifications s on s.id = ops.specification_id
                LEFT JOIN specification_categories sc on sc.id = s.specification_category_id
                WHERE o.preorder = 0
                AND o.store_id IN ($storeIds)
                AND o.created_at BETWEEN '$startDate' AND '$endDate'
                GROUP BY specification;")
            )
        );

        // Sort
        if ($sortOrder === 'ascend') {
            $data = $data->sortBy($sortBy);
        } elseif ($sortOrder === 'descend') {
            $data = $data->sortByDesc($sortBy);
        }

        // WhereLike in collection
        if ($strLike) {
            // $offset = 0; // Search results in first page
            $data = $data->filter(function ($item) use ($strLike) {
                // Searchable columns
                $s = false !== stristr($item['specification'], $strLike);
                $c = false !== stristr($item['category'], $strLike);
                return $s || $c;
            });
        }

        $data = $data->toArray();
        $data = array_values($data);
        // Pagination
        $sliced = $shouldPaginate
            ? array_slice((array) $data, $offset, $pageSize)
            : $data;

        return ['data' => $sliced, 'total' => count($data)];
    }


    public function getTableData(Request $request)
    {
        try {
            $store = $this->authStore;
            $reportResults = $this->getReportData($request, true);
            return response()->json([
                'status' => 'Success',
                'results' => $reportResults
            ], 200);
        } catch (\Exception $e) {
            Log::info("NO SE PUDO OBTENER EL REPORTE DE TOPPINGS POR CATEGORIA");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte',
                'results' => ['data' => [], 'total' => 0]
            ], 500);
        }
    }


    public function exportExcel(Request $request)
    {
        try {
            $store = $this->authStore;

            $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $excel->getProperties()->setTitle("myPOS");

            // Primera hoja donde apracerán detalles del objetivo
            $sheet = $excel->getActiveSheet();
            $excel->getActiveSheet()->setTitle("Reporte de Especificaciones"); // Max 31 chars
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
                'Especificación', // A5
                'Categoría de toppings', // B5
                'Cantidad', // C5
                'Precio unitario', //D5
                'Precio Total' //E5
            );
            $campos = array();
            foreach ($columnas as $col) {
                $campos[$col] = $col;
            }
            array_push($lineaSheet, $campos);
            // Format column headers
            $sheet->getStyle('A5:F5')->getFont()->setBold(true)->setSize(12);
            $sheet->getColumnDimension('a')->setWidth(50);
            $sheet->getColumnDimension('b')->setWidth(25);
            $sheet->getColumnDimension('c')->setWidth(15);
            $sheet->getColumnDimension('d')->setWidth(25);
            $sheet->getColumnDimension('e')->setWidth(25);

            $reportResults = $this->getReportData($request, false);
            $data = $reportResults['data'];
            foreach ($data as $d) {
                $e = json_decode(json_encode($d), true);
                array_push($lineaSheet, [
                    'Especificación' => $e['specification'],
                    'Categoría de toppings' => $e['category'],
                    'Cantidad' => $e['quantity'],
                    'Unit' =>$e['single_value'] == 0 ? "0.00" : $e['single_value'] / 100,
                    'Total' => $e['total_value'] == 0 ? "0.00" : $e['total_value'] / 100
                ]);
                $num_fila++;
            }

            $sheet->mergeCells('a1:E4');
            $sheet->getStyle('a1:E4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle('D6:E' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->getStyle('b1:E1')->getFont()->setBold(true)->setSize(28);
            $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
            $sheet->getStyle('b1:E1')->applyFromArray($st);
            $sheet->freezePane('A6');
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
            $sheet->getStyle('A5:E5')->applyFromArray($estilob);

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
            $nombreArchivo = 'Reporte de Especificaciones ' . Carbon::today()->format("d-m-Y");
            $response = response()->streamDownload(function () use ($objWriter) {
                $objWriter->save('php://output');
            });
            $response->setStatusCode(200);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '.xls"');
            $response->send();
        } catch (\Exception $e) {
            Log::info("NO SE PUDO GENERAR EL EXCEL DEL REPORTE DE TOPPINGS POR CATEGORIA");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte'
            ], 500);
        }
    }

    public function getToppingsByProductData($options, $shouldPaginate = true)
    {
        $store = $this->authStore;
        // Params from request
        $storeIds = $options->ids ? implode(',', $options->ids) : "$store->id"; // 1, 2, 3, ...
        $startDate = TimezoneHelper::convertToServerDateTime($options->start_date, $store);
        $endDate = TimezoneHelper::convertToServerDateTime($options->end_date, $store);
        $currentPage = $options->current_page;
        $pageSize = $options->page_size;
        $sortBy = $options->sort_by ?: 'date';
        $sortOrder = $options->sort_order ?: 'ascend';
        $strLike = $options->searchStr ?: '';
        $offset = ($currentPage * $pageSize) - $pageSize; // Pagination

        $data = collect(
            DB::select(
                DB::raw("SELECT ops.name_specification specification,
                    od.name_product product,
                    COUNT(ops.id) quantity,
                    ops.value single_value,
                    COUNT(ops.id)*ops.value total_value
                FROM order_product_specifications ops
                LEFT JOIN order_details od on ops.order_detail_id = od.id
                LEFT JOIN orders o on o.id = od.order_id
                LEFT JOIN specifications s on s.id = ops.specification_id
                LEFT JOIN specification_categories sc on sc.id = s.specification_category_id
                WHERE o.preorder = 0
                AND o.store_id IN ($storeIds)
                AND o.created_at BETWEEN '$startDate' AND '$endDate'
                GROUP BY ops.name_specification")
            )
        );

        // Sort
        if ($sortOrder === 'ascend') {
            $data = $data->sortBy($sortBy);
        } elseif ($sortOrder === 'descend') {
            $data = $data->sortByDesc($sortBy);
        }

        // WhereLike in collection
        if ($strLike) {
            // $offset = 0; // Search results in first page
            $data = $data->filter(function ($item) use ($strLike) {
                // Searchable columns
                $s = false !== stristr($item['specification'], $strLike);
                $c = false !== stristr($item['product'], $strLike);
                return $s || $c;
            });
        }

        $data = $data->toArray();
        $data = array_values($data);
        // Pagination
        $sliced = $shouldPaginate
            ? array_slice((array) $data, $offset, $pageSize)
            : $data;

        return ['data' => $sliced, 'total' => count($data)];
    }

    public function getToppingsByProduct(Request $request)
    {
        try {
            $store = $this->authStore;
            $reportResults = $this->getToppingsByProductData($request, true);
            return response()->json([
                'status' => 'Success',
                'results' => $reportResults
            ], 200);
        } catch (\Exception $e) {
            Log::info("NO SE PUDO OBTENER EL REPORTE DE ESPECIFICACIONES POR PRODUCTO");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte',
                'results' => ['data' => [], 'total' => 0]
            ], 500);
        }
    }

    public function getToppingsByProductExcel(Request $request)
    {
        try {
            $store = $this->authStore;

            $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $excel->getProperties()->setTitle("myPOS");

            // Primera hoja donde apracerán detalles del objetivo
            $sheet = $excel->getActiveSheet();
            $excel->getActiveSheet()->setTitle("Reporte de Especificaciones"); // Max 31 chars
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
                'Especificación', // A5
                'Producto', // B5
                'Cantidad', // C5
                'Precio Unitario', //D5
                'Precio Total' //E5
            );
            $campos = array();
            foreach ($columnas as $col) {
                $campos[$col] = $col;
            }
            array_push($lineaSheet, $campos);
            // Format column headers
            $sheet->getStyle('A5:F5')->getFont()->setBold(true)->setSize(12);
            $sheet->getColumnDimension('a')->setWidth(50);
            $sheet->getColumnDimension('b')->setWidth(25);
            $sheet->getColumnDimension('c')->setWidth(15);
            $sheet->getColumnDimension('d')->setWidth(25);
            $sheet->getColumnDimension('e')->setWidth(15);

            $reportResults = $this->getToppingsByProductData($request, false);
            $data = $reportResults['data'];
            foreach ($data as $d) {
                array_push($lineaSheet, [
                    'Especificación' => $d['specification'],
                    'Producto' => $d['product'],
                    'Cantidad' => $d['quantity'],
                    'Unit' => $d['single_value'] == 0 ? "0.00" : $d['single_value'],
                    'Total' => $d['total_value'] == 0 ? "0.00" : $d['total_value']
                ]);
                $num_fila++;
            }

            $sheet->mergeCells('a1:E4');
            $sheet->getStyle('a1:E4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle('D6:E' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->getStyle('b1:E1')->getFont()->setBold(true)->setSize(28);
            $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
            $sheet->getStyle('b1:E1')->applyFromArray($st);
            $sheet->freezePane('A6');
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
            $sheet->getStyle('A5:E5')->applyFromArray($estilob);

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
            $nombreArchivo = 'Reporte de Especificaciones ' . Carbon::today()->format("d-m-Y");
            $response = response()->streamDownload(function () use ($objWriter) {
                $objWriter->save('php://output');
            });
            $response->setStatusCode(200);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '.xls"');
            $response->send();
        } catch (\Exception $e) {
            Log::info("NO SE PUDO GENERAR EL EXCEL DEL REPORTE DE TOPPINGS POR PRODUCTO");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte'
            ], 500);
        }
    }
}
