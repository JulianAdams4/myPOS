<?php

namespace App\Http\Controllers;

use App\Billing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Log;

class BillingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
      return Billing::orderBy('name')->get();
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

    protected function validateAddBilling(Request $request)
  	{
  		$validator = Validator::make($request->all(), [
        'name' => ['required', 'string','max:255'],
  			'document' => ['required', 'string','max:13'],
        'phone' => ['required', 'string','max:10'],
        'address' => ['required', 'string','max:255'],
        'email' => ['required', 'string', 'email', 'max:255'],
  		],[
  			'name.required' => 'El correo es obligatorio.',
        'document.required' => 'La indentificación es obligatoria.',
        'phone.required' => 'Teléfono es obligatorio.',
        'address.required' => 'Dirección es obligatorio.',
        'email.email' => 'Correo electrónico inválido.',
  			'email.required' => 'Correo electrónico es obligatorio.',
  		]);
  		if ($validator->fails()) {
  			return response()->json([
          'saved' => false,
  				'status' => 'Algunos campos no son válidos.',
  				'results' => $validator->errors(),
  			], 400);
  		}
  	}

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
      $this->validateAddBilling($request);
      Log::info($request);
      $billing = Billing::create($request->all());
      if($billing){
        return response()->json([
          'saved' => true,
          'results' => $billing
        ],200);
      }
      else{
				return response()->json([
						'saved' => false,
						'results' => '',
					], 400);
			}
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Billing  $billing
     * @return \Illuminate\Http\Response
     */
    public function show(Billing $billing)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Billing  $billing
     * @return \Illuminate\Http\Response
     */
    public function edit(Billing $billing)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Billing  $billing
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Billing $billing)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Billing  $billing
     * @return \Illuminate\Http\Response
     */
    public function destroy(Billing $billing)
    {
        //
    }

    public function getFirstBillingUser($customer)
    {
      $billing = Billing::where('customer_id', $customer)->orderBy('id')->first();
      if($billing){
        return response()->json([
          'status' => 'Exito',
          'results' => $billing
        ],200);
      }
      else{
				return response()->json([
						'status' => 'Error al obtener los datos del primer billing del cliente',
						'results' => '',
					], 400);
			}
    }
}
