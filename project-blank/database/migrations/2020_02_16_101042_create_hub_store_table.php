<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHubStoreTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hub_store', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('hub_id')->unsigned();
            $table->foreign('hub_id')->references('id')->on('hubs');
            $table->unsignedInteger('store_id')->unsigned();
            $table->foreign('store_id')->references('id')->on('stores');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hub_store');
    }
}
