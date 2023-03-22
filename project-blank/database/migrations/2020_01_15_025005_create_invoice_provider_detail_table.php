<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateInvoiceProviderDetailTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('invoice_provider_details', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('invoice_provider_id')->unsigned();
            $table->foreign('invoice_provider_id')->references('id')->on('invoice_providers')->onDelete('cascade');

            $table->integer('component_variation_id')->unsigned();
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');

            $table->float('quantity', 8, 2);
            $table->integer('unit_price')->unsigned();
            $table->integer('discount')->unsigned();
            $table->integer('tax')->unsigned();

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
        Schema::dropIfExists('invoice_provider_details');
    }
}
