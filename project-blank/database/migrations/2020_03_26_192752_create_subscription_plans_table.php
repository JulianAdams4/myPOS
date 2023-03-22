<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            //
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('subscription_product_id')->unsigned()->nullable();
            $table->foreign('subscription_product_id')->references('id')->on('subscription_products');
            $table->enum('frequency', ['day', 'week', 'month', 'year']);
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
        Schema::dropIfExists('subscription_plans');
    }
}
