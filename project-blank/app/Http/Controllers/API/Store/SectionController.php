<?php

namespace App\Http\Controllers\API\Store;

ini_set('max_execution_time', 300);

use App\Store;
use App\Section;
use App\Employee;
use Buzz\Browser;
use Carbon\Carbon;
use App\SectionDiscount;
use App\Traits\AuthTrait;
use App\SectionIntegration;
use App\SectionAvailability;
use App\MetricUnit;
use App\Traits\UberEatsMenu;
use Illuminate\Http\Request;
use App\Country;
use App\ProductSpecification;
use App\Traits\SectionHelper;
use App\Traits\FranchiseHelper;
use App\StoreIntegrationToken;
use App\Traits\iFood\IfoodMenu;
use App\Traits\Mely\MelyRequest;
use Buzz\Client\FileGetContents;
use App\ProductIntegrationDetail;
use App\ToppingIntegrationDetail;
use App\AvailableMyposIntegration;
use App\ProductToppingIntegration;
use App\SectionAvailabilityPeriod;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\SubscriptionPlan;
use App\Traits\DidiFood\DidiFoodMenu;
use Nyholm\Psr7\Factory\Psr17Factory;
use Log;
use App\Traits\iFood\IfoodRequests;

class SectionController extends Controller
{
    use AuthTrait, SectionHelper, UberEatsMenu, DidiFoodMenu {
        UberEatsMenu::countryToLocale insteadof DidiFoodMenu;
        DidiFoodMenu::countryToCurrency insteadof UberEatsMenu;
        DidiFoodMenu::countryToTaxValue insteadof UberEatsMenu;
    }

    public $authUser;
    public $authEmployee;
    public $authStore;

    const TARGET_LOCAL = 0;
    const TARGET_UBER = 1;
    const TARGET_RAPPI = 2;
    const TARGET_DIDI = 7;
    const TARGET_ALOHA = 10;
    const TARGET_IFOOD = 6;

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

    public function getSectionsStore(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;

        if ($request->store_id) {
            $store = Store::find($request->store_id);
        }

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($store->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $sections = Section::where('store_id', $store->id)
            ->with([
                'availabilities' => function ($availability) {
                    $availability->orderBy('day')
                        ->with([
                            'periods' => function ($period) {
                                $period->orderBy('start_time');
                            }
                        ]);
                },
                'categories' => function ($category) {
                    $category->select(
                        'id',
                        'section_id'
                    )
                        ->where('status', 1)
                        ->withCount([
                            'products' => function ($product) {
                                $product->where('status', 1);
                            }
                        ]);
                },
                'specificationsCategories' => function ($specificationCategory) {
                    $specificationCategory->select(
                        'id',
                        'section_id'
                    );
                },
                'integrations' => function ($integrations) {
                    $integrations->select(
                        'section_id',
                        'integration_id',
                        'synced',
                        'status_sync'
                    );
                }

            ])
            ->get();

        return response()->json([
            'status' => 'Listando secciones',
            'results' => $sections
        ], 200);
    }

    public function hideSection(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $sectionStore = Store::whereHas('sections', function ($query) use ($request) {
            return $query->where('id', $request->id_section);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $section = Section::where('id', $request->id_section)
            ->where('store_id', $sectionStore->id)
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'El menú que deseas ocultar no existe o ya está ocultao',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($section) {
                    $section->delete();
                    return response()->json([
                        "status" => "El menú se ocultó exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR OCULTAR MENU, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo ocultar este menú',
                'results' => null,
            ], 409);
        }
    }

    public function showSection(Request $request)
    {
        $store = $this->authStore;

        $section = Section::withTrashed()
            ->where('id', $request->id_section)
            ->where('store_id', $store->id)
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'El menú que deseas mostrar no existe o no está oculta',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($section) {
                    $section->restore();
                    return response()->json([
                        "status" => "Menú habilitado exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR MOSTRAR MENU, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo mostrar este menú',
                'results' => null,
            ], 409);
        }
    }

    public function duplicateMenu(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $sectionStore = Store::whereHas('sections', function ($query) use ($request) {
            return $query->where('id', $request->section_id);
        })->first();

        $targetStore = Store::find($request->store_id);

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $section = Section::where('id', $request->section_id)
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'El menú que deseas duplicar no existe',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($section, $sectionStore, $targetStore) {
                    $newSection = $this->duplicateSection(
                        $section,
                        $targetStore
                    );

                    $this->duplicateMenuContent(
                        $section,
                        $newSection,
                        $sectionStore,
                        $targetStore
                    );

                    return response()->json([
                        "status" => "Menú duplicado exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR DUPLICAR MENU, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo duplicar este menú',
                'results' => null,
            ], 409);
        }
    }

    public function assign(Request $request)
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

        $section = Section::where('id', $request->section_id)
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'El menú que deseas asignar no existe',
                'results' => null,
            ], 409);
        }

