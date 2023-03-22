<?php

namespace App\Http\Controllers\API\Store;

use App\Traits\TimezoneHelper;
use App\Http\Controllers\Controller;
use App\CashierBalance;
use Illuminate\Http\Request;
use App\Traits\AuthTrait;
use App\Traits\CashierBalanceHelper;

class CashierBalanceController extends Controller
{
    use AuthTrait;
    use CashierBalanceHelper;

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

    public function getReportCashierBalances(Request $request)
    {
        $store = $this->authStore;
        $initialDate = TimezoneHelper::convertToServerDateTime($request->dates['from']."00:00:00", $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($request->dates['to']."23:59:59", $store);
        $cashierBalances = CashierBalance::where('store_id', $store->id)
                            ->whereBetween('created_at', [$initialDate, $finalDate])
                            ->with(['employeeOpen', 'employeeClose'])
                            ->orderBy('created_at', 'desc')
                            ->get();
        $hasUberEats = false;
        $hasRappi = false;
        $hasRappiPay = false;
        foreach ($cashierBalances as &$cashierBalance) {
            $valuesData = $this->getValuesCashierBalance($cashierBalance->id);
            $cashierBalance["value_cash"] = $valuesData["close"];
            $cashierBalance["value_card"] = $valuesData["card"];
            $cashierBalance["transfer"] = $valuesData["transfer"];
            $cashierBalance["rappi_pay"] = $valuesData["rappi_pay"];
            $cashierBalance["others"] = $valuesData["others"];
            $cashierBalance["external_values"] = $valuesData["external_values"];
            if ($valuesData["has_uber_eats"]) {
                $hasUberEats = true;
            }
            if ($valuesData["has_rappi"]) {
                $hasRappi = true;
            }
            if ($valuesData["has_rappi_pay"]) {
                $hasRappiPay = true;
            }

            $totalReal = $valuesData["close"] + $valuesData["card"] + $valuesData["transfer"] + $valuesData["rappi_pay"] + $valuesData["others"];
            foreach(array_values($valuesData["external_values"]) as $ext){
                $totalReal = $totalReal + $ext;
            }
            $cashierBalance["value_close"] = $totalReal;
            
        }

        return response()->json(
            [
                'msg' => 'Success',
                'results' => [
                    'data' => $cashierBalances,
                    'count' => count($cashierBalances),
                    'has_uber_eats' => $hasUberEats,
                    'has_rappi' => $hasRappi,
                    'has_rappi_pay' => $hasRappiPay,
                ],
            ],
            200
        );
    }

    public function getReportCashierExpenses(Request $request)
    {
        $store = $this->authStore;
        $initialDate = TimezoneHelper::convertToServerDateTime($request->dates['from']."00:00:00", $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($request->dates['to']."23:59:59", $store);
        $cashierBalances = CashierBalance::where('store_id', $store->id)
                            ->whereBetween('created_at', [$initialDate, $finalDate])
                            ->with(['employeeOpen', 'employeeClose', 'expenses'])
                            ->orderBy('created_at', 'desc')
                            ->get();
        $hasUberEats = false;
        $hasRappi = false;
        $hasRappiPay = false;
        foreach ($cashierBalances as &$cashierBalance) {
            $valuesData = $this->getValuesCashierBalance($cashierBalance->id);
            $cashierBalance["value_cash"] = $valuesData["close"];
            $cashierBalance["value_card"] = $valuesData["card"];
            $cashierBalance["transfer"] = $valuesData["transfer"];
            $cashierBalance["rappi_pay"] = $valuesData["rappi_pay"];
            $cashierBalance["others"] = $valuesData["others"];
            $cashierBalance["external_values"] = $valuesData["external_values"];
            if ($valuesData["has_uber_eats"]) {
                $hasUberEats = true;
            }
            if ($valuesData["has_rappi"]) {
                $hasRappi = true;
            }
            if ($valuesData["has_rappi_pay"]) {
                $hasRappiPay = true;
            }
        }

        return response()->json(
            [
                'msg' => 'Success',
                'store_name' => $store->name,
                'results' => [
                    'data' => $cashierBalances,
                ],
            ],
            200
        );
    }
}
