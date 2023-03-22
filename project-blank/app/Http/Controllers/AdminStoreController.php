<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Traits\AuthTrait;
use App\Store;
use Auth;
use Log;
use App\Employee;
use App\Role;

class AdminStoreController extends Controller
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

    /*
    getUserStore
    Retorna el nombre y el store del admin-store loggueado
    */
    public function getUserStore()
    {
        return response()->json([
          'status' => 'Exito',
          'results' => [
              'name' => $this->authEmployee->name,
              'store_id' => $this->authStore->id,
              'company_id' => $this->authStore->company_id,
            ]
        ], 200);
    }

    /**
     * Set admin passcode
     */
    public function setAdminPasscode(Request $request)
    {
        $employee = $this->authEmployee;

        if ($request['passcode'] == null
        || strlen($request['passcode']) == 0) {
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'La clave no puede estar vacía'
                ],
                409
            );
        }

        $employee->pin_code = isset($request['passcode']) ? $request['passcode'] : null;
        $employee->save();

        return response()->json(
            [
                'status' => 'Éxito',
                'results' => 'Clave configurada correctamente'
            ],
            200
        );
    }

    /**
     * Verificar passcode de admin
     */
    public function getAdminAuthorization(Request $request)
    {
        $employee = $this->authEmployee;

        if ($request['passcode'] == null
        || strlen($request['passcode']) == 0) {
            return response()->json(
                [
                    'status' => 'Error',
                    'results' => 'La clave no puede estar vacía'
                ],
                409
            );
        }

        $authorization = Employee::with('user.role')
                            ->where('store_id', $employee->store->id)
                            ->where('type_employee', Employee::ADMIN_STORE)
                            ->first();

        if (!$authorization) {
            return response()->json([
                'status' => 'Exito',
                'results' => [
                    'autorizado' => false,
                ]
            ], 200);
        }

        $user = $authorization->user;

        if ($user->role->name !== Role::ADMIN_STORE) {
            return response()->json([
                'status' => 'Exito',
                'results' => [
                    'autorizado' => false,
                ]
            ], 200);
        }

        $pin = isset($data['passcode']) ? $data['passcode'] : null;

        return response()->json([
            'status' => 'Exito',
            'results' => [
                'autorizado' => $employee->passcode === $pin ? true : false,
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
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
