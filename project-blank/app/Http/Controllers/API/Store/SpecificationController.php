<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\ProductCategory;
use App\Company;
use App\Employee;
use App\Store;
use App\Traits\GeoProcedures;
use Illuminate\Http\Request;
use Log;
use Auth;
use App\Traits\ValidateToken;
use App\Helper;
use App\Traits\AuthTrait;
use App\Traits\FranchiseHelper;
use Carbon\Carbon;
use App\Specification;
use App\SpecificationCategory;
use App\SpecificationComponent;
use App\ProductSpecification;
use Illuminate\Support\Facades\DB;
use App\ToppingIntegrationDetail;
use App\AvailableMyposIntegration;
use App\ProductIntegrationDetail;
use App\ProductToppingIntegration;
use App\ProductSpecificationComponent;
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\IFood\UploadIfoodGroupModifierJob;
use App\Jobs\IFood\UpdateIfoodGroupModifierJob;
use App\Jobs\IFood\UploadIfoodItemJob;
use App\Jobs\IFood\UpdateIfoodItemJob;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\iFood\IfoodMenu;
use App\Traits\iFood\IfoodRequests;

class SpecificationController extends Controller
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
        $user = $this->authUser;
        $store = $this->authStore;
        $data = $request->data;
        $sectionId = $request->section_id;

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

        $specificationCategory = SpecificationCategory::where('name', $data["name"])
            ->where('section_id', $sectionId)
            ->where('company_id', $sectionStore->company_id)
            ->get();
        if (count($specificationCategory) > 0) {
            return response()->json([
                'status' => 'Esta especificación ya existe',
                'results' => null
            ], 409);
        }
        try {
            $specificationJSON = DB::transaction(
                function () use ($data, $sectionStore, $sectionId) {
                    $specificationCategory = new SpecificationCategory();
                    $specificationCategory->name = $data["name"];
                    $specificationCategory->subtitle = '';
                    $specificationCategory->status = 1;
                    $specificationCategory->company_id = $sectionStore->company_id;
                    $priority = 0;
                    $lastSpecificationCategory = DB::table('specification_categories')
                        ->where('section_id', $sectionId)
                        ->latest('priority')
                        ->first();
                    if ($lastSpecificationCategory) {
                        $priority = $lastSpecificationCategory->priority + 1;
                    }
                    $specificationCategory->priority = $priority;
                    if ((int)$data["is_size_type"] == 1) {
                        $specificationCategory->type = 2;
                        $specificationCategory->show_quantity = 0;
                        $specificationCategory->max = 1;
                    } else {
                        $specificationCategory->type = 1;
                        $specificationCategory->show_quantity = (int)$data["checked_show_quantity"];
                        if ((int)$data["checked_one_option"] == 1) {
                            $specificationCategory->max = 1;
                        } else {
                            if ($data["max_options"] == null) {
                                $specificationCategory->max = count($data["options"]);
                            } else {
                                $specificationCategory->max = $data["max_options"];
                            }
                        }
                    }
                    if ((int)$data["is_required"] == 1) {
                        $specificationCategory->required = 1;
                    }
                    $specificationCategory->created_at = Carbon::now()->toDateTimeString();
                    $specificationCategory->updated_at = Carbon::now()->toDateTimeString();
                    $specificationCategory->section_id = $sectionId;
                    $specificationCategory->save();

                    $dataConfig = IfoodRequests::checkIfoodConfiguration($sectionStore);
                    $updateIfood = true;
                    $client = $browser = $channelLog = $channelSlackDev = $baseUrl = null;
                    $iFoodJobs = [];
                    if ($dataConfig["code"] != 200) {
                        $updateIfood = false;
                    } else {
                        $client = new FileGetContents(new Psr17Factory());
                        $browser = new Browser($client, new Psr17Factory());
                        $channelLog = "ifood_logs";
                        $channelSlackDev = "#integration_logs_details";
                        $baseUrl = config('app.ifood_url_api');

                        // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                        $countOptionsSpec = count($data["options"]);
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
                            "externalCode" => $specificationCategory->id,
                            "name" => $specificationCategory->name,
                            "sequence" => $specificationCategory->priority,
                            "maxQuantity" => $maxPermitted,
                            "minQuantity" => $minPermitted,
                        ];

                        array_push(
                            $iFoodJobs,
                            (new UploadIfoodGroupModifierJob(
                                $sectionStore,
                                $dataConfig["data"]["integrationConfig"]->external_store_id,
                                $sectionStore->name,
                                $modifiersGroupObject,
                                $channelLog,
                                $channelSlackDev,
                                $baseUrl,
                                $browser
                            ))->delay(5)
                        );
                    }

                    foreach ($data["options"] as $index => $option) {
                        $specification = new Specification();
                        $specification->name = $option["nameOpt"];
                        $specification->value = $option["value"];
                        $specification->specification_category_id = $specificationCategory->id;
                        $specification->status = 1;
                        $specification->created_at = Carbon::now()->toDateTimeString();
                        $specification->updated_at = Carbon::now()->toDateTimeString();
                        $specification->priority = $index;
                        $specification->save();

                        if ($updateIfood) {
                            // Creación de opciones
                            $itemObject = [
                                [
                                    "name" => "sku",
                                    "contents" => json_encode(
                                        [
                                            "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                                            "availability" => "AVAILABLE",
                                            "externalCode" => $specification->id,
                                            "name" => $specification->name,
                                            "description" => '',
                                            "order" => $specification->priority,
                                            "price" => [
                                                "promotional" => false,
                                                "originalValue" => 0,
                                                "value" => $specification->value / 100
                                            ]
                                        ]
                                    )
                                ]
                            ];
                            array_push(
                                $iFoodJobs,
                                (new UploadIfoodItemJob(
                                    $sectionStore,
                                    $dataConfig["data"]["integrationConfig"]->external_store_id,
                                    $sectionStore->name,
                                    $itemObject,
                                    $specificationCategory->id,
                                    true,
                                    $channelLog,
                                    $channelSlackDev,
                                    $baseUrl,
                                    $browser
                                ))->delay(5)
                            );
                        }

                        foreach ($option["itemsSpecifications"] as $item) {
                            $specificationComponent = new SpecificationComponent();
                            $specificationComponent->specification_id = $specification->id;
                            $specificationComponent->component_id = $item["id"];
                            $specificationComponent->created_at = Carbon::now()->toDateTimeString();
                            $specificationComponent->updated_at = Carbon::now()->toDateTimeString();
                            $specificationComponent->consumption = $item["product_components"][0]["consumption"];
                            $specificationComponent->save();
                        }
                    }

                    EmptyJob::withChain($iFoodJobs)->dispatch();

                    return response()->json([
                        "status" => "Especificación creada con éxito",
                        "results" => null
                    ], 200);
                }
            );
            return $specificationJSON;
        } catch (\Exception $e) {
            Log::info("SpecificationController API V1: NO SE PUDO GUARDAR LA ESPECIFICACION");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo crear la especificación',
                'results' => null
            ], 409);
        }
    }

    public function update(Request $request)
    {

        $user = $this->authUser;
        $store = $this->authStore;
        $data = $request->data;

        $isAdminFranchise = $user->isAdminFranchise();
        $sectionStore = Store::whereHas('sections', function ($query) use ($data) {
            return $query->where('id', $data["section_id"]);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($sectionStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }
        
        $secionIsNull = $data["section_id"] != null;
        $specificationCategoryExist = SpecificationCategory::where('name', $data["name"])
            ->when($secionIsNull, function ($query, $data) {
                return $query->where('section_id', $data["section_id"]);
            })
            ->where('company_id', $sectionStore->company_id)
            ->where('id', "!=", $data["id"])
            ->get();
        if (count($specificationCategoryExist) > 0) {
            return response()->json([
                'status' => 'Este nombre ya existe para otra categoría de especificación',
                'results' => null
            ], 409);
        }

        $specificationCategory = SpecificationCategory::where('id', $data["id"])
            ->with(
                [
                    'specifications.components.variation.unit',
                    'specifications.components.variation'
                ]
            )
            ->first();
        if ($specificationCategory) {
            try {
                $processJSON = DB::transaction(
                    function () use ($data, $sectionStore, $specificationCategory) {
                        $specificationCategory->name = $data["name"];
                        if ((int)$data["type"] == 2) {
                            $specificationCategory->type = 2;
                            $specificationCategory->show_quantity = 0;
                            $specificationCategory->max = 1;
                        } else {
                            $specificationCategory->type = 1;
                            $specificationCategory->show_quantity = (int)$data["show_quantity"];
                            $specificationCategory->max = $data["max"];
                        }
                        $specificationCategory->updated_at = Carbon::now()->toDateTimeString();
                        $specificationCategory->required = (int)$data["required"];
                        $specificationCategory->save();

                        $dataConfig = IfoodRequests::checkIfoodConfiguration($sectionStore);
                        $updateIfood = true;
                        $client = $browser = $channelLog = $channelSlackDev = $baseUrl = null;
                        $iFoodJobs = [];
                        if ($dataConfig["code"] != 200) {
                            $updateIfood = false;
                        } else {
                            $client = new FileGetContents(new Psr17Factory());
                            $browser = new Browser($client, new Psr17Factory());
                            $channelLog = "ifood_logs";
                            $channelSlackDev = "#integration_logs_details";
                            $baseUrl = config('app.ifood_url_api');

                            // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                            $countOptionsSpec = count($data["specifications"]);
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
                                "name" => $specificationCategory->name,
                                "sequence" => $specificationCategory->priority,
                                "maxQuantity" => $maxPermitted,
                                "minQuantity" => $minPermitted,
                            ];

                            array_push(
                                $iFoodJobs,
                                (new UpdateIfoodGroupModifierJob(
                                    $dataConfig["data"]["integrationToken"]->token,
                                    $sectionStore->name,
                                    $modifiersGroupObject,
                                    $specificationCategory->id,
                                    $channelLog,
                                    $channelSlackDev,
                                    $baseUrl,
                                    $browser
                                ))->delay(5)
                            );
                        }
                        
                        // Soft Delete Specifications borrados en frontend
                        $oldSpecifications = $specificationCategory->specifications;
                        foreach ($oldSpecifications as $oldElement) {
                            $deleted = true;
                            $idOldElement = (string) $oldElement->id;
                            foreach ($data["specifications"] as $specification) {
                                $idRequestSpec = (string) $specification["id"];
                                if ($idOldElement == $idRequestSpec) {
                                    $deleted = false;
                                    break;
                                }
                            }
                            if ($deleted) {
                                $specificationDB = Specification::where(
                                    'specification_category_id',
                                    $specificationCategory->id
                                )
                                ->where('id', $oldElement->id)
                                ->first();
                                if ($specificationDB) {
                                    $specificationDB->status = 0;
                                    $specificationDB->updated_at = Carbon::now()->toDateTimeString();
                                    $specificationDB->save();
                                    $specificationDB->delete();
                                    // Soft Delete Specifications Products de esta especificación
                                    $productSpecifications = ProductSpecification::where(
                                        'specification_id',
                                        $specificationDB->id
                                    )
                                    ->where('status', 1)
                                    ->get();
                                    foreach ($productSpecifications as $productSpecification) {
                                        $productSpecification->status = 0;
                                        $productSpecification->updated_at = Carbon::now()->toDateTimeString();
                                        $productSpecification->save();
                                        $productSpecComponents = ProductSpecificationComponent::where(
                                            "prod_spec_id",
                                            $productSpecification->id
                                        )->get();
                                        foreach ($productSpecComponents as $productSpecComponent) {
                                            $productSpecComponent->delete();
                                        }
                                    }
                                }
                            }
                        }

                        // Soft Delete Specifications Items borrados en frontend
                        foreach ($data["specifications"] as $specification) {
                            if (strpos($specification["id"], "new") === false) {
                                $idComponents = [];
                                foreach ($specification["components"] as $component) {
                                    if (isset($component["specification_id"])) {
                                        array_push($idComponents, $component["id"]);
                                    }
                                }
                                $deletedSpecComponents = SpecificationComponent::whereNotIn('id', $idComponents)
                                ->where(
                                    'specification_id',
                                    $specification["id"]
                                )
                                ->where('status', 1)
                                ->get();
                                foreach ($deletedSpecComponents as $deletedSpecComponent) {
                                    $deletedSpecComponent->status = 0;
                                    $deletedSpecComponent->updated_at = Carbon::now()->toDateTimeString();
                                    $deletedSpecComponent->save();
                                    // Soft delete de ProductSpecificationComponent
                                    // porque borro el SpecificationComponent
                                    $productSpecifications = ProductSpecification::where(
                                        'specification_id',
                                        $deletedSpecComponent->specification_id
                                    )
                                    ->where('status', 1)
                                    ->get();
                                    foreach ($productSpecifications as $productSpecification) {
                                        $productSpecComponent = ProductSpecificationComponent::where(
                                            "prod_spec_id",
                                            $productSpecification->id
                                        )
                                        ->where(
                                            "component_id",
                                            $deletedSpecComponent->component_id
                                        )->first();
                                        if ($productSpecComponent) {
                                            $productSpecComponent->delete();
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Obteniendo todos los productos que tienen una especificación dentro de este grupo
                        $specificationValid = Specification::where(
                            'specification_category_id',
                            $specificationCategory->id
                        )
                        ->first();
                        $productsWithThisSpecification = null;
                        if ($specificationValid !== null) {
                            $productsWithThisSpecification = ProductSpecification::where(
                                'specification_id',
                                $specificationValid->id
                            )
                            ->where('status', 1)
                            ->get();
                        }

                        foreach ($data["specifications"] as $index => $specification) {
                            $posNewId = strpos($specification["id"], "new");
                            if ($posNewId === false) {
                                $specificationUpdate = Specification::where('id', $specification["id"])
                                                                ->first();
                                if ($specificationUpdate) {
                                    $specificationUpdate->name = $specification["name"];
                                    $specificationUpdate->value = $specification["value"];
                                    $specificationUpdate->updated_at = Carbon::now()->toDateTimeString();
                                    $specificationUpdate->priority = $index;
                                    $specificationUpdate->save();

                                    if ($updateIfood) {
                                        // Creación de opciones
                                        $itemObject = [
                                            [
                                                "name" => "sku",
                                                "contents" => json_encode(
                                                    [
                                                        "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                                                        "externalCode" => $specificationUpdate->id,
                                                        "name" => $specificationUpdate->name,
                                                        "description" => '',
                                                        "order" => $specificationUpdate->priority,
                                                        "price" => [
                                                            "promotional" => false,
                                                            "originalValue" => 0,
                                                            "value" => $specificationUpdate->value / 100
                                                        ]
                                                    ]
                                                )
                                            ]
                                        ];
                                        array_push(
                                            $iFoodJobs,
                                            (new UpdateIfoodItemJob(
                                                $dataConfig["data"]["integrationToken"]->token,
                                                $dataConfig["data"]["integrationConfig"]->external_store_id,
                                                $sectionStore->name,
                                                $itemObject,
                                                $channelLog,
                                                $channelSlackDev,
                                                $baseUrl,
                                                $browser
                                            ))->delay(5)
                                        );
                                    }

                                    $productSpecifications = ProductSpecification::
                                                                    where('specification_id', $specificationUpdate->id)
                                                                    ->where('status', 1)
                                                                    ->get();
                                    foreach ($productSpecifications as $productSpecification) {
                                        $productSpecification->value = $specification["value"];
                                        $productSpecification->updated_at = Carbon::now()->toDateTimeString();
                                        $productSpecification->save();
                                    }

                                    // Agregando estas nuevas specs a los productos con integraciones
                                    if ($productsWithThisSpecification !== null) {
                                        foreach ($productSpecifications as $productSpecification) {
                                            $existProductIntegration = ProductIntegrationDetail::where(
                                                'product_id',
                                                $productSpecification->product_id
                                            )
                                            ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                                            ->first();
                                            $idProductIntegration = null;
                                            if ($existProductIntegration) {
                                                $toppingIntegration = ToppingIntegrationDetail::where(
                                                    'specification_id',
                                                    $specificationUpdate->id
                                                )
                                                ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                                                ->first();
                                                if (!$toppingIntegration) {
                                                    $toppingIntegration = new ToppingIntegrationDetail();
                                                    $toppingIntegration->specification_id =
                                                        $specificationUpdate->id;
                                                    $toppingIntegration->integration_name =
                                                        AvailableMyposIntegration::NAME_EATS;
                                                    $toppingIntegration->name = $specificationUpdate->name;
                                                    $toppingIntegration->price = $specificationUpdate->value;
                                                    $toppingIntegration->save();
                                                }
                                                $toppingIntProduct = ProductToppingIntegration::where(
                                                    'product_integration_id',
                                                    $existProductIntegration->id
                                                )
                                                ->where('topping_integration_id', $toppingIntegration->id)
                                                ->first();
                                                if (!$toppingIntProduct) {
                                                    $toppingIntProduct = new ProductToppingIntegration();
                                                    $toppingIntProduct->product_integration_id =
                                                    $existProductIntegration->id;
                                                    $toppingIntProduct->topping_integration_id =
                                                        $toppingIntegration->id;
                                                }
                                                $toppingIntProduct->value = $specificationUpdate->value;
                                                $toppingIntProduct->save();
                                            }
                                        }
                                    }
                                }
                                foreach ($specification["components"] as $component) {
                                    if (isset($component["specification_id"])) {
                                        $specificationCompUpdate = SpecificationComponent::where(
                                            'specification_id',
                                            $component["specification_id"]
                                        )->where('component_id', $component["component_id"])
                                        ->where('status', 1)
                                        ->first();
                                        if ($specificationCompUpdate) {
                                            $specificationCompUpdate->consumption = $component["consumption"];
                                            $specificationCompUpdate->updated_at =
                                                Carbon::now()->toDateTimeString();
                                            $specificationCompUpdate->save();
                                            // Actualizo el consumo de ProductSpecificationComponent
                                            // dependiendo del valor ingresado en EditSpecification
                                            $productSpecifications = ProductSpecification::where(
                                                'specification_id',
                                                $specificationCompUpdate->specification_id
                                            )->where('status', 1)
                                            ->get();
                                            foreach ($productSpecifications as $productSpecification) {
                                                $productSpecComponent = ProductSpecificationComponent::where(
                                                    "prod_spec_id",
                                                    $productSpecification->id
                                                )
                                                ->where(
                                                    "component_id",
                                                    $specificationCompUpdate->component_id
                                                )->first();
                                                if ($productSpecComponent) {
                                                    // Si el consumo de la variación a partir de la especificación
                                                    // del producto es 0 o el nuevo valor de consumo es distinto de
                                                    // cero sobreescribe el consumo anterior para evitar se reseteen
                                                    // a 0 consumos personalizados en los productos
                                                    if ($productSpecComponent->consumption == 0
                                                        || $specificationCompUpdate->consumption != 0
                                                    ) {
                                                        $productSpecComponent->consumption =
                                                            $specificationCompUpdate->consumption;
                                                        $productSpecComponent->save();
                                                    }
                                                } else {
                                                    $productSpecComponent = new ProductSpecificationComponent();
                                                    $productSpecComponent->component_id =
                                                        $specificationCompUpdate->component_id;
                                                    $productSpecComponent->prod_spec_id =
                                                        $productSpecification->id;
                                                    $productSpecComponent->consumption =
                                                        $specificationCompUpdate->consumption;
                                                    $productSpecComponent->save();
                                                }
                                            }
                                        }
                                    } else {
                                        // Agregó un component a consumir
                                        $specificationCompNew = new SpecificationComponent();
                                        $specificationCompNew->specification_id = $specificationUpdate->id;
                                        $specificationCompNew->component_id =
                                            $component["id"];
                                        $specificationCompNew->created_at = Carbon::now()->toDateTimeString();
                                        $specificationCompNew->updated_at = Carbon::now()->toDateTimeString();
                                        $specificationCompNew->consumption = $component["consumption"];
                                        $specificationCompNew->save();
                                        // Creo un nuevo ProductSpecificationComponent
                                        // que fue agregado en EditSpecification
                                        $productSpecifications = ProductSpecification::where(
                                            'specification_id',
                                            $specificationCompNew->specification_id
                                        )->where('status', 1)
                                        ->get();
                                        foreach ($productSpecifications as $productSpecification) {
                                            $productSpecComponent = new ProductSpecificationComponent();
                                            $productSpecComponent->component_id =
                                                $specificationCompNew->component_id;
                                            $productSpecComponent->prod_spec_id =
                                                $productSpecification->id;
                                            $productSpecComponent->consumption =
                                                $specificationCompNew->consumption;
                                            $productSpecComponent->save();
                                        }
                                    }
                                }
                            } else {
                                // Agregó una especificación
                                $specificationNew = new Specification();
                                $specificationNew->specification_category_id = $specificationCategory->id;
                                $specificationNew->name = $specification["name"];
                                $specificationNew->value = $specification["value"];
                                $specificationNew->status = 1;
                                $specificationNew->created_at = Carbon::now()->toDateTimeString();
                                $specificationNew->updated_at = Carbon::now()->toDateTimeString();
                                $specificationNew->priority = $index;
                                $specificationNew->save();

                                if ($updateIfood) {
                                    // Creación de opciones
                                    $itemObject = [
                                        [
                                            "name" => "sku",
                                            "contents" => json_encode(
                                                [
                                                    "merchantId" => $dataConfig["data"]["integrationConfig"]->external_store_id,
                                                    "availability" => "AVAILABLE",
                                                    "externalCode" => $specificationNew->id,
                                                    "name" => $specificationNew->name,
                                                    "description" => '',
                                                    "order" => $specificationNew->priority,
                                                    "price" => [
                                                        "promotional" => false,
                                                        "originalValue" => 0,
                                                        "value" => $specificationNew->value / 100
                                                    ]
                                                ]
                                            )
                                        ]
                                    ];
                                    array_push(
                                        $iFoodJobs,
                                        (new UploadIfoodItemJob(
                                            $sectionStore,
                                            $dataConfig["data"]["integrationConfig"]->external_store_id,
                                            $sectionStore->name,
                                            $itemObject,
                                            $specificationCategory->id,
                                            true,
                                            $channelLog,
                                            $channelSlackDev,
                                            $baseUrl,
                                            $browser
                                        ))->delay(5)
                                    );
                                }

                                // Asignando a los productos esta nueva especificación
                                if ($productsWithThisSpecification !== null) {
                                    foreach ($productsWithThisSpecification as $productSpecification) {
                                        $prodSpec = new ProductSpecification();
                                        $prodSpec->product_id = $productSpecification->product_id;
                                        $prodSpec->specification_id = $specificationNew->id;
                                        $prodSpec->value = $specificationNew->value;
                                        $prodSpec->status = 1;
                                        $prodSpec->save();
                                    }
                                }
                                $productSpecifications = ProductSpecification::where(
                                    'specification_id',
                                    $specificationNew->specification_id
                                )->where('status', 1)
                                ->get();
                                foreach ($specification["components"] as $component) {
                                    $specificationCompNew = new SpecificationComponent();
                                    $specificationCompNew->specification_id = $specificationNew->id;
                                    $specificationCompNew->component_id =
                                        $component["id"];
                                    $specificationCompNew->created_at = Carbon::now()->toDateTimeString();
                                    $specificationCompNew->updated_at = Carbon::now()->toDateTimeString();
                                    $specificationCompNew->consumption = $component["consumption"];
                                    $specificationCompNew->save();
                                    // Creo un nuevo ProductSpecificationComponent
                                    // debido a una nueva especificación
                                    // que fue agregada en EditSpecification
                                    foreach ($productSpecifications as $productSpecification) {
                                        $productSpecComponent = new ProductSpecificationComponent();
                                        $productSpecComponent->component_id =
                                            $specificationCompNew->component_id;
                                        $productSpecComponent->prod_spec_id =
                                            $productSpecification->id;
                                        $productSpecComponent->consumption =
                                            $specificationCompNew->consumption;
                                        $productSpecComponent->save();
                                    }
                                }

                                // Agregando estas nuevas specs a los productos con integraciones
                                if ($productsWithThisSpecification !== null) {
                                    foreach ($productSpecifications as $productSpecification) {
                                        $existProductIntegration = ProductIntegrationDetail::where(
                                            'product_id',
                                            $productSpecification->product_id
                                        )
                                        ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                                        ->first();
                                        $idProductIntegration = null;
                                        if ($existProductIntegration) {
                                            $toppingIntegration = ToppingIntegrationDetail::where(
                                                'specification_id',
                                                $specificationNew->id
                                            )
                                            ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                                            ->first();
                                            if (!$toppingIntegration) {
                                                $toppingIntegration = new ToppingIntegrationDetail();
                                                $toppingIntegration->specification_id =
                                                    $specificationNew->id;
                                                $toppingIntegration->integration_name =
                                                    AvailableMyposIntegration::NAME_EATS;
                                                $toppingIntegration->name = $specificationNew->name;
                                                $toppingIntegration->price = $specificationNew->value;
                                                $toppingIntegration->save();
                                            }
                                            $toppingIntProduct = ProductToppingIntegration::where(
                                                'product_integration_id',
                                                $existProductIntegration->id
                                            )
                                            ->where('topping_integration_id', $toppingIntegration->id)
                                            ->first();
                                            if (!$toppingIntProduct) {
                                                $toppingIntProduct = new ProductToppingIntegration();
                                                $toppingIntProduct->product_integration_id =
                                                $existProductIntegration->id;
                                                $toppingIntProduct->topping_integration_id =
                                                    $toppingIntegration->id;
                                            }
                                            $toppingIntProduct->value = $specificationUpdate->value;
                                            $toppingIntProduct->save();
                                        }
                                    }
                                }
                            }
                        }

                        EmptyJob::withChain($iFoodJobs)->dispatch();

                        return response()->json([
                            "status" => "Especificación creada con éxito",
                            "results" => null
                        ], 200);
                    }
                );
                return $processJSON;
            } catch (\Exception $e) {
                Log::info("SpecificationController API STORE: NO SE PUDO ACTUALIZAR LA ESPECIFICACION");
                Log::info($e);
                return response()->json([
                    'status' => 'No se pudo actualizar la especificación',
                    'results' => null
                ], 409);
            }
        } else {
            return response()->json([
                'status' => 'Esta categoría de especificación no existe',
                'results' => "null"
            ], 409);
        }
    }
}
