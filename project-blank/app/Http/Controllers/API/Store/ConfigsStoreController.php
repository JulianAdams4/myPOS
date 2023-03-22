<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\Jobs\DarkKitchen\CheckMenuSchedules;
use App\StoreConfig;
use App\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ConfigsStoreController extends Controller
{
    use AuthTrait;
    use LoggingHelper;

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

    public function getConfig(Request $request)
    {
        $store = $this->authStore;
        $config = StoreConfig::where('store_id', $store->id)->first();
        //Se añade un objeto que posee las configuraciones disponibles de moneda para el store
        $nations =array();
        $nation = new \stdClass();
        $nation->locale = "MX"  ;
        $nation->nation =  "US";
        $nation->minimumFractionDigits =2;
        $nation->maximumFractionDigits = 2;
        array_push ($nations,$nation);
        $nation1 = new \stdClass();
        $nation1->locale = "CO"  ;
        $nation1->nation =  "de-DE";
        $nation1->minimumFractionDigits =2;
        $nation1->maximumFractionDigits = 2;
        array_push ($nations,$nation1);
        $nation2 = new \stdClass();
        $nation2->locale = "EC"  ;
        $nation2->nation =  "US";
        $nation2->minimumFractionDigits =2;
        $nation2->maximumFractionDigits = 2;
        array_push ($nations,$nation2);
        return response()->json(
            [
                'status' => 'Exito',
                'results' => $config,
                'nations' =>$nations
            ],
            200
        );
    }

    public function getInventoryStores(Request $request)
    {
        $store = $this->authStore;

        return response()->json(
            [
                'status' => 'Exito',
                'results' => Store::where('company_id', $store->company_id)->get()
            ],
            200
        );
    }

    public function switchIsDK(Request $request)
    {
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->is_dark_kitchen = $store->configs->is_dark_kitchen == 1 ? 0 : 1;
        $storeConfig->save();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ],
            200
        );
    }

    public function switchAutoCashier(Request $request)
    {
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->auto_open_close_cashier = $store->configs->auto_open_close_cashier == 1 ? 0 : 1;
        $storeConfig->save();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ],
            200
        );
    }

    public function setTimesAutoCashier(Request $request)
    {
        $store = $this->authStore;

        $field = $request->type_time;
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->$field = $request->new_time;
        $storeConfig->save();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ],
            200
        );
    }

    public function switchEmployeesModifyOrders(Request $request){
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->employees_edit = $store->configs->employees_edit == 1 ? 0 : 1;
        $storeConfig->save();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ], 200
        );
    }

    public function setTimeZone(Request $request)
    {
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->time_zone = trim($request->new_time_zone);
        $storeConfig->save();
        Cache::forever("store:{$store->id}:configs:timezone", trim($request->new_time_zone));

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ],
            200
        );
    }

    public function unitTestingAutoCashier(Request $request)
    {
        $Scheduler = new CheckMenuSchedules();
        $Scheduler->checkToOpenCashier();
        $Scheduler->openCashierWithSetTime(true);
        $Scheduler->checkToCloseCashier();
        $Scheduler->closeCashierWithSetTime(true);
        $Scheduler->checkToCloseCashierWithSpecialDays();

        return response()->json(
            [
                'status' => 'Exito'
            ],
            200
        );
    }
