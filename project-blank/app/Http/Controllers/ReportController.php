<?php

namespace App\Http\Controllers;

use Log;
use App\Order;
use App\Company;
use Carbon\Carbon;
use App\OrderDetail;
use App\PaymentType;
use App\Traits\AuthTrait;
use Illuminate\Http\Request;
use App\Traits\ReportHelperTrait;
use App\Traits\TimezoneHelper;
use App\CashierBalance;
use Illuminate\Support\Facades\DB;
use App\ExportsExcel\ExcelCashierExpenses;
use App\ExportsExcel\ExcelOrdersByEmployee;
use PDF;
use App\ExpensesBalance;
use App\Employee;
use App\Helpers\PrintService\PrintJobHelper;
use App\StockTransfer;
use App\Store;
use App\Traits\CashierBalanceHelper;
use App\StoreConfig;
use DateTime;
use stdClass;
use App\Helper;

class ReportController extends Controller
{
    use ReportHelperTrait, AuthTrait, TimezoneHelper, CashierBalanceHelper;

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

    public function invoiceDataReport(Request $request)
    {
        Log::info("invoiceDataReport");

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Facturas");
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
            'Fecha',
            'Factura',
            'Cliente',
            'Documento',
            'Subtotal',
            'Porc Desc',
            'Descuento',
            'Subtotal Desc',
            'Impuesto',
            'Total',
            'Propina',
            'Total Pagado',
            'Forma Pago',
            'Mesa',
            'Delivery',
            'Cortesía'
        );
        $campos = array();

        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:P5')->getFont()->setBold(true)->setSize(12);

