<?php

namespace App\Http\Controllers\Auth;

use App\Company;
use App\Customer;
use App\FirebaseToken;
use App\Http\Controllers\Controller;
use App\User;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Log;
use Socialite;

class SocialAuthController extends Controller
{
    public function redirect($provider)
    {
        Log::info('on redirect provider');
        // if ($provider === "google") {
        //     return Socialite::driver($provider)->scopes(['profile', 'email'])->redirect();
        // }
        return Socialite::driver($provider)->redirect();
    }

    public function callback($provider)
    {
        Log::info('on callback provider');
        $social_user = Socialite::driver($provider)->user();
        Log::info($social_user->getAvatar());
        Log::info($social_user->token);
        $authUser = $this->findOrCreateUser($social_user, $provider);
        Auth::login($authUser, true);
        return redirect()->to('/');
    }

    public function tokenLogin(Request $request, $provider)
    {
        Log::info('on TokenLogin provider');
        $token = $request->input('provider_token', null);
        $firebaseToken = $request->input('firebase_token', null);
        $platform = $request->input('platform', null);
        $identifier = $request->input('identifier', 'tere');
        if ($token) {
            try {
                $social_user = Socialite::driver($provider)->stateless()->userFromToken($token);
                Log::info($social_user->token);
                $authUser = $this->findOrCreateUser($social_user, $provider,
                    $identifier, $firebaseToken, $platform);
                if ($authUser) {
                    $authUser['avatar'] = $social_user->getAvatar();
                    $authUser['token'] = $authUser->createToken($provider)->accessToken;
                    return response()->json([
                        'status' => 'Bienvenido!',
                        'results' => $authUser,
                    ], 200);
                }
            } catch (\Exception $e) {
                Log::info('error social login');
                Log::info($e);
                return response()->json([
                    'status' => 'No se pudo autenticar tu cuenta en ' . $provider . '.',
                    'results' => [],
                ], 409);
            }
        }
        return response()->json([
            'status' => 'No se encontro el usuario en ' . $provider . '.',
            'results' => [],
        ], 404);
    }

    public function findOrCreateUser($social_user, $provider, $identifier, $firebaseToken, $platform)
    {
        Log::info('$social_user');
        $company = Company::where('identifier', $identifier)->first();
        if (!$company) {
            return response()->json([
                'status' => 'No se encontro compañia.',
                'results' => [],
            ], 404);
        }
        $company_id = $company->id;

        $authUser = User::where('email', $social_user->email)->first();
        if ($authUser) {
            $authUser->email_verified_at = Carbon::now();
            $authUser->active = 1;
            $authUser->save();

            $authCustomer = Customer::firstOrNew([
                'user_id' => $authUser->id,
                'provider' => $provider,
                'company_id' => $company_id,
            ]);
            Log::info($social_user->token);
            $authCustomer->provider_token = $social_user->token;
            $authCustomer->active = 1;
			$authCustomer->save();
			Log::info($authCustomer);
            FirebaseToken::addToken($authCustomer, $firebaseToken, $platform);
            return $authUser->load([
                'customers' => function ($q) use ($provider, $company_id) {
                    $q->where('provider', $provider)->where('company_id', $company_id);
                }]);
        }

        $authUser = User::create([
            'name' => $social_user->name,
            'email' => $social_user->email,
            'email_verified_at' => Carbon::now(),
            'active' => 1,
        ]);
        $authCustomer = Customer::firstOrNew([
            'user_id' => $authUser->id,
            'provider' => $provider,
            'provider_token' => $social_user->token,
            'company_id' => $company_id,
            'active' => 1,
		]);
		$authCustomer->save();
		Log::info("PRINT NEW CUSTOMER");
		Log::info($authCustomer);
        FirebaseToken::addToken($authCustomer, $firebaseToken, $platform);
        return $authUser->load([
            'customers' => function ($q) use ($provider, $company_id) {
                $q->where('provider', $provider)->where('company_id', $company_id);
            }]);
    }
}
