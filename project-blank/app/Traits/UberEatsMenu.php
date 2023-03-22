<?php

namespace App\Traits;

use Log;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use App\Traits\Utility;
use App\Traits\LocaleHelper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\StoreConfig;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\AvailableMyposIntegration;
use App\ProductSpecification;
use Illuminate\Support\Facades\DB;

trait UberEatsMenu
{
    use Utility, LocaleHelper;

    public function updateUberEatsMenuV2($user, $isTesting)
    {
        $store = $user->store;

        $integration = StoreIntegrationToken::where('store_id', $user->store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
            ->where('type', 'delivery')
            ->first();

        if ($integration == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id/uuid de la tienda en la sección de Uber Eats. Acércate a tu ejecutivo de cuenta de Uber Eats para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409
            ]);
        }

        $config = StoreConfig::where('store_id', $user->store->id)
                ->first();

        if ($config == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id/uuid de la tienda en la sección de Uber Eats. Acércate a tu ejecutivo de cuenta de Uber Eats para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409
            ]);
        } elseif ($config->eats_store_id == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id/uuid de la tienda en la sección de Uber Eats. Acércate a tu ejecutivo de cuenta de Uber Eats para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409
            ]);
        }

        $localeLanguage = $this->countryToLocale(strtolower($user->store->country_code));

        $store->load(
            ['sections' => function ($sections) {
                $sections->whereHas(
                    'integrations',
                    function ($integration) {
                        $integration->where('integration_id', 1);
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
                                                AvailableMyposIntegration::NAME_EATS
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
                "message" => "Esta tienda no tiene menús habilitados para ser usados en Uber Eats",
                "code" => 409
            ]);
        }

        $uberMenu = [
            'menus' => [],
            'categories' => [],
            'items' => [],
            'modifier_groups' => [],
            'display_options' => [
            'disable_item_instructions' => false
            ]
        ];

        
        $categoriesIds = [];
        $itemsIds = [];
        $modifiersGroupsIds = [];
        
        foreach ($sections as $section) {
            $availabilities = $section->availabilities;
            // Si el menú no tiene días para el horario no se lo puede subir a Uber Eats
            if (!(count($availabilities) > 0)) {
                return ([
                    "message" => "Un menú debe tener un horario para subirlo a Uber Eats",
                    "code" => 409
                ]);
            }

            $menuData = [
                'title' => [
                    'translations' => [
                        $localeLanguage => $section->name
                    ]
                ],
                'subtitle' => [
                    'translations' => [
                        $localeLanguage => $section->subtitle
                    ]
                ],
                'service_availability' => [],
                'category_ids' => [],
                'id' => strval($section->id)
            ];


            foreach ($availabilities as $availability) {
                $periods = $availability->periods;
                // Si el día no tiene horarios no se lo puede subir a Uber Eats
                if (!(count($periods) > 0)) {
                    return ([
                        "message" => "El día" . $this->dayOfWeekES($availability->day) .
                            " de un menú, debe tener un horario para subirlo a Uber Eats",
                        "code" => 409
                    ]);
                }

                $availabilityData = [
                    'time_periods' => [],
                    'day_of_week' => $this->dayOfWeek($availability->day)
                ];

                foreach ($periods as $period) {
                    $periodData = [
                        'start_time' => date('H:i', strtotime($period->start_time)),
                        'end_time' => date('H:i', strtotime($period->end_time)),
                    ];
                    array_push($availabilityData['time_periods'], $periodData);
                }
                array_push($menuData['service_availability'], $availabilityData);
            }

            $categories = $section->categories;

            if (!(count($categories) > 0)) {
                return ([
                    "message" => "Un menú debe tener por lo menos una categoría, para subirlo a Uber Eats",
                    "code" => 409
                ]);
            }

            $specsAdded = collect([]);
            $catSpecsAdded = collect([]);
            

            $modifierOptionsIds = [];

            foreach ($categories as $category) {
                if ($category->products->count() === 0) {
                    continue;
                }

                array_push($menuData['category_ids'], strval($category->id));
                $productCategoryData = [
                    'id' => strval($category->id),
                    'title' => [
                        'translations' => [
                            $localeLanguage => $category->name
                        ]
                    ],
                    'entities' => []
                ];

                $products = $category->products;
                foreach ($products as $product) {
                    $productIntegrations = $product->integrations;
                    $uberEatsProduct = null;
                    foreach ($productIntegrations as $productIntegration) {
                        if ($productIntegration->integration_name == AvailableMyposIntegration::NAME_EATS) {
                            $uberEatsProduct = $productIntegration;
                        }
                    }
                    if ($uberEatsProduct == null) {
                        continue;
                    }

                    array_push(
                        $productCategoryData['entities'],
                        [
                            'id' => strval($product->id),
                            'type' => "ITEM"
                        ]
                    );
                    Log::info("UBER IMAGE: ".json_encode($product->image));
                    $productItemData = [
                        'id' => strval($product->id),
                        'external_data' => strval($product->id),
                        'title' => [
                            'translations' => [
                                $localeLanguage => $uberEatsProduct->name
                            ]
                        ],
                        'description' => [
                            'translations' => [
                                $localeLanguage => $product->description  != null ? $product->description : ''
                            ]
                        ],
                        'image_url' => $product->image != null ? $product->image : '',
                        'price_info' => [
                            'price' => $uberEatsProduct->price,
                            'overrides' => []
                        ],
                        'quantity_info' => [
                            "overrides" => [],
                            "quantity" => [
                                "max_permitted" => null,
                                "min_permitted" => null,
                                "default_quantity" => null,
                                "charge_above" => null,
                                "refund_under" => null
                            ]
                        ],
                        'suspension_info' => null,
                        'modifier_group_ids' => [
                            'ids' => [],
                            'overrides' => []
                        ],
                        'tax_info' => [
                            'tax_rate' => null,
                            'vat_rate_percentage' => null
                        ]
                    ];

                    // Agrupar por categoria de specs
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
                            $minPermitted = $specCategory->required;
                        }

                        // Opciones de este grupo
                        $prodSpecs = $specCategory->productSpecs;

                        // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                        $countOptionsSpec = count($prodSpecs);
                        $maxPermitted = $specCategory->max;
                        if ($specCategory->max > $countOptionsSpec && $store->company_id!=491 && $config->automatic) {
                            $maxPermitted = $countOptionsSpec;
                        }

                        $modifierGroup = [
                            'quantity_info' => [
                                'overrides' => [],
                                'quantity' => [
                                    'min_permitted' => $minPermitted,
                                    'max_permitted' => $maxPermitted,
                                    'default_quantity' => null,
                                    'charge_above' => null,
                                    'refund_under' => null
                                ]
                            ],
                            'title' => [
                                'translations' => [
                                    $localeLanguage => $specCategory->name
                                ]
                            ],
                            'external_data' => strval($specCategory->id),
                            'modifier_options' => [],
                            'id' => strval($specCategory->id),
                            'display_type' => 'expanded'
                        ];

                        foreach ($prodSpecs as $spec) {
                            // Obteniendo integraciones de esa categoría de especificación
                            $toppingIntegration = ToppingIntegrationDetail::where(
                                'specification_id',
                                $spec->specification->id
                            )
                            ->where('integration_name', AvailableMyposIntegration::NAME_EATS)
                            ->first();

                            if (!$toppingIntegration) {
                                continue;
                            }

                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                'product_integration_id',
                                $uberEatsProduct->id
                            )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                            if (!$toppingIntegrationProduct) {
                                continue;
                            }

                            $specCategoryMenu = SpecificationCategory::
                                where('id', $specCategory->id)
                                ->where('section_id', $section->id)
                                ->first();
                            
                            if (is_null($specCategoryMenu)) {
                                $toppingIntegrationProduct->delete();
                                $toppingIntegration->delete();
                                $invalidProdSpec = ProductSpecification::where(
                                    'specification_id',
                                    $spec->specification_id
                                )
                                    ->where('product_id', $spec->product_id)
                                    ->first();
                                if (!is_null($invalidProdSpec)) {
                                    $invalidProdSpec->delete();
                                }
                                continue;
                            }

                            $specIdStr = strval($spec->specification->id)  . '_spec';
                            
                            // Agregando specificaciones en el arreglo de items del menú(Sólo únicos, no duplicados)
                            if (!$specsAdded->contains($specIdStr)) {
                                $quantity=[
                                    "max_permitted" => null,
                                    "min_permitted" => null,
                                    "default_quantity" => null,
                                    "charge_above" => null,
                                    "refund_under" => null
                                ];
                                if(!$config->automatic){
                                    $quantity["max_permitted"]=$maxPermitted;
                                    $quantity["min_permitted"]=$minPermitted;
                                } 
				$specItemData = [
                                    'id' => $specIdStr,
                                    'external_data' => $specIdStr,
                                    'title' => [
                                        'translations' => [
                                            $localeLanguage => $spec->specification->name
                                        ]
                                    ],
                                    'description' => [
                                        'translations' => [
                                            $localeLanguage => ""
                                        ]
                                    ],
                                    'image_url' => "",
                                    'price_info' => [
                                        'price' => $toppingIntegrationProduct->value,
                                        'overrides' => []
                                    ],
                                    'quantity_info' => [
                                        // "overrides" => [
                                        //     [
                                        //         'context_type' => "MODIFIER_GROUP",
                                        //         'context_value' => strval($specCategory->id),
                                        //         'quantity' => [
                                        //             'min_permitted' => 0,
                                        //             'max_permitted' => $maxPermitted,
                                        //             'default_quantity' => null,
                                        //             'charge_above' => null,
                                        //             'refund_under' => null
                                        //         ]
                                        //     ]
                                        // ],
                                        "overrides" => [],
                                        "quantity" => $quantity
                                    ],
                                    'suspension_info' => null,
                                    'modifier_group_ids' => [
                                        'ids' => [],
                                        'overrides' => []
                                    ],
                                    'tax_info' => [
                                        'tax_rate' => null,
                                        'vat_rate_percentage' => null
                                    ]
                                ];

                                if(!in_array($specIdStr, $itemsIds)){
                                    $specsAdded->push($specIdStr);
                                    array_push($uberMenu['items'], $specItemData);
                                    array_push($itemsIds, $specIdStr);
                                }
                            }
                            
                            if(!isset($modifierOptionsIds[$specCategory->id])){
                                $modifierOptionsIds[$specCategory->id] = [];
                            }
                            
                            if(!in_array($specIdStr, $modifierOptionsIds[$specCategory->id])){

                                $minRequired = $specCategoryMenu->required;
                                $countProducts = $specCategoryMenu->specifications()->count();
                                array_push(
                                    $modifierGroup['modifier_options'],
                                    [
                                        'type' => "ITEM",
                                        'id' => $specIdStr
                                    ]
                                );
                                array_push($modifierOptionsIds[$specCategory->id], $specIdStr);
                                if($countProducts==1 && $minRequired>1){
                                    for ($i=0; $i < $minRequired - 1 ; $i++) { 
                                        array_push(
                                            $modifierGroup['modifier_options'],
                                            [
                                                'type' => "ITEM",
                                                'id' => $specIdStr."_".$i
                                            ]
                                        );
                                        array_push($modifierOptionsIds[$specCategory->id], $specIdStr."_".$i);
                                        $specItemDataCustom = $specItemData;
                                        $specItemDataCustom['id'] = $specIdStr."_".$i;
                                        $specItemDataCustom['external_data'] = $specIdStr."_".$i;
                                        array_push($uberMenu['items'], $specItemDataCustom);
                                    }
                                }  
                            }
                            
                        }

                        $specCategoryMenu = SpecificationCategory::
                            where('id', $specCategory->id)
                            ->where('section_id', $section->id)
                            ->first();

                        if (is_null($specCategoryMenu)) {
                            $specCategoryValid = SpecificationCategory::
                                where('id', $specCategory->id)
                                ->first();
                            if (!is_null($specCategoryValid)) {
                                $specificationsCat = $specCategoryValid->specifications;
                                foreach ($specificationsCat as $specificationCat) {
                                    $invalidProdSpec = ProductSpecification::where(
                                        'specification_id',
                                        $specificationCat->id
                                    )
                                        ->where('product_id', $product->id)
                                        ->first();
                                    if (!is_null($invalidProdSpec)) {
                                        $invalidProdSpec->delete();
                                    }
                                }
                                continue;
                            }
                        } else {
                            $specRepeat = DB::select("select count(*) as total from product_specifications ps
                            join specification_external_ids sei on ps.specification_id = sei.specification_id
                            join specifications s on ps.specification_id = s.id
                            join specification_categories sc on s.specification_category_id = sc.id and sc.id=?
                            where ps.product_id=? group by ps.specification_id;", array($specCategory->id, $productItemData['id']));
                          
                            if (count($modifierGroup['modifier_options']) > 0) {
                                if(count($specRepeat)>0){
                                    $count = intval($specRepeat[0]->total);
                                    for ($i=0; $i < $count; $i++) { 
                                        array_push($productItemData['modifier_group_ids']['ids'], strval($specCategory->id));
                                    }
                                }else{
                                    array_push($productItemData['modifier_group_ids']['ids'], strval($specCategory->id));
                                }
                            } elseif (isset($modifierOptionsIds[$specCategory->id]) && count($modifierOptionsIds[$specCategory->id]) > 0) {
                                if(count($specRepeat)>0){
                                    $count = intval($specRepeat[0]->total);
                                    for ($i=0; $i < $count; $i++) { 
                                        array_push($productItemData['modifier_group_ids']['ids'], strval($specCategory->id));
                                    }
                                }else{
                                    array_push($productItemData['modifier_group_ids']['ids'], strval($specCategory->id));
                                }
                            }  else {
                                $specCategoryValid = SpecificationCategory::
                                    where('id', $specCategory->id)
                                    ->first();
                                if (!is_null($specCategoryValid)) {
                                    $specificationsCat = $specCategoryValid->specifications;
                                    foreach ($specificationsCat as $specificationCat) {
                                        $invalidProdSpec = ProductSpecification::where(
                                            'specification_id',
                                            $specificationCat->id
                                        )
                                            ->where('product_id', $product->id)
                                            ->first();
                                        if (!is_null($invalidProdSpec)) {
                                            $invalidProdSpec->delete();
                                        }
                                    }
                                }
                            }
                        }
                        
                        if (count($modifierGroup['modifier_options']) > 0) {
                            
                            if (!$catSpecsAdded->contains(strval($specCategory->id))) {
                                if(!in_array($specCategory->id, $modifiersGroupsIds)){
                                    array_push($uberMenu['modifier_groups'], $modifierGroup);
                                    $catSpecsAdded->push(strval($specCategory->id));

                                    array_push($modifiersGroupsIds, $specCategory->id);
                                }
                            }
                        }
                    }
                    
                    if(!in_array($product->id, $itemsIds)){
                        array_push($uberMenu['items'], $productItemData);
                        array_push($itemsIds, $product->id);
                    }
                    
                }

                if(!isset($categoriesIds[$section->id])){
                    $categoriesIds[$section->id] = [];
                }
                
                if(!in_array($category->id, $categoriesIds[$section->id])){
                    array_push($uberMenu['categories'], $productCategoryData);
                    array_push($categoriesIds[$section->id], $category->id);
                }
            }
            // Log::info(json_encode($uberMenu['modifier_groups']));
            array_push($uberMenu['menus'], $menuData);
        }

        // Subiendo el menú a Uber Eats utilizando el Endpoint Upload Menu
	$jsonObject = json_encode($uberMenu, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
	//Log::info($jsonObject);

	if ($isTesting) {
            return ([
                "message" => "Menú de Uber Eats actualizado exitosamente",
                "code" => 200
            ]);
        } else {
            $baseUrl = config('app.eats_url_api');
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            $response2 = $browser->put(
                $baseUrl . 'v2/eats/stores/'. $config->eats_store_id .'/menus',
                [
                    'User-Agent' => 'Buzz',
                    'Authorization' => 'Bearer ' . $integration->token,
                    'Content-Type' => 'application/json'
                ],
                $jsonObject
            );

            if ($response2->getStatusCode() !== 204) {
                Log::info($response2->getStatusCode());
                Log::info($response2->getBody()->__toString());
                return ([
                    "message" => "No se pudo actualizar el menú a Uber Eats. Consulte con el soporte de myPOS",
                    "code" => 409
                ]);
            } else {
                return ([
                    "message" => "Menú de Uber Eats actualizado exitosamente",
                    "code" => 200
                ]);
            }
        }
    }
}
