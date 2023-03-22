<?php

namespace App\Http\Controllers\API\V1;

use Log;
use Auth;
use App\Store;
use App\Company;
use App\Section;
use App\Employee;
use App\ProductCategory;
use App\Traits\AuthTrait;
use App\Product;
use Illuminate\Http\Request;
use App\Traits\GeoProcedures;
use App\Traits\ValidateToken;
use App\Http\Controllers\Controller;

class ProductCategoryController extends Controller
{
    use ValidateToken, GeoProcedures, AuthTrait;

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

    public function getCategoriesByCompany($company)
    {
        return [
            'categories' => ProductCategory::with('products')->where('company_id', $company)->where('status', 1)->get(),
        ];
    }

    public function categoriesStore(Request $request)
    {
	    // $employee = Employee::where('user_id', Auth::user()->id)->first();
	   
        $employee = $this->authEmployee;
	
        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
                return response()->json(
                    [
                        'status' => 'El empleado no pertenece al hub',
                        'results' => null
                    ],
                    401
                );
            }
        }
	
        $categories = [];
        $sectionsMain = Section::where('store_id', $employee->store->id)
            ->where('is_main', 1)->get();
        $categories = collect([]);
        if (!(count($sectionsMain) > 0)) {
            $sectionsMain = Section::where('store_id', $employee->store->id)->get();
        }

        foreach ($sectionsMain as $sectionMain) {
            $categoriesSection = $employee->store->company->categories()
                ->where('section_id', $sectionMain->id)
                ->where('status', 1)
                ->orderBy('priority')
                ->get();
            foreach ($categoriesSection as $categorySection) {
                $categories->push($categorySection);
            }
        }
        return response()->json([
            'status' => 'Success',
            'results' => $categories
        ], 200);
    }

    public function getCategoriesByStore(Request $request)
    {
        $agent = $request->input('agent', 'tere');
        $customerLat = $request->input('latitude', null);
        $customerLng = $request->input('longitude', null);
        $company = Company::with('stores', 'stores.address')->where('identifier', $agent)->first();
        if ($company && $customerLat && $customerLng) {
            $stores = $company->stores;
            $closetStore = $this->getClosestStoreByLocation($stores, $customerLat, $customerLng);
            if ($closetStore) {
                $categories = ProductCategory::whereHas('products.product_details', function ($details) use ($closetStore) {
                    $details->where('store_id', $closetStore->id);
                })->with(['products' => function ($products) use ($closetStore) {
                    $products->whereHas('product_details', function ($details) use ($closetStore) {
                        $details->where('store_id', $closetStore->id)
                            ->where('stock', '>', 0);
                    })->with(['product_details' => function ($details) use ($closetStore) {
                        $details->where('store_id', $closetStore->id);
                    }])->where('type', 'main');
                }])->get();

                foreach ($categories as $key => $category) {
                    if ($category->products->count() === 0) {
                        $categories->forget($key);
                    }
                }

                return response()->json([
                    'message' => 'Productos listados correctamente.',
                    'results' => [
                        'categories' => $categories->values()->all(),
                        'store' => [
                            'id' => $closetStore->id,
                            'latitude' => $closetStore->address->latitude,
                            'longitude' => $closetStore->address->longitude,
                        ]
                    ]
                ], 200);
            } else {
                return response()->json([
                    'message' => 'No se encontro ningún Store cercano.',
                    'results' => []
                ], 404);
            }
        }
        return response()->json([
            'message' => 'No se encontro Company',
            'results' => []
        ], 404);
    }

    public function getProductsStore(Request $request)
    {
        $employee = Employee::where('user_id', Auth::user()->id)->first();

        if ($request->employee_id != null) {
            $employee = Employee::find($request->employee_id);

            if (!$employee->verifyEmployeeBelongsToHub($this->authUser->hub)) {
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

        $sectionsMain = Section::where('store_id', $employee->store->id)
            ->where('is_main', 1)->get();

        if (!(count($sectionsMain) > 0)) {
            $sectionsMain = Section::where('store_id', $employee->store->id)->get();
        }

        $categories = collect([]);

        foreach ($sectionsMain as $sectionMain) {
            $categoriesSection = $employee->store->company->categories()
                ->where('section_id', $sectionMain->id)
                ->where('status', 1)
                ->get();
            foreach ($categoriesSection as $categorySection) {
                $categories->push($categorySection->id);
            }
        }

        if ($categories == null) {
            return response()->json(
                [
                    'status' => 'No se encontró categorías',
                    'results' => null
                ],
                404
            );
        }

        $products = Product::where('products.status', 1)
            ->whereIn('product_category_id', $categories)
            ->orderBy('priority')
            ->with(
                [
                    'category',
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
            ->get();

        return response()->json(
            [
                'status' => 'Success',
                'results' => $products
            ],
            200
        );
    }
}
