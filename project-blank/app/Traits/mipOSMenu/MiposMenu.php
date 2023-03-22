<?php

namespace App\Traits\myPOSMenu;

use App\Store;
use App\Helper;
use App\Product;
use App\StoreTax;
use App\ProductTax;
use App\ProductDetail;
use App\Specification;
use App\ProductCategory;
use App\ProductExternalId;
use App\Traits\Logs\Logging;

use App\ProductSpecification;
use App\SpecificationCategory;
use App\SpecificationExternalId;
use App\AvailableMyposIntegration;
use App\ProductCategoryExternalId;
use Illuminate\Support\Facades\Log;

use App\ProductSpecificationComponent;
use App\SpecificationCategoryExternalId;

trait MyposMenu
{

    private $channelLogMM = "mypos_menu_logs";

    public function createAllMenu($dataMenu, Store $store, int $sectionId, int $integrationId = null, $needsExtIds = true)
    {
        Logging::printLogFile(
            "Creando menú con createAllMenu para la tienda: " . $store->name,
            $this->channelLogMM
        );
        Logging::printLogFile(
            "En el section id: " . $sectionId,
            $this->channelLogMM
        );
        Logging::printLogFile(
            "Con la integración id: " . $integrationId,
            $this->channelLogMM
        );
        Logging::printLogFile(
            "******************************************************",
            $this->channelLogMM
        );
        Logging::printLogFile(
            "******************************************************",
            $this->channelLogMM
        );

        // Creando categorías de productos
        foreach ($dataMenu as $category) {
            $newProductCategory;
            if ($category["status"] == 0) {
                if (!is_null($integrationId)) {
                    $prodCatAloha = ProductCategoryExternalId::where('external_id', $category["external_id"])
                        ->whereHas('categories.section', function ($q) use ($sectionId) {
                            $q->where('section_id', $sectionId);
                        })
                        ->where('integration_id', $integrationId)
                        ->first();
                    
                    if (is_null($prodCatAloha)) {
                        $newProductCategory = $this->createProductCategory(
                            $category["name"],
                            $category["position"],
                            $sectionId,
                            $store
                        );
                        if($needsExtIds){
                            $external = new ProductCategoryExternalId();
                            $external->product_category_id = $newProductCategory->id;
                            $external->integration_id = $integrationId;
                            $external->external_id = $category["external_id"];
                            $external->save();
                        }
                    } else {
                        $newProductCategory = ProductCategory::find($prodCatAloha->product_category_id);
                    }
                } else {
                    $newProductCategory = $this->createProductCategory(
                        $category["name"],
                        $category["position"],
                        $sectionId,
                        $store
                    );
                }
            } else {
                $newProductCategory = ProductCategory::find($category["id"]);
            }
            // Creando productos
            foreach ($category["products"] as $product) {
                $newProduct;
                if ($product["status"] == 0 && $integrationId != 1) {
                    if (!is_null($integrationId)) {
                        $productAloha = ProductExternalId::where('external_id', $product["external_id"])
                        ->whereHas('products.category', function ($q) use ($newProductCategory) {
                            $q->where('id', $newProductCategory->id);
                        })
                        ->where('integration_id', $integrationId)
                        ->first();

                        if (is_null($productAloha)) {
                            $newProduct = $this->createProduct(
                                $newProductCategory->id,
                                $product["position"],
                                $product,
                                $store->id
                            );
                            
                            if($needsExtIds){
                                $external = new ProductExternalId();
                                $external->product_id = $newProduct->id;
                                $external->integration_id = $integrationId;
                                $external->external_id = $product["external_id"];
                                $external->save();
                            }
                        } else {
                            $newProduct = Product::find($productAloha->product_id);
                        }
                    } else {
                        $newProduct = $this->createProduct(
                            $newProductCategory->id,
                            $product["position"],
                            $product,
                            $store->id
                        );
                    }
                } elseif ($integrationId === 1) {
                    $newProduct = $this->setProductForUberIntegration($store, $product, $sectionId, $newProductCategory);
                } else {
                    $newProduct = Product::find($product["id"]);
                }
                // Creando categorías de especificaciones
                foreach ($product["modifiers"] as $specificationCategory) {

                    $newSpecificationCategory;
                    
                    if ($specificationCategory["status"] == 0 && $needsExtIds) {
                        if (!is_null($integrationId)) {
                            $specCatAloha = SpecificationCategoryExternalId::where(
                                'external_id',
                                $specificationCategory["external_id"]
                            )
                                ->where('integration_id', $integrationId)
                                ->first();
                            if (is_null($specCatAloha)) {
                                $newSpecificationCategory = $this->createSpecificationCategory(
                                    $specificationCategory,
                                    $specificationCategory["position"],
                                    $sectionId,
                                    $store
                                );
                                if($needsExtIds){
                                    $external = new SpecificationCategoryExternalId();
                                    $external->spec_category_id = $newSpecificationCategory->id;
                                    $external->integration_id = $integrationId;
                                    $external->external_id = $specificationCategory["external_id"];
                                    $external->save();
                                }
                            } else {
                                $newSpecificationCategory = SpecificationCategory::find(
                                    $specCatAloha->spec_category_id
                                );
                            }
                        } else {
                            $newSpecificationCategory = $this->createSpecificationCategory(
                                $specificationCategory,
                                $specificationCategory["position"],
                                $sectionId,
                                $store
                            );
                        }
                    } else {
                        $newSpecificationCategory = SpecificationCategory::find($specificationCategory["id"]);
                        
                        /*Si es integración de uber le damos un trato especial ya que no usa las tables de xxx_external_ids*/
                        if($integrationId === 1){
                            $newSpecificationCategory = $this->setModifiersCatForUberIntegration($store, $newProduct, $specificationCategory, $sectionId);
                        }
                    }
                    
                    // Creando especificaciones
                    foreach ($specificationCategory["options"] as $specification) {
                        /*Si es integración de uber le damos un trato especial ya que no usa las tables de xxx_external_ids*/
                        if($integrationId === 1){
                            $this->setModifiersForUberIntegration($store, $newProduct, $newSpecificationCategory, $specification);
                            continue;
                        }

                        if ($specification["status"] == 0 && $needsExtIds) {
                            if (!is_null($integrationId)) {
                                $specAloha = SpecificationExternalId::where(
                                    'external_id',
                                    $specification["external_id"]
                                )
                                ->where('integration_id', $integrationId)
                                ->first();
                                if (is_null($specAloha)) {
                                    $newSpecification = $this->createSpecification(
                                        $newSpecificationCategory->id,
                                        $specification["position"],
                                        $specification,
                                        $store->id,
                                        $newProduct->id
                                    );
                                    if($needsExtIds){
                                        $external = new SpecificationExternalId();
                                        $external->specification_id = $newSpecification->id;
                                        $external->integration_id = $integrationId;
                                        $external->external_id = $specification["external_id"];
                                        $external->save();
                                    }
                                } else {
                                    // Esto es para cuando la opción se encuentra en otra categoría,
                                    // pero igual se necesita para esta categoría
                                    // Obteniendo todas las especificaciones de la categoría
                                    $specsMypos = Specification::where(
                                        'specification_category_id',
                                        $newSpecificationCategory->id
                                    )
                                        ->whereRaw("BINARY `name`= ?", [$specification["name"]])
                                        ->get();
                                    $actualSpecMypos = null;
                                    foreach ($specsMypos as $specMypos) {
                                        $specExternal = SpecificationExternalId::where(
                                            'specification_id',
                                            $specMypos->id
                                        )
                                            ->where('external_id', $specification["external_id"])
                                            ->first();
                                        if (!is_null($specExternal)) {
                                            $actualSpecMypos = $specMypos;
                                        }
                                    }
                                    if (is_null($actualSpecMypos)) {
                                        $newSpecification = $this->createSpecification(
                                            $newSpecificationCategory->id,
                                            $specification["position"],
                                            $specification,
                                            $store->id,
                                            $newProduct->id
                                        );
                                        if($needsExtIds){
                                            $external = new SpecificationExternalId();
                                            $external->specification_id = $newSpecification->id;
                                            $external->integration_id = $integrationId;
                                            $external->external_id = $specification["external_id"];
                                            $external->save();
                                        }
                                    } else {
                                        // Como la especificación existe sólo asignarla al producto
                                        $this->assignProductSpecification(
                                            $newProduct->id,
                                            $actualSpecMypos,
                                            $specification["spec_prod_price"]
                                        );
                                    }
                                }
                            } else {
                                $newSpecification = $this->createSpecification(
                                    $newSpecificationCategory->id,
                                    $specification["position"],
                                    $specification,
                                    $store->id,
                                    $newProduct->id
                                );
                            }
                        } else {
                            $specAloha = SpecificationExternalId::where('external_id', $specification["external_id"])
                                    ->where('integration_id', $integrationId)
                                    ->first();
                            $specMypos = Specification::where('id', $specAloha->specification_id)->first();
                            $this->assignProductSpecification(
                                $newProduct->id,
                                $specMypos,
                                $specification["spec_prod_price"]
                            );
                        }
                    }
                }
            }
        }
    }

