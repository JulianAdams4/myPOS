<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSectionIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('section_integrations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('section_id');
            $table->foreign('section_id')->references('id')->on('sections');
            $table->unsignedInteger('integration_id');
            $table->foreign('integration_id')->references('id')->on('available_mypos_integrations');
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
        Schema::dropIfExists('section_integrations');
    }
}
