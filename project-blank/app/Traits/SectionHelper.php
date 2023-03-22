<?php

namespace App\Traits;

ini_set('max_execution_time', 300);

use App\Component;
use App\ComponentCategory;
use App\ComponentStock;
use App\ComponentVariationComponent;
use App\DailyStock;
use App\MetricUnit;
use App\Section;
use App\SectionAvailability;
use App\SectionAvailabilityPeriod;
use App\ProductCategory;
use App\Product;
use App\ProductComponent;
use App\ProductDetail;
use App\StoreTax;
use App\SpecificationCategory;
use App\Specification;
use App\ProductSpecification;
use App\ProductSpecificationComponent;
use App\ProductTax;
use App\SpecificationComponent;
use App\ProductDetailStoreLocation;

trait SectionHelper
{

    public function duplicateSection(Section $section, $store, $isAssign = false)
    {
        $sectionMain = Section::where('store_id', $store->id)
            ->where('is_main', 1)
            ->first();

        $isMain = 0;
        if (!$sectionMain) {
            $isMain = 1;
        }

        // Duplicando modelo

        $newSectionName =  $isAssign ? $section->name : $section->name . ' copia';

        $newSection = Section::updateOrCreate(
            ['store_id' => $store->id, 'assigned_of' => $isAssign ? $section->id : null, 'name' => $newSectionName],
            ['subtitle' => $section->subtitle, 'is_main' => $isMain]
        );

        // Reseteando relaciones del modelo
        $section->relations = [];

        $section->load(
            'availabilities'
        );

        // Duplicando section availabilities and periods

        $includedSectionAvailability = collect([]);

        foreach ($section->availabilities as $availability) {
            $newSectionAvailability = SectionAvailability::updateOrCreate(
                ['section_id' => $newSection->id, 'day' => $availability->day],
                ['enabled' => $availability->enabled]
            );

            $includedSectionAvailability->push($newSectionAvailability->id);

            $availability->relations = [];
            $availability->load(
                'periods'
            );

            $includedSectionAvailabilityPeriod = collect([]);
            foreach ($availability->periods as $period) {

                $newSectionPeriod = SectionAvailabilityPeriod::updateOrCreate(
                    ['section_availability_id' => $newSectionAvailability->id, 'start_time' => $period->start_time, 'end_time' => $period->end_time],
                    ['start_day' => $period->start_day, 'end_day' => $period->start_day]
                );

                $includedSectionAvailabilityPeriod->push($newSectionPeriod->id);
            }

            SectionAvailabilityPeriod::where('section_availability_id', $newSectionAvailability->id)
                ->whereNotIn('id', $includedSectionAvailabilityPeriod)
                ->delete();
        }

        SectionAvailability::where('section_id', $newSection->id)
            ->whereNotIn('id', $includedSectionAvailability)
            ->delete();

        return $newSection;
    }

