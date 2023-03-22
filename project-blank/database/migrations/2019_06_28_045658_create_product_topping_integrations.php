<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductToppingIntegrations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_topping_integrations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_integration_id');
            $table->foreign('product_integration_id')->references('id')->on('product_integration_details')->onDelete('cascade');
            $table->unsignedInteger('topping_integration_id');
            $table->foreign('topping_integration_id')->references('id')->on('topping_integration_details')->onDelete('cascade');
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
        Schema::dropIfExists('product_topping_integrations');
    }
}
