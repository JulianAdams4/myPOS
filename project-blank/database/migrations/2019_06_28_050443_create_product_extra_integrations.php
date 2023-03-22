<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductExtraIntegrations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products_connection_integrations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('main_product_id');
            $table->foreign('main_product_id')->references('id')->on('product_integration_details')->onDelete('cascade');
            $table->unsignedInteger('component_product_id');
            $table->foreign('component_product_id')->references('id')->on('product_integration_details')->onDelete('cascade');
            $table->string('connection_type')->nullable();
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
        Schema::dropIfExists('products_connection_integrations');
    }
}
