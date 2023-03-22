<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_products', function (Blueprint $table) {
            //
            $table->increments('id');
            $table->unsignedInteger('country_id')->unsigned()->nullable();
            $table->foreign('country_id')->references('id')->on('countries');
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->boolean('apply_taxes');
            $table->string('stripe_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::dropIfExists('subscription_products');
    }
}
