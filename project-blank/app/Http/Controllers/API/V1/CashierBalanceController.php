<?php

namespace App\Http\Controllers\API\V1;

use Log;
use App\Spot;
use App\Order;
use App\Helper;
use App\Employee;
use Carbon\Carbon;
use App\AdminStore;
use App\PendingSync;
use App\CashierBalance;
use App\ExpensesBalance;
use App\StoreConfigurations;
use App\StoreIntegrationToken;
use App\Traits\AuthTrait;
use App\Events\SpotDeleted;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\Traits\TimezoneHelper;
use App\Mail\CloseDayHIPOSummary;
use App\AvailableMyposIntegration;
use App\Helpers\PrintService\PrintServiceHelper;
use Illuminate\Support\Facades\DB;
use App\Events\BalanceStatusUpdate;
use App\Http\Controllers\Controller;
use App\Traits\CashierBalanceHelper;
use App\Traits\Mely\MelyRequest;
use Illuminate\Support\Facades\Mail;
use App\Mail\CutXZEmail;
use App\CashierBalanceXReport;
use App\StoreConfig;

class CashierBalanceController extends Controller
{
    use AuthTrait, CashierBalanceHelper, LoggingHelper,TimezoneHelper;

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

    public function getLastOpenCashierBalance(Request $request)
    {
        $store = $this->authStore;

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);
            
            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }

            $store = $employee->store;
        }
        $store->load('currentCashierBalance');
        $cashierBalance = $store->currentCashierBalance;
        $lastCashierBalance = $cashierBalance;
        if (!$cashierBalance) {
            $dt = TimezoneHelper::localizedNowDateForStore($store);
            $today = $dt->toDateString();
            $lastCashierBalance = [
                "date_open" => str_replace('-', '/', $today),
                "hour_open" => Carbon::createFromFormat('Y-m-d H:i:s', $dt)->format('H:i'),
                "value_previous_close" => $this->getPreviousValueClosed($store),
                "value_open" => null,
                "observation" => "",
            ];
        }
        return response()->json(
            [
                'status' => 'Success',
                'results' => $lastCashierBalance
            ],
            200
        );
    }

    public function hasOpenCashierBalance(Request $request)
    {
        $store = $this->authStore;
        $store->load('currentCashierBalance');
        $cashierBalance = $store->currentCashierBalance;
        $hasOpenCashierBalance = true;
        $valueOpen = "0";
        $valuesData = [
            'close' => "0",
            'card' => "0",
            'card_tips' => "0",
            'cash_tips' => "0",
            'transfer' => "0",
            'rappi_pay' => "0",
            'others' => "0",
            'external_values' => [],
            'revoked_orders' => 0
        ];
        if (!$cashierBalance) {
            $hasOpenCashierBalance = false;
        } else {
            $valueOpen = (string) $cashierBalance->value_open;
            $valuesData = $this->getValuesCashierBalance($cashierBalance->id);
        }
        return response()->json(
            [
                'msg' => 'Success',
                'results' => $hasOpenCashierBalance,
                'cashier_balance_id' => $cashierBalance ? $cashierBalance->id : null,
                'value' => $valueOpen,
                'close' => $valuesData['close'],
                'card' => $valuesData['card'],
                'transfer' => $valuesData['transfer'],
                'rappi_pay' => $valuesData['rappi_pay'],
                'others' => $valuesData['others'],
                'card_tips' => $valuesData['card_tips'],
                'cash_tips' => $valuesData['cash_tips'],
                'external_values' => $valuesData['external_values'],
                'revoked_orders' => $valuesData['revoked_orders']
            ],
            200
        );
    }

    public function openDay(Request $request)
    {
        $employee = $this->authEmployee;
        $store    = $employee->store;
        $now      = Carbon::now();
        // Volver a obtener estos valores, ya que es posible que los datos del request esten desactualizados.

        if(isset($request->date_open) && isset($request->hour_open)){
            try {
                $now = TimezoneHelper::convertToServerDateTime($request->date_open." ".$request->hour_open, $this->authStore);
            }catch(\Exception $e){
                $now      = Carbon::now();
            }
        }

        $request->merge([
            'value_previous_close' => $this->getPreviousValueClosed($store),
            'date_open' => $now->toDateString(),
            'hour_open' => $now->toTimeString()
        ]);
        $requestData = $request->all();
        if ($request->observation == null) {
            $requestData['observation'] = "";
        }
        $store->load('currentCashierBalance');
        $openCashierBalance = $store->currentCashierBalance;
        if ($openCashierBalance) {
            return response()->json(
                [
                    'msg' => '¡La caja ya está abierta!',
                    'results' => null
                ],
                400
            );
        }
        $store->load('previousCashierBalance');
        $previousCashierBalance = $store->previousCashierBalance;
        // Colocando número de caja
        $cashierNumber = 1;
        if (!is_null($previousCashierBalance)) {
            $cashierNumber = $previousCashierBalance->cashier_number + 1;
        }

        $cashierBalance = CashierBalance::create(
            array_merge(
                $requestData,
                [
                    'employee_id_open' => $employee->id,
                    'store_id' => $store->id,
                    'cashier_number' => $cashierNumber
                ]
            )
        );
        if ($cashierBalance) {
            if (config('app.slave')) {
                $pendingSyncing = new PendingSync();
                $pendingSyncing->store_id = $store->id;
                $pendingSyncing->syncing_id = $cashierBalance->id;
                $pendingSyncing->type = "cashier_balance";
                $pendingSyncing->action = "open";
                $pendingSyncing->save();
            }

            event(new BalanceStatusUpdate(['store' => $store->toArray(), 'status' => 'opened', 'balance'=> $cashierBalance]));

            $storeIntegrationsTokens = StoreIntegrationToken::where('store_id', $store->id)
            ->where('is_anton', true)
            ->whereNotNull('external_store_id')
            ->get();
            if($storeIntegrationsTokens->count()>0){
                MelyRequest::sendStatusIntegration($storeIntegrationsTokens, CashierBalance::ANTON_OPEN);
            }

            return response()->json(
                [
                    'msg' => 'Información guardada con éxito',
                    'results' => $cashierBalance
                ],
                200
            );
        }
        return response()->json(
            [
                'msg' => 'No se pudo guarda la información de apertura de caja',
                'results' => null
            ],
            400
        );
    }

    public function closeDay(Request $request)
    {
        $employee = $this->authEmployee;
        $store    = $employee->store;

        /*
        * Cerrar caja con ID enviado desde front. Lo ideal sería siempre obtener este ID, ya que,
        * si la pantalla de cierre se mantiene abierta por mucho tiempo, es posible que se esté intentando
        * cerrar una caja más reciente con los datos de una caja antigua (cerrada).
        */
        $store->load('currentCashierBalance');
        $cashierBalanceId = $request->input('cashier_balance_id', null);
        $cashierBalance = $cashierBalanceId === null ?
        $store->currentCashierBalance :
        CashierBalance::where('id', $cashierBalanceId)
        ->where('store_id', $store->id)
        ->whereNull('date_close')
        ->first();

        foreach ($request->expenses as $expense) {
            if ($expense['name'] == null || $expense['name'] == "") {
                return response()->json(
                    [
                        'msg' => 'Los gastos deben tener un motivo',
                        'results' => null
                    ],
                    409
                );
            }
        }
        if (!$cashierBalance) {
            return response()->json(
                [
                    'msg' => 'No existe apertura de caja',
                    'results' => null
                ],
                400
            );
        }
        
        //Validación para que el total de gastos no supere el valor de cierre
        $cashierBalanceValues= $this->getValuesCashierBalance($cashierBalance->id);
        $valor_cierre = $cashierBalance->value_open + $cashierBalanceValues['close'];
        $total_gastos=0;
        foreach ($request->expenses as $expense) {
            if ($expense['value'] != 0) {
                $total_gastos = $expense['value'];
            }
        }
        if($total_gastos>$valor_cierre){
            return response()->json(
                [
                    'msg' => 'El total de gastos es mayor que el valor de cierre de caja',
                    'results' => null
                ],
                405
            );
        }
    
        // if ($cashierBalance->hasActiveOrders()) {
        //     return response()->json(
        //         [
        //             'msg' => 'Existen mesas con órdenes no finalizadas',
        //             'results' => null
        //         ],
        //         409
        //     );
        // }
        
        try {
            $dataJSON = DB::transaction(
                function () use ($request, $employee, $cashierBalance, $store,$cashierBalanceValues) {
                    // Los datos del request pueden estar desactualizados. Volver a obtenerlos.
                    $now = Carbon::now();
                    if(isset($request->date_close) && isset($request->hour_close) && false){
                        try {
                            $createDate = TimezoneHelper::convertToServerDateTime($request->date_close." ".$request->hour_close, $this->authStore);
                            $cashierBalance->date_close = $createDate->toDateString();
                            $cashierBalance->hour_close = $createDate->toTimeString();
                        }catch(\Exception $e){
                            $cashierBalance->date_close = $now->toDateString();
                            $cashierBalance->hour_close = $now->toTimeString();
                        }
                    }else{
                        $cashierBalance->date_close = $now->toDateString();
                        $cashierBalance->hour_close = $now->toTimeString();
                    }
                    
                    $cashierBalance->value_close = $cashierBalance->value_open + $cashierBalanceValues['close'];
                    if ($request->reported_value_close !== null) {
                        $cashierBalance->reported_value_close = $request->reported_value_close;
                    }
                    $cashierBalance->employee_id_close = $employee->id;
                    if (isset($request->totalUberDiscount)) {
                        $cashierBalance->uber_discount = $request->totalUberDiscount * 100;
                    } else {
                        $cashierBalance->uber_discount = 0;
                    }
                    $cashierBalance->save();

                    if (config('app.slave')) {
                        $pendingSyncing = new PendingSync();
                        $pendingSyncing->store_id = $employee->store->id;
                        $pendingSyncing->syncing_id = $cashierBalance->id;
                        $pendingSyncing->type = "cashier_balance";
                        $pendingSyncing->action = "close";
                        $pendingSyncing->save();
                    }

                    foreach ($request->expenses as $expense) {
                        if ($expense['value'] != 0) {
                            $newExpense = new ExpensesBalance();
                            $newExpense->cashier_balance_id = $cashierBalance->id;
                            $newExpense->name = $expense['name'];
                            $newExpense->value = $expense['value'];
                            $newExpense->save();
                        }
                    }

                    $resultDiscount = DB::select("select ifnull(sum(discount_value), 0) as value_discount
                    from orders
                    where cashier_balance_id=? and status = 1 and preorder = 0", [$cashierBalance->id]);

                    $request->value_discount = $resultDiscount[0]->value_discount;

                    $dateClose = TimezoneHelper::localizedNowDateForStore($store);
                    $data = $this->formatCashierValuesForMail($request, $cashierBalance, $cashierBalanceValues, $dateClose->format('Y-m-d'), $dateClose->format('H:i:s'));

                    $printJob = [];
                    $request->print_browser = isset($request->print_browser)?$request->print_browser:false;
                    if (isset($request->print_balance) && $request->print_balance) {
                        if($request->print_browser){
                            $printJob1 = PrintServiceHelper::getCashierReportJobs($data, $employee);
                            array_push($printJob,$printJob1);
                        }else{
                            PrintServiceHelper::printCashierReport($data, $employee);
                        }
                    }

                    $config = StoreConfig::where('store_id', $store->id)->first();

                    // Imprimir reporte X y Z de la tienda/caja
                    if (!is_null($config) && $config->enable_xz) {
                        $extraData = $this->extraDataCashierBalance($cashierBalance);
                        if($request->print_browser){
                            $printJob2 = PrintServiceHelper::getXZJobs($data, $employee, "Z", $extraData);
                            array_push($printJob,$printJob2);
                        }else{
                            PrintServiceHelper::printXZReport($data, $employee, "Z", $extraData);
                        }
                    }

                    try {
                        $isMailDisabled = $store->configurationFromKey(StoreConfigurations::DISABLE_CLOSE_MAIL_KEY);

                        foreach ($store->mailRecipients as $mailRecipient) {
                            $email = config('app.env') === 'local'
                                ? config('app.mail_development')
                                : $mailRecipient->email;

                            if (strpos($email, '@xxx.xxx') !== false) {
                                continue;
                            }

                            if ($isMailDisabled == false) {
                                Mail::to($email)
                                  ->send(new CloseDayHIPOSummary($store, $data));
                            }

                            if (isset($extraData)) {
                                Mail::to($email)
                                    ->send(new CutXZEmail($store, $data, $extraData, "Z", $employee));
                            }

                            if (config('app.env') === 'local') { break; }
                        }
                    } catch (\Exception $e) {
                        Log::info('Error enviar emails de cierre de caja');
                        Log::info($e);
                    }

                    // Limpiar mesas temporales
                    $spots = Spot::where('store_id', $store->id)
                        ->where('origin', Spot::ORIGIN_MYPOS_KIOSK_TMP)->get();

                    $kioskSpot = Spot::getKioskSpot($store->id);

		    // Limpiar ordenes
		    if ($kioskSpot != null) {
		    
                    foreach ($spots as $spot) {
                        $orders = Order::where('spot_id', $spot->id)->get();
                        foreach ($orders as $order) {
                            $order->spot_id = $kioskSpot->id;
                            $order->status = 0;
                            $order->save();
                        }

                        event(new SpotDeleted($spot->toArray()));
                        $spot->delete();
		    }
		    }

                    event(new BalanceStatusUpdate(['store' => $store->toArray(), 'status' => 'closed', 'balance'=> $cashierBalance]));
                    $storeIntegrationsTokens = StoreIntegrationToken::where('store_id', $store->id)
                    ->where('is_anton', true)
                    ->whereNotNull('external_store_id')
                    ->get();
                    if($storeIntegrationsTokens->count()>0){
                        MelyRequest::sendStatusIntegration($storeIntegrationsTokens, CashierBalance::ANTON_CLOSE);
                    }
                    /*Ejecuta Integración de Siigo si existe -- Cancelado temporalemente por pruebas*/
                    /*$this->prepareToSendForElectronicBilling(
                        $store,
                        $cashierBalance->id,
                        AvailableMyposIntegration::NAME_NORMAL,
                        AvailableMyposIntegration::NAME_SIIGO,
                        null,
                        [
                            'cashier' => $cashierBalance->id,
                            'invoice' => null
                        ]
                    );*/

                    return response()->json(
                        [
                            'msg' => 'Información guardada con éxito',
                            'results' => null,
                            'printerJob' => $printJob
                        ],
                        200
                    );
                }
            );
            return $dataJSON;
        } catch (\Exception $e) {
            $this->simpleLogError(
                "CashierBalanceController closeDay: storeId: " . $store->id,
                $request->all()
            );

            $this->logError(
                "CashierBalanceController API V2: NO SE PUDO CERRAR CAJA, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'No se pudo cerrar caja',
                    'results' => null
                ],
                409
            );
        }
    }

    public function printXReport(Request $request)
    {
        $employee = $this->authEmployee;
        $store    = $employee->store;

        $config = StoreConfig::where('store_id', $store->id)->first();
        if (!is_null($config) && $config->enable_xz == false) {
            return response()->json([
                'status' => 'Esta tienda no tiene habilitada la impresión de Corte X y Corte Z',
                'results' => null
            ], 409);
        }
        
        $store->load('currentCashierBalance');
        $cashierBalance = $store->currentCashierBalance;
        if (!$cashierBalance) {
            return response()->json(
                [
                    'status' => 'No existe apertura de caja',
                    'results' => null
                ],
                409
            );
        }

        $xReports = CashierBalanceXReport::where('cashier_balance_id', $cashierBalance->id)->orderBy('id', 'DESC')->get();

        $reportNumber = 1;
        $dateOpen = null;
        $hourOpen = null;
        $orderIds = [];
        if (!is_null($xReports)) {
            if (count($xReports) > 0) {
                $reportNumber = $xReports[0]->x_cashier_number_day + 1;
                $dateOpen = $xReports[0]->date_close;
                $hourOpen = $xReports[0]->hour_close;
            }
            foreach ($xReports as $xReport) {
                $orderIds = array_merge($orderIds, array_values($xReport->order_ids));
            }
            array_unique($orderIds);
        }

        try {
            $dataJSON = DB::transaction(
                function () use ($request, $employee, $cashierBalance, $store, $orderIds, $reportNumber, $dateOpen, $hourOpen) {
                    // Los datos del request pueden estar desactualizados. Volver a obtenerlos.
                    $valuesData = $this->getValuesCashierBalanceX($cashierBalance->id, $orderIds);
                    $dateClose = TimezoneHelper::localizedNowDateForStore($store);
                    $data = $this->formatCashierValuesForMail($request, $cashierBalance, $valuesData, $dateClose->format('Y-m-d'), $dateClose->format('H:i:s'));

                    try {
                        $printJob = null;
                        if (!is_null($dateOpen)) {
                            $data["date_open"] = $dateOpen;
                            $data["hour_open"] = $hourOpen;
                        }

                        $emails = [];
                        if (config('app.env') === 'local') {
                            array_push($emails, config('app.mail_development'));
                        }

                        foreach ($store->employees as $employee) {
                            if ($employee->user == null || !$employee->user->isAdminStore()) {
                                continue;
                            }
                            array_push($emails, $employee->user->email);
                        }

                        $config = StoreConfig::where('store_id', $store->id)->first();
                        if (!is_null($config) && $config->enable_xz) {
                            // Extra Data
                            $extraData = $this->extraDataCashierBalanceX($cashierBalance, $orderIds);
                            // Imprimir reporte X y Z de la tienda/caja
                            $request->print_browser = isset($request->print_browser)?$request->print_browser:false;
                            if($request->print_browser){
                                $printJob = PrintServiceHelper::getXZJobs($data, $employee, "X", $extraData);
                            }else{
                                PrintServiceHelper::printXZReport($data, $employee, "X", $extraData);
                            }
                            
                            
                            try {
                                foreach ($store->mailRecipients as $mailRecipient) {
                                    $email = config('app.env') === 'local' 
                                        ? config('app.mail_development') 
                                        : $mailRecipient->email;

                                    if (strpos($email, '@xxx.xxx') !== false) {
                                        continue;
                                    }

                                    Mail::to($email)
                                        ->send(new CutXZEmail($store, $data, $extraData, "X", $employee));
        
                                    if (config('app.env') === 'local') { break; }
                                }
                            } catch (\Exception $e) {
                                Log::info('Error enviar emails de corte X');
                                Log::info($e);
                            }
                        }

                        $dateClose = TimezoneHelper::localizedNowDateForStore($store);

                        $xReport = new CashierBalanceXReport();
                        $xReport->x_cashier_number_day = $reportNumber;
                        $xReport->cashier_balance_id = $cashierBalance->id;
                        $xReport->employee_id = $employee->id;
                        $xReport->order_ids = array_unique($valuesData["order_ids"]);
                        $xReport->date_close = $dateClose->format('Y-m-d');
                        $xReport->hour_close = $dateClose->format('H:i:s');
                        $xReport->save();
                    } catch (\Exception $e) {
                        Log::info('Se capturo el ERROR');
                        Log::info($e);
                    }

                    return response()->json([
                        'status' => 'Reporte generado exitosamente',
                        'results' => null,
                        'printerJob'=> $printJob
                    ], 200);
                }
            );
            return $dataJSON;
        } catch (\Exception $e) {
            $this->simpleLogError(
                "CashierBalanceController printXReport: storeId: " . $store->id,
                $request->all()
            );

            $this->logError(
                "CashierBalanceController API V2: NO SE PUDO GENERAR EL REPORTE X, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'No se pudo generar el reporte X',
                    'results' => null
                ],
                409
            );
        }
    }
}
