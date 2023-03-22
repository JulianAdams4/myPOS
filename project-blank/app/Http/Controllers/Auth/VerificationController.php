<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\VerifiesEmails;
use Illuminate\Http\Request;
use App\Customer;
use App\AdminCompany;
use App\AdminStore;
use Carbon\Carbon;
use Log;

class VerificationController extends Controller
{
	/*
	|--------------------------------------------------------------------------
	| Email Verification Controller
	|--------------------------------------------------------------------------
	|
	| This controller is responsible for handling email verification for any
	| user that recently registered with the application. Emails may also
	| be re-sent if the user didn't receive the original email message.
	|
	*/

	use VerifiesEmails;

	/**
	 * Where to redirect users after verification.
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
		$this->middleware('auth',['except'=>['verifyCustomer','verifyAdminCompany','verifyStoreCompany']]);
		#$this->middleware('signed')->only('verify');
		#$this->middleware('throttle:6,1')->only('verify', 'resend');

		$this->middleware('signed',['only'=>['verify']]);
		$this->middleware('throttle:6,1',['only'=>['verify','resend']]);
	}


	public function verifyCustomer(Request $request, $token){
		Log::info("verify customer");
		Log::info($token);
		$customer = Customer::with('user','company')->where('verification_token',$token)->first();
		Log::info($customer);
		if($customer){
			if ($customer->active) {
				$data = [
					'name'=>$customer->user->name,
				];
				return response()->view('auth.verify.alreadyVerified',$data,200);
			} else {
				$customer->active = true;
				$customer->save();
				$customer->user->active = true;
				$customer->user->email_verified_at = Carbon::now();
				$customer->user->save();
				$data = [
					'name'=>$customer->user->name,
				];
				return response()->view('auth.verify.successVerification',$data,200);
			}
		}
		abort(404);
	}


	public function verifyAdminCompany(Request $request, $token){
		Log::info("verify admin company");
        Log::info($token);
        $admin = AdminCompany::with('company')->where('activation_token',$token)->first();
        Log::info($admin);
        if($admin && !$admin->active){

            $admin->active = true;
			$admin->email_verified_at = Carbon::now();
            $admin->save();
            $data = [
                'name'=>$admin->name,
            ];
            return response()->view('auth.verify.successVerification',$data,200);
        }
        abort(404);
	}


	public function verifyStoreCompany(Request $request, $token){
		Log::info("verify admin store");
        Log::info($token);
        $admin = AdminStore::with('store')->where('activation_token',$token)->first();
        Log::info($admin);
        if($admin && !$admin->active){

            $admin->active = true;
			$admin->email_verified_at = Carbon::now();
            $admin->save();
            $data = [
                'name'=>$admin->name,
            ];
            return response()->view('auth.verify.successVerification',$data,200);
        }
        abort(404);
	}


}
