<?php

namespace App\Traits\iFood;

// Libraries
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Carbon\Carbon;
use Log;

// Models
use App\StoreIntegrationToken;
use App\Store;
use App\AvailableMyposIntegration;
use App\StoreIntegrationId;
use App\SpecificationCategory;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;

// Jobs
use App\Jobs\IFood\UploadIfoodCategoryJob;
use App\Jobs\IFood\UploadIfoodItemJob;
use App\Jobs\IFood\UploadIfoodGroupModifierJob;
use App\Jobs\IFood\LinkIfoodModifierGroupToItemJob;
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\IFood\CreateIfoodCategoryJob;
use App\Jobs\IFood\FinishIfoodUploadMenu;

// Helpers
use App\Traits\iFood\IfoodRequests;

trait IfoodMenu
{
    public static function uploadCategories(
        Store $store,
        $integrationData,
        $config,
        $isTesting,
        $sectionId,
        $sectionIntegration
    ) {
        $store->load(
            ['sections' => function ($sections) use ($integrationData, $sectionId) {
                $sections->where('id', $sectionId)->whereHas(
                    'integrations',
                    function ($integration) use ($integrationData) {
                        $integration->where('integration_id', $integrationData->id);
                    }
                )
                ->with(
                    [
                        'availabilities.periods',
                        'categories' => function ($categories) {
                            $categories->orderBy('priority')
                            ->with(
                                [
                                'products' => function ($products) {
                                    $products->where('status', 1)->whereHas(
                                        'integrations',
                                        function ($integrations) {
                                            $integrations->where(
                                                'integration_name',
                                                AvailableMyposIntegration::NAME_IFOOD
                                            );
                                        }
                                    )->with(
                                        ['integrations', 'productSpecifications' => function ($prodSpecs) {
                                            $prodSpecs->where('status', 1);
                                        }]
                                    );
                                }]
                            );
                        }]
                );
            }]
        );

        $sections = $store->sections;

        if (!(count($sections) > 0)) {
            return ([
                "message" => "Esta tienda no tiene menús habilitados para ser usados en iFood",
                "code" => 409
            ]);
        } elseif (count($sections) > 1) {
            return ([
                "message" => "iFood sólo soporta un menú a la vez",
                "code" => 409
            ]);
        }

        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $channelLog = "ifood_logs";
        $channelSlackDev = "#integration_logs_details";
        $baseUrl = config('app.ifood_url_api');

        $uploadMenuJobs = [];
        $addedItems = [];
        $addedModifiers = [];
        $categoriesCount = 0;
        $itemsCount = 0;
        $modifierGroupsCount = 0;
        $linkModProductCount = 0;

        foreach ($sections as $section) {
            $categories = $section->categories;
            foreach ($categories as $category) {
                // if ($category->products->count() === 0) {
                //     continue;
                // }
                // Categorías de productos
                $categoryObject = [
                    "merchantId" => $config->external_store_id,
                    "availability" => "AVAILABLE",
                    "name" => $category->name,
                    "order" => $category->priority,
                    "template" => "PADRAO",
                    "externalCode" => $category->id
                ];
                array_push(
                    $uploadMenuJobs,
                    (new UploadIfoodCategoryJob(
                        $store,
                        $config->external_store_id,
                        $store->name,
                        $categoryObject,
                        $channelLog,
                        $channelSlackDev,
                        $baseUrl,
                        $browser,
                        $sectionIntegration
                    ))->delay(1)
                );
                $categoriesCount++;

                $products = $category->products;
                foreach ($products as $product) {
                    $productIntegrations = $product->integrations;
                    $iFoodProduct = null;
                    foreach ($productIntegrations as $productIntegration) {
                        if ($productIntegration->integration_name == AvailableMyposIntegration::NAME_IFOOD) {
                            $iFoodProduct = $productIntegration;
                        }
                    }
                    if ($iFoodProduct == null) {
                        continue;
                    }
                    $hasPromotion = false;
                    $promotionValue = $iFoodProduct->price / 100;
                    $originalValue = 0;
                    if ($iFoodProduct->ifoodPromotion != null) {
                        $hasPromotion = true;
                        $originalValue = $iFoodProduct->price / 100;
                        $promotionValue = $iFoodProduct->ifoodPromotion->value / 100;
                    }
                    if (!in_array($product->id, $addedItems)) {
                        // Productos
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
                                            "promotional" => $hasPromotion,
                                            "originalValue" => $originalValue,
                                            "value" => $promotionValue
                                        ]
                                    ]
                                )
                            ]
                        ];
                        array_push(
                            $uploadMenuJobs,
                            (new UploadIfoodItemJob(
                                $store,
                                $config->external_store_id,
                                $store->name,
                                $itemObject,
                                $category->id,
                                false,
                                $channelLog,
                                $channelSlackDev,
                                $baseUrl,
                                $browser,
                                $sectionIntegration,
                                $product->image,
                                $product->id
                            ))->delay(1)
                        );
                        array_push($addedItems, $product->id);
                        $itemsCount++;
                    }

                    // Grupo de modificadores
                    $prodSpecIds = $product->productSpecifications->pluck('id')->toArray();
                    $specCategories = SpecificationCategory::
                        whereHas(
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

                        if (!in_array($specCategory->id, $addedModifiers)) {
                            // Grupo de modificadores
                            $modifiersGroupObject = [
                                "merchantId" => $config->external_store_id,
                                "externalCode" => $specCategory->id,
                                "name" => $specCategory->name,
                                "sequence" => $specCategory->priority,
                                "maxQuantity" => $maxPermitted,
                                "minQuantity" => $minPermitted,
                            ];

                            array_push(
                                $uploadMenuJobs,
                                (new UploadIfoodGroupModifierJob(
                                    $store,
                                    $config->external_store_id,
                                    $store->name,
                                    $modifiersGroupObject,
                                    $channelLog,
                                    $channelSlackDev,
                                    $baseUrl,
                                    $browser,
                                    $sectionIntegration
                                ))->delay(1)
                            );
                            array_push($addedModifiers, $specCategory->id);
                            $modifierGroupsCount++;
                        }
                        
                        foreach ($prodSpecs as $spec) {
                            // Opciones de los grupos de modificadores
                            $toppingIntegration = ToppingIntegrationDetail::where(
                                'specification_id',
                                $spec->specification->id
                            )
                            ->where('integration_name', AvailableMyposIntegration::NAME_IFOOD)
                            ->first();

                            if (!$toppingIntegration) {
                                continue;
                            }

                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                'product_integration_id',
                                $iFoodProduct->id
                            )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                            if (!$toppingIntegrationProduct) {
                                continue;
                            }

                            if (!in_array("spec_" . $spec->specification->id, $addedItems)) {
                                // Opciones
                                $itemObject = [
                                    [
                                        "name" => "sku",
                                        "contents" => json_encode(
                                            [
                                                "merchantId" => $config->external_store_id,
                                                "availability" => "AVAILABLE",
                                                "externalCode" => $spec->specification->id,
                                                "name" => $spec->specification->name,
                                                "description" => '',
                                                "order" => $spec->specification->priority,
                                                "price" => [
                                                    "promotional" => false,
                                                    "originalValue" => 0,
                                                    "value" => $toppingIntegrationProduct->value / 100
                                                ]
                                            ]
                                        )
                                    ]
                                ];
                                array_push(
                                    $uploadMenuJobs,
                                    (new UploadIfoodItemJob(
                                        $store,
                                        $config->external_store_id,
                                        $store->name,
                                        $itemObject,
                                        $specCategory->id,
                                        true,
                                        $channelLog,
                                        $channelSlackDev,
                                        $baseUrl,
                                        $browser,
                                        $sectionIntegration
                                    ))->delay(1)
                                );
                                array_push($addedItems, "spec_" . $spec->specification->id);
                                $itemsCount++;
                            }
                        }

                        // Asignar el grupo de modificadores al producto
                        $linkGroupItem = [
                            "merchantId" => $config->external_store_id,
                            "externalCode" => $specCategory->id,
                            "order" => $specCategory->priority,
                            "maxQuantity" => $maxPermitted,
                            "minQuantity" => $minPermitted,
                        ];

                        array_push(
                            $uploadMenuJobs,
                            (new LinkIfoodModifierGroupToItemJob(
                                $store,
                                $config->external_store_id,
                                $store->name,
                                $linkGroupItem,
                                $product->id,
                                $channelLog,
                                $channelSlackDev,
                                $baseUrl,
                                $browser,
                                $sectionIntegration
                            ))->delay(1)
                        );
                        $linkModProductCount++;
                    }
                }
            }

            $sectionIntegration->status_sync = [
                "categories_count" => $categoriesCount,
                "items_count" => $itemsCount,
                "modifier_groups_count" => $modifierGroupsCount,
                "links_modifiers_products_count" => $linkModProductCount,
                "categories_current" => 0,
                "items_current" => 0,
                "modifier_groups_current" => 0,
                "links_modifiers_products_current" => 0,
                "finished" => false
            ];
            $sectionIntegration->save();
            array_push(
                $uploadMenuJobs,
                (new FinishIfoodUploadMenu($sectionIntegration))->delay(1)
            );
            
            EmptyJob::withChain($uploadMenuJobs)->dispatch();

            return ([
                "message" => "Se inició el proceso de subir el menú a iFood...",
                "code" => 200
            ]);
        }
    }

    public static function importIFoodMenuFromEndpoint(Store $store, $integrationData, $integrationToken, $config, $sectionId)
    {
        // Para el caso de que no exista el menú
        if ($sectionId == null) {
            return ([
                "message" => "No se encontró el menú que se desea subir el menú...",
                "code" => 409
            ]);
        }
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

        $result = IfoodRequests::getAllCategories($integrationToken->token, $store->name, $config->external_store_id);
        if ($result["status"] != 1) {
            return ([
                "message" => "No se pudo obtener el menú de iFood",
                "code" => 409
            ]);
        }

        $menu = [];
        $createCategoriesJob = [];
        // Status Sync
        // 0: No creado
        // 1: Creado
        // 2: Por actualizar

        foreach ($result["data"] as $categoryIFood) {
            if ($categoryIFood["availability"] != "AVAILABLE") {
                continue;
            }
            $prodCatStatusSync = 1;
            $idProductCategory = null;
            // Verificando si la categoría está sincronizada
            if (!isset($categoryIFood["externalCode"])) {
                $prodCatStatusSync = 0;
            } elseif ($categoryIFood["externalCode"] == "") {
                $prodCatStatusSync = 0;
            }

            // Datos de la categoría a crear en myPOS
            $categoryData = [
                "status" => $prodCatStatusSync,
                "external_id" => $categoryIFood["id"],
                "id" => $idProductCategory,
                "name" => $categoryIFood["name"],
                "position" => $categoryIFood["order"],
                "products" => []
            ];

            array_push(
                $createCategoriesJob,
                (new CreateIfoodCategoryJob(
                    $integrationToken->token,
                    $config->external_store_id,
                    $store,
                    $categoryData,
                    $integrationData,
                    $sectionId,
                    $channelLog,
                    $channelSlackDev,
                    $baseUrl,
                    $browser
                ))->delay(5)
            );
        }
        EmptyJob::withChain($createCategoriesJob)->dispatch();

        return ([
            "message" => "Se inició el proceso de importar el menú a iFood...",
            "code" => 200
        ]);
    }
}
