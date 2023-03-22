<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\ProductCategory;
use App\Employee;
use App\Store;
use App\Traits\GeoProcedures;
use Illuminate\Http\Request;
use App\Traits\ValidateToken;
use App\Helper;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Traits\LoggingHelper;
use App\Traits\AWSHelper;
use App\Traits\LocalImageHelper;
use App\Traits\FranchiseHelper;
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\IFood\UpdateIfoodCategoryJob;
use App\Jobs\IFood\UploadIfoodCategoryJob;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\iFood\IfoodMenu;
use App\Traits\iFood\IfoodRequests;

class ProductCategoryController extends Controller
{
    use ValidateToken;
    use GeoProcedures;
    use AuthTrait;
    use AWSHelper;
    use LocalImageHelper;
    use IfoodMenu;

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

    public function create(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $sectionStore = Store::whereHas('sections', function ($query) use ($request) {
            return $query->where('id', $request->section_id);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        try {
            $categoryJSON = DB::transaction(function () use ($request, $sectionStore) {
                $productCategory = ProductCategory::where('name', $request->name)
                    ->where('section_id', $request->section_id)
                    ->where('company_id', $sectionStore->company_id)
                    ->get();
                if (count($productCategory) > 0) {
                    return response()->json([
                        'status' => 'Esta categoría ya existe',
                        'results' => null
                    ], 409);
                }

                $priority = 0;
                $lastProductCategory = DB::table('product_categories')
                    ->where('section_id', $request->section_id)
                    ->latest('priority')
                    ->first();
                if ($lastProductCategory) {
                    $priority = $lastProductCategory->priority + 1;
                }
                $productCategory = new ProductCategory();
                $productCategory->name = $request->name;
                $productCategory->subtitle = '';
                $productCategory->search_string = Helper::remove_accents($request->name);
                $productCategory->status = 1;
                $productCategory->section_id = $request->section_id;
                $productCategory->priority = $priority;
                $productCategory->company_id = $sectionStore->company_id;
                $productCategory->image_version = 0;
                $productCategory->created_at = Carbon::now()->toDateTimeString();
                $productCategory->updated_at = Carbon::now()->toDateTimeString();
                $productCategory->save();
                $imageData = $request["image_bitmap64"];
                if ($imageData != null) {
                    $filename = $this->storeProductCategoryImageOnLocalServer($imageData, $productCategory->id);
                    if ($filename != null) {
                        $folder = '/product_category_images/';
                        $this->uploadLocalFileToS3(
                            public_path() . $folder . $filename . '.jpg',
                            $sectionStore->id,
                            $filename,
                            $productCategory->image_version,
                            null
                        );
                        $this->saveProductCategoryImageAWSUrlDB($productCategory, $filename, $sectionStore->id);
                        $this->deleteImageOnLocalServer($filename, $folder);
                    }
                }
                $dataConfig = IfoodRequests::checkIfoodConfiguration($sectionStore);
                $updateIfood = true;
                $client = $browser = $channelLog = $channelSlackDev = $baseUrl = null;
                if ($dataConfig["code"] != 200) {
                    $updateIfood = false;
                } else {
                    $client = new FileGetContents(new Psr17Factory());
                    $browser = new Browser($client, new Psr17Factory());
                    $channelLog = "ifood_logs";
                    $channelSlackDev = "#integration_logs_details";
                    $baseUrl = config('app.ifood_url_api');
                    $categoryObject = [
                        "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                        "availability" => "AVAILABLE",
                        "name" => $productCategory->name,
                        "order" => $productCategory->priority,
                        "template" => "PADRAO",
                        "externalCode" => $productCategory->id
                    ];
                    EmptyJob::withChain([
                        (new UploadIfoodCategoryJob(
                            $sectionStore,
                            $dataConfig["data"]["integrationConfig"]->external_store_id,
                            $sectionStore->name,
                            $categoryObject,
                            $channelLog,
                            $channelSlackDev,
                            $baseUrl,
                            $browser
                        ))->delay(5)
                    ])->dispatch();
                }

                return response()->json([
                    'status' => 'Categoría editada exitosamente',
                    'results' => null
                ], 200);
            });
            return $categoryJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductCategoryController API Store create: ERROR AL CREAR LA CATEGORIA, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo crear la categoría',
                'results' => null
            ], 409);
        }
    }

