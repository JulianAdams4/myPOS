<?php

namespace App\Traits\Mely;

// Libraries
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;

// Models
use App\StoreIntegrationId;
use App\StoreIntegrationToken;
use App\SpecificationCategory;
use App\ToppingIntegrationDetail;
use App\AvailableMyposIntegration;
use App\ProductToppingIntegration;


// Helpers
use App\Traits\Logs\Logging;
use App\Traits\LocaleHelper;
use App\Traits\Utility;



trait MelyRequest
{
    use LocaleHelper;
    private static $channelLogOrders = 'mely_orders_logs';
    private static $channelLogMenuUR = 'mely_menu_logs';
    private static $channelLogNormalUR = 'mely_logs';
    private static $baseUrlUR = null;
    private static $client = null;
    private static $browserUR = null;

    public static function createStore($store)
    {
        $baseUrl = config('app.mely_url_api');
        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false,
                'data' => null
            ];
        }
        $tokenObj = StoreIntegrationToken::where("store_id", $store->id)
            ->where('integration_name', "mely")
            ->where('type', 'delivery')
            ->first();
        if (!is_null($tokenObj)) {
            return [
                'message' => 'La tienda ya se encuentra creado un Store en Anton',
                'success' => false,
                'data' => null
            ];
        }
        $tokenData = MelyRequest::getAccessToken();
        if($tokenData["success"]!=true){
            return [
                'message' => 'Error al obtener token de Anton',
                'success' => false,
                'data' => null
            ];
        }
        $token =  $tokenData["data"]['data']['token_type']." ".$tokenData["data"]['data']['token'];
        $client = new Client();
        $data = [
            'name' => "MYPOS_".$store->id."_".$store->name,
            'country_code' => $store->country_code
        ];
        $response = $client->request(
            'POST',
            $baseUrl."/api/v1/store",
            [
                'headers' => ['Content-type'=>'application/json', 'Authorization'=>$token],
                'json' => $data,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();
        if ($response->getStatusCode() !== 201) {
            Logging::printLogFile(
                'Error al crear tienda en anton :'.json_encode($response->getBody()->getContents()),
                self::$channelLogNormalUR
            );
            return [
                'message' => 'No se pudo crear la tienda en Anton',
                'success' => false,
                'data' => null
            ];
        } else {
            Logging::printLogFile(
                'Tienda creada en Anton con éxito.',
                self::$channelLogNormalUR
            );
            return [
                'message' => 'Configuración realizada exitosamente.',
                'success' => true,
                'data' => json_decode($responseBody, true),
                'token' => $token
            ];
        }
    }
 
    public static function sendConfirmationStatus($status, $integration_id, $store_id, $external_store_id)
    {
    }

    public static function uploadMenu($store, $request)
    {
        
        $baseUrl = config('app.mely_url_api');
        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false
            ];
        }
        Logging::printLogFile(
            json_encode($request),
            self::$channelLogNormalUR
        );

        $integration = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', "mely")
            ->where('type', 'delivery')
            ->where('scope', $request['integration']['anton_integration'])
            ->where('token', "!=","")
            ->first();

        if ($integration == null) {
            return ([
                "message" => "Esta tienda no tiene token de esta integración",
                "code" => 409
            ]);
        }

        $store->load(
            ['sections' => function ($sections) use ($request) {
                $sections->whereHas(
                    'integrations',
                    function ($integration) use ($request) {
                        $integration->where('integration_id', $request['integration']['id']);
                    }
                )
                ->with(
                    [
                        'availabilities.periods',
                        'categories' => function ($categories) use ($request) {
                            $categories->orderBy('priority')
                            ->with(
                                [
                                'products' => function ($products) use ($request) {
                                    $products->where('status', 1)->whereHas(
                                        'integrations',
                                        function ($integrations) use ($request) {
                                            $integrations->where(
                                                'integration_name',
                                                $request['integration']['integration_name']."_".$request['integration']['anton_integration']
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
                "message" => "Esta tienda no tiene menús habilitados para ser usados en en la integración seleccionada",
                "code" => 409
            ]);
        }

        $melyMenu = [
            'store_id'=>$integration->token_type,
            'integration_id'=> $integration->scope,
            'menus' => [],
            'categories' => [],
            'items' => [],
            'modifier_groups' => []
        ];
        
        $categoriesIds = [];
        $itemsIds = [];
        $modifiersGroupsIds = [];
        
        foreach ($sections as $section) {
            $availabilities = $section->availabilities;

            $menuData = [
                'name' => $section->name,
                'schedules' => [],
                'category_external_ids' => [],
                'identifier' => strval($section->id)
            ];


            foreach ($availabilities as $availability) {
                $periods = $availability->periods;

                $availabilityData = [
                    'periods' => [],
                    'day_of_week' => Utility::staticDayOfWeek($availability->day)
                ];

                foreach ($periods as $period) {
                    $periodData = [
                        'start' => date('H:i', strtotime($period->start_time)),
                        'end' => date('H:i', strtotime($period->end_time)),
                    ];
                    array_push($availabilityData['periods'], $periodData);
                }
                array_push($menuData['schedules'], $availabilityData);
            }

            $categories = $section->categories;

            if (!(count($categories) > 0)) {
                return ([
                    "message" => "Un menú debe tener por lo menos una categoría, para subirlo",
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

                array_push($menuData['category_external_ids'], strval($category->id));
                $productCategoryData = [
                    'external_id' => strval($category->id),
                    'name' => $category->name,
                    'data_items' => []
                ];

                $products = $category->products;
                foreach ($products as $product) {
                    $productIntegrations = $product->integrations;
                    $melyProduct = null;
                    foreach ($productIntegrations as $productIntegration) {
                        if ($productIntegration->integration_name == $request['integration']['integration_name']."_".$request['integration']['anton_integration']) {
                            $melyProduct = $productIntegration;
                        }
                    }
                    if ($melyProduct == null) {
                        continue;
                    }

                    array_push(
                        $productCategoryData['data_items'],
                        [
                            'item_external_id' => strval($product->id)
                        ]
                    );

                    $productItemData = [
                        'external_id' => strval($product->id),
                        'name' => $melyProduct->name,
                        'description' => $product->description  != null ? $product->description : '',
                        'image_url' => $product->image != null ? $product->image : '',
                        'price' => [
                            'value' => strval($melyProduct->price),
                        ],
                        "data_modifier_groups" => [],
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

                        $modifierGroup = [
                            'name' => $specCategory->name,
                            'external_id' => strval($specCategory->id),
                            'data_options' => [],
                            'quantity' => [
                                'min_quantity' => strval($minPermitted),
                                'max_quantity' => strval($maxPermitted)
                            ]
                        ];

                        foreach ($prodSpecs as $spec) {
                            // Obteniendo integraciones de esa categoría de especificación
                            $toppingIntegration = ToppingIntegrationDetail::where(
                                'specification_id',
                                $spec->specification->id
                            )
                            ->where('integration_name', $request['integration']['integration_name']."_".$request['integration']['anton_integration'])
                            ->first();

                            if (!$toppingIntegration) {
                                continue;
                            }

                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                'product_integration_id',
                                $melyProduct->id
                            )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                            if (!$toppingIntegrationProduct) {
                                continue;
                            }

                            $specIdStr = strval($spec->specification->id)  . '_spec';
                            // Agregando specificaciones en el arreglo de items del menú(Sólo únicos, no duplicados)
                            if (!$specsAdded->contains($specIdStr)) {
                                $specItemData = [
                                    'external_id' => $specIdStr,
                                    'name' => $spec->specification->name,
                                    'price' => [
                                        'value' => strval($toppingIntegrationProduct->value),
                                    ],
                                    'data_modifier_groups'=>[],
                                    'quantity' => [
                                        "min_quantity" => null,
                                        "max_quantity" => null,
                                    ]
                                ];

                                if(!in_array($specIdStr, $itemsIds)){
                                    $specsAdded->push($specIdStr);
                                    array_push($melyMenu['items'], $specItemData);
                                    array_push($itemsIds, $specIdStr);
                                }
                            }
                            
                            if(!isset($modifierOptionsIds[$specCategory->id])){
                                $modifierOptionsIds[$specCategory->id] = [];
                            }
                            
                            if(!in_array($specIdStr, $modifierOptionsIds[$specCategory->id])){
                                array_push(
                                    $modifierGroup['data_options'],
                                    [
                                        'item_external_id' => $specIdStr
                                    ]
                                );

                                array_push($modifierOptionsIds[$specCategory->id], $specIdStr);
                            }
                            
                        }
                        
                        array_push($productItemData['data_modifier_groups'], [
                            "modifier_group_external_id"=> strval($specCategory->id)
                        ]);
                        
                        if (count($modifierGroup['data_options']) > 0) {
                            
                            if (!$catSpecsAdded->contains(strval($specCategory->id))) {
                                if(!in_array($specCategory->id, $modifiersGroupsIds)){
                                    array_push($melyMenu['modifier_groups'], $modifierGroup);
                                    $catSpecsAdded->push(strval($specCategory->id));

                                    array_push($modifiersGroupsIds, $specCategory->id);
                                }
                            }
                        }
                    }
                    
                    if(!in_array($product->id, $itemsIds)){
                        array_push($melyMenu['items'], $productItemData);
                        array_push($itemsIds, $product->id);
                    }
                    
                }

                if(!isset($categoriesIds[$section->id])){
                    $categoriesIds[$section->id] = [];
                }
                
                if(!in_array($category->id, $categoriesIds[$section->id])){
                    array_push($melyMenu['categories'], $productCategoryData);
                    array_push($categoriesIds[$section->id], $category->id);
                }
            }
            array_push($melyMenu['menus'], $menuData);
        }

        $client = new Client();
        $response = $client->request(
            'POST',
            $baseUrl."/api/v1/menus/upload",
            [
                'headers' => ['Content-type'=>'application/json', "Authorization"=>$integration->password ],
                'json' => $melyMenu,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                'Upload Mely: '.json_encode($responseBody->getContents()),
                self::$channelLogNormalUR
            );
            Logging::printLogFile(
                json_encode($melyMenu),
                self::$channelLogNormalUR
            );
            return ([
                "message" => "No se pudo actualizar el menú. Consulte con el soporte de myPOS",
                "code" => 409,
                "data"=> $responseBody->getContents()
            ]);
        } else {
            Logging::printLogFile(
                'Menú subido exitosamente.',
                self::$channelLogNormalUR
            );
            return ([
                "message" => "Menú actualizado exitosamente",
                "code" => 200,
                "data"=> null
            ]);
        }
    }

    public static function uploadRappiMenu($store, $request)
    {
        $baseUrl = config('app.mely_url_api');
        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false
            ];
        }

        Logging::printLogFile(
            json_encode($request),
            self::$channelLogNormalUR
        );

        $integration = StoreIntegrationToken::where('integration_name', AvailableMyposIntegration::NAME_RAPPI)
            ->where('store_id', $store->id)
            ->whereNotNull('token')
            ->first();

        if ($integration == null) {
            return ([
                "message" => "Esta tienda no tiene token de esta integración",
                "code" => 409
            ]);
        }

        $store->load(
            ['sections' => function ($sections) use ($request) {
                $sections->whereHas(
                    'integrations',
                    function ($integration) use ($request) {
                        $integration->where('integration_id', $request['integration']['id'])
                            ->where('section_id', $request['section_id']);
                    }
                )
                ->with(
                    [
                        'availabilities.periods',
                        'categories' => function ($categories) use ($request) {
                            $categories->orderBy('priority')
                            ->with(
                                [
                                'products' => function ($products) use ($request) {
                                    $products->where('status', 1)->whereHas(
                                        'integrations',
                                        function ($integrations) use ($request) {
                                            $integrations->where(
                                                'integration_name',
                                                AvailableMyposIntegration::NAME_RAPPI
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
                "message" => "Esta tienda no tiene menús habilitados para ser usados en en la integración seleccionada",
                "code" => 409
            ]);
        }

        $melyToken = StoreIntegrationToken::where("store_id", $store->id)
            ->where('integration_name', "mely")
            ->where('type', 'delivery')
            ->first();

        $melyMenu = [
            'store_id' => $melyToken->token_type,
            'integration_id'=> $request['integration']['id'],
            'menus' => [],
            'categories' => [],
            'items' => [],
            'modifier_groups' => []
        ];
        
        $categoriesIds = [];
        $itemsIds = [];
        $modifiersGroupsIds = [];
        
        foreach ($sections as $section) {
            $availabilities = $section->availabilities;

            $menuData = [
                'name' => $section->name,
                'schedules' => [],
                'category_external_ids' => [],
                'identifier' => strval($section->id)
            ];


            foreach ($availabilities as $availability) {
                $periods = $availability->periods;

                $availabilityData = [
                    'periods' => [],
                    'day_of_week' => Utility::staticDayOfWeek($availability->day)
                ];

                foreach ($periods as $period) {
                    $periodData = [
                        'start' => date('H:i', strtotime($period->start_time)),
                        'end' => date('H:i', strtotime($period->end_time)),
                    ];
                    array_push($availabilityData['periods'], $periodData);
                }
                array_push($menuData['schedules'], $availabilityData);
            }

            $categories = $section->categories;

            if (!(count($categories) > 0)) {
                return ([
                    "message" => "Un menú debe tener por lo menos una categoría, para subirlo",
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

                array_push($menuData['category_external_ids'], strval($category->id));
                $productCategoryData = [
                    'external_id' => strval($category->id),
                    'name' => $category->name,
                    'data_items' => []
                ];

                $products = $category->products;
                foreach ($products as $product) {
                    $productIntegrations = $product->integrations;
                    $melyProduct = null;
                    foreach ($productIntegrations as $productIntegration) {
                        if ($productIntegration->integration_name == $request['integration']['integration_name']) {
                            $melyProduct = $productIntegration;
                        }
                    }
                    if ($melyProduct == null) {
                        continue;
                    }

                    array_push(
                        $productCategoryData['data_items'],
                        [
                            'item_external_id' => strval($product->id)
                        ]
                    );

                    $productItemData = [
                        'external_id' => strval($product->id),
                        'name' => $melyProduct->name,
                        'description' => $product->description  != null ? $product->description : '',
                        'image_url' => $product->image != null ? $product->image : '',
                        'price' => [
                            'value' => strval($melyProduct->price),
                        ],
                        "data_modifier_groups" => [],
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

                        $modifierGroup = [
                            'name' => $specCategory->name,
                            'external_id' => strval($specCategory->id),
                            'data_options' => [],
                            'quantity' => [
                                'min_quantity' => strval($minPermitted),
                                'max_quantity' => strval($maxPermitted)
                            ]
                        ];

                        foreach ($prodSpecs as $spec) {
                            // Obteniendo integraciones de esa categoría de especificación
                            $toppingIntegration = ToppingIntegrationDetail::where(
                                'specification_id',
                                $spec->specification->id
                            )
                            ->where('integration_name', $request['integration']['integration_name'])
                            ->first();

                            if (!$toppingIntegration) {
                                continue;
                            }

                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                'product_integration_id',
                                $melyProduct->id
                            )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                            if (!$toppingIntegrationProduct) {
                                continue;
                            }

                            $specIdStr = strval($spec->specification->id)  . '_spec';
                            // Agregando specificaciones en el arreglo de items del menú(Sólo únicos, no duplicados)
                            if (!$specsAdded->contains($specIdStr)) {
                                $specItemData = [
                                    'external_id' => $specIdStr,
                                    'name' => $spec->specification->name,
                                    'price' => [
                                        'value' => strval($toppingIntegrationProduct->value),
                                    ],
                                    'data_modifier_groups'=>[],
                                    'quantity' => [
                                        "min_quantity" => null,
                                        "max_quantity" => null,
                                    ]
                                ];

                                if(!in_array($specIdStr, $itemsIds)){
                                    $specsAdded->push($specIdStr);
                                    array_push($melyMenu['items'], $specItemData);
                                    array_push($itemsIds, $specIdStr);
                                }
                            }
                            
                            if(!isset($modifierOptionsIds[$specCategory->id])){
                                $modifierOptionsIds[$specCategory->id] = [];
                            }
                            
                            if(!in_array($specIdStr, $modifierOptionsIds[$specCategory->id])){
                                array_push(
                                    $modifierGroup['data_options'],
                                    [
                                        'item_external_id' => $specIdStr
                                    ]
                                );

                                array_push($modifierOptionsIds[$specCategory->id], $specIdStr);
                            }
                            
                        }
                        
                        array_push($productItemData['data_modifier_groups'], [
                            "modifier_group_external_id"=> strval($specCategory->id)
                        ]);
                        
                        if (count($modifierGroup['data_options']) > 0) {
                            
                            if (!$catSpecsAdded->contains(strval($specCategory->id))) {
                                if(!in_array($specCategory->id, $modifiersGroupsIds)){
                                    array_push($melyMenu['modifier_groups'], $modifierGroup);
                                    $catSpecsAdded->push(strval($specCategory->id));

                                    array_push($modifiersGroupsIds, $specCategory->id);
                                }
                            }
                        }
                    }
                    
                    if(!in_array($product->id, $itemsIds)){
                        array_push($melyMenu['items'], $productItemData);
                        array_push($itemsIds, $product->id);
                    }
                    
                }

                if(!isset($categoriesIds[$section->id])){
                    $categoriesIds[$section->id] = [];
                }
                
                if(!in_array($category->id, $categoriesIds[$section->id])){
                    array_push($melyMenu['categories'], $productCategoryData);
                    array_push($categoriesIds[$section->id], $category->id);
                }
            }
            array_push($melyMenu['menus'], $menuData);
        }

        $client = new Client();
        $response = $client->request(
            'POST',
            $baseUrl."/api/v1/menus/upload",
            [
                'headers' => ['Content-type'=>'application/json', "Authorization"=>$melyToken->password ],
                'json' => $melyMenu,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                'Upload Mely: '.json_encode($responseBody->getContents()),
                self::$channelLogNormalUR
            );
            Logging::printLogFile(
                json_encode($melyMenu),
                self::$channelLogNormalUR
            );
            return ([
                "message" => "No se pudo actualizar el menú. Consulte con el soporte de myPOS",
                "code" => 409,
                "data"=> $responseBody->getContents()
            ]);
        } else {
            Logging::printLogFile(
                'Menú subido exitosamente.',
                self::$channelLogNormalUR
            );
            return ([
                "message" => "Menú actualizado exitosamente",
                "code" => 200,
                "data"=> null
            ]);
        }
    }

    public static function getAccessToken()
    {
        Logging::printLogFile(
            'Obteniendo mely token',
            self::$channelLogNormalUR
        );
        
        $clientID = config('app.mely_client_id');
        $clientSecret = config('app.mely_client_secret');
        $baseUrl = config('app.mely_url_api');
        $mail = config('app.mely_user');
        $grantType = config('app.mely_grant_type');
        $password = config('app.mely_password');
        $baseUrl = config('app.mely_url_api');

        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false,
                'data' => null
            ];
        }

        $client = new Client();
        $data = [
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'grant_type' => $grantType,
            'email' => $mail,
            'password' => $password,
        ];
        
        $response = $client->request(
            'POST',
            $baseUrl."/api/oauth/token",
            [
                'headers' => ['Content-type'=>'application/json'],
                'json' => $data,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();
        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                'Error al obtener el token access de mely',
                self::$channelLogNormalUR
            );
            return [
                'message' => 'No se pudo obtener el token de Mely.',
                'success' => false,
                'data' => null
            ];
        } else {
            Logging::printLogFile(
                'Token obtenido con éxito.',
                self::$channelLogNormalUR
            );
            return [
                'message' => 'Configuración realizada exitosamente.',
                'success' => true,
                'data' => json_decode($responseBody, true)
            ];
        }
    }

    public static function setupStore($external_store_id, $integration_id, $store_id, $token)
    {
        Logging::printLogFile(
            'SetupMely store: '.$store_id,
            self::$channelLogNormalUR
        );

        $baseUrl = config('app.mely_url_api');

        if (is_null($baseUrl)) {
            return [
                'message' => 'myPOS no tiene la configuración para esta integración.',
                'success' => false,
                'data' => null
            ];
        }

        $client = new Client();
        $data = [
            'store_id' => $store_id,
            'external_store_id' => $external_store_id,
            'integration_id' => $integration_id
        ];
        $response = $client->request(
            'POST',
            $baseUrl."/third_party/v1/integration/store/config",
            [
                'headers' => ['Content-type'=>'application/json', 'Authorization'=> $token],
                'json' => $data,
                'http_errors' => false
            ]
        );
        $responseBody = $response->getBody();

        if ($response->getStatusCode() !== 200) {
            Logging::printLogFile(
                'Error al configurar tienda por mely: '.json_encode($response->getBody()->getContents()),
                self::$channelLogNormalUR
            );
            return [
                'message' => 'No se pudo obtener el token de Mely.',
                'success' => false,
                'data' => null
            ];
        } else {
            Logging::printLogFile(
                'Tienda configurada con éxito.',
                self::$channelLogNormalUR
            );
            return [
                'message' => 'Store configurado exitosamente.',
                'success' => true,
                'data' => json_decode($responseBody, true)
            ];
        }
    }

    public static function sendStatusIntegration($storeIntegrationTokens,$status){

        $baseUrl = config('app.mely_url_api');
        $client = new Client();

        foreach($storeIntegrationTokens as $storeToken){
            if($storeToken->anton_password==null || $storeToken->anton_password==""){
                $accessToken = MelyRequest::getAccessToken();
                if($accessToken["success"]!=true){
                    return response()->json(
                        [
                            'status' => false,
                            'message' => "La tienda no tiene configurada la integración con anton"
                        ],
                        409
                    );
                }
                $token =  $accessToken["data"]['data']['token_type']." ".$accessToken["data"]['data']['token'];
                $storeToken->anton_password = $token;
                $storeToken->save();
            }else{
                $token = $storeToken->anton_password;
            }
            $integration = AvailableMyposIntegration::where('code_name', $storeToken->integration_name)->first();
            $data = [
                'integration_id' => $integration->anton_integration,
                'external_store_id' => $storeToken->external_store_id,
                "status"=>$status
            ];
            $response = $client->request(
                'POST',
                $baseUrl."/third_party/v1/integration/status",
                [
                    'headers' => ['Content-type'=>'application/json', 'Authorization'=>$token],
                    'json' => $data,
                    'http_errors' => false
                ]
            );
            $responseBody = $response->getBody();
            Logging::printLogFile(
                'Status integration requeset anton: '.$response->getStatusCode()." : ".json_encode($response->getBody()->getContents()),
                self::$channelLogNormalUR
            );
        }
    }
}
