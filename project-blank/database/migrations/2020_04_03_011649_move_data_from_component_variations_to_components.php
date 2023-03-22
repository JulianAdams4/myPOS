<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Component;
use App\ComponentVariation;
use App\ComponentStock;
use App\ComponentVariationComponent;
use App\ProductComponent;
use App\ProductionOrder;
use App\ProductSpecificationComponent;
use App\Specification;
use App\SpecificationComponent;
use App\InvoiceProviderDetail;

class MoveDataFromComponentVariationsToComponents extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /*
        DB::table('components')->join('component_variations', 'components.id', '=', 'component_variations.component_id')
        ->where('component_variations.name', '=', 'Normal')
        ->update([
            'components.SKU'=>DB::raw('component_variations.SKU'),
            'components.metric_unit_id'=>DB::raw('component_variations.metric_unit_id'),
        ]);
        */
        DB::table('components')->chunkById(50, function ($components) {
            foreach ($components as $comp) {
                $normalVariation = ComponentVariation::where('component_id', '=', $comp->id, 'and')
                    ->where('name', 'Normal')->first();
                if ($normalVariation) {
                    DB::table('components')->where('id', '=', $comp->id)
                        ->update([
                            'components.SKU' => $normalVariation->SKU,
                            'components.metric_unit_id' => $normalVariation->metric_unit_id
                        ]);
                }
            }
        });

        ComponentVariation::where('name', 'Normal')->chunk(50, function ($component_variations) {
            foreach ($component_variations as $component) {
                $componentId = $component->component_id;
                $var_id = $component->id;

                foreach ($component->componentStocks as $stocks) {
                    $component_stock = ComponentStock::where('id', $stocks->id)
                    ->update(array('component_variation_id' => $componentId, 'cost' => $component->cost));
                }

                $product_components = ProductComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $componentId));

                $spec_comps = SpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $componentId));

                $product_specs = ProductSpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $componentId));

                $subrecipes = ComponentVariationComponent::where('comp_var_origin_id', $var_id)
                ->update(array('comp_var_origin_id' => $componentId));

                $subrecipesDest = ComponentVariationComponent::where('comp_var_destination_id', $var_id)
                ->update(array('comp_var_destination_id' => $componentId));

                $product_orders = ProductionOrder::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $componentId));

                $invoice_provider = InvoiceProviderDetail::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $componentId));
            }
        });

        ComponentVariation::where('name', '<>', 'Normal')
        ->with(['component'])->chunk(50, function ($component_variations) {
            foreach ($component_variations as $component) {
                $componentId = $component->id;
                $component_new = new Component();
                $component_new->name = strcmp($component->name, $component->component->name) == 0 ? $component->name : $component->component->name . ' ' . $component->name;
                $component_new->component_category_id = $component->component->component_category_id;
                $component_new->SKU = $component->SKU;
                $component_new->metric_unit_id = $component->metric_unit_id;
                $component_new->save();

                foreach ($component->componentStocks as $stocks) {
                    $component_stock = ComponentStock::where('id', $stocks->id)
                    ->update(array('component_variation_id' => $component_new->id, 'cost' => $component->cost));
                }

                $product_components = ProductComponent::where('component_variation_id', $componentId)
                ->update(array('component_variation_id' => $component_new->id));

                $spec_comps = SpecificationComponent::where('component_variation_id', $componentId)
                ->update(array('component_variation_id' => $component_new->id));

                $product_specs = ProductSpecificationComponent::where('component_variation_id', $componentId)
                ->update(array('component_variation_id' => $component_new->id));

                $subrecipes = ComponentVariationComponent::where('comp_var_origin_id', $componentId)
                ->update(array('comp_var_origin_id' => $component_new->id));

                $subrecipesDest = ComponentVariationComponent::where('comp_var_destination_id', $componentId)
                ->update(array('comp_var_destination_id' => $component_new->id));

                $product_orders = ProductionOrder::where('component_variation_id', $componentId)
                ->update(array('component_variation_id' => $component_new->id));

                $invoice_provider = InvoiceProviderDetail::where('component_variation_id', $componentId)
                ->update(array('component_variation_id' => $componentId));
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('components')->join('component_variations', 'components.id', '=', 'component_variations.component_id')
        ->where('component_variations.name', '=', 'Normal')
        ->update(['components.SKU'=>null,
            'components.cost'=>null,
            'components.value'=>null,
            'components.metric_unit_id'=>null,
        ]);


        ComponentVariation::where('name', 'Normal')->chunk(50, function ($component_variations) {
            foreach ($component_variations as $component) {
                $componentId = $component->component_id;
                $var_id = $component->id;

                foreach ($component->componentStocks as $stocks) {
                    $component_stock = ComponentStock::where('id', $stocks->id)
                    ->update(array('component_variation_id' => $var_id));
                }

                $product_components = ProductComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $spec_comps = SpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $product_specs = ProductSpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $subrecipes = ComponentVariationComponent::where('comp_var_origin_id', $var_id)
                ->update(array('comp_var_origin_id' => $var_id));

                $subrecipesDest = ComponentVariationComponent::where('comp_var_destination_id', $var_id)
                ->update(array('comp_var_destination_id' => $var_id));

                $product_orders = ProductionOrder::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $invoice_provider = InvoiceProviderDetail::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));
            }
        });

        ComponentVariation::where('name', '<>', 'Normal')
        ->with(['component'])->chunk(50, function ($component_variations) {
            foreach ($component_variations as $component) {
                $componentId = $component->component_id;
                $var_id = $component->id;

                foreach ($component->componentStocks as $stocks) {
                    $component_stock = ComponentStock::where('id', $stocks->id)
                    ->update(array('component_variation_id' => $var_id));
                }

                $product_components = ProductComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $spec_comps = SpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $product_specs = ProductSpecificationComponent::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $subrecipes = ComponentVariationComponent::where('comp_var_origin_id', $var_id)
                ->update(array('comp_var_origin_id' => $var_id));

                $subrecipesDest = ComponentVariationComponent::where('comp_var_destination_id', $var_id)
                ->update(array('comp_var_destination_id' => $var_id));

                $product_orders = ProductionOrder::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));

                $invoice_provider = InvoiceProviderDetail::where('component_variation_id', $var_id)
                ->update(array('component_variation_id' => $var_id));
            }
        });

        $col_variations = DB::table('components')->select('components.id as id', 'component_variations.id as var_id')
            ->join('component_variations', 'components.SKU', '=', 'component_variations.SKU')
            ->where('component_variations.name', '<>', 'Normal')
            ->get()->toArray();

        foreach ($col_variations as $variation) {
            $component_stock = ComponentStock::where('id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));

            $product_components = ProductComponent::where('component_variation_id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));

            $spec_comps = SpecificationComponent::where('component_variation_id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));

            $product_specs = ProductSpecificationComponent::where('component_variation_id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));

            $subrecipes = ComponentVariationComponent::where('comp_var_origin_id', $variation->id)
            ->update(array('comp_var_origin_id' => $variation->var_id));

            $subrecipesDest = ComponentVariationComponent::where('comp_var_destination_id', $variation->id)
            ->update(array('comp_var_destination_id' => $variation->var_id));

            $product_orders = ProductionOrder::where('component_variation_id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));

            Component::where('id', $variation->id)->delete();

            $invoice_provider = InvoiceProviderDetail::where('component_variation_id', $variation->id)
            ->update(array('component_variation_id' => $variation->var_id));
        }
    }
}