    public function update(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $categoryStore = Store::whereHas('sections.categories', function ($query) use ($request) {
            return $query->where('id', $request->category_id);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($categoryStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        try {
            $categoryJSON = DB::transaction(function () use ($request, $categoryStore) {
                $productCategory = ProductCategory::where('id', $request->category_id)
                    ->where('status', 1)
                    ->where('company_id', $categoryStore->company_id)
                    ->first();
                if (!$productCategory) {
                    return response()->json([
                        'status' => 'Esta categoría no existe',
                        'results' => null
                    ], 409);
                }
                $productCategory->name = $request->name;
                $productCategory->search_string = Helper::remove_accents($request->name);
                $productCategory->updated_at = Carbon::now()->toDateTimeString();
                $imageData = $request["image_bitmap64"];
                if ($imageData) {
                    $filename = $this->storeProductCategoryImageOnLocalServer($imageData, $productCategory->id);
                    if ($filename != null) {
                        $folder = '/product_category_images/';
                        $this->uploadLocalFileToS3(
                            public_path() . $folder . $filename . '.jpg',
                            $categoryStore->id,
                            $filename,
                            $productCategory->image_version,
                            null
                        );
                        $this->saveProductCategoryImageAWSUrlDB($productCategory, $filename, $categoryStore->id);
                        $this->deleteImageOnLocalServer($filename, $folder);
                    }
                } else {
                    $productCategory->save();
                }

                $categoryIfoodJobs = [];
                $dataConfig = IfoodRequests::checkIfoodConfiguration($categoryStore);
                $client = $browser = $channelLog = $channelSlackDev = $baseUrl = null;
                if ($dataConfig["code"] == 200) {
                    $client = new FileGetContents(new Psr17Factory());
                    $browser = new Browser($client, new Psr17Factory());
                    $channelLog = "ifood_logs";
                    $channelSlackDev = "#integration_logs_details";
                    $baseUrl = config('app.ifood_url_api');
                    $categoryObject = [
                        "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                        "externalCode" => $productCategory->id,
                        "availability" => "AVAILABLE",
                        "name" => $productCategory->name,
                        "order" => $productCategory->priority
                    ];
                    EmptyJob::withChain([
                        (new UpdateIfoodCategoryJob(
                            $dataConfig["data"]["integrationToken"]->token,
                            $categoryStore->name,
                            $categoryObject,
                            $channelLog,
                            $channelSlackDev,
                            $baseUrl,
                            $browser
                        ))->delay(5)
                    ])->dispatch();
                }

                return response()->json([
                    'status' => 'Categoría editada exitosamente',
                    'results' => null
                ], 200);
            });
            return $categoryJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductCategoryController API Store update: ERROR AL ACTUALIZAR LA CATEGORIA, storeId: " . $categoryStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo editar la categoría',
                'results' => null
            ], 409);
        }
    }

    public function delete(Request $request, $categoryId)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $categoryStore = Store::whereHas('sections.categories', function ($query) use ($categoryId) {
            return $query->where('id', $categoryId);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($categoryStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        try {
            $categoryJSON = DB::transaction(function () use ($categoryId, $categoryStore) {
                if (!$categoryStore) {
                    return response()->json([
                        'status' => 'No autorizado',
                        'results' => null
                    ], 401);
                }
                $productCategory = ProductCategory::where('id', $categoryId)
                    ->where('company_id', $categoryStore->company_id)
                    ->first();
                if (!$productCategory) {
                    return response()->json([
                        'status' => 'Esta categoría no existe',
                        'results' => null
                    ], 409);
                }
                $productCategory->status = 0;
                $productCategory->updated_at = Carbon::now()->toDateTimeString();
                if ($productCategory->image) {
                    $storeId = $categoryStore->id;
                    $filename = 'categoria_' . $productCategory->id;
                    $this->deleteFileFromS3(
                        $productCategory,
                        $storeId,
                        $filename
                    );
                } else {
                    $productCategory->save();
                }
                $productCategory->delete();
                return response()->json([
                    'status' => 'Categoría eliminada exitosamente',
                    'results' => null
                ], 200);
            });
            return $categoryJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductCategoryController API Store delete: ERROR AL ELIMINAR LA CATEGORIA, storeId: " . $categoryStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo eliminar la categoría',
                'results' => null
            ], 409);
        }
    }


    public function getProductCategoriesBySection($id, $deleted)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $sectionStore = Store::whereHas('sections', function ($query) use ($id) {
            return $query->where('id', $id);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $categories = [];
        if ($deleted == "true") {
            $categories = ProductCategory::where('section_id', $id)
                ->where('company_id', $sectionStore->company_id)
                ->where('status', 1)
                ->with([
                    'section.integrations' => function ($integration) {
                        $integration->select([
                            'id',
                            'section_id',
                            'integration_id'
                        ]);
                    }
                ])
                ->orderBy('priority')
                ->get();
        } else {
            $categories = ProductCategory::where('section_id', $id)
                ->where('company_id', $sectionStore->company_id)
                ->where('status', 1)
                ->with([
                    'section.integrations' => function ($integration) {
                        $integration->select([
                            'id',
                            'section_id',
                            'integration_id'
                        ]);
                    }
                ])
                ->orderBy('priority')
                ->get();
        }

        return response()->json([
            'status' => 'Listando categorías',
            'results' => $categories
        ], 200);
    }

    public function reorderPrioritiesProductCategories(Request $request)
    {

        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $productCategoryIds = $request->ids;
        $categoriesStore = Store::whereHas('sections.categories', function ($query) use ($productCategoryIds) {
            return $query->whereIn('id', $productCategoryIds);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($categoriesStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        if (!$productCategoryIds) {
            return response()->json([
                'status' => 'Se necesita del arrego con el ordenamiento',
                'results' => null
            ], 409);
        }

        try {
            $processJSON = DB::transaction(
                function () use ($productCategoryIds, $categoriesStore) {
                    $orderedIds = implode(',', $productCategoryIds);
                    $productCategories = ProductCategory::whereIn('id', $productCategoryIds)
                        ->orderByRaw(DB::raw("FIELD(id, $orderedIds)"))
                        ->get();
                    $categoryIfoodJobs = [];
                    $dataConfig = IfoodRequests::checkIfoodConfiguration($categoriesStore);
                    $updateIfood = true;
                    $client = $browser = $channelLog = $channelSlackDev = $baseUrl = null;
                    if ($dataConfig["code"] != 200) {
                        $updateIfood = false;
                    } else {
                        $client = new FileGetContents(new Psr17Factory());
                        $browser = new Browser($client, new Psr17Factory());
                        $channelLog = "ifood_logs";
                        $channelSlackDev = "#integration_logs_details";
                        $baseUrl = config('app.ifood_url_api');
                    }
                    foreach ($productCategories as $index => $productCategory) {
                        $productCategory->priority = $index;
                        $productCategory->save();
                        if ($updateIfood) {
                            $categoryObject = [
                                "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                                "externalCode" => $productCategory->id,
                                "availability" => "AVAILABLE",
                                "name" => $productCategory->name,
                                "order" => $productCategory->priority
                            ];
                            array_push(
                                $categoryIfoodJobs,
                                (new UpdateIfoodCategoryJob(
                                    $dataConfig["data"]["integrationToken"]->token,
                                    $categoriesStore->name,
                                    $categoryObject,
                                    $channelLog,
                                    $channelSlackDev,
                                    $baseUrl,
                                    $browser
                                ))->delay(5)
                            );
                        }
                    }
                    if ($updateIfood) {
                        EmptyJob::withChain($categoryIfoodJobs)->dispatch();
                    }
                    return response()->json([
                        'status' => "Guardado exitoso",
                        'results' => null
                    ], 200);
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductCategoryController API Store reorder: ERROR CAMBIAR EL ORDEN, storeId: " . $categoriesStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo cambiar el orden',
                'results' => null
            ], 409);
        }
    }
}
