<?php

namespace App\Traits\DidiFood;

use Log;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use App\Traits\LocaleHelper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\StoreConfig;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;
use App\AvailableMyposIntegration;
use App\StoreIntegrationId;
use App\Traits\LoggingHelper;
use App\Traits\DidiFood\DidiRequests;


trait DidiFoodMenu
{
    use LocaleHelper, LoggingHelper, DidiRequests {
        LoggingHelper::printLogFile insteadof DidiRequests;
        LoggingHelper::logIntegration insteadof DidiRequests;
        LoggingHelper::sendSlackMessage insteadof DidiRequests;
        LoggingHelper::logError insteadof DidiRequests;
        LoggingHelper::simpleLogError insteadof DidiRequests;
        LoggingHelper::getSlackChannel insteadof DidiRequests;
        LoggingHelper::logModelAction insteadof DidiRequests;
    }

    public function updateDidiFoodMenu($user, $isTesting)
    {
        $store = $user->store;
        $store_config= StoreConfig::where('store_id',$store->id)->first();
        Log::info("Mandando a subir el menú a Didi para la tienda: " . $store->name);

        $integrationDidi = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_DIDI)->first();

        if ($integrationDidi == null) {
            return ([
                "message" => "myPOS no tiene configurado la integración con Didi Food",
                "code" => 409
            ]);
        }

        $configDidi = StoreIntegrationId::where('store_id', $user->store->id)
                ->where('integration_id', $integrationDidi->id)
                ->first();

        if ($configDidi == null) {
            return ([
                "message" => "Ingrese a la sección de configuración y coloque el id de la tienda en la sección de Didi Food. Acércate a tu ejecutivo de cuenta de Didi Food para obtener el id/uuid de la tienda y comunícalo a myPOS",
                "code" => 409
            ]);
        }

        $this->initVarsDidiRequests();
        $resultToken = $this->getDidiToken($configDidi->store_id, $configDidi->external_store_id);

        if (!$resultToken['success']) {
            return ([
                "message" => "No se pudo obtener el token de Didi, comuníquese con myPOS para identificar el problema",
                "code" => 409
            ]);
        }

