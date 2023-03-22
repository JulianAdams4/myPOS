<?php

namespace App\Http\Controllers\Api\V2;

use App\CuponDetails;
use App\Cupons;
use App\Franchise;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Product;
use App\ProductCategory;
use App\Promotions;
use App\PromotionTypes;
use App\Section;
use App\Store;
use App\StorePromotion;
use App\StorePromotionDetails;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\Traits\ValidateToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionController extends Controller
{
    use AuthTrait, ValidateToken,LoggingHelper;
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
    public function getShopsByProducts(Request $request){
        try{
            $store = $this->authStore;
            $companies_list=array();
            if($this->authEmployee->user->isAdminFranchise()){
                $companies_list=Franchise::select('company_id')
                    ->where('origin_company_id',$store->company_id)
                    ->get()
                    ->map(function ($companies) {
                        return $companies->company_id;
                    })->toArray();
                array_push($companies_list, $store->company_id);
            }else{
                array_push($companies_list, $store->company_id);
            }
            //1) Se procede a formar el array de nombres de producto 
            $products_name=array();
            foreach ( json_decode($request->promotion_products) as $value) {
                array_push($products_name,$value->name);
            }
            if(sizeof($products_name)==0){
                return response()->json([
                    'status' => 'Debe tener productos seleccionados',
                    'results' => "null"
                ], 404);
            }
            //2) Se procede a extraer el category id de los productos
            $products_category_ids= Product::select('product_category_id','base_value')
                ->whereIn('name',$products_name)
                ->where('status',1)
                ->get()
                ->map(function ($funcion) {
                    return $funcion->product_category_id;
                })
                ->toArray();
            //3)Se procede a obtener las secciones de la tabla product_categories
            $sections_ids=ProductCategory::select('section_id')
                ->whereIn('id',$products_category_ids)
                ->where('company_id', $companies_list)
                ->groupBy('section_id')
                ->get()
                ->map(function ($funcion) {
                    return $funcion->section_id;
                })
                ->toArray();
            //4) Se procede a obtener el id de la tienda de la tabla sections
            $stores_ids= Section::select('store_id')
                ->whereIn('id',$sections_ids)
                ->groupBy('store_id')
                ->get()
                ->map(function ($funcion) {
                    return $funcion->store_id;
                })
                ->toArray();
            return response()->json([
                'status' => 'Exito',
                'results' => ['stores'=>$stores_ids]
            ], 200);
        }catch (\Exception $e) {
            Log::info("PromotionController@getShopsByProducts: No se pudo obtener las tiendas a partir de los productos");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al obtener las tiendas a partir de los productos',
                'results' => 'null'
            ], 500);
        } 
        
    }
    public function getProductsForPromotion(){
        try{
            //muestra las categorias y sus productos agrupados por nombre  
            $store = $this->authStore;
            $companies_list=array();
            //Se procede a comprobar si el usuario tiene el rol de franquicia master.
            if($this->authEmployee->user->isAdminFranchise()){
                    $companies_list=Franchise::select('company_id')
                        ->where('origin_company_id',$store->company_id)
                        ->get()
                        ->map(function ($companies) {
                            return $companies->company_id;
                        })->toArray();
                    array_push($companies_list, $store->company_id);
            }else{
                    array_push($companies_list, $store->company_id);
            }
            //1) Se traen las categorias agrupadas por nombre
            $product_categories_grouped_by_name= ProductCategory::
            select('name')
            ->whereIn('company_id',$companies_list)
            ->groupBy('name')
            ->where('status',1)
            ->get();
            //2) Por cada item del punto 1 se busca los ids de las categorias asociadas a ese nombre
            foreach ($product_categories_grouped_by_name as $category) {
                $product_categories_ids= ProductCategory::
                select('id')
                ->whereIn('company_id',$companies_list)
                ->where('name',$category->name)
                ->get()
                ->map(function ($query) {
                    return $query->id;
                });  
                
                //3) se busca los productos agrupados por nombre
                $products= Product::select('name')
                    ->whereIn('product_category_id', $product_categories_ids)
                    ->groupBy('name')
                    ->get();
                
                
                //4) para cada producto se le obtiene el base_value
                foreach ($products as $product) {
                    $info_product=Product::select('base_value')
                    ->where('name',$product->name)
                    ->first();

                    $product->base_value= $info_product->base_value;
                }
                
                $category->products= $products;
                
            } 
            return response()->json([
                'status' => 'Exito',
                'results' => ['categorias'=>$product_categories_grouped_by_name]
            ], 200);
        }catch (\Exception $e) {
            Log::info("PromotionController@getProductsForPromotion: No se pudo cargar los productos para la promoción");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al cargar los productos para la promoción',
                'results' => 'null'
            ], 500);
        } 
             
    }
    public function validatePromotion($request){
        //La promoción debe tener un tipo de promoción.
        if($request->input('promotion_type_id')=='' || $request->input('promotion_type_id')==null){
            return response()->json([
                'status' => 'Se debe seleccionar un tipo de promoción',
                'results' => "null"
            ], 404);
        }
        //Se comprueba si el tipo de promoción existe en la tabla tipo promoción
        $tipo_promocion=PromotionTypes::where('id',$request->input('promotion_type_id'))
            ->where('status','A')
            ->first();
        if($tipo_promocion==null || !isset($tipo_promocion)){
            return response()->json([
                'status' => 'El tipo de promoción no es valido.',
                'results' => "null"
            ], 404);
        }
         
        //Se comprueba que la promoción tenga un nombre
        if($request->input('name')=='' || $request->input('name')==null){
            return response()->json([
                'status' => 'La promoción debe tener un nombre.',
                'results' => "null"
            ], 404);
        }
        //Se comprueba que la promoción tenga una fecha desde 
        if($request->input('from_date')=='' || $request->input('from_date')==null){
            return response()->json([
                'status' => 'La promoción debe tener una fecha de inicio.',
                'results' => "null"
            ], 404);
        }
        //Se comprueba que la promoción tenga una fecha de fin
        if($request->input('to_date')=='' || $request->input('to_date')==null){
            return response()->json([
                'status' => 'La promoción debe tener una fecha de fin.',
                'results' => "null"
            ], 404);
        }
        //Se comprueba que la promoción tenga una hora de inicio
        if($request->input('to_time')=='' || $request->input('to_time')==null){
            return response()->json([
                'status' => 'La promoción debe tener una hora de incio.',
                'results' => "null"
            ], 404);
        }
        //Se comprueba que la promoción tenga una hora de fin
        if($request->input('to_time')=='' || $request->input('to_time')==null){
            return response()->json([
                'status' => 'La promoción debe tener una hora de fin.',
                'results' => "null"
            ], 404);
        }
        //Se comprueba si la promoción posee productos.
        //En caso de ser promocion de tipo descuento nominal y porcentual se analizara solamente el array de promotion_products
        //Debido a que solo debera traer ese array lleno, con los productos y sus valores de descuento.
        $promotion_products=$request->input('promotion_products')==null || $request->input('promotion_products')==''?[]:$request->input('promotion_products');
        if(sizeof($promotion_products)==0){
            return response()->json([
                'status' => 'La promoción no tiene productos.',
                'results' => "null"
            ], 404);
        }
         //La promoción debe tener un tipo de descuento.
        if( $request->input('promotion_type_id')==4 || $request->input('promotion_type_id')==5){
            if($request->input('discount_type_id')=='' || $request->input('discount_type_id')==null){
                return response()->json([
                    'status' => 'Se debe seleccionar un tipo de descuento',
                    'results' => "null"
                ], 404);
            }
            //Se comprueba si el tipo de descuento existe en la tabla tipo promoción
            $tipo_descuento=PromotionTypes::where('id',$request->input('discount_type_id'))
            ->where('is_discount_type',1)
            ->where('status','A')
            ->first();
            if($tipo_descuento==null || !isset($tipo_descuento)){
                return response()->json([
                    'status' => 'El tipo de descuento no es valido.',
                    'results' => "null"
                ], 404);
            }
        }
        //Se recorre los productos para determinar que posean una cantidad  y un valor de descuento.
        foreach ($promotion_products as  $product) {
            if($product['name']==''||$product['name']==null){
                return response()->json([
                    'status' => 'Los productos deben tener un nombre.',
                    'results' => "null"
                ], 404);
                break;
            }
            if($product['quantity']==0||$product['discount_value']==0){
                return response()->json([
                    'status' => 'Los productos deben tener un valor de descuento y una cantidad.',
                    'results' => "null"
                ], 404);
                break;
            }
        }
        //En el caso de ser promoción condicionada al valor se debe validar que exista un valor ingresado
        if($request->input('promotion_type_id')==5){//valor condicionado
            if($request->input('condition_value')==0 || $request->input('condition_value')=='')
            {
                return response()->json([
                    'status' => 'Cuando es una promoción de tipo condicionada a un valor debe poseer un valor condición mayor a 0',
                    'results' => "null"
                ], 404);
            }
        }
        
        //Se comprueba que existan franquicias seleccionadas para que se les aplique la promoción
        if(!$request->input('apply_to_all_stores')){
            //Se comprueba que el array de tiendas no este vacio.
            $store_promotion= $request->input('stores_promotion') ==null?[]:$request->input('stores_promotion');
            if(sizeof($store_promotion)==0){
                return response()->json([
                    'status' => 'La promoción no tiene puntos de venta asignados.',
                    'results' => "null"
                ], 404);
            }
        }
        //Se comprueba que la variable max apply este seteada.
        if($request->input('unlimited_promotion')==false){
            if($request->input('max_apply')==null || $request->input('max_apply')=='' || $request->input('max_apply')==0){
                return response()->json([
                    'status' => 'La promoción debe tener una cantidad limite de aplicaciones y en caso de ser ilimitada debera escoger la opción ilimitada.',
                    'results' => "null"
                ], 404);
            }
        }
        //En caso de ser promoción de cuponera se comprueba que tenga las variables de max_apply
        $is_cupon= $request->input('is_cupon')==null || $request->input('is_cupon')==''?false:$request->input('is_cupon');
        if($is_cupon){
            if($request->input('cupon_name')==''){
                return response()->json([
                    'status' => 'La cuponera debe tener un nombre.',
                    'results' => "null"
                ], 404);      
            }
             //En el caso de que sea una cuponera con limite se valida la variable max_apply
            if($request->input('unlimited_cupon')==false){
                if($request->input('cupon_max_apply')==null || $request->input('cupon_max_apply')=='' || $request->input('cupon_max_apply')==0){
                    return response()->json([
                        'status' => 'La cuponera debe tener un maximo numero de usos.',
                        'results' => "null"
                    ], 404);
                }
            }
           
        }
    }
    public function getPromotions(Request $request){
        try{
            
            $pageSize=$request->pageSize ==null?10:$request->pageSize;
            $page=$request->page ==null?1:$request->page;
            $typeFilter=$request->typeFilter==null?'T':$request->typeFilter;
            $stores=$request->stores==null?[]:json_decode($request->stores);

            //Retorna las promociones activas, vencidas y por aperturarse que no sean cuponera.
            //Se obtiene el id de las promociones activas a la fecha.
            $acutal_date=Carbon::now();
            $now_date= $acutal_date->format('Y-m-d');
            $promotions_actives_ids= Promotions::select('id')
                ->where('from_date','<=',$now_date) 
                ->where('to_date','>=',$now_date)
                ->where('status','A');
                
            //Se obtiene el id de las promociones vencidas a la fecha.
            $promotions_expired_ids= Promotions::select('id')
                ->where('to_date','<',$now_date)
                ->where('status','A');
            //Se obtiene el id de las promociones por aperturarse.
            $promotions_open_up_ids= Promotions::select('id')
                ->where('from_date','>',$now_date)
                ->where('status','A');

            if(sizeOf($stores)>0){//En el caso que se envíen tiendas se procede a traer las promociones activas de esa tienda
                $promotion_stores= StorePromotion::select('promotion_id')
                    ->whereIn('store_id',$stores)
                    ->where('status','A')
                    ->groupBy('promotion_id')
                    ->get()
                    ->map(function ($promotions) {
                        return $promotions->promotion_id;
                    });
                $promotions_actives_ids->whereIn('id', $promotion_stores);
                $promotions_expired_ids->whereIn('id', $promotion_stores);
                $promotions_open_up_ids->whereIn('id', $promotion_stores);
            }
            $promotions_actives_ids->get()->map(function ($promotions) {return $promotions->id;});
            $promotions_expired_ids->get()->map(function ($promotions) {return $promotions->id;});
            $promotions_open_up_ids->get()->map(function ($promotions) {return $promotions->id;});
            //Se comprueba cuales promociones estan atadas a una cuponera, puesto que el query solo mostrara las promociones que no sean cuponeras.
            //Se trae los ids de las promociones que tienen asociada una cuponera.
            $promotions_with_cupons_ids=Cupons::whereIn('promotion_id',$promotions_actives_ids)
                ->get()
                ->map(function ($cupons) {
                    return $cupons->promotion_id;
                });
            //Se procede a traer las promociones activas que no poseen cuponera.
            $promotions_actives= Promotions::with('promotion_type');   
            $promotions_expired=  Promotions::with('promotion_type');               
            $promotions_open_up=  Promotions::with('promotion_type');
                
            if(sizeof($stores)>0){
                $promotions_actives=$promotions_actives->with(["promotion_stores" => function($q)use ($stores){
                    $q->whereIn('store_promotions.store_id', $stores);
                }]);
                $promotions_expired=$promotions_expired->with(["promotion_stores" => function($q)use ($stores){
                    $q->whereIn('store_promotions.store_id', $stores);
                }]);
                $promotions_open_up=$promotions_open_up->with(["promotion_stores" => function($q)use ($stores){
                    $q->whereIn('store_promotions.store_id', $stores);
                }]);
            }else{
                $promotions_actives=$promotions_actives->with('promotion_stores');
       
                $promotions_expired=$promotions_expired->with('promotion_stores');
                
                $promotions_open_up=$promotions_open_up->with('promotion_stores');
             
            }
            $promotions_actives = $promotions_actives->whereIn('id',$promotions_actives_ids)
            ->whereNotIn('id',$promotions_with_cupons_ids)
            ->orderBy('created_at','desc')
            ->get();
            $promotions_expired =$promotions_expired->whereIn('id',$promotions_expired_ids)
            ->whereNotIn('id',$promotions_with_cupons_ids)
            ->orderBy('created_at','desc')
            ->get();
            $promotions_open_up =$promotions_open_up->whereIn('id',$promotions_open_up_ids)
            ->whereNotIn('id',$promotions_with_cupons_ids)
            ->orderBy('created_at','desc')
            ->get();
            $promotions_data=array();
            //Se procede a añadir un indicador de que es una promoción activa
            foreach ($promotions_actives as $promotion) {
                $promotion->promotion_actual_status= 'A';//Active 
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
            
                //Se insertan las promociones activas
                array_push($promotions_data, $promotion);
            }
            foreach ($promotions_open_up as $promotion) {
                $promotion->promotion_actual_status= 'O';//Open up
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
                //Se ingresan las promociones vencidas
                array_push($promotions_data, $promotion);
            }
            foreach ($promotions_expired as $promotion) {
                $promotion->promotion_actual_status= 'E';//Expired
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
                //Se ingresan las promociones por aperturar
                array_push($promotions_data, $promotion);
            }
            if($typeFilter=='T')
            {//Filtro por tipo
                usort($promotions_data, function($a, $b) {return $a->promotion_type_id > $b->promotion_type_id;});
            }else{
                if($typeFilter=='P')
                {//Producto
                    usort($promotions_data, function($a, $b) {return strcmp($a->name, $b->name);});
                }
            }
        
            //Se retorna la consulta
            return response()->json([
                'status' => 'Exito',
                'results' => ['data_count'=>sizeof($promotions_data),'data'=>array_slice($promotions_data,(($page-1)*$pageSize), $pageSize) ]
            ], 200);    
        }catch (\Exception $e) {
            Log::info("PromotionController@getPromotions: No se pudo cargar las promociones");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al cargar las promociones',
                'results' => 'null'
            ], 500);
        } 
             
    }
    public function getCupons(Request $request){
        try{
            $pageSize=$request->pageSize ==null?10:$request->pageSize;
            $page=$request->page ==null?1:$request->page;
            $typeFilter=$request->typeFilter==null?'T':$request->typeFilter;
            $stores=$request->stores==null?[]:json_decode($request->stores);
            //Retorna las promociones de cuponera activas.
            $acutal_date=Carbon::now();
            $now_date= $acutal_date->format('Y-m-d');
            //Se trae las promociones activas a la fecha.
            $promotions_actives_ids= Promotions::select('id')
                ->where('from_date','<=',$now_date) 
                ->where('to_date','>=',$now_date)
                ->where('status','A');
            //Se obtiene el id de las promociones vencidas a la fecha.
            $promotions_expired_ids= Promotions::select('id')
                ->where('to_date','<',$now_date)
                ->where('status','A');
            //Se obtiene el id de las promociones por aperturarse.
            $promotions_open_up_ids= Promotions::select('id')
                ->where('from_date','>',$now_date)
                ->where('status','A');
            if(sizeOf($stores)>0){//En el caso que se envíen tiendas se procede a traer las promociones activas de esa tienda
                $promotion_stores= StorePromotion::select('promotion_id')
                    ->whereIn('store_id',$stores)
                    ->where('status','A')
                    ->groupBy('promotion_id')
                    ->get()
                    ->map(function ($promotions) {
                        return $promotions->promotion_id;
                    });
                $promotions_actives_ids->whereIn('id', $promotion_stores);
                $promotions_expired_ids->whereIn('id', $promotion_stores);
                $promotions_open_up_ids->whereIn('id', $promotion_stores);
            }
            $promotions_actives_ids->get()->map(function ($promotions) {return $promotions->id;});
            $promotions_expired_ids->get()->map(function ($promotions) {return $promotions->id;});
            $promotions_open_up_ids->get()->map(function ($promotions) {return $promotions->id;});

            //Se comprueba cuales promociones estan atadas a una cuponera.
            if(sizeof($stores)>0){
                $promotions_actives_with_cupons=Cupons::with(["promotion.promotion_stores" => function($q)use ($stores){
                        $q->with('store_promotion_details.product')->whereIn('store_promotions.store_id', $stores);
                    }])
                    ->whereIn('promotion_id',$promotions_actives_ids)
                    ->get();
                $promotions_expired_with_cupons=Cupons::with(["promotion.promotion_stores" => function($q)use ($stores){
                     $q->with('store_promotion_details.product')->whereIn('store_promotions.store_id', $stores);
                    }])
                    ->whereIn('promotion_id',$promotions_expired_ids)
                    ->get();
                $promotions_open_up_with_cupons=Cupons::with(["promotion.promotion_stores" => function($q)use ($stores){
                        $q->with('store_promotion_details.product')->whereIn('store_promotions.store_id', $stores);
                    }])->whereIn('promotion_id',$promotions_open_up_ids)
                    ->get();
            }else{
                $promotions_actives_with_cupons=Cupons::with('promotion.promotion_stores.store_promotion_details.product')
                    ->whereIn('promotion_id',$promotions_actives_ids)
                    ->get();
                $promotions_expired_with_cupons=Cupons::with('promotion.promotion_stores.store_promotion_details.product')
                    ->whereIn('promotion_id',$promotions_expired_ids)
                    ->get();
                $promotions_open_up_with_cupons=Cupons::with('promotion.promotion_stores.store_promotion_details.product')
                    ->whereIn('promotion_id',$promotions_open_up_ids)
                    ->get();
            }
            

            $promotions_data=array();
            //Se procede a añadir un indicador de que es una promoción activa
            foreach ($promotions_actives_with_cupons as $promotion) {
                $promotion->promotion_actual_status= 'A';//Active 
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
                
                //Se insertan las promociones activas
                array_push($promotions_data, $promotion);
            }
            foreach ($promotions_open_up_with_cupons as $promotion) {
                $promotion->promotion_actual_status= 'O';//Open up
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
                //Se ingresan las promociones vencidas
                array_push($promotions_data, $promotion);
            }
            foreach ($promotions_expired_with_cupons as $promotion) {
                $promotion->promotion_actual_status= 'E';//Expired
                if(isset($promotion->promotion_stores) && $promotion->promotion_stores!=null)
                {
                    $promotion->stores_count = count($promotion->promotion_stores);
                }else{
                    $promotion->stores_count =0;
                }
                //Se ingresan las promociones por aperturar
                array_push($promotions_data, $promotion);
            }
            if($typeFilter=='T')
            {//Filtro por tipo
                usort($promotions_data, function($a, $b) {return $a->promotion->promotion_type_id > $b->promotion->promotion_type_id;});
            }else{
                if($typeFilter=='P')
                {//Producto
                    usort($promotions_data, function($a, $b) {return strcmp($a->name, $b->name);});
                }
            }
            
            return response()->json([
                'status' => 'Exito',
                'results' => ['data_count'=>sizeof($promotions_data),'data'=>array_slice($promotions_data,(($page-1)*$pageSize), $pageSize)]
            ], 200);            
        } catch (\Exception $e) {
            Log::info("PromotionController@getCupons: No se pudo cargar las cuponeras");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al cargar la cuponera',
                'results' => 'null'
            ], 500);
        } 

    }
    public function createPromotion(Request $request){
        try {
            $store = $this->authStore;
            $validacion=$this->validatePromotion($request);
            if($validacion!=null && $validacion!=''){
                return $validacion;
            }
            
            return  DB::transaction(
                function () use ($request, $store) {
                    $inputs=$request->all();
                    $is_entire_menu=false;
                    if(isset($inputs['is_entire_menu'])  && $inputs['is_entire_menu']!=null ){
                        $is_entire_menu=$inputs['is_entire_menu'];
                    }
                    $unlimited_promotion=false;
                    if(isset($inputs['unlimited_promotion'])  && $inputs['unlimited_promotion']!=null ){
                        $unlimited_promotion=$inputs['unlimited_promotion'];
                    }
                    $requiered_recipe=false;
                    if(isset($inputs['requiered_recipe']) && $inputs['requiered_recipe']!=null){
                        $requiered_recipe=$inputs['requiered_recipe'];  
                    }
                    $cupon_details= [];
                    if(isset($inputs['cupon_details']) && $inputs['cupon_details']!=null){
                        $cupon_details=$inputs['cupon_details'];  
                    }
                    $unlimited_cupon= false;
                    if(isset($inputs['unlimited_cupon']) && $inputs['unlimited_cupon']!=null){
                        $unlimited_cupon=$inputs['unlimited_cupon'];  
                    }
                    $is_cupon= false;
                    if(isset($inputs['is_cupon']) && $inputs['is_cupon']!=null){
                        $is_cupon=$inputs['is_cupon'];  
                    }
                    $discount_type_id= null;
                    if(isset($inputs['discount_type_id']) && $inputs['discount_type_id']!=null){
                        $discount_type_id=$inputs['discount_type_id'];  
                    }
                    $cupon_max_apply= null;
                    if(isset($inputs['cupon_max_apply']) && $inputs['cupon_max_apply']!=null){
                        $cupon_max_apply=$inputs['cupon_max_apply'];  
                    }
                    $from_time= '';
                    if(isset($inputs['from_time']) && $inputs['from_time']!=null){
                        $from_time=$inputs['from_time'];  
                    }
                    $to_time= '';
                    if(isset($inputs['to_time']) && $inputs['to_time']!=null){
                        $to_time=$inputs['to_time'];  
                    }
                    $condition_value=0;
                    if(isset($inputs['condition_value']) && $inputs['condition_value']!=null){
                        $condition_value=$inputs['condition_value'];
                    }
                    $products=[];
                    if(isset($inputs['products'])){
                        $products=$inputs['products'];
                    }

                    //Se pregunta si el horario aplica a todo el día
                    if($inputs['all_day_promo']){
                        $from_time='00:00';
                        $to_time='23:59';
                    }
                    $promotion = new Promotions();
                    //Se crea la promoción.
                    $promotion->company_id= $store->company_id;
                    $promotion->name= $inputs['name'];
                    $promotion->promotion_type_id= $inputs['promotion_type_id'];
                    $promotion->discount_type_id= $discount_type_id;
                    $promotion->is_entire_menu= $is_entire_menu;
                    $promotion->requiered_recipe=$requiered_recipe;
                    $promotion->is_unlimited=$unlimited_promotion;
                    $promotion->condition_value=$condition_value;
                    $promotion->max_apply=$unlimited_promotion?null:$inputs['max_apply'];
                    $promotion->times_applied=0;
                    $promotion->from_date= $inputs['from_date'];
                    $promotion->to_date= $inputs['to_date'];
                    $promotion->from_time= $from_time;
                    $promotion->to_time= $to_time;
                    
                    $promotion->status= 'A';
                    $promotion->save();
                    //Se pregunta si la promoción es aplicada a todos los puntos de ventas o a unos especificos
                    if($inputs['apply_to_all_stores']){
                        //Se comprueba si el usuario posee el rol de fraquicia master y en caso de poseerlo se obtienen todas las stores asociadas a la franquicia master.
                        $companies_list=array();
                        if($this->authEmployee->user->isAdminFranchise()){
                            $companies_list=Franchise::select('company_id')
                                ->where('origin_company_id',$store->company_id)
                                ->get()
                                ->map(function ($companies) {
                                    return $companies->company_id;
                                })->toArray();
                            array_push($companies_list, $store->company_id);
                        }else{
                            array_push($companies_list, $store->company_id);
                        }
                        //Se obtienen todos los puntos de ventas para aplicarles promoción.
                        $pdvs = Store::whereIn('company_id',$companies_list)->get();
                        foreach ($pdvs as  $pdv) {
                            $store_promotion= new StorePromotion();
                            $store_promotion->promotion_id= $promotion->id;
                            $store_promotion->store_id= $pdv->id;
                            $store_promotion->status='A';
                            $store_promotion->save();
                            if(!$is_entire_menu ){
                                //Crear el detalle de la promoción en función del array que tiene los productos condicionados o productos a los cuales se le aplicara descuento.
                                foreach ($inputs['promotion_products'] as $product) {
                                    //Se extrae el id del producto puesto que viene agrupado del front.
                                    $name=$product['name'];
                                    $product_ids= DB::select(DB::raw("select prod.id from products prod 
                                    left join product_categories prod_cat on prod_cat.id = prod.product_category_id
                                    left join sections sect on sect.id= prod_cat.section_id
                                    where prod.name = '$name'
                                    and prod_cat.company_id='$store->company_id'
                                    and sect.store_id='$store_promotion->store_id'"));
                                    foreach ($product_ids as $product_id) {
                                        $promotion_detail= new StorePromotionDetails();
                                        $promotion_detail->store_promotion_id= $store_promotion->id;
                                        $promotion_detail->product_id= $product_id->id;
                                        $promotion_detail->quantiti= $product['quantity'];
                                        $promotion_detail->cause_tax= $product['cause_tax']??false;
                                        $discount_value=$product['discount_value'];
                                        if($inputs['promotion_type_id']==3){
                                            $discount_value=100; //Se le asigna 100 debido a que el descuento sera en su totalidad del producto que se esta regalando.
                                        }
                                        $promotion_detail->discount_value= $discount_value;
                                        $promotion_detail->status= 'A';
                                        $promotion_detail->save();
                                    }
                                }
                                //Crear el detalle de la promoción en función del array que tiene los productos que habilitan la promoción.
                                if(sizeof($products)>0){
                                    foreach ($products as  $product) {
                                        //Se extrae el id del producto puesto que viene agrupado del front.
                                        $name=$product['name'];
                                        $product_ids= DB::select(DB::raw("select prod.id from products prod 
                                        left join product_categories prod_cat on prod_cat.id = prod.product_category_id
                                        left join sections sect on sect.id= prod_cat.section_id
                                        where prod.name = '$name'
                                        and prod_cat.company_id='$store->company_id'
                                        and sect.store_id='$store_promotion->store_id'"));
                                        foreach ($product_ids as $product_id) {
                                            $promotion_detail= new StorePromotionDetails();
                                            $promotion_detail->store_promotion_id= $store_promotion->id;
                                            $promotion_detail->product_id= $product_id->id;
                                            $promotion_detail->quantiti= $product['quantity'];
                                            $promotion_detail->cause_tax= false;
                                            $discount_value=$product['discount_value'];
                                            //se le asigna discount_value = 0, puesto que estos productos son los que habilitan la promoción
                                            $promotion_detail->discount_value= 0;
                                            $promotion_detail->status= 'A';
                                            $promotion_detail->save();
                                        }
                                    }
                                }
                            }
                        }

                    }else{
                        //Se asocia los puntos de venta con la promocion
                        foreach ($inputs['stores_promotion'] as  $pdv) {
                            $store_promotion= new StorePromotion();
                            $store_promotion->promotion_id= $promotion->id;
                            $store_promotion->store_id= $pdv;
                            $store_promotion->status='A';
                            $store_promotion->save();
                            //Se procede a asociar los productos a la tienda que posee promoción.
                            if(!$is_entire_menu ){
                                //Crear el detalle de la promoción.
                                foreach ($inputs['promotion_products'] as $product) {
                                    //Se extrae el id del producto puesto que viene agrupado del front.
                                    $name=$product['name'];
                                    $product_ids= DB::select(DB::raw("select prod.id from products prod 
                                    left join product_categories prod_cat on prod_cat.id = prod.product_category_id
                                    left join sections sect on sect.id= prod_cat.section_id
                                    where prod.name = '$name'
                                    and prod_cat.company_id='$store->company_id'
                                    and sect.store_id='$store_promotion->store_id'"));
                                    foreach ($product_ids as $product_id) {
                                        $promotion_detail= new StorePromotionDetails();
                                        $promotion_detail->store_promotion_id= $store_promotion->id;
                                        $promotion_detail->product_id= $product_id->id;
                                        $promotion_detail->quantiti= $product['quantity'];
                                        $promotion_detail->cause_tax= $product['cause_tax']??false;
                                        $discount_value=$product['discount_value'];
                                        if($inputs['promotion_type_id']==3){
                                            $discount_value=100; //Se le asigna 100 debido a que el descuento sera en su totalidad del producto que se esta regalando.
                                        }
                                        $promotion_detail->discount_value= $discount_value;
                                        $promotion_detail->status= 'A';
                                        $promotion_detail->save();
                                    }
                                }
                                //Crear el detalle de la promoción en función del array que tiene los productos que habilitan la promoción.
                                if(sizeof($products)>0){
                                    foreach ($products as  $product) {
                                        //Se extrae el id del producto puesto que viene agrupado del front.
                                        $name=$product['name'];
                                        $product_ids= DB::select(DB::raw("select prod.id from products prod 
                                        left join product_categories prod_cat on prod_cat.id = prod.product_category_id
                                        left join sections sect on sect.id= prod_cat.section_id
                                        where prod.name = '$name'
                                        and prod_cat.company_id='$store->company_id'
                                        and sect.store_id='$store_promotion->store_id'"));
                                        foreach ($product_ids as $product_id) {
                                            $promotion_detail= new StorePromotionDetails();
                                            $promotion_detail->store_promotion_id= $store_promotion->id;
                                            $promotion_detail->product_id= $product_id->id;
                                            $promotion_detail->quantiti= $product['quantity'];
                                            $promotion_detail->cause_tax=false;
                                            $discount_value=$product['discount_value'];
                                            //se le asigna discount_value = 0, puesto que estos productos son los que habilitan la promoción
                                            $promotion_detail->discount_value= 0;
                                            $promotion_detail->status= 'A';
                                            $promotion_detail->save();
                                        }
                                    }
                                }
                            }  
                        }
                    }
                     
                    // se pregunta si es una cuponera la que se esta creando.
                    if($is_cupon){
                        //Es una promoción cuponera.
                        //Se crea el cupon 
                        $cupon = new Cupons();
                        $cupon->promotion_id=$promotion->id;
                        $cupon->cupon_name=$inputs['cupon_name'];
                        $cupon->max_apply= $cupon_max_apply;
                        $cupon->times_applied = 0;
                        $cupon->unlimited=$unlimited_cupon;
                        $cupon->save();
                        //Se crea el detalle del cupon en caso de tenerlo (Excel).
                        foreach ($cupon_details as $cupon_item) {
                            $cupon_details_bd= new CuponDetails();
                            $cupon_details_bd->cupon_id=$cupon->id;
                            $cupon_details_bd->cupon_code= $cupon_item['cupon_code'];
                            $cupon_details_bd->save();
                        }
                    }
                    return response()->json([
                        'status' => 'Exito',
                        'results' => ['promotion_id'=>$promotion->id]
                    ], 200);
                }
            );
           
        } catch (\Exception $e) {
            Log::info("PromotionController@createPromotion: No se pudo crear la promoción");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al crear la promoción',
                'results' => 'null'
            ], 500);
        } 
    }
    public function deletePromotion(Request $request){
        //Se recupera el id de la promocion 
        $promotion_id= $request->input('id');
        if ($promotion_id==null || $promotion_id=='') {
            return response()->json([
                'status' => 'El id de promoción es requerido.',
            ], 401);
        }
        try {
            return  DB::transaction(
                function () use ($promotion_id) {
                    Promotions::where('id',$promotion_id)
                        ->update(['status'=>'I']);
                    StorePromotion::where('promotion_id',$promotion_id)
                        ->update(['status'=>'I']);
                    //Se obtiene el id de los store promotion para anular su detalla
                    $storesPromotions=StorePromotion::where('promotion_id',$promotion_id)
                        ->get()
                        ->map(function ($store) {
                            return $store->id;
                        })->toArray();
                    StorePromotionDetails::whereIn('store_promotion_id', $storesPromotions)
                        ->update(['status'=>'I']);
                    
                    return response()->json([
                        'status' => 'Exito',
                        'results' => ['promotion_id'=>$promotion_id]
                    ], 200);
            });
        } catch (\Exception $e) {
            Log::info("PromotionController@deletePromotion: No se pudo anular la promoción");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al anular la promoción',
                'results' => 'null'
            ], 500);
        } 
    }

}
