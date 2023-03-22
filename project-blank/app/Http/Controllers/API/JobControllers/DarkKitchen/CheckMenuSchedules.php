<?php

namespace App\Http\Controllers\API\JobControllers\DarkKitchen;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Log;
use App\Section;
use Carbon\Carbon;
use App\Jobs\DarkKitchen\OpenCashier;
use App\Jobs\DarkKitchen\CloseCashier;
use App\StoreConfig;
use App\CashierBalance;

class CheckMenuSchedules extends Controller
{
    public function __construct()
    {
        //
    }

    /**
     * Funcion principal
     * @return void
     */
    public function checkMenus()
    {
        $this->log("Verificando cajas para abrir por horarios de menú...");
        $this->checkToOpenCashier();

        $this->log('Verificando cajas para abrir por horarios en store_config...');
        $this->openCashierWithSetTime();

        $this->log('Verificando cajas para cerrar por horarios de menú...');
        $this->checkToCloseCashier();

        $this->log('Verificando cajas para cerrar por horarios en store_config...');
        $this->closeCashierWithSetTime();

        $this->log('Verificando cajas para cerrar por horarios especiales...');
        $this->checkToCloseCashierWithSpecialDays();

        return response()->json(['status' => 'OK'], 200);
    }

    public function checkToOpenCashier()
    {
        // Trae todas las tiendas que tengan que no tenga configurado auto_close_time y auto_open_time
        $stores = StoreConfig::with('store.currentCashierBalance')->whereNull('auto_open_time')
            ->where('auto_open_close_cashier', 1);
        if (config('app.env') != 'production') { // ***
            $stores = $stores->where('store_id', 3);
        }
        $stores = $stores->whereNotNull('time_zone')->get();

        $cashiersToOpen = [];
        foreach ($stores as $store) {
            // Si para la tienda actual ya existe una caja abierta, entonces abandona
            // la iteración y continúa con la siguiente tienda
            $hasOpenCashier = $store->store->currentCashierBalance;
            if ($hasOpenCashier) {
                continue;
            }

            $timeZone = $store->time_zone;
            // Trae todos los menús que estén disponibles para el día de hoy
            $menus = Section::where('store_id', $store->store_id)
                ->with(['availabilities' => function ($availability) use ($timeZone) {
                    $availability->where('day', Carbon::now($timeZone)->format('N'))->with('periods');
                }])->get();

            $allHoursOpen = []; // Almacena todos los horarios de apertura
            // Recorre los menús disponibles para capturar las horas de apertura y guardarlas en $allHoursOpen
            foreach ($menus as $menu) {
                foreach ($menu->availabilities as $availabilities) {
                    foreach ($availabilities->periods as $periods) {
                        array_push($allHoursOpen, $periods->start_time);
                    }
                }
            }
            // Si no se encuentran horarios para comparar en $minEarlyHourToOpen, se continúa con la siguiente tienda
            if (count($allHoursOpen) == 0) {
                continue;
            }

            // Determinamos cuál es el horario de apertura de menú más temprano en $allHoursOpen
            $minEarlyHourToOpen = min($allHoursOpen);

            // Formateamos hora actual y menor hora de apertura de menú
            $actualHourHours = Carbon::now($store->time_zone)->format('H');

            $incomingFormat = "Y-m-d H:i:s";
            $store_time_zone = Carbon::now($store->time_zone)->format('Y-m-d') . " " . $minEarlyHourToOpen;
            $startTime = Carbon::createFromFormat($incomingFormat, $store_time_zone, $store->time_zone);

            // Verificamos si la hora actual es igual a la menor hora de apertura de menú de la tienda, entonces abrimos caja
            if ($actualHourHours == $startTime->format('H')) {
                array_push($cashiersToOpen, $store->store_id);
            }
        }

        if (!empty($cashiersToOpen)) {
            $this->log('Abriendo cajas en tiendas con IDs: '.json_encode($cashiersToOpen));
            dispatch(new OpenCashier($cashiersToOpen));
        } else {
            $this->log('Sin cajas para abrir');
        }
    }

