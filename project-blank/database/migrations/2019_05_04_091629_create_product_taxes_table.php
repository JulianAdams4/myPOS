<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductTaxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_taxes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
			$table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unsignedInteger('store_tax_id');
            $table->foreign('store_tax_id')->references('id')->on('store_taxes')->onDelete('cascade');
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
        Schema::dropIfExists('product_taxes');
    }
}
