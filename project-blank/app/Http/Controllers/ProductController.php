<?php

namespace App\Http\Controllers;

use App\Product;
use App\ProductDetail;
use Illuminate\Http\Request;
use Log;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function show(Product $product)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Product $product)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy(Product $product)
    {
        //
    }

    public function getProductDetails(Request $request)
    {
         $product = ProductDetail::with(
            [
              'product:id,name',
              'product.specifications.specification.specificationCategory',
            ]
          )
            ->find($request->idProductDetail);
           if($product){
              return response()->json([
                'status' => 'Se obtuvo los detalles del producto con éxito',
                'results' => $product
              ],200);
            }
            else{
                return response()->json([
                'status' => 'No se obtuvieron los detalles del producto',
                'results' => ''
              ],200);
            }
    }
}
