<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdminStoresTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('admin_stores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('store_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->text('api_token')->nullable();
            $table->text('activation_token')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
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
        Schema::dropIfExists('admin_stores');
    }
}
