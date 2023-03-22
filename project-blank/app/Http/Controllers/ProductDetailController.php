<?php

namespace App\Http\Controllers;

use App\ProductDetail;
use App\Product;
use Illuminate\Http\Request;
use Log;

class ProductDetailController extends Controller
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
     * @param  \App\ProductDetail  $productDetail
     * @return \Illuminate\Http\Response
     */
    public function show(ProductDetail $productDetail)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\ProductDetail  $productDetail
     * @return \Illuminate\Http\Response
     */
    public function edit(ProductDetail $productDetail)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\ProductDetail  $productDetail
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, ProductDetail $productDetail)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\ProductDetail  $productDetail
     * @return \Illuminate\Http\Response
     */
    public function destroy(ProductDetail $productDetail)
    {
        //
    }

    public function getProductsDetails(Request $request)
    {
        $collection = collect([]);
        foreach ($request->productsDetails as $productDetail) {
          $product = ProductDetail::with(
            [
              'product:id,name',
              'product.specifications'=>function($q) use ($productDetail){
                $q->whereIn('specification_id',$productDetail['idSpecification']);
                $q->with('specification:id,name');
              }
            ]
          )
            ->find($productDetail['id']);
           if($product){
              $collection->push($product);
            } 
        }
          return response()->json([
            'status' => 'Se obtuvo los detalles de los productos con éxito',
            'results' => $collection
          ],200);
    }

  }
