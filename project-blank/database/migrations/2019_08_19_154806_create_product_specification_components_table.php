<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Product;
use App\ProductSpecification;
use App\SpecificationComponent;
use App\ProductSpecificationComponent;

class CreateProductSpecificationComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_specification_components', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('component_variation_id')->unsigned();
            $table->foreign('component_variation_id')->references('id')
            ->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('prod_spec_id');
            $table->foreign('prod_spec_id')->references('id')
            ->on('product_specifications')->onDelete('cascade');
            $table->float('consumption', 8, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $products = Product::all();
        foreach ($products as $product) {
            $productSpecifications = ProductSpecification::where(
                "product_id",
                $product->id
            )->where('status', 1)->get();
            foreach ($productSpecifications as $productSpecification) {
                $specificationComponents = SpecificationComponent::where(
                    "specification_id",
                    $productSpecification->specification_id
                )->where('status', 1)->get();
                foreach ($specificationComponents as $specificationComponent) {
                    $prodSpecComp = new ProductSpecificationComponent();
                    $prodSpecComp->component_variation_id =
                        $specificationComponent->component_variation_id;
                    $prodSpecComp->prod_spec_id =
                        $productSpecification->id;
                    $prodSpecComp->consumption =
                        $specificationComponent->consumption;
                    $prodSpecComp->save();
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_specification_components');
    }
}
