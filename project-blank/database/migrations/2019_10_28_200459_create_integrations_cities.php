<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIntegrationsCities extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('integrations_cities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('integration_id');
            $table->foreign('integration_id')->references('id')->on('available_mypos_integrations')->onDelete('cascade');
            $table->string('name_integration')->nullable();
            $table->string('city_code')->nullable();
            $table->string('city_name')->nullable();
            $table->string('state_code')->nullable();
            $table->string('state_name')->nullable();
            $table->string('country_code')->nullable();
            $table->string('country_name')->nullable();
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
        Schema::dropIfExists('integrations_cities');
    }
}