public function pingTest(Request $request)
    {

        return response()->json(
            [
                'status' => 'Exito'
            ],
            200
        );
    }

    public function setInventoryStore(Request $request)
    {
        $store = $this->authStore;

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $storeConfig->inventory_store_id = $request->store_id;
        $storeConfig->save();

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $storeConfig
            ],
            200
        );
    }

    public function updateDollarConversion(Request $request)
    {
        $store = $this->authStore;

        try {
            $dataJSON = DB::transaction(
                function () use ($request, $store) {
                    $storeConfig = StoreConfigs::where('store_id', $store->id)->first();
                    $storeConfig->dollar_conversion = $request->dollar_conversion;
                    $storeConfig->save();

                    return response()->json([
                        'status' => 'Tipo de conversión actualizada',
                        'results' => null
                    ], 200);
                }
            );
            return $dataJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "Error al actualizar el tipo de conversión a dólar, para el store: " . $store->name,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo actualizar el tipo de conversión a dólar',
                'results' => null
            ], 409);
        }
    }

    public function getDataStores(Request $request)
    {
        $stores = Store::select(
            'id',
            'name',
            'country_code'
        )->get();

        return response()->json([
            'results' => $stores
        ], 200);
    }
    public function updateStoreMoneyFormat(Request $request)
    {
        $store = $this->authStore;

        try {
            $dataJSON = DB::transaction(
                function () use ($request, $store) {
                    $storeConfig = StoreConfig::where('store_id', $store->id)->first();
                    $storeConfig->store_money_format = json_encode($request->store_money_format);
                    $storeConfig->save();

                    return response()->json([
                        'status' => 'Formato de moneda actualizado',
                        'results' => null
                    ], 200);
                }
            );
            return $dataJSON;
        } catch (\Exception $e) {
            $this->logError(
                "Error al actualizar el formato de moneda , para el store: " . $store->name,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo actualizar el formato de moneda',
                'results' => null
            ], 409);
        }
    }

    /**
     * Se setea para todas las tiendas de una company
     */
    public function toggleZeroLowerLimit(Request $request)
    {
        $store = $this->authStore;

        try {
            $dataJSON = DB::transaction(function () use ($request, $store) {
                $prevValue = $store->configs->zero_lower_limit;

                $ids = $store->company->stores()->pluck('id');
                $companyStoresConfigs = StoreConfig::whereIn('store_id', $ids)
                    ->update(['zero_lower_limit' => !$prevValue]);

                return response()->json([
                    'status' => 'Límite inferior actualizado',
                    'results' => !$prevValue
                ], 200);
            });
            return $dataJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "Error al actualizar el límite inferior, para la company: " . $store->company->name,
                "daily", $e->getMessage(), $e->getFile(), $e->getLine(), json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo actualizar el límite inferior de la compañía',
                'results' => null
            ], 409);
        }
    }

    /**
     * Se setea para todas las tiendas de una company
     */
    public function toggleRestrictiveStock(Request $request)
    {
        $store = $this->authStore;

        try {
            $dataJSON = DB::transaction(function () use ($request, $store) {
                $prevValue = StoreConfig::where('store_id', $store->id)->first();
                $productions = filter_var($prevValue->restrictive_stock_production);
                $sales       = filter_var($prevValue->restrictive_stock_sales);

                // Definimos los proximos valores de 'production' y 'sales'
                $toggleFor = $request->toggleFor;
                $results = null;
                // Toggle Switch Padre
                if (strcmp($toggleFor, 'all') === 0) {
                    if ($productions || $sales) { // Ya había uno activado (Padre activado)
                        $nextProduction = false; // Se desactivan ambas
                        $nextSales = false;
                    } else { // Ninguno activado (Padre desactivado)
                        $nextProduction = true; // Se activan ambas
                        $nextSales = true;
                    }
                // Toggle Switch Hijos
                } else if (strcmp($toggleFor, 'production') === 0) {
                    $nextProduction = !$productions; // Toggle production switch
                    $nextSales      = $sales;
                    $results        = !$productions;
                } else if (strcmp($toggleFor, 'sales') === 0) {
                    $nextProduction = $productions;
                    $nextSales      = !$sales; // Toggle sales switch
                    $results        = !$sales;
                }

                // Actualizamos los valores para todos los stores de la company
                $ids = $store->company->stores()->pluck('id');
                $companyStoresConfigs = StoreConfig::whereIn('store_id', $ids)
                    ->update([
                        'restrictive_stock_production' => $nextProduction,
                        'restrictive_stock_sales' => $nextSales
                    ]);

                return response()->json([
                    'status' => 'Stock Restrictivo actualizado',
                    'results' => $results
                ], 200);
            });
            return $dataJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "Error al actualizar el stock restrictivo, para la company: " . $store->company->name,
                "daily", $e->getMessage(), $e->getFile(), $e->getLine(), json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo actualizar el stock restrictivo de la compañía',
                'results' => null
            ], 409);
        }
    }
}
