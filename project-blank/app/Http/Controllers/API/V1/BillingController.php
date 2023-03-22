<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
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
        'phone' => ['string','max:255'],
        'address' => ['string','max:255'],
        'email' => ['string', 'email', 'max:255'],
        ], [
            'name.required' => 'El correo es obligatorio.',
        'document.required' => 'La identificación es obligatoria.',
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
        $billing = Billing::create($request->all());
        if ($billing) {
            return response()->json([
                'saved' => true,
                'results' => $billing
            ], 200);
        } else {
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

    /*
    getFirstBillingUser
    Retorna el primer billing del customer para presentarlo en el area
    de datos de factura en el checkout, con el fin de de que el usuario
    no escribe de nuevo todos los campos del mismo
    */
    public function getFirstBillingUser($customer)
    {
        $billing = Billing::where('customer_id', $customer)->orderBy('id')->first();
        if ($billing) {
            return response()->json([
                'status' => 'Exito',
                'results' => $billing
            ], 200);
        } else {
            return response()->json([
                'status' => 'No tiene primer billing',
                'results' => '',
            ], 200);
        }
    }

    public function searchBillingByDocument(Request $request)
    {
        if (!$request->billing_document) {
            return response()->json(
                [
                    'status' => 'Ingrese un documento',
                    'results' => null,
                ],
                409
            );
        }

        $billing = Billing::where('document', $request->billing_document)->orderBy('id', 'desc')->first();
        if ($billing) {
            return response()->json(
                [
                    'status' => 'Billing encontrado',
                    'results' => $billing,
                ],
                200
            );
        }
        return response()->json(
            [
            'status' => 'No hay billings con ese documento',
            'results' => null,
            ],
            404
        );
    }
}