    public function setProductForUberIntegration($store, $product, $sectionId, $newProductCategory){
        $productExternalId = $product["external_id"];
        $productMypos = Product::where('id', $productExternalId)
                            ->whereHas('category', function ($q) use ($sectionId){
                                $q->where('section_id', $sectionId);
                            })->first();

        if ($product["status"] == 0) {

            //si no encontramos resultados
            if(is_null($productMypos) || $product["external_id"] == 0){
                
                /*Buscamos también por nombre y en el section de la categoría actual */
                $productMypos = Product::where('name','like',"%".$product['name']."%")
                                    ->whereHas('category', function ($q) use ($sectionId){
                                        $q->where('section_id', $sectionId);
                                    })->first();
                
                /*Si se encuentra el producto por nombre entonces lo retornamos, si no, entonces lo creamos y lo retornamos*/
                if($productMypos){
                    return $productMypos;
                    // Log::info("Coincidencia producto por nombre ".$productMypos->name." id ".$productMypos->id);
                }else{
                    return $productMypos = $this->createProduct(
                        $newProductCategory->id,
                        $product["position"],
                        $product,
                        $store->id
                    );
                    // Log::info("Creando nueva producto ".$productMypos->name." id ".$productMypos->id);
                }

            //Si sí encontramos el producto por id, entonces lo retornamos directamente
            }else{
                // Log::info("Retornando producto existente status 1".$productMypos->name." id ".$productMypos->id);
                return $productMypos;
            }
        }else{
            //verificamos nuevamente que efectivamente exista, por seguridad
            if($productMypos){
                //si existe la retornamos
                // Log::info("Retornando producto existente status 1".$productMypos->name." id ".$productMypos->id);
                return $productMypos;
            }else{
                //si no existe, entonces modificamos el index ['status'], y llamamos esta misma función para crear
                $product["status"] = 0;
                // Log::info("Manda a crear producto en la misma función ".$product['name']." id ".$product['id']);
                return $productMypos = $this->setProductForUberIntegration($store, $product, $sectionId);
            }
            
        }

    }

