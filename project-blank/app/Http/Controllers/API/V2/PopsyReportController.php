<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Spot;
use App\Store;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\Traits\TimezoneHelper;
use App\Traits\ValidateToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
class PopsyReportController extends Controller
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
    /* 
        * @desc retorna el total de ordenes, cantidad  y ventas agrupados por día, producto y tienda.
    */
    public function getPopsyCategoriesReport(Request $request){
        try{
            $store = $this->authStore;
            $store_ids= json_decode($request->store_ids);
            $start_date= $request->start_date;
            $end_date=$request->end_date;
            $all_stores= $request->all_stores=='true'?true:false;   
            if($all_stores)
            {
                //Se procede a traer todas las tiendas de la compañia
                $store_ids= Store::
                    where('company_id',$store->company_id)
                    ->get()
                    ->map(function ($store) {
                        return $store->id;
                    })->toArray();
            }
            $timezone = TimezoneHelper::getStoreTimezone($store);
            //Se obtiene el intervalo real
            $startDate = Carbon::parse($start_date)->startOfDay()->setTimezone($timezone);
            $endDate = Carbon::parse($end_date)->endOfDay()->setTimezone($timezone);
            
            //Se recuperan los registros en el rango de fecha establecido.
            $products= collect(DB::select(DB::raw("
            select ord.store_id, prodcat.name , sum(ordet.total) as total_sales,count(distinct ord.id) as orders_quantity, 
                sum(ordet.quantity) as total_quantity, 
                ord.created_at as date
            from orders ord
                inner join order_details ordet on ordet.order_id = ord.id
                inner join product_details  prodet on prodet.id = ordet.product_detail_id
                inner join products prod on prod.id = prodet.product_id
                inner join product_categories prodcat on prodcat.id = prod.product_category_id
            where 
                ord.store_id in('".implode("','", $store_ids)."')
                and ord.created_at between '$startDate' and '$endDate'
                group by ord.store_id, prodcat.name, ord.created_at
                order by ord.store_id asc;")));
                 //Se procede a insertar la fecha real en cada item
            foreach ($products as $key => $product) {
                $datemod=TimezoneHelper::localizedDateForStore($product->date,$store);
                $product->real_date_time=$datemod->format('Y-m-d H:i:s');
                $product->real_date=$datemod->format('Y-m-d');
            }
            //Se agrupa por id tiendas, nombre_producto, real_data.
            $grouped_products_date= $products->groupBy(
                [
                    'store_id',
                    'name',
                    'real_date'                   
                ]
            );
            $finalData= array();
            $arrayIndex=0;
            //Se recorre la colección para acumular los totale y adicionarlos a un array.
            $grouped_products_date->each(function ($shop, $key) use(&$finalData,&$arrayIndex){
                //Se obtiene los datos de la tienda
                $shopDataBase= Store::where('id',$key)->first();
                $shopData= new stdClass();
                $shopData->store_id=$key;
                $shopData->store_name=$shopDataBase->name;
                $shopData->datos=array();
                array_push($finalData,$shopData);
                  $shop->each(function ($categories, $key) use(&$finalData,$arrayIndex){
                   
                       $categories->each(function ($date, $key2) use(&$finalData,$arrayIndex){  
                           $total_quantity= 0;
                           $total_sales=0;
                           $total_orders_quantity= 0;
                           $name_category='';
                           $date->each(function ($finalItem, $key) use(&$total_quantity,&$total_sales,&$total_orders_quantity,&$name_category){ 
                               //Se crea el registro de la tienda con sus productos
                               $name_category=$finalItem->name;
                               $total_quantity=$total_quantity+intval($finalItem->total_quantity);
                               $total_sales=$total_sales+floatval($finalItem->total_sales);
                               $total_orders_quantity=$total_orders_quantity+intval($finalItem->orders_quantity);
                           });
                           $cate= new stdClass();
                           $cate->date=$key2;
                           $cate->name_category=$name_category;
                           $cate->total_quantity=$total_quantity;
                           $cate->total_sales=$total_sales;
                           $cate->total_orders_quantity=$total_orders_quantity;
   
                           array_push($finalData[$arrayIndex]->datos,$cate);
   
                       });
   
                  });
                  $arrayIndex++;
               });
           
            
            return response()->json(['status' =>'Exito', 'result'=>$finalData], 200);
        }catch (\Exception $e) {
            Log::info("PopsyController@getPopsyCategoriesReport: No se pudo obtener el reporte popsy con tipo producto, fecha desde ".$start_date. "fecha hasta ".$end_date. " tiendas ".json_encode($store_ids));
            Log::info($e);
            return response()->json([
                'status' => 'Falló al obtener el reporte popsy',
                'results' => 'null'
            ], 500);
        }
    }
    /* 
        * @desc retorna el total de ordenes, cantidad  y ventas agrupados por día, producto y tienda.
    */
    public function getPopsyProductsReport(Request $request){
        try{
            $store = $this->authStore;
            $store_ids= json_decode($request->store_ids);
            $start_date= $request->start_date;
            $end_date=$request->end_date;
            $all_stores= $request->all_stores=='true'?true:false;   
            if($all_stores)
            {
                //Se procede a traer todas las tiendas de la compañia
                $store_ids= Store::
                    where('company_id',$store->company_id)
                    ->get()
                    ->map(function ($store) {
                        return $store->id;
                    })->toArray();
            }
            $timezone = TimezoneHelper::getStoreTimezone($store);
            //Se obtiene el intervalo real
            $startDate = Carbon::parse($start_date)->startOfDay()->setTimezone($timezone);
            $endDate = Carbon::parse($end_date)->endOfDay()->setTimezone($timezone);
            
            //Se recuperan los registros en el rango de fecha establecido.
            $products= collect(DB::select(DB::raw("
            select ord.store_id, or_det.name_product, sum(or_det.total)as total_sales, count(distinct ord.id) as orders_quantity,
            sum(or_det.quantity) as total_quantity
                ,ord.created_at as date
            from orders ord
            inner join order_details or_det on or_det.order_id = ord.id
            inner join product_details prod on prod.id = or_det.product_detail_id
            where 
                ord.store_id in('".implode("','", $store_ids)."')
                and ord.created_at between '$startDate' and '$endDate'
            group by ord.store_id,or_det.name_product
                    ,date
            order by store_id,
                    name_product asc;")));
            //Se procede a insertar la fecha real en cada item
            foreach ($products as $key => $product) {
                $datemod=TimezoneHelper::localizedDateForStore($product->date,$store);
                $product->real_date_time=$datemod->format('Y-m-d H:i:s');
                $product->real_date=$datemod->format('Y-m-d');
            }
            //Se agrupa por id tiendas, nombre_producto, real_data.
            $grouped_products_date= $products->groupBy(
                [
                    'store_id',
                    'name_product',
                    'real_date'                   
                ]
            );
            $finalData= array();
            $arrayIndex=0;
            //Se recorre la colección para acumular los totale y adicionarlos a un array.
            $grouped_products_date->each(function ($shop, $key) use(&$finalData,&$arrayIndex){
             //Se obtiene los datos de la tienda
             $shopDataBase= Store::where('id',$key)->first();
             $shopData= new stdClass();
             $shopData->store_id=$key;
             $shopData->store_name=$shopDataBase->name;
             $shopData->datos=array();
             array_push($finalData,$shopData);
               $shop->each(function ($product, $key) use(&$finalData,$arrayIndex){
                
                    $product->each(function ($date, $key2) use(&$finalData,$arrayIndex){  
                        $total_quantity= 0;
                        $total_sales=0;
                        $total_orders_quantity= 0;
                        $name_product='';
                        $date->each(function ($finalItem, $key) use(&$total_quantity,&$total_sales,&$total_orders_quantity,&$name_product){ 
                            //Se crea el registro de la tienda con sus productos
                            $name_product=$finalItem->name_product;
                            $total_quantity=$total_quantity+intval($finalItem->total_quantity);
                            $total_sales=$total_sales+floatval($finalItem->total_sales);
                            $total_orders_quantity=$total_orders_quantity+intval($finalItem->orders_quantity);
                        });
                        $prod= new stdClass();
                        $prod->date=$key2;
                        $prod->name_product=$name_product;
                        $prod->total_quantity=$total_quantity;
                        $prod->total_sales=$total_sales;
                        $prod->total_orders_quantity=$total_orders_quantity;

                        array_push($finalData[$arrayIndex]->datos,$prod);

                    });

               });
               $arrayIndex++;
            });
            return response()->json(['status' =>'Exito', 'result'=>$finalData], 200);
        }catch (\Exception $e) {
            Log::info("PopsyController@getPopsyProductsReport: No se pudo obtener el reporte popsy con tipo producto, fecha desde ".$start_date. "fecha hasta ".$end_date. " tiendas ".json_encode($store_ids));
            Log::info($e);
            return response()->json([
                'status' => 'Falló al obtener el reporte popsy',
                'results' => 'null'
            ], 500);
        }
    }
    /* 
        * @desc retorna el total de ordenes, cantidad  y ventas agrupados por día y tienda.
    */
    public function getPopsyStoresReport(Request $request){
        try{
            $store = $this->authStore;
            $store_ids= json_decode($request->store_ids);
            $start_date= $request->start_date;
            $end_date=$request->end_date;
            $all_stores= $request->all_stores=='true'?true:false;   
            if($all_stores)
            {
                //Se procede a traer todas las tiendas de la compañia
                $store_ids= Store::
                    where('company_id',$store->company_id)
                    ->get()
                    ->map(function ($store) {
                        return $store->id;
                    })->toArray();
            }
            $timezone = TimezoneHelper::getStoreTimezone($store);
            //Se obtiene el intervalo real
            $startDate = Carbon::parse($start_date)->startOfDay()->setTimezone($timezone)->format('Y-m-d H:i:s');
            $endDate = Carbon::parse($end_date)->endOfDay()->setTimezone($timezone)->format('Y-m-d H:i:s');
        
            //Se recuperan los registros en el rango de fecha establecido.
            $products= collect(DB::select(DB::raw("
            select ord.store_id, sum(ordet.total)as total_sales,
                sum(ordet.quantity) as total_quantity,  ord.created_at as date,
                sum(CASE WHEN (prod.type_product is null or  prod.type_product='null' or  prod.type_product='food') THEN coalesce(ordet.quantity,0)
                WHEN prod.type_product ='drink' THEN 0 END ) as total_food,
                sum(CASE  WHEN prod.type_product ='drink' 
                THEN coalesce(ordet.quantity,0)  WHEN (prod.type_product is null or  prod.type_product='null' or  prod.type_product='food') THEN 0  END) as total_drink
            from orders ord
                inner join order_details ordet on ordet.order_id = ord.id
                inner join stores sto on sto.id = ord.store_id
                inner join product_details  prodet on prodet.id = ordet.product_detail_id
                inner join products prod on prod.id = prodet.product_id 
            where 
                ord.store_id in('".implode("','", $store_ids)."')
                and ord.created_at between '$startDate' and '$endDate'
            group by ord.store_id,ord.created_at
            order by store_id asc;")));
        
            //Se procede a insertar la fecha real en cada item
            foreach ($products as $key => $product) {
                $datemod=TimezoneHelper::localizedDateForStore($product->date,$store);
                $product->real_date_time=$datemod->format('Y-m-d H:i:s');
                $product->real_date=$datemod->format('Y-m-d');
            }
            //Se agrupa por id tiendas, nombre_producto, real_data.
            $grouped_products_date= $products->groupBy(
                [
                    'store_id',
                    'real_date'                   
                ]
            );
           
            $finalData= array();
            $arrayIndex=0;
            //Se recorre la colección para acumular los totale y adicionarlos a un array.
            $grouped_products_date->each(function ($shop, $key) use(&$finalData,&$arrayIndex){
             //Se obtiene los datos de la tienda
             $shopDataBase= Store::where('id',$key)->first();
             $shopData= new stdClass();
             $shopData->store_id=$key;
             $shopData->store_name=$shopDataBase->name;
             $shopData->total_food=0;
             $shopData->total_drink=0;
             $shopData->total_sales=0;
             $shopData->datos=array();
           

             array_push($finalData,$shopData);
               $shop->each(function ($date, $key2) use(&$finalData,&$arrayIndex){
                //SE SACAN LOS TOTALES DEL DÍA    
                    $total_quantity= 0;
                    $total_sales=0;
                    $total_food= 0;
                    $total_drink=0;
                    
                    $date->each(function ($finalItem, $key) use(&$total_quantity,&$total_sales,&$total_drink,&$total_food){ 
                        //Se crea el registro de la tienda con sus productos
                        $total_quantity=$total_quantity+intval($finalItem->total_quantity);
                        $total_sales=$total_sales+floatval($finalItem->total_sales);
                        $total_food=$total_food+intval($finalItem->total_food);
                        $total_drink=$total_drink+intval($finalItem->total_drink);
                    });
                    $prod= new stdClass();
                    $prod->date=$key2;
                    $prod->total_quantity=$total_quantity;
                    $prod->total_sales=$total_sales;
                    $prod->total_food=$total_food;
                    $prod->total_drink= $total_drink;
                    $finalData[$arrayIndex]->total_food =  $finalData[$arrayIndex]->total_food + $total_food;
                    $finalData[$arrayIndex]->total_drink =  $finalData[$arrayIndex]->total_drink + $total_drink;
                    $finalData[$arrayIndex]->total_sales =  $finalData[$arrayIndex]->total_sales + $total_sales;
                    array_push($finalData[$arrayIndex]->datos,$prod);
               });
               $arrayIndex++;
            });

            return response()->json(['status' =>'Exito', 'result'=>$finalData], 200);
        }catch (\Exception $e) {
            Log::info("PopsyController@getPopsyStoresReport: No se pudo obtener el reporte popsy con tipo producto, fecha desde ".$start_date. "fecha hasta ".$end_date. " tiendas ".json_encode($store_ids));
            Log::info($e);
            return response()->json([
                'status' => 'Falló al obtener el reporte popsy',
                'results' => 'null'
            ], 500);
        }
    }
    /* 
        * @desc retorna el total de ordenes, cantidad  y ventas agrupados por tienda, integracion, dia.
    */
    public function getPopsyIntegrationsReport(Request $request){
        try{
            $store = $this->authStore;
            $store_ids= json_decode($request->store_ids);
            $start_date= $request->start_date;
            $end_date=$request->end_date;
            $all_stores= $request->all_stores=='true'?true:false;   
            if($all_stores)
            {
                //Se procede a traer todas las tiendas de la compañia
                $store_ids= Store::
                    where('company_id',$store->company_id)
                    ->get()
                    ->map(function ($store) {
                        return $store->id;
                    })->toArray();
            }
            $timezone = TimezoneHelper::getStoreTimezone($store);
            //Se obtiene el intervalo real
            $startDate = Carbon::parse($start_date)->startOfDay()->setTimezone($timezone);
            $endDate = Carbon::parse($end_date)->endOfDay()->setTimezone($timezone);
            
            //Se recuperan los registros en el rango de fecha establecido.
            $products= collect(DB::select(DB::raw("
            select ord.store_id,spt.origin, sum(ord.total) as total_diario,ord.created_at as date
            from orders ord
                inner join spots spt on spt.id = ord.spot_id
            where 
                 ord.store_id in('".implode("','", $store_ids)."')
                 and spt.origin not in(1,0,10,11,21)
                 and ord.created_at between '$startDate' and '$endDate'
            group by ord.store_id, spt.origin, ord.created_at
            order by ord.store_id asc;")));
                 
            //Se procede a insertar la fecha real en cada item
            foreach ($products as $key => $product) {
                $datemod=TimezoneHelper::localizedDateForStore($product->date,$store);
                $product->real_date_time=$datemod->format('Y-m-d H:i:s');
                $product->real_date=$datemod->format('Y-m-d');
            }
            //Se agrupa por id tiendas, nombre_producto, real_data.
            $grouped_products_date= $products->groupBy(
                [
                    'store_id',
                    'origin',
                    'real_date'                   
                ]
            );
            
            $finalData= array();
            $arrayIndex=0;
            //Se recorre la colección para acumular los totale y adicionarlos a un array.
            $grouped_products_date->each(function ($shop, $key) use(&$finalData,&$arrayIndex){
                //Se obtiene los datos de la tienda
                $shopDataBase= Store::where('id',$key)->first();
                $shopData= new stdClass();
                $shopData->store_id=$key;
                $shopData->store_name=$shopDataBase->name;
                $shopData->integrations=array();
                array_push($finalData,$shopData);
                  $shop->each(function ($inte, $keyInte) use(&$finalData,$arrayIndex){
                 
                       $inte->each(function ($date, $key2) use(&$finalData,$arrayIndex,&$keyInte){  
                           $total_day= 0;
                           $date->each(function ($finalItem, $key) use(&$total_day){ 

                               $total_day=$total_day+intval($finalItem->total_diario);
                           });
                           $integra_detail= new stdClass();
                           $integra_detail->id=$keyInte;
                           $integra_detail->name=Spot::getNameIntegrationByOrigin($keyInte);
                           $integra_detail->date=$key2;
                           $integra_detail->total_day=$total_day;
   
                           array_push($finalData[$arrayIndex]->integrations,$integra_detail);
   
                       });
   
                  });
                  $arrayIndex++;
               });
           
            
            return response()->json(['status' =>'Exito', 'result'=>$finalData], 200);
        }catch (\Exception $e) {
            Log::info("PopsyController@getPopsyCategoriesReport: No se pudo obtener el reporte popsy con tipo producto, fecha desde ".$start_date. "fecha hasta ".$end_date. " tiendas ".json_encode($store_ids));
            Log::info($e);
            return response()->json([
                'status' => 'Falló al obtener el reporte popsy',
                'results' => 'null'
            ], 500);
        }
    }
}
