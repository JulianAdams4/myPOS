<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStoreTaxesIntegrations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_taxes_integrations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->unsignedInteger('integration_id');
            $table->foreign('integration_id')->references('id')->on('available_mypos_integrations')->onDelete('cascade');
            $table->unsignedInteger('id_tax');
            $table->foreign('id_tax')->references('id')->on('store_taxes')->onDelete('cascade');
            $table->string('external_id');
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
        Schema::dropIfExists('store_taxes_integrations');
    }
}
