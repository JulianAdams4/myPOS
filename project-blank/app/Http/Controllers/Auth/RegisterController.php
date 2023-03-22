<?php

namespace App\Http\Controllers\Auth;

use App\User;
use App\AdminStore;
use App\AdminCompany;
use App\Company;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Carbon\Carbon;
use Log;

class RegisterController extends Controller
{
	/*
	|--------------------------------------------------------------------------
	| Register Controller
	|--------------------------------------------------------------------------
	|
	| This controller handles the registration of new users as well as their
	| validation and creation. By default this controller uses a trait to
	| provide this functionality without requiring any additional code.
	|
	*/

	use RegistersUsers;

	/**
	 * Where to redirect users after registration.
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
		$this->middleware('guest');
	}

	/**
	 * Get a validator for an incoming registration request.
	 *
	 * @param  array  $data
	 * @return \Illuminate\Contracts\Validation\Validator
	 */
	protected function validator(array $data)
	{
		return Validator::make($data, [
			'name' => ['required', 'string', 'max:255'],
			'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
			'password' => ['required', 'string', 'min:6', 'confirmed'],
		]);
	}

	/**
	 * Create a new user instance after a valid registration.
	 *
	 * @param  array  $data
	 * @return \App\User
	 * Crea un user tipo admin ya sea para el store o para company
	 */
	protected function create(array $data)
	{
		$identifier = 'tere';
		$company = Company::with('stores')->where('identifier',$identifier)->first();
		$store = $company->stores->first();

		$type = $data['type'];
		switch ($type) {
			case 'company':
				$token = Hash::make($data['email'].Carbon::now()->toDateString());
				$trimmed = trim($token);
				$firstFilter = str_replace("$", str_random(1), $trimmed);
				$secondFilter = str_replace("/", str_random(1), $firstFilter);
				$finalToken = str_replace(".", str_random(1), $secondFilter);
				$user = AdminCompany::create([
					'name' => $data['name'],
					'email' => $data['email'],
					'password' => Hash::make($data['password']),
					'api_token' => Hash::make($data['email']),
					'company_id' => $company->id,
					'activation_token' => $finalToken,
				]);
				break;
			case 'store':
				$token = Hash::make($data['email'].Carbon::now()->toDateString());
				$trimmed = trim($token);
				$firstFilter = str_replace("$", str_random(1), $trimmed);
				$secondFilter = str_replace("/", str_random(1), $firstFilter);
				$finalToken = str_replace(".", str_random(1), $secondFilter);
				$user = AdminStore::create([
					'name' => $data['name'],
					'email' => $data['email'],
					'password' => Hash::make($data['password']),
					'api_token' => Hash::make($data['email']),
					'store_id' => $store->id,
					'activation_token' => $finalToken,
				]);
				break;
			default:
				break;
		}
		return $user;
	}
}