    public function setModifiersForUberIntegration($store, $newProduct, $newSpecificationCategory, $specification){
        $specExternalId = $specification["external_id"];

        $specMypos = Specification::where('id', $specExternalId)
                        ->whereHas('specificationCategory', function ($q) use ($newSpecificationCategory){
                            $q->where('section_id', $newSpecificationCategory->section_id);
                        })
                        ->first();

        if ($specification["status"] == 0) {
            /*Descartamos que haga falta el external_id (es decir, que sea igual a cero), si hace falta, buscamos el modificador
            por el nombre y en el menú actual, si lo encontramos, re-asignamos el
            valor a external_id. Todo con el fin de evitar modificadores duplicados*/
            if(is_null($specMypos) || $specification["external_id"] == 0){
                $specMypos = Specification::where('name','like',"%".$specification['name']."%")
                                ->whereHas('specificationCategory', function ($q) use ($newSpecificationCategory){
                                    $q->where('section_id', $newSpecificationCategory->section_id);
                                })->first();
            }

            if(!is_null($specMypos)){
                /*verificamos que la specificación todavía no esté asignada al producto 
                en cuestión, para evitar duplicados*/
                $newProductHasThisSpec = $specMypos->products()->where('product_id', $newProduct->id)->first();
                if(!$newProductHasThisSpec){
                    // Log::info("Especificación no asignada ".$specMypos->id." se asigna a ".$newProduct->name." id ".$newProduct->id);
                    $this->assignProductSpecification(
                        $newProduct->id,
                        $specMypos,
                        $specification["spec_prod_price"]
                    );
                }
            //Si no se encuentra la especificación, entonces se crea y se relaciona al producto
            }else{
                $newSpecification = $this->createSpecification(
                    $newSpecificationCategory->id,
                    $specification["position"],
                    $specification,
                    $store->id,
                    $newProduct->id
                );
                
                // Log::info("Spec No hubo coincidencia por nombre ".$newSpecification->name." se crea ".$newSpecification->id);

                /*verificamos que la specificación todavía no esté asignada al producto 
                en cuestión, para evitar duplicados*/
                $newProductHasThisSpec = $newSpecification->products()->where('product_id', $newProduct->id)->first();
                if(!$newProductHasThisSpec){
                    // Log::info("else Especificación no asignada ".$newSpecification->id." se asigna a ".$newProduct->name." id ".$newProduct->id);
                    $this->assignProductSpecification(
                        $newProduct->id,
                        $newSpecification,
                        $specification["spec_prod_price"]
                    );
                }
            }
        }else{
            //verificamos nuevamente que efectivamente exista, por seguridad
            if($specMypos){
               /*verificamos que la specificación todavía no esté asignada al producto 
                en cuestión, para evitar duplicados*/
                $newProductHasThisSpec = $specMypos->products()->where('product_id', $newProduct->id)->first();
                if(!$newProductHasThisSpec){
                    // Log::info("Especificación no asignada ".$specMypos->id." se asigna a ".$newProduct->name." id ".$newProduct->id);
                    $this->assignProductSpecification(
                        $newProduct->id,
                        $specMypos,
                        $specification["spec_prod_price"]
                    );
                }
            }else{
                //si no existe, entonces modificamos el index ['status'], y llamamos esta misma función para crear
                $specification["status"] = 0;
                // Log::info("Manda a crear spec en la misma función ".$specification['name']." id ".$specification['id']);
                $this->setModifiersForUberIntegration($store, $newProduct, $newSpecificationCategory, $specification);
            }
        }

    }

