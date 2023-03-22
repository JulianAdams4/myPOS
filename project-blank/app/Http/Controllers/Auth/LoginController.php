<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Employee;
use App\Store;
use App\AdminStore;
use App\FcmToken;
use Auth;
use App\Traits\ValidateToken;
use App\Jobs\DarkKitchen\CheckMenuSchedules;
use Log;

class LoginController extends Controller
{
    use ValidateToken;
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // $this->middleware('guest')->except('logout');
        // $this->middleware('verified');
    }

    protected function validateRequest(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:6'],
            ],
            [
                'email.required' => 'El correo es obligatorio.',
                'email.email' => 'Correo electrónico invalido.',
                'password.required' => 'La contraseña es obligatoria.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'status' => 'Algunos campos no son válidos.',
                    'results' => $validator->errors(),
                ],
                400
            );
        }
    }

    /*
    loginStore
    Realiza el login del admin-store si es que las credenciales son validas,
    se envia el api_token para setearlo en local storage del front
    */
    public function loginStore(Request $request)
    {
        $credentials = [
            'email' => $request['email'],
            'password' => $request['password'],
        ];
        $user = AdminStore::with('store.configs')->where('email', $request->email)->first();
        if ($user) {
            $authValid = Auth::guard('store')->attempt($credentials);
            if ($authValid) {
                if (is_null($user->api_token)) {
                    $token = $user->createToken('project-blank', ['admin'])->accessToken;
                    $user->api_token = $token;
                    $user->save();
                }
                return $user->active ? response()->json(
                    [
                        'status' => 'Bienvenido a myPOS!',
                        'results' => [
                            'email' => $user->email,
                            'id' => $user->id,
                            'api_token' => $user->api_token,
                            'full_name' => $user->name,
                            'store' => $user->store,
                        ]
                    ],
                    200
                ) :
                    response()->json(
                        [
                            'status' => 'Tu cuenta no ha sido activada. Por favor revisa tu correo o comunícate con uno de nuestros agentes.',
                        ],
                        401
                    );
            } else {
                return response()->json(
                    [
                        'status' => 'Credenciales incorrectas.',
                    ],
                    401
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'Usuario no se encuentra registrado.',
                ],
                404
            );
        }
    }

    /**
     * Login API
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function loginAPI(Request $request)
    {
        $input = $request->all();
        $validator = Validator::make(
            $input,
            [
                'email' => 'required|email',
                'password' => 'required',
            ]
        );
        if ($validator->fails()) {
            return response()->json(
                [
                    'status' => $validator->errors(),
                    'results' => null
                ],
                417
            );
        }

        $credentials = $request->only(['email', 'password']);
        if (Auth::guard('employee')->attempt($credentials)) {
            $user = Auth::guard('employee')->user()->makeVisible('access_token');
            $access_token_obj = $user->createToken('project-blank', ['employee']);
            $access_token = $access_token_obj->accessToken;
            $user->access_token = $access_token;
            $user->token_expire_at = $access_token_obj->token->expires_at;
            $user->save();
            if ($request->fcm_token && $request->platform) {
                FcmToken::where('token', $request->fcm_token)->delete();
                $fcmToken = new FcmToken();
                $fcmToken->employee_id = $user->id;
                $fcmToken->platform = $request->platform;
                $fcmToken->token = $request->fcm_token;
                $fcmToken->save();
                $user->id = $user->user_id;
            }
            $store = Store::where('id', $user->store_id)
                ->with('company.billingInformation')
                ->first();
            return response()->json(
                [
                    'status' => 'Success!',
                    'results' => $user,
                    'order_online' => $store->order_app_sync,
                    'printers' => $store->printers,
                    'button_bill_options' => $store->button_bill_prints,
                    'store_configs' => $store->configs,
                    'store' => $store
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'Unauthorized',
                    'results' => null
                ],
                401
            );
        }
    }

    public function loginToken(Request $request)
    {
        $input = $request->all();
        $validator = Validator::make(
            $input,
            [
                'email' => 'required|email',
                'password' => 'required',
            ]
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'status' => $validator->errors(),
                    'results' => null
                ],
                417
            );
        }

        $credentials = $request->only(['email', 'password']);
        if (Auth::guard('web')->attempt($credentials)) {
            $user = Auth::guard('web')->user();
            $access_token_obj = $user->createToken('project-blank', ['admin']);
            $access_token = $access_token_obj->accessToken;
            $user->access_token = $access_token;
            $employee = Employee::where('user_id', $user->id)->first();

            if (!$employee) {
                return response()->json(
                    [
                        'status' => 'Unauthorized',
                        'results' => null
                    ],
                    401
                );
            }
            $user->load('role');
            return response()->json(
                [
                    'status' => 'Bienvenido a myPOS!',
                    'results' => [
                        'email' => $user->email,
                        'api_token' => $user->access_token,
                        'store' => $employee->store,
                    ]
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'Unauthorized',
                    'results' => null
                ],
                401
            );
        }
    }

    /**
     * Login API
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function loginUser(Request $request)
    {
        $input = $request->all();
        $validator = Validator::make(
            $input,
            [
                'email' => 'required|email',
                'password' => 'required',
            ]
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'status' => $validator->errors(),
                    'results' => null
                ],
                417
            );
        }

        $credentials = $request->only(['email', 'password']);
        if (Auth::guard('web')->attempt($credentials)) {
            $user = Auth::guard('web')->user();
            if ($user->isEmployee()) {
                $access_token_obj = $user->createToken('project-blank', ['employee']);
                $access_token = $access_token_obj->accessToken;
                $user->access_token = $access_token;
                $user->api_token = $access_token;
                $employee = Employee::with('store.company.billingInformation')
                    ->where('user_id', $user->id)->first()
                    ->makeVisible('access_token');
                $employee->access_token = $access_token;
                $employee->token_expire_at =  $access_token_obj->token->expires_at;
                if ($request->fcm_token && $request->platform) {
                    FcmToken::where('token', $request->fcm_token)->delete();
                    $fcmToken = new FcmToken();
                    $fcmToken->employee_id = $employee->id;
                    $fcmToken->platform = $request->platform;
                    $fcmToken->token = $request->fcm_token;
                    $fcmToken->save();
                }
                $employee->api_token = $access_token;
                if (!$employee) {
                    return response()->json(
                        [
                            'status' => 'Unauthorized',
                            'results' => null
                        ],
                        401
                    );
                }

                $store = $employee->store->load('configs');

                $employee->load('user.role');
                $employee->role = $employee->user->role->name;
                $employee->permissions = $user->permissions()->pluck('identifier')->toArray();
                return response()->json(
                    [
                        'status' => 'Success!',
                        'results' => $employee,
                        'order_online' => $store->order_app_sync,
                        'printers' => $store->printers,
                        'button_bill_options' => $store->button_bill_prints,
                        'store_configs' => $store->configs,
                        'store' => $store,
                        'is_employe' => true,
                        'permissions' => $employee->user->permissions()->pluck('identifier')->toArray()
                    ],
                    200
                );
            } elseif ($user->isAdminStore()  || $user->isAdminFranchise()) {
                $access_token_obj = $user->createToken('project-blank', ['admin']);
                $access_token = $access_token_obj->accessToken;
                $user->access_token = $access_token;
                $employee = Employee::with(['store.company.billingInformation', 'store.hubs'])->where('user_id', $user->id)->first();
                $store = $employee->store->load('configs');
                if (!$employee) {
                    return response()->json(
                        [
                            'status' => 'Unauthorized',
                            'results' => null
                        ],
                        401
                    );
                }
                $user->load('role');
                return $user->active ? response()->json(
                    [
                        'status' => 'Bienvenido a myPOS!',
                        'results' => [
                            'email' => $user->email,
                            'id' => $employee->id,
                            'api_token' => $user->access_token,
                            'full_name' => $employee->name,
                            'store' => $employee->store,
                            'role' => $user->role->name,
                            'permissions' => $user->permissions()->pluck('identifier')->toArray(),
                            'hubs' => $employee->store->hubs,
                            'store_configs' => $store->configs
                        ]
                    ],
                    200
                ) :
                    response()->json(
                        [
                            'status' => 'Tu cuenta no ha sido activada. Por favor revisa tu correo o comunícate con uno de nuestros agentes.',
                        ],
                        401
                    );
            } elseif ($user->isAdmin()) {
                $access_token_obj = $user->createToken('project-blank', ['admin']);
                $access_token = $access_token_obj->accessToken;
                $user->access_token = $access_token;
                $user->load('role');
                return $user->active ? response()->json(
                    [
                        'status' => 'Bienvenido a myPOS!',
                        'results' => [
                            'email' => $user->email,
                            'id' => $user->id,
                            'api_token' => $user->access_token,
                            'full_name' => $user->name,
                            'role' => $user->role->name,
                            'permissions' => $user->permissions()->pluck('identifier')->toArray(),
                            'hub' => $user->hub
                        ]
                    ],
                    200
                ) :
                    response()->json(
                        [
                            'status' => 'Tu cuenta no ha sido activada. Por favor revisa tu correo o comunícate con uno de nuestros agentes.',
                        ],
                        401
                    );
            } elseif ($user->isEmployeePlaza()) {
                $access_token_obj = $user->createToken('project-blank', ['employee']);
                $access_token = $access_token_obj->accessToken;
                $user->access_token = $access_token;
                $user->api_token = $access_token;

                if ($user->hub == null || $user->hub->stores == null) {
                    return response()->json(
                        [
                            'status' => 'Unauthorized',
                            'results' => null
                        ],
                        401
                    );
                }

                foreach ($user->hub->stores as $store) {
                    $store->load('configs');

                    $storeEmployee = Employee::select('id')
                        ->where('store_id', $store->id)
                        ->first();

                    if ($storeEmployee != null) {
                        $store->employee_id = $storeEmployee->id;
                    }
                }

                return response()->json(
                    [
                        'status' => 'Success!',
                        'results' => $user->hub->stores,
                        'is_employe' => true,
                        'email' => $user->email,
                        'id' => $user->id,
                        'api_token' => $user->access_token,
                        'full_name' => $user->name,
                        'role' => $user->role->name,
                        'hub' => $user->hub
                    ],
                    200
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'Unauthorized',
                    'results' => null
                ],
                401
            );
        }
    }

    // Antigua no tomar en cuenta
    public function loginApp(Request $request)
    {
        $employee = Employee::where('email', $request->email)->first();

        if (!$employee) {
            return response()->json(
                [
                    'status' => 'No se encontró este empleado.',
                    'results' => null,
                ],
                404
            );
        }

        $store = Store::find($employee->store_id);
        if (!$store) {
            return response()->json(
                [
                    'status' => 'No se encontró esta tienda.',
                    'results' => null,
                ],
                404
            );
        }

        if ($employee->store_id != $store->id) {
            return response()->json(
                [
                    'status' => 'El empleado no pertenece a esta tienda.',
                    'results' => null,
                ],
                404
            );
        }

        if (Hash::check($request->password, $employee->password)) {
            return response()->json(
                [
                    'status' => 'Exito!',
                    'results' => $employee,
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'Creedenciales incorrectas.',
                    'results' => null,
                ],
                401
            );
        }
    }
    public function testJob(Request $request)
    {
        CheckMenuSchedules::dispatch();
        return response()->json(
            [
                'status' => 'test'
            ],
            200
        );
    }
}
