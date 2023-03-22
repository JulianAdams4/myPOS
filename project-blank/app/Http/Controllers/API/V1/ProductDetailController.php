<?php

namespace App\Http\Controllers\API\V1;

use App\ProductDetail;
use App\Store;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProductDetailController extends Controller
{
    public function getProductsDetails(Request $request)
    {
        $collection = collect([]);
        foreach ($request->productsDetails as $productDetail) {
          $product = ProductDetail::with([
            'product' => function ($product) use ($productDetail) {
              $product->where('status', 1)->with([
                'specifications' => function($specifications) use ($productDetail) {
                  $specifications->where('specifications.status', 1)
                  ->whereIn('specifications.id',$productDetail['idSpecification']);
                }
              ]);
            }
          ])->where('status',1)->where('id',$productDetail['id'])->first();
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