    public function openCashierWithSetTime($testing = false)
    {
        // Trae todas las tiendas con caja automatica y que tengan seteadas las horas de apertura y cierre
        $stores = StoreConfig::with('store.currentCashierBalance')->where('auto_open_close_cashier', 1);
        if (config('app.env') != 'production') { // ***
            $stores = $stores->where('store_id', 3);
        }
        $stores = $stores->whereNotNull('auto_open_time')
            ->whereNotNull('auto_close_time')
            ->get();
        $this->log('Count stires:: '.json_encode($stores->count()));

        $cashiersToOpen = [];
        // Recorre todas las tiendas
        foreach ($stores as $store) {
            // Si para la tienda actual ya existe una caja abierta, entonces abandona
            // la iteración y continúa con la siguiente tienda
            $hasOpenCashier = $store->store->currentCashierBalance;
            if ($store->store->id == 783) {
                $this->log('Has open cashier:: '.json_encode($hasOpenCashier));
            }

            if ($hasOpenCashier) {
                continue;
            }

            // Formateamos hora actual y hora de apertura
            $actualHourHours = Carbon::now($store->time_zone)->format('H:i');

            $incomingFormat = 'Y-m-d H:i:s';
            $store_auto_open_time  = Carbon::now($store->time_zone)->format('Y-m-d')." ".$store->auto_open_time;
            $store_auto_close_time = Carbon::now($store->time_zone)->format('Y-m-d')." ".$store->auto_close_time;
            $startTime = Carbon::createFromFormat($incomingFormat, $store_auto_open_time, $store->time_zone);
            $endTime   = Carbon::createFromFormat($incomingFormat, $store_auto_close_time, $store->time_zone);
            if ($store->store->id == 783) {
                $this->log('Actual hour:: '.json_encode($actualHourHours));
                $this->log('StartTime  :: '.json_encode($startTime), true);
                $this->log('EndTime    :: '.json_encode($endTime), true);
            }
            // Verificamos si la hora actual es igual a la hora de apertura configurada, y abrimos la caja
            if ($actualHourHours >= $startTime->format('H:i') && $actualHourHours < $endTime->format('H:i')){
                array_push($cashiersToOpen, $store->store_id);
            } elseif ($actualHourHours >= $startTime->format('H:i') && $startTime->format('H:i') >= $endTime->format('H:i')){
                array_push($cashiersToOpen, $store->store_id);
            }
        }

        if (!empty($cashiersToOpen)) {
            $this->log('Abriendo cajas en tiendas con IDs:: '.json_encode($cashiersToOpen));
            if ($testing) {
                dispatch_now(new OpenCashier($cashiersToOpen));
            } else {
                dispatch(new OpenCashier($cashiersToOpen));
            }
        } else {
            $this->log('Sin cajas para abrir');
        }
    }

    public function checkToCloseCashier()
    {
        // Trae todas las tiendas que tengan que no tenga configurado auto_close_time y auto_open_time
        $stores = StoreConfig::with('store.currentCashierBalance')
            ->where('auto_open_close_cashier', 1);
        if (config('app.env') != 'production') { // ***
            $stores = $stores->where('store_id', 3);
        }
        $stores = $stores->whereNull('time_zone')
            ->whereNull('auto_close_time')
            ->get();

        $cashiersToClose = [];
        foreach ($stores as $store) {
            // Si para la tienda actual no existe una caja abierta, entonces abandona
            // la iteración y continúa con la siguiente tienda
            $hasOpenCashier = $store->store->currentCashierBalance;
            if (!$hasOpenCashier) {
                continue;
            }

            $timeZone = $store->time_zone;
            $actualDay = Carbon::now($timeZone)->format('N');

            // En $menus trae todos los menús que estén disponibles para el día de hoy
            $menus = Section::where('store_id', $store->store_id)
                ->with(['availabilities' => function ($availability) use ($timeZone, $actualDay) {
                    $availability->where('day', $actualDay)->with('periods');
                }])
                ->get();

            // Almacena todos los horarios de apertura y cierre
            $allHoursClose = [];
            $allHoursOpen = [];

            // Recorre los menús disponibles para capturar las horas de cierre y guardarlas en $allHoursClose
            foreach ($menus as $menu) {
                foreach ($menu->availabilities as $availabilities) {
                    foreach ($availabilities->periods as $periods) {
                        // Se procesan únicamente los horarios especificados para el mismo día.
                        // Por ende, si end_day está especificado, quiere decir que este horario no se ejecuta el día actual
                        if (!$periods->end_day) {
                            array_push($allHoursClose, $periods->end_time);
                            array_push($allHoursOpen, $periods->start_time);
                        }
                    }
                }
            }

            // Si no se encuentran horarios para comparar en $maxLateHourToClose, se continúa con la siguiente tienda
            if (count($allHoursClose) == 0) {
                continue;
            }

            // Determinamos cuál es el horario de cierre de menú más tarde en $allHoursClose
            $maxLateHourToClose = max($allHoursClose);
            $minEarlyHourToOpen = min($allHoursOpen);
            $maxLateFix = [];
            foreach ($allHoursClose as $horas) {
                if ($horas < $minEarlyHourToOpen) {
                    array_push($maxLateFix, $horas);
                }
            }

            if (count($maxLateFix)>0) {
                $maxLateHourToClose = max($maxLateFix);
            }

            // Formateamos hora actual y mayor hora de cierre de menú teniendo en cuenta los minutos y segundos
            $actualHourHours = Carbon::now($store->time_zone)->format('H:i:s');
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now($store->time_zone)->format('Y-m-d')." ".$maxLateHourToClose, $store->time_zone);
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now($store->time_zone)->format('Y-m-d')." ".$minEarlyHourToOpen, $store->time_zone);

