<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\SpecificationCategory;
use App\Employee;
use App\SpecificationComponent;
use App\Store;
use App\Traits\AuthTrait;
use App\Traits\GeoProcedures;
use App\Traits\ValidateToken;
use App\Traits\FranchiseHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\IFood\UpdateIfoodGroupModifierJob;
use App\Jobs\IFood\UploadIfoodCategoryJob;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\iFood\IfoodMenu;
use App\Traits\iFood\IfoodRequests;
use Log;

class SpecificationCategoryController extends Controller
{

    use ValidateToken;
    use GeoProcedures;
    use AuthTrait;
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
        //
    }

    public function search(Request $request)
    {
        $resultsLimit = 15;
        $store = $this->authStore;
        $requestQuery = $request->searchQuery;
        $searchQuery = "%" . $requestQuery . "%";
        $specificationCategories = SpecificationCategory::where('status', 1)
            ->where('company_id', $store->company_id)
            ->whereHas(
                'section',
                function ($q) use ($store, $request) {
                    $q->where('store_id', $store->id)
                        ->where('section_id', $request->section_id)->withTrashed();
                }
            )
            ->where('name', 'like', $searchQuery)
            ->with([
                'specifications' => function ($specification) {
                    $specification->orderBy('priority')
                        ->with('productSpecifications');
                }
            ])
            ->limit($resultsLimit)
            ->get();

        foreach ($specificationCategories as &$specificationCategory) {
            $specifications = $specificationCategory->specifications;
            foreach ($specifications as &$specification) {
                $specComps = SpecificationComponent::where(
                    "specification_id",
                    $specification->id
                )
                    ->with([
                        "variation" => function ($variation) {
                            $variation->where("status", 1)->with(["unitConsume"]);
                        }
                    ])
                    ->where("status", 1)
                    ->get();
                $compConsumptions = [];
                foreach ($specComps as $specComp) {
                    if (!isset($specComp->variation)) {
                        continue;
                    }

                    array_push(
                        $compConsumptions,
                        [
                            "component_id" => $specComp->component_id,
                            "variation" => $specComp->variation,
                            "id" => "new" . $specificationCategory->id . $specification->id . $specComp->id,
                            "consumption" => $specComp->consumption
                        ]
                    );
                }
                $specification["component_consumption"] = $compConsumptions;
            }
        }

        return response()->json(
            [
                'status' => 'Listando especificaciones',
                'results' => $specificationCategories,
            ],
            200
        );
    }

    public function getSpecificationsBySection(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $search = $request->search ? $request->search : "";
        $sectionId = $request->section_id ? $request->section_id : null;

        $isAdminFranchise = $user->isAdminFranchise();
        $sectionStore = Store::whereHas('sections', function ($query) use ($sectionId) {
            return $query->where('id', $sectionId);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        if (!$sectionId) {
            return response()->json([
                'status' => 'Se necesita enviar el identificador de la sección',
                'results' => null,
            ], 409);
        }

        if ($search === "") {
            $specificationCategories = SpecificationCategory::where('section_id', $sectionId)
                ->where('company_id', $sectionStore->company_id)
                ->orderBy('priority', 'asc')
                ->with([
                    'specifications' => function ($specification) {
                        $specification->where('status', 1);
                    }
                ])
                ->get();
        } else {
            $specificationCategories = SpecificationCategory::search($search)
                ->where('section_id', $sectionId)
                ->where('company_id', $sectionStore->company_id)
                ->get()
                ->pluck('id');
            $specificationCategories = SpecificationCategory::whereIn('id', $specificationCategories)
                ->with([
                    'specifications' => function ($specification) {
                        $specification->where('status', 1);
                    }
                ])
                ->orderBy('priority', 'asc')
                ->get();
        }
        return response()->json([
            'status' => 'Listado de categorías de las especificaciones',
            'results' => [
                'count' => count($specificationCategories),
                'data' => $specificationCategories,
            ],
        ], 200);
    }

    public function delete(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;

        $isAdminFranchise = $user->isAdminFranchise();
        $specificationCategoryStore = Store::whereHas('sections.specificationsCategories', function ($query) use ($request) {
            return $query->where('id', $request->id_specification_category);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($specificationCategoryStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $specificationCategory = SpecificationCategory::where('company_id', $specificationCategoryStore->company_id)
            ->where('id', $request->id_specification_category)
            ->first();

        if (!$specificationCategory) {
            return response()->json([
                'status' => 'Esta categoría de especificación no existe o ya está oculta',
                'results' => null,
            ], 409);
        }

        try {
            $componentJSON = DB::transaction(
                function () use ($specificationCategory) {
                    $specificationCategory->delete();
                    return response()->json([
                        "status" => "Categoría de especificación deshabilitada con éxito",
                        "results" => null,
                    ], 200);
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SpecificationCategoryController API Store: ERROR ELIMINAR SPEC CAT, storeId: " . $specificationCategoryStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo deshabilitar esta categoría de especificación',
                'results' => null,
            ], 409);
        }
    }

    public function infoSpecification($id)
    {
        $specificationCategory = SpecificationCategory::where('status', 1)
            ->where('id', $id)
            ->with(
                [
                    'specifications' => function ($query) {
                        $query->orderBy('priority');
                    },
                    'specifications.components' => function ($query) {
                        $query->where('status', 1);
                    },
                    'specifications.components.variation.unit',
                    'specifications.components.variation.unitConsume',
                    'specifications.components.variation',
                ]
            )
            ->first();
        if ($specificationCategory) {
            return response()->json(
                [
                    'status' => 'Especificación info',
                    'results' => $specificationCategory,
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'status' => 'La especificación no existe',
                    'results' => null,
                ],
                409
            );
        }
    }

    public function reorderPrioritiesSpecCategory(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $specificationCategoryIds = $request->ids;

        $isAdminFranchise = $user->isAdminFranchise();
        $specificationCategoriesStore = Store::whereHas('sections.specificationsCategories', function ($query) use ($specificationCategoryIds) {
            return $query->whereIn('id', $specificationCategoryIds);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($specificationCategoriesStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        if (!$specificationCategoryIds) {
            return response()->json([
                'status' => 'Se necesita del arrego con el ordenamiento',
                'results' => null
            ], 409);
        }
        try {
            $processJSON = DB::transaction(
                function () use ($specificationCategoryIds, $specificationCategoriesStore) {
                    $orderedIds = implode(',', $specificationCategoryIds);
                    $specificationCategories = SpecificationCategory::whereIn('id', $specificationCategoryIds)
                        ->orderByRaw(DB::raw("FIELD(id, $orderedIds)"))
                        ->get();
                    $categoryIfoodJobs = [];
                    $dataConfig = IfoodRequests::checkIfoodConfiguration($specificationCategoriesStore);
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
                    foreach ($specificationCategories as $index => $specificationCategory) {
                        $specificationCategory->priority = $index;
                        $specificationCategory->save();
                        if ($updateIfood) {
                            // Opciones de este grupo
                            $prodSpecs = $specificationCategory->productSpecs;

                            // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                            $countOptionsSpec = count($prodSpecs);
                            $maxPermitted = $specificationCategory->max;
                            if ($specificationCategory->max > $countOptionsSpec) {
                                $maxPermitted = $countOptionsSpec;
                            }

                            $minPermitted = 0;
                            if ($specificationCategory->required) {
                                $minPermitted = 1;
                            }

                            // Grupo de modificadores
                            $modifiersGroupObject = [
                                "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                                "maxQuantity" => $maxPermitted,
                                "minQuantity" => $minPermitted,
                                "name" => $specificationCategory->name,
                                "sequence" => $specificationCategory->priority
                            ];

                            array_push(
                                $categoryIfoodJobs,
                                (new UpdateIfoodGroupModifierJob(
                                    $$dataConfig["data"]["integrationToken"]->token,
                                    $specificationCategoriesStore->name,
                                    $modifiersGroupObject,
                                    $specificationCategory->id,
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
                "SpecificationCategoryController API Store rerorder: ERROR CAMBIAR ORDEN, storeId: " . $specificationCategoriesStore->id,
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

    public function enable(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;

        $isAdminFranchise = $user->isAdminFranchise();
        $specificationCategoryStore = Store::whereHas('sections.specificationsCategories', function ($query) use ($request) {
            return $query->where('id', $request->id_specification_category)->withTrashed();
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($specificationCategoryStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $specificationCategory = SpecificationCategory::where('company_id', $specificationCategoryStore->company_id)
            ->where('id', $request->id_specification_category)
            ->withTrashed()
            ->first();

        if (!$specificationCategory) {
            return response()->json([
                'status' => 'Esta categoría de especificación no existe o no está oculta',
                'results' => null,
            ], 409);
        }

        try {
            $componentJSON = DB::transaction(
                function () use ($specificationCategory) {
                    $specificationCategory->restore();
                    return response()->json([
                        "status" => "Categoría de especificación habilitada con éxito",
                        "results" => null,
                    ], 200);
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SpecificationCategoryController API Store: ERROR HABILITAR SPEC CAT, storeId: " . $specificationCategoryStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json([
                'status' => 'No se pudo habilitar esta categoría de especificación',
                'results' => null,
            ], 409);
        }
    }
}
