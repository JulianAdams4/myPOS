<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Log;
use App\Traits\AuthTrait;

class ReportePorcentajeVentasController extends Controller
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
            ], 401);
        }
    }

    public function getReportePorcentajeXCategoria(Request $request, $obj_fechas)
    {   
        try{
            $store = $this->authStore;

            // Como el get por id viene como parametro un objeto json casteado a string
            // entonces lo parseo a json para acceder a sus campos
            $obj_fechas_procesado = json_decode($obj_fechas);

            //Parseo de las fechas de inicio y fin para la obtención de la data
            $fecha_inicio_procesado = Carbon::parse($obj_fechas_procesado->fecha_inicio)->startOfDay();
            $fecha_fin_procesado = Carbon::parse($obj_fechas_procesado->fecha_fin)->endOfDay();
            
            // var_dump($fecha_inicio_procesado, $fecha_fin_procesado);


            ############################### INICIO OBTENCION DE DATOS ############################
            
            $sql_total_facturado = "
                SELECT 
                ROUND(SUM(od.total), 2) AS total_facturado
                -- pc.id as id_categoria, pr.id AS id_producto, pc.name AS nombre_categoria, pr.name AS nombre_producto, COUNT(pr.id)  AS no_ventas_categoria_producto, ROUND(SUM(od.total), 2) AS subtotal_categoria, (SUM(od.total)/(SELECT sum(iv.total) FROM invoices as iv WHERE DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado')) as porcentaje_categoria
                FROM invoices AS iv INNER JOIN orders AS o ON o.id = iv.order_id INNER JOIN order_details AS od ON od.order_id = o.id INNER JOIN product_details AS pd ON pd.id = od.product_detail_id INNER JOIN products AS pr ON pr.id = pd.product_id INNER JOIN product_categories AS pc ON pc.id = pr.product_category_id 
                WHERE o.store_id = '$store->id' AND pr.name IS NOT NULL AND DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado'
                AND pc.name NOT LIKE '%Bebida%'
                -- GROUP BY pc.name, pr.id, pr.name
                ;
            ";
            
            \DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
            
            $sql_total_facturado_respuesta = \DB::select($sql_total_facturado);
            if (is_null($sql_total_facturado_respuesta) ) {
                $total_facturado = 0;
            } else {
                $total_facturado = $sql_total_facturado_respuesta[0]->total_facturado;
            }

            $lista_categorias = [];
            $sql_bebidas = "
                SELECT 
                -- ROUND(SUM(od.total), 2) AS total_facturado
                pc.id as id_categoria, pr.id AS id_producto, pc.name AS nombre_categoria, pr.name AS nombre_producto, COUNT(pr.id)  AS no_ventas_categoria_producto, ROUND(SUM(od.total), 2) AS subtotal_categoria, (SUM(od.total)/(SELECT sum(iv.total) FROM invoices as iv WHERE DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado')) as porcentaje_categoria    
                FROM invoices AS iv INNER JOIN orders AS o ON o.id = iv.order_id INNER JOIN order_details AS od ON od.order_id = o.id INNER JOIN product_details AS pd ON pd.id = od.product_detail_id INNER JOIN products AS pr ON pr.id = pd.product_id INNER JOIN product_categories AS pc ON pc.id = pr.product_category_id 
                WHERE o.store_id = '$store->id' AND pr.name IS NOT NULL AND DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado'
                AND pc.name LIKE '%Bebida%'
                GROUP BY pc.id, pc.name, pr.id, pr.name
                ;
            ";
            $sql_bebidas_respuesta = \DB::select($sql_bebidas);        
            // en caso que exista mas de una categoria en el query
            // se debe de sumar el total y porcentaje de cada una
            $subtotal_categoria = 0;
            $id_categoria = 0;
            $cantidad_categoria = 0;
            $lista_productos_categoria_bebidas = [];
            foreach ($sql_bebidas_respuesta as $categoria) {
                $subtotal_categoria += $categoria->subtotal_categoria;
                $id_categoria = $categoria->id_categoria;
                $cantidad_categoria += $categoria->no_ventas_categoria_producto;
                
                $lista_productos_categoria_bebidas[] = (object) [
                    'nombre_producto' => $categoria->nombre_producto,
                    'no_ventas_categoria_producto' => $categoria->no_ventas_categoria_producto,
                    'subtotal_categoria'=> $categoria->subtotal_categoria,            
                    'porcentaje' => $categoria->porcentaje_categoria,
                ];
            }
            
            $bebidas = array(
                'id_categoria' => $id_categoria,
                'nombre_categoria' => 'Bebidas',
                'cantidad_categoria' => $cantidad_categoria,
                'subtotal_categoria' => $subtotal_categoria,
                // para evitar la division para cero debemos de preguntar si 
                // el total facturado es mayor a cero, es decir si existen productos
                // en el rango de fechas recibido
                'porcentaje' => $total_facturado > 0 ? bcdiv($subtotal_categoria, $total_facturado, 5)*100 : 0,
                'productos' => $lista_productos_categoria_bebidas,
            );

            $lista_categorias = [];
            $sql_comidas = "
                SELECT 
                -- ROUND(SUM(od.total), 2) AS total_facturado
                pc.id as id_categoria, pr.id AS id_producto, pc.name AS nombre_categoria, pr.name AS nombre_producto, COUNT(pr.id)  AS no_ventas_categoria_producto, ROUND(SUM(od.total), 2) AS subtotal_categoria, (SUM(od.total)/(SELECT sum(iv.total) FROM invoices as iv WHERE DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado')) as porcentaje_categoria
                FROM invoices AS iv INNER JOIN orders AS o ON o.id = iv.order_id INNER JOIN order_details AS od ON od.order_id = o.id INNER JOIN product_details AS pd ON pd.id = od.product_detail_id INNER JOIN products AS pr ON pr.id = pd.product_id INNER JOIN product_categories AS pc ON pc.id = pr.product_category_id 
                WHERE o.store_id = '$store->id' AND pr.name IS NOT NULL AND DATE(iv.created_at) >= '$fecha_inicio_procesado' AND DATE(iv.created_at) <= '$fecha_fin_procesado'
                AND pc.name NOT LIKE '%Bebida%'
                GROUP BY pc.id, pc.name, pr.id, pr.name
                ;
            ";
            $sql_comidas_respuesta = \DB::select($sql_comidas);        
            // en caso que exista mas de una categoria en el query
            // se debe de sumar el total y porcentaje de cada una
            $subtotal_categoria = 0;
            $id_categoria = 0;
            $cantidad_categoria = 0;
            $lista_productos_categoria_comidas = [];
            foreach ($sql_comidas_respuesta as $categoria) {
                $subtotal_categoria += $categoria->subtotal_categoria;
                $id_categoria = $categoria->id_categoria;
                $cantidad_categoria += $categoria->no_ventas_categoria_producto;

                $lista_productos_categoria_comidas[] = (object) [
                    'nombre_producto' => $categoria->nombre_producto,
                    'no_ventas_categoria_producto' => $categoria->no_ventas_categoria_producto,
                    'subtotal_categoria'=> $categoria->subtotal_categoria,            
                    'porcentaje' => $categoria->porcentaje_categoria,
                ];
            }

            $comidas = array(
                'id_categoria' => $id_categoria,
                'nombre_categoria' => 'Comidas',
                'cantidad_categoria' => $cantidad_categoria,
                'subtotal_categoria' => $subtotal_categoria,
                // para evitar la division para cero debemos de preguntar si 
                // el total facturado es mayor a cero, es decir si existen productos
                // en el rango de fechas recibido
                'porcentaje' => $total_facturado > 0 ? bcdiv($subtotal_categoria, $total_facturado, 5)*100 : 0,
                'productos' => $lista_productos_categoria_comidas,
            );

            array_push($lista_categorias, $bebidas, $comidas);

            ############################### FIN OBTENCION DE DATOS ############################

            ############################### INICIO FORMATEO DE RESPUESTA ############################

            // envio el monto_total_facturado, el numero de categorias presentes y la lista de categorias
            $respuesta_procesada_final = [];

            // finalmente creo los objetos que enviare
            $obj_categorias = array(
                'total_facturado' => $total_facturado,
                'no_categorias' => '2',
                'categorias' => $lista_categorias,
            );
            
            array_push($respuesta_procesada_final, $obj_categorias);

            ############################### FIN FORMATEO DE RESPUESTA ############################
            return $respuesta_procesada_final;
        }catch(\Exception $e){
            return response()->json([
                'status' => 'Estamos trabajando para mejorar el reporte.',
            ], 400);
        }
    }

    public function exportarExcelReportePorcentajeXCategoria(Request $request)
    {
        Log::info("exportarExcelReportePorcentajeXCategoria");

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
            'Categoría',
            'Cantidad',
            'Subtotal',
            'Porcentaje'
        );
        $campos = array();

        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:D5')->getFont()->setBold(true)->setSize(12);

        $sheet->getColumnDimension('a')->setWidth(30);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(15);
        $sheet->getColumnDimension('d')->setWidth(15);

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########

        $data = $request->categorias;
        foreach ($data as $d) {
            $datos = array();
            $datos['Categoría'] = $d['nombre_categoria'];
            $datos['Cantidad'] = $d['cantidad_categoria'];
            $datos['Subtotal'] = $d['subtotal_categoria'];
            $datos['Porcentaje'] = $d['porcentaje']/100;

            array_push($lineaSheet, $datos);
            $num_fila++;
        }

        $datos['Categoría'] = "";
        $datos['Cantidad'] = "Total Facturado";
        $datos['Monto'] = $request->total_facturado;
        $datos['Porcentaje'] = "100%";
        array_push($lineaSheet, $datos);
        $num_fila++;
        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:d4');

        $sheet->getStyle('a1:d4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:d1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:d1')->applyFromArray($st);
        $sheet->getStyle('c6:c' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('d6:d' . $num_fila)
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
        $sheet->getStyle('a5:d5')->applyFromArray($estilob);
        $sheet->getStyle('b' . $num_fila . ':' . 'd' . $num_fila)->applyFromArray($border_thin);

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

        $nombreArchivo = 'Reporte Porcentaje de Ventas por Categoria ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }
}
