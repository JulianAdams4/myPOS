<?php

namespace App\Http\Controllers;

use Log;
use Auth;
use App\Spot;
use App\Helper;
use App\Employee;
use App\TaxesTypes;
use App\Traits\AuthTrait;
use App\IntegrationsCities;
use Illuminate\Http\Request;
use App\Traits\ValidateToken;
use App\StoreIntegrationToken;
use App\IntegrationsDocumentTypes;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    // use AuthTrait;
    use ValidateToken, AuthTrait;

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

    /*
    getUserStore
    Retorna el nombre y el store del admin-store loggueado
    */
    public function getUserEmployee()
    {
        $employee = $this->authEmployee;
        return response()->json([
          'status' => 'Exito',
          'results' => [
              'name'=> $employee->name,
              'store_id'=> $employee->store_id,
              'type' => $employee->type_employee
            ]
        ], 200);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * List coworkers
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getCoworkers(Request $request)
    {
        $employee = $this->authEmployee;
        if ($employee === null) {
            $this->simpleLogError(
                "EmployeeController getCoworkers employee: NO SE PUDO OBTENER EL EMPLEADO",
                $request->all()
            );
            return response()->json(
                [
                    'status' => 'El empleado no existe',
                    'results' => null
                ],
                401
            );
        }
        $store = $employee->store;
        $store->load('employees');
        $employees = $store->employees;
        $employeeWithIndex = [];
        foreach ($employees as $key => $employeeDB) {
            $employeeDB['index'] = $key;
            $employeeWithIndex[] = $employeeDB
                ->makeHidden(['email', 'location_id', 'type_employee'])
                ->toArray();
        }
        return response()->json(
            [
                'status' => 'Success',
                'results' => $employeeWithIndex
            ],
            200
        );
    }

    public function getIntegrations()
    {
        $store = $this->authStore;

        if ($store) {
            // $integrations = StoreIntegrationToken::where('store_id', $store->id)
            //                     ->select('integration_name','id','scope')->distinct()->get();
            $integrations = DB::select("select distinct ami.id, st.integration_name, ami.name as third_party_name, ami.anton_integration from store_integration_tokens st
            left join available_mypos_integrations ami on ami.anton_integration = st.scope and ami.anton_integration is not null 
				where st.store_id=?;",array($store->id));

            return response()->json(
                [
                    'results' => $integrations
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'No autorizado',
                    'results' => null
                ],
                401
            );
        }
    }

    public function getTaxesCategories()
    {
        $store = $this->authStore;

        $categories = TaxesTypes::where('country', $store->country_code)->get();

        return response()->json(
            [
                'results' => $categories
            ],
            200
        );
    }

    public function getDocumentTypes($integration_name)
    {
        $store = $this->authStore;

        if ($store) {
            $integrations = IntegrationsDocumentTypes::where('integration_name', $integration_name)->get();

            return response()->json(
                [
                    'results' => $integrations
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'No autorizado',
                    'results' => null
                ],
                401
            );
        }
    }

    public function getCities($integration_name)
    {
        $store = $this->authStore;

        if ($store) {
            $integrations = IntegrationsCities::where('name_integration', $integration_name)
                ->where('country_code', 'like', '%'.$store->country_code.'%')->get();

            return response()->json(
                [
                    'results' => $integrations,
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'No autorizado',
                    'results' => null
                ],
                401
            );
        }
    }

    public function checkPinCode(Request $request)
    {
        $store = $this->authStore;
        if (!$store) {
            return response()->json(
                [
                    'status' => 'No autorizado',
                    'results' => null
                ],
                401
            );
        }

        $store_id = $store->id;
        $pinCode = $request->pinCode;
        $employee = Employee::where('store_id', $store_id)
            ->where('pin_code', $pinCode)
            ->first();
        if (!$employee) {
            return response()->json(
                [
                    'status' => 'Empleado no existe o PIN incorrecto',
                    'results' => null
                ],
                404
            );
        }

        return response()->json(
            [
                'status' => 'Success',
                'results' => $employee
            ],
            200
        );
    }
}