    public function setModifiersCatForUberIntegration($store, $newProduct, $specificationCategory, $sectionId){
        // Log::info("Usando nueva función");
        $spectCatExternalId = $specificationCategory["external_id"];
        $specCatMypos = SpecificationCategory::where('id', $spectCatExternalId)
                        ->where('section_id', $sectionId)
                        ->first();

        if ($specificationCategory["status"] == 0) {

            //si no encontramos resultados
            if(is_null($specCatMypos) || $specificationCategory["external_id"] == 0){
                
                /*Buscamos también por nombre y en el section actual */
                $specCatMypos = SpecificationCategory::where('name','like',"%".$specificationCategory['name']."%")
                                ->where('section_id', $sectionId)
                                ->first();
                
                /*Si se encuentra la categoría por nombre entonces la retornamos, si no, entonces la creamos y la retornamos*/
                if($specCatMypos){
                    return $specCatMypos;
                    // Log::info("Coincidencia Category por nombre ".$specCatMypos->name." id ".$specCatMypos->id);
                }else{
                    return $specCatMypos = $this->createSpecificationCategory(
                        $specificationCategory,
                        $specificationCategory["position"],
                        $sectionId,
                        $store
                    );
                    
                    // Log::info("Creada nueva categoría ".$specCatMypos->name." id ".$specCatMypos->id);
                }

            //Si sí encontramos la categoría por id, entonces la retornamos directamente
            }else{
                // Log::info("Retornando categoría existente status 1".$specCatMypos->name." id ".$specCatMypos->id);
                return $specCatMypos;
            }
        }else{
            //verificamos nuevamente que efectivamente exista, por seguridad
            if($specCatMypos){
                //si existe la retornamos
                // Log::info("Retornando categoría existente status 1".$specCatMypos->name." id ".$specCatMypos->id);
                return $specCatMypos;
            }else{
                //si no existe, entonces modificamos el index ['status'], y llamamos esta misma función para crear
                $specificationCategory["status"] = 0;
                // Log::info("Manda a crear cat spec en la misma función ".$specificationCategory['name']." id ".$specificationCategory['id']);
                return $specCatMypos = $this->setModifiersCatForUberIntegration($store, $newProduct, $specificationCategory, $sectionId);
            }
            
        }

    }

