<?php

namespace App\Http\Controllers\API\V1;

use App\Customer;
use App\User;
use App\Mail\CustomerEmailVerification;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Log;

class CustomerController extends Controller
{
    public function resendCustomerActivationEmail(Request $request) {
        $customer = Customer::whereHas('user', function($user) use ($request) {
            $user->where('email', $request->email);
        })
        ->where('provider', $request->provider)
        ->with('user', 'company')
        ->first();

        if ($customer) {
            if ($customer->verification_token) {
                try {
                    Mail::to($customer->user->email)
                        ->send(new CustomerEmailVerification($customer));
                    return response()->json([
                        'status' => 'Email de activación reenviado'
                    ], 200);
                } catch (\Exception $e) {
                    Log::info($e);
                    return response()->json([
                        'status' => 'No se pudo enviar el email de activación'
                    ], 500);
                }
            } else {
                return response()->json([
                    'status' => 'El customer no tiene token de activación'
                ], 404);
            }
        } else {
            return response()->json([
                'status' => 'El customer no existe'
            ], 404);
        }
    }

    /*
    updateProfile
    Actualiza los datos del un cliente
    Los datos  del cliente se encuentra en user y customer
    */
    public function updateProfile(Request $request)
	{
        Log::info('Update profile');
		Log::info($request);
        $customerFinded = Customer::find($request->customerId);
		if($customerFinded){
            Log::info($customerFinded);
            $userFinded = User::find($customerFinded->user_id);
            if($userFinded){
                $customerUpdated = $customerFinded->update($request->all());
                $userUpdated = $userFinded->update($request->all());
                if($customerUpdated && $userUpdated){
                    return response()->json([
                        'status' => 'Actualización de perfil con éxito',
                        'results' => $userUpdated
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
                    'status' => 'User no encontrado',
                    'results' => '',
			    ], 404);
            }
		}
		else{
			return response()->json([
				'status' => 'Customer no encontrado',
				'results' => '',
			], 404);
		}
	}
}
