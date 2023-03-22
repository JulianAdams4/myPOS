<?php

namespace App\Http\Controllers\API\V1;

use App\Product;
use App\ProductCategory;
use App\SpecificationCategory;
use App\ProductDetail;
use App\Employee;
use App\Store;
use App\ProductSpecification;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Log;
use Auth;
use App\Traits\ValidateToken;

class ProductController extends Controller
{

    use ValidateToken;

    public function getProductsByCategory($category) {
        return [
            'products' => Product::where('product_category_id', $category)->get()
        ];
    }

    /*
    getProductDetails
    Retorna los datos de cierto producto con sus categorias y las respectivas especificaciones 
    de cada categoria por Ejm. Masa: Verde, Maduro
    */
    public function getProductDetails(Request $request)
    {
        $product = ProductDetail::with([
            'product' => function ($product) {
            $product->where('status', 1)->with([
                'compatibles' => function($compatibles) {
                $compatibles->wherePivot('status', 1)->where('products.status', 1);
                }
            ]);
            }
        ])->where('status', 1)
        ->where('id', $request->idProductDetail)
        ->first();

        /*$productSpecifications = ProductSpecification::whereHas(
          'product', function ($product) use ($request) {
            $product->where('status',1)->whereHas(
              'product_details', function ($productDetail) use ($request) {
                $productDetail->where('status',1)->where('id',$request->idProductDetail);
              }
            );
          }
        )->pluck('specification_id')->toArray();*/

        $specificationCategories = SpecificationCategory::
            whereHas('specifications', function($specs) use ($request) {
                $specs->where('status', 1)->whereHas('products', function($products) use ($request) {
                    $products->where('products.status', 1)->whereHas('product_details', function ($detail) use ($request) {
                        $detail->where('status', 1)->where('id', $request->idProductDetail);
                    });
                });
            })
            ->with([
                'specifications' => function ($specs) use ($request) {
                    $specs->where('status', 1)->with(['products' => function ($prods) use ($request) {
                        $prods->whereHas('details', function($details) use ($request){
                            $details->where('status', 1)->where('id', $request->idProductDetail);
                        });
                    }])->whereHas('products', function($products) use ($request){
                        $products->where('products.status', 1)->whereHas('details', function($details) use ($request){
                            $details->where('status', 1)->where('id', $request->idProductDetail);
                        });
                    });
                }
            ])
            ->where('status', 1)
            ->get();
        if($product){
            return response()->json([
                'specification_categories' => $specificationCategories,
                'product_detail' => $product
            ],200);
        } else {
            return response()->json([
                'status' => 'No se obtuvieron los detalles del producto',
                'results' => ''
            ],404);
        }
    }

    public function productsCategory(Request $request, $id)
    {
        $employee = Employee::where('user_id', Auth::user()->id)->first();

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub(Auth::user()->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }
        }

        if (!$employee) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
        $category = ProductCategory::where('company_id', $employee->store->company_id)
            ->where('id', $id)->first();
        if ($category) {
            $products = Product::select('id','name','base_value','image','status','invoice_name','ask_instruction')
                ->with(
                    [
                        'specifications' => function ($query) {
                            $query->wherePivot('status', 1)
                            ->orderBy('specifications.priority')
                            ->with('specificationCategory')
                            ->join('specification_categories', function ($join) {
                                $join->on(
                                    'specification_categories.id',
                                    '=',
                                    'specifications.specification_category_id'
                                )
                                ->where('specification_categories.status', 1)
                                ->whereNull('specification_categories.deleted_at');
                            })
                            ->orderBy('specification_categories.priority', 'ASC');
                        }
                    ]
                )
                ->where('product_category_id', $id)
                ->where('products.status', 1)
                ->orderBy('priority')
                ->get();
            return response()->json(
                [
                    'status' => 'Success',
                    'results' => $products
                ],
                200
            );
        }
        
        return response()->json(
            [
                'status' => 'No existe la categoría.',
                'results' => []
            ],
            404
        );
    }

    public function inventoryProducts(Request $request) 
    {
        $employee = Auth::guard('employee-api')->user();
        $products = Product::whereHas('category', function ($category) use ($employee) {
                    $category->where('company_id', $employee->store->company_id);
                })->with('category:id,name')->get();
        return response()->json(
            [
                'status' => 'Success',
                'results' => $products
            ], 200
        );
    }

}