        $sheet->getColumnDimension('a')->setWidth(25);
        $sheet->getColumnDimension('b')->setWidth(25);
        $sheet->getColumnDimension('c')->setWidth(25);
        $sheet->getColumnDimension('d')->setWidth(25);
        $sheet->getColumnDimension('e')->setWidth(20);
        $sheet->getColumnDimension('f')->setWidth(15);
        $sheet->getColumnDimension('g')->setWidth(15);
        $sheet->getColumnDimension('h')->setWidth(15);
        $sheet->getColumnDimension('i')->setWidth(20);
        $sheet->getColumnDimension('j')->setWidth(15);
        $sheet->getColumnDimension('k')->setWidth(15);
        $sheet->getColumnDimension('l')->setWidth(15);
        $sheet->getColumnDimension('m')->setWidth(25);
        $sheet->getColumnDimension('n')->setWidth(25);
        $sheet->getColumnDimension('o')->setWidth(25);
        $sheet->getColumnDimension('p')->setWidth(25);

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########
        $sumatest = 0;
        $data = $request->data;
        foreach ($data as $d) {
            $datos = array();

            $payments = $d['payments'];
            $total = round($d['total'] / 100, 2);
            $tip = round($d['tip'] / 100, 2);
            $revoked = $d['revoked'];

            $paymentMethod = '';
            if (is_null($d['integration_name']) && is_null($d['payments'])) {
                $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
            } else {
                if (!is_null($d['integration_name'])) {
                    $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, $d['integration_name']);
                } else {
                    // flags para solo ingresar una vez el metodo al array
                    $cash = false;
                    $debit = false;
                    $credit = false;
                    $transfer = false;
                    $rappiPay = false;
                    $others = false;

                    foreach ($payments as $payment) {
                        switch ($payment['type']) {
                            case PaymentType::CASH:
                                $cash = true;
                                break;
                            case PaymentType::DEBIT:
                                $debit = true;
                                break;
                            case PaymentType::CREDIT:
                                $credit = true;
                                break;
                            case PaymentType::TRANSFER:
                                $transfer = true;
                                break;
                            case PaymentType::RAPPI_PAY:
                                $rappiPay = true;
                                break;
                            case PaymentType::OTHER:
                                $others = true;
                                break;
                        }
                    }

                    if ($cash) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                    }
                    if ($debit) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Débito');
                    }
                    if ($credit) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Crédito');
                    }
                    if ($transfer) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Transferencia');
                    }
                    if ($rappiPay) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Rappi Pay');
                    }
                    if ($others) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Otros');
                    }
                }
            }

            if ($revoked) {
                $paymentMethod = 'Anulada';
            }
            //$totalNotRounded = round($d['subtotal'] / 100, 2) + round($d['tax'] / 100, 2);
            //$totalNotRounded = round(($d['subtotal'] + $d['tax']) / 100, 2, PHP_ROUND_HALF_DOWN);
            $totalNotRounded = ($d['subtotal'] + $d['tax']) / 100;
            $datos['Fecha'] = $d['created_at'];
            $datos['Factura'] = $d['invoice_number'];
            $datos['Cliente'] = $d['name'];
            $datos['Documento'] = $d['document'];
            $datos['Subtotal'] = round($d['undiscounted_subtotal'] / 100, 2);
            $datos['Porc Desc'] = round($d['discount_percentage'], 2) . '%';
            $datos['Descuento'] = round($d['discount_value'] / 100, 2);
            $datos['Subtotal Desc'] = round($d['subtotal'] / 100, 4);
            $datos['Impuesto'] = round($d['tax'] / 100, 4);
            $datos['Total'] = round($totalNotRounded, 4);
            $datos['Propina'] = $tip;
            $datos['Total Pagado'] = round($totalNotRounded + $tip, 4);
            $datos['Forma Pago'] = $paymentMethod;
            $datos['Mesa'] = $d['spot'];
            $datos['Delivery'] = $d['integration_id'];
            $datos['Coresia'] =  $d['courtesy'] == 1 ? "Cortesía" : "No Cortesía";
            $sumatest = $sumatest + $totalNotRounded;
            array_push($lineaSheet, $datos);
            $num_fila++; #8
        }
        Log::info("sumatest: " . $sumatest);

        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:p4');

        $sheet->getStyle('a1:p4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:p1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:p1')->applyFromArray($st);

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
        $sheet->getStyle('e6:e' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('g6:l' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('a5:p5')->applyFromArray($estilob);
        $sheet->getStyle('b6:b' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
        $sheet->getStyle('d6:d' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
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
        // new
        $nombreArchivo = 'Reporte de Facturas ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();

        // old
        // header("Access-Control-Allow-Origin: *");
        // header('Content-Type: application/vnd.ms-excel');
        // header("Content-Disposition: attachment; filename=\".$nombreArchivo.xls\"");
        // header('Cache-Control: max-age=0');
        // $objWriter->save('php://output');
    }


    public function invoiceDataReportMultiStore(Request $request)
    {
        // return $request->data;
        Log::info("invoiceDataReport");

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Facturas");
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
            'Fecha',
            'Factura',
            'Cliente',
            'Documento',
            'Subtotal',
            'Porc Desc',
            'Descuento',
            'Subtotal Desc',
            'Impuesto',
            'Total',
            'Propina',
            'Total Pagado',
            'Medio de pago',
            'Mesa',
            'Tienda',
            'Cortesía'
        );
        $campos = array();

        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:P5')->getFont()->setBold(true)->setSize(10);

        $sheet->getColumnDimension('a')->setWidth(25);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(25);
        $sheet->getColumnDimension('d')->setWidth(20);
        $sheet->getColumnDimension('e')->setWidth(15);
        $sheet->getColumnDimension('f')->setWidth(15);
        $sheet->getColumnDimension('g')->setWidth(15);
        $sheet->getColumnDimension('h')->setWidth(20);
        $sheet->getColumnDimension('i')->setWidth(15);
        $sheet->getColumnDimension('j')->setWidth(15);
        $sheet->getColumnDimension('k')->setWidth(15);
        $sheet->getColumnDimension('l')->setWidth(25);
        $sheet->getColumnDimension('m')->setWidth(25);
        $sheet->getColumnDimension('n')->setWidth(25);
        $sheet->getColumnDimension('o')->setWidth(25);
        $sheet->getColumnDimension('p')->setWidth(25);

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########

        $data = $request->data;
        foreach ($data as $d) {
            $datos = array();

            $payments = $d['payments'];
            $total = round($d['total'] / 100, 2);
            $tip = round($d['tip'] / 100, 2);

            $paymentMethod = '';
            if ($d['revoked']) {
                $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Anulada');
            } else {
                if (
                    is_null($d['integration_name'])
                    && is_null($d['payments'])
                ) {
                    $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                } else {
                    if (!is_null($d['integration_name'])) {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, $d['integration_name']);
                    } else {
                        // flags para solo ingresar una vez el metodo al array
                        $cash = false;
                        $debit = false;
                        $credit = false;
                        $transfer = false;
                        $rappiPay = false;
                        $others = false;

                        foreach ($payments as $payment) {
                            switch ($payment['type']) {
                                case PaymentType::CASH:
                                    $cash = true;
                                    break;
                                case PaymentType::DEBIT:
                                    $debit = true;
                                    break;
                                case PaymentType::CREDIT:
                                    $credit = true;
                                    break;
                                case PaymentType::TRANSFER:
                                    $transfer = true;
                                    break;
                                case PaymentType::RAPPI_PAY:
                                    $rappiPay = true;
                                    break;
                                case PaymentType::OTHER:
                                    $others = true;
                                    break;
                            }
                        }

                        if ($cash) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                        }
                        if ($debit) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Débito');
                        }
                        if ($credit) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Crédito');
                        }
                        if ($transfer) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Transferencia');
                        }
                        if ($rappiPay) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Rappi Pay');
                        }
                        if ($others) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Otros');
                        }
                    }
                }
            }

            $datos['Fecha'] = $d['created_at'];
            $datos['Factura'] = $d['invoice_number'];
            $datos['Cliente'] = $d['name'];
            $datos['Documento'] = $d['document'];
            $datos['Subtotal'] = round($d['undiscounted_subtotal'] / 100, 2);
            $datos['Porc Desc'] = round($d['discount_percentage'], 2) . '%';
            $datos['Descuento'] = round($d['discount_value'] / 100, 2);
            $datos['Subtotal Desc'] = round($d['subtotal'] / 100, 2);
            $datos['Impuesto'] = round($d['tax'] / 100, 2);
            $datos['Total'] = $total;
            $datos['Propina'] = $tip;
            $datos['Total Pagado'] = round($total + $tip, 2);
            $datos['Forma Pago'] = $paymentMethod;
            $datos['Mesa'] = $d['spot'];
            $datos['Tienda'] = $d['store_name'];
            $datos['Coresia'] =  $d['courtesy'] == 1 ? "Cortesía" : "No Cortesía";

            array_push($lineaSheet, $datos);
            $num_fila++; #8
        }

        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:p4');

        $sheet->getStyle('a1:p4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:p1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:p1')->applyFromArray($st);

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
        $sheet->getStyle('e6:e' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('g6:l' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('a5:p5')->applyFromArray($estilob);
        $sheet->getStyle('b6:b' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
        $sheet->getStyle('d6:d' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
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

        $nombreArchivo = 'Reporte de Facturas ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function transactionDetailsReport(Request $request)
    {
        Log::info("transactionDetailsReport");
        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");
        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Detalle de Transacciones");
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
            'Fecha',
            'Factura',
            'Cliente',
            'Categoría',
            'Producto',
            'Detalle',
            'Cantidad',
            'Precio Producto',
            'Subtotal',
            'Descuento',
            'Subtotal Desc',
            'Impuesto',
            'Total',
            'Forma Pago',
            'Mesa',
            'Usuario',
            'Cortesía'
        );
        $campos = array();
        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);
        $sheet->getStyle('A5:P5')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('a')->setWidth(25);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(25);
        $sheet->getColumnDimension('d')->setWidth(25);
        $sheet->getColumnDimension('e')->setWidth(25);
        $sheet->getColumnDimension('f')->setWidth(20);
        $sheet->getColumnDimension('g')->setWidth(15);
        $sheet->getColumnDimension('h')->setWidth(15);
        $sheet->getColumnDimension('i')->setWidth(20);
        $sheet->getColumnDimension('j')->setWidth(15);
        $sheet->getColumnDimension('k')->setWidth(15);
        $sheet->getColumnDimension('l')->setWidth(15);
        $sheet->getColumnDimension('m')->setWidth(30);
        $sheet->getColumnDimension('n')->setWidth(30);
        $sheet->getColumnDimension('o')->setWidth(30);
        $sheet->getColumnDimension('p')->setWidth(30);
        $sheet->getColumnDimension('q')->setWidth(30);
        ######### FIN FILA DE TITULOS DEL REPORTE #########
        ######### POBLACIÓN DE DATOS DEL REPORTE ##########
        $data = $request->data;
        foreach ($data as $d) {
            $details = "";
            foreach ($d['specifications'] as $specification) {
                $details .= " + " . $specification['name_specification'];
            }
            $datos = array();
            $subtotal = 0;
            $datos['Fecha'] = Carbon::parse($d['date']['date']);
            $datos['Factura'] = $d['fact'];
            $datos['Cliente'] = $d['customer'];
            $datos['Category'] = $d['category'];
            $datos['Producto'] = $d['product'];
            $datos['Detalle'] = substr($details, 3);
            $datos['Cantidad'] = $d['quantity'];
            $datos['Precio Producto'] = $d['value'];
            $datos['Subtotal'] = $d['subtotal'];
            $datos['Descuento'] = $d['discounted_value'];
            $datos['Subtotal Desc'] = $d['discounted_subtotal'];
            $datos['Impuesto'] = $d['tax'];
            $datos['Total'] = $d['tax'] + $d['discounted_subtotal'];
            $datos['Forma Pago'] = $d["payment_method"];
            $datos['Mesa'] = $d['spot'];
            $datos['Usuario'] = $d['employee'];
            $datos['Coresia'] =  $d['courtesy'] == 1 ? "Cortesía" : "No Cortesía";
            array_push($lineaSheet, $datos);
            $num_fila++; #8
        }
        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########
        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:p4');
        $sheet->getStyle('a1:p4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('b1:p1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:p1')->applyFromArray($st);
        $sheet->getStyle('g6:k' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
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
        $sheet->getStyle('b6:b' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER
            );
        $sheet->getStyle('a5:p5')->applyFromArray($estilob);
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
        $nombreArchivo = 'Reporte de Transacciones ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }


    public function transactionDetailsReportColombiaPDF(Request $request)
    {
        $this->authStore->load('currentCashierBalance');
        $cashierBalanceId = $request->data['cashier_balance_id'];
        $cashierBalance = $cashierBalanceId === null ?
            $this->authStore->currentCashierBalance :
            CashierBalance::where('id', $cashierBalanceId)
            ->where('store_id', $this->authStore->id)
            ->first();
        $date = date('m/d/Y h:i:s a', time());

        $endDate = TimezoneHelper::localizedNowDateForStore($this->authStore)->toDateTimeString();

        if ($cashierBalance->date_close != null) {
            $endDate = $cashierBalance->date_close . " " . $cashierBalance->hour_close;
        }

        $data = ReportHelperTrait::transactionsClosingCashier($cashierBalanceId, $this->authStore);

        $country = $this->authStore->country_code;
        $company = Company::whereId($this->authStore->company_id)->first()->name;

        Log::info("transactionDetailsReportColombiaPDF");
        Log::info($cashierBalanceId);

        //******************** Starting Format ************************//

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("Comprobante de Informe Diario" . date('m/d/Y a', time()));

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Detalle de Transacciones");
        $excel->getDefaultStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $excel->getDefaultStyle()->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $sheet->getPageMargins()
            ->setLeft(0.1)
            ->setRight(0.1)
            ->setTop(0.1)
            ->setBottom(0.1)
            ->setHeader(0);

        //$excel->getActiveSheet()->getPageSetup()
        //->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $excel->getActiveSheet()->getPageSetup()
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $excel->getActiveSheet()->getPageSetup()->setFitToWidth(1);
        $excel->getActiveSheet()->getPageMargins()->setLeft(0.25);
        $excel->getActiveSheet()->setShowGridLines(false);
        $estiloh = array(
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                    'color' => ['rgb' => 'FFFFFF'],
                )
            ),
            'alignment' => array(
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            )
        );

        $estiloMedium = array(
            'alignment' => array(
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            )
        );

        $estiloRight = array(
            'alignment' => array(
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            )
        );
        date_default_timezone_set('America/Bogota');

        $num_fila = 5; //Se empezará a ubicar los datos desde la fila 5 debido al logo

        $lineaSheet = array();
        $nombreEmpresa = array();
        $title = array();
        $ordenes = array();

        ###############  TITULO INICIO #################
        $titulo_empresa = $this->authStore->name;

        $nombreEmpresa['titulo1'] = $company;
        $nombreEmpresa['titulo2'] = '';
        $nombreEmpresa["titulo3"] = '';
        $nombreEmpresa["titulo4"] = '';
        $nombreEmpresa["titulo5"] = '';
        $nombreEmpresa["titulo6"] = '';
        $nombreEmpresa["titulo7"] = '';
        $nombreEmpresa["titulo8"] = '';
        $nombreEmpresa["titulo9"] = '';
        $nombreEmpresa['fecha'] = $endDate;

        $sheet->getStyle('a1')->applyFromArray($estiloh);
        $sheet->getStyle('h1')->applyFromArray($estiloh);
        $sheet->getStyle('a1:h1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
        $sheet->getStyle('h1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
        $sheet->getStyle('A1:h1')->getFont()->setBold(true);
        $sheet->mergeCells('a1:i1');
        $sheet->mergeCells('j1:k1');
        array_push($lineaSheet, $nombreEmpresa);

        ############# Codigo de segunda linea #####################
        $ordenes['NIT'] = 'NIT: ' . $this->authStore->company->TIN;
        $ordenes['titulo2'] = '';
        $ordenes["titulo3"] = '';
        $ordenes["titulo4"] = '';
        $ordenes["titulo5"] = '';
        $ordenes["titulo6"] = '';
        $ordenes["titulo7"] = '';
        $ordenes["titulo8"] = '';
        $ordenes["titulo9"] = '';
        $ordenes["titulo10"] = '';
        $ordenes["titulo11"] = '';
        array_push($lineaSheet, $ordenes);
        $sheet->getStyle('a2:j2')->applyFromArray($estiloh);
        $sheet->mergeCells('a2:j2');
        array_push($lineaSheet, array());
        $title["title"] = "COMPROBANTE DE INFORME DIARIO";

        $sheet->getStyle('A4:J4')->getFont()->setBold(true)->setSize(20);
        $sheet->getStyle('a4:j4')->applyFromArray($estiloh);
        $sheet->mergeCells('a4:j4');
        array_push($lineaSheet, $title);

        ################################ Segunda Parte #############################
        array_push($lineaSheet, array());
        $procesado = array();
        $bodega = array();
        $fecha_inicio = array();

        $procesado["u1"] = "Procesado";
        $procesado["u2"] = "";
        $procesado["u3"] = $endDate;
        $sheet->mergeCells('c6:e6');

        $bodega["b1"] = "Almacen/Bodega";
        $bodega["b2"] = "";
        $sheet->mergeCells('a7:b7');
        $sheet->getStyle('a7:j7')->applyFromArray($estiloh);
        $bodega["b3"] = $this->authStore->name;
        $sheet->mergeCells('c7:h7');
        $fecha_inicio["f1"] = "Fecha inicio:";
        $fecha_inicio["f2"] = "";
        $sheet->mergeCells('a8:b8');
        $fecha_inicio["f3"] = $cashierBalance->date_open . " " . $cashierBalance->hour_open;
        $fecha_inicio["f4"] = "";
        $sheet->mergeCells('c8:d8');
        $fecha_inicio["f5"] = "";
        $fecha_inicio["f6"] = "Fecha fin:";
        $fecha_inicio["f7"] = "";
        $sheet->mergeCells('f8:g8');
        $fecha_inicio["f8"] = $endDate;
        $sheet->mergeCells('h8:i8');
        $sheet->getStyle('c6:d8')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('a6:b8')->applyFromArray($estiloh);
        $sheet->getStyle('h8')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('f8')->applyFromArray($estiloh);
        array_push($lineaSheet, $procesado);
        array_push($lineaSheet, $bodega);
        array_push($lineaSheet, $fecha_inicio);

        $inicio_id = array();
        $inicio_id["message"] = "ID de origen:";
        $inicio_id["e"] = "";
        $sheet->mergeCells('a9:b9');
        //$inicio_id["e3"] = "";
        $inicio_id["ip"] = $request->ip();
        $inicio_id["e2"] = "";
        $sheet->mergeCells('c9:d9');
        $sheet->getStyle('a9:b9')->applyFromArray($estiloh);
        $sheet->getStyle('c9:e9')->applyFromArray($estiloRight);
        $sheet->getStyle('c9:j9')->getFont()->setBold(true)->setSize(12);

        array_push($lineaSheet, $inicio_id);

        $spacing = array();
        for ($i = 1; $i <= 11; $i++) {
            $spacing['ordenes' . $i] = '----------------';
        }

        array_push($lineaSheet, $spacing);

        ################################# Tercera Parte ############################ row 10
        $movimientos_caja = array();

        $movimientos_caja["a1"] = "Movimientos por Caja";
        $sheet->mergeCells('a11:i11');
        $sheet->getStyle('a11:i11')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('a11:i11')->applyFromArray($estiloMedium);

        $linea_caja = array();
        $linea_caja["0"] = "    ";
        $linea_caja["1"] = "Caja";
        $linea_caja["2"] = "Serie fiscal";
        $linea_caja["3"] = "    ";
        $sheet->mergeCells('C13:D13');
        $linea_caja["4"] = "Mov. Inicial";
        $linea_caja["5"] = "    ";
        $sheet->mergeCells('E13:F13');
        $linea_caja["6"] = "Mov. Final";
        $linea_caja["7"] = "    ";
        $sheet->mergeCells('G13:H13');
        $linea_caja["8"] = "Transacciones";
        $linea_caja["9"] = "    ";
        $sheet->mergeCells('I13:J13');
        $linea_caja["10"] = " Ventas";

        $sheet->getStyle('A13:L13')->getFont()->setBold(true)->setSize(10);

        $movi_inicial_final = array();

        $medios_pago = array();
        $device_transactions = array();
        array_push($lineaSheet, $movimientos_caja);
        array_push($lineaSheet, $spacing);
        array_push($lineaSheet, $linea_caja);
        array_push($lineaSheet, $spacing);

        $category_data = $this->categorySalesData($this->authStore->id, $cashierBalanceId);
        $subtotal = 0;
        $total_count = 0;
        $num_fila = 15;

        foreach ($category_data as $d) {
            $datos = array();
            $datos["0"] = "";
            $datos["device_id"] = $d["device_id"];
            $datos["1"] = "";
            $datos["2"] = "  ";
            $datos["mov_init"] = $d["mov_inicial"];
            $datos["4"] = " ";
            $sheet->mergeCells('E15:F15');
            $datos["mov_fin"] = $d["mov_final"];
            $datos["5"] = "";
            $sheet->mergeCells('G15:H15');
            $datos['Cantidad'] = $d['category_sales'];
            $datos['Precio Producto'] = $d['category_value'];
            $datos["6"] = "";
            $sheet->mergeCells('J' . $num_fila . ':K' . $num_fila);
            $sheet->getStyle('J' . $num_fila . ':K' . $num_fila)->applyFromArray($estiloRight);
            $device_transactions[$d["device_id"]] =
                array_key_exists($d["device_id"], $device_transactions) ?
                $device_transactions[$d["device_id"]] + 1 : 0;
            array_push($lineaSheet, $datos);
            $subtotal = $subtotal + $d['category_value'];
            $total_count = $total_count + $d["category_sales"];
            $sheet->getStyle('I' . $num_fila . ':L' . $num_fila)->getFont()->setSize(10);
            $sheet->getStyle('I' . $num_fila . ':L' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(
                    \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
                );
            $num_fila++; #8
        }

        $total_devices = array();

        $total_devices["to"] = "Totales";
        for ($i = 1; $i <= 7; $i++) {
            $total_devices['ordenes' . $i] = '';
        }
        $total_devices["v"] = $total_count;
        $total_devices["value"] = $subtotal;
        $total_devices["v1"] = "";

        array_push($lineaSheet, $total_devices);

        array_push($lineaSheet, $spacing);

        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );

        $sheet->mergeCells('J' . $num_fila . ':K' . $num_fila);
        $sheet->getStyle('J' . $num_fila . ':K' . $num_fila)->applyFromArray($estiloRight);
        $num_fila = $num_fila + 2;


        ################################# Cuarta Parte #############################
        $movimientos_dpto = array();
        $movimientos_dpto["u1"] = "Movimientos por Departamento";

        $department_data = $this->categoryData($this->authStore->id, $cashierBalanceId);

        $sheet->mergeCells('A' . $num_fila . ':i' . $num_fila);

        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);

        array_push($lineaSheet, $movimientos_dpto);

        $num_fila = $num_fila + 1;
        array_push($lineaSheet, $spacing);
        $totales = 0;
        $descuentos  = 0;
        $vent_dsc = 0;
        $taxes = 0;

        foreach ($department_data as $d) {

            $num_fila++;
            $dep_t = array();
            $dep_t["dep"] = "Departamento:";
            $dep_t["e"] = "";
            $dep_t["e1"] = "";
            $dep_t["e2"] = $d["category_sales"];
            $dep_t["e3"] = $d["category_name"];
            $sheet->mergeCells('E' . $num_fila . ':F' . $num_fila);
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setBold(true)->setSize(10);
            array_push($lineaSheet, $dep_t);

            $dep2 = array();
            $dep2["e1"] = "";
            $dep2["e3"] = "Ventas";
            $dep2["ei0"] = "";
            $dep2["e4"] = "Descuento";
            $dep2["e5"] = "Ventas-Descuento";
            $dep2["ei1"] = "";
            $dep2["e6"] = "Exento";
            $dep2["e7"] = "Excluido";
            $dep2["ei3"] = "";
            $dep2["e8"] = "Gravadas";
            $sheet->mergeCells('B' . $num_fila . ':C' . $num_fila);
            $sheet->mergeCells('E' . $num_fila . ':F' . $num_fila);

            array_push($lineaSheet, $dep2);
            $num_fila++;
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
            $val1 = array();
            $val1["e1"] = "";
            $val1["e3"] = $d["base_value"];
            $val1["ei0"] = "";
            $val1["e4"] = $d["discount_value"];
            $val1["e5"] =  $d["category_value"];
            $val1["ei1"] = "";
            $val1["e6"] = "0";
            $val1["e7"] = "0";
            $val1["ei3"] = "";
            $val1["e8"] = $d["category_value"];

            $totales = $totales + $d["base_value"];
            $descuentos = $descuentos + $d["discount_value"];
            $vent_dsc = $vent_dsc + $d["category_value"];
            $taxes = $taxes + $d["taxes"];

            $sheet->mergeCells('B' . $num_fila . ':C' . $num_fila);
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);

            array_push($lineaSheet, $val1);

            $dep3["c1"] = "";
            $dep3["e0"] = "";
            $dep3["e1"] = "";
            $dep3["c3"] = "% Impto";
            $dep3["c4"] = "Base";
            $dep3["e2"] = "";
            $dep3["c6"] = "Impuesto";
            $dep3["c7"] = "Total";

            $num_fila++;
            $sheet->mergeCells('E' . $num_fila . ':F' . $num_fila);
            array_push($lineaSheet, $dep3);
            $sheet->getStyle('A' . $num_fila . ':E' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(
                    \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
                );
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
            $dep4 = array();
            $dep4["e0"] = "2-IVA 19%";
            $dep4["c1"] = "";
            $dep4["e1"] = "";
            $dep4["c3"] = "19";
            $dep4["c4"] = $d["base_value"];
            $dep4["e2"] = "";
            $dep4["c6"] = $d["taxes"];
            $dep4["c7"] = $d["category_value"];

            $num_fila++;

            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
            array_push($lineaSheet, $dep4);
            $num_fila++;

            $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(
                    \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
                );
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
        }


        $num_fila = $num_fila + 2;

        array_push($lineaSheet, $spacing);
        ################################ Quinta Parte ##############################
        $total_dptos = array();
        $total_dptos["u1"] = "Total Departamentos";

        for ($i = 1; $i <= 10; $i++) {
            $total_dptos['ordenes' . $i] = '';
        }

        $sheet->mergeCells('A' . $num_fila . ':i' . $num_fila);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);

        array_push($lineaSheet, $total_dptos);
        array_push($lineaSheet, $spacing);

        $num_fila = $num_fila + 2;

        $dpts = array();
        $dpts["c1"] = "";
        $dpts["c2"] = "Ventas";
        $dpts["c3"] = "";
        $dpts["c4"] = "Descuento";
        $dpts["c5"] = "Ventas - Descuento";
        $dpts["e0"] = "";
        $dpts["c6"] = "Exento";
        $dpts["e1"] = "";
        $dpts["e1"] = "";
        $dpts["c7"] = "Excluido";
        $dpts["e2"] = "";
        $dpts["c8"] = "Gravadas";

        $sheet->mergeCells('B' . $num_fila . ':C' . $num_fila);
        $sheet->mergeCells('E' . $num_fila . ':F' . $num_fila);
        $sheet->mergeCells('G' . $num_fila . ':H' . $num_fila);


        $sheet->getStyle('A' . $num_fila . ':K' . $num_fila)->getFont()->setBold(true)->setSize(10);

        $num_fila = $num_fila + 2;

        $sec = array();
        $sec["e1"] = "Totales:";
        $sec["e3"] = $totales;
        $sec["e4"] = "";
        $sec["e5"] = $descuentos == 0 ? "0" : $descuentos;
        $sec["e6"] = $vent_dsc == 0 ? "0" : $vent_dsc;
        $sec["e7"] = "";
        $sec["e10"] = "";
        $sec["e8"] = "0";
        $sheet->mergeCells('H' . $num_fila . ':I' . $num_fila);
        $sec["e11"] = "0";
        $sec["e12"] = "";
        $sec["e13"] = $totales;

        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );
        $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setBold(true)->setSize(8);

        $num_fila = $num_fila + 1;

        $sec3 = array();
        $sec3["c1"] = "";
        $sec3["e0"] = "";
        $sec3["c3"] = "% Impto";
        $sec3["c4"] = "Base";
        $sec3["e2"] = "";
        $sec3["c6"] = "Impuesto";
        $sec3["c7"] = "Total";

        $sheet->getStyle('A' . $num_fila . ':E' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );
        $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);

        $num_fila = $num_fila + 1;

        $sec4 = array();
        $sec4["e0"] = "2-IVA 19%";
        $sec4["c1"] = "";
        $sec4["c3"] = "19";
        $sec4["c4"] = $vent_dsc == 0 ? "0" : $vent_dsc;
        $sec4["e2"] = "";
        $sec4["c6"] = $taxes == 0 ? "0" : $taxes;
        $sec4["c7"] = $totales == 0 ? "0" : $totales;

        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );
        $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);


        array_push($lineaSheet, $dpts);
        array_push($lineaSheet, $spacing);
        array_push($lineaSheet, $sec);
        array_push($lineaSheet, $sec3);
        array_push($lineaSheet, $sec4);
        array_push($lineaSheet, $spacing);
        $num_fila = $num_fila + 2;



        ############################### Sexta Parte ################################
        $otros = array();
        $otros["u1"] = "Formas de Pago";

        $sheet->mergeCells('A' . $num_fila . ':i' . $num_fila);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);

        array_push($lineaSheet, $otros);
        array_push($lineaSheet, $spacing);

        $num_fila = $num_fila + 1;

        $pay = array();
        $pay["e4"] = "Nombre";
        $pay["e2"] = "";
        $pay["e1"] = "";
        $pay["e5"] = "Transacciones";
        $pay["e6"] = "";
        $pay["e7"] = "Importe";

        $num_fila = $num_fila + 1;

        $sheet->getStyle('A' . $num_fila . ':J' . $num_fila)->getFont()->setBold(true)->setSize(8);

        array_push($lineaSheet, $pay);

        $medios_counter = array();
        foreach ($data as $d) {
            $datos = array();
            $subtotal = 0;

            $datos['Operacion'] = $this->detectOperationTypeColombia($d['tax'], $d['subtotal']);
            $datos['Total'] = $d['total'];
            $datos['Forma Pago'] = $d["payment_method"];
            $medios_pago[$d["payment_method"]] =
                array_key_exists($d["payment_method"], $medios_pago) ?
                $medios_pago[$d["payment_method"]] + $d['total'] : $d['total'];
            $medios_counter[$d["payment_method"]] =
                array_key_exists($d["payment_method"], $medios_counter) ?
                $medios_counter[$d["payment_method"]] + 1 : 1;
            $device_transactions[$d["device_id"]] =
                array_key_exists($d["device_id"], $device_transactions) ?
                $device_transactions[$d["device_id"]] + 1 : 0;
        }

        $total_each = 0;
        $total_count_each = 0;
        foreach ($medios_pago as $medio => $pago) {
            $valuesPayment = array();

            $valuesPayment[$medio] = $medio;

            for ($i = 1; $i <= 2; $i++) {
                $valuesPayment['ordenes' . $i] = '';
            }
            $valuesPayment[$medio . '1'] = $medios_counter[$medio];
            $valuesPayment[$medio . '2'] = "";
            $valuesPayment["total"] = $pago;

            $total_each = $total_each + $pago;
            $total_count_each = $total_count_each + $medios_counter[$medio];

            array_push($lineaSheet, $valuesPayment);

            $num_fila = $num_fila + 1;

            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
            $sheet->getStyle('A' . $num_fila . ':K' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(
                    \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
                );
        }

        $num_fila = $num_fila + 1;

        array_push($lineaSheet, $spacing);

        $totales_e = array();
        $totales_e["e1"] = "Totales:";
        $totales_e["e2"] = "";
        $totales_e["e3"] = "";
        $totales_e["e5"] = $total_count_each == 0 ? "0" : $total_count_each;
        $totales_e["e6"] = "";
        $totales_e["e4"] = $total_each == 0 ? "0" : $total_each;

        array_push($lineaSheet, $totales_e);

        $num_fila = $num_fila + 1;

        array_push($lineaSheet, $spacing);

        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle('D' . $num_fila . ':i' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);

        $num_fila = $num_fila + 2;


        ############################### Septima Parte ##############################
        $otros_pagos = array();
        $otros_pagos["u1"] = "Otros Cobros/Pagos";

        $sheet->mergeCells('A' . $num_fila . ':i' . $num_fila);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);

        $num_fila = $num_fila + 1;

        array_push($lineaSheet, $otros_pagos);
        array_push($lineaSheet, $spacing);

        $opay = array();
        $opay["e4"] = "Nombre";
        $opay["e2"] = "";
        $opay["e1"] = "";
        $opay["e5"] = "Transacciones";
        $opay["e6"] = "";
        $opay["e7"] = "Importe";

        $num_fila = $num_fila + 1;

        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(8);

        array_push($lineaSheet, $opay);
        array_push($lineaSheet, $spacing);

        $num_fila = $num_fila + 1;


        $exp_pago = array();
        $exp_counter = array();

        $expense_data = $this->otherExpenses($this->authStore->id, $cashierBalanceId);;

        foreach ($expense_data as $d) {
            $datos = array();
            if ($d["name"] != "") {
                $exp_pago[$d["name"]] =
                    array_key_exists($d["name"], $exp_pago) ?
                    $exp_pago[$d["name"]] + $d['value'] : $d['value'];
                $exp_counter[$d["name"]] =
                    array_key_exists($d["name"], $exp_counter) ?
                    $exp_counter[$d["name"]] + 1 : 1;
            }
        }

        $total_count_each = 0;
        $total_each = 0;

        foreach ($exp_pago as $medio => $pago) {
            $valuesPayment = array();

            $valuesPayment[$medio] = $medio;

            for ($i = 1; $i <= 2; $i++) {
                $valuesPayment['ordenes' . $i] = '';
            }

            $valuesPayment[$medio . '1'] = $exp_counter[$medio];
            $valuesPayment[$medio . '2'] = "";
            $valuesPayment["total"] = $pago;

            $total_each = $total_each + $pago;
            $total_count_each = $total_count_each + $exp_counter[$medio];

            array_push($lineaSheet, $valuesPayment);

            $num_fila = $num_fila + 1;

            $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
                ->getNumberFormat()
                ->setFormatCode(
                    \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
                );
            $sheet->getStyle('A' . $num_fila . ':k' . $num_fila)->getFont()->setSize(8);
            $sheet->mergeCells('A' . $num_fila . ':B' . $num_fila);
        }


        $num_fila = $num_fila + 1;
        array_push($lineaSheet, $spacing);

        $totales_f = array();
        $totales_f["e1"] = "Totales:";
        $totales_f["e2"] = "";
        $totales_f["e3"] = "";
        $totales_f["e5"] = $total_count_each == 0 ? "0" : $total_count_each;
        $totales_f["e6"] = "";
        $totales_f["e4"] = $total_each == 0 ? "0" : $total_each;

        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );

        array_push($lineaSheet, $totales_f);

        $num_fila = $num_fila + 1;

        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle('A' . $num_fila . ':i' . $num_fila)->applyFromArray($estiloMedium);
        $sheet->getStyle('A' . $num_fila . ':L' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );

        $excel->setActiveSheetIndex(0);
        $sheet->fromArray($lineaSheet);

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($excel, 'Dompdf');

        $nombreArchivo = 'Reporte de Transacciones ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function downloadZCutReport(Request $request)
    {
        Log::info("Descargando reporte Z para tienda: " . $this->authStore->name);
        $valuesData = $this->getValuesCashierBalance($request->data['cashier_balance_id']);
        $cashierBalance = CashierBalance::where('id', $request->data['cashier_balance_id'])
            ->where('store_id', $this->authStore->id)
            ->first();
        $expenses = ExpensesBalance::where('cashier_balance_id', $cashierBalance->id)->get();

        $employeeOpen = Employee::where('id', $cashierBalance->employee_id_open)->first();
        $employeeClose = Employee::where('id', $cashierBalance->employee_id_close)->first();
        $storeConfig = StoreConfig::where('store_id', $this->authStore->id)->first();


        $totalValueExternal = 0;
        $externalArray = array();
        foreach ($valuesData['external_values'] as $key => $value) {
            $totalValueExternal += $value;
            array_push($externalArray, [$key, $value]);
        }
        $valuesData['external_values'] = $externalArray;

        $totalExpenses = 0;
        foreach ($expenses as $expense) {
            $totalExpenses += $expense->value;
        }

        $valueSales = $valuesData['close'] +
            $valuesData['card'] +
            $totalValueExternal +
            $valuesData['transfer'] +
            $valuesData['others'] +
            $valuesData['rappi_pay'];

        $dataCashier = [
            'value_open' => round($cashierBalance->value_open / 100, 2),
            'value_cash' => round($valuesData['close'] / 100, 2),
            'value_sales' => round($valueSales / 100, 2),
            'value_close' => round($valuesData['close'] / 100, 2),
            'value_card' => round($valuesData['card'] / 100, 2),
            'value_transfer' => round($valuesData['transfer'] / 100, 2),
            'value_rappi_pay' => round($valuesData['rappi_pay'] / 100, 2),
            'value_others' => round($valuesData['others'] / 100, 2),
            'value_card_tips' => round($valuesData['card_tips'] / 100, 2),
            'date_close' => $cashierBalance->date_close,
            'hour_open' => $cashierBalance->hour_open,
            'hour_close' => $cashierBalance->hour_close,
            'expenses' => $expenses,
            'total_expenses' => round($totalExpenses / 100, 2),
            'externalValues' => $valuesData['external_values'],
            'revoked_orders' => $valuesData['revoked_orders'],
            'cashier_number' => $cashierBalance->cashier_number == null ? "" : $cashierBalance->cashier_number,
            'date_open' => $cashierBalance->date_open,
            'revoked_orders' => $valuesData['revoked_orders'],
            'value_revoked_orders' => round($valuesData['value_revoked_orders'] / 100, 2),
            'value_pending_orders' => round($valuesData['value_pending_orders'] / 100, 2),
            'value_change' => round($valuesData['change_value'] / 100, 2),
            'value_tip_cash' => round($valuesData['cash_tip_value'] / 100, 2),
            'value_tip_card' => round($valuesData['card_tip_value'] / 100, 2),
            'value_deliveries' => round($totalValueExternal / 100, 2),
            'count_orders_cash' => $valuesData['count_orders_cash'],
            'count_orders_card' => $valuesData['count_orders_card'],
            'count_orders_transfer' => $valuesData['count_orders_transfer'],
            'count_orders_rappi_pay' => $valuesData['count_orders_rappi_pay'],
            'count_orders_other' => $valuesData['count_orders_other'],
            'count_orders_external' => $valuesData['count_orders_external'],
        ];

        $extraData = $this->extraDataCashierBalance($cashierBalance);

        $hasTaxValues = false;
        if (count($extraData['tax_values_details']) > 0) {
            $hasTaxValues = true;
        }

        $data = [
            'data' => $dataCashier,
            'extra_data' => $extraData,
            'storeName' => $this->authStore->name,
            'employee_name_open' => $employeeOpen->name,
            'employee_name_close' => $employeeClose->name,
            'conversion' => $storeConfig->dollar_conversion,
            'currency_symbol' => $storeConfig->currency_symbol,
            'has_tax_values' => $hasTaxValues
        ];
        // $pdf = \PDF::loadView('reports.html.zCut', $data)->setPaper('a4', 'landscape')->setWarnings(false);

        // return response()->streamDownload(function () use ($pdf) {
        //     echo $pdf->output();
        // }, 'corteZ.pdf');

        // $pdf = \PDF::loadHTML('<h1>Test</h1>');
        // return $pdf->stream();

        $pdf = \PDF::loadView('reports.html.zCut', $data);
        return $pdf->download('invoice.pdf');
    }


    public function inventoryReport(Request $request)
    {
        Log::info("inventoryReport");

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Reporte de Inventario");
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

        $data = $request->data;

        $lineaFecha = "Reporte desde: " . $data['from']
            . ", hasta: " . $data['to'];
        $nombreEmpresa['titulo'] = $lineaFecha;
        $nombreEmpresa['titulo2'] = '';
        $nombreEmpresa['titulo3'] = $titulo_empresa;
        array_push($lineaSheet, $nombreEmpresa);

        array_push($lineaSheet, $ordenes); #push linea 2
        array_push($lineaSheet, $ordenes); #push linea 3
        array_push($lineaSheet, $ordenes); #push linea 4
        ############# FIN TITULO INICIO ################

        ############ FILA DE TITULOS DEL REPORTE #######
        $columnas = array(
            'Item',
            'Cant. Inicial',
            'Ingresado',
            'Reajuste',
            'Pérdidas',
            'Devolución',
            'Enviado a tiendas',
            'Recibido de tiendas',
            'Consumido por órdenes',
            'Final'
        );
        $campos = array();
        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:M5')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('a')->setWidth(30);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(15);
        $sheet->getColumnDimension('d')->setWidth(14);
        $sheet->getColumnDimension('e')->setWidth(15);
        $sheet->getColumnDimension('f')->setWidth(15);
        $sheet->getColumnDimension('g')->setWidth(20);
        $sheet->getColumnDimension('h')->setWidth(24);
        $sheet->getColumnDimension('i')->setWidth(25);
        $sheet->getColumnDimension('j')->setWidth(15);
        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########
        foreach ($data["components"] as $d) {
            $datos = array();
            $datos['Item'] = $d['name'];
            $datos['Cant. Inicial'] = (string) $d['initial'];
            $datos['Ingresado'] = (string) $d['joined'];
            $datos['Reajuste'] = (string) $d['readjusted'];
            $datos['Pérdidas'] = (string) ($d['lost'] + $d['expired']);
            $datos['Devolución'] = (string) $d['returned'];
            $datos['Enviado a tiendas'] = (string) $d['transfer_sent'];
            $datos['Recibido de tiendas'] = (string) $d['transfer_received'];
            $datos['Consumido por órdenes'] = (string) $d['consumed'];
            $datos['Final'] = (string) $d['final'];
            array_push($lineaSheet, $datos);
            $num_fila++; #8
        }
        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:j4');
        $sheet->getStyle('a1:j4')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:j1')->getFont()->setBold(true)->setSize(28);
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:j1')->applyFromArray($st);

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
        $sheet->getStyle('a5:j5')->applyFromArray($estilob);
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

        $nombreArchivo = 'Reporte de Inventario ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function hourlyDetailsReport(Request $request)
    {
        Log::info("hourlyDetailsReport");

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Detalle por Hora");
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
            'Hora',
            'Cantidad',
            'Monto',
            'Porcentaje'
        );
        $campos = array();

        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:D5')->getFont()->setBold(true)->setSize(12);

        $sheet->getColumnDimension('a')->setWidth(15);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(15);
        $sheet->getColumnDimension('d')->setWidth(15);

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########

        $data = $request->data;
        $totalValue = round($request->totalValue / 100, 2);
        foreach ($data as $d) {
            $datos = array();
            $monto = round($d['monto'] / 100, 2);

            $datos['Hora'] = $d['hora'];
            $datos['Cantidad'] = $d['num_fact'];
            $datos['Monto'] = $monto;
            $datos['Porcentaje'] = $totalValue > 0 ? $monto / $totalValue : 0;

            array_push($lineaSheet, $datos);
            $num_fila++; #8
        }

        $datos['Hora'] = "";
        $datos['Cantidad'] = "Subtotal";
        $datos['Monto'] = $totalValue;
        $datos['Porcentaje'] = 1;
        array_push($lineaSheet, $datos);
        $num_fila++; #8
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

        $nombreArchivo = 'Reporte de Transacciones por Hora ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function weekDayDetailsReport(Request $request)
    {
        Log::info("weekDayDetailsReport");

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Detalle por Dia");
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
            'Dia',
            'Cantidad',
            'Monto',
            'Porcentaje'
        );
        $campos = array();

        foreach ($columnas as $col) {
            $campos[$col] = $col;
        }
        array_push($lineaSheet, $campos);

        $sheet->getStyle('A5:D5')->getFont()->setBold(true)->setSize(12);

        $sheet->getColumnDimension('a')->setWidth(15);
        $sheet->getColumnDimension('b')->setWidth(15);
        $sheet->getColumnDimension('c')->setWidth(15);
        $sheet->getColumnDimension('d')->setWidth(15);

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########

        $data = $request->data;
        $totalValue = round($request->totalValue / 100, 2);
        foreach ($data as $d) {
            $datos = array();
            $monto = round($d['monto'] / 100, 2);
            switch ($d['dia']) {
                case 0:
                    $dia = 'Lunes';
                    break;
                case 1:
                    $dia = 'Martes';
                    break;
                case 2:
                    $dia = 'Miércoles';
                    break;
                case 3:
                    $dia = 'Jueves';
                    break;
                case 4:
                    $dia = 'Viernes';
                    break;
                case 5:
                    $dia = 'Sábado';
                    break;
                case 6:
                    $dia = 'Domingo';
                    break;
                default:
                    $dia = 'N/A';
                    break;
            }
            $datos['Dia'] = $dia;
            $datos['Cantidad'] = $d['num_fact'];
            $datos['Monto'] = $monto;
            $datos['Porcentaje'] = $totalValue > 0 ? $monto / $totalValue : 0;

            array_push($lineaSheet, $datos);
            $num_fila++;
        }

        $datos['Dia'] = "";
        $datos['Cantidad'] = "Subtotal";
        $datos['Monto'] = $totalValue;
        $datos['Porcentaje'] = 1;
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

        $nombreArchivo = 'Reporte de Transacciones por Hora ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function categorySalesDetailsReport(Request $request)
    {
        Log::info("categorySalesDetailsReport");
        $store = $this->authStore;

        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $excel->getProperties()->setTitle("myPOS");

        // Primera hoja donde apracerán detalles del objetivo
        $sheet = $excel->getActiveSheet();
        $excel->getActiveSheet()->setTitle("Detalle por Dia");
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
            // 'Mesa', // TODO: Check this
            'Cantidad',
            'Monto',
            'Porcentaje',
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
        // $sheet->getColumnDimension('e')->setWidth(15); // TODO: Check this

        ######### FIN FILA DE TITULOS DEL REPORTE #########

        ######### POBLACIÓN DE DATOS DEL REPORTE ##########

        $data = ReportHelperTrait::categorySalesData($request->date, $store->id, False);

        $totalValue = round($request->totalValue / 100, 2);
        $totalQuantity = 0;
        foreach ($data as $d) {
            $datos = array();
            $monto = round($d->category_value / 100, 2);
            $datos['Categoría'] = $d->category_name;
            // $datos['Mesa'] = $d->spot; // TODO: Check this
            $datos['Cantidad'] = $d->category_sales;
            $datos['Monto'] = $monto;
            $datos['Porcentaje'] = $totalValue > 0 ? $monto / $totalValue : 0;
            $totalQuantity = $d->category_sales + $totalQuantity;
            array_push($lineaSheet, $datos);
            $num_fila++;
        }

        $datos['Categoría'] = "Subtotal";
        // $datos['Mesa'] = "Subtotal"; // TODO: Check this
        $datos['Cantidad'] = $totalQuantity;
        $datos['Monto'] = $totalValue;
        $datos['Porcentaje'] = 1;
        array_push($lineaSheet, $datos);
        $num_fila++;
        ##########  FIN DE POBLACIÓN DE DATOS DEL REPORTE ########

        ############## CONFIGURACIONES DE LA HOJA ################
        $sheet->mergeCells('a1:d4'); // TODO: Check this

        $sheet->getStyle('a1:d4') // TODO: Check this
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

        $sheet->getStyle('b1:d1')->getFont()->setBold(true)->setSize(28); // TODO: Check this
        $st = array('font' => array(
            'color' => array('rgb' => 'ff9900'),
        ));
        $sheet->getStyle('b1:d1')->applyFromArray($st); // TODO: Check this
        $sheet->getStyle('d6:d' . $num_fila)
            ->getNumberFormat()
            ->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE
            );
        $sheet->getStyle('d6:d' . $num_fila) // TODO: Check this
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
        $sheet->getStyle('a5:d5')->applyFromArray($estilob); // TODO: Check this
        $sheet->getStyle('b' . $num_fila . ':' . 'd' . $num_fila)->applyFromArray($border_thin); // TODO: Check this

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

        $nombreArchivo = 'Reporte de Transacciones por Hora ' . Carbon::today();
        $response = response()->streamDownload(function () use ($objWriter) {
            $objWriter->save('php://output');
        });
        $response->setStatusCode(200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$nombreArchivo.'.xls"');
        $response->send();
    }

    public function weekSalesReport(Request $request)
    {
        $store = $this->authStore;
        $startDate = Carbon::now()->subDays(14)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $orders = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 1)
            ->where('preorder', 0)
            ->get()
            ->groupBy(function ($order) {
                return Carbon::parse($order->created_at)->format('Y-m-d');
            })
            ->map(function ($day, $key) {
                return [
                    'day' => $key,
                    'total' => $day->sum('total'),
                    'cantidad' => $day->count('id')
                ];
            })
            ->toArray();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => array_values($orders)
            ],
            200
        );
    }

    public function topProducts(Request $request)
    {
        $store = $this->authStore;

        if (!$request->startDate) {
            $startDate = Carbon::now()->startOfDay();
        } else {
            $startOfDay = $request->startDate . '00:00:00';
            $startDate = TimezoneHelper::convertToServerDateTime($startOfDay, $store);
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->endOfDay();
        } else {
            $endOfDay = $request->endDate . '23:59:59';
            $endDate = TimezoneHelper::convertToServerDateTime($endOfDay, $store);
        }
        // Esta mal obtenido los valores de los productos. 
        $details = OrderDetail::whereHas(
            'order',
            function ($order) use ($startDate, $endDate, $store) {
                $order
                    ->where('store_id', $store->id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', 1);
            }
        )
            ->get()
            ->groupBy(function ($orderDetail) {
                return $orderDetail->product_detail_id;
            })
            ->map(function ($group, $key) {
                return [
                    'id' => $key,
                    'name' => $group[0]->name_product,
                    'quantity' => sizeof($group),
                    'total' => $group->sum('total')
                ];
            })->toArray();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => array_values($details)
            ],
            200
        );
    }

    public function topProductsPaginate(Request $request)
    {
        $store = $this->authStore;
        $rowsPerPage = 12;
        if (!$request->startDate) {
            $startDate = Carbon::now()->startOfDay();
        } else {
            $startOfDay = $request->startDate . '00:00:00';
            $startDate = TimezoneHelper::convertToServerDateTime($startOfDay, $store);
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->endOfDay();
        } else {
            $endOfDay = $request->endDate . '23:59:59';
            $endDate = TimezoneHelper::convertToServerDateTime($endOfDay, $store);
        }

        $details = DB::select(
            "select count(order_details.quantity) as quantity, 
                order_details.name_product as name, sum(order_details.total) as total from 
                `order_details` left join `orders` on `order_details`.`order_id` = `orders`.`id` where 
                `orders`.`store_id` = ? and `orders`.`created_at` between 
                ? and ? and `orders`.`status` = 1 
                 group by UPPER(`order_details`.`name_product`), order_details.name_product
                 ORDER BY quantity limit ? offset ?",
            array($store->id, $startDate, $endDate, $rowsPerPage, ($request->page * $rowsPerPage) - $rowsPerPage)
        );

        $waiting = ($request->page - 1) * $rowsPerPage;

        $details_quantity = DB::select(
            "select order_details.name_product as name from 
            `order_details` left join `orders` on `order_details`.`order_id` = `orders`.`id` where 
            `orders`.`store_id` = ? and `orders`.`created_at` between 
            ? and ? and `orders`.`status` = 1 
             group by UPPER(`order_details`.`name_product`), order_details.name_product;",
            array($store->id, $startDate, $endDate)
        );

        $values = array_values($details);

        Log::info(sizeof($details_quantity));

        return response()->json(
            [
                'status' => 'Exito',
                'results' => array_values($details),
                'count' => sizeof($details_quantity)
            ],
            200
        );
    }

    public function reportOrdersByEmployee(Request $request)
    {
        return (new ExcelOrdersByEmployee($request))->download('reporte_ventas_por_empleado' . date('Y-m-d_H:i:s') . '.xls');
    }

    public function reportCashierExpenses(Request $request)
    {
        return (new ExcelCashierExpenses($request))->download('reporte_detalles_gastos' . date('Y-m-d_H:i:s') . '.xls');
    }

    /**
     * Metodo actual creado para detectar el tipo de transaccion que se tuvo
     * si fue tipo sujetas a pago del IVA; las operaciones sujetas al pago del
     * impuesto al consumo; exentas del IVA o excluidas del IVA. 
     * PARA COMPLIANCE CON COLOMBIA
     * Esto se puede reemplazar almacenando el tipo de transaccion como un campo
     * en la base
     */
    private function detectOperationTypeColombia($tax, $value)
    {
        $iva = $value * 0.16; //actual iva en colombia
        $consumo = $value * 0.08; //actual impuesto al consumo
        if ($tax > $consumo) {
            return "SUJETA A IVA";
        } else if ($tax == 0) {
            return "EXCLUIDA";
        } else {
            return "IMPUESTO AL CONSUMO";
        }
    }

    public static function categorySalesData($store_id, $balance_id)
    {
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = date('m-d-Y', time());
        $finalDate = date('m-d-Y', time());

        $transactions = DB::select(DB::raw("SELECT pc.id AS id, 
        pc.name AS category_name, 
        COUNT(pc.id) AS category_sales, 
        SUM(od.total) AS category_value ,
        SUM(od.base_value) AS base_value,
        SUM(o.discount_value) AS discount_value,
        MAX(o.id) AS mov_inicial, MIN(o.id) AS mov_final,
        o.*, od.*
        FROM invoices AS iv 
        LEFT JOIN orders AS o ON o.id = iv.order_id 
        LEFT JOIN order_details AS od ON od.order_id = o.id 
        LEFT JOIN product_details AS pd ON pd.id = od.product_detail_id 
        LEFT JOIN products AS pr ON pr.id = pd.product_id 
        LEFT JOIN product_categories AS pc ON pc.id = pr.product_category_id 
        WHERE o.store_id = '$store_id' AND o.cashier_balance_id = '$balance_id'
        AND pc.name IS NOT NULL
        GROUP BY o.device_id ;"));
        $data = [];
        foreach ($transactions as $key => $transaction) {
            $data[] = [
                'id' => $transaction->id,
                'date' => Carbon::parse($transaction->created_at),
                'category_name' => $transaction->category_name,
                'device_id' => $transaction->device_id,
                'category_sales' => $transaction->category_sales,
                'base_value' => $transaction->base_value,
                'discounted_value' => $transaction->discount_value,
                'category_value' => $transaction->category_value,
                'device_id' => $transaction->device_id,
                'mov_inicial' => $transaction->mov_inicial,
                'mov_final' => $transaction->mov_final
            ];
        }

        return $data;
    }

    public static function categoryData($store_id, $balance_id)
    {
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = date('m-d-Y', time());
        $finalDate = date('m-d-Y', time());;

        $transactions = DB::select(DB::raw("SELECT pc.id AS id, 
        pc.name AS category_name, 
        COUNT(pc.id) AS category_sales, 
        SUM(od.total) AS category_value ,
        SUM(od.base_value) AS base_value,
        SUM(o.discount_value) AS discount_value,
        SUM(ivd.has_iva>0) AS exento,
        SUM(ivd.has_iva=0) AS excluido,
        SUM(iv.tax) AS taxes,
        o.*, od.*
        FROM invoices AS iv 
        LEFT JOIN orders AS o ON o.id = iv.order_id 
        LEFT JOIN order_details AS od ON od.order_id = o.id 
        LEFT JOIN product_details AS pd ON pd.id = od.product_detail_id 
        LEFT JOIN products AS pr ON pr.id = pd.product_id
        LEFT JOIN invoice_items AS ivd ON ivd.order_detail_id = od.id
        LEFT JOIN product_categories AS pc ON pc.id = pr.product_category_id 
        WHERE o.store_id = '$store_id' AND o.cashier_balance_id = '$balance_id'
        AND pc.name IS NOT NULL
        GROUP BY pc.id, pc.name;"));
        $data = [];
        foreach ($transactions as $key => $transaction) {
            $data[] = [
                'id' => $transaction->id,
                'date' => Carbon::parse($transaction->created_at),
                'category_name' => $transaction->category_name,
                'device_id' => $transaction->device_id,
                'category_sales' => $transaction->category_sales,
                'base_value' => $transaction->base_value,
                'discount_value' => $transaction->discount_value,
                'category_value' => $transaction->category_value,
                'device_id' => $transaction->device_id,
                'taxes' => $transaction->taxes,
                'exentos' => $transaction->exento,
                'excluido' => $transaction->excluido
            ];
        }

        return $data;
    }

    public static function otherExpenses($store_id, $balance_id)
    {

        $transactions = DB::select(DB::raw("
        select id, name, value, SUM(value) as value_total from expenses_balances where 
        cashier_balance_id = '$balance_id'
        GROUP BY name"));
        $data = [];
        foreach ($transactions as $key => $transaction) {
            $data[] = [
                'id' => $transaction->id,
                'name' => $transaction->name,
                'value' => $transaction->value
            ];
        }

        return $data;
    }
    public function downloadPendingStoreTransfers(Request $request)
    {
        Log::info("Descargando reporte Pending Store Transfers para: " . $this->authStore->name);

        $store = $this->authStore;
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();

        $storeMoneyFormat = new \stdClass();
        $storeMoneyFormat->store_money_format = json_decode($storeConfig->store_money_format);
        if (!isset($request->data)) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Data is no defined"
                ],
                400
            );
        }
        if (!isset($request->data['store_destination_id'])) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Id destination not exist"
                ],
                400
            );
        }
        $store_destination_id = $request->data['store_destination_id'];
        $store_destination =  Store::where('id', $store_destination_id)->first();
        $storeMoneyFormat->country = $store->country_code;

        $transferencias = StockTransfer::where('destination_store_id', $store_destination_id)
            ->where('origin_store_id', $store->id)
            ->where('status', 0)
            ->with('originStock.component.unit')
            ->get()->toArray();
        //Se procede a preparar el objeto a enviar al blade para generar el reporte.
        $dataFinal = array();
        $cantidadTotal = 0;
        $totalImporte = 0;
        foreach ($transferencias as $key => $transferencia) {
            $transferencia = (object) $transferencia;
            $originStock = $transferencia->origin_stock;
            $component = $transferencia->origin_stock['component'];
            $unit = $transferencia->origin_stock['component']['unit'];
            //data a mostrar por cada fila en el reporte
            $codigoTransferencia = $transferencia->id;
            $sku = $component['SKU'] != null ? ' - ' . $component['SKU'] : '';
            $codigo = $codigoTransferencia . $sku;
            $cantidadTotal = floatval($cantidadTotal) + floatval($transferencia->quantity);
            $totalImporte = floatval($totalImporte) + floatval($transferencia->quantity) * (floatval($originStock['cost']) / 100);
            $data = (object) array(
                'codigo' => $codigo,
                'descripcion' => $component['name'],
                'unidad' => $unit != null ? $unit['name'] : '',
                'cantidad' => $transferencia->quantity,
                'costo' =>  PrintJobHelper::formatNumberToMoney(floatval($originStock['cost']) / 100, $storeMoneyFormat),
                'importe' => PrintJobHelper::formatNumberToMoney(floatval($transferencia->quantity) * (floatval($originStock['cost']) / 100), $storeMoneyFormat),
            );
            array_push($dataFinal, $data);
        }
        $dateTime = new DateTime();
        $fecha = $dateTime->format('Y-m-d');
        $time = $dateTime->format('H:i:s');
        $pdf = \PDF::loadView('reports.html.pendingTransfer', [
            'dataFinal' => $dataFinal,
            'store_city_origin' => $store->city->name != null && $store->city->name != '' ? $store->city->name : 'Sin ciudad',
            'store_telf_origin' => $store->phone != null && $store->phone != '' ? $store->phone : 'Sin telefono',
            'store_address_origin' => $store->address != null && $store->address != '' ? $store->address : 'Sin dirección',
            'store_city_destination' => $store_destination->city->name != null && $store_destination->city->name != '' ? $store_destination->city->name : 'Sin ciudad',
            'store_telf_destination' => $store_destination->phone != null && $store_destination->phone != '' ? $store_destination->phone : 'Sin telefono',
            'store_address_destination' => $store_destination->address != null && $store_destination->address != '' ? $store_destination->address : 'Sin dirección',
            'cantidad_total' =>  PrintJobHelper::formatNumberToMoney(floatval($cantidadTotal), $storeMoneyFormat),
            'total_importe' => PrintJobHelper::formatNumberToMoney($totalImporte, $storeMoneyFormat),
            'fecha_hora' => $fecha . ' ' . $time,
            'store_origin' => $store,
            'store_destination' => $store_destination
        ]);
        return $pdf->download('pendingTransfer.pdf');
    }
}
