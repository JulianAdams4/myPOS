<?php

namespace App\Http\Controllers;

use App\Customer;
use App\User;
use App\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Log;

class CustomerController extends Controller
{

	public function __construct()
	{
		//$this->middleware('auth:api')->except(['create','store','index','destroy']);
		//$this->middleware('customer',['only' => ['show', 'edit', 'update']]);
	}

	protected function validateRequest(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'name' => ['required', 'string', 'max:255','filled'],
			'phone' => ['required', 'string', 'filled'],
			'email' => ['required', 'string', 'email', 'max:255'],
			'password' => ['required', 'string', 'min:6'],
		],[
			'email.required' => 'El correo es obligatorio.',
			'email.email' => 'Correo electrónico invalido.',
			'name.required' => 'El nombre es requerido.',
			'phone.required' => 'El teléfono es requerido.',
			'password.required' => 'La contraseña es obligatoria.',
		]);

		if ($validator->fails()) {
			return response()->json([
				'status' => 'Algunos campos no son válidos.',
				'results' => $validator->errors(),
			], 400);
		}
	}

	/**
	 * Display a listing of the resource.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function index()
	{
		error_log('index');
	}

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function create()
	{
		error_log('create');

	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	 */
	public function store(Request $request)
	{
		error_log('store');
		Log::info('store customer');
		$this->validateRequest($request);

		$provider = 'tere';
		$company = Company::where('identifier',$provider)->first();
		if(!$company){
			return response()->json([
						'status' => 'No se encontro compañia.',
						'results' => [],
					], 404);
		}
		$company_id = $company->id;

		$name = $request->name;
		$email = $request->email;
		$password = Hash::make($request->password . $request->email);
		$user = User::where('email',$email)->first();
		if (!$user){
			$user = User::create([
				'name'=>$name,
				'email'=>$email
			]);
		}
		$customer = $user->customers()->where('provider',$provider)->where('company_id',$company_id)->first();
		Log::info('Buscando Customer de usuario registrado');
		Log::info($customer);
		if(!$customer){
			$verificationToken = Hash::make(base64_encode($email));
			$verificationToken = preg_replace('/[^A-Za-z0-9\-]/','',$verificationToken);

			$user->customers()->create([
				'access_token' => $password,
				'provider' => $provider,
				'company_id' => $company_id,
				'verification_token' => $verificationToken,
				'phone' => $request->phone,
			]);
			$user->load([
				'customers'=>function($q)use($provider,$company_id){
					$q->where('provider',$provider)->where('company_id',$company_id);
				}]);
			return response()->json([
				'status' => 'Usuario registrado exitosamente.',
				'results' => $user,
			], 201);
		}
		return response()->json([
			'status' => 'El usuario ya se encuentra registrado.',
			'results' => [],
		], 400);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  \App\Customer  $customer
	 * @return \Illuminate\Http\Response
	 */
	public function show(Customer $customer)
	{
		$customer->load('user');
		if($customer){
			return response()->json([
				'status' => 'Usuario encontrado exitosamente.',
				'results' => $customer,
			],200);
		}
		else{
			return response()->json([
				'status' => 'Usuario no encontrado.',
				'results' => '',
			],400);
		}
	}

	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  \App\Customer  $customer
	 * @return \Illuminate\Http\Response
	 */
	public function edit(Customer $customer)
	{
		error_log('edit');
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  \App\Customer  $customer
	 * @return \Illuminate\Http\Response
	 */
	public function update(Request $request, Customer $customer)
	{
		$customerFinded = Customer::find($customer->id);
		Log::info($customerFinded);
		if($customerFinded){
			$customerUpdated = $customerFinded->update($request->all());
			if($customerUpdated){
				return response()->json([
				'status' => 'Actualización del customer con éxito',
				'results' => $customer
				],200);
			}					
			else{
				return response()->json([
				'status' => 'No se ha podido actualizar el customer',
				'results' => '',
			], 400);
			}
		}
		else{
			return response()->json([
				'status' => 'Customer no encontrado',
				'results' => '',
			], 404);
		}
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  \App\Customer  $customer
	 * @return \Illuminate\Http\Response
	 */
	public function destroy(Customer $customer)
	{
		error_log('destroy');
	}
}