    public function duplicateMenuContent(Section $section, Section $newSection, $originStore, $store, $isAssign = false)
    {
        // Reseteando relaciones del modelo
        $section->relations = [];

        $section->load(
            'categories',
            'specificationsCategories'
        );

        $equivalentUnits = collect([]);

        // Duplicando unidades de medida
        /* if ($isAssign) {
            $equivalentUnits = $this->duplicateUnits($originStore, $store);
        } */

        $equivalentUnits = $this->duplicateUnits($originStore, $store);

        // Duplicando categorías de productos, productos y consumos
        $includedProductCategories = collect([]);

        foreach ($section->categories as $productCategory) {

            $newProductCategory = ProductCategory::updateOrCreate(
                ['company_id' => $store->company_id, 'name' => $productCategory->name, 'section_id' => $newSection->id],
                [
                    'priority' => $productCategory->priority,
                    'search_string' => $productCategory->search_string,
                    'image' => $productCategory->image,
                    'status' => $productCategory->status,
                    'subtitle' => $productCategory->subtitle,
                    'image_version' => $productCategory->image_version
                ]
            );

            $includedProductCategories->push($newProductCategory->id);

            $this->duplicateProducts(
                $productCategory,
                $newProductCategory,
                $originStore,
                $store,
                $equivalentUnits
            );
        }

        ProductCategory::where('section_id', $newSection->id)
            ->whereNotIn('id', $includedProductCategories)
            ->delete();

        // Duplicando especificacione, product_specifications, consumos, consumos por producto

        $includedSpecificationCategories = collect([]);

        foreach ($section->specificationsCategories as $specificationsCategory) {

            $newSpecificationsCategory = SpecificationCategory::updateOrCreate(
                ['company_id' => $store->company_id, 'name' => $specificationsCategory->name, 'section_id' => $newSection->id],
                [
                    'priority' => $specificationsCategory->priority,
                    'required' => $specificationsCategory->required,
                    'max' => $specificationsCategory->max,
                    'status' => $specificationsCategory->status,
                    'show_quantity' => $specificationsCategory->show_quantity,
                    'type' => $specificationsCategory->type,
                    'subtitle' => $specificationsCategory->subtitle
                ]
            );

            $includedSpecificationCategories->push($newSpecificationsCategory->id);

            $this->duplicateSpecifications(
                $specificationsCategory,
                $newSpecificationsCategory,
                $newSection,
                $originStore,
                $store,
                $equivalentUnits
            );
        }

        SpecificationCategory::where('section_id', $newSection->id)
            ->whereNotIn('id', $includedSpecificationCategories)
            ->delete();
    }

    public function duplicateProducts(ProductCategory $productCategory, ProductCategory $newProductCategory, $originStore, $store, $equivalentUnits)
    {
        // Duplicando productos
        $productCategory->relations = [];
        $productCategory->load(
            'products'
        );

        $includedProducts = collect([]);

        foreach ($productCategory->products as $product) {

            $newProduct = Product::updateOrCreate(
                ['product_category_id' => $newProductCategory->id, 'name' => $product->name],
                [
                    'search_string' => $product->search_string,
                    'description' => $product->description,
                    'priority' => $product->priority,
                    'base_value' => $product->base_value,
                    'image' => $product->image,
                    'status' => $product->status,
                    'invoice_name' => $product->invoice_name,
                    'sku' => $product->sku,
                    'ask_instruction' => $product->ask_instruction,
                    'eats_product_name' => $product->eats_product_name,
                    'image_version' => $product->image_version,
                    'is_alcohol' => $product->is_alcohol,
                    'type_product' => $product->type_product
                ]
            );

            $includedProducts->push($newProduct->id);

            $this->asignProductTax(
                $product,
                $newProduct,
                $store->id
            );

            $this->duplicateProductDetail(
                $product,
                $newProduct,
                $store->id
            );

            $this->duplicateConsumptionComponent(
                $product,
                $newProduct,
                $originStore,
                $store,
                $equivalentUnits
            );
        }

        Product::where('product_category_id', $newProductCategory->id)
            ->whereNotIn('id', $includedProducts)
            ->delete();
    }

    public function asignProductTax(Product $product, Product $newProduct, $storeId)
    {
        $product->relations = [];
        $product->load(
            'taxes'
        );
        // Duplicando product taxes
        $includedTaxes = collect([]);

        foreach ($product->taxes as $storeTax) {
            $storeTaxDB = StoreTax::where('id', $storeTax->id)->first();
            if ($storeTaxDB) {
                $newStoreTax = StoreTax::where('store_id', $storeId)
                    ->where('name', $storeTax->name)
                    ->where('percentage', $storeTax->percentage)
                    ->where('type', $storeTax->type)
                    ->where('is_main', $storeTax->is_main)
                    ->first();
                if (!$newStoreTax) {
                    // Creando store tax si no existe
                    $newStoreTax = $storeTax->replicate();
                    $newStoreTax->store_id = $storeId;
                    $newStoreTax->save();
                }
                // Duplicando product tax

                $newProductTax = ProductTax::updateOrCreate(['product_id' => $newProduct->id, 'store_tax_id' => $newStoreTax->id], []);
                $includedTaxes->push($newProductTax->id);
            }
        }

        ProductTax::where('product_id', $newProduct->i)
            ->whereNotIn('id', $includedTaxes)
            ->delete();
    }

