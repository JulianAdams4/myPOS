<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionDiscountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_discounts', function (Blueprint $table) {
            //
            $table->increments('id');
            $table->unsignedInteger('store_id')->unsigned()->nullable();
            $table->foreign('store_id')->references('id')->on('stores');
            $table->unsignedInteger('subscription_product_id')->unsigned()->nullable();
            $table->foreign('subscription_product_id')->references('id')->on('subscription_products');
            $table->unsignedInteger('discount')->default(false);
            $table->boolean('is_percentage')->default(true);
            $table->string('stripe_id')->nullable();
            $table->timestamp('expires_at')->nullable();
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
        Schema::dropIfExists('subscription_discounts');
    }
}
