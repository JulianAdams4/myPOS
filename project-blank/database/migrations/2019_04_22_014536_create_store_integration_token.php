<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStoreIntegrationToken extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_integration_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('store_id');
            $table->string('integration_name');
            $table->string('token');
            $table->string('password')->nullable();
            $table->string('type')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
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
        Schema::dropIfExists('store_integration_tokens');
    }
}
