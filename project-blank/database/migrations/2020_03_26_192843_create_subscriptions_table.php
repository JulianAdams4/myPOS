<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            //
            $table->increments('id');
            $table->unsignedInteger('store_id')->unsigned()->nullable();
            $table->foreign('store_id')->references('id')->on('stores');
            $table->unsignedInteger('subscription_plan_id')->unsigned()->nullable();
            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plans');
            $table->dateTime('billing_date')->nullable();
            $table->dateTime('activation_date')->nullable();
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
        Schema::dropIfExists('subscriptions');
    }
}
