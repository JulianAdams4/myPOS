<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\TimezoneHelper;
use App\Employee;

class ReportePorcentajeVentasXEmpleado extends Controller
{
    use AuthTrait;

    public $authUser;

    public function __construct()
    {
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    }

    public function getReportePorcentajeXEmpleadoXCategoria(Request $request)
    {
        $userStore = $this->authStore;

        $storeId = $userStore->id;
        $employeeId = $request->id_empleado;
        $startDate = TimezoneHelper::convertToServerDateTime($request->fecha_inicio."00:00:00", $userStore);
        $endDate = TimezoneHelper::convertToServerDateTime($request->fecha_fin."23:59:59", $userStore);

        $sql_total_item_facturado = "
            SELECT e.id AS id_empleado, e.name AS nombre_empleado, pc.id AS id_categoria, 
            pc.name AS nombre_categoria, pr.base_value as valor_base_producto, ii.total as total_item_facturado
            FROM orders AS o
            INNER JOIN employees AS e ON o.employee_id = e.id
            INNER JOIN order_details AS od ON od.order_id = o.id
            INNER JOIN product_details AS pd ON pd.id = od.product_detail_id
            INNER JOIN invoice_items AS ii ON ii.order_detail_id = od.id
            INNER JOIN products AS pr ON pr.id = pd.product_id
            INNER JOIN product_categories AS pc ON pc.id = pr.product_category_id 
            WHERE o.current_status = 'Creada' AND o.store_id = '$storeId'
            AND pr.name IS NOT NULL
            AND ii.created_at >= '$startDate'
            AND ii.created_at <= '$endDate'
            AND e.id = '$employeeId';
        ";

        $sql_total_item_facturado_respuesta = \DB::select($sql_total_item_facturado);
        $dic_total_item_facturado = [];
        foreach ($sql_total_item_facturado_respuesta as $total_item_facturado) {
            if (! array_key_exists($total_item_facturado->nombre_categoria, $dic_total_item_facturado)) {
                $dic_total_item_facturado[$total_item_facturado->nombre_categoria] = array();
            }
                $dic_total_item_facturado[$total_item_facturado->nombre_categoria][] = (object) [
                    'id_empleado' => $total_item_facturado->id_empleado,
                    'id_categoria' => $total_item_facturado->id_categoria,
                    'key' => $total_item_facturado->id_empleado . $total_item_facturado->id_categoria,
                    'nombre_empleado' => $total_item_facturado->nombre_empleado,
                    'nombre_categoria' => $total_item_facturado->nombre_categoria,
                    'total_item_facturado' => $total_item_facturado->total_item_facturado,
                ];
        }

        $sql_total_facturado = "
            SELECT SUM(ii.total) as total_facturado
            FROM orders AS o
            INNER JOIN employees AS e ON o.employee_id = e.id
            INNER JOIN order_details AS od ON od.order_id = o.id
            INNER JOIN product_details AS pd ON pd.id = od.product_detail_id
            INNER JOIN invoice_items AS ii ON ii.order_detail_id = od.id
            WHERE o.current_status = 'Creada'
            AND o.store_id = '$storeId'
            AND ii.created_at >= '$startDate'
            AND ii.created_at <= '$endDate' 
            AND e.id = '$employeeId';
        ";

        $sql_total_facturado_respuesta = \DB::select($sql_total_facturado);

        $obj_categorias = array(
            'total_facturado' => $sql_total_facturado_respuesta[0]->total_facturado,
            'dic_total_item_facturado' => $dic_total_item_facturado
        );

        return $obj_categorias;
    }

    public function exportarExcelReportePorcentajeXEmpleadoXCategoria(Request $request)
    {
        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Porcentaje Ventas");
        $excel->getDefaultStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $excel->getDefaultStyle()->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $lineaSheet = array();
        $nombreEmpresa = array();
        $ordenes = array();
        ###############  TITULO INICIO #################
        $titulo_empresa = "myPOS";
        $num_fila = 5; //Se empezará a ubicar los datos desde la fila 5 debido al logo
        $nombreEmpresa['titulo'] = '';
        $nombreEmpresa['titulo2'] = '';
        $nombreEmpresa['titulo3'] = $titulo_empresa;
        array_push($lineaSheet, $nombreEmpresa);

        array_push($lineaSheet, $ordenes); #push linea 2
        array_push($lineaSheet, $ordenes); #push linea 3
        array_push($lineaSheet, $ordenes); #push linea 4
        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            'Empleado',
            'Categoría',
            'Total Facturado Por Categoria',
            'Porcentaje'
        );

        $campos = array();
        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:E5')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('a')->setWidth(30); // Empleado
        $sheet->getColumnDimension('b')->setWidth(15); // Categoria
        $sheet->getColumnDimension('c')->setWidth(15); // Cantidad
        $sheet->getColumnDimension('d')->setWidth(15); // Subtotal
        $sheet->getColumnDimension('e')->setWidth(15); // Porcentaje
        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########
        $data = $request->dic_total_categorias_por_empleado;
        foreach ($data as $nombre_empleado => $categorias) {
            foreach ($categorias as $c) {
                $datos = array();
                $datos['Empleado'] = $nombre_empleado;
                $datos['Categoría'] = $c['nombre_categoria'];
                $datos['Total Facturado Por Categoria'] = $c['total_facturado_por_categoria'];
                $datos['Porcentaje'] = $c['porcentaje_categoria'];
                array_push($lineaSheet, $datos);
                $num_fila++;
            }
        }

        $datos['Empleado'] = "";
        $datos['Categoría'] = "Total Facturado";
        $datos['Total Facturado Por Categoria'] = $request->total_facturado;
        $datos['Porcentaje'] = "100%";
        array_push($lineaSheet, $datos);
        $num_fila++;
        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:e4');

        $sheet->getStyle('a1:e4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:d1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:d1')->applyFromArray($st);
        $sheet->getStyle('e6:d' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_PERCENTAGE_00
            );
        $sheet->freezePane('A6');

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
        $border_thin = array(
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                )
            ),
            'alignment' => array(
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            )
        );
        $sheet->getStyle('b6:b' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
        $sheet->getStyle('b' . $num_fila)->getFont()->setBold(true);
        $sheet->getStyle('a5:e5')->applyFromArray($estilob);
        $sheet->getStyle('c' . $num_fila . ':' . 'e' . $num_fila)->applyFromArray($border_thin);

        $sheet->fromArray($lineaSheet);

        $excel->setActiveSheetIndex(0);
        ############### LOGO  ##############
        $imagenGacela = public_path() . '/images/logo.png';

        $objGacela = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $objGacela->setName('Sample image');
        $objGacela->setDescription('Sample image');
        $objGacela->setPath($imagenGacela);
        $objGacela->setWidthAndHeight(160, 75);
        $objGacela->setCoordinates('A1');
        $objGacela->setWorksheet($excel->getActiveSheet());
        ############## FIN LOGO #############

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Xls');

        $nombreArchivo = 'Reporte Porcentaje de Ventas por Empleado por Categoria ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function getEmpleadosXTienda(Request $request)
    {
        try {
            $userStore = $this->authStore;
            $employees = Employee::select()
                ->where('store_id', $userStore->id)
                ->where('type_employee', 3)
                ->get();
            return $employees;
        } catch (\Exception $e) {
            return [];
        }
    }
}