            // Verificamos si la hora actual (teniendo en cuenta los minutos y segundos)
            // supera la mayor hora de cierre de menú de la tienda, entonces cerramos caja
            $this->log('Cerrando cajas en tiendas con IDs: ');
            if ($actualHourHours >= $endTime->format('H:i:s') && $actualHourHours < $startTime->format('H:i:s')) {
                array_push($cashiersToClose, $store->store_id);
            } elseif ($actualHourHours >= $endTime->format('H:i:s') && $endTime->format('H:i:s') > $startTime->format('H:i:s')
                && $actualHourHours > $startTime->format('H:i:s')) {
                array_push($cashiersToClose, $store->store_id);
            }
        }

        if (!empty($cashiersToClose)) {
            $this->log('Cerrando cajas en tiendas con IDs: '.json_encode($cashiersToClose));
            dispatch(new CloseCashier($cashiersToClose));
        } else {
            $this->log('Sin cajas para cerrar.');
        }
    }

    public function checkToCloseCashierWithSpecialDays()
    {
        /* Trae todas las tiendas que tengan que no tenga configurado auto_close_time y auto_open_time*/
        $stores = StoreConfig::with('store.currentCashierBalance')
            ->where('auto_open_close_cashier', 1);
        if (config('app.env') != 'production') { // ***
            $stores = $stores->where('store_id', 3);
        }
        $stores = $stores->whereNotNull('time_zone')
            ->whereNull('auto_close_time')
            ->get();

        $cashiersToClose = [];
        foreach ($stores as $store) {
            // Para la tienda actual no existe una caja abierta, entonces abandona
            // la iteración y continúa con la siguiente tienda
            $hasOpenCashier = $store->store->currentCashierBalance;
            if (!$hasOpenCashier) {
                continue;
            }

            $timeZone = $store->time_zone;
            $actualDay = Carbon::now($timeZone)->format('N');

            // En $menus trae todos los menús disponibles
            $menus = Section::where('store_id', $store->store_id)
                ->with(['availabilities' => function ($availability) use ($timeZone, $actualDay) {
                    $availability->with('periods');
                }])
                ->get();

            // Recorre los menús disponibles para procesar las horas de cierre
            $allHoursClose =[];
            foreach ($menus as $menu) {
                // Recorre cada día de disponibilidad
                foreach ($menu->availabilities as $availabilities) {
                    // Recorre cada periodo de disponibilidad durante el día
                    foreach ($availabilities->periods as $periods) {
                        // Verifica si end_day está especificado y si corresponde al día actual,
                        // para agregarlo a un arreglo y luego ejecutar la hora de cierre mayor
                        if ($periods->end_day != null && $periods->end_day == $actualDay) {
                            array_push($allHoursClose, $periods->end_time);
                        }
                    }
                }
            }

            // Si no se encuentran horarios para comparar en $maxLateHourToClose, se continúa con la siguiente tienda
            if (count($allHoursClose) == 0) {
                continue;
            }

            // Determinamos cuál es el horario de cierre de menú más tarde en $allHoursClose
            $maxLateHourToClose = max($allHoursClose);
            $actualHourWithMinutes = Carbon::now($store->time_zone)->format('H:i:s');
            $endTimeWithMinutes = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now($store->time_zone)->format('Y-m-d')." ".$maxLateHourToClose, $store->time_zone);
            // Si la hora actual supera la hora del menú, entonces cierra caja
            if ($actualHourWithMinutes >= $endTimeWithMinutes->format('H:i:s')) {
                $this->log('Cerrando caja con horarios del menú del día '.$availabilities->day.', y día de cierre '.$periods->end_day.' a las '.$endTimeWithMinutes->format('H:i:s'));
                array_push($cashiersToClose, $store->store_id);
            }
        }

        if (!empty($cashiersToClose)) {
            $this->log('Cerrando cajas en tiendas con IDs: '.json_encode($cashiersToClose));
            dispatch(new CloseCashier($cashiersToClose));
        } else {
            $this->log('Sin cajas para cerrar.');
        }
    }

    public function closeCashierWithSetTime($testing = false)
    {
        // Trae todas las tiendas que tengan configurado auto_close_time
        $stores = StoreConfig::with('store.currentCashierBalance')
            ->where('auto_open_close_cashier', 1);
        if (config('app.env') != 'production') { // ***
            $stores = $stores->where('store_id', 3);
        }
        $stores = $stores->whereNotNull('auto_close_time')
            ->whereNotNull('auto_open_time')
            ->orderBy('store_id', 'asc')
            ->get();

        $cashiersToClose = [];
        foreach ($stores as $store) {
            // Si para la tienda actual no existe una caja abierta, entonces abandona
            // la iteración y continúa con la siguiente tienda
            $hasOpenCashier = $store->store->currentCashierBalance;
            if (!$hasOpenCashier) {
                continue;
            }

            // Formateamos hora actual y hora de cierre
            $actualDate = Carbon::now($store->time_zone);
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now($store->time_zone)->format('Y-m-d')." ".$store->auto_close_time, $store->time_zone);
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now($store->time_zone)->format('Y-m-d')." ".$store->auto_open_time, $store->time_zone);
            $this->log('Store     : '.$store->store_id);
            $this->log('StartTime : '.$startTime, true);
            $this->log('EndTime   : '.$endTime, true);
            // Si la hora de cierre es menor que la hora de apertura
            if ($endTime->format('H:i') < $startTime->format('H:i')) {
                $this->log('Cierra el día siguiente');
                // Toma la última fecha de apertura para verificar que es otro día y no el mismo día de apertura
                $dateLastOpenedCashier = Carbon::createFromFormat('Y-m-d', $hasOpenCashier->first()->date_open, $store->time_zone);
                // Verificamos si la hora actual es igual a la hora de cierre configurada, y cerramos la caja
                if ($endTime->format('d') > $dateLastOpenedCashier->format('d') && $actualDate->format('H:i') >= $endTime->format('H:i')){
                    array_push($cashiersToClose, $store->store_id);
                } elseif ($actualDate->format('H:i') == $endTime->format('H:i') && $startTime->format('H:i')!=$endTime->format('H:i')){
                    array_push($cashiersToClose, $store->store_id);
                }
            } else {
                // Verificamos si la hora actual es igual a la hora de cierre configurada, y cerramos la caja
                if ($actualDate->format('H:i') >= $endTime->format('H:i') && $startTime->format('H:i')!=$endTime->format('H:i')){
                    array_push($cashiersToClose, $store->store_id);
                } elseif ($actualDate->format('H:i') <  $startTime->format('H:i') && $actualDate->format('H:i') <  $endTime->format('H:i')){
                    array_push($cashiersToClose, $store->store_id);
                }
            }
            break;
        }

        if (!empty($cashiersToClose)) {
            Log::channel('auto_cashier')->info('Cerrando cajas en tiendas con IDs: '.json_encode($cashiersToClose));
            if ($testing) {
                dispatch_now(new CloseCashier($cashiersToClose));
            } else {
                dispatch(new CloseCashier($cashiersToClose));
            }
        } else {
            Log::channel('auto_cashier')->info('Sin cajas para cerrar.');
        }
    }

    public function log($message, $omitLine = false)
    {
        if ($omitLine === false) {
            Log::channel('auto_cashier')->info('------------------------------------------------');
        }
        Log::channel('auto_cashier')->info($message);
    }
}
