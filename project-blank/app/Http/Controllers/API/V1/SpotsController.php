<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Employee;
use App\Spot;
use App\Order;
use Illuminate\Http\Request;
use App\Traits\AuthTrait;
use Auth;
use App\CashierBalance;

class SpotsController extends Controller
{
    use AuthTrait;

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

    public function spots(Request $request)
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
        $spotsResponse = [];
        $allSpots = Spot::where('store_id', $store->id)->get()->toArray();
        if (count($allSpots) == 0) { // To remove
            $spotStore  = [
                "id" => -2,
                "name" => "En el local",
                'bussy' => false
            ];
            array_push($spotsResponse, $spotStore);
        }
        $spotChoose = [
            "id" => -1,
            "name" => "Seleccione el destino de la orden",
            'bussy' => false
        ];
        array_unshift($spotsResponse, $spotChoose);
        $index = 0;
        foreach ($allSpots as $spot) {
            $bussy = false;
            if ($cashierBalance) {
                $activeOrdersCount = Order::where('store_id', $store->id)
                    ->where('cashier_balance_id', $cashierBalance->id)
                    ->where('spot_id', $spot['id'])
                    ->where('preorder', 1)
                    ->where('status', 1)
                    ->count();
                $bussy = $activeOrdersCount > 0;
            }
            $spot['index'] = $index;
            $spot['bussy'] = $bussy;
            array_push($spotsResponse, $spot);
            $index++;
        }
        return response()->json(
            [
                'status' => 'Success',
                'results' => $spotsResponse
            ],
            200
        );
    }
}
