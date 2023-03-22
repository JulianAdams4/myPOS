<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIntegrationsDocumentTypes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('integrations_document_types', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('integration_id');
            $table->foreign('integration_id')->references('id')->on('available_mypos_integrations')->onDelete('cascade');
            $table->string('document_code');
            $table->string('document_description');
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
        Schema::dropIfExists('integrations_document_types');
    }
}