    public function duplicateProductDetail(Product $product, Product $newProduct, $storeId)
    {
        $product->relations = [];
        $product->load(
            'details'
        );
        // Duplicando product details

        $includedProductDetails = collect([]);

        foreach ($product->details as $productDetail) {

            $newProductDetail = ProductDetail::updateOrCreate(
                ['product_id' => $newProduct->id, 'store_id' => $storeId],
                [
                    'stock' => $productDetail->stock,
                    'value' => $productDetail->value,
                    'status' => $productDetail->status,
                    'production_cost' => $productDetail->production_cost,
                    'income' => $productDetail->income,
                    'cost_ratio' => $productDetail->cost_ratio,
                    'tax_by_value' => $productDetail->tax_by_value
                ]
            );

            $includedProductDetails->push($newProductDetail->id);
        }

        ProductDetail::where('product_id', $newProduct->id)
            ->whereNotIn('id', $includedProductDetails)
            ->delete();
    }

    // hacen falta los components al momento de duplicar a otra tienda ??? - consulta gianni
    public function duplicateConsumptionComponent(Product $product, Product $newProduct, $originStore, $store, $equivalentUnits)
    {
        $product->relations = [];
        $product->load(
            'components'
        );

        // Duplicando product components

        $includedProductComponents = collect([]);

        foreach ($product->components as $productComponent) {

            $component = $productComponent->variation;

            $newComponent = $this->duplicateComponent($component, $originStore, $store, $equivalentUnits);

            if (!isset($newComponent)) continue;

            $newProductComponent = ProductComponent::updateOrCreate(
                ['product_id' => $newProduct->id, 'component_id' => $newComponent->id],
                ['consumption' => $productComponent->consumption, 'status' => $component->status]
            );

            $includedProductComponents->push($newProductComponent->id);
        }

        ProductComponent::where('product_id', $newProduct->id)
            ->whereNotIn('id', $includedProductComponents)
            ->delete();
    }

    public function duplicateSpecifications(
        SpecificationCategory $specificationCategory,
        SpecificationCategory $newSpecificationCategory,
        Section $section,
        $originStore,
        $store,
        $equivalentUnits
    ) {
        // Duplicando specificaciones
        $specificationCategory->relations = [];
        $specificationCategory->load(
            'specifications'
        );

        $includedSpecifications = collect([]);

        foreach ($specificationCategory->specifications as $specification) {

            $newSpecification = Specification::updateOrCreate(
                ['specification_category_id' => $newSpecificationCategory->id, 'name' => $specification->name],
                ['status' => $specification->status, 'value' => $specification->value, 'priority' => $specification->priority]
            );

            $includedSpecifications->push($newSpecification->id);

            $this->duplicateProductSpecification(
                $specification,
                $newSpecification,
                $section,
                $originStore,
                $store,
                $equivalentUnits
            );

            $this->duplicateSpecificationComponents(
                $specification,
                $newSpecification,
                $originStore,
                $store,
                $equivalentUnits
            );
        }

        Specification::where('specification_category_id', $newSpecificationCategory->id)
            ->whereNotIn('id', $includedSpecifications)
            ->delete();
    }