        $stores = $request->stores;

        try {
            $operationJSON = DB::transaction(
                function () use ($section, $stores, $store) {

                    foreach ($stores as $storeId) {

                        $assignstore = Store::find($storeId);

                        $newSection = $this->duplicateSection(
                            $section,
                            $assignstore,
                            true
                        );

                        $this->duplicateMenuContent(
                            $section,
                            $newSection,
                            $store,
                            $assignstore,
                            true
                        );
                    }

                    return response()->json([
                        "status" => "Menú asignado exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR ASIGNAR MENU, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo asignar este menú',
                'results' => null,
            ], 409);
        }
    }

    public function create(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;

        if ($request->store_id) {
            $store = Store::find($request->store_id);
        }

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($store->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $section = Section::where('name', $request->name)
            ->where('store_id', $store->id)
            ->first();

        if ($section) {
            return response()->json([
                'status' => 'El nombre de este menú ya se está utilizando para otro menú de esta tienda',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($request, $store) {
                    $section = new Section();
                    $section->name = $request->name;
                    $section->subtitle = "";
                    $section->store_id = $store->id;
                    $section->save();

                    // Creando horarios
                    $periods = $request->periods;
                    foreach ($periods as $period) {
                        $day = $period["day"];
                        $timePeriods = $period["timePeriods"];
                        if (count($timePeriods) > 0) {
                            $sectionAvailability = new SectionAvailability();
                            $sectionAvailability->section_id = $section->id;
                            $sectionAvailability->day = $day;
                            $sectionAvailability->save();
                            foreach ($timePeriods as $timePeriod) {
                                $sectionAvailabilityPeriod = new SectionAvailabilityPeriod();
                                $sectionAvailabilityPeriod->section_availability_id = $sectionAvailability->id;
                                $sectionAvailabilityPeriod->start_time = $timePeriod["start_time"];
                                $sectionAvailabilityPeriod->end_time = $timePeriod["end_time"];

                                /*Configuración para autocashier*/
                                if ($timePeriod["end_time"] < $timePeriod["start_time"]) {
                                    $day = $period["day"];
                                    if ($day + 1 >= 8) {
                                        $day = 1;
                                    } else {
                                        $day = $day + 1;
                                    }

                                    $sectionAvailabilityPeriod->end_day = $day;
                                } else {
                                    $sectionAvailabilityPeriod->end_day = null;
                                }

                                $sectionAvailabilityPeriod->save();
                            }
                        }
                    }
                    return response()->json([
                        "status" => "Menú creado exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR CREAR MENU, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo crear este menú',
                'results' => null,
            ], 409);
        }
    }

    public function getSection($id)
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

        $section = Section::select(
            'id',
            'store_id',
            'name'
        )
            ->where('id', $id)
            ->where('store_id', $sectionStore->id)
            ->withTrashed()
            ->with([
                'availabilities' => function ($availability) {
                    $availability->select(
                        'id',
                        'section_id',
                        'day'
                    )->with([
                        'periods' => function ($period) {
                            $period->select(
                                'id',
                                'section_availability_id',
                                'start_time',
                                'end_time'
                            );
                        }
                    ])
                        ->orderBy('day');
                }
            ])
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'Este menú no existe',
                'results' => null,
            ], 409);
        }

        unset($section["store_id"]);
        $newAvailabilities = $this->APIPeriods($section->availabilities);
        unset($section["availabilities"]);
        $section["availabilities"] = $newAvailabilities;
        foreach ($section->availabilities as &$availability) {
            unset($availability["section_id"]);
            unset($availability["deleted_at"]);
            foreach ($availability["periods"] as &$period) {
                unset($period["section_availability_id"]);
            }
        }

        return response()->json([
            "status" => "Menú",
            "results" => $section,
        ], 200);
    }

    public function update(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;

        $sectionRequest = $request->section;

        $sectionStore = Store::whereHas('sections', function ($query) use ($sectionRequest) {
            return $query->where('id', $sectionRequest["id"]);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $anotherSection = Section::where('name', $request->name)
            ->where('id', '!=', $sectionRequest["id"])
            ->where('store_id', $sectionStore->id)
            ->withTrashed()
            ->first();

        if ($anotherSection) {
            return response()->json([
                'status' => 'El nombre de este menú ya se está utilizando para otro menú de esta tienda',
                'results' => null,
            ], 409);
        }

        if ($sectionRequest == null) {
            return response()->json([
                'status' => 'No se ha enviado la información del menú',
                'results' => null,
            ], 409);
        }

        $sectionExist = Section::where('id', $sectionRequest["id"])
            ->where('store_id', $sectionStore->id)
            ->withTrashed()
            ->first();

        if (!$sectionExist) {
            return response()->json([
                'status' => 'Este menú no existe para esta tienda!',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($sectionRequest, $sectionExist) {
                    $sectionExist->name = $sectionRequest["name"];
                    $sectionExist->save();
                    // Modificando horarios
                    $periods = $sectionRequest["availabilities"];
                    foreach ($periods as $period) {
                        $day = $period["day"];
                        $timePeriods = $period["periods"];
                        $sectionAvailability = SectionAvailability::where(
                            'section_id',
                            $sectionExist->id
                        )
                            ->where('day', $day)
                            ->first();
                        if ($sectionAvailability == null) {
                            $sectionAvailability = new SectionAvailability();
                            $sectionAvailability->section_id = $sectionExist->id;
                            $sectionAvailability->day = $day;
                            $sectionAvailability->save();
                        }
                        $sectionAvailability->periods()->delete();

                        if (count($timePeriods) > 0) {
                            foreach ($timePeriods as $timePeriod) {
                                $sectionAvailabilityPeriod = new SectionAvailabilityPeriod();
                                $sectionAvailabilityPeriod->section_availability_id = $sectionAvailability->id;
                                $sectionAvailabilityPeriod->start_time = $timePeriod["start_time"];
                                $sectionAvailabilityPeriod->end_time = $timePeriod["end_time"];

                                /*Configuración para autocashier*/
                                if ($timePeriod["end_time"] < $timePeriod["start_time"]) {
                                    $day = $period["day"];
                                    if ($day + 1 >= 8) {
                                        $day = 1;
                                    } else {
                                        $day = $day + 1;
                                    }

                                    $sectionAvailabilityPeriod->end_day = $day;
                                } else {
                                    $sectionAvailabilityPeriod->end_day = null;
                                }

                                $sectionAvailabilityPeriod->save();
                            }
                        } else {
                            $sectionAvailability->delete();
                        }
                    }

                    return response()->json([
                        "status" => "Menú modificado exitosamente",
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR MODIFICAR MENU, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => $e->getMessage(),
                'results' => null,
            ], 409);
        }
    }

    public function changeSectionTarget(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $sectionStore = Store::whereHas('sections', function ($query) use ($request) {
            return $query->where('id', $request->id_section);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $section = Section::where('id', $request->id_section)
            ->where('store_id', $sectionStore->id)
            ->with([
                'integrations',
                'availabilities.periods'
            ])
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'El menú que deseas cambiar no existe o está oculto',
                'results' => null,
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($section, $request) {
                    $action = $request->action;
                    $status = "";
                    if ($action == 1) {
                        $status = $this->enableMenuTarget($request->target, $section);
                    } else {
                        $status = $this->disableMenuTarget($request->target, $section);
                    }
                    return response()->json([
                        "status" => $status,
                        "results" => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR MENU OBJETIVO, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => $e->getMessage(),
                'results' => null,
            ], 409);
        }
    }

    public function enableMenuTarget($target, $section)
    {
        if ($target == $this::TARGET_LOCAL) {
            if ($section->is_main != 1) {
                $section->is_main = 1;
                $section->save();
            }
            $status = "Menú habilitado exitosamente para uso en el local";
        } elseif ($target == $this::TARGET_UBER) {
            $uberData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_EATS
            )->first();
            if ($uberData == null) {
                throw new \Exception('No existe información de la integración de Uber Eats');
            } else {
                $integrations = $section->integrations;
                $integrationUber = $integrations->firstWhere('integration_id', $uberData->id);
                if ($integrationUber == null) {
                    if (count($section->availabilities) == 0) {
                        throw new \Exception('Este menú no tiene horarios');
                    } else {
                        $notHasPeriods = true;
                        foreach ($section->availabilities as $availability) {
                            if (count($availability->periods) != 0) {
                                $notHasPeriods = false;
                                break;
                            }
                        }
                        if ($notHasPeriods) {
                            throw new \Exception('Este menú no tiene horarios');
                        }
                    }
                    $integrationUber = new SectionIntegration();
                    $integrationUber->section_id = $section->id;
                    $integrationUber->integration_id = $uberData->id;
                    $integrationUber->save();
                }
                $this->createIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_EATS);
                $status = "Menú habilitado exitosamente para uso en Uber Eats";
            }
        } elseif ($target == $this::TARGET_RAPPI) {
            $rappiData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_RAPPI
            )->first();
            if ($rappiData == null) {
                throw new \Exception('No existe información de la integración de Uber Eats');
            } else {
                $integrations = $section->integrations;
                $integrationRappi = $integrations->firstWhere('integration_id', $rappiData->id);
                if ($integrationRappi == null) {
                    $integrationRappi = new SectionIntegration();
                    $integrationRappi->section_id = $section->id;
                    $integrationRappi->integration_id = $rappiData->id;
                    $integrationRappi->save();
                }
                $this->createIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_RAPPI);
                $status = "Menú habilitado exitosamente para uso en Rappi";
            }
        } elseif ($target == $this::TARGET_DIDI) {
            $didiData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_DIDI
            )->first();
            if ($didiData == null) {
                throw new \Exception('No existe información de la integración de Didi Food');
            } else {
                $integrations = $section->integrations;
                $integrationDidi = $integrations->firstWhere('integration_id', $didiData->id);
                if ($integrationDidi == null) {
                    if (count($section->availabilities) == 0) {
                        throw new \Exception('Este menú no tiene horarios');
                    } else {
                        $notHasPeriods = true;
                        foreach ($section->availabilities as $availability) {
                            if (count($availability->periods) != 0) {
                                $notHasPeriods = false;
                                break;
                            }
                        }
                        if ($notHasPeriods) {
                            throw new \Exception('Este menú no tiene horarios');
                        }
                    }
                    $integrationDidi = new SectionIntegration();
                    $integrationDidi->section_id = $section->id;
                    $integrationDidi->integration_id = $didiData->id;
                    $integrationDidi->save();
                }
                $this->createIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_DIDI);
                $status = "Menú habilitado exitosamente para uso en Didi Food";
            }
        } elseif ($target == $this::TARGET_ALOHA) {
            $alohaData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_ALOHA
            )->first();
            if ($alohaData == null) {
                throw new \Exception('No existe información de la integración de Aloha');
            } elseif (count($section->categories) != 0) {
                throw new \Exception('Sólo se puede activar la integración de Aloha en un menú vacío');
            } else {
                $integrations = $section->integrations;
                $integrationAloha = $integrations->firstWhere('integration_id', $alohaData->id);
                if ($integrationAloha == null) {
                    $integrationAloha = new SectionIntegration();
                    $integrationAloha->section_id = $section->id;
                    $integrationAloha->integration_id = $alohaData->id;
                    $integrationAloha->save();
                }
                $status = "Menú habilitado exitosamente para uso en Aloha";
            }
        } elseif ($target == $this::TARGET_IFOOD) {
            $ifoodData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_IFOOD
            )->first();
            if ($ifoodData == null) {
                throw new \Exception('No existe información de la integración de iFood');
            } else {
                $integrations = $section->integrations;
                $integrationIfood = $integrations->firstWhere('integration_id', $ifoodData->id);
                if ($integrationIfood == null) {
                    if (count($section->availabilities) == 0) {
                        throw new \Exception('Este menú no tiene horarios');
                    } else {
                        $notHasPeriods = true;
                        foreach ($section->availabilities as $availability) {
                            if (count($availability->periods) != 0) {
                                $notHasPeriods = false;
                                break;
                            }
                        }
                        if ($notHasPeriods) {
                            throw new \Exception('Este menú no tiene horarios');
                        }
                    }
                    $integrationIfood = new SectionIntegration();
                    $integrationIfood->section_id = $section->id;
                    $integrationIfood->integration_id = $ifoodData->id;
                    $integrationIfood->save();
                }
                $this->createIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_IFOOD);
                $status = "Menú habilitado exitosamente para uso en iFood";
            }
        } else {
            //validar si no es un integración mely.
            Log::info($target);
            $melyIntegration = AvailableMyposIntegration::whereNotNull('anton_integration')->where('id', $target)->first();
            if ($melyIntegration == null) {
                throw new \Exception('Objetivo inválido');
            } else {
                $integrations = $section->integrations;
                $sectionIntegrationMely = $integrations->firstWhere('integration_id', $melyIntegration->id);
                if ($sectionIntegrationMely == null) {
                    $sectionIntegrationMely = new SectionIntegration();
                    $sectionIntegrationMely->section_id = $section->id;
                    $sectionIntegrationMely->integration_id = $melyIntegration->id;
                    $sectionIntegrationMely->save();
                }
                $this->createIntegrationDataMenu($section->id, $melyIntegration->code_name);
                $status = "Menú habilitado exitosamente para uso en Third Party";
            }
        }
        return $status;
    }

    public function disableMenuTarget($target, $section)
    {
        if ($target == $this::TARGET_LOCAL) {
            $mainSections = Section::where('store_id', $section->store_id)
                ->where('is_main', 1)
                ->get();
            if (count($mainSections) == 1) {
                throw new \Exception('Se debe tener por lo menos 1 menú para uso en el local');
            } elseif ($section->is_main != 0) {
                $section->is_main = 0;
                $section->save();
            }
            $status = "Menú deshabilitado exitosamente para uso en el local";
        } elseif ($target == $this::TARGET_UBER) {
            $uberData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_EATS
            )->first();
            if ($uberData == null) {
                throw new \Exception('No existe información de la integración de Uber Eats');
            } else {
                $integrationUber = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $uberData->id)
                    ->first();
                if ($integrationUber != null) {
                    $integrationUber->delete();
                }
                $this->deleteIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_EATS);
                $status = "Menú deshabilitado exitosamente para uso en Uber Eats";
            }
        } elseif ($target == $this::TARGET_RAPPI) {
            $rappiData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_RAPPI
            )->first();
            if ($rappiData == null) {
                throw new \Exception('No existe información de la integración de Uber Eats');
            } else {
                $integrationRappi = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $rappiData->id)
                    ->first();
                if ($integrationRappi != null) {
                    $integrationRappi->delete();
                }
                $this->deleteIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_RAPPI);
                $status = "Menú deshabilitado exitosamente para uso en Rappi";
            }
        } elseif ($target == $this::TARGET_DIDI) {
            $uberData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_DIDI
            )->first();
            if ($uberData == null) {
                throw new \Exception('No existe información de la integración de Didi Food');
            } else {
                $integrationDidi = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $uberData->id)
                    ->first();
                if ($integrationDidi != null) {
                    $integrationDidi->delete();
                }
                $this->deleteIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_DIDI);
                $status = "Menú deshabilitado exitosamente para uso en Didi Food";
            }
        } elseif ($target == $this::TARGET_ALOHA) {
            $alohaData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_ALOHA
            )->first();
            if ($alohaData == null) {
                throw new \Exception('No existe información de la integración de Aloha');
            } else {
                $integrationAloha = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $alohaData->id)
                    ->first();
                if ($integrationAloha != null) {
                    $integrationAloha->delete();
                }
                $status = "Menú deshabilitado exitosamente para uso en Aloha";
            }
        } elseif ($target == $this::TARGET_IFOOD) {
            $iFoodData = AvailableMyposIntegration::where(
                'code_name',
                AvailableMyposIntegration::NAME_IFOOD
            )->first();
            if ($iFoodData == null) {
                throw new \Exception('No existe información de la integración de iFood');
            } else {
                $integrationIfood = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $iFoodData->id)
                    ->first();
                if ($integrationIfood != null) {
                    $integrationIfood->delete();
                }
                $this->deleteIntegrationDataMenu($section->id, AvailableMyposIntegration::NAME_IFOOD);
                $status = "Menú deshabilitado exitosamente para uso en iFood";
            }
        } else {
            $melyIntegration = AvailableMyposIntegration::whereNotNull("anton_integration")->where('id', $target)->first();
            if ($melyIntegration == null) {
                throw new \Exception('Objetivo inválido');
            } else {
                $sectionIntegrationMely = SectionIntegration::where('section_id', $section->id)
                    ->where('integration_id', $melyIntegration->id)
                    ->first();
                if ($sectionIntegrationMely != null) {
                    $sectionIntegrationMely->delete();
                }
                $this->deleteIntegrationDataMenu($section->id, $melyIntegration->code_name);
                $status = "Menú deshabilitado exitosamente para uso en Third Party";
            }
        }
        return $status;
    }

    public function createIntegrationDataMenu($sectionId, $codeName)
    {
        $sectionData = Section::where('id', $sectionId)
            ->with([
                'categories.products' => function ($product) {
                    $product->where('status', 1);
                },
                'specificationsCategories.specifications' => function ($specification) {
                    $specification->where('status', 1);
                }
            ])
            ->first();

        // Creando información de integración para cada categoría de especificación
        foreach ($sectionData->specificationsCategories as $category) {
            $specifications = $category->specifications;
            // Creando información de integración para cada especificación
            foreach ($specifications as $specification) {
                $toppingIntegration = ToppingIntegrationDetail::where(
                    'specification_id',
                    $specification->id
                )
                    ->where('integration_name', $codeName)
                    ->first();
                if (!$toppingIntegration) {
                    $toppingIntegration = new ToppingIntegrationDetail();
                    $toppingIntegration->specification_id = $specification->id;
                    $toppingIntegration->integration_name = $codeName;
                    $toppingIntegration->name = $specification->name;
                    $toppingIntegration->price = $specification->value;
                    $toppingIntegration->save();
                }
            }
        }


        // Creando información de integración para cada categoría de producto
        foreach ($sectionData->categories as $category) {
            $products = $category->products;
            // Creando información de integración para cada producto
            foreach ($products as $product) {
                $existProdInt = ProductIntegrationDetail::where('product_id', $product->id)
                    ->where('integration_name', $codeName)
                    ->first();
                $idProductIntegration = null;
                if ($existProdInt == null) {
                    $productIntegration = new ProductIntegrationDetail();
                    $productIntegration->product_id = $product->id;
                    $productIntegration->integration_name = $codeName;
                    $productIntegration->name = $product->name;
                    $productIntegration->price = $product->base_value;
                    $productIntegration->save();
                    $idProductIntegration = $productIntegration->id;
                } else {
                    $idProductIntegration = $existProdInt->id;
                }

                // Agregando relación entre producto y especificaciones en integración
                $specificationsProduct = ProductSpecification::where('product_id', $product->id)
                    ->where('status', 1)
                    ->get();
                foreach ($specificationsProduct as $specificationProduct) {
                    $toppingIntegration = ToppingIntegrationDetail::where(
                        'specification_id',
                        $specificationProduct->specification_id
                    )
                        ->where('integration_name', $codeName)
                        ->first();
                    if ($toppingIntegration != null) {
                        $toppingIntProduct = ProductToppingIntegration::where(
                            'product_integration_id',
                            $idProductIntegration
                        )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                        if ($toppingIntProduct == null) {
                            $toppingIntProduct = new ProductToppingIntegration();
                            $toppingIntProduct->product_integration_id = $idProductIntegration;
                            $toppingIntProduct->topping_integration_id = $toppingIntegration->id;
                            $toppingIntProduct->value = $specificationProduct->value;
                            $toppingIntProduct->save();
                        }
                    }
                }
            }
        }
    }

    public function deleteIntegrationDataMenu($sectionId, $codeName)
    {
        $sectionData = Section::where('id', $sectionId)
            ->with([
                'categories.products' => function ($product) {
                    $product->where('status', 1);
                },
                'specificationsCategories.specifications' => function ($specification) {
                    $specification->where('status', 1);
                }
            ])
            ->first();

        // Borrando información de integración para cada categoría de especificación
        foreach ($sectionData->specificationsCategories as $category) {
            $specifications = $category->specifications;
            // Borrando información de integración para cada especificación
            foreach ($specifications as $specification) {
                $toppingIntegration = ToppingIntegrationDetail::where(
                    'specification_id',
                    $specification->id
                )
                    ->where('integration_name', $codeName)
                    ->first();
                if ($toppingIntegration != null) {
                    $toppingIntegration->delete();
                }
            }
        }


        // Borrando información de integración para cada categoría de producto
        foreach ($sectionData->categories as $category) {
            $products = $category->products;
            // Borrando información de integración para cada producto
            foreach ($products as $product) {
                $productIntegration = ProductIntegrationDetail::where('product_id', $product->id)
                    ->where('integration_name', $codeName)
                    ->first();
                if ($productIntegration != null) {
                    $productIntegration->delete();
                }
            }
        }
    }

    public function hasConflictHoursUber($section, $uberEatsId)
    {
        $anotherUberMenus = Section::where('store_id', $section->store_id)
            ->where('id', '!=', $section->id)
            ->whereHas(
                'integrations',
                function ($integration) use ($uberEatsId) {
                    $integration->where('integration_id', $uberEatsId);
                }
            )
            ->with('availabilities.periods')
            ->get();
        $hasConflict = false;
        $originalSchedules = $section->availabilities;
        // Comparando con los otros sections
        foreach ($anotherUberMenus as $uberMenu) {
            // section availabilities a comparar para ver si hay conflictos
            $schedules = $uberMenu->availabilities;
            foreach ($originalSchedules as $originalSchedule) {
                $availability = $schedules->firstWhere('day', $originalSchedule->day);
                if ($availability != null) {
                    // Periods del section availability original
                    foreach ($originalSchedule->periods as $period) {
                        $originalStart = $period->start_time;
                        $originalEnd = $period->end_time;
                        // Periods del section availability a comparar
                        foreach ($availability->periods as $anotherPeriod) {
                            $anotherStart = $anotherPeriod->start_time;
                            $anotherEnd = $anotherPeriod->end_time;
                            // Horas de inicio o fin están entre las horas del otro horario
                            if (
                                $anotherStart <= $originalStart && $anotherEnd > $originalStart
                                || $anotherStart < $originalEnd && $anotherEnd >= $originalEnd
                            ) {
                                $hasConflict = true;
                                break 4;
                                // Hora de incio o fin del otro horario está entre las horas del horario original
                            } elseif (
                                $originalStart <= $anotherStart && $originalEnd > $anotherStart
                                || $originalStart < $anotherEnd && $originalEnd >= $anotherEnd
                            ) {
                                $hasConflict = true;
                                break 4;
                            }
                        }
                    }
                }
            }
        }
        return $hasConflict;
    }

    public function uploadUberMenu($isTesting)
    {
        $employee = $this->authEmployee;
        if (!$employee) {
            return response()->json([
                'status' => 'No autorizado',
                'results' => null,
            ], 401);
        }
        $status = $this->updateUberEatsMenuV2($employee, $isTesting == 1);
        return response()->json([
            "status" => $status["message"],
            "results" => null
        ], $status["code"]);
    }

    public function uploadMelyMenu(Request $request)
    {
        $store = $this->authStore;

        //si se trata de rappi, redireccionamos
        if ($request->integration['id'] == 2) {
            return $this->uploadRappiMenu($request, $store);
        }

        $status = MelyRequest::uploadMenu($store, $request->all());
        return response()->json([
            "status" => $status["message"],
            "results" => $status["data"]
        ], $status["code"]);
    }

    public function uploadRappiMenu($request, $store)
    {
        $status = MelyRequest::uploadRappiMenu($store, $request->all());
        return response()->json([
            "status" => $status["message"],
            "results" => $status["data"]
        ], $status["code"]);
    }

    public function uploadDidiMenu($isTesting)
    {
        $employee = $this->authEmployee;
        if (!$employee) {
            return response()->json([
                'status' => 'No autorizado',
                'results' => null,
            ], 401);
        }
        $status = $this->updateDidiFoodMenu($employee, $isTesting == 1);
        return response()->json([
            "status" => $status["message"],
            "results" => null
        ], $status["code"]);
    }

    public function uploadIFoodMenu($isTesting, $id)
    {
        $store = $this->authStore;

        // Verificar si el token ha caducado
        $storeToken = StoreIntegrationToken::where(
            'integration_name',
            AvailableMyposIntegration::NAME_IFOOD
        )
            ->where('store_id', $store->id)
            ->first();
        if (is_null($storeToken)) {
            return response()->json([
                'status' => 'Ingrese a la sección de configuración y coloque el id de la tienda en la sección de iFood. Acércate a tu ejecutivo de cuenta de iFood para obtener el id/uuid de la tienda y comunícalo a myPOS',
                'results' => null,
            ], 409);
        }

        // Verificar que exista el menú con integración iFood
        $integration = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_IFOOD)->first();
        $sectionIntegration = SectionIntegration::where(
            'integration_id',
            $integration->id
        )
            ->where('section_id', $id)
            ->first();
        if (is_null($sectionIntegration)) {
            return response()->json([
                'status' => "No existe este menú con la integración de iFood activada",
                'results' => null,
            ], 409);
        }

        $dataConfig = IfoodRequests::checkIfoodConfiguration($store);
        if ($dataConfig["code"] != 200) {
            return response()->json([
                'status' => $dataConfig["message"],
                'results' => null,
            ], 409);
        }

        $integrationConfig = $dataConfig["data"]["integrationConfig"];
        $store = Store::where('id', $integrationConfig->store_id)->first();
        $result = IfoodMenu::uploadCategories(
            $store,
            $dataConfig["data"]["integrationData"],
            $integrationConfig,
            $isTesting == 1,
            $id,
            $sectionIntegration
        );

        $sectionIntegration->synced = true;
        $sectionIntegration->save();

        return response()->json([
            "status" => $result["message"],
            "results" => null
        ], $result["code"]);
    }

    public function getIntegrations($id)
    {
        $store = $this->authStore;

        $section = Section::select(
            'id',
            'store_id',
            'name'
        )
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->withTrashed()
            ->with([
                'integrations' => function ($integrations) {
                    $integrations->select(
                        'id',
                        'section_id',
                        'integration_id'
                    )->with([
                        'integration' => function ($integration) {
                            $integration->select(
                                'id',
                                'code_name'
                            );
                        }
                    ]);
                }
            ])
            ->first();

        if (!$section) {
            return response()->json([
                'status' => 'Este menú no existe',
                'results' => null,
            ], 409);
        }

        return response()->json([
            "status" => "Integraciones",
            "results" => $section->integrations
        ], 200);
    }

    public function getStoresCompany()
    {
        $store = $this->authStore;
        $companyId = $store->company_id;

        $stores = Store::select(['id', 'name', 'company_id', 'address'])
            ->where('company_id', $companyId)
            ->where('id', '!=', $store->id)
            ->get();

        $storeOriginal = [
            "id" => $store->id,
            "name" => $store->name,
            "company_id" => $companyId,
            "address" => $store->address
        ];

        $stores->splice(0, 0, [$storeOriginal]);

        $countries = Country::whereHas('cities.stores', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        })->get();

        $plans = SubscriptionPlan::whereHas('subscriptions.store', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        })->get();

        return response()->json([
            "status" => "Tiendas de esta compañía",
            "results" => $stores,
            "plans" => $plans,
            "countries" => $countries
        ], 200);
    }

    public function getMenuDiscounts($sectionId)
    {
        $sectionDiscounts = SectionDiscount::select(['id', 'section_id', 'base_value', 'discount_value'])
            ->where('section_id', $sectionId)
            ->get();

        foreach ($sectionDiscounts as &$discount) {
            $discount->base_value = $discount->base_value / 100;
            $discount->discount_value = $discount->discount_value / 100;
        }

        return response()->json([
            "status" => "Descuentos",
            "results" => $sectionDiscounts
        ], 200);
    }

    public function updateMenuDiscounts(Request $request)
    {
        $store = $this->authStore;

        try {
            $operationJSON = DB::transaction(
                function () use ($request) {
                    $sectionDiscounts = SectionDiscount::select(['id', 'section_id', 'base_value', 'discount_value'])
                        ->where('section_id', $request->section_id)
                        ->get();


                    $requestDiscounts = collect($request->discounts);

                    $discountIds = $sectionDiscounts->pluck('id')->all();
                    $requestDiscountIds = $requestDiscounts->pluck('id')->all();

                    // Obteniendo los descuentos borrados en frontend
                    $deleted = array_diff($discountIds, $requestDiscountIds);

                    // Borrar los descuentos, borrados en frontend
                    foreach ($deleted as $discountId) {
                        $discount = $sectionDiscounts->where('id', $discountId)->first();
                        if ($discount != null) {
                            $discount->delete();
                        }
                    }

                    foreach ($requestDiscounts as $discount) {
                        if ($discount["discount_value"] != 0) {
                            $discountMenu;
                            if (strpos($discount["id"], "new") !== false) {
                                // Descuento no existe, creo uno nuevo
                                $discountMenu = new SectionDiscount();
                                $discountMenu->section_id = $request->section_id;
                            } else {
                                $discountMenu = $sectionDiscounts->where('id', $discount["id"])->first();
                                if ($discountMenu == null) {
                                    $discountMenu = new SectionDiscount();
                                    $discountMenu->section_id = $request->section_id;
                                }
                            }
                            $discountMenu->base_value = $discount["base_value"] * 100;
                            $discountMenu->discount_value = $discount["discount_value"] * 100;
                            $discountMenu->save();
                        }
                    }

                    return response()->json([
                        'status' => 'Descuentos del menú actualizados',
                        'results' => null,
                    ], 200);
                }
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->logError(
                "SectionController API Store: ERROR GUARDAR DESCUENTOS, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo guardar estos descuentos',
                'results' => null,
            ], 409);
        }
    }

    public function importIFoodMenu($id)
    {
        $employee = $this->authEmployee;
        if (!$employee) {
            return response()->json([
                'status' => 'No autorizado',
                'results' => null,
            ], 401);
        }
        $dataConfig = IfoodRequests::checkIfoodConfiguration($employee->store);
        if ($dataConfig["code"] != 200) {
            return response()->json([
                'status' => $dataConfig["message"],
                'results' => null,
            ], 409);
        }

        $integrationConfig = $dataConfig["data"]["integrationConfig"];
        $store = Store::where('id', $integrationConfig->store_id)->first();
        $result = IfoodMenu::importIFoodMenuFromEndpoint(
            $store,
            $dataConfig["data"]["integrationData"],
            $dataConfig["data"]["integrationToken"],
            $integrationConfig,
            $id
        );

        return response()->json([
            "status" => $result["message"],
            "results" => null
        ], $result["code"]);
    }
}
