<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Order;
use App\Helper;
use App\OrderDetail;
use App\Traits\AuthTrait;
use App\Traits\TimezoneHelper;
use App\InventoryAction;
use App\StockMovement;
use App\StockTransfer;
use Log;

class ReporteMovimientosDeInventario extends Controller
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
     *  Actualmente solo se crean los movimientos de Send/Receive en StockMovements
     *  cuando se acepta la transferencia en la tienda destino (se crean ambos en ese momento)
     *  Entonces, solo en el estado de TRANSFERENCIA ACEPTADA se puede obtener
     *  el usuario que hizo el movimiento (El usuario que envió o el que recibió el stock)
     *  Actualizacion:
     *  Se ha agregado referencia a los demas movimientos de inventario
     */
    public function getReportData($options, $shouldPaginate = true)
    {
        $store = $this->authStore;
        // Params from request
        $storeIds = $options->ids ?: [$store->id];
        $startDate = TimezoneHelper::convertToServerDateTime($options->start_date, $store);
        $endDate = TimezoneHelper::convertToServerDateTime($options->end_date, $store);
        $currentPage = $options->current_page;
        $pageSize = $options->page_size;
        $sortBy = $options->sort_by ?: 'date';
        $sortOrder = $options->sort_order ?: 'ascend';
        $strLike = $options->searchStr ?: '';
        $offset = ($currentPage * $pageSize) - $pageSize; // Pagination
        // Allowed actions
        $allowedInventoryActionCodes = [
            'receive', 'count', 'damaged', 'stolen', 'lost', 'return',
            'update_cost', 'create_order_consumption', 'revert_stock_revoked_order'
        ];
        $allowedActionIds = InventoryAction::whereIn('code', $allowedInventoryActionCodes)->get()->pluck('id');
        /** ----------------------------------------------
         *  Get data from StockMovements (Allowed actions)
         */
        $idx = 1;
        $data = collect([]);
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
                'componentStock.store',
                'user'
            ])
            ->get();
        foreach ($Movements as $mov) {
            $date = Carbon::parse($mov->created_at)->toString();
            $invoice = '-';
            $invoice_details = collect([]);
            $idx2 = 1;
            $invoice_details->push([
                'id2' => $idx.$idx2,
                'quantity' => $mov->value,
                'name' => $mov->componentStock->component->name,
                'unit_price' => $mov->cost,
                'total' => $mov->value * $mov->cost
            ]);
            $idx2++;
            $movement = $mov->action->alias ?: $mov->action->name;
            $user = $mov->user ? $mov->user->email : '-';
            $made_by = '-';
            $received_by = '-';
            $data->push([
                'id' => $idx,
                'date' => $date,
                'invoice' => $invoice,
                'invoice_details' => $invoice_details,
                'movement' => $movement,
                'user' => $user,
                'made_by' => $made_by,
                'received_by' => $received_by
            ]);
            $idx++;
        }
        /** ---------------------------------------------------------
         *  Get data from StockMovements (Only Invoice Provider movs)
         */
        $invoiceProviderAction = InventoryAction::where('code', 'invoice_provider')->first();
        $providerMovements = StockMovement::where('inventory_action_id', $invoiceProviderAction->id)
            ->whereHas(
                'componentStock',
                function ($query) use ($storeIds) {
                    $query->whereIn('store_id', $storeIds);
                }
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'action',
                'componentStock.store',
                'invoiceProvider.details.variation',
                'user'
            ])
            ->get()
            ->groupBy(function ($m) {
                return $m->invoice_provider_id; // ***
            })
            ->map(function ($group, $invoice_provider_id) use ($idx) {
                if (!$group[0]->invoiceProvider) { // Prevent fail on null values
                    $group[0]->invoiceProvider = (object)['created_at' => "", 'invoice_number' => "", 'details' => []];
                }
                $invoice_date = $group[0]->invoiceProvider->created_at; // May change to [invoice_date | reception_date]
                $date = Carbon::parse($invoice_date)->toString();
                $invoice = $group[0]->invoiceProvider->invoice_number;
                $idx2 = 1;
                $invoice_details = collect([]);
                foreach ($group[0]->invoiceProvider->details as $inv_detail) {
                    $var = $inv_detail->variation ? $inv_detail->variation->name : '';
                    $comp = $inv_detail->variation
                        ? $inv_detail->variation
                        ? $inv_detail->variation->name : '' : '';
                    $invoice_details->push([
                        'id2' => $idx.$idx2,
                        'quantity' => $inv_detail->quantity,
                        'name' => $comp,
                        'unit_price' => $inv_detail->unit_price,
                        'total' => $inv_detail->unit_price * $inv_detail->quantity
                    ]);
                    $idx2++;
                }
                $invoice_details = $invoice_details->toArray();
                $movement = $group[0]->action->alias;
                $user = $group[0]->user ? $group[0]->user->email : '-';
                $made_by = '-';
                $received_by = '-';
                return [
                    'date' => $date,
                    'invoice' => $invoice,
                    'invoice_details' => $invoice_details,
                    'movement' => $movement,
                    'user' => $user,
                    'made_by' => $made_by,
                    'received_by' => $received_by
                ];
            });
        foreach ($providerMovements as $mov) {
            $mov['id'] = $idx;
            $data->push($mov);
            $idx++;
        }
        /** ------------------------------------------------------------
         *  Get data from StockTransfers (Only Send & Receive transfers)
         */
        $sendTransferAction = InventoryAction::where('code', 'send_transfer')->first();
        $receiveTransferAction = InventoryAction::where('code', 'receive_transfer')->first();
        $Transfers = StockTransfer::whereBetween('created_at', [$startDate, $endDate])
            ->with(['originStore', 'destinationStore', 'originStock', 'originStock.component'])
            ->get()
            ->filter(function ($value) use ($storeIds) {
                $transferIsSend = in_array($value->origin_store_id, $storeIds);
                // Only accepted transfers affect stocks
                $transferIsReceived = in_array($value->destination_store_id, $storeIds) && $value->processed_at;
                return $transferIsSend || $transferIsReceived;
            })
            ->map(function ($item) use ($sendTransferAction) {
                $lastSendTransfer = StockMovement::where('created_by_id', $item->origin_store_id)
                        ->where('inventory_action_id', $sendTransferAction->id)
                        ->where('component_stock_id', $item->origin_stock_id)
                        ->with(['user'])
                        ->first();
                $item['user'] = $lastSendTransfer
                    ? $lastSendTransfer->user
                    ? $lastSendTransfer->user->email
                    : '-' : '-';
                return $item;
            });

        $idx2 = 0;
        foreach ($Transfers as $rowResult) {
            $date = Carbon::parse($rowResult->created_at)->toString();
            $invoice = '-';
            $invoice_details = collect([]);
            $movement = 'Transferencia';
            $user = $rowResult->user;
            $made_by = $rowResult->originStore->name;
            $received_by = $rowResult->status === StockTransfer::PENDING ? "-"
                : $rowResult->destinationStore->name;

            $detail_iv = $rowResult->quantity ? $rowResult->quantity: 0;
            $unit_price = $rowResult->originStock->cost? $rowResult->originStock->cost : 0; 
            $invoice_details->push([
                'id2' => $idx.$idx2,
                'quantity' =>  $detail_iv,
                'name' => $rowResult->originStock->component->name,
                'unit_price' => $unit_price,
                'total' => $detail_iv * $unit_price
            ]);
            $idx2++;

            $data->push([
                'id' => $idx,
                'date' => $date,
                'invoice' => $invoice,
                'invoice_details' => $invoice_details,
                'movement' => $movement,
                'user' => $user,
                'made_by' => $made_by,
                'received_by' => $received_by
            ]);
            $idx++;
        }

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
                // Searchable columns
                $b = false !== stristr($item['invoice'], $strLike);
                $m = false !== stristr($item['movement'], $strLike);
                $u = false !== stristr($item['user'], $strLike);
                $mb = false !== stristr($item['made_by'], $strLike);
                $rb = false !== stristr($item['received_by'], $strLike);
                return $b || $m || $u || $mb || $rb;
            });
        }
        $data = $data->toArray();
        // Pagination
        $sliced = $shouldPaginate ? array_slice((array) $data, $offset, $pageSize) : $data;
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
            Log::info("NO SE PUDO OBTENER EL REPORTE DE MOVIMIENTOS DE INVENTARIO (TABLA)");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo generar el reporte',
                'results' => []
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
            $excel->getActiveSheet()->setTitle("Reporte de Movimientos de Inv."); // Max 31 chars
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
                'Fecha', // A5
                'Factura', // B5
                'Movimiento', // C5
                'Usuario', // D5
                'Realizó', // E5
                'Recibió', // F5
                'Producto',
                'Cantidad',
                'Costo',
                'Total'
            );
            $campos = array();
            foreach ($columnas as $col) {
                $campos[$col] = $col;
            }
            array_push($lineaSheet, $campos);
            // Format column headers
            $sheet->getStyle('A5:J5')->getFont()->setBold(true)->setSize(12);
            $sheet->getColumnDimension('a')->setWidth(25);
            $sheet->getColumnDimension('b')->setWidth(15);
            $sheet->getColumnDimension('c')->setWidth(25);
            $sheet->getColumnDimension('d')->setWidth(30);
            $sheet->getColumnDimension('e')->setWidth(20);
            $sheet->getColumnDimension('f')->setWidth(20);
            $sheet->getColumnDimension('g')->setWidth(25);
            $sheet->getColumnDimension('h')->setWidth(30);
            $sheet->getColumnDimension('i')->setWidth(20);
            $sheet->getColumnDimension('j')->setWidth(20);

            $reportResults = $this->getReportData($request, false);
            $data = $reportResults['data'];
            foreach ($data as $d) {
                foreach ($d['invoice_details'] as $product) {
                    array_push($lineaSheet, [
                        'Fecha' => Carbon::parse($d['date'])->format("d-m-Y g:i A"),
                        'Factura' => $d['invoice'] === "-" ? "(Sin factura)" : $d['invoice'],
                        'Movimiento' => $d['movement'],
                        'Usuario' => $d['user'] === "-" ? "(No definido)" : $d['user'],
                        'Realizó' => $d['made_by'] === "-" ? "(No definido)" : $d['made_by'],
                        'Recibió' => $d['received_by'] === "-" ? "(No definido)" : $d['received_by'],
                        'Producto' => $product['name'],
                        'Cantidad' => $product['quantity'] == 0 ? "0" : $product['quantity'],
                        'Costo' => $product['unit_price'] == 0 ? "0" : $product['unit_price'] / 100,
                        'Total' => $product['total'] == 0 ? "0" : $product['total'] / 100
                    ]);
                    $num_fila++;
                    $sheet->getStyle('I' . $num_fila .':J' . $num_fila)
                        ->getNumberFormat()
                        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
                }
            }

            $sheet->mergeCells('a1:J4');
            $sheet->getStyle('a1:J4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);

            $sheet->getStyle('b1:f1')->getFont()->setBold(true)->setSize(28);
            $st = ['font' => ['color' => ['rgb' => 'ff9900']]];
            $sheet->getStyle('b1:f1')->applyFromArray($st);
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
            $nombreArchivo = 'Reporte de Movimientos de Inventario ' . Carbon::today()->format("d-m-Y");
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
