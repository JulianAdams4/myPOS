<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\MetricUnit;
use App\Employee;
use App\Traits\AuthTrait;

class MetricUnitController extends Controller
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

    public function getMetricUnits()
    {
        $store = $this->authStore;
        $units = MetricUnit::where('company_id', $store->company_id)->where('status', 1)->get();
        return response()->json(
            [
                'status' => 'Listando unidades',
                'results' => $units
            ],
            200
        );
    }
}