    public function duplicateProductSpecification(
        Specification $specification,
        Specification $newSpecification,
        Section $section,
        $originStore,
        $store,
        $equivalentUnits
    ) {
        $specification->relations = [];
        $specification->load(
            'productSpecifications'
        );

        foreach ($specification->productSpecifications as $productSpec) {
            $product = Product::where('id', $productSpec->product_id)->first();
            if ($product) {
                $productStore = Product::where('name', $product->name)
                    ->whereHas(
                        'category',
                        function ($q) use ($section) {
                            $q->where('section_id', $section->id);
                        }
                    )
                    ->where('base_value', $product->base_value)
                    ->first();

                if ($productStore) {
                    // Duplicando product spec

                    $newProdSpec = ProductSpecification::updateOrCreate(
                        ['product_id' => $productStore->id, 'specification_id' => $newSpecification->id],
                        ['status' => $productSpec->status, 'value' => $productSpec->value]
                    );

                    // Consumo de stock de este product specification
                    $prodSpecComps = ProductSpecificationComponent::where(
                        'prod_spec_id',
                        $productSpec->id
                    )->get();

                    // Duplicando consumos

                    foreach ($prodSpecComps as $prodSpecComp) {

                        $component = $prodSpecComp->variation;
                        $newComponent = $this->duplicateComponent($component, $originStore, $store, $equivalentUnits);

                        if (!isset($newComponent)) continue;

                        $newProductSpecificationComponent = ProductSpecificationComponent::updateOrCreate(
                            ['prod_spec_id' => $newProdSpec->id, 'component_id' => $newComponent->id],
                            ['consumption' => $prodSpecComp->consumption]
                        );
                    }
                }
            }
        }
    }

    // hacen falta los components al momento de duplicar a otra tienda ??? - consulta gianni
    public function duplicateSpecificationComponents(Specification $specification, Specification $newSpecification, $originStore, $store, $equivalentUnits)
    {
        $specification->relations = [];
        $specification->load(
            'components'
        );

        // Duplicando specification components

        $includedSpecificationComponents = collect([]);

        foreach ($specification->components as $specComponent) {

            $component = $specComponent->variation;
            $newComponent = $this->duplicateComponent($component, $originStore, $store, $equivalentUnits);

            if (!isset($newComponent)) continue;

            $newSpecComponent = SpecificationComponent::updateOrCreate(
                ['specification_id' => $newSpecification->id, 'component_id' => $newComponent->id],
                ['status' => $specComponent->status, 'consumption' => $specComponent->consumption]
            );

            $includedSpecificationComponents->push($newSpecComponent->id);
        }

        SpecificationComponent::where('specification_id', $newSpecification->id)
            ->whereNotIn('id', $includedSpecificationComponents)
            ->delete();
    }

    public function duplicateUnits($originStore, $store)    
    {
        $originUnits = MetricUnit::where('company_id', $originStore->company->id)->get();        

        $equivalentUnits = collect([]);        

        foreach ($originUnits as $originUnit) {

            // Validando si el las stores son de la misma company
            if ($originStore->company->id === $store->company->id) {
                $equivalentUnits->push([
                    'origin' => $originUnit->id,
                    'equivalent' => $originUnit->id
                ]);
            } else {
                $newOriginUnit = MetricUnit::updateOrCreate(
                    ['name' => $originUnit->name, 'company_id' => $store->company->id],
                    ['short_name' => $originUnit->short_name, 'status' => $originUnit->status]
                );
    
                $equivalentUnits->push([
                    'origin' => $originUnit->id,
                    'equivalent' => $newOriginUnit->id
                ]);
            }
        }

        return $equivalentUnits;
    }

