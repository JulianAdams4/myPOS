<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductionOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('code');
            $table->unsignedInteger('component_variation_id');
            $table->foreign('component_variation_id')
                ->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('store_id');
            $table->foreign('store_id')
                ->references('id')->on('stores')->onDelete('cascade');
            // Campo para el caso de cambio del insumo elaborado o su receta(para que contenga el contenido original)
            $table->json("original_content");
            $table->decimal('quantity_produce', 14, 4)->nullable();
            $table->decimal('sum_consumables', 14, 4)->nullable();
            $table->decimal('total_produced', 14, 4)->nullable();
            $table->decimal('consumed_stock', 14, 4)->nullable();
            $table->decimal('ullage', 14, 4)->nullable();
            $table->decimal('cost', 14, 4)->nullable();
            $table->boolean('event_launched')->default(false);
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('production_orders');
    }
}
