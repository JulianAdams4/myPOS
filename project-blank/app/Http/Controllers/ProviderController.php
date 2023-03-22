<?php

namespace App\Http\Controllers;

use Log;
use App\Providers;
use App\Helper;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    use AuthTrait, LoggingHelper;

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

    public function createProvider(Request $request)
    {
        $store = $this->authStore;
        if (!$store) {
            return response()->json([
                'status' => 'No permitido',
                'results' => []
            ], 409);
        }

        try {
            $storeId = $request['store_id'] ? $request['store_id'] : $store->id;

            $provider = new Providers();
            $provider->store_id = $storeId;
            $provider->name = $request['name'];
            $provider->identification = $request['identification'];
            $provider->email = $request['email'];

            if ($request['phone'] != null) {
                $provider->phone = $request['phone'];
            }

            if ($request['address'] != null) {
                $provider->address = $request['address'];
            }

            if ($request['credit_days'] != null) {
                $provider->credit_days = $request['credit_days'];
            }
            $provider->save();

            return response()->json([
                'status' => 'Exito',
                'results' => $provider
            ], 200);
        } catch (\Exception $e) {
            $this->logError(
                "ProviderController API Provider: ERROR AL CREAR PROVEEDOR, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo crear el proveedor',
                'results' => null
            ], 500);
        }
    }

    public function getProviders(Request $request)
    {
        $store = $this->authStore;
        if (!$store) {
            return response()->json([
                'status' => 'No permitido',
                'results' => []
            ], 409);
        }

        $storeId = $request['store_id'] ? $request['store_id'] : $store->id;

        $providers = Providers::where('store_id', $storeId)->get();

        return response()->json([
            'status' => 'Exito',
            'results' => $providers
        ], 200);
    }
}