    public function duplicateComponent($component, $originStore, $store, $equivalentUnits)
    {
        $category = $component->category;

        if (!isset($category)) return;

        $newCategory = ComponentCategory::updateOrCreate(
            ['name' => $category->name, 'company_id' => $store->company->id],
            [
                'search_string' => $category->search_string,
                'status' => $category->status,
                'priority' => $category->priority,
                'synced_id' => $category->synced_id
            ]
        );

        $newComponent = Component::updateOrCreate(
            ['name' => $component->name, 'component_category_id' => $newCategory->id],
            [
                'status' => $component->status,
                'value' => $component->value,
                'SKU' => $component->SKU,
                'metric_unit_id' => $this->getEquivalentId($equivalentUnits, $component->metric_unit_id),                
                'metric_unit_factor' => $component->metric_unit_factor,
                'conversion_metric_unit_id' => $this->getEquivalentId($equivalentUnits, $component->conversion_metric_unit_id),                
                'conversion_metric_factor' => $component->conversion_metric_factor
            ]
        );

        $componentStock = ComponentStock::where('store_id', $originStore->id)
            ->where('component_id', $component->id)
            ->first();

        $newComponentStock = ComponentStock::updateOrCreate(
            ['component_id' => $newComponent->id, 'store_id' => $store->id],
            [
                'alert_stock' => isset($componentStock) ? $componentStock->alert_stock : 0,
                'cost' => isset($componentStock) ? $componentStock->cost : 0,
                'merma' => isset($componentStock) ? $componentStock->merma : 0
            ]
        );

        if (isset($componentStock)) {
            $includedDailyStock = collect([]);

            foreach ($componentStock->dailyStocks as $dailyStock) {

                $newDailyStock = DailyStock::updateOrCreate(
                    ['component_stock_id' => $newComponentStock->id, 'day' => $dailyStock->day],
                    ['min_stock' => $dailyStock->min_stock, 'max_stock' => $dailyStock->max_stock]
                );

                $includedDailyStock->push($newDailyStock->id);
            }

            DailyStock::where('component_stock_id', $newComponentStock->id)
                ->whereNotIn('id', $includedDailyStock)
                ->delete();
        }

        $includedSubrecipe = collect([]);

        foreach ($component->subrecipe as $componentVariationComponent) {
            $newComponentDestination = $this->duplicateComponent($componentVariationComponent->variationSubrecipe, $originStore, $store, $equivalentUnits);

            if (!isset($newComponentDestination)) continue;

            $newSubrecipeItem = ComponentVariationComponent::updateOrCreate(
                [
                    'component_origin_id' => $component->id,
                    'component_destination_id' => $newComponentDestination->id
                ],
                ['value_reference' => $componentVariationComponent->value_reference, 'consumption' => $componentVariationComponent->consumption]
            );

            $includedSubrecipe->push($newSubrecipeItem->id);
        }

        ComponentVariationComponent::where('component_origin_id', $component->id)
            ->whereNotIn('id', $includedSubrecipe)
            ->delete();

        return $newComponent;
    }

    public function getEquivalentId($equivalentArray, $originId)
    {
        $first = $equivalentArray->where('origin', $originId)->first();
        if (isset($first) && isset($first['equivalent'])) {
            return $first['equivalent'];
        }

        return null;
    }

    public function hasDay($periods, $day)
    {
        $hasDay = false;
        foreach ($periods as $period) {
            if ($period["day"] == $day) {
                $hasDay = true;
            }
        }
        return $hasDay;
    }

    public function sectionDayOfWeek($day)
    {
        $dayOfWeek = "";
        switch ($day) {
            case 1:
                $dayOfWeek = "Lunes";
                break;
            case 2:
                $dayOfWeek = "Martes";
                break;
            case 3:
                $dayOfWeek = "Miércoles";
                break;
            case 4:
                $dayOfWeek = "Jueves";
                break;
            case 5:
                $dayOfWeek = "Viernes";
                break;
            case 6:
                $dayOfWeek = "Sábado";
                break;
            case 7:
                $dayOfWeek = "Domingo";
                break;
            default:
                break;
        }
        return $dayOfWeek;
    }

    public function APIPeriods($periods)
    {
        $periodsCollection = collect($periods);
        if (count($periodsCollection) != 7) {
            // Agregando días faltantes y el día como texto
            for ($i = 1; $i <= 7; $i++) {
                if (!$this->hasDay($periodsCollection, $i)) {
                    $period = [
                        "id" => "new" . $i,
                        "day" => $i,
                        "periods" => [],
                        "dayOfWeek" => $this->sectionDayOfWeek($i)
                    ];
                    $periodsCollection->splice($i - 1, 0, [$period]);
                } else {
                    $periodsCollection[$i - 1]["dayOfWeek"] = $this->sectionDayOfWeek($i);
                }
            }
        } else {
            for ($i = 0; $i < count($periodsCollection); $i++) {
                $periodsCollection[$i]["dayOfWeek"] = $this->sectionDayOfWeek($i + 1);
            }
        }
        return $periodsCollection;
    }
}
