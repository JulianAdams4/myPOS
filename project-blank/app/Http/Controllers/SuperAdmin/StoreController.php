<?php

namespace App\Http\Controllers\SuperAdmin;

// Libraries
use Auth;
use App\Card;
use App\City;
use App\Role;
use App\Spot;
use App\User;

// Models
use App\Store;
use App\Company;
use App\Country;
use App\Employee;
use App\StoreTax;
use App\CardStore;
use Carbon\Carbon;
use App\CompanyTax;
use App\StoreConfig;
use App\StorePrinter;
use App\Subscription;
use App\StoreLocations;
use App\Traits\AuthTrait;
use App\Traits\LocaleHelper;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\StripeCustomerCompany;

// Helpers
use App\Traits\StoreConfigHelper;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

// Cache
use Illuminate\Support\Facades\Cache;
// Controllers
use App\Http\Controllers\StripeCompanyController;

class StoreController extends Controller
{
    use LoggingHelper;
    use AuthTrait;
    use LocaleHelper;
    use StoreConfigHelper;
    public $authUser;
    public $authStore;
    public $authEmployee;
    public $channel;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
        $this->channel = "#laravel_logs";
    }

    public function getStoresOfCompany($companyId)
    {
        $stores = Store::select(
            'id',
            'company_id',
            'name',
            'phone',
            'contact',
            'issuance_point',
            'code',
            'address',
            'country_code',
            'bill_sequence',
            'city_id',
            'email',
            'virtual_of'
        )
            ->where('company_id', $companyId)
            ->with(['subscriptions.subscriptionPlan'])
            ->get();

        foreach ($stores as $store) {
            $store->append('country_id');
        }

        return response()->json($stores);
    }

    public function getCountryCitiesData()
    {
        $countries = Country::select(
            'id',
            'name'
        )
            ->with([
                'cities' => function ($cities) {
                    $cities->select(
                        'id',
                        'country_id',
                        'name'
                    );
                }
            ])
            ->get();

        return response()->json($countries);
    }

    public function getEmployees($storeId)
    {
        $employees = Employee::whereHas('user', function ($user) {
            $user->where('role_id', 3)->where('active', 1);
        })
            ->where('store_id', $storeId)->get();
        return response()->json($employees);
    }

    public function getEmployeesPaginate(Request $request)
    {
        $store = $this->authStore;
        $storeID = $store->id;
        $pageSize = $request->pageSize;
        $page = $request->page;

        $query = Employee::whereHas('user', function ($user) {
            $user->whereIn('role_id', [2, 3])->where('active', 1);
        })->where('store_id', $storeID);

        $employeesCount = $query->count();

        $employees = $query
            ->with('user')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->get();

        return response()->json([
            'employees' => $employees,
            'employees_count' => $employeesCount
        ]);
    }

    public function getPrinterActions()
    {
        return response()->json([
            [
                "name" => "Imprimir factura",
                "value" => 1
            ],
            [
                "name" => "Imprimir comanda",
                "value" => 2
            ],
            [
                "name" => "Imprimir precuenta",
                "value" => 3
            ],
            [
                "name" => "Imprimir cierre de caja",
                "value" => 4
            ],
            [
                "name" => "Imprimir Corte X/Z",
                "value" => 6
            ]
        ]);
    }

    public function getLocations($storeId)
    {
        $locations = StoreLocations::where('store_id', $storeId)
            ->orderBy('name')
            ->get();
        return response()->json($locations);
    }

    public function createLocation(Request $request, $storeId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'El nombre es obligatorio!',
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        $storeLocationExist = StoreLocations::where('store_id', $storeId)
            ->where('name', $data["name"])
            ->first();
        if ($storeLocationExist != null) {
            return response()->json([
                'status' => 'Este nombre no está disponible!',
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $storeId) {
                    $location = new StoreLocations();
                    $location->name = $data["name"];
                    $location->store_id = $storeId;
                    $location->save();
                    return response()->json([
                        "status" => "Ubicación de tienda creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR UBICACION TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR UBICACION TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la ubicación de la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function updateLocation(Request $request, $storeId, $locationId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'El nombre es obligatorio!',
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        $anotherStoreLocationExist = StoreLocations::where('store_id', $storeId)
            ->where('id', '!=', $locationId)
            ->where('name', $data["name"])
            ->first();
        if ($anotherStoreLocationExist != null) {
            return response()->json([
                'status' => 'Este nombre no está disponible!',
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $locationId) {
                    $location = StoreLocations::find($locationId);
                    $location->name = $data["name"];
                    $location->save();
                    return response()->json([
                        "status" => "Ubicación de tienda actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR UBICACION TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR UBICACION TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la ubicación de la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function deleteLocation($storeId, $locationId)
    {
        $user = $this->authUser;

        try {
            $operationJSON = DB::transaction(
                function () use ($locationId) {
                    $location = StoreLocations::find($locationId);
                    $location->delete();
                    return response()->json([
                        "status" => "Ubicación de tienda eliminada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ELIMINAR UBICACION TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $locationId
            );
            $slackMessage = "StoreController: ERROR ELIMINAR UBICACION TIENDA, userId: " . $user->id .
                "Provocado por: " . $locationId;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar la ubicación de la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function createEmployee(Request $request, $storeId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "email" => "required|email|unique:employees,email",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $storeId) {
                    $typeEmployee = isset($data['type_employee'])
                        ? $data['type_employee']
                        : Employee::WAITER;

                    $permissionName = 'employee';

                    if ($typeEmployee == Employee::ADMIN_STORE) {
                        $permissionName = 'admin_store';
                    }

                    $employeeRole = Role::with('permissions')->where('name', $permissionName)->first();
                    if (!$employeeRole) {
                        return response()->json([
                            'status' => 'No existe el rol de empleado.',
                            'results' => null,
                        ], 404);
                    }

                    $userEmployee = new User();
                    $userEmployee->name = $data["name"];
                    $userEmployee->email = $data["email"];
                    $password = isset($data['password']) && $data["password"] != "" ? $data['password'] : 123456;
                    $userEmployee->password = bcrypt($password);
                    $userEmployee->active = 1;
                    $userEmployee->api_token = str_random(60);
                    $userEmployee->role_id = $employeeRole->id;
                    $userEmployee->ci = isset($data['ci']) ? $data['ci'] : null;
                    $userEmployee->save();

                    // Asigna todos los persmisos de empleado al nuevo usuario.
                    $userEmployee->permissions()->sync($employeeRole->permissions);

                    $employee = new Employee();
                    $employee->store_id = $storeId;
                    $employee->name = $data["name"];
                    $employee->email = $data["email"];
                    $employee->password = bcrypt($password);
                    $employee->location_id = isset($data['location_id']) ? $data['location_id'] : null;
                    $employee->user_id = $userEmployee->id;
                    $employee->type_employee = isset($data['type_employee'])
                        ? $data['type_employee']
                        : Employee::WAITER;
                    $employee->plate = isset($data['plate']) ? $data['plate'] : null;
                    $employee->pin_code = isset($data['passcode']) ? $data['passcode'] : null;
                    $employee->phone_number = isset($data['phone_number']) ? $data['phone_number'] : null;
                    $employee->save();

                    return response()->json([
                        "status" => "Usuario empleado creado con éxito!",
                        "results" => $userEmployee->id,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR USUARIO EMPLEADO, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR USUARIO EMPLEADO, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear el usuario empleado',
                'results' => null,
            ], 409);
        }
    }

    public function updateEmployee(Request $request, $storeId, $employeeId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "email" => "required|email",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $employeeId) {
                    $typeEmployee = isset($data['type_employee'])
                        ? $data['type_employee']
                        : Employee::WAITER;

                    $permissionName = 'employee';

                    if ($typeEmployee == Employee::ADMIN_STORE) {
                        $permissionName = 'admin_store';
                    }

                    $employeeRole = Role::with('permissions')->where('name', $permissionName)->first();
                    if (!$employeeRole) {
                        return response()->json([
                            'status' => 'No existe el rol de empleado.',
                            'results' => null,
                        ], 404);
                    }

                    $employee = Employee::find($employeeId);
                    $employee->name = $data["name"];
                    $employee->email = $data["email"];
                    if (isset($data["password"]) && $data["password"] != "") {
                        $employee->password = bcrypt($data["password"]);
                    }
                    $employee->location_id = isset($data['location_id']) ? $data['location_id'] : null;
                    $employee->type_employee = isset($data['type_employee']) ? $data['type_employee'] : 3;
                    $employee->pin_code = isset($data['passcode']) ? $data['passcode'] : null;
                    $employee->plate = isset($data['plate']) ? $data['plate'] : null;
                    $employee->phone_number = isset($data['phone_number']) ? $data['phone_number'] : null;
                    $employee->type_employee = $typeEmployee;
                    $employee->save();

                    $user = User::find($employee->user_id);
                    $user->name = $data["name"];
                    $user->email = $data["email"];
                    if (isset($data["password"]) && $data["password"] != "") {
                        $user->password = bcrypt($data["password"]);
                    }
                    $user->ci = isset($data['ci']) ? $data['ci'] : null;
                    $user->role_id = $employeeRole->id;
                    $user->save();

                    // Asigna todos los persmisos de empleado al nuevo usuario.
                    $user->permissions()->sync($employeeRole->permissions);

                    return response()->json([
                        "status" => "Usuario empleado actualizado con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR USUARIO EMPLEADO, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR USUARIO EMPLEADO, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar el usuario empleado',
                'results' => null,
            ], 409);
        }
    }

    public function deleteEmployee($storeId, $employeeId)
    {
        $user = $this->authUser;

        try {
            $operationJSON = DB::transaction(
                function () use ($employeeId) {
                    $employee = Employee::find($employeeId);
                    $user_id = $employee->user_id;
                    $employee->delete();
                    $user = User::find($user_id);

                    // soft delete user if no more employee relations exist
                    if (count($user->employees) == 0) {
                        $user->delete();
                    }

                    return response()->json([
                        "status" => "Usuario empleado eliminado con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ELIMINAR USUARIO EMPLEADO, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $employeeId
            );
            $slackMessage = "StoreController: ERROR ELIMINAR USUARIO EMPLEADO, userId: " . $user->id .
                "Provocado por: " . $employeeId;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar el usuario empleado',
                'results' => null,
            ], 409);
        }
    }

    public function getPrinters($storeId)
    {
        $printers = StorePrinter::where('store_id', $storeId)->get();
        foreach ($printers as $printer) {
            $printer->append('action_name');
        }
        return response()->json($printers);
    }

    public function createPrinter(Request $request, $storeId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "model" => "required|string",
            "number_model" => "required",
            "actions" => "required|integer",
            "interface" => "required|string",
            "connector" => "required",
            "store_locations_id" => "nullable|integer"
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $storeId) {
                    $storePrinter = new StorePrinter();
                    $storePrinter->store_id = $storeId;
                    $storePrinter->name = $data["name"];
                    $storePrinter->model = $data["model"];
                    $storePrinter->number_model = $data["number_model"];
                    $storePrinter->actions = $data["actions"];
                    $storePrinter->interface = $data["interface"];
                    $storePrinter->connector = is_numeric($data["connector"]) ? $data["connector"] : 0;
                    $storePrinter->store_locations_id =
                        isset($data["store_locations_id"]) ? $data["store_locations_id"] : null;
                    $storePrinter->save();

                    return response()->json([
                        "status" => "Impresora creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR IMPRESORA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR IMPRESORA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la impresora',
                'results' => null,
            ], 409);
        }
    }

    public function updatePrinter(Request $request, $storeId, $printerId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "model" => "required|string",
            "number_model" => "required",
            "actions" => "required|integer",
            "interface" => "required|string",
            "connector" => "required",
            "store_locations_id" => "nullable|integer"
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $printerId) {
                    $storePrinter = StorePrinter::find($printerId);
                    $storePrinter->name = $data["name"];
                    $storePrinter->model = $data["model"];
                    $storePrinter->number_model = $data["number_model"];
                    $storePrinter->actions = $data["actions"];
                    $storePrinter->interface = $data["interface"];
                    $storePrinter->connector = is_numeric($data["connector"]) ? $data["connector"] : 0;
                    $storePrinter->store_locations_id =
                        isset($data["store_locations_id"]) ? $data["store_locations_id"] : null;
                    $storePrinter->save();

                    return response()->json([
                        "status" => "Impresora actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR IMPRESORA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR IMPRESORA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la impresora',
                'results' => null,
            ], 409);
        }
    }

    public function deletePrinter(Request $request, $storeId, $printerId)
    {
        $user = $this->authUser;

        try {
            $operationJSON = DB::transaction(
                function () use ($printerId) {
                    $storePrinter = StorePrinter::find($printerId);
                    $storePrinter->delete();

                    return response()->json([
                        "status" => "Impresora eliminada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ELIMINAR IMPRESORA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ELIMINAR IMPRESORA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar la impresora',
                'results' => null,
            ], 409);
        }
    }

    public function getSpots(Request $request, $storeId)
    {
        $storeSpots = Spot::where('store_id', $storeId)
            ->where('origin', '!=', 10)
            ->where('origin', '!=', 11)
            ->get();
        foreach ($storeSpots as $spot) {
            $spot->append('name_integration');
        }
        return response()->json($storeSpots);
    }

    public function getSpotTypes(Request $request)
    {
        $types = Spot::getConstants();
        $typesData = [];
        foreach ($types as $key => $value) {
            if (
                $key != "ORIGIN_MYPOS_KIOSK" && $key != "ORIGIN_MYPOS_KIOSK_TMP"
                && $value != 0
            ) {
                $data = [
                    "name" => Spot::getNameIntegrationByOrigin($value),
                    "value" => $value
                ];
                array_push($typesData, $data);
            }
        }
        return response()->json($typesData);
    }

    public function createSpot(Request $request, $storeId)
    {
        $user = $this->authUser;
        // TO DO: Validate location_id
        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "origin" => "required|integer",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $storeId) {
                    $locationId = isset($data["location_id"]) ? $data["location_id"] : null;
                    if (!$locationId) {
                        $defaultLocation = StoreLocations::firstOrCreate(
                            [
                                'name' => "Piso 1",
                                'store_id' => $storeId
                            ],
                            ['priority' => 1]
                        );
                        $locationId = $defaultLocation->id;
                    }
                    $spot = new Spot();
                    $spot->store_id = $storeId;
                    $spot->name = $data["name"];
                    $spot->origin = $data["origin"];
                    $spot->location_id = $locationId;
                    $spot->save();
                    return response()->json([
                        "status" => "Mesa creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR MESA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR MESA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la mesa',
                'results' => null,
            ], 409);
        }
    }

    public function updateSpot(Request $request, $storeId, $spotId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "origin" => "required|integer",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $spotId) {
                    $spot = Spot::find($spotId);
                    $spot->name = $data["name"];
                    $spot->origin = $data["origin"];
                    $spot->save();

                    return response()->json([
                        "status" => "Mesa actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR MESA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR MESA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la mesa',
                'results' => null,
            ], 409);
        }
    }

    public function deleteSpot($storeId, $spotId)
    {
        $user = $this->authUser;

        try {
            $operationJSON = DB::transaction(
                function () use ($spotId) {
                    $spot = Spot::find($spotId);
                    $spot->delete();
                    return response()->json([
                        "status" => "Mesa eliminada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ELIMINAR MESA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $spotId
            );
            $slackMessage = "StoreController: ERROR ELIMINAR MESA, userId: " . $user->id .
                "Provocado por: " . $spotId;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar la mesa',
                'results' => null,
            ], 409);
        }
    }

    public function getTaxes($storeId)
    {
        $storeTaxes = StoreTax::where('store_id', $storeId)->get();
        return response()->json($storeTaxes);
    }

    public function createTax(Request $request, $storeId)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "percentage" => "required|numeric",
            "type" => "required|string",
            "enabled" => "required|integer",
        ]);

        if ($validator->fails()) {
            return response()
                ->json([
                    "status" => false,
                    "errors" => $validator->errors(),
                ]);
        }

        $tax = new StoreTax();
        $tax->store_id = $storeId;
        $tax->name = $request->get('name');
        $tax->percentage = $request->get('percentage');
        $tax->type = $request->get('type');
        $tax->enabled = $request->get('enabled');
        $tax->is_main = $request->get('is_main');
        if ($tax->save()) {
            return response()
                ->json([
                    "status" => true,
                    "errors" => [],
                    "message" => "The tax has been created correctly",
                ]);
        }
    }


    public function updateTax(Request $request, $storeId, $taxId)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "percentage" => "required|numeric",
            "type" => "required|string",
            "enabled" => "required|integer",
        ]);

        if ($validator->fails()) {
            return response()
                ->json([
                    "status" => false,
                    "errors" => $validator->errors(),
                ]);
        }

        $tax = StoreTax::find($taxId);
        $tax->name = $request->get('name');
        $tax->percentage = $request->get('percentage');
        $tax->type = $request->get('type');
        $tax->enabled = $request->get('enabled');
        $tax->is_main = $request->get('is_main');
        if ($tax->save()) {
            return response()
                ->json([
                    "status" => true,
                    "errors" => [],
                    "message" => "The tax has been updated correctly",
                ]);
        }
    }

    public function deleteTax($storeId, $taxId)
    {
        $tax = StoreTax::find($taxId);
        $tax->delete();
        return response()
            ->json([
                "status" => true,
                "errors" => [],
                "message" => "The tax has been deleted correctly",
            ]);
    }

    public function getAdminStores($storeId)
    {
        $adminStores = Employee::select(
            'id',
            'store_id',
            'name',
            'email'
        )
            ->whereHas('user', function ($user) {
                $user->where('role_id', 2)->where('active', 1);
            })
            ->where('store_id', $storeId)
            ->get();
        return response()->json($adminStores);
    }

    public function createAdminStore(Request $request, $storeId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "email" => "required|string|unique:admin_stores,email",
            "password" => "required|string",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $storeId) {
                    $adminStoreRole = Role::with('permissions')->where('name', 'admin_store')->first();
                    if (!$adminStoreRole) {
                        return response()->json([
                            'status' => 'No existe el rol de administrador de tiendas.',
                            'results' => null,
                        ], 404);
                    }

                    $userAdmin = new User();
                    $userAdmin->name = $data["name"];
                    $userAdmin->email = $data["email"];
                    $userAdmin->password = bcrypt($data["password"]);
                    $userAdmin->active = 1;
                    $userAdmin->api_token = "teststore" . $storeId;
                    $userAdmin->activation_token = "teststore" . $storeId;
                    $userAdmin->role_id = $adminStoreRole->id;
                    $userAdmin->save();

                    // Asigna todos los persmisos de administrador de tienda al nuevo usuario.
                    $userAdmin->permissions()->sync($adminStoreRole->permissions);

                    $adminStore = new Employee();
                    $adminStore->store_id = $storeId;
                    $adminStore->name = $data["name"];
                    $adminStore->email = $data["email"];
                    $adminStore->password = bcrypt($data["password"]);
                    $adminStore->type_employee = Employee::ADMIN_STORE;
                    $adminStore->user_id = $userAdmin->id;
                    $adminStore->save();

                    return response()->json([
                        "status" => "Usuario tienda creado con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR USUARIO TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR USUARIO TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear el usuario tienda',
                'results' => null,
            ], 409);
        }
    }

    public function updateAdminStore(Request $request, $storeId, $userId)
    {
        $user = $this->authUser;

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "email" => "required|string",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos o correos no disponibles",
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe!',
                'results' => null
            ], 409);
        }

        $data = $request->all();

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $userId) {
                    $adminStore = Employee::find($userId);
                    $adminStore->name = $data["name"];
                    $adminStore->email = $data["email"];
                    if (isset($data["password"]) && $data["password"] != "") {
                        $adminStore->password = bcrypt($data["password"]);
                    }
                    $adminStore->save();

                    $user = User::find($adminStore->user_id);
                    $user->name = $data["name"];
                    $user->email = $data["email"];
                    if (isset($data["password"]) && $data["password"] != "") {
                        $user->password = bcrypt($data["password"]);
                    }
                    $user->save();
                    return response()->json([
                        "status" => "Usuario tienda actualizado con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR USUARIO TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR USUARIO TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar el usuario tienda',
                'results' => null,
            ], 409);
        }
    }

    public function deleteAdminStore($storeId, $userId)
    {
        $user = $this->authUser;

        try {
            $operationJSON = DB::transaction(
                function () use ($userId) {
                    $admin = Employee::find($userId);
                    $user = User::find($admin->user_id);
                    $admin->delete();
                    $user->delete();
                    return response()->json([
                        "status" => "Usuario tienda eliminado con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ELIMINAR USUARIO TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $userId
            );
            $slackMessage = "StoreController: ERROR ELIMINAR USUARIO TIENDA, userId: " . $user->id .
                "Provocado por: " . $userId;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar el usuario tienda',
                'results' => null,
            ], 409);
        }
    }

    public function getCities()
    {
        return response()->json(City::all());
    }

    public function createStore(Request $request, $companyId)
    {
        $user = $this->authUser;
        $company = Company::find($companyId);

        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            "virtual_of" => "nullable|integer",
            "name" => "required|string",
            "country_id" => "required|integer",
            "city_id" => "required|integer",
            "phone" => "nullable|string",
            "contact" => "nullable|string",
            "email" => "nullable|email",
            "address" => "nullable|string",
            "bill_sequence" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $data = $request->all();
        $country = Country::where('id', $data['country_id'])->first();
        $configByCountry = $this->getDataConfigByCountryCode(strtoupper($country->code));
        
       
        try {
            $operationJSON = DB::transaction(
                function () use ($data, $companyId) {
                    $store = new Store();
                    $store->virtual_of = isset($data['virtual_of']) ? $data['virtual_of'] : null;
                    $store->name = $data['name'];
                    $store->phone = isset($data['phone']) ? $data['phone'] : null;
                    $store->contact = isset($data['contact']) ? $data['contact'] : null;
                    $country = Country::where('id', $data['country_id'])->first();
                    $currency = $this->countryToCurrency(strtoupper($country->code));
                    $store->currency = $currency;
                    $store->issuance_point = isset($data['issuance_point']) ? $data['issuance_point'] : null;
                    $store->code = isset($data['code']) ? $data['code'] : null;
                    $store->address = isset($data['address']) ? $data['address'] : null;
                    $store->country_code = $country->code;
                    $store->bill_sequence = $data['bill_sequence'];
                    $store->order_app_sync = 1;
                    $store->button_bill_prints = 1;
                    $store->city_id = $data['city_id'];
                    $store->max_sequence = 1;
                    $store->email = isset($data['email']) ? $data['email'] : null;
                    $store->company_id = $companyId;
                    $store->save();

                    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

                    $dataTax = $this->countryToTaxValue(strtoupper($country->code));

                    // Creando el CompanyTax por si no existe
                    $companyTax = CompanyTax::where('company_id', $companyId)->first();

                    if ($companyTax == null) {
                        $newCompanyTax = new CompanyTax();
                        $newCompanyTax->company_id = $companyId;
                        $newCompanyTax->name = $dataTax["name"];
                        $newCompanyTax->percentage = $dataTax["value"];
                        $newCompanyTax->type = "included";
                        $newCompanyTax->enabled = 1;
                        $newCompanyTax->save();
                    }

                    // Creando el StoreTax
                    $storeTax = new StoreTax();
                    $storeTax->store_id = $store->id;
                    $storeTax->name = $dataTax["name"];
                    $storeTax->percentage = $dataTax["value"];
                    $storeTax->type = "included";
                    $storeTax->enabled = 1;
                    $storeTax->is_main = 1;
                    $storeTax->save();

                    // Creando el StoreConfig
                    $storeConfig = new StoreConfig();
                    $storeConfig->store_id = $store->id;
                    $storeConfig->show_taxes = 1;
                    $storeConfig->document_lengths = "";
                    $storeConfig->uses_print_service = 1;
                    $storeConfig->employee_digital_comanda = 0;
                    $storeConfig->show_invoice_specs = 0;
                    $storeConfig->alternate_bill_sequence = 0;
                    $storeConfig->show_search_name_comanda = 0;
                    $storeConfig->is_dark_kitchen = 0;
                    $storeConfig->auto_open_close_cashier = 0;
                    $storeConfig->allow_modify_order_payment = 0;
                    $storeConfig->currency_symbol = "$";

                    $configByCountry = $this->getDataConfigByCountryCode(strtoupper($country->code));
                    $storeConfig->comanda = $configByCountry["comanda"];
                    $storeConfig->precuenta = $configByCountry["precuenta"];
                    $storeConfig->factura = $configByCountry["factura"];
                    $storeConfig->cierre = $configByCountry["cierre"];
                    $storeConfig->common_bills = $configByCountry["common_bills"];
                    $storeConfig->time_zone = $configByCountry["timezone"];
                    $storeConfig->xz_format = $configByCountry["xz_format"];
                    $storeConfig->credit_format = $configByCountry["credit_format"];
                    $storeConfig->store_money_format = $configByCountry["store_money_format"];
                    $storeConfig->save();

                    Cache::forever("store:{$store->id}:configs:timezone", $configByCountry["timezone"]);

                    // Creando el location default
                    $location = new StoreLocations();
                    $location->name = "Piso 1";
                    $location->priority = 1;
                    $location->store_id = $store->id;
                    $location->save();

                    // subscriptions

                    if (isset($data['subscriptions'])) {
                        $subscriptions = $data['subscriptions'];

                        foreach ($subscriptions as $subscription) {
                            if (!isset($subscription['subscription_plan_id'])) { continue; }

                            $billingDate = Carbon::parse($subscription['billing_date'])->startOfDay();
                            $activationDate = Carbon::parse($subscription['activation_date'])->startOfDay();

                            $newSubscription = new Subscription();
                            $newSubscription->store_id = $store->id;
                            $newSubscription->subscription_plan_id = $subscription['subscription_plan_id'];
                            $newSubscription->billing_date = $billingDate;
                            $newSubscription->activation_date = $activationDate;
                            $newSubscription->save();

                            // If is autobilling active, then create the subscription on stripe
                            if ($stripeCompany->is_autobilling_active) StripeCompanyController::syncSubscription($newSubscription);
                        }
                    }

                    return response()->json([
                        "status" => "Tienda creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR CREAR TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR CREAR TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function updateCompanyStore(Request $request, $companyId, $storeId)
    {
        $user = $this->authUser;

        $company = Company::find($companyId);

        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $storeExist = Store::find($storeId);

        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe',
                'results' => null
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            "name" => "required|string",
            "country_id" => "required|integer",
            "city_id" => "required|integer",
            "phone" => "nullable|string",
            "contact" => "nullable|string",
            "email" => "nullable|email",
            "address" => "nullable|string",
            "bill_sequence" => "required",
            "virtual_of" => "nullable|integer",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $data = $request->all();
        try {
            $operationJSON = DB::transaction(
                function () use ($data, $companyId, $storeId) {
                    $store = Store::find($storeId);
                    $store->name = $data['name'];
                    $store->phone = isset($data['phone']) ? $data['phone'] : null;
                    $store->contact = isset($data['contact']) ? $data['contact'] : null;
                    $country = Country::where('id', $data['country_id'])->first();
                    $currency = $this->countryToCurrency(strtoupper($country->code));
                    $store->currency = $currency;
                    $store->issuance_point = isset($data['issuance_point']) ? $data['issuance_point'] : null;
                    $store->code = isset($data['code']) ? $data['code'] : null;
                    $store->address = isset($data['address']) ? $data['address'] : null;
                    $store->country_code = $country->code;
                    $store->bill_sequence = $data['bill_sequence'];
                    $store->order_app_sync = 1;
                    $store->button_bill_prints = 1;
                    $store->city_id = $data['city_id'];
                    $store->max_sequence = 1;
                    $store->email = isset($data['email']) ? $data['email'] : null;
                    $store->company_id = $companyId;
                    $store->virtual_of = isset($data['virtual_of']) ? $data['virtual_of'] : null;
                    $store->save();

                    $stripeCompany = StripeCustomerCompany::where('company_id', $companyId)->first();

                    $dataTax = $this->countryToTaxValue(strtoupper($country->code));

                    // Cambiando el StoreTax si cambió de País
                    $storeTax = StoreTax::where('store_id', $storeId)
                        ->where('is_main', 1)
                        ->where('enabled', 1)
                        ->first();

                    if ($storeTax != null && ($storeTax->name != $dataTax["name"] ||
                        $storeTax->percentage != $dataTax["value"])) {
                        $storeTax->name = $dataTax["name"];
                        $storeTax->percentage = $dataTax["value"];
                        $storeTax->save();
                    }

                    // subscriptions

                    $existingSubscriptions = array();

                    if (isset($data['subscriptions'])) {
                        $subscriptions = $data['subscriptions'];

                        foreach ($subscriptions as $subscription) {
                            $billingDate = isset($subscription['billing_date']) ?  Carbon::parse($subscription['billing_date'])->startOfDay() : null;
                            $activationDate = isset($subscription['activation_date']) ? Carbon::parse($subscription['activation_date'])->startOfDay() : null;

                            $newSubscription = Subscription::updateOrCreate(
                                ['store_id' => $storeId, 'subscription_plan_id' => $subscription['subscription_plan_id']],
                                ['billing_date' => $billingDate, 'activation_date' => $activationDate]
                            );

                            // If is autobilling active, then create the subscription on stripe
                            if ($stripeCompany->is_autobilling_active) StripeCompanyController::syncSubscription($newSubscription);

                            array_push($existingSubscriptions, $newSubscription->id);
                        }
                    }

                    $deletedSubscriptions = Subscription::where('store_id', $store->id)
                        ->whereNotIn('id', $existingSubscriptions);

                    foreach ($deletedSubscriptions->get() as $deletedSubscription) {
                        // If is autobilling active, then remove the subscription on stripe
                        if ($stripeCompany->is_autobilling_active) StripeCompanyController::removeSubscription($deletedSubscription);
                    }

                    $deletedSubscriptions->delete();

                    return response()->json([
                        "status" => "Tienda actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function getCards($storeId)
    {
        $cards = CardStore::where('store_id', $storeId)->get();
        $cards = $cards->map(function ($card) {
            $card['card_name'] = Card::find($card->card_id)->name;
            return $card;
        });

        return response()->json($cards);
    }

    public function getStoreConfig($storeId)
    {
        $storeConfig = StoreConfig::select(
            'id',
            'store_id',
            'show_taxes',
            'eats_store_id',
            'show_invoice_specs',
            'comanda',
            'precuenta',
            'factura',
            'cierre',
            'common_bills',
            'show_search_name_comanda',
            'is_dark_kitchen',
            'auto_open_close_cashier',
            'allow_modify_order_payment',
            'currency_symbol'
        )->where('store_id', $storeId)->first();
        return response()->json($storeConfig);
    }

    public function updateStoreConfig(Request $request, $storeId)
    {
        $user = $this->authUser;

        $storeExist = Store::find($storeId);
        if ($storeExist == null) {
            return response()->json([
                'status' => 'Esta tienda no existe',
                'results' => null
            ], 409);
        }

        $values = $request->all();
        $validator = Validator::make($values, [
            "eats_store_id" => "nullable|string",
            "show_taxes" => "required|integer",
            "show_invoice_specs" => "required|integer",
            "comanda" => "required|json",
            "precuenta" => "required|json",
            "factura" => "required|json",
            "cierre" => "required|json",
            "common_bills" => "required|json",
            "show_search_name_comanda" => "required|integer",
            "is_dark_kitchen" => "required|integer",
            "auto_open_close_cashier" => "required|integer",
            "allow_modify_order_payment" => "required|integer",
            "currency_symbol" => "required|string",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($values, $storeId) {
                    $config = StoreConfig::where('store_id', $storeId)->first();
                    $config->update($values);

                    return response()->json([
                        "status" => "Configuración de la tienda actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "StoreController: ERROR ACTUALIZAR CONFIG TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $values
            );
            $slackMessage = "StoreController: ERROR ACTUALIZAR CONFIG TIENDA, userId: " . $user->id .
                "Provocado por: " . $values;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la configuración de la tienda',
                'results' => null,
            ], 409);
        }
    }
}