        $store->load(
            ['sections' => function ($sections) use ($integrationDidi) {
                $sections->whereHas(
                    'integrations',
                    function ($integration) use ($integrationDidi) {
                        $integration->where('integration_id', $integrationDidi->id);
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
                                                AvailableMyposIntegration::NAME_DIDI
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
                "message" => "Esta tienda no tiene menús habilitados para ser usados en Didi Food",
                "code" => 409
            ]);
        } elseif (count($sections) > 1) {
            return ([
                "message" => "Didi Food sólo soporta un menú a la vez",
                "code" => 409
            ]);
        }

        $didiMenu = [
            'auth_token' => $resultToken['token'],
            'menus' => [],
            'categories' => [],
            'items' => []
        ];

        $soldInfo = [
            "time" => []
        ];

        $currency = $this->countryToCurrency(strtoupper($user->store->country_code));

        foreach ($sections as $section) {
            $availabilities = $section->availabilities;
            // Si el menú no tiene días para el horario no se lo puede subir a Didi Food
            if (!(count($availabilities) > 0)) {
                return ([
                    "message" => "Un menú debe tener un horario para subirlo a Didi Food",
                    "code" => 409
                ]);
            }

            $menuData = [
                'app_menu_id' => strval($section->id),
                'menu_name' => $section->name,
                'desc' => $section->subtitle,
                'app_category_ids' => [],
            ];

            $availabilityMain = $availabilities[0];
            $periods = $availabilityMain->periods;
            // Si el día no tiene horarios no se lo puede subir a Didi Food
            if (!(count($periods) > 0)) {
                return ([
                    "message" => "Se debe tener un horario para subirlo a Didi Food",
                    "code" => 409
                ]);
            }

            foreach ($periods as $period) {
                $periodData = [
                    'begin' => date('H:i', strtotime($period->start_time)),
                    'end' => date('H:i', strtotime($period->end_time)),
                ];
                array_push($soldInfo["time"], $periodData);
            }

            $categories = $section->categories;

            if (!(count($categories) > 0)) {
                return ([
                    "message" => "Un menú debe tener por lo menos una categoría, para subirlo a Didi Food",
                    "code" => 409
                ]);
            }

            foreach ($categories as $category) {
                if ($category->products->count() === 0) {
                    continue;
                }

                array_push($menuData['app_category_ids'], strval($category->id));
                $productCategoryData = [
                    'app_category_id' => strval($category->id),
                    'category_name' => $category->name,
                    'app_item_ids' => []
                ];

                $products = $category->products;
                foreach ($products as $product) {
                    $productIntegrations = $product->integrations;
                    $didiProduct = null;
                    foreach ($productIntegrations as $productIntegration) {
                        if ($productIntegration->integration_name == AvailableMyposIntegration::NAME_DIDI) {
                            $didiProduct = $productIntegration;
                        }
                    }
                    if ($didiProduct == null) {
                        continue;
                    }

                    array_push($productCategoryData['app_item_ids'], strval($product->id));

                    $productItemData = [
                        'app_item_id' => strval($product->id),
                        'app_external_id' => strval($product->id),
                        'item_name' => $didiProduct->name,
                        'short_desc' => $product->description  != null ? $product->description : '',
                        'head_img' => $product->image != null ? $product->image : '',
                        'type' => 0,
                        'additional_type' => 0,
                        'sold_info' => $soldInfo,
                        'status' => 1,
                        'price' => $didiProduct->price,
                        'currency' => $currency,
                        'content_with_sub_item' => []
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
                        $isRequiered=2;
                        if ($specCategory->required) {
                            $minPermitted = 1;
                            $isRequiered=1;
                        }

                        // Opciones de este grupo
                        $prodSpecs = $specCategory->productSpecs;

                        // Cambiando máxima cantidad de opciones si la cantidad de opciones es menor al máximo
                        $countOptionsSpec = count($prodSpecs);
                        $maxPermitted = $specCategory->max;
                        if ($specCategory->max > $countOptionsSpec && $store_config->automatic) {
                            $maxPermitted = $countOptionsSpec;
                        }

                        $contentSubItem = [
                            'app_content_id' => strval($specCategory->id),
                            'app_external_id' => strval($specCategory->id),
                            'content_name' => $specCategory->name,
                            'is_required' => $isRequiered,
                            'quantity_min_permitted' => $minPermitted,
                            'quantity_max_permitted' => $maxPermitted,
                        ];

                        $subItems = [];

                        foreach ($prodSpecs as $spec) {
                            // Obteniendo integraciones de esa categoría de especificación
                            $toppingIntegration = ToppingIntegrationDetail::where(
                                'specification_id',
                                $spec->specification->id
                            )
                            ->where('integration_name', AvailableMyposIntegration::NAME_DIDI)
                            ->first();

                            if (!$toppingIntegration) {
                                continue;
                            }

                            $toppingIntegrationProduct = ProductToppingIntegration::where(
                                'product_integration_id',
                                $didiProduct->id
                            )
                            ->where('topping_integration_id', $toppingIntegration->id)
                            ->first();
                            if (!$toppingIntegrationProduct) {
                                continue;
                            }

                            $specIdStr = strval($spec->specification->id);
                            // Agregando specificaciones en el arreglo de subitems del menú
                            $subItem = [
                                'app_sub_item_id' => $specIdStr,
                                'app_external_id' => $specIdStr,
                                'sub_item_name' => $spec->specification->name,
                                'type' => 1,
                                'status' => 1,
                                'price' => $toppingIntegrationProduct->value,
                                'currency' => $currency,
                            ];

                            array_push($subItems, $subItem);
                        }
                        if (count($subItems) > 0) {
                            $content = [
                                "content" => $contentSubItem,
                                "sub_item_list" => $subItems
                            ];
                            array_push($productItemData["content_with_sub_item"], $content);
                        }
                    }
                    array_push($didiMenu['items'], $productItemData);
                }
                array_push($didiMenu['categories'], $productCategoryData);
            }
            array_push($didiMenu['menus'], $menuData);
        }

        // Subiendo el menú a Didi Food utilizando el Endpoint Upload Menu
        $jsonObject = json_encode($didiMenu, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);
        //Log::info($jsonObject);
        if ($isTesting) {
            return ([
                "message" => "Menú de Didi Food actualizado exitosamente",
                "code" => 200
            ]);
        } else {
            $baseUrl = config('app.didi_url_api');
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            $response2 = $browser->post(
                $baseUrl . 'v1/item/item/upload',
                [
                    'User-Agent' => 'Buzz',
                    'Content-Type' => 'application/json'
                ],
                $jsonObject
            );

            $responseBody = json_decode($response2->getBody());
            $result = $this->processResponse($responseBody);
            if ($result['hasError']) {
                $this->logIntegration(
                    "Didi Error: " . json_encode($responseBody, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE),
                    "error"
                );
                $slackMessage = "Error al subir el menú a Didi\n" .
                    "Tienda: " . $user->store->name . "\n" .
                    "Error: " . $result['message'];
                $this->sendSlackMessage(
                    "#laravel_logs",
                    $slackMessage
                );
                return ([
                    "message" => $result['message'],
                    "code" => 409
                ]);
            } else {
                return ([
                    "message" => $result['message'],
                    "code" => 200
                ]);
            }
        }
    }

    public function processResponse($response)
    {
        $hasError = true;
        $message = "";
        $errorCode = $response->errno;
        switch ($errorCode) {
            case 0:
                $hasError = false;
                $message = "Menú de Didi Food actualizado exitosamente";
                break;
            case 10002:
                $message = "Error con un parámetro del JSON";
                break;
            case 10100:
                $message = "No se pudo obtener el token para la tienda";
                break;
            case 10101:
                $message = "Esta tienda no tiene token o no es un token válido";
                break;
            case 10102:
                $message = "Token expirado";
                break;
            case 14103:
                $message = "No se pudo identificar a la aplicación";
                break;
            case 14105:
                $message = "La aplicación no existe";
                break;
            case 14106:
                $message = "El secret para la aplicación no es correcto";
                break;
            default:
                $message = "Error desconocido";
                break;
        }
        return [
            "hasError" => $hasError,
            "message" => $message
        ];
    }
}
