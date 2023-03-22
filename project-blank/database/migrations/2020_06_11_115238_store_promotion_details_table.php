<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class StorePromotionDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_promotion_details', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('store_promotion_id')->unsigned();
            $table->foreign('store_promotion_id')->references('id')->on('store_promotions')->onDelete('cascade');
            $table->integer('product_id');
            $table->integer('quantiti');
            $table->boolean('cause_tax')->default(false);
            $table->decimal('discount_value',10,4);
            $table->char('status',1);
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
        Schema::dropIfExists('store_promotion_details');
    }
}
