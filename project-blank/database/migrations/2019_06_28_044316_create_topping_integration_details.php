<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateToppingIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('topping_integration_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('specification_id');
            $table->string('integration_name');
            $table->foreign('specification_id')->references('id')->on('specifications')->onDelete('cascade');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->string('type')->nullable();
            $table->string('subtype')->nullable();
            $table->unsignedInteger('external_topping_category_id')->nullable();
            $table->timestamps();
        });
        /// NOTA: Guardar el campo comentario solo en tablas de MYPOS (no de integraciones)
        /// NOTA: Guardar el campo cantidad solo en tablas de MYPOS (no de integraciones)
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('topping_integration_details');
    }
}
