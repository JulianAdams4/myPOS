<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\StoreTax;
use App\Employee;
use App\Product;
use App\Traits\GeoProcedures;
use Illuminate\Http\Request;
use Log;
use App\Traits\ValidateToken;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class StoreTaxController extends Controller
{
    use ValidateToken;
    use GeoProcedures;
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

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $request = request();
        $rowsPerPage = 12;
        $store = $this->authStore;
        $store->load('city.country');
        $offset = ($request->page['page'] * $rowsPerPage) - $rowsPerPage;
        $components = StoreTax::where('store_id', $store->id)->get();
        $componentsPage = [];
        $componentsSlice = $components->slice($offset, $rowsPerPage);
        if ($offset > 0) {
            foreach ($componentsSlice as $component) {
                array_push($componentsPage, $component);
            }
        } else {
            $componentsPage = $componentsSlice;
        }
        $country = null;
        try {
            $country = $store->city->country;
        } catch (\Exception $e) {
        }
        return response()->json(
            [
                'status' => 'Listando componentes',
                'results' => [
                    'count' => count($components),
                    'data' => $componentsPage,
                    'country' => $country,
                ]
            ],
            200
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $store = $this->authStore;
        $storeTax = StoreTax::where('name', $request->name)
                            ->where('store_id', $store->id)
                            ->get();
        if (count($storeTax) > 0) {
            return response()->json(
                [
                    'status' => 'Este impuesto ya existe',
                    'results' => null
                ],
                409
            );
        }
        try {
            $processJSON = DB::transaction(
                function () use ($request, $store) {
                    $storeTax = new StoreTax();
                    $storeTax->store_id = $store->id;
                    $storeTax->name = $request->name;
                    $storeTax->percentage = $request->percentage;
                    $storeTax->type = $request->type;
                    $storeTax->tax_type = $request->category;
                    $storeTax->enabled = $request->enabled;
                    $storeTax->is_main = $request->apply_all;
                    $storeTax->created_at = Carbon::now()->toDateTimeString();
                    $storeTax->updated_at = Carbon::now()->toDateTimeString();
                    $storeTax->save();
                    if ($request->apply_all && $storeTax) {
                        $products = Product::whereHas(
                            'category.section',
                            function ($q) use ($store) {
                                $q->where('store_id', $store->id);
                            }
                        )->get();
                        foreach ($products as $product) {
                            $product->taxes()->syncWithoutDetaching([$storeTax->id]);
                        }
                    }
                    if ($storeTax) {
                        return response()->json(
                            [
                                'status' => 'Impuesto creado exitosamente',
                                'results' => null
                            ],
                            200
                        );
                    } else {
                        return response()->json(
                            [
                                'status' => 'No se pudo crear el impuesto',
                                'results' => null
                            ],
                            409
                        );
                    }
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            Log::info("StoreTaxController API store(): NO SE PUDO COMPLETAR EL PROCESO");
            Log::info($e);
            return response()->json(
                [
                    'status' => 'No se pudo completar este proceso',
                    'results' => "null"
                ],
                409
            );
        }
    }
    
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $store = $this->authStore;
        $storeTax = StoreTax::where('store_id', $store->id)->where('id', $id)->first();
        $externalId = $storeTax->taxesIntegrations;
        $storeTax = collect($storeTax);
        $storeTax->prepend($externalId);

        if ($storeTax) {
            return response()->json(
                [
                    'status' => 'Impuesto del local obtenido exitosamente',
                    'results' => $storeTax
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'El impuesto no existe',
                    'results' => null
                ],
                409
            );
        }
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
        $store = $this->authStore;
        $existingTax = StoreTax::where('store_id', $store->id)
                        ->where('id', '!=', $id)
                        ->where('name', $request->name)
                        ->first();
        if (!$existingTax) {
            $storeTax = StoreTax::where('store_id', $store->id)->where('id', $id)->first();
            if ($storeTax) {
                $storeTax->name = $request->name;
                $storeTax->percentage = $request->percentage;
                $storeTax->type = $request->type;
                $storeTax->tax_type = $request->category;
                $storeTax->enabled = $request->enabled;
                $storeTax->is_main = $request->is_main;
                $storeTax->save();
                if ($storeTax->type === 'invoice') {
                    $storeTax->products()->sync([]);
                }

                $products = Product::whereHas(
                    'category.section',
                    function ($q) use ($store) {
                        $q->where('store_id', $store->id);
                    }
                )->get();

                //Crea la relación del impuesto con el producto
                if ($request->is_main && $storeTax) {
                    foreach ($products as $product) {
                        $product->taxes()->syncWithoutDetaching([$storeTax->id]);
                    }
                //Elimina la relación del impuesto con el producto
                } elseif (!$request->is_main && $storeTax) {
                    foreach ($products as $product) {
                        $product->taxes()->detach([$storeTax->id]);
                    }
                }

                return response()->json(
                    [
                        'status' => 'Impuesto modificado exitosamente',
                        'results' => $storeTax
                    ],
                    200
                );
            } else {
                return response()->json(
                    [
                        'status' => 'El impuesto no existe',
                        'results' => null
                    ],
                    409
                );
            }
        }
        return response()->json(
            [
                'status' => 'Ya existe un impuesto con el mismo nombre',
                'results' => null
            ],
            409
        );
    }
    
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $store = $this->authStore;
        $storeTax = StoreTax::where('store_id', $store->id)->where('id', $id)->first();
        if ($storeTax) {
            $storeTax->delete();
            return response()->json(
                [
                    'status' => 'Impuesto borrado exitosamente',
                    'results' => $storeTax
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'El impuesto no existe',
                    'results' => null
                ],
                409
            );
        }
    }

    public function search(Request $request)
    {
        $store = $this->authStore;
        $requestQuery = $request->searchQuery;
        $searchQuery = "%" . $requestQuery . "%";
        $storeTaxes = StoreTax::where('store_id', $store->id)
            ->where('type', '!=', 'invoice')
            ->where('name', 'like', $searchQuery)
            ->get();
        
        return response()->json(
            [
                'status' => 'Listando especificaciones',
                'results' => $storeTaxes,
            ],
            200
        );
    }
}
