<?php

namespace App\Http\Controllers\API\Store;

use Log;
use App\Helper;
use App\Product;
use App\Employee;
use App\Store;
use App\StoreTax;
use Carbon\Carbon;
use App\Component;
use App\MetricUnit;
use App\ProductDetail;
use App\Specification;
use App\ProductCategory;
use App\ProductComponent;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\Section;
use App\Traits\AWSHelper;
use App\Traits\AuthTrait;
use App\Traits\FranchiseHelper;
use App\ComponentCategory;
use App\Traits\UberEatsMenu;
use Illuminate\Http\Request;
use App\Traits\ValidateToken;
use App\ProductSpecification;
use App\SpecificationCategory;
use App\Traits\LocalImageHelper;
use App\Traits\LoggingHelper;
use App\ProductIntegrationDetail;
use App\ProductSpecificationComponent;
use App\SpecificationComponent;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\IfoodProductPromotion;
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;
use App\StoreIntegrationId;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\Traits\iFood\IfoodMenu;
use App\Traits\iFood\IfoodRequests;

class ProductController extends Controller
{
    use ValidateToken;
    use AuthTrait;
    use UberEatsMenu;
    use AWSHelper;
    use LocalImageHelper;
    use LoggingHelper;
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
        $employee = $this->authEmployee;
        $data = $request->data;
        $product = Product::where('name', $data["name"])
            ->whereHas(
                'category',
                function ($q) use ($employee, $data) {
                    $q->where('company_id', $employee->store->company_id)
                        ->where('section_id', $data["section_id"]);
                }
            )
            ->where('status', 1)->get();
        if (count($product) > 0) {
            return response()->json(
                [
                    'status' => 'Este producto ya existe',
                    'results' => null
                ],
                409
            );
        }
        try {
            $productJSON = DB::transaction(
                function () use ($data, $employee) {
                    $productionCost = 0;
                    $income = 0;
                    $costRatio = 0;
                    if (isset($data['production_cost'])) {
                        $productionCost = $data['production_cost'];
                    }
                    if (isset($data['income'])) {
                        $income = $data['income'];
                    }
                    if (isset($data['cost_percentage'])) {
                        $costRatio = $data['cost_percentage'];
                    }
                    $product = new Product();
                    $product->name = $data["name"];
                    $product->product_category_id = $data["category_id"];
                    $product->type_product = $data["type_product"];
                    $product->search_string = Helper::remove_accents($data["name"]);
                    $product->description = $data["description"];
                    $product->priority = 0;
                    $product->base_value = Helper::getValueInCents($data["price"]);
                    $product->status = 1;
                    $invoiceName = $data["name"];
                    if (strlen($data["name"]) > 25) {
                        $invoiceName = mb_substr($data["name"], 0, 22, "utf-8");
                        $invoiceName = $invoiceName . "...";
                    }
                    $now = Carbon::now()->toDateTimeString();
                    $product->invoice_name = $invoiceName;
                    $product->sku = $data["sku"];
                    $product->created_at = $now;
                    $product->updated_at = $now;
                    $product->ask_instruction = $data['ask'];
                    $product->is_alcohol = $data['is_alcohol'];
                    $product->save();
                    $taxes = [];
                    foreach ($data["taxesProduct"] as $tax) {
                        array_push($taxes, $tax["id"]);
                    }

                    //Rescatamos lo impuestos globales para asignarlos también al producto
                    $globalTaxes = $this->getGlobalTaxes($employee->store->id);

                    $product->taxes()->sync(array_merge($taxes, $globalTaxes));

                    // Creando receta del producto
                    foreach ($data["itemsProduct"] as $item) {
                        $productComponent = new ProductComponent();
                        $productComponent->product_id = $product->id;
                        $productComponent->consumption = $item["consumption"];
                        $productComponent->component_id = $item["id"];
                        $productComponent->created_at = Carbon::now()->toDateTimeString();
                        $productComponent->updated_at = Carbon::now()->toDateTimeString();
                        $productComponent->save();
                    }

                    foreach ($data["specificationsProduct"] as $specification) {
                        $specifications = $specification["specifications"];
                        foreach ($specifications as $singleSpec) {
                            $productSpecification = new ProductSpecification();
                            $productSpecification->product_id = $product->id;
                            $productSpecification->specification_id = $singleSpec["id"];
                            $specificationDB = Specification::where('id', $singleSpec["id"])
                                ->where('status', 1)
                                ->first();
                            if ($specificationDB) {
                                $productSpecification->value = $specificationDB->value;
                            } else {
                                $productSpecification->value = 0;
                            }
                            $productSpecification->created_at = Carbon::now()->toDateTimeString();
                            $productSpecification->updated_at = Carbon::now()->toDateTimeString();
                            $productSpecification->save();
                            $specificationComponents = SpecificationComponent::where(
                                "specification_id",
                                $productSpecification->specification_id
                            )->where("status", 1)
                                ->get();
                            if (count($specificationComponents) > 0) {
                                foreach ($specificationComponents as $specComp) {
                                    $productSpecComponent = new ProductSpecificationComponent();
                                    $productSpecComponent->component_id =
                                        $specComp->component_id;
                                    $productSpecComponent->prod_spec_id =
                                        $productSpecification->id;
                                    $productSpecComponent->consumption =
                                        $specComp->consumption;
                                    $productSpecComponent->save();
                                }
                            }
                        }
                    }
                    $productDetail = new ProductDetail();
                    $productDetail->product_id = $product->id;
                    $productDetail->store_id = $employee->store->id;
                    $productDetail->stock = 0;
                    $productDetail->value = Helper::getValueInCents($data["price"]);
                    $productDetail->status = 1;
                    $productDetail->created_at = Carbon::now()->toDateTimeString();
                    $productDetail->updated_at = Carbon::now()->toDateTimeString();
                    $productDetail->production_cost = $productionCost;
                    $productDetail->income = $income;
                    $productDetail->cost_ratio = $costRatio;
                    $productDetail->save();

                    $integrations = $data["integrations"];
                    // Modificando registros de integraciones de productos
                    $uploadToIfood = false;
                    $dataIFood = null;
                    foreach ($integrations as $integration) {
                        $existProductIntegration = ProductIntegrationDetail::where('product_id', $product->id)
                            ->where('integration_name', $integration["code_name"])
                            ->first();
                        $idProductIntegration = null;
                        if ($existProductIntegration) {
                            if ($integration["available"]) {
                                $existProductIntegration->name = $integration["product"]["name"];
                                $existProductIntegration->price = $integration["product"]["price"];
                                $existProductIntegration->save();
                                $idProductIntegration = $existProductIntegration->id;
                                // Eliminando promociones iFood anteriores
                                $promotions = IfoodProductPromotion::where(
                                    'product_integration_id',
                                    $idProductIntegration
                                )->get();
                                foreach ($promotions as $promotion) {
                                    $promotion->delete();
                                }
                            } else {
                                $existProductIntegration->delete();
                            }
                        } elseif ($integration["available"]) {
                            $productIntegration = new ProductIntegrationDetail();
                            $productIntegration->product_id = $product->id;
                            $productIntegration->integration_name = $integration["code_name"];
                            $productIntegration->name = $integration["product"]["name"];
                            $productIntegration->price = $integration["product"]["price"];
                            $productIntegration->created_at = Carbon::now()->toDateTimeString();
                            $productIntegration->updated_at = Carbon::now()->toDateTimeString();
                            $productIntegration->save();
                            $idProductIntegration = $productIntegration->id;
                        }

                        // Actualizando registros de integraciones de especificaciones
                        $specificationsProduct = $integration["product"]["specifications"];
                        if ($integration["available"] && $idProductIntegration != null) {
                            foreach ($specificationsProduct as $specificationProduct) {
                                $specificationCategory = SpecificationCategory::where(
                                    'id',
                                    $specificationProduct["id"]
                                )
                                    ->first();
                                if (!$specificationCategory) {
                                    throw new \Exception("No existe esta especificación.");
                                }
                                $optionsSpecifications = $specificationProduct["specifications"];
                                foreach ($optionsSpecifications as $option) {
                                    $specificationOption = Specification::where('id', $option["id"])->first();
                                    if (!$specificationOption) {
                                        throw new \Exception("No existe esta opción de especificación.");
                                    }
                                    $toppingIntegration = ToppingIntegrationDetail::where(
                                        'specification_id',
                                        $option["id"]
                                    )
                                        ->where('integration_name', $integration["code_name"])
                                        ->first();
                                    if (!$toppingIntegration) {
                                        $toppingIntegration = new ToppingIntegrationDetail();
                                        $toppingIntegration->specification_id = $specificationOption->id;
                                        $toppingIntegration->integration_name = $integration["code_name"];
                                        $toppingIntegration->name = $specificationOption->name;
                                        $toppingIntegration->price = $specificationOption->value;
                                        $toppingIntegration->save();
                                    }
                                    $toppingIntProduct = ProductToppingIntegration::where(
                                        'product_integration_id',
                                        $idProductIntegration
                                    )
                                        ->where('topping_integration_id', $toppingIntegration->id)
                                        ->first();
                                    if (!$toppingIntProduct) {
                                        $toppingIntProduct = new ProductToppingIntegration();
                                        $toppingIntProduct->product_integration_id = $idProductIntegration;
                                        $toppingIntProduct->topping_integration_id = $toppingIntegration->id;
                                    }
                                    $toppingIntProduct->value = $option["value"];
                                    $toppingIntProduct->save();
                                }
                            }
                            // Agregando nueva promoción iFood
                            if ($integration["code_name"] == AvailableMyposIntegration::NAME_IFOOD) {
                                // Creando item en iFood
                                $integrationToken = StoreIntegrationToken::where('store_id', $employee->store->id)
                                    ->where('integration_name', AvailableMyposIntegration::NAME_IFOOD)
                                    ->where('type', 'delivery')
                                    ->first();

                                $integrationData = AvailableMyposIntegration::where(
                                    'code_name',
                                    AvailableMyposIntegration::NAME_IFOOD
                                )
                                    ->first();

                                $config = StoreIntegrationId::where('store_id', $employee->store->id)
                                    ->where('integration_id', $integrationData->id)
                                    ->first();

                                $client = new FileGetContents(new Psr17Factory());
                                $browser = new Browser($client, new Psr17Factory());
                                $channelLog = "ifood_logs";
                                $channelSlackDev = "#integration_logs_details";
                                $baseUrl = config('app.ifood_url_api');
                                IfoodRequests::initVarsIfoodRequests(
                                    $channelLog,
                                    $channelSlackDev,
                                    $baseUrl,
                                    $browser
                                );
                                if ($integrationToken != null) {
                                    // Verificar si el token ha caducado
                                    $now = Carbon::now();
                                    $emitted = Carbon::parse($integrationToken->updated_at);
                                    $diff = $now->diffInSeconds($emitted);
                                    // El token sólo dura 1 hora(3600 segundos)
                                    if ($diff > 3599) {
                                        $resultToken = IfoodRequests::getToken();
                                        if ($resultToken["status"] == 1) {
                                            $uploadToIfood = true;
                                            $dataIFood = [
                                                'employee' => $employee,
                                                'integrationData' => $integrationData,
                                                'product' => $product,
                                                'integration' => $integration,
                                                'config' => $config
                                            ];
                                        }
                                    } else {
                                        $uploadToIfood = true;
                                        $dataIFood = [
                                            'employee' => $employee,
                                            'integrationData' => $integrationData,
                                            'product' => $product,
                                            'integration' => $integration,
                                            'config' => $config
                                        ];
                                    }
                                }

                                // Creando promociones iFood
                                if ($integration["product"]["hasPromotion"] == true) {
                                    $promotion = new IfoodProductPromotion();
                                    $promotion->product_integration_id = $idProductIntegration;
                                    $promotion->value = $integration["product"]["promotionPrice"];
                                    $promotion->save();
                                }
                            }
                        }
                    }

                    if ($data["image_bitmap64"] != null) {
                        $filename = $this->storeProductImageOnLocalServer($data["image_bitmap64"], $product->id);
                        if ($filename != null) {
                            $folder = '/products_images/';

                            if ($uploadToIfood) {
                                $this->uploadToIfood(
                                    $dataIFood['employee'],
                                    $dataIFood['integrationData'],
                                    $dataIFood['product'],
                                    $dataIFood['integration'],
                                    $dataIFood['config'],
                                    $filename,
                                    public_path() . $folder . $filename . '.jpg'
                                );
                            }

                            $this->uploadLocalFileToS3(
                                public_path() . $folder . $filename . '.jpg',
                                $employee->store->id,
                                $filename,
                                $product->image_version
                            );
                            $this->saveProductImageAWSUrlDB($product, $filename, $employee->store->id);
                            $this->deleteImageOnLocalServer($filename, $folder);
                        }
                    } elseif ($uploadToIfood) {
                        $this->uploadToIfood(
                            $dataIFood['employee'],
                            $dataIFood['integrationData'],
                            $dataIFood['product'],
                            $dataIFood['integration'],
                            $dataIFood['config']
                        );
                    }

                    return response()->json(
                        [
                            "status" => "Producto creado con éxito",
                            "results" => null
                        ],
                        200
                    );
                }
            );
            return $productJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductController API Store create: ERROR GUARDAR PRODUCTO, storeId: " . $employee->store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            return response()->json(
                [
                    'status' => 'No se pudo crear el producto',
                    'results' => null
                ],
                409
            );
        }
    }

    public function uploadToIfood($employee, $integrationData, $product, $integration, $config, $filename = null, $path = null)
    {
        $integrationToken = StoreIntegrationToken::where('store_id', $employee->store->id)
            ->where('integration_name', $integrationData->code_name)
            ->where('type', 'delivery')
            ->first();

        if (!is_null($config)) {
            $promotionValue = $product->base_value / 100;
            $originalValue = 0;
            if ($integration["product"]["hasPromotion"]) {
                $hasPromotion = true;
                $originalValue = $product->base_value / 100;
                $promotionValue = $integration["product"]["promotionPrice"] / 100;
            }
            // Data item
            $itemObject = [
                [
                    "name" => "sku",
                    "contents" => json_encode(
                        [
                            "merchantId" => $config->external_store_id,
                            "availability" => "AVAILABLE",
                            "externalCode" => $product->id,
                            "name" => $product->name,
                            "description" => $product->description  != null ? $product->description : '',
                            "order" => $product->priority,
                            "price" => [
                                "promotional" => $integration["product"]["hasPromotion"],
                                "originalValue" => $originalValue,
                                "value" => $promotionValue
                            ]
                        ]
                    )
                ]
            ];
            if (!is_null($path)) {
                array_push(
                    $itemObject,
                    [
                        "name" => "file",
                        "contents" => fopen($path, 'r')
                    ]
                );
            }

            $statusUploadItem = IfoodRequests::uploadItem(
                $integrationToken->token,
                $config->external_store_id,
                $employee->store->name,
                $itemObject,
                $product->product_category_id,
                false
            );

            if ($statusUploadItem['status'] == 1) {
                // Grupo de modificadores
                $prodSpecIds = $product->productSpecifications->pluck('id')->toArray();
                $specCategories = SpecificationCategory::whereHas(
                    'productSpecs',
                    function ($prodSpec) use ($prodSpecIds) {
                        $prodSpec->whereIn('product_specifications.id', $prodSpecIds)
                            ->where('product_specifications.status', 1);
                    }
                )
                    ->with(
                        ['productSpecs' => function ($prodSpec) use ($prodSpecIds) {
                            $prodSpec->whereIn('product_specifications.id', $prodSpecIds)
                                ->where('product_specifications.status', 1)->with('specification')
                                ->orderBy('priority');
                        }]
                    )
                    ->orderBy('priority')
                    ->get();
                // Asignar el grupo de modificadores al producto
                foreach ($specCategories as $specCategory) {
                    $minPermitted = 0;
                    if ($specCategory->required) {
                        $minPermitted = 1;
                    }

                    // Opciones de este grupo
                    $prodSpecs = $specCategory->productSpecs;

                    // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                    $countOptionsSpec = count($prodSpecs);
                    $maxPermitted = $specCategory->max;
                    if ($specCategory->max > $countOptionsSpec) {
                        $maxPermitted = $countOptionsSpec;
                    }

                    $linkGroupItem = [
                        "merchantId" => $config->external_store_id,
                        "externalCode" => $specCategory->id,
                        "order" => $specCategory->priority,
                        "maxQuantity" => $maxPermitted,
                        "minQuantity" => $minPermitted,
                    ];

                    IfoodRequests::linkModifierGroupToItem(
                        $integrationToken->token,
                        $config->external_store_id,
                        $employee->store->name,
                        $product->id,
                        $linkGroupItem
                    );
                }
            }
        }
    }

    public function getGlobalTaxes(Int $storeId)
    {
        //traremos todos los impuestos seteados como globales
        $storeTaxes = StoreTax::where('store_id', $storeId)->where('is_main', 1)->get();

        $taxes = [];

        //recolectamos el id de cada producto
        foreach ($storeTaxes as $storeTax) {
            array_push($taxes, $storeTax->id);
        }

        return $taxes;
    }

    public function getProductsByCompany(Request $request)
    {
        $rowsPerPage = 12;
        $store = $this->authStore;
        $search = $request->search ? $request->search : "";

        $offset = ($request->page * $rowsPerPage) - $rowsPerPage;
        if ($search === "") {
            $products = Product::where('status', 1)->get()->pluck('id');
        } else {
            $products = Product::search($search)->where('status', 1)->get()->pluck('id');
        }
        $products = Product::whereHas(
            'category',
            function ($q) use ($store) {
                $q->where('company_id', $store->company_id)->where('status', 1);
            }
        )->whereIn('id', $products)
            ->with([
                "taxes" => function ($taxes) use ($store) {
                    $taxes->where("store_id", $store->id);
                }
            ])
            ->orderBy('name', 'asc')
            ->get();
        $productsAskInstructions = $products->where('ask_instruction', 1);
        $allAskInstructions = false;
        if (count($products) == count($productsAskInstructions)) {
            $allAskInstructions = true;
        }
        $productsPage = [];
        $productsSlice = $products->slice($offset, $rowsPerPage);
        if ($offset > 0) {
            foreach ($productsSlice as $component) {
                array_push($productsPage, $component);
            }
        } else {
            $productsPage = $productsSlice->toArray();
        }
        return response()->json([
            'status' => 'Listando productos',
            'results' => [
                'count' => count($products),
                'data' => array_values($productsPage),
                'allAskInstructions' => $allAskInstructions,
            ]
        ], 200);
    }

    public function delete(Request $request)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $productStore = Store::whereHas('sections.categories.products', function ($query) use ($request) {
            return $query->where('id', $request->id_product);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($productStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $product = Product::where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($productStore) {
                    $q->where('company_id', $productStore->company_id);
                }
            )
            ->where('id', $request->id_product)
            ->first();

        if ($product) {
            try {
                $componentJSON = DB::transaction(
                    function () use ($product) {
                        $product->status = 0;
                        $product->save();
                        $productsIntegration = ProductIntegrationDetail::where('product_id', $product->id)
                            ->get();
                        foreach ($productsIntegration as $productIntegration) {
                            $productIntegration->delete();
                        }
                        return response()->json(
                            [
                                "status" => "Producto borrado con éxito",
                                "results" => null
                            ],
                            200
                        );
                    }
                );
                return $componentJSON;
            } catch (\Exception $e) {
                $this->logError(
                    "ProductController API Store: ERROR ELIMINAR PRODUCTO, storeId: " . $productStore->id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    json_encode($request->all())
                );
                return response()->json(
                    [
                        'status' => 'No se pudo eliminar el producto',
                        'results' => null
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'El producto no existe',
                    'results' => null
                ],
                409
            );
        }
    }

    public function infoProduct($id)
    {
        $store = $this->authStore;
        $product = Product::where('id', $id)
            ->with(
                [
                    'productSpecifications' => function ($productSpecifications) {
                        $productSpecifications->where('status', 1)
                            ->with([
                                'componentConsumption.variation' => function ($variation) {
                                    $variation->with([
                                        'component',
                                        'unit'
                                    ]);
                                }
                            ]);
                    },
                    'productSpecifications.specification.specificationCategory',
                    'taxes'
                ]
            )
            ->where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($store) {
                    $q->where('company_id', $store->company_id);
                }
            )
            ->first();

        if ($product) {
            $variationsProduct = ProductComponent::where('status', 1)
                ->where('product_id', $product->id)
                ->with(
                    [
                        'variation.unit',
                        'variation',
                        'variation.productComponents' => function ($products) use ($id) {
                            $products->where('product_id', $id)->where('status', 1);
                        }
                    ]
                )
                ->get();
            $listVariations = [];
            foreach ($variationsProduct as $variationProduct) {
                array_push($listVariations, $variationProduct->variation);
            }
            return response()->json([
                'status' => 'Producto info',
                'results' => $product,
                'variations' => $listVariations
            ], 200);
        } else {
            return response()->json([
                'status' => 'El producto no existe',
                'results' => null
            ], 409);
        }
    }

    public function update(Request $request)
    {
        $productData = $request->data["product"];

        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $productStore = Store::whereHas('sections.categories.products', function ($query) use ($productData) {
            return $query->where('id', $productData["id"]);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($productStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $itemVariationsData = $request->data["variations"];
        $specificationsData = $request->data["specifications"];
        $taxes = $request->data["taxes"];
        $integrations = $request->data["integrations"];
        $imageData = $request->data["image_bitmap64"];
        $productionCost = 0;
        $income = 0;
        $costRatio = 0;
        if (isset($request->data['production_cost'])) {
            $productionCost = $request->data['production_cost'];
        }
        if (isset($request->data['income'])) {
            $income = $request->data['income'];
        }
        if (isset($request->data['cost_percentage'])) {
            $costRatio = $request->data['cost_percentage'];
        }
        $secionNotNull = $productData["category"]["section_id"] != null;
        $productExist = Product::where('name', $productData["name"])
            ->where('status', 1)
            ->where('id', "!=", $productData["id"])
            ->whereHas(
                'category',
                function ($q) use ($productStore, $secionNotNull) {
                    $q->where('company_id', $productStore->company_id)
                        ->when($secionNotNull, function ($query, $productData) {
                            return $query->where('section_id', $productData["category"]["section_id"]);
                        });
                }
            )
            ->get();
        if (count($productExist) > 0) {
            return response()->json([
                'status' => 'Este nombre no se encuentra disponible',
                'results' => null
            ], 409);
        }

        $product = Product::where('status', 1)
            ->where('id', $productData["id"])
            ->whereHas(
                'category',
                function ($q) use ($productStore) {
                    $q->where('company_id', $productStore->company_id);
                }
            )
            ->with(
                [
                    'components',
                    'productSpecifications'
                ]
            )
            ->first();
        if ($product) {
            try {
                $productJSON = DB::transaction(
                    function () use (
                        $product,
                        $productData,
                        $itemVariationsData,
                        $specificationsData,
                        $taxes,
                        $productStore,
                        $integrations,
                        $imageData,
                        $productionCost,
                        $income,
                        $costRatio,
                        $secionNotNull
                    ) {
                        $product->name = $productData["name"];
                        $product->product_category_id = $productData["category"]["id"];
                        $product->search_string = Helper::remove_accents($productData["name"]);
                        $product->description = $productData["description"];
                        $product->base_value = $productData["base_value"];
                        $invoiceName = $productData["name"];
                        if (strlen($productData["name"]) > 25) {
                            $invoiceName = mb_substr($productData["name"], 0, 22, "utf-8");
                            $invoiceName = $invoiceName . "...";
                        }
                        $product->invoice_name = $invoiceName;
                        $product->sku = $productData["sku"];
                        $product->updated_at = Carbon::now()->toDateTimeString();
                        $product->ask_instruction = $productData["ask_instruction"];
                        $product->is_alcohol = $productData["is_alcohol"];
                        $product->type_product = $productData["type_product"];
                        $product->save();
                        $taxIds = [];
                        foreach ($taxes as $tax) {
                            array_push($taxIds, $tax["id"]);
                        }
                        $product->taxes()->sync($taxIds);
                        // Soft Delete Products Components borrados en frontend
                        $productOldItemsVariations = $product->variations;
                        foreach ($productOldItemsVariations as $oldItemVariation) {
                            $variationDeleted = true;
                            $idOldItemVariation = (string) $oldItemVariation->id;
                            foreach ($itemVariationsData as $variation) {
                                $idVariation = (string) $variation["id"];
                                if ($idOldItemVariation == $idVariation) {
                                    $variationDeleted = false;
                                    break;
                                }
                            }
                            if ($variationDeleted) {
                                $variationDB = ProductComponent::where(
                                    'component_id',
                                    $oldItemVariation->id
                                )
                                    ->where('product_id', $product->id)
                                    ->where('status', 1)
                                    ->first();
                                if ($variationDB) {
                                    $variationDB->status = 0;
                                    $variationDB->updated_at = Carbon::now()->toDateTimeString();
                                    $variationDB->save();
                                }
                            }
                        }

                        // Actualizando o creando products components
                        foreach ($itemVariationsData as $variation) {
                            $idVariation = (string) $variation["id"];
                            $productComponentExist = ProductComponent::where('component_id', $idVariation)
                                ->where('product_id', $product->id)
                                ->where('status', 1)
                                ->first();
                            if ($productComponentExist) {
                                $productComponentExist->consumption =
                                    $variation["product_components"][0]["consumption"];
                                $productComponentExist->updated_at = Carbon::now()->toDateTimeString();
                                $productComponentExist->save();
                            } else {
                                $productItemVariation = new ProductComponent();
                                $productItemVariation->product_id = $product->id;
                                $productItemVariation->consumption =
                                    $variation["product_components"][0]["consumption"];
                                $productItemVariation->component_id = $idVariation;
                                $productItemVariation->status = 1;
                                $productItemVariation->created_at = Carbon::now()->toDateTimeString();
                                $productItemVariation->updated_at = Carbon::now()->toDateTimeString();
                                $productItemVariation->save();
                            }
                        }

                        // Soft Delete Products Specifications borrados en frontend
                        $productOldSpecifications = $product->productSpecifications;
                        $idsUnlink = [];
                        foreach ($productOldSpecifications as $oldSpecification) {
                            $specificationDeleted = true;
                            $idOldSpecification = (string) $oldSpecification->specification_id;
                            foreach ($specificationsData as $specification) {
                                $specificationsGroup = $specification["specs"];
                                foreach ($specificationsGroup as $specificationGroup) {
                                    $idSpecification = (string) $specificationGroup["id"];
                                    if ($idOldSpecification == $idSpecification) {
                                        $specificationDeleted = false;
                                        break;
                                    }
                                }
                                if (!$specificationDeleted) {
                                    break;
                                }
                            }
                            if ($specificationDeleted) {
                                $specificationDB = ProductSpecification::where(
                                    'specification_id',
                                    $oldSpecification->specification_id
                                )
                                    ->where('product_id', $product->id)
                                    ->where('status', 1)
                                    ->first();
                                if ($specificationDB) {
                                    $specificationDB->status = 0;
                                    $specificationDB->updated_at = Carbon::now()->toDateTimeString();
                                    $specificationDB->save();
                                    $specProdComps = ProductSpecificationComponent::where(
                                        "prod_spec_id",
                                        $specificationDB->id
                                    )->get();
                                    foreach ($specProdComps as $specProdComp) {
                                        $specProdComp->delete();
                                    }
                                }
                                $specificationData = Specification::where(
                                    'id',
                                    $oldSpecification->specification_id
                                )
                                    ->first();
                                if (!is_null($specificationData) && !in_array($specificationData->specification_category_id, $idsUnlink)) {
                                    array_push($idsUnlink, $specificationData->specification_category_id);
                                }
                            }
                        }

                        // Actualizando o creando products specifications
                        $idsLink = [];
                        $idsDataLink = [];
                        foreach ($specificationsData as $specification) {
                            $specificationsGroup = $specification["specs"];
                            foreach ($specificationsGroup as $specificationGroup) {
                                $idSpecification = (string) $specificationGroup["id"];
                                $specificationExist = ProductSpecification::where(
                                    'specification_id',
                                    $idSpecification
                                )
                                    ->where('product_id', $product->id)
                                    ->where('status', 1)
                                    ->first();
                                if ($specificationExist) {
                                    $specificationExist->value = $specificationGroup["value"];
                                    $specificationExist->updated_at = Carbon::now()->toDateTimeString();
                                    $specificationExist->save();
                                    // Actualizando los consumos de ProductSpecificationComponent
                                    // que vienen de EditarProducto
                                    $prodSpecComps = $specificationGroup["component_consumption"];
                                    foreach ($prodSpecComps as $prodSpecComp) {
                                        $productSpecComponent = ProductSpecificationComponent::where(
                                            "id",
                                            $prodSpecComp["id"]
                                        )->first();
                                        if ($productSpecComponent) {
                                            $productSpecComponent->consumption = $prodSpecComp["consumption"];
                                            $productSpecComponent->save();
                                        }
                                    }
                                } else {
                                    $newSpecification = new ProductSpecification();
                                    $newSpecification->product_id = $product->id;
                                    $newSpecification->specification_id = $idSpecification;
                                    $newSpecification->value = $specificationGroup["value"];
                                    $newSpecification->status = 1;
                                    $newSpecification->created_at = Carbon::now()->toDateTimeString();
                                    $newSpecification->updated_at = Carbon::now()->toDateTimeString();
                                    $newSpecification->save();
                                    // Creando ProductSpecificationComponent
                                    // que vienen de EditarProducto(especificación agregada)
                                    $prodSpecComps = $specificationGroup["component_consumption"];
                                    foreach ($prodSpecComps as $prodSpecComp) {
                                        $productSpecComponent = new ProductSpecificationComponent();
                                        $productSpecComponent->component_id =
                                            $prodSpecComp["component_id"];
                                        $productSpecComponent->prod_spec_id =
                                            $newSpecification->id;
                                        $productSpecComponent->consumption =
                                            $prodSpecComp["consumption"];
                                        $productSpecComponent->save();
                                    }
                                    $specificationData = Specification::where(
                                        'id',
                                        $idSpecification
                                    )
                                        ->first();
                                    if (!is_null($specificationData) && !in_array($specificationData->specification_category_id, $idsLink)) {
                                        $countOptionsSpec = count($specificationsGroup);
                                        $maxPermitted = $specificationData->specificationCategory->max;
                                        if ($specificationData->specificationCategory->max > $countOptionsSpec) {
                                            $maxPermitted = $countOptionsSpec;
                                        }
                                        $minPermitted = 0;
                                        if ($specificationData->specificationCategory->required) {
                                            $minPermitted = 1;
                                        }
                                        array_push(
                                            $idsDataLink,
                                            [
                                                "externalCode" => $specificationData->specification_category_id,
                                                "order" => $specificationData->specificationCategory->priority,
                                                "maxQuantity" => $maxPermitted,
                                                "minQuantity" => $minPermitted,
                                            ]
                                        );
                                        array_push($idsLink, $specificationData->specification_category_id);
                                    }
                                }
                            }
                        }

                        $productDetail = ProductDetail::where('product_id', $product->id)
                            ->where('status', 1)->first();
                        $productDetail->value = $productData["base_value"];
                        $productDetail->updated_at = Carbon::now()->toDateTimeString();
                        $productDetail->income = $income;
                        $productDetail->production_cost = $productionCost;
                        $productDetail->cost_ratio = $costRatio;
                        $productDetail->save();

                        $section = null;
                        if ($secionNotNull) {
                            $section = Section::where('id', $productData["category"]["section_id"])->first();
                        }

                        // Modificando registros de integraciones de productos
                        $dataIFood = null;
                        $uploadToIfood = false;
                        foreach ($integrations as $integration) {
                            $existProductIntegration = ProductIntegrationDetail::where('product_id', $product->id)
                                ->where('integration_name', $integration["code_name"])
                                ->first();
                            $idProductIntegration = null;
                            if ($existProductIntegration) {
                                if ($integration["available"]) {
                                    if ($section != null) {
                                        if ($section->is_main == 1) {
                                            $existProductIntegration->name = $integration["product"]["name"];
                                            $existProductIntegration->price = $integration["product"]["price"];
                                        } else {
                                            $existProductIntegration->name = $product->name;
                                            $existProductIntegration->price = $product->base_value;
                                        }
                                        $existProductIntegration->save();
                                        $idProductIntegration = $existProductIntegration->id;
                                        // Eliminando promociones iFood anteriores
                                        $promotions = IfoodProductPromotion::where(
                                            'product_integration_id',
                                            $idProductIntegration
                                        )->get();
                                        foreach ($promotions as $promotion) {
                                            $promotion->delete();
                                        }
                                    }
                                } else {
                                    $existProductIntegration->delete();
                                }
                            } elseif ($integration["available"]) {
                                if ($section != null) {
                                    $productIntegration = new ProductIntegrationDetail();
                                    $productIntegration->product_id = $product->id;
                                    if ($section->is_main == 1) {
                                        $productIntegration->integration_name = $integration["code_name"];
                                        $productIntegration->name = $integration["product"]["name"];
                                        $productIntegration->price = $integration["product"]["price"];
                                    } else {
                                        $productIntegration->integration_name = $integration["code_name"];
                                        $productIntegration->name = $product->name;
                                        $productIntegration->price = $product->base_value;
                                    }
                                    $productIntegration->created_at = Carbon::now()->toDateTimeString();
                                    $productIntegration->updated_at = Carbon::now()->toDateTimeString();
                                    $productIntegration->save();
                                    $idProductIntegration = $productIntegration->id;
                                }
                            }

                            // Actualizando registros de integraciones de especificaciones
                            $specificationsProduct = $integration["product"]["specifications"];
                            if ($integration["available"] && $idProductIntegration != null) {
                                foreach ($specificationsProduct as $specificationProduct) {
                                    $specificationCategory = SpecificationCategory::where(
                                        'id',
                                        $specificationProduct["id"]
                                    )
                                        ->first();
                                    if (!$specificationCategory) {
                                        throw new \Exception("No existe esta especificación.");
                                    }
                                    $optionsSpecifications = $specificationProduct["specs"];
                                    foreach ($optionsSpecifications as $option) {
                                        $specificationOption = Specification::where('id', $option["id"])->first();
                                        if (!$specificationOption) {
                                            throw new \Exception("No existe esta opción de especificación.");
                                        }

                                        if ($section != null) {
                                            $toppingIntegration = ToppingIntegrationDetail::where(
                                                'specification_id',
                                                $option["id"]
                                            )
                                                ->where('integration_name', $integration["code_name"])
                                                ->first();
                                            if (!$toppingIntegration) {
                                                $toppingIntegration = new ToppingIntegrationDetail();
                                                $toppingIntegration->specification_id = $specificationOption->id;
                                                $toppingIntegration->integration_name = $integration["code_name"];
                                                $toppingIntegration->name = $specificationOption->name;
                                                $toppingIntegration->price = $specificationOption->value;
                                                $toppingIntegration->save();
                                            }
                                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                                'product_integration_id',
                                                $idProductIntegration
                                            )
                                                ->where('topping_integration_id', $toppingIntegration->id)
                                                ->first();
                                            if (!$toppingIntegrationProduct) {
                                                $toppingIntegrationProduct = new ProductToppingIntegration();
                                                $toppingIntegrationProduct->product_integration_id =
                                                    $idProductIntegration;
                                                $toppingIntegrationProduct->topping_integration_id =
                                                    $toppingIntegration->id;
                                            }
                                            if ($section->is_main == 1) {
                                                $toppingIntegrationProduct->value = $option["value"];
                                            } else {
                                                $prodSpec = ProductSpecification::where(
                                                    'product_id',
                                                    $product->id
                                                )
                                                    ->where(
                                                        'specification_id',
                                                        $specificationOption->id
                                                    )
                                                    ->first();
                                                if ($prodSpec != null) {
                                                    $toppingIntegrationProduct->value = $prodSpec->value;
                                                }
                                                if ($section->is_main == 1) {
                                                    $toppingIntegrationProduct->value = $option["value"];
                                                } else {
                                                    $prodSpec = ProductSpecification::where(
                                                        'product_id',
                                                        $product->id
                                                    )
                                                        ->where(
                                                            'specification_id',
                                                            $specificationOption->id
                                                        )
                                                        ->where('status', 1)
                                                        ->first();
                                                    if ($prodSpec != null) {
                                                        $toppingIntegrationProduct->value = $prodSpec->value;
                                                    }
                                                }
                                                $toppingIntegrationProduct->save();
                                            }
                                            $toppingIntegrationProduct->save();
                                        }
                                    }
                                }

                                // Agregando nueva promoción iFood
                                if ($integration["code_name"] == AvailableMyposIntegration::NAME_IFOOD) {
                                    // Creando item en iFood
                                    $integrationToken = StoreIntegrationToken::where('store_id', $productStore->id)
                                        ->where('integration_name', AvailableMyposIntegration::NAME_IFOOD)
                                        ->where('type', 'delivery')
                                        ->first();

                                    $integrationData = AvailableMyposIntegration::where(
                                        'code_name',
                                        AvailableMyposIntegration::NAME_IFOOD
                                    )
                                        ->first();

                                    $config = StoreIntegrationId::where('store_id', $productStore->id)
                                        ->where('integration_id', $integrationData->id)
                                        ->first();

                                    $client = new FileGetContents(new Psr17Factory());
                                    $browser = new Browser($client, new Psr17Factory());
                                    $channelLog = "ifood_logs";
                                    $channelSlackDev = "#integration_logs_details";
                                    $baseUrl = config('app.ifood_url_api');
                                    IfoodRequests::initVarsIfoodRequests(
                                        $channelLog,
                                        $channelSlackDev,
                                        $baseUrl,
                                        $browser
                                    );
                                    if ($integrationToken != null) {
                                        // Verificar si el token ha caducado
                                        $now = Carbon::now();
                                        $emitted = Carbon::parse($integrationToken->updated_at);
                                        $diff = $now->diffInSeconds($emitted);
                                        // El token sólo dura 1 hora(3600 segundos)
                                        if ($diff > 3599) {
                                            $resultToken = IfoodRequests::getToken();
                                            if ($resultToken["status"] == 1) {
                                                $uploadToIfood = true;
                                                $dataIFood = [
                                                    'store' => $productStore,
                                                    'integrationData' => $integrationData,
                                                    'product' => $product,
                                                    'integration' => $integration,
                                                    'config' => $config
                                                ];
                                            }
                                        } else {
                                            $uploadToIfood = true;
                                            $dataIFood = [
                                                'store' => $productStore,
                                                'integrationData' => $integrationData,
                                                'product' => $product,
                                                'integration' => $integration,
                                                'config' => $config
                                            ];
                                        }
                                    }

                                    // Creando promociones iFood
                                    if ($integration["product"]["hasPromotion"] == true) {
                                        $promotion = new IfoodProductPromotion();
                                        $promotion->product_integration_id = $idProductIntegration;
                                        $promotion->value = $integration["product"]["promotionPrice"];
                                        $promotion->save();
                                    }
                                }


                                $prodSpecIds = ProductSpecification::where('product_id', $product->id)
                                    ->where('status', 1)
                                    ->get()
                                    ->pluck('specification_id')->all();
                                $toppingIntegrationIds = ProductToppingIntegration::where(
                                    'product_integration_id',
                                    $idProductIntegration
                                )
                                    ->get()
                                    ->pluck('topping_integration_id')->all();
                                $prodSpecsIntIds = ToppingIntegrationDetail::whereIn(
                                    'id',
                                    $toppingIntegrationIds
                                )
                                    ->where('integration_name', $integration["code_name"])
                                    ->get()
                                    ->pluck('specification_id')->all();

                                $toppingsToDelete = array_diff($prodSpecsIntIds, $prodSpecIds);
                                // Especificaciones de integración a borrar
                                foreach ($toppingsToDelete as $toppingToDelete) {
                                    $deleted = ToppingIntegrationDetail::where(
                                        'specification_id',
                                        $toppingToDelete
                                    )
                                        ->where('integration_name', $integration["code_name"])
                                        ->first();
                                    $deleted->delete();
                                }
                            }
                        }

                        if ($imageData != null) {
                            $filename = $this->storeProductImageOnLocalServer($imageData, $product->id);
                            if ($filename != null) {
                                $folder = '/products_images/';

                                if ($uploadToIfood) {
                                    $this->updateInIfood(
                                        $dataIFood['store'],
                                        $dataIFood['integrationData'],
                                        $dataIFood['product'],
                                        $dataIFood['integration'],
                                        $dataIFood['config'],
                                        $idsUnlink,
                                        $idsDataLink,
                                        $filename,
                                        public_path() . $folder . $filename . '.jpg'
                                    );
                                }

                                $this->uploadLocalFileToS3(
                                    public_path() . $folder . $filename . '.jpg',
                                    $productStore->id,
                                    $filename,
                                    $product->image_version
                                );
                                $this->saveProductImageAWSUrlDB($product, $filename, $productStore->id);
                                $this->deleteImageOnLocalServer($filename, $folder);
                            }
                        } elseif ($uploadToIfood) {
                            $this->updateInIfood(
                                $dataIFood['store'],
                                $dataIFood['integrationData'],
                                $dataIFood['product'],
                                $dataIFood['integration'],
                                $dataIFood['config'],
                                $idsUnlink,
                                $idsDataLink
                            );
                        }
                        return response()->json([
                            "status" => "Producto actualizado con éxito",
                            "results" => null
                        ], 200);
                    }
                );
                return $productJSON;
            } catch (\Exception $e) {
                $this->logError(
                    "ProductController API Store update: ERROR EDITAR PRODUCTO, storeId: " . $productStore->id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    json_encode($request->all())
                );
                return response()->json(
                    [
                        'status' => 'No se pudo modificar el producto',
                        'results' => "null"
                    ],
                    409
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'No se pudo modificar el producto',
                    'results' => "null"
                ],
                409
            );
        }
    }

    public function updateInIfood($store, $integrationData, $product, $integration, $config, $idsUnlink, $idsLink, $filename = null, $path = null)
    {
        $integrationToken = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', $integrationData->code_name)
            ->where('type', 'delivery')
            ->first();

        if (!is_null($config)) {
            $promotionValue = $product->base_value / 100;
            $originalValue = 0;
            if ($integration["product"]["hasPromotion"]) {
                $hasPromotion = true;
                $originalValue = $product->base_value / 100;
                $promotionValue = $integration["product"]["promotionPrice"] / 100;
            }
            // Data item
            $itemObject = [
                [
                    "name" => "sku",
                    "contents" => json_encode(
                        [
                            "merchantId" => $config->external_store_id,
                            "externalCode" => $product->id,
                            "name" => $product->name,
                            "description" => $product->description  != null ? $product->description : '',
                            "price" => [
                                "promotional" => $integration["product"]["hasPromotion"],
                                "originalValue" => $originalValue,
                                "value" => $promotionValue
                            ]
                        ]
                    )
                ]
            ];
            if (!is_null($path)) {
                array_push(
                    $itemObject,
                    [
                        "name" => "file",
                        "contents" => fopen($path, 'r')
                    ]
                );
            }

            $statusUploadItem = IfoodRequests::updateItem(
                $integrationToken->token,
                $config->external_store_id,
                $store->name,
                $itemObject
            );

            foreach ($idsUnlink as $id) {
                IfoodRequests::unlinkModifierGroupToItem(
                    $integrationToken->token,
                    $config->external_store_id,
                    $store->name,
                    $product->id,
                    [
                        'externalCode' => $id,
                        'merchantId' => $config->external_store_id,
                        'order' => 1
                    ]
                );
            }

            foreach ($idsLink as $data) {
                $data['merchantId'] = $config->external_store_id;
                IfoodRequests::linkModifierGroupToItem(
                    $integrationToken->token,
                    $config->external_store_id,
                    $store->name,
                    $product->id,
                    $data
                );
            }
        }
    }

    public function allProductsAskInstructions(Request $request)
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

        $products = Product::whereHas(
            'category',
            function ($q) use ($sectionStore, $request) {
                $q->where('company_id', $sectionStore->company_id)
                    ->where('section_id', $request->section_id);
            }
        )
            ->get();

        try {
            $processJSON = DB::transaction(
                function () use ($products, $request) {
                    if (count($products) > 0) {
                        $message = "";
                        if ($request->ask) {
                            foreach ($products as $product) {
                                $product->ask_instruction = true;
                                $product->save();
                            }
                            $message = "El cuadro de instrucciones se mostrarán en todos los productos";
                        } else {
                            foreach ($products as $product) {
                                $product->ask_instruction = false;
                                $product->save();
                            }
                            $message = "El cuadro de instrucciones NO se mostrarán en todos los productos";
                        }
                        return response()->json([
                            'status' => $message,
                            'results' => null
                        ], 200);
                    } else {
                        return response()->json([
                            'status' => 'No se encontraron productos de esta tienda',
                            'results' => null
                        ], 409);
                    }
                }
            );
            return $processJSON;
        } catch (\Exception $e) {
            $this->logError(
                "ProductController API allProductsAskInstructions: ERROR INSTRUCCIONES, storeId: " . $sectionStore->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request->all())
            );
            Log::info("ProductController API allProductsAskInstructions: NO SE PUDO COMPLETAR EL PROCESO");
            Log::info($e);
            return response()->json([
                'status' => 'No se pudo completar este proceso',
                'results' => null
            ], 409);
        }
    }

    public function importExcelMenu(Request $request)
    {
        $files = $request['files'];
        $store = $this->authStore;
        Log::info("Importacion de excel por usuario: " . $store->name);
        if (!isset($files)) {
            return response()->json(
                [
                    'status' => 'Error al obtener el archivo',
                    'results' => null
                ],
                400
            );
        }
        $companyId = $store->company->id;
        $productIndex = -1;
        $categoryIndex = -1;
        $valueIndex = -1;
        $hasTaxIndex = -1;
        $skuIndex = -1;
        $specification1Index = -1;
        $specification2Index = -1;
        foreach ($files as $key => $f) {
            /// La primera iteracion es la cabecera del excel
            if ($key == 0) {
                for ($i = 0; $i < count($f); $i++) {
                    /// Obtengo los indices de cada columna necesaria para ingresar los productos
                    switch ($f[$i]) {
                        case 'Producto':
                            $productIndex = $i;
                            break;
                        case 'Categoria':
                            $categoryIndex = $i;
                            break;
                        case 'Impuesto':
                            $hasTaxIndex = $i;
                            break;
                        case 'Precio':
                            $valueIndex = $i;
                            break;
                        case 'SKU':
                            $skuIndex = $i;
                            break;
                        case 'Cat. Especificacion 1':
                            $specification1Index = $i;
                            break;
                        case 'Cat. Especificacion 2':
                            $specification2Index = $i;
                            break;
                        default:
                            break;
                    }
                }
                continue;
            }
            $newProduct = new Product();
            $useTax = 0;
            $specifications1 = null;
            $specifications2 = null;
            $productDB = null;
            for ($i = 0; $i < count($f); $i++) {
                switch ($i) {
                    case $productIndex:
                        $newProduct->name = $f[$i];
                        $newProduct->search_string = Helper::remove_accents($f[$i]);
                        $newProduct->invoice_name = mb_substr($f[$i], 0, 25, "utf-8");
                        break;
                    case $categoryIndex:
                        $productCategory = ProductCategory::where('name', 'like', $f[$i])
                            ->where('company_id', $companyId)
                            ->where('status', 1)
                            ->first();
                        if (!$productCategory) {
                            $productCategory = new ProductCategory();
                            $productCategory->name = $f[$i];
                            $productCategory->search_string = Helper::remove_accents($f[$i]);
                            $productCategory->company_id = $companyId;
                            $productCategory->save();
                        }
                        $newProduct->product_category_id = $productCategory->id;
                        break;
                    case $valueIndex:
                        $newProduct->base_value = ($f[$i]) * 100;
                        break;
                    case $hasTaxIndex:
                        if ($f[$i] === 'Si') {
                            $taxes = StoreTax::where('store_id', $store->id)->where('type', '!=', 'invoice')
                                ->pluck('id');
                            $useTax = 1;
                        } else {
                            Log::info("no tiene tax");
                        }
                        break;
                    case $skuIndex:
                        $newProduct->sku = $f[$i];
                        break;
                    case $specification1Index:
                        $specifications1 = SpecificationCategory::where('name', $f[$i])
                            ->where('status', 1)
                            ->where('company_id', $companyId)
                            ->first();
                        break;
                    case $specification2Index:
                        $specifications2 = SpecificationCategory::where('name', $f[$i])
                            ->where('status', 1)
                            ->where('company_id', $companyId)
                            ->first();
                        break;
                }
            }

            try {
                if ($newProduct->sku) {
                    $productDB = Product::where('sku', $newProduct->sku)
                        ->whereHas(
                            'product_details',
                            function ($prodDetail) use ($store) {
                                $prodDetail->where('store_id', $store->id)->where('status', 1);
                            }
                        )->first();
                    if (!$productDB) {
                        $newProduct->status = 1;
                        $newProduct->ask_instruction = 1;
                        $newProduct->save();
                    } else {
                        $newProduct = $productDB;
                    }
                } else {
                    $productDB = Product::where('name', $newProduct->name)
                        ->whereHas(
                            'product_details',
                            function ($prodDetail) use ($store) {
                                $prodDetail->where('store_id', $store->id)->where('status', 1);
                            }
                        )->first();
                    if (!$productDB) {
                        $newProduct->status = 1;
                        $newProduct->ask_instruction = 1;
                        $newProduct->save();
                    } else {
                        $newProduct = $productDB;
                    }
                }

                if ($useTax) {
                    $newProduct->taxes()->sync($taxes);
                }

                $productDetailDB = ProductDetail::where('product_id', $newProduct->id)
                    ->where('store_id', $store->id)->where('status', 1)->first();
                if (!$productDetailDB) {
                    $productDetailDB = new ProductDetail();
                    $productDetailDB->product_id = $newProduct->id;
                    $productDetailDB->store_id = $store->id;
                    $productDetailDB->status = 1;
                    $productDetailDB->stock = 0;
                    $productDetailDB->value = $newProduct->base_value;
                    $productDetailDB->save();
                }

                $componentCat = ComponentCategory::where('name', 'General')
                    ->where('company_id', $store->company->id)->where('status', 1)->first();
                if (!$componentCat) {
                    $componentCat = new ComponentCategory();
                    $componentCat->company_id = $store->company->id;
                    $componentCat->search_string = "general";
                    $componentCat->name = "General";
                    $componentCat->save();
                }

                $componentDB = Component::where('name', 'General')
                    ->where('component_category_id', $componentCat->id)->where('status', 1)->first();
                if (!$componentDB) {
                    $componentDB = new Component();
                    $componentDB->component_category_id = $componentCat->id;
                    $componentDB->name = "General";
                    $componentDB->save();
                }

                $unitDB = MetricUnit::where('name', 'Unidades')
                    ->where('company_id', $store->company->id)->where('status', 1)->first();
                if (!$unitDB) {
                    $unitDB = new MetricUnit();
                    $unitDB->name = "Unidades";
                    $unitDB->short_name = "unidades";
                    $unitDB->save();
                }

                $variationDB = Component::where('name', 'General')
                    ->with(['componentStocks'])
                    ->whereHas(
                        'componentStocks',
                        function ($q) use ($store) {
                            $q->where('store_id', $store->id);
                        }
                    )
                    ->where('component_id', $componentDB->id)
                    ->where('status', 1)->first();
                if (!$variationDB) {
                    $variationDB = new Component();
                    $variationDB->component_id = $componentDB->id;
                    $variationDB->store_id = $store->id;
                    $variationDB->name = "General";
                    $variationDB->cost = 0.00;
                    $variationDB->status = 1;
                    $variationDB->metric_unit_id = $unitDB->id;
                    $variationDB->save();
                }

                $productComponentDB = ProductComponent::where('product_id', $newProduct->id)
                    ->where('component_id', $variationDB->id)->where('status', 1)
                    ->first();
                if (!$productComponentDB) {
                    $productComponentDB = new ProductComponent();
                    $productComponentDB->product_id = $newProduct->id;
                    $productComponentDB->component_id = $variationDB->id;
                    $productComponentDB->consumption = 0.00;
                    $productComponentDB->status = 1;
                    $productComponentDB->save();
                }

                if ($specifications1) {
                    foreach ($specifications1->specifications as $specification1) {
                        $prodSpec1 = new ProductSpecification();
                        $prodSpec1->product_id = $newProduct->id;
                        $prodSpec1->specification_id = $specification1->id;
                        $prodSpec1->value = $specification1->value;
                        $prodSpec1->status = 1;
                        $prodSpec1->save();
                    }
                }

                if ($specifications2) {
                    foreach ($specifications2->specifications as $specification2) {
                        $prodSpec2 = new ProductSpecification();
                        $prodSpec2->product_id = $newProduct->id;
                        $prodSpec2->specification_id = $specification2->id;
                        $prodSpec2->value = $specification2->value;
                        $prodSpec2->status = 1;
                        $prodSpec2->save();
                    }
                }
            } catch (\Exception $e) {
                $this->logError(
                    "ProductController importExcelMenu: FALLA EN CREAR PRODUCTO, storeId: " . $store->id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $f
                );
                //// TODO: Return list of products not imported
                //// agregar el producto a lista de rechazados
            }
        }
        Log::info("Importacion de Excel finalizada");
        return response()->json(
            [
                'status' => 'Guardado exitoso',
                'results' => null
            ],
            200
        );
    }

    public function getProductsBySection(Request $request)
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

        $rowsPerPage = 12;
        $search = $request->search ? $request->search : "";

        // if ($search === "") {
        //     $products = Product::where('status', 1)->get()->pluck('id');
        // } else {
        //     $products = Product::search($search)->where('status', 1)->get()->pluck('id');
        // }
        $products = Product::select(
            'id',
            'ask_instruction',
            'base_value',
            'product_category_id',
            'name',
            'deleted_at'
        )
            ->where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($sectionStore, $request) {
                    $q->where('company_id', $sectionStore->company_id)
                        ->where('section_id', $request->section_id);
                }
            )
            ->where('name', 'LIKE', "%{$search}%")
            ->with([
                "taxes" => function ($taxes) use ($sectionStore) {
                    $taxes->where("store_id", $sectionStore->id);
                }
            ])
            ->orderBy('name', 'asc')
            ->get();

        $productsAskInstructions = $products->where('ask_instruction', 1);
        $allAskInstructions = false;
        if (count($products) == count($productsAskInstructions)) {
            $allAskInstructions = true;
        }
        $productsCollection = collect([]);
        foreach ($products as &$product) {
            $productCollection = collect($product);
            $productCollection->forget('taxes');
            $productCollection->forget('category');
            $productCollection->forget('nt_value');
            $productsCollection->push($productCollection);
        }
        $productsPage = $productsCollection->forPage($request->page, $rowsPerPage)->toArray();
        return response()->json([
            'status' => 'Listando productos',
            'results' => [
                'count' => count($productsCollection),
                'data' => array_values($productsPage),
                'allAskInstructions' => $allAskInstructions,
            ]
        ], 200);
    }

    public function infoProductMenu($id)
    {
        $user = $this->authUser;
        $isAdminFranchise = $user->isAdminFranchise();
        $store = $this->authStore;
        $productStore = Store::whereHas('sections.categories.products', function ($query) use ($id) {
            return $query->where('id', $id);
        })->first();

        $isStoreOfFranchiseMaster = FranchiseHelper::isStoreOfFranchiseMaster($productStore->id, $store->company->id, $isAdminFranchise);

        if (!$isStoreOfFranchiseMaster) {
            return response()->json([
                'status' => 'No tiene permisos para acceder a este recurso.',
                'results' => null
            ], 403);
        }

        $product = Product::where('id', $id)
            ->with(
                [
                    'productSpecifications' => function ($productSpecifications) {
                        $productSpecifications->where('status', 1)
                            ->whereHas(
                                'specification.specificationCategory',
                                function ($q) {
                                    $q->where('status', 1);
                                }
                            )
                            ->with([
                                'componentConsumption.variation.unitConsume',
                                'specification.specificationCategory'
                            ]);
                    },
                    'taxes',
                    'category'
                ]
            )
            ->where('status', 1)
            ->whereHas(
                'category',
                function ($q) use ($productStore) {
                    $q->where('company_id', $productStore->company_id);
                }
            )
            ->first();

        if (!$product) {
            return response()->json([
                'status' => 'El producto no existe',
                'results' => null
            ], 409);
        }

        $variationsProduct = ProductComponent::where('status', 1)
            ->where('product_id', $product->id)
            ->with(
                [
                    'variation.componentStocks' => function ($componentStocks) use ($productStore) {
                        $componentStocks->where('store_id', $productStore->id);
                    },
                    'variation.unitConsume',
                    'variation.productComponents' => function ($products) use ($id) {
                        $products->where('product_id', $id)->where('status', 1);
                    }
                ]
            )
            ->get();
        $listVariations = [];
        foreach ($variationsProduct as $variationProduct) {
            array_push($listVariations, $variationProduct->variation);
        }
        return response()->json([
            'status' => 'Product info',
            'results' => $product,
            'variations' => $listVariations
        ], 200);
    }
}
