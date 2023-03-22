<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_integration_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('integration_name');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->string('type')->nullable();
            $table->string('subtype')->nullable();
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
        Schema::dropIfExists('product_integration_details');
    }
}
