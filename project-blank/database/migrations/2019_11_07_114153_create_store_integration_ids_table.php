<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStoreIntegrationIdsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_integration_ids', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('integration_id')->unsigned();
            $table->foreign('integration_id')->references('id')
                ->on('available_mypos_integrations')->onDelete('cascade');
            $table->integer('store_id')->unsigned();
                $table->foreign('store_id')->references('id')
                    ->on('stores')->onDelete('cascade');
            $table->string('external_store_id');
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
        Schema::dropIfExists('store_integration_ids');
    }
}