    public function createProductCategory($name, $position, $sectionId, Store $store)
    {
        $category = new ProductCategory();
        $category->company_id = $store->company_id;
        $category->priority = $position;
        $category->name = $name;
        $category->search_string = Helper::remove_accents($name);
        $category->status = 1;
        $category->section_id = $sectionId;
        $category->subtitle = "";
        $category->save();

        return $category;
    }

    public function createProduct($categoryId, $position, $dataProduct, $storeId)
    {
        $product = new Product();
        $product->product_category_id = $categoryId;
        $product->name = $dataProduct["name"];
        $product->search_string = Helper::remove_accents($dataProduct["name"]);
        $product->description = $dataProduct["description"];
        $product->priority = $position;
        $product->base_value = $dataProduct["price"];
        $product->image = $dataProduct["image"];
        $product->status = 1;
        $product->invoice_name = substr($dataProduct["name"], 0, 25);
        $product->ask_instruction = 0;
        $product->eats_product_name = "NINGUNO";
        $product->image_version = 0;
        $product->is_alcohol = 0;
        $product->type_product = "null";
        $product->save();

        $this->asignProductTax(
            $product->id,
            $dataProduct["taxName"],
            ((int) $dataProduct["taxRate"]) * 100,
            $storeId
        );
        $this->assignProductDetail(
            $product,
            $storeId
        );

        return $product;
    }

    public function asignProductTax($productId, $taxName, $taxValue, $storeId)
    {
        if ($taxName == null) {
            return;
        }

        $storeTax = StoreTax::where('store_id', $storeId)
            ->where('name', $taxName)
            ->where('percentage', $taxValue)
            ->where('type', "included")
            ->where('is_main', 1)
            ->first();

        if (is_null($storeTax)) {
            $storeTax = new StoreTax();
            $storeTax->store_id = $storeId;
            $storeTax->name = $taxName;
            $storeTax->percentage = $taxValue;
            $storeTax->type = "included";
            $storeTax->is_main = 1;
            $storeTax->save();
        }

        $productTax = new ProductTax();
        $productTax->product_id = $productId;
        $productTax->store_tax_id = $storeTax->id;
        $productTax->save();
    }

    public function assignProductDetail(Product $product, $storeId)
    {
        $newProductDetail = new ProductDetail();
        $newProductDetail->product_id = $product->id;
        $newProductDetail->store_id = $storeId;
        $newProductDetail->stock = 0;
        $newProductDetail->value = $product->base_value;
        $newProductDetail->save();
    }

    public function createSpecificationCategory($dataCategory, $position, $sectionId, Store $store)
    {
        $category = new SpecificationCategory();
        $category->company_id = $store->company_id;
        $category->priority = $position;
        $category->name = $dataCategory["name"];

        if($store->company_id==491){
            $category->required = $dataCategory["min"];
        }else{
            $category->required = $dataCategory["min"] != "0";
        }
       


        $category->max = $dataCategory["max"];
        $category->status = 1;
        $category->section_id = $sectionId;
        $category->subtitle = "";
        $category->save();

        return $category;
    }

    public function createSpecification($categoryId, $position, $dataSpecification, $storeId, $productId)
    {
        $specification = new Specification();
        $specification->specification_category_id = $categoryId;
        $specification->name = $dataSpecification["name"];
        $specification->priority = $position;
        $specification->value = $dataSpecification["price"];
        $specification->status = 1;
        $specification->save();

        $this->assignProductSpecification(
            $productId,
            $specification,
            $dataSpecification["spec_prod_price"]
        );

        return $specification;
    }

    public function assignProductSpecification($productId, Specification $specification, $price)
    {
        $newProductSpecification = new ProductSpecification();
        $newProductSpecification->product_id = $productId;
        $newProductSpecification->specification_id = $specification->id;
        $newProductSpecification->status = 1;
        $newProductSpecification->value = $price;
        $newProductSpecification->save();
    }
}
