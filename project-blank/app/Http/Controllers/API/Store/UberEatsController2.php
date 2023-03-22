<?php

namespace App\Http\Controllers\API\Store;

use Log;
use App\Store;
use App\Helper;
use App\Product;
use App\Section;
use App\Employee;
use Buzz\Browser;
use Carbon\Carbon;
use App\StoreConfig;
use App\Specification;
use App\Traits\Utility;
use App\ProductCategory;
use App\Traits\AuthTrait;
use App\ProductExternalId;
use App\SectionIntegration;
use App\SectionAvailability;
use App\Traits\Logs\Logging;
use Illuminate\Http\Request;
use App\SpecificationCategory;
use App\StoreIntegrationToken;
use App\SpecificationExternalId;
use Buzz\Client\FileGetContents;
use App\AvailableMyposIntegration;
use App\ProductCategoryExternalId;
use App\SectionAvailabilityPeriod;
use App\Traits\mypOSMenu\MyposMenu;
use App\Http\Controllers\Controller;
use Nyholm\Psr7\Factory\Psr17Factory;
use App\SpecificationCategoryExternalId;
use App\Http\Controllers\API\Store\SectionController;

class UberEatsController extends Controller
{

    use AuthTrait, Utility, MyposMenu, Logging;

    public $authUser;
    public $authEmployee;
    public $authStore;
    public $uberIntegrationId;
    public $menusToDisable = [];

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }

        $this->uberIntegrationId = AvailableMyposIntegration::where('code_name', AvailableMyposIntegration::NAME_EATS)
                                ->where('type', 'delivery')
                                ->first()->id;
    }

    public function getUberEatsStoreMenu()
    {
        $store = $this->authStore;
        $integration = StoreIntegrationToken::where('store_id', $store->id)
                                            ->where(
                                                'integration_name',
                                                AvailableMyposIntegration::NAME_EATS
                                            )
                                            ->where('type', 'delivery')
                                            ->first();
        $config = StoreConfig::where('store_id', $store->id)
                                ->first();
        if ($integration !== null && $config !== null) {
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            $baseUrl = config('app.eats_url_api');

            $response = $browser->get(
                $baseUrl . 'v1/eats/stores/'. $config->eats_store_id . '/menu',
                [
                    'User-Agent' => 'Buzz',
                    'Authorization' => 'Bearer ' . $integration->token,
                    'Content-Type' => 'application/json'
                ]
            );

            try {
                $bodyResponse = $response->getBody()->__toString();
                $bodyJSON = json_decode($bodyResponse, true);
                return response()->json(
                    [
                        'status' => 'Menú',
                        'results' => $bodyJSON
                    ],
                    200
                );
            } catch (\Exception $e) {
                Log::info('Error al tratar de obtener el menú en uber eats');
                Log::info($e);
            }
        } else {
            return response()->json(
                [
                    'status' => 'Su tienda no está configurada para usar uber eats',
                    'results' => null
                ],
                409
            );
        }
    }

    public function saveMatchUberEatsStoreMenu(Request $request)
    {
        $store = $this->authStore;
        $integration = StoreIntegrationToken::where('store_id', $store->id)
                                            ->where(
                                                'integration_name',
                                                AvailableMyposIntegration::NAME_EATS
                                            )
                                            ->where('type', 'delivery')
                                            ->first();
        $config = StoreConfig::where('store_id', $store->id)
                                ->first();
        $product = Product::where('id', $request->product_id)->first();

        if ($integration !== null && $config !== null && $product !== null) {
            $baseUrl = config('app.eats_url_api');
            $client = new FileGetContents(new Psr17Factory());
            $browser = new Browser($client, new Psr17Factory());
            try {
                $response = $browser->get(
                    $baseUrl . 'v1/eats/stores/'. $config->eats_store_id . '/menu',
                    [
                        'User-Agent' => 'Buzz',
                        'Authorization' => 'Bearer ' . $integration->token,
                        'Content-Type' => 'application/json'
                    ]
                );
                $bodyResponse = $response->getBody()->__toString();
                $bodyJSON = json_decode($bodyResponse, true);
                foreach ($bodyJSON["sections"] as &$section) {
                    foreach ($section["subsections"] as &$subsection) {
                        foreach ($subsection["items"] as &$item) {
                            if ($item["external_id"] == $request->product_id) {
                                $item["external_id"] = null;
                            }
                            if ($section["section_id"] == $request->section_id
                                && $subsection["subsection_id"] == $request->subsection_id
                                && $item["title"] == $request->item_name
                            ) {
                                $item["external_id"] = (string) $request->product_id;
                            }
                        }
                    }
                }

                $response2 = $browser->put(
                    $baseUrl . 'v1/eats/stores/'. $config->eats_store_id .'/menu',
                    [
                        'User-Agent' => 'Buzz',
                        'Authorization' => 'Bearer ' . $integration->token,
                        'Content-Type' => 'application/json'
                    ],
                    json_encode($bodyJSON)
                );

                if ($response2->getStatusCode() === 204) {
                    $product2 = Product::where('eats_product_name', $request->item_name)->first();
                    if ($product2) {
                        $product2->eats_product_name = 'Ninguno';
                        $product2->save();
                    }
                    $product->eats_product_name = $request->item_name;
                    $product->save();
                    return response()->json(
                        [
                            'status' => "Éxito",
                            'results' => null
                        ],
                        200
                    );
                } else {
                    Log::info(json_encode($bodyJSON));
                    Log::info($response2->getStatusCode());
                    Log::info($response2->getBody()->__toString());
                    return response()->json(
                        [
                            'status' => "Ha ocurrido un error",
                            'results' => null
                        ],
                        409
                    );
                }
            } catch (\Exception $e) {
                Log::info('Error al tratar de guardar el menú en uber eats');
                Log::info($e);
            }
        } else {
            return response()->json(
                [
                    'status' => 'Su tienda no está configurada para usar uber eats',
                    'results' => null
                ],
                409
            );
        }
    }

    public function buildUberEatsMenu(Request $request)
    {
        $store = $this->authStore;

        $uberMenu = [
            'sections' => []
        ];

        $store->load(['sections' => function ($sections) {
            $sections->with(['availabilities.periods', 'categories.products' => function ($products) {
                $products->where('status', 1)->whereHas('integrations', function ($integrations) {
                    $integrations->where('integration_name', AvailableMyposIntegration::NAME_EATS);
                })->with(['integrations', 'productSpecifications' => function ($prodSpecs) {
                    $prodSpecs->where('status', 1);
                }]);
            }]);
        }]);

        $sections = $store->sections;

        foreach ($sections as $section) {
            $sectionData = [
                'service_availability' => [],
                'subtitle' => $section->subtitle,
                'subsections' => [],
                'title' => $section->name
            ];
            $availabilities = $section->availabilities;

            foreach ($availabilities as $availability) {
                $availabilityData = [
                    'enabled' => $availability->enabled ? true : false,
                    'time_periods' => [],
                    'day_of_week' => $this->dayOfWeek($availability->day)
                ];
                $periods = $availability->periods;

                foreach ($periods as $period) {
                    $periodData = [
                        'start_time' => date('H:i', strtotime($period->start_time)),
                        'end_time' => date('H:i', strtotime($period->end_time)),
                    ];
                    array_push($availabilityData['time_periods'], $periodData);
                }
                array_push($sectionData['service_availability'], $availabilityData);
            }

            $categories = $section->categories;

            foreach ($categories as $category) {
                if ($category->products->count() === 0) {
                    continue;
                }

                $categoryData = [
                    'title' => $category->name,
                    'items' => []
                ];
                $products = $category->products;

                foreach ($products as $product) {
                    $uberEatsProduct = $product->integrations[0];
                    if (!$uberEatsProduct) {
                        continue;
                    }

                    // Pendiente hasta que arreglen disable_instructions en uber eats
                    // $disabedInstructions = true;
                    // if ($product->ask_instruction == 1) {
                    //     $disabedInstructions = false;
                    // }
                    $productData = [
                        'title' => $uberEatsProduct->name,
                        'price' => $uberEatsProduct->price / 100,
                        'customizations' => [],
                        'currency_code' => $store->currency,
                        // 'tax_rate' => 0, // Comentado hasta que se defina como manejar taxes en Eats
                        'external_id' => (string) $product->id,
                        'disable_instructions' => false,
                        "item_description" => $product->description,
                        "nutritional_info" => json_decode("{}"),
                    ];

                    // Agrupar por categoria de specs
                    $prodSpecIds = $product->productSpecifications->pluck('id')->toArray();
                    $specCategories = SpecificationCategory::
                        whereHas('productSpecs', function ($prodSpec) use ($prodSpecIds) {
                            $prodSpec->whereIn('product_specifications.id', $prodSpecIds)
                                    ->where('product_specifications.status', 1);
                        })->with(['productSpecs' => function ($prodSpec) use ($prodSpecIds) {
                            $prodSpec->whereIn('product_specifications.id', $prodSpecIds)
                                    ->where('product_specifications.status', 1)->with('specification');
                        }])->get();

                    foreach ($specCategories as $specCategory) {
                        $specCategoryData = [
                            'customization_options' => [],
                            'max_permitted' => $specCategory->max,
                            // Quemado 0 ya que no manejamos categorias obligatorias (required esta quemado en 1)
                            'min_permitted' => 0,
                            'title' => $specCategory->name
                        ];
                        $prodSpecs = $specCategory->productSpecs;
                        foreach ($prodSpecs as $spec) {
                            $specData = [
                                'title' => $spec->specification->name,
                                'price' => $spec->value / 100,
                                'external_id' => (string) $spec->specification->id
                            ];
                            array_push($specCategoryData['customization_options'], $specData);
                        }
                        array_push($productData['customizations'], $specCategoryData);
                    }
                    array_push($categoryData['items'], $productData);
                }
                array_push($sectionData['subsections'], $categoryData);
            }
            array_push($uberMenu['sections'], $sectionData);
        }

        return response()->json([
            'status' => 'Menu de Uber Eats creado exitosamente.',
            'results' => $uberMenu
        ], 200);
    }

    public function syncAllMenu(Request $request)
    {
        $store = $this->authStore;

        $productsStore = Product::whereHas(
            'category',
            function ($q) use ($store) {
                $q->where('company_id', $store->company_id)
                    ->where('status', 1);
            }
        )
        ->where('status', 1)
        ->doesnthave('integrations')
        ->get();

        $productsStore2 = Product::whereHas(
            'category',
            function ($q) use ($store) {
                $q->where('company_id', $store->company_id)
                    ->where('status', 1);
            }
        )
        ->where('status', 1)
        ->get();

        return response()->json(
            [
                'status' => 'No se pudo obtener el recurso',
                'results' => null
            ],
            200
        );
    }

    public function getMenuFromUber(){
        $store = $this->authStore;
        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());

        $response = $browser->get(
            config('app.eats_url_api') . 'v2/eats/stores/'. $store->configs->eats_store_id . '/menus',
            [
                'User-Agent' => 'Buzz',
                'Authorization' => 'Bearer ' . $store->eatsIntegrationToken->token,
                'Content-Type' => 'application/json'
            ]
        );

        try {
            $bodyResponse = $response->getBody()->__toString();
		//Log::info(json_encode($bodyResponse));
            return $menuJson = json_decode($bodyResponse, true);
        } catch (\Exception $e) {
            Log::info('Error al tratar de obtener el menú en uber eats');
            Log::info($e);
        }
    }

    /**
     * Descripción: función encargada de buscar el menú solicitado. Además se setear los horarios de disponibilidad para el mismo.
     * @param store object de la clase App\Store
     * @param menu array contiene el menú original de uber
     * @param uberMenuIdInMypos int id del menú en myPOS a tratar
     * @return object App/Section
     */
    public function setSpecificMenuForStoreFromUber(Store $store, Array $menu, Int $uberMenuIdInMypos){
        $section = Section::where('id', $uberMenuIdInMypos)->first();

        //organizamos los horarios del menú
        $this->setAvailabilitiesForMenuFromUber($menu['service_availability'], $section);

        //Retoranamos el id del menú
        return $section;
    }

    /**
     * Descripción: función encargada de crear el menú donde se importará desde uber. Además de setear los horarios de disponibilidad para el mismo.
     * @param store object de la clase App\Store
     * @param menu array contiene el menú original de uber
     * @return object App/Section
     */
    public function setMenuForStoreFromUber(Store $store, Array $menu){
        
        $name = $this->setNameDescFromTranslations($menu['title']['translations'], "[D]Uber Menu Imported");
        // $now = Carbon::now();
        // $fecha = Carbon::create($now, $store->configs->time_zone)->format('Y-m-d H:i:s');
        $section = Section::create([
            'name'      => $name,
            'store_id'  => $store->id,
            'subtitle'  => ' ',
            'is_main'   => 0
        ]);

        //organizamos los horarios del menú
        $this->setAvailabilitiesForMenuFromUber($menu['service_availability'], $section);
        
        //Retoranamos el id del menú
        return $section;
    }

    /**
     * Descripción: función encargada de configurar los horarios de menú en la importación de uber
     * @param availabilities array contiene los detalles de horarios por días
     * @param section object de la clase App\Section
     * @return void
     */
    public function setAvailabilitiesForMenuFromUber(Array $availabilities, Section $section){
        foreach ($availabilities as $service) {
            // Organizamos los días
            //Buscamos el día para actualizar y si no existe lo creamos   
            $forDay = 0;
            switch ($service['day_of_week']) {
                case 'monday':
                    $forDay = 1;
                break;

                case 'tuesday':
                    $forDay = 2;
                break;

                case 'wednesday':
                    $forDay = 3;
                break;

                case 'thursday':
                    $forDay = 4;
                break;

                case 'friday':
                    $forDay = 5;
                break;

                case 'saturday':
                    $forDay = 6;
                break;

                case 'sunday':
                    $forDay = 7;
                break;
            }
            
            //Borramos todos los días actuales
            $sectionAvailability = SectionAvailability::where('section_id', $section->id)
                ->where('day', $forDay)
                ->delete();

            //Creamos los días que vienen para el menú
            $sectionAvailability = new SectionAvailability;
            $sectionAvailability->section_id = $section->id;
            $sectionAvailability->day = $forDay;
            $sectionAvailability->enabled = 1;
            $sectionAvailability->save();

            // Organizamos los horarios
            foreach ($service['time_periods'] as $period) {
                //Borramos todos los horarios actuales
                $sectionAvailabilityPeriod = SectionAvailabilityPeriod::where('section_availability_id', $sectionAvailability->id)->delete();
                
                //creamos los nuevos horarios
                $sectionAvailabilityPeriod = new SectionAvailabilityPeriod;
                $sectionAvailabilityPeriod->section_availability_id = $sectionAvailability->id;
                $sectionAvailabilityPeriod->start_time = $period['start_time'].':00';
                $sectionAvailabilityPeriod->end_time = $period['end_time'].':00';
                $sectionAvailabilityPeriod->save();
            }
        }
    }
    
    /**
     * Descripción: Recorre los menús de la tienda que tengan integración con uber eats,
     * si el/los menús enviados en $menusToActive se encuentra en la iteración (la iteración trae 
     * solo los menús con switch encendidos), serán actulizados.
     * si el/los menús enviados en $menusToDisable se encuentra en la iteración (la iteración trae 
     * solo los menús con switch encendidos), serán deshabilitados (el switch apagado).
     */
    public function disableAndEnableMenusInt($menusToActive = [], $menusToDisable = [], $storeId){
        // Log::info("menusToActive ".json_encode($menusToActive));
        // Log::info("menusToDisable ".json_encode($menusToDisable));
        $sectionController = new SectionController();

        $sectionsWithInt = SectionIntegration::whereHas('section', function ($section) use ($storeId){
                                            $section->where('store_id', $storeId);
                                        })
                                        ->where('integration_id', $this->uberIntegrationId)
                                        ->get();
        
        foreach ($sectionsWithInt as $integration) {
            // Log::info("Recorriendo menú ".json_encode($integration->section_id));
            if(in_array($integration->section_id, $menusToDisable)){
                // Log::info("Disabled menú ".json_encode($integration->section_id));
                $sectionController->disableMenuTarget($this->uberIntegrationId, $integration->section);
            }

            if(in_array($integration->section_id, $menusToActive)){
                // Log::info("Enabled menú ".json_encode($integration->section_id));
                $sectionController->enableMenuTarget($this->uberIntegrationId, $integration->section);
            }

        }
    }

    /**
     * Descripción: funcíón encargada de desencadenar todos los eventos para la importación de los menús de uber.
     * @param request object de la clase Illuminate\Http\Request
     * @return object json
     */
    public function createMenuFromUber(Request $request){
        $store = $this->authStore;

        try {
            //Traémos el menú desde Uber
            $menuJson = $this->getMenuFromUber();
            $menusToActive = [];

            //Procesamos el menú solicitado en el request
            foreach ($menuJson['menus'] as $menu) {

                //Para procesar un único menú de uber debemos recibir el id del mismo y el id del menu en mypos
                if( (
                        isset($request->uberMenuIdInUber) &&
                        !is_null($request->uberMenuIdInUber)
                    ) &&
                    (
                        isset($request->uberMenuIdInMypos) &&
                        !is_null($request->uberMenuIdInMypos)
                    )
                ){
                    // return "busca por menú específico";
                    //buscamos la coincidencia en el objeto que rescatamos de uber
                    if($menu['id'] == $request->uberMenuIdInUber){

                        //Seteamos el menú espécifico de mypos
                        $sectionInfo = $this->setSpecificMenuForStoreFromUber($store, $menu, $request->uberMenuIdInMypos);

                        // verificamos que exista el objeto de categorías
                        if(isset($menu['category_ids']) && !is_null($menu['category_ids'])){
                            //Seteamos cada categoría del menú con sus productos y especificaciones
                            $menuCategoriesWithDetails = $this->setCategoriesForMenuFromUber($menu['category_ids'], $menuJson, $sectionInfo->id);
                        }else{
                            return response()->json([
                                'status' => "No existen categorías en este menú."
                            ], 409 );
                        }

                        //rellenamos el menú desde una función global
                        $this->createAllMenu($menuCategoriesWithDetails, $store, $sectionInfo->id, $this->uberIntegrationId, false);

                        //Para actulizar la información de integración de este menú
                        array_push($menusToActive, $sectionInfo->id);

                        //Una vez procesado el menú solicitado, salimos de la iteración
                        break;
                    }

                // Si no envían $request->uberMenuIdInUber quiere decir que vamos a procesar todos los menús
                } elseif (!isset($request->uberMenuIdInUber) && is_null($request->uberMenuIdInUber)) {
                    // return "No busca por menú específico";
                    //Creamos un menú para mypos
                    $sectionInfo = $this->setMenuForStoreFromUber($store, $menu);

                    // verificamos que exista el objeto de categorías
                    if(isset($menu['category_ids']) && !is_null($menu['category_ids'])){
                        //Seteamos cada categoría del menú con sus productos y especificaciones
                        $menuCategoriesWithDetails = $this->setCategoriesForMenuFromUber($menu['category_ids'], $menuJson, $sectionInfo->id);
                    }else{
                        return response()->json([
                            'status' => "No existen categorías en este menú."
                        ], 409 );
                    }

                    //rellenamos el menú desde una función global
                    $this->createAllMenu($menuCategoriesWithDetails, $store, $sectionInfo->id, $this->uberIntegrationId, false);
                    
                    //Para actulizar la información de integración de este menú
                    array_push($menusToActive, $sectionInfo->id);
                }

            }

            // Log::info('Todos los menús donde ya se encontraron los diferentes item '.json_encode($this->menusToDisable));
            $collectMenusToDisable = collect($this->menusToDisable);
            $menusToDisableUnique = $collectMenusToDisable->unique();
            // Log::info('Unique '.json_encode($menusToDisableUnique));
            //Actulizamos la información de integración para los menús tratados
            $this->disableAndEnableMenusInt($menusToActive, $menusToDisableUnique->toArray(), $store->id);

            return response()->json([
                'status' => "Menú importado correctamente."
            ], 200 );

        } catch (\Throwable $e) {
            $this->printLogFile(
                "Error al importar menú Uber Eats| ".$store->name. " ID:". $store->id,
                'uber_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                "-"
            );

            return response()->json([
                'status' => "Algo salió mal. Contacte a soporte. "
            ], 409 );
        }
    }

    public function setCategoriesForMenuFromUber(Array $categories, $menuJson, Int $menuId){
        $store = $this->authStore;
        $menuForMypos = [];
        $indexProdCat = 0;
        // Status Sync
        // 0: No creado
        // 1: Creado
        // 2: Por actualizar

        //Hacemos una colección del detalle de las categorías
        $categoryDetails = collect($menuJson['categories']);

        foreach ($categories as $category) {
            //buscamos la categoría por id
            $category = (array) $categoryDetails->where('id', $category)->first();

            $idProductCategory = null;
            $prodCatStatusSync = 1;

            $nameCat = $this->setNameDescFromTranslations($category['title']['translations'], "[D]Especificación");

            //buscamos la categoría
            $productCategory = ProductCategory::where('name','like',"%".$nameCat."%")
                ->where('section_id', $menuId)
                ->first();

            if (is_null($productCategory)) {
                $prodCatStatusSync = 0;
            } else {
                $idProductCategory = $productCategory->id;
            }

            $productCatData = [
                "status" => $prodCatStatusSync,
                "external_id" => $category['id'],
                "id" => $idProductCategory,
                "name" => $nameCat,
                "position" => $indexProdCat,
                "products" => $this->setProductsForMenuFromUber($category, $menuJson, $menuId)
            ];

            array_push($menuForMypos, $productCatData);
            $indexProdCat++;
        }
        return $menuForMypos;
    }

    public function setProductsForMenuFromUber(Array $category, Array $menu, Int $menuId){
        $store = $this->authStore;
        $productsForMenuMypos = [];
        $collectProductsForMenuMypos = collect($productsForMenuMypos);

        $products = $category['entities'];
        $indexProductCat = 0;

        //Hacemos una colección del detalle de los productos
        $productDetails = collect($menu['items']);

        //Recorremos el arreglo de ids de los productos de cada categoría
        foreach ($products as $product) {
            //buscamos el producto por id
            $product = (array) $productDetails->where('id', $product['id'])->first();

            $productStatusSync = 1;
            $idProduct = null;
            $externalData = isset($product['external_data']) ? $product['external_data'] : 0;

            //revisamos si este producto ya se encuentra incluido en un menú para deshabilitar la integración de ese menú
            $checkProductUber = Product::where('id', $externalData)
                                    ->with('category')
                                    ->first();
            if(isset($checkProductUber->category) && $checkProductUber->category['section_id'] != $menuId){
                // Log::info('Producto '.$product['external_data'].' encontrado en el menú '.$checkProductUber->category['section_id']);
                array_push($this->menusToDisable, $checkProductUber->category['section_id']);
            }

            //Buscamos el producto por id y en el menú actual
            $productUber = Product::where('id', $externalData)
                            ->whereHas('category', function ($q) use ($menuId){
                                $q->where('section_id', $menuId);
                            })->first();

            if (is_null($productUber)) {
                $productStatusSync = 0;
                $isAllSync = false;
            } else {
                $idProduct = $productUber->id;
            }

            $price = (float) $product['price_info']['price'];
            // $valueWithTax = $price * 100;
            $valueRound = Helper::bankersRounding($price, 4);

            //Si no existe el grupo de ids entonces lo pasamos como vacío
            $callAndSetModifiersCat = !isset($product['modifier_group_ids']['ids']) ?
                 [] :
                 $this->setModifiersCatForMenuFromUber($product['modifier_group_ids']['ids'], $menu, $menuId);
            
            $nameProduct = $this->setNameDescFromTranslations($product['title']['translations'], "[D]Producto");
            $descProduct = $this->setNameDescFromTranslations($product['description']['translations'], "[D]Desc Producto");
	    $descProduct = substr($descProduct, 0, 190);
            $productData = [
                "status" => $productStatusSync,
                "external_id" => $externalData,
                "id" => $idProduct,
                "name" => $nameProduct,
                "description" => $descProduct,
                "image" => (isset($product['image_url']) && $product['image_url'] !== "") ? $product['image_url'] : null,
                "position" => $indexProductCat,
                "price" => $valueRound,
                "taxName" => null,
                "taxRate" => null,
                "modifiers" => $callAndSetModifiersCat
            ];

            $indexProductCat++;
            array_push($productsForMenuMypos, $productData);
        }

        return $productsForMenuMypos;
    }

    public function setModifiersCatForMenuFromUber(Array $modifiersCat, Array $menu, Int $menuId){
        $store = $this->authStore;
        $modifiersCatForMenuMypos = [];
        $indexModifiersCat = 0;
        //Hacemos una colección del detalle de los grupos modificadores
        $modifiersCatDetails = collect($menu['modifier_groups']);

        //Recorremos el arreglo de ids de los grupos modificadores de cada producto
        foreach ($modifiersCat as $modifierCat) {
            //buscamos la categoría del modificador por id
            $modifierCat = (array) $modifiersCatDetails->where('id', $modifierCat)->first();

            $modifierCatStatusSync = 1;
            $idModifierCat = null;
            $externalData = isset($modifierCat['external_data']) ? $modifierCat['external_data'] : 0;

            //revisamos si esta categoría de especificación ya se encuentra incluido en un menú para deshabilitar la integración de ese menú
            $checkCatSpec = SpecificationCategory::where('id', $externalData)
                            ->where('section_id','!=',$menuId)
                            ->first();
            if(!is_null($checkCatSpec)){
                // Log::info('Cat Spec '.$modifierCat['external_data'].' encontrado en el menú '.$checkCatSpec->section_id);
                array_push($this->menusToDisable, $checkCatSpec->section_id);
            }

            //Buscamos la categoría del modificador por id y en el menú actual
            $modifierCatUber = SpecificationCategory::where('id', $externalData)
                                ->where('section_id', $menuId)->first();

            if (is_null($modifierCatUber)) {
                $modifierCatStatusSync = 0;
            } else {
                $idModifierCat = $modifierCatUber->id;
            }

            //$maxModifierCat = (string) $modifierCat['quantity_info']['quantity']['max_permitted'];
            //$minModifierCat = (string) $modifierCat['quantity_info']['quantity']['min_permitted'];
	    $minModifierCat = "0";
            if(isset($modifierCat['quantity_info']['quantity']['min_permitted'])){
                $minModifierCat = (string) $modifierCat['quantity_info']['quantity']['min_permitted'];
            }
            $maxModifierCat = "0";
            if(isset($modifierCat['quantity_info']['quantity']['max_permitted'])){
                $maxModifierCat = (string) $modifierCat['quantity_info']['quantity']['max_permitted'];
            }

            //Si no existe el grupo de ids entonces lo pasamos como vacío
            $callAndSetModifiers = !isset($modifierCat['modifier_options']) ?
                 [] :
                 $this->setModifiersForMenuFromUber($modifierCat['modifier_options'], $menu, $menuId);

            $modifierCatData = [
                "status" => $modifierCatStatusSync,
                "external_id" => $externalData,
                "id" => $idModifierCat,
                "name" => $this->setNameDescFromTranslations($modifierCat['title']['translations'], "[D]Cat. Modificador"),
                "min" => $minModifierCat,
                "max" => $maxModifierCat,
                "position" => $indexModifiersCat,
                "added_options" => $callAndSetModifiers['added_options'],
                "options" => $callAndSetModifiers['options']
            ];

            $indexModifiersCat++;
            array_push($modifiersCatForMenuMypos, $modifierCatData);
        }

        return $modifiersCatForMenuMypos;
    }

    public function setModifiersForMenuFromUber(Array $modifiersOptions, Array $menu, Int $menuId){
        $store = $this->authStore;
        $addedModifiersForMenuMypos = [];
        $modifiersForMenuMypos = [];

        //Hacemos una colección de las opciones de los modificadores
        $modifiersDetails = collect($menu['items']);
        $indexModifier = 0;

        foreach ($modifiersOptions as $modifier) {
            //buscamos el modificador por id
            $modifier = (array) $modifiersDetails->where('id', $modifier['id'])->first();

            $modifierExternalData = null;
            if (isset($modifier['external_data'])) {
                $modifierExternalData = explode("_spec", $modifier['external_data']);
            }

            $modifierStatusSync = 1;
            $idModifier = null;
            $externalData = isset($modifierExternalData[0]) ? $modifierExternalData[0] : 0;

            //revisamos si este modificador ya se encuentra incluido en un menú para deshabilitar la integración de ese menú
            $checkModifier = Specification::where('id', $externalData)
                            ->with('specificationCategory')
                            ->first();
            if(isset($checkModifier->specificationCategory) && $checkModifier->specificationCategory['section_id'] != $menuId){
                // Log::info('Spec '.$externalData.' encontrado en el menú '.$checkModifier->specificationCategory['section_id']);
                array_push($this->menusToDisable, $checkModifier->specificationCategory['section_id']);
            }

            //Buscamos el modificador por id y en el menú actual
            $modifierUber = Specification::where('id', $externalData)
                                ->whereHas('specificationCategory', function ($q) use ($menuId){
                                    $q->where('section_id', $menuId);
                                })
                                ->first();
            $nameModifier = $this->setNameDescFromTranslations($modifier['title']['translations'], "[D]Modificador");

            if (is_null($modifierUber)){
                $modifierStatusSync = 0;
            }else{
                $idModifier = $modifierUber->id;
            }

            $price = (float) $modifier['price_info']['price'];
            $value = Helper::bankersRounding($price, 4);
            $modifierData = [
                "status" => $modifierStatusSync,
                "external_id" => $externalData,
                "id" => $idModifier,
                "name" => $nameModifier,
                "position" => $indexModifier,
                "price" => $value,
                "spec_prod_price" => $value,
            ];

            $indexModifier++;
            array_push($modifiersForMenuMypos, $modifierData);
            array_push($addedModifiersForMenuMypos, $modifier['id']);
        }
        
        return ["options" => $modifiersForMenuMypos, "added_options" => $addedModifiersForMenuMypos];
    }

    /**
     * Descripción: retorna el valor más acertado del objeto translations para nombres o descripciones.
     * @param translationObj array con todas las posibles traducciones
     * @param defaultName string con nombre por defecto a devolver
     * @return string
     */
    public function setNameDescFromTranslations(Array $translationObj, String $defaultName){
        $store = $this->authStore;

        //Buscamos el key en español y por país
        $keySpanishByCountry = "es_".$store->country_code;
        if(array_key_exists($keySpanishByCountry, $translationObj)){
            return $translationObj[$keySpanishByCountry];
        }

        //Devolvemos el primero que no esté vació, si todos están vacíos retorna el valor por defecto
        foreach ($translationObj as $languaje => $translation) {
            if(!is_null($translation))
                return $translation;
        }

        //retornamos el valor por defecto
        return $defaultName;
    }

    public function getNamesFromUberMenus(Request $request){
        try {
            $menus = $this->getMenuFromUber();
        } catch (\Throwable $e) {
            $this->printLogFile(
                "Error al importar menú Uber Eats| ".$store->name. " ID:". $store->id,
                'uber_logs',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                "-"
            );

            return response()->json([
                'status' => "No es posible traer los nombres de menú desde Uber Eats. Contacte a soporte."
            ], 409 );
        }

        if(count($menus['menus']) <= 0){
            return response()->json([
                'status' => "No existen menús en Uber Eats para esta tienda."
            ], 409 );
        }

        $responseNames = [];
        foreach ($menus['menus'] as $menu) {
            $menuName = $this->setNameDescFromTranslations($menu['title']['translations'], "[Default] Menú uber");
            array_push($responseNames, 
            [
                "id" => $menu['id'],
                "name" => $menuName
            ]);
        }
        
        return response()->json([
            'data' => $responseNames
        ], 200 );
    }
}